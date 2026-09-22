<?php

namespace App\Http\Controllers;

use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;
use App\Support\SchemaCache;
use App\Support\Sheet2Review;
use App\Support\StudentRosterSync;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NurseController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->requireNurseRole($request)) {
            return $redirect;
        }

        StudentRosterSync::syncToSession($request);

        $records = self::dedupedRoster($request->session()->get('school_health_card_records', []));

        $consentByLrn = [];

        if (! empty($records)) {
            $schoolYear = ParentalConsentForm::currentSchoolYear();
            $lrns = array_values(array_filter(array_column($records, 'lrn'), fn ($v) => $v !== null && $v !== ''));

            if (! empty($lrns)) {
                $studentRecords = StudentHealthRecord::forActiveInstitution()->whereIn('student_id', $lrns)->forCurrentSchoolYear($schoolYear)->get()->keyBy('student_id');
                $studentIds = $studentRecords->pluck('id')->toArray();

                if (! empty($studentIds)) {
                    $consents = ParentalConsentForm::whereIn('student_health_record_id', $studentIds)
                        ->where('program_type', 'Deworming')
                        ->where('school_year', $schoolYear)
                        ->get()
                        ->keyBy('student_health_record_id');

                    foreach ($lrns as $lrn) {
                        $sr = $studentRecords->get($lrn);
                        $consentByLrn[$lrn] = ($sr !== null && $consents->has($sr->id))
                            ? $consents->get($sr->id)
                            : null;
                    }
                }
            }
        }

        return view('nurse.index', [
            'records' => $records,
            'consentByLrn' => $consentByLrn,
        ]);
    }

    /**
     * The roster deduplicated by LRN, keyed by each surviving row's index in
     * the raw session array.
     *
     * Examination links carry that raw index, and saveExamination() writes
     * back to the raw array at the same index — so re-indexing the deduplicated
     * list (as this used to do) pointed "Fill Medical Record" at a different
     * learner than the row it was rendered on, for every roster holding a
     * duplicate LRN.
     *
     * @param  array<int, array<string, mixed>>  $rawRecords
     * @return array<int, array<string, mixed>>
     */
    public static function dedupedRoster(array $rawRecords): array
    {
        $rawIndexByLrn = [];
        $kept = [];

        foreach ($rawRecords as $rawIndex => $row) {
            if (! is_array($row)) {
                continue;
            }

            $lrn = (string) ($row['lrn'] ?? '');

            if ($lrn === '') {
                $kept[$rawIndex] = $row;

                continue;
            }

            if (! isset($rawIndexByLrn[$lrn])) {
                $rawIndexByLrn[$lrn] = $rawIndex;
                $kept[$rawIndex] = $row;

                continue;
            }

            // A later duplicate that carries the examination supersedes the first.
            $firstIndex = $rawIndexByLrn[$lrn];
            if (! empty($row['examination']) && empty($kept[$firstIndex]['examination'])) {
                unset($kept[$firstIndex]);
                $rawIndexByLrn[$lrn] = $rawIndex;
                $kept[$rawIndex] = $row;
            }
        }

        return $kept;
    }

    public function examine(Request $request, int $index): View|RedirectResponse
    {
        if ($redirect = $this->requireNurseRole($request)) {
            return $redirect;
        }

        $records = $request->session()->get('school_health_card_records', []);

        if (! isset($records[$index])) {
            abort(404);
        }

        // The form no longer carries the Supplementation & Programs section,
        // so the deworming-consent banner it fed is gone and with it the two
        // round trips that looked the consent up on every open. The gate on
        // saveExamination() stays: a replayed old form still cannot mark
        // deworming as given without a signed consent on file.
        return view('nurse.examine', [
            'index' => $index,
            'record' => $records[$index],
            'backTo' => $this->returnTo($request),
        ]);
    }

    /**
     * Where Back, Cancel and the save go: the page the form was opened from.
     * A `return_to` the opener passed wins (the profile passes its own
     * learner, so the nurse lands back on the record they were reading),
     * else the referring page, and Health Records only when neither is a
     * page inside this app — never the dashboard.
     */
    private function returnTo(Request $request): string
    {
        $base = rtrim(url('/'), '/');
        $inApp = fn (string $url): bool => $url !== ''
            && str_starts_with($url, $base.'/')
            && ! str_contains($url, "\n")
            && ! preg_match('#/nurse/\d+/examine(\?|$)#', $url);

        $candidate = trim((string) $request->input('return_to', ''));
        if ($inApp($candidate)) {
            return $candidate;
        }

        $previous = (string) url()->previous();
        if ($request->isMethod('GET') && $inApp($previous)) {
            return $previous;
        }

        return route('dashboard.student-health-records');
    }

    public function saveExamination(Request $request, int $index): RedirectResponse
    {
        if ($redirect = $this->requireNurseRole($request)) {
            return $redirect;
        }

        $records = $request->session()->get('school_health_card_records', []);

        if (! isset($records[$index])) {
            abort(404);
        }

        // Gate: block if deworming is being marked "given" but no valid consent is on file
        if ($request->input('deworming') === 'V') {
            $lrn = (string) ($records[$index]['lrn'] ?? '');
            $studentRecord = StudentHealthRecord::currentForStudent($lrn, $request->session()->get('active_institution_id'));
            $schoolYear = ParentalConsentForm::currentSchoolYear();
            $consentForm = $studentRecord !== null
                ? ParentalConsentForm::where('student_health_record_id', $studentRecord->id)
                    ->where('program_type', 'Deworming')
                    ->where('school_year', $schoolYear)
                    ->latest()
                    ->first()
                : null;

            if ($consentForm === null) {
                return back()
                    ->withInput()
                    ->withErrors(['deworming' => "Cannot proceed — no signed parental consent on file for this student for SY {$schoolYear}."]);
            }

            if ($consentForm->consent_type === 'refused') {
                return back()
                    ->withInput()
                    ->withErrors(['deworming' => "Cannot proceed — the parent/guardian refused consent for health services for SY {$schoolYear}."]);
            }
        }

        $existingHeight = $records[$index]['height_cm'] ?? null;
        $existingWeight = $records[$index]['weight_kg'] ?? null;

        if (empty($records[$index]['baseline_snapshot'])) {
            $records[$index]['baseline_snapshot'] = [
                'height_cm' => $existingHeight,
                'weight_kg' => $existingWeight,
            ];
        }

        $examDateInput = (string) $request->input('date_of_examination', '');
        $examDate = now()->toDateString();
        if ($examDateInput !== '') {
            try {
                $examDate = Carbon::parse($examDateInput)->toDateString();
            } catch (\Throwable $_) {
                $examDate = now()->toDateString();
            }
        }

        $attendanceByMonth = $records[$index]['attendance_by_month'] ?? [];
        if (! is_array($attendanceByMonth)) {
            $attendanceByMonth = [];
        }
        $monthKey = Carbon::parse($examDate)->format('Y-m');
        $attendanceByMonth[$monthKey] = ((int) ($attendanceByMonth[$monthKey] ?? 0)) + 1;
        ksort($attendanceByMonth);
        $records[$index]['attendance_by_month'] = $attendanceByMonth;

        // Height and weight are the class adviser's measurements and vital
        // signs live on the learner's profile, so the form no longer carries
        // either: the examination is stamped with the record's own figures.
        // A form that still posts them (an older tab) is honoured.
        $heightCm = $request->input('height_cm', $records[$index]['height_cm'] ?? null);
        $weightKg = $request->input('weight_kg', $records[$index]['weight_kg'] ?? null);
        $records[$index]['height_cm'] = $heightCm;
        $records[$index]['weight_kg'] = $weightKg;
        $lockedBmiStatus = (string) ($records[$index]['nutritional_status_bmi_for_age'] ?? '');
        $lockedHeightAgeStatus = (string) ($records[$index]['nutritional_status_height_for_age'] ?? '');
        $records[$index]['endline_snapshot'] = [
            'height_cm' => $heightCm,
            'weight_kg' => $weightKg,
            'nutritional_status_bmi' => $lockedBmiStatus,
        ];
        $records[$index]['examination'] = [
            'date_of_examination' => $examDate,
            'temperature_bp' => $request->input('temperature_bp'),
            'heart_rate' => $request->input('heart_rate'),
            'pulse_rate' => $request->input('pulse_rate'),
            'respiratory_rate' => $request->input('respiratory_rate'),
            'height_cm' => $heightCm,
            'weight_kg' => $weightKg,
            'nutritional_status_bmi' => $lockedBmiStatus,
            'nutritional_status_height_age' => $lockedHeightAgeStatus,
            'vision_screening' => $request->input('vision_screening'),
            'auditory_screening' => $request->input('auditory_screening'),
            'skin_scalp' => $request->input('skin_scalp'),
            'eyes_ears_nose' => $request->input('eyes_ears_nose'),
            'mouth_throat_neck' => $request->input('mouth_throat_neck'),
            'lungs_heart' => $request->input('lungs_heart'),
            'abdomen' => $request->input('abdomen'),
            'deformities' => $request->input('deformities'),
            'iron_supplementation' => $request->input('iron_supplementation'),
            'deworming' => $request->input('deworming'),
            'immunization' => $request->input('immunization'),
            'sbfp_beneficiary' => $request->input('sbfp_beneficiary'),
            'four_ps_beneficiary' => $request->input('four_ps_beneficiary'),
            'menarche' => $request->input('menarche'),
            'others' => $request->input('others'),
            // The Supplementation & Programs section (and its Examined By box)
            // was removed from the form, so attribution is the app's: whoever
            // is signed in examined the learner. An older form still posting a
            // name is honoured, since it was the nurse who typed it.
            'examined_by' => trim((string) $request->input('examined_by', ''))
                ?: (string) $request->session()->get('active_name', ''),
        ];

        // Sheet 2 — F. body systems through J. summary — as the form posted
        // it, signed by whoever is signed in on the date of examination. The
        // one shape the profile's Sheet 2 tab and the MLAT download read
        // (App\Support\Sheet2Review).
        $records[$index]['examination'][Sheet2Review::KEY] = Sheet2Review::fromInput(
            $request->all() + [
                'examiner' => (string) $request->session()->get('active_name', ''),
                'examiner_date' => $examDate,
            ]
        );

        $request->session()->put('school_health_card_records', $records);

        // Persist the examination to the database so it survives session expiry
        // and server restarts. Only update records that already exist in the DB
        // (i.e. real students submitted by an adviser) to avoid creating orphans.
        $lrn = (string) ($records[$index]['lrn'] ?? '');
        if ($lrn !== '' && SchemaCache::hasTable('student_health_records')) {
            $studentRecord = StudentHealthRecord::currentForStudent($lrn, $request->session()->get('active_institution_id'));

            if ($studentRecord !== null) {
                $endlineHeight = $request->input('height_cm');
                $endlineWeight = $request->input('weight_kg');
                $endlineBmi = null;
                if (is_numeric($endlineHeight) && is_numeric($endlineWeight) && (float) $endlineHeight > 0) {
                    $heightMeters = ((float) $endlineHeight) / 100;
                    $endlineBmi = round(((float) $endlineWeight) / ($heightMeters * $heightMeters), 2);
                }

                $studentRecord->update([
                    'examination' => $records[$index]['examination'],
                    'attendance_by_month' => $attendanceByMonth,
                    'weight' => is_numeric($endlineWeight) ? (float) $endlineWeight : $studentRecord->weight,
                    'endline_height_cm' => is_numeric($endlineHeight) ? (float) $endlineHeight : $studentRecord->endline_height_cm,
                    'endline_weight_kg' => is_numeric($endlineWeight) ? (float) $endlineWeight : $studentRecord->endline_weight_kg,
                    'endline_bmi_value' => $endlineBmi ?? $studentRecord->endline_bmi_value,
                    'endline_nutritional_status' => $lockedBmiStatus !== '' ? $lockedBmiStatus : $studentRecord->endline_nutritional_status,
                    'endline_recorded_at' => $examDate,
                ]);
            }
        }

        return redirect()->to($this->returnTo($request))->with('success', 'Medical record saved.');
    }

    /**
     * Redirects a non-nurse session to its own dashboard instead of letting
     * it view nurse-only screens. Without this, a mismatched session could
     * browse the nurse pages that have no guard and only get bounced away
     * unpredictably on the one page (Health Records) that does check.
     */
    private function requireNurseRole(Request $request): ?RedirectResponse
    {
        $role = (string) $request->session()->get('active_role', '');
        if (in_array($role, ['school_nurse', 'clinic_staff', 'system_admin'], true)) {
            return null;
        }

        $redirectByRole = [
            'class_adviser' => 'dashboard.class-adviser',
            'school_head' => 'dashboard.school-head',
            'feeding_coor' => 'dashboard.feedingcor-dashboard',
            'nutricor' => 'dashboard.nutricor-dashboard',
            'system_admin' => 'dashboard.system-admin',
        ];

        return redirect()->route($redirectByRole[$role] ?? 'login');
    }
}
