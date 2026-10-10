<?php

namespace App\Http\Controllers;

use App\Models\FhirTransmission;
use App\Models\StudentHealthRecord;
use App\Support\AuditTrail;
use App\Support\FhirBundle;
use App\Support\FhirExchangeUnavailable;
use App\Support\FhirTransmitter;
use App\Support\NutritionalHealthStatus;
use App\Support\SchemaCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Health Data Exchange — the School Nurse's HL7 FHIR R4 screen.
 *
 * Pick a learner, read the bundle their record serialises to, download it or
 * send it to the receiving server. Every send goes through
 * App\Support\FhirTransmitter, the one outbound path; this controller decides
 * who may reach it and for which learners.
 *
 * Access is the nurse's alone (FhirTransmitter::ROLES) and every read is
 * re-scoped to the nurse's own school: a learner, a bundle or a transmission
 * from another school is a 404, never a disclosure.
 */
class FhirExchangeController extends Controller
{
    public function index(Request $request): View
    {
        $institutionId = $this->authorizeNurse($request);

        $records = StudentHealthRecord::rosterFor($institutionId)
            ->sortBy(fn (StudentHealthRecord $r) => mb_strtolower((string) $r->student_name))
            ->values();

        $transmissions = $this->transmissions($institutionId);
        $lastSentByLrn = $transmissions
            ->where('status', FhirTransmission::STATUS_SENT)
            ->groupBy('student_lrn')
            ->map(fn ($group) => $group->max('sent_at'));

        $learners = $records->map(function (StudentHealthRecord $record) use ($lastSentByLrn) {
            $details = is_array($record->student_details) ? $record->student_details : [];
            $nutrition = NutritionalHealthStatus::forRecord($record);

            return [
                'lrn' => (string) $record->student_id,
                'name' => (string) $record->student_name,
                'grade' => trim((string) ($details['grade_level'] ?? '')),
                'section' => trim((string) ($details['section'] ?? '')),
                'status' => $nutrition['endline']['bmi_status'] ?: $nutrition['baseline']['bmi_status'],
                'last_sent' => $lastSentByLrn->get((string) $record->student_id),
            ];
        });

        $names = $records->mapWithKeys(fn (StudentHealthRecord $r) => [(string) $r->student_id => (string) $r->student_name]);

        return view('dashboard.data-exchange', [
            'learners' => $learners,
            'transmissions' => $transmissions->take(25),
            'names' => $names,
            'counts' => [
                'sent' => $transmissions->where('status', FhirTransmission::STATUS_SENT)->count(),
                'failed' => $transmissions->whereIn('status', [FhirTransmission::STATUS_FAILED, FhirTransmission::STATUS_BLOCKED])->count(),
                'last_sent' => $transmissions->where('status', FhirTransmission::STATUS_SENT)->max('sent_at'),
            ],
            'server' => $this->server(),
        ]);
    }

    public function preview(Request $request, string $lrn): View
    {
        $institutionId = $this->authorizeNurse($request);
        $record = $this->learner($lrn, $institutionId);

        $bundle = FhirBundle::forRecord($record);
        $json = FhirBundle::encode($bundle);

        return view('dashboard.data-exchange-preview', [
            'record' => $record,
            'json' => $json,
            'summary' => FhirBundle::summary($bundle),
            'bytes' => strlen($json),
            'sha256' => hash('sha256', $json),
            'deidentified' => FhirBundle::deidentifies(),
            'history' => $this->transmissions($institutionId)
                ->where('student_lrn', (string) $record->student_id)
                ->take(10),
            'server' => $this->server(),
        ]);
    }

