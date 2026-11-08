<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use App\Models\StudentIncidentReport;
use App\Support\AuditTrail;
use App\Support\BmiClassifier;
use App\Support\ClinicalProofreader;
use App\Support\FeedingBeneficiarySummary;
use App\Support\IncidentPhysicalExam;
use App\Support\LearnerPlacement;
use App\Support\ProofreadUnavailable;
use App\Support\SchemaCache;
use App\Support\SchoolLetterhead;
use App\Support\SchoolSignatories;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Incident reports on a learner's student profile: list, file, edit, withdraw.
 *
 * **The clinic writes; the adviser reads.** The report is charted in FDAR —
 * Focus / Data / Action / Response — which is clinical documentation: reading
 * what happened to a learner, recording what was done and whether it worked is
 * the clinic's assessment to make — the nurse's, or the clinic teacher's while
 * they cover. Those two also edit a report; withdrawing one stays the nurse's.
 * Clinic staff file and read, and do neither. The class adviser sees them on
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
            // The panel draws its write controls from these rather than from a
            // role check of its own, so the form and the endpoints cannot
            // disagree about who may file, edit or withdraw.
            'can_file' => $this->mayWrite($request, $lrn),
            'can_edit' => $this->mayEdit($request, $lrn),
            'can_withdraw' => $this->mayWithdraw($request, $lrn),
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

        // Written through the model, never a raw insert: the casts are what
        // keep the description, the action taken and the staff name encrypted.
        $report = StudentIncidentReport::create($this->chartFrom($request) + [
            'institution_id' => $request->session()->get('active_institution_id'),
            'student_lrn' => $lrn,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
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
     * Correcting a report: the school nurse's and the clinic teacher's.
     *
     * Hard-scoped like a withdrawal: the row must belong to this school AND to
     * the learner in the URL. Only the chart changes. Who filed it, the learner,
     * the school and the school year are never read off this request, so the
     * signature stands; the edit stamp says who changed it, and the Auditable
     * trait keeps the old and new values.
     */
    public function update(Request $request, string $lrn, int $id): JsonResponse
    {
        if (! $this->mayEdit($request, $lrn)) {
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

        // A value is assigned only when it differs. These columns encrypt on
        // write, so every assignment is new ciphertext: the audit entry would
        // name fields nobody changed, and a save that changed nothing would
        // stamp the note as edited.
        foreach ($this->chartFrom($request) as $field => $value) {
            if (! $this->unchanged($report, $field, $value)) {
                $report->{$field} = $value;
            }
        }

        if ($report->isDirty()) {
            if (StudentIncidentReport::supportsEditStamp()) {
                $report->edited_by_name = (string) $request->session()->get('active_name', '');
                $report->edited_by_role = (string) $request->session()->get('active_role', '');
                $report->edited_at = now();
            }

            $report->save();
        }

        return response()->json([
            'report' => $this->present($report),
            'reports' => $this->listFor($request, $lrn),
        ]);
    }

    /** Whether a posted chart field says what the report already says. */
    private function unchanged(StudentIncidentReport $report, string $field, mixed $value): bool
    {
        // The examination is one section, compared as its stored shape.
        if ($field === 'physical_exam') {
            return IncidentPhysicalExam::fromInput($report->physical_exam) === $value;
        }

        return $this->chartValue($report, $field) === (string) $value;
    }

    /** A chart field as a form would post it, so the two can be compared. */
    private function chartValue(StudentIncidentReport $report, string $field): string
    {
        return match ($field) {
            'occurred_at' => (string) $report->occurred_at?->toDateString(),
            // The form carries minutes; a time column may read back with seconds.
            'occurred_time' => substr((string) $report->occurred_time, 0, 5),
            'guardian_notified' => (string) (bool) $report->guardian_notified,
            default => (string) $report->{$field},
        };
    }

    /**
     * The chart as a form posted it, validated: everything a report says, and
     * nothing about who wrote it. Filing and editing are held to the same rules.
     *
     * @return array<string, mixed>
     */
    private function chartFrom(Request $request): array
    {
        // "Today" and "now" are the school's, not the server's: the date and
        // time on the form are what the clinic's clock read, and the server
        // keeps UTC — eight hours behind Davao.
        $now = now((string) config('app.school_timezone'));

        $validated = $request->validate([
            // An incident is something that already happened. A future date is
            // a typo, and it would sort to the top of the learner's history.
            'occurred_at' => ['required', 'date', 'before_or_equal:'.$now->toDateString()],
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
            // The physical examination under Data: the vital signs taken, the
            // level of consciousness, the pain score, the body part affected
            // and the findings. Every reading is optional — one nobody took is
            // left blank, never refused and never invented.
        ] + IncidentPhysicalExam::rules(), [], IncidentPhysicalExam::attributes());

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
        if ($time !== '' && $validated['occurred_at'] === $now->toDateString() && $time > $now->format('H:i')) {
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

        $exam = StudentIncidentReport::supportsPhysicalExam()
            ? ['physical_exam' => IncidentPhysicalExam::fromInput($validated['exam'] ?? null)]
            : [];

        return $response + $chart + $exam + [
            'occurred_at' => $validated['occurred_at'],
            'category' => $validated['category'],
            'severity' => $validated['severity'],
            'location' => $validated['location'] ?? null,
            'description' => $validated['description'],
            'action_taken' => $validated['action_taken'] ?? null,
            'witnesses' => $validated['witnesses'] ?? null,
            'guardian_notified' => (bool) ($validated['guardian_notified'] ?? false),
        ];
    }

    /**
     * Withdrawing a report filed by mistake.
     *
     * The nurse's alone — an adviser reading the panel has no delete, and
     * neither has a clinic teacher, who may chart an incident while covering
     * but not take a clinical note off a child's record; the endpoint refuses
     * both whatever a page draws. Deliberately hard-scoped besides: the row
     * must belong to this school AND to the learner in the URL. The delete is
     * audited by the Auditable trait, so a withdrawn report leaves a record that
     * it existed — an incident log you can silently empty is not one.
     */
    public function destroy(Request $request, string $lrn, int $id): JsonResponse
    {
        if (! $this->mayWithdraw($request, $lrn)) {
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
     * The text fields the grammar and terms check reads, as the form names
     * them: what each is called for the model, and the limit its column holds.
     * Witnesses and the place are left out — they are names and places, not
     * clinical wording.
     *
     * @var array<string, array{label: string, max: int}>
     */
    public const PROOFREAD_FIELDS = [
        'focus' => ['label' => 'Focus (the concern being charted)', 'max' => 255],
        'description' => ['label' => 'Data (what was observed and what the learner reported)', 'max' => 2000],
        'exam_body_site' => ['label' => 'Body part affected', 'max' => IncidentPhysicalExam::BODY_SITE_MAX],
        'exam_findings' => ['label' => 'Physical examination findings', 'max' => IncidentPhysicalExam::FINDINGS_MAX],
        'action_taken' => ['label' => 'Action (what was done)', 'max' => 2000],
        'response' => ['label' => 'Response (how the learner responded)', 'max' => 2000],
    ];

    /**
     * An AI check of the report's grammar and terms, before it is saved.
     *
     * Open to whoever may chart on this learner, and it writes nothing: the
     * suggestions go back to the form, where the nurse accepts or dismisses
     * each one, and the report is saved through store()/update() as typed.
     * The learner's names, the witnesses' and the nurse's own are masked
     * before the text leaves the server (ClinicalProofreader). The audit entry
     * names which fields were sent, never what they said.
     */
    public function proofread(Request $request, string $lrn): JsonResponse
    {
        if (! $this->mayWrite($request, $lrn)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! ClinicalProofreader::isConfigured()) {
            return response()->json(['message' => 'The grammar and terms check is not set up on this server.'], 503);
        }

        $rules = ['fields' => ['required', 'array'], 'witnesses' => ['nullable', 'string', 'max:500']];
        foreach (self::PROOFREAD_FIELDS as $key => $field) {
            $rules['fields.'.$key] = ['nullable', 'string', 'max:'.$field['max']];
        }
        $validated = $request->validate($rules);

        $fields = [];
        foreach (self::PROOFREAD_FIELDS as $key => $field) {
            $text = trim((string) ($validated['fields'][$key] ?? ''));
            if ($text !== '') {
                $fields[$key] = ['label' => $field['label'], 'text' => $text];
            }
        }

        if ($fields === []) {
            return response()->json(['suggestions' => (object) [], 'checked' => []]);
        }

        $record = StudentHealthRecord::currentForStudent($lrn, $request->session()->get('active_institution_id'));
        $details = is_array($record?->student_details) ? $record->student_details : [];

        $names = array_filter([
            (string) ($details['first_name'] ?? ''),
            (string) ($details['middle_name'] ?? ''),
            (string) ($details['last_name'] ?? ''),
            (string) $record?->student_name,
            (string) ($validated['witnesses'] ?? ''),
            (string) $request->session()->get('active_name', ''),
        ]);

        try {
            $suggestions = (new ClinicalProofreader)->proofread($fields, array_values($names));
        } catch (ProofreadUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        // A correction that would not fit the column is no correction.
        $suggestions = array_filter(
            $suggestions,
            fn (array $suggestion, string $key): bool => mb_strlen($suggestion['suggested']) <= self::PROOFREAD_FIELDS[$key]['max'],
            ARRAY_FILTER_USE_BOTH
        );

        AuditTrail::record(
            'proofread',
            'StudentHealthRecord',
            $record?->id,
            'Checked the grammar and terms of an incident report for LRN '.$lrn,
            ['fields' => array_keys($fields), 'suggested' => array_keys($suggestions)]
        );

        return response()->json([
            'suggestions' => (object) $suggestions,
            'checked' => array_keys($fields),
        ]);
    }

    /**
     * One filed report on the school's Incident Report form, to print and
     * sign: the letterhead, the learner, the incident, the physical
     * examination, the F-DAR chart, and the four signatories.
     *
     * Everyone who may read the report may print it — the adviser signs it
     * too. Scoped like a withdrawal (this school, this learner) and recorded
     * on the audit trail.
     */
    public function printReport(Request $request, string $lrn, int $id): View
    {
        abort_unless($this->mayRead($request, $lrn), 403);
        abort_unless(SchemaCache::hasTable('student_incident_reports'), 404);

        $report = StudentIncidentReport::query()
            ->forLearner($lrn, $request->session()->get('active_institution_id'))
            ->find($id);

        abort_if($report === null, 404);

        AuditTrail::record(
            'printed',
            'StudentIncidentReport',
            (int) $report->id,
            'Printed the incident report of '.$report->occurred_at?->toDateString().' for LRN '.$lrn
        );

        return view('dashboard.incident-report-print', $this->sheet($request, $lrn, $report));
    }

    /**
     * The same form with nothing charted on it, for an incident written up by
     * hand first. The learner's own lines and the signatories are filled in;
     * everything about the incident is a ruled line. The clinic's, since it
     * is the clinic that charts.
     */
    public function printBlank(Request $request, string $lrn): View
    {
        abort_unless($this->mayWrite($request, $lrn), 403);

        $sheet = $this->sheet($request, $lrn, null);

        AuditTrail::record(
            'printed',
            'StudentHealthRecord',
            $sheet['recordId'],
            'Printed a blank incident report form for LRN '.$lrn
        );

        return view('dashboard.incident-report-print', $sheet);
    }

    /**
     * Everything the printed form carries.
     *
     * @return array<string, mixed>
     */
    private function sheet(Request $request, string $lrn, ?StudentIncidentReport $report): array
    {
        $institutionId = (int) $request->session()->get('active_institution_id') ?: null;
        $schoolName = (string) $request->session()->get('active_school_name', '');

        // The learner as they were placed the year the incident happened, or
        // as they are now where that year's row is gone.
        $record = ($report ? StudentHealthRecord::currentForStudent($lrn, $institutionId, (string) $report->school_year) : null)
            ?? StudentHealthRecord::currentForStudent($lrn, $institutionId);

        $details = is_array($record?->student_details) ? $record->student_details : [];
        [$grade, $section] = FeedingBeneficiarySummary::splitSection((string) $record?->section);
        $grade = $grade === 'Unassigned' ? '' : $grade;
        $section = $section === 'Unassigned' ? '' : $section;

        $letterhead = SchoolLetterhead::for($institutionId, $schoolName);

        // Prepared by the clinic nurse: the nurse who charted it, or the
        // school's nurse where a clinic teacher or clinic staff charted it —
        // their own name stays on the chart's signature below.
        $nurse = $report !== null && $report->reported_by_role === 'school_nurse' && trim((string) $report->reported_by_name) !== ''
            ? (string) $report->reported_by_name
            : SchoolSignatories::preparedBy($institutionId, $schoolName);

        $names = [
            'nurse' => $nurse,
            'adviser' => SchoolSignatories::classAdviser($institutionId, $grade, $section),
            // Nobody holds a curriculum head's account: a line to sign on,
            // never an invented name.
            'curriculum_head' => '',
            'principal' => SchoolSignatories::notedBy($institutionId, $schoolName),
        ];

        $signatories = [];
        foreach (StudentIncidentReport::SIGNATORIES as $key => $signatory) {
            $signatories[] = $signatory + ['name' => $names[$key] ?? ''];
        }

        $occurred = $report?->occurred_at ? CarbonImmutable::parse($report->occurred_at->toDateString()) : null;

        return [
            'report' => $report !== null ? $this->present($report) : null,
            'recordId' => $record?->id,
            'schoolYear' => (string) ($report?->school_year ?: ($record?->school_year ?: StudentHealthRecord::currentSchoolYear())),
            'letterhead' => $letterhead,
            'masthead' => SchoolLetterhead::masthead($letterhead),
            'seals' => SchoolLetterhead::seals(),
            'learner' => [
                'name' => $this->learnerName($record, $details),
                'lrn' => $lrn,
                'grade' => $grade,
                'section' => $section,
                'sex' => $record ? FeedingBeneficiarySummary::sexOf($record) : '',
                'age' => $this->ageOn($details, $occurred),
                'guardian' => trim((string) ($details['parent_guardian'] ?? '')),
            ],
            'categories' => StudentIncidentReport::CATEGORIES,
            'severities' => StudentIncidentReport::SEVERITIES,
            'consciousness' => IncidentPhysicalExam::CONSCIOUSNESS,
            'vitals' => IncidentPhysicalExam::VITALS,
            'signatories' => $signatories,
        ];
    }

    /** "Cruz, Juan P." — surname first, as a school form lists a learner. */
    private function learnerName(?StudentHealthRecord $record, array $details): string
    {
        $first = trim((string) ($details['first_name'] ?? ''));
        $middle = trim((string) ($details['middle_name'] ?? ''));
        $last = trim((string) ($details['last_name'] ?? ''));

        if ($first === '' && $last === '') {
            return trim((string) $record?->student_name);
        }

        $initial = $middle !== '' ? ' '.mb_strtoupper(mb_substr($middle, 0, 1)).'.' : '';

        return trim($last.', '.$first.$initial, ', ');
    }

    /**
     * Completed years on the day of the incident, from the birth date on the
     * learner's card; the card's own age where it holds no birth date; blank
     * where it holds neither.
     */
    private function ageOn(array $details, ?CarbonImmutable $on): string
    {
        $birth = BmiClassifier::birthDateFrom($details);

        if ($birth !== null) {
            $years = $birth->diffInYears($on ?? CarbonImmutable::parse((string) now((string) config('app.school_timezone'))->toDateString()));

            return $years >= 0 ? (string) (int) floor($years) : '';
        }

        $age = trim((string) ($details['age'] ?? ''));

        return is_numeric($age) ? (string) (int) $age : '';
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

        // Only while the learner is still in the section: an adviser the
        // learner has left no longer reads their record.
        return strcasecmp(trim((string) $record->section), trim($grade.' / '.$section)) === 0
            && LearnerPlacement::isOpen($record);
    }

    /**
     * Who may file one: the nurse or the clinic teacher, on a learner they
     * may read.
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

    /** Who may edit one: the nurse or the clinic teacher, on a learner they may read. */
    private function mayEdit(Request $request, string $lrn): bool
    {
        return StudentIncidentReport::canEdit((string) $request->session()->get('active_role'))
            && $this->mayRead($request, $lrn);
    }

    /** Who may withdraw one: the nurse, on a learner they may read. */
    private function mayWithdraw(Request $request, string $lrn): bool
    {
        return StudentIncidentReport::canWithdraw((string) $request->session()->get('active_role'))
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
        $stamp = StudentIncidentReport::supportsEditStamp() && $report->edited_at !== null;
        $exam = StudentIncidentReport::supportsPhysicalExam();

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
            // The physical examination under Data: every key for the form to
            // be filled from, and what was recorded for the list to read back.
            'physical_exam' => IncidentPhysicalExam::read($exam ? $report->physical_exam : null),
            'exam_summary' => IncidentPhysicalExam::summary($exam ? $report->physical_exam : null),
            'witnesses' => (string) $report->witnesses,
            'guardian_notified' => (bool) $report->guardian_notified,
            // The signature under the note: who charted it and as what. The
            // sheet is signed by the nurse, so the role is printed as a title.
            'reported_by' => (string) $report->reported_by_name,
            'reported_by_title' => $this->staffTitle((string) $report->reported_by_role),
            // Stored in UTC; shown on the school's clock, like the time charted.
            'filed_label' => $report->created_at?->copy()->setTimezone((string) config('app.school_timezone'))->format('d M Y, g:i A'),
            // The signature stands through an edit, so whoever changed the note
            // is named beside it rather than leaving their words under it.
            'edited_by' => $stamp ? (string) $report->edited_by_name : '',
            'edited_by_title' => $stamp ? $this->staffTitle((string) $report->edited_by_role) : '',
            'edited_label' => $stamp ? $report->edited_at->copy()->setTimezone((string) config('app.school_timezone'))->format('d M Y, g:i A') : '',
        ];
    }

    private function staffTitle(string $role): string
    {
        return match ($role) {
            'school_nurse' => 'School Nurse',
            'clinic_teacher' => 'Clinic Teacher',
            'clinic_staff' => 'Clinic Staff',
            'class_adviser' => 'Class Adviser',
            default => '',
        };
    }
}
