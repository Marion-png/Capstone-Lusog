<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use App\Models\StudentIncidentReport;
use App\Support\SchemaCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Incident reports on a learner's student profile: list, file, withdraw.
 *
 * The class adviser is the one in the room when something happens, so this
 * is their tab and their write. It is scoped twice, like every other adviser
 * surface: to the school, and within it to the adviser's own class — an LRN
 * off the wire decides nothing, because the learner is re-read and re-checked
 * against the session's assigned grade and section on every call.
 *
 * Routes sit under /health-records/*, not /adviser/*, for the same reason the
 * medical-documents ones do: EnsureActiveSession seeds a demo session for
 * whichever role a URL belongs to, so a prototype nurse opening an /adviser/*
 * URL would be switched to class_adviser before the request ever arrived.
 */
class StudentIncidentReportController extends Controller
{
    /** Only the adviser files these; the nurse and clinic have their own logs. */
    private const WRITE_ROLES = ['class_adviser'];

    public function index(Request $request, string $lrn): JsonResponse
    {
        if (! $this->mayRead($request, $lrn)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(['reports' => $this->listFor($request, $lrn)]);
    }

    public function store(Request $request, string $lrn): JsonResponse
    {
        if (! $this->mayRead($request, $lrn)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! SchemaCache::hasTable('student_incident_reports')) {
            return response()->json(['message' => 'Incident reports are not available.'], 503);
        }

        $validated = $request->validate([
            // An incident is something that already happened. A future date is
            // a typo, and it would sort to the top of the learner's history.
            'occurred_at' => ['required', 'date', 'before_or_equal:today'],
            'category' => ['required', Rule::in(array_keys(StudentIncidentReport::CATEGORIES))],
            'severity' => ['required', Rule::in(array_keys(StudentIncidentReport::SEVERITIES))],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:2000'],
            'action_taken' => ['nullable', 'string', 'max:2000'],
            'witnesses' => ['nullable', 'string', 'max:500'],
            'guardian_notified' => ['nullable', 'boolean'],
        ]);

        // Written through the model, never a raw insert: the casts are what
        // keep the description, the action taken and the staff name encrypted.
        $report = StudentIncidentReport::create([
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
     * Withdrawing a report the adviser filed by mistake.
     *
     * Deliberately hard-scoped: the row must belong to this school AND to the
     * learner in the URL, whose class this adviser holds. The delete is
     * audited by the Auditable trait, so a withdrawn report leaves a record
     * that it existed — an incident log you can silently empty is not one.
     */
    public function destroy(Request $request, string $lrn, int $id): JsonResponse
    {
        if (! $this->mayRead($request, $lrn)) {
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
     * The adviser must hold this learner's class.
     *
     * Two locks on one door: the learner has to exist in this school, and
     * their grade/section has to match the adviser's assignment. An adviser
     * who somehow reaches a colleague's learner gets the same 403 as an
     * outsider.
     */
    private function mayRead(Request $request, string $lrn): bool
    {
        if (! in_array((string) $request->session()->get('active_role'), self::WRITE_ROLES, true)) {
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

        $grade = trim((string) $request->session()->get('assigned_grade_level', ''));
        $section = trim((string) $request->session()->get('assigned_section', ''));

        // An adviser with no assignment is not scoped to a class, so the
        // school check is all there is to apply.
        if ($grade === '' || $section === '') {
            return true;
        }

        return strcasecmp(trim((string) $record->section), trim($grade.' / '.$section)) === 0;
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
        return [
            'id' => $report->id,
            'occurred_at' => $report->occurred_at?->toDateString(),
            'occurred_label' => $report->occurred_at?->format('d M Y'),
            'category' => $report->category,
            'category_label' => $report->categoryLabel(),
            'severity' => $report->severity,
            'severity_label' => $report->severityLabel(),
            'location' => (string) $report->location,
            'description' => (string) $report->description,
            'action_taken' => (string) $report->action_taken,
            'witnesses' => (string) $report->witnesses,
            'guardian_notified' => (bool) $report->guardian_notified,
            'reported_by' => (string) $report->reported_by_name,
            'filed_label' => $report->created_at?->format('d M Y, g:i A'),
        ];
    }
}
