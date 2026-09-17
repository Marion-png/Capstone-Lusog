<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use App\Models\StudentIncidentReport;
use App\Support\SchemaCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Incident reports on a learner's student profile: list, file, withdraw.
 *
 * **The nurse writes; the adviser reads.** The report is charted in FDAR —
 * Focus / Data / Action / Response — which is clinical documentation: reading
 * what happened to a learner, recording what was done and whether it worked is
 * the nurse's assessment to make. The class adviser sees the same reports on
 * their own profile, read-only, because an incident involving a learner in
 * their class is something they have to know about.
 *
 * Both roles are scoped to their school. The adviser is scoped a second time,
 * like every other adviser surface, to their own class — an LRN off the wire
 * decides nothing, because the learner is re-read and re-checked against the
 * session's assigned grade and section on every call. The nurse holds no class,
 * so the school is their whole scope; that is stated here rather than falling
 * out of an empty assignment, which is how it used to pass.
 *
 * Routes sit under /health-records/*, not /adviser/*, for the same reason the
 * medical-documents ones do: EnsureActiveSession seeds a demo session for
 * whichever role a URL belongs to, so a prototype nurse opening an /adviser/*
 * URL would be switched to class_adviser before the request ever arrived.
 */
class StudentIncidentReportController extends Controller
{
    public function index(Request $request, string $lrn): JsonResponse
    {
        if (! $this->mayRead($request, $lrn)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json([
            'reports' => $this->listFor($request, $lrn),
            // The panel draws its write controls from this rather than from a
            // role check of its own, so the form and the endpoint cannot
            // disagree about who may file.
            'can_file' => $this->mayWrite($request, $lrn),
        ]);
    }

    public function store(Request $request, string $lrn): JsonResponse
    {
        if (! $this->mayWrite($request, $lrn)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! SchemaCache::hasTable('student_incident_reports')) {
            return response()->json(['message' => 'Incident reports are not available.'], 503);
        }

        $validated = $request->validate([
            // An incident is something that already happened. A future date is
            // a typo, and it would sort to the top of the learner's history.
            'occurred_at' => ['required', 'date', 'before_or_equal:today'],
            // F-DAR's first column is the date AND the time. Optional, because
            // a nurse charting from a note that carried no clock reading must
            // still be able to file; a time later than now on today's date is
            // refused below for the reason a future date is.
            'occurred_time' => ['nullable', 'date_format:H:i'],
            'category' => ['required', Rule::in(array_keys(StudentIncidentReport::CATEGORIES))],
            'severity' => ['required', Rule::in(array_keys(StudentIncidentReport::SEVERITIES))],
            // FDAR: Focus — the nurse's own statement of what the note is
            // about (a sign or symptom, a behaviour, a treatment event). The
            // category above is the kind the list filters on; this is the
            // words on the sheet, and it falls back to the category when
            // nothing was written.
            'focus' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            // FDAR: Data is what was observed, and it is the one part of the
            // chart that cannot be left out — a report with no data is not a
            // record of anything.
            'description' => ['required', 'string', 'max:2000'],
            'action_taken' => ['nullable', 'string', 'max:2000'],
            // FDAR: Response. Nullable because a report filed the moment
            // something happens has no outcome yet, and refusing it until one
            // exists would lose the record of the incident itself.
            'response' => ['nullable', 'string', 'max:2000'],
            'witnesses' => ['nullable', 'string', 'max:500'],
            'guardian_notified' => ['nullable', 'boolean'],
        ]);

        // The Response is dropped where the column has not been migrated yet,
        // so an un-migrated machine charts F, D and A rather than failing the
        // save outright. See StudentIncidentReport::supportsResponse().
        $response = StudentIncidentReport::supportsResponse()
            ? ['response' => $validated['response'] ?? null]
            : [];

        $time = trim((string) ($validated['occurred_time'] ?? ''));

        // An incident is something that already happened, to the minute: a
        // time still ahead of the clock on today's date is the same typo as a
        // date in the future, and it would sort above what actually occurred.
        if ($time !== '' && $validated['occurred_at'] === now()->toDateString() && $time > now()->format('H:i')) {
            throw ValidationException::withMessages([
                'occurred_time' => 'The time of the incident cannot be later than now.',
            ]);
        }

        // The sheet's own columns travel only where they have been migrated,
        // like the Response, so an older database still files the chart.
        $chart = StudentIncidentReport::supportsChartColumns()
            ? [
                'occurred_time' => $time !== '' ? $time : null,
                'focus' => trim((string) ($validated['focus'] ?? '')) ?: null,
            ]
            : [];

        // Written through the model, never a raw insert: the casts are what
        // keep the description, the action taken and the staff name encrypted.
        $report = StudentIncidentReport::create($response + $chart + [
            'institution_id' => $request->session()->get('active_institution_id'),
            'student_lrn' => $lrn,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'occurred_at' => $validated['occurred_at'],
            'category' => $validated['category'],
            'severity' => $validated['severity'],
            'location' => $validated['location'] ?? null,
            'description' => $validated['description'],
            'action_taken' => $validated['action_taken'] ?? null,
            'witnesses' => $validated['witnesses'] ?? null,
            'guardian_notified' => (bool) ($validated['guardian_notified'] ?? false),
            // Attribution is the app's, not the form's — a filer cannot sign
            // somebody else's name to a report about a child.
            'reported_by_name' => (string) $request->session()->get('active_name', ''),
            'reported_by_role' => (string) $request->session()->get('active_role', ''),
        ]);

        return response()->json([
            'report' => $this->present($report),
            'reports' => $this->listFor($request, $lrn),
        ], 201);
    }

    /**
     * Withdrawing a report the nurse filed by mistake.
     *
     * Whoever may file may withdraw, and nobody else — an adviser reading the
     * panel has no delete, on the endpoint or on the page. Deliberately
     * hard-scoped besides: the row must belong to this school AND to the
     * learner in the URL. The delete is audited by the Auditable trait, so a
     * withdrawn report leaves a record that it existed — an incident log you
     * can silently empty is not one.
     */
    public function destroy(Request $request, string $lrn, int $id): JsonResponse
    {
        if (! $this->mayWrite($request, $lrn)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! SchemaCache::hasTable('student_incident_reports')) {
            return response()->json(['message' => 'Incident reports are not available.'], 503);
        }

        $report = StudentIncidentReport::query()
            ->forLearner($lrn, $request->session()->get('active_institution_id'))
            ->find($id);

        if ($report === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $report->delete();

        return response()->json(['reports' => $this->listFor($request, $lrn)]);
    }

    /**
     * Who may see this learner's reports.
     *
     * Two locks on one door for the adviser: the learner has to exist in this
     * school, and their grade/section has to match the adviser's assignment.
     * An adviser who somehow reaches a colleague's learner gets the same 403
     * as an outsider.
     *
     * The nurse holds no class — they serve the whole school — so the school
     * is their whole scope. That is checked by role rather than by finding an
     * empty assignment in the session: an adviser whose assignment happens to
     * be blank must not quietly acquire the run of the school through the same
     * branch.
     */
    private function mayRead(Request $request, string $lrn): bool
    {
        $role = (string) $request->session()->get('active_role');

        if (! StudentIncidentReport::canView($role)) {
            return false;
        }

        $institutionId = $request->session()->get('active_institution_id');

        if (! $institutionId) {
            return false;
        }

        $record = StudentHealthRecord::currentForStudent($lrn, $institutionId);

        if ($record === null) {
            return false;
        }

        if ($role !== 'class_adviser') {
            return true;
        }

        $grade = trim((string) $request->session()->get('assigned_grade_level', ''));
        $section = trim((string) $request->session()->get('assigned_section', ''));

        // An adviser with no assignment is not scoped to a class, so the
        // school check is all there is to apply.
        if ($grade === '' || $section === '') {
            return true;
        }

        return strcasecmp(trim((string) $record->section), trim($grade.' / '.$section)) === 0;
    }

    /**
     * Who may file or withdraw one: the nurse, on a learner they may read.
     *
     * Reading is the wider permission and writing is a subset of it, so this
     * is deliberately mayRead() plus the role rather than a second scope
     * check that could drift from the first.
     */
    private function mayWrite(Request $request, string $lrn): bool
    {
        return StudentIncidentReport::canFile((string) $request->session()->get('active_role'))
            && $this->mayRead($request, $lrn);
    }

    /** @return array<int, array<string, mixed>> */
    private function listFor(Request $request, string $lrn): array
    {
        if (! SchemaCache::hasTable('student_incident_reports')) {
            return [];
        }

        return StudentIncidentReport::query()
            ->forLearner($lrn, $request->session()->get('active_institution_id'))
            // Both are plain columns. Newest incident first, and the id breaks
            // a tie so two reports filed for one day keep a stable order.
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (StudentIncidentReport $report) => $this->present($report))
            ->all();
    }

    /** @return array<string, mixed> */
    private function present(StudentIncidentReport $report): array
    {
        $chart = StudentIncidentReport::supportsChartColumns();

        return [
            'id' => $report->id,
            'occurred_at' => $report->occurred_at?->toDateString(),
            'occurred_label' => $report->occurred_at?->format('d M Y'),
            // The sheet's first column: date and time. Blank where no time
            // was charted, never a made-up midnight.
            'occurred_time' => $chart && $report->occurred_time ? substr((string) $report->occurred_time, 0, 5) : '',
            'occurred_time_label' => $report->occurredTimeLabel(),
            'category' => $report->category,
            'category_label' => $report->categoryLabel(),
            'severity' => $report->severity,
            'severity_label' => $report->severityLabel(),
            'location' => (string) $report->location,
            // FDAR, in the order it is charted: the Focus is the nurse's own
            // statement (or the kind of incident where none was written), the
            // description the Data, then the Action and the Response.
            'focus' => $chart ? (string) $report->focus : '',
            'focus_label' => $report->focusLabel(),
            'description' => (string) $report->description,
            'action_taken' => (string) $report->action_taken,
            'response' => StudentIncidentReport::supportsResponse() ? (string) $report->response : '',
            'witnesses' => (string) $report->witnesses,
            'guardian_notified' => (bool) $report->guardian_notified,
            // The signature under the note: who charted it and as what. The
            // sheet is signed by the nurse, so the role is printed as a title.
            'reported_by' => (string) $report->reported_by_name,
            'reported_by_title' => match ((string) $report->reported_by_role) {
                'school_nurse' => 'School Nurse',
                'clinic_staff' => 'Clinic Staff',
                'class_adviser' => 'Class Adviser',
                default => '',
            },
            'filed_label' => $report->created_at?->format('d M Y, g:i A'),
        ];
    }
}