    public function download(Request $request, string $lrn): Response
    {
        $institutionId = $this->authorizeNurse($request);
        $record = $this->learner($lrn, $institutionId);

        $json = FhirBundle::encode(FhirBundle::forRecord($record));

        AuditTrail::record(
            'downloaded',
            'StudentHealthRecord',
            (int) $record->id,
            'Downloaded the FHIR R4 bundle for LRN '.$record->student_id,
            ['payload_sha256' => hash('sha256', $json), 'deidentified' => FhirBundle::deidentifies()],
        );

        $name = 'fhir-bundle-'.(FhirBundle::deidentifies() ? substr(FhirBundle::pseudonym($record), 0, 12) : $record->student_id).'.json';

        return response($json, 200, [
            'Content-Type' => FhirBundle::MIME.'; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    public function transmit(Request $request, string $lrn, FhirTransmitter $transmitter): RedirectResponse
    {
        $institutionId = $this->authorizeNurse($request);
        $record = $this->learner($lrn, $institutionId);

        try {
            $transmission = $transmitter->transmit($record, 'manual');
        } catch (FhirExchangeUnavailable $e) {
            return back()->with('error', $e->getMessage());
        }

        $flash = match ($transmission->status) {
            FhirTransmission::STATUS_SENT => ['success', 'Transmitted to '.$transmission->endpoint_host.' — HTTP '.$transmission->http_status.'.'],
            FhirTransmission::STATUS_BLOCKED => ['error', 'Not sent: the endpoint is not HTTPS.'],
            default => ['error', 'Not delivered. '.$transmission->error_message],
        };

        return redirect()
            ->route('dashboard.school-nurse.data-exchange.transmission', $transmission)
            ->with($flash[0], $flash[1]);
    }

    public function transmission(Request $request, FhirTransmission $transmission): View
    {
        $institutionId = $this->authorizeNurse($request);

        if ((int) $transmission->institution_id !== $institutionId) {
            abort(404);
        }

        $record = $transmission->student_health_record_id
            ? StudentHealthRecord::query()
                ->where('institution_id', $institutionId)
                ->find($transmission->student_health_record_id)
            : null;

        return view('dashboard.data-exchange-transmission', [
            'transmission' => $transmission,
            'record' => $record,
            'outcomes' => $transmission->serverOutcomes(),
            'intact' => $transmission->payloadIntact(),
            'responseJson' => $this->pretty((string) $transmission->response_body),
        ]);
    }

    /**
     * The nurse, at a school. Anybody else is refused outright rather than
     * redirected: this screen discloses records, and a refusal that looks like
     * a navigation is easy to mistake for a page that simply moved.
     */
    private function authorizeNurse(Request $request): int
    {
        $role = (string) $request->session()->get('active_role', '');
        $institutionId = (int) $request->session()->get('active_institution_id', 0);

        if (! in_array($role, FhirTransmitter::ROLES, true) || $institutionId <= 0) {
            abort(403, 'Only the School Nurse can exchange health records.');
        }

        return $institutionId;
    }

    private function learner(string $lrn, int $institutionId): StudentHealthRecord
    {
        if (! SchemaCache::hasTable('student_health_records')) {
            abort(404);
        }

        return StudentHealthRecord::currentForStudent($lrn, $institutionId) ?? abort(404);
    }

    private function transmissions(int $institutionId)
    {
        if (! SchemaCache::hasTable('fhir_transmissions')) {
            return collect();
        }

        return FhirTransmission::query()
            ->where('institution_id', $institutionId)
            ->latest('id')
            ->limit(200)
            ->get();
    }

    /**
     * @return array{configured: bool, secure: bool, host: string, url: string, auth: string, deidentified: bool, auto: bool}
     */
    private function server(): array
    {
        $endpoint = FhirTransmitter::endpoint();

        return [
            'configured' => $endpoint !== '',
            'secure' => $endpoint !== '' && FhirTransmitter::isSecure($endpoint),
            'host' => FhirTransmitter::endpointHost(),
            'url' => FhirTransmitter::displayEndpoint(),
            'auth' => FhirTransmitter::authMethod(),
            'deidentified' => FhirBundle::deidentifies(),
            'auto' => (bool) config('services.fhir.auto_transmit', false),
        ];
    }

    private function pretty(string $body): string
    {
        $decoded = json_decode($body, true);

        return is_array($decoded)
            ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $body;
    }
}
