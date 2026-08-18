<?php

namespace App\Http\Controllers;

use App\Models\AttendanceImport;
use App\Models\FeedingAttendance;
use App\Models\StudentHealthRecord;
use App\Support\AttendanceSheetParser;
use App\Support\AttendanceSheetScanner;
use App\Support\AuditTrail;
use App\Support\EncryptedFileStorage;
use App\Support\FeedingAtRiskRule;
use App\Support\FeedingBeneficiarySummary;
use App\Support\FeedingProgramCycle;
use App\Support\SchemaCache;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class FeedingProgramController extends Controller
{
    private const PROGRAM_DURATION_DAYS = FeedingProgramCycle::DURATION_DAYS;

    /** Memoized per request — see hasReviewColumns(). */
    private ?bool $hasReviewColumns = null;

    public function index(Request $request): View
    {
        $activeRole = (string) $request->session()->get('active_role', '');
        $currentRouteName = (string) optional($request->route())->getName();
        $isNurseFeedingRoute = $currentRouteName === 'dashboard.school-nurse.feeding-program';
        $isReadOnly = $isNurseFeedingRoute || $activeRole === 'school_nurse';

        $institutionId = $request->session()->get('active_institution_id');

        // A Feeding Coordinator is scoped to a single institution, so the
        // relevant slice is grade level, not school. Grade level lives in the
        // plain "section" column as "Grade X / Section"; we derive the options
        // and filter in PHP so nothing depends on the encrypted columns.
        $selectedGrade = trim((string) $request->query('grade', 'all'));
        if ($selectedGrade === '') {
            $selectedGrade = 'all';
        }

        $eligibleStudents = collect();
        if (SchemaCache::hasTable('student_health_records')) {
            $studentsQuery = StudentHealthRecord::query();
            if ($institutionId) {
                $studentsQuery->where('institution_id', $institutionId);
            }

            // nutritional_status is encrypted at rest, so eligibility filtering
            // happens in PHP after fetch.
            $eligibleStudents = $studentsQuery
                ->forCurrentSchoolYear()
                ->get()
                ->filter(fn (StudentHealthRecord $record): bool => $this->isEnrolledBeneficiary($record))
                ->values();
        }

        // Always offer the full DepEd grade range (Grade 1–12), regardless of
        // which grades currently have beneficiaries on file.
        $gradeOptions = collect(range(1, 12))
            ->map(fn (int $level): string => 'Grade '.$level)
            ->values();

        if ($selectedGrade !== 'all' && ! $gradeOptions->contains($selectedGrade)) {
            $selectedGrade = 'all';
        }

        // student_name is encrypted at rest, so sorting happens in PHP.
        $students = $eligibleStudents
            ->when($selectedGrade !== 'all', fn ($collection) => $collection->filter(
                fn (StudentHealthRecord $record): bool => $this->resolveGradeLevel((string) $record->section) === $selectedGrade
            ))
            ->sortBy('student_name')
            ->values();

        $programDay = $this->resolveProgramDay($institutionId);

        // One rule, one reading. The at-risk flag, the rate beside a learner
        // and the threshold printed on the alert all come from the school's own
        // rule here — reading the stored is_at_risk column instead would leave
        // this page disagreeing with the Dashboard until the next import, and a
        // hardcoded percentage would disagree with it forever.
        $rule = FeedingAtRiskRule::forInstitution($institutionId);
        $marksByRecord = FeedingBeneficiarySummary::marksByRecord($students);

        // How many sessions a learner must have attended to clear the
        // threshold, over the feeding days the school has actually held.
        $sessionsHeld = $this->sessionsHeld($institutionId, StudentHealthRecord::currentSchoolYear());
        $atRiskThresholdCount = (int) ceil($sessionsHeld * ($rule->thresholdPercent() / 100));

        $studentRows = $students->map(function (StudentHealthRecord $record) use ($marksByRecord, $rule): array {
            $currentWeight = (float) $record->weight;
            // No invented baseline. A learner the adviser has not measured yet
            // has no baseline weight or BMI, and the roster prints an em dash —
            // guessing "current minus 0.7" would put a measurement that never
            // happened on a child's health record, and show it as improvement.
            $baselineWeight = $record->baseline_weight_kg !== null ? (float) $record->baseline_weight_kg : null;
            $bmiCurrent = (float) $record->bmi_value;
            $bmiBaseline = $record->baseline_bmi_value !== null ? (float) $record->baseline_bmi_value : null;
            $resolvedStatus = $this->normalizeNutritionalStatus($record->nutritional_status, $bmiCurrent);

            $trendClass = 't-stable';
            $trendLabel = 'Stable';
            $bmiClass = 'bmi-up';

            $status = strtolower((string) $resolvedStatus);
            $isAttendanceEligible = $this->isAttendanceEligible($resolvedStatus);
            if (str_contains($status, 'normal')) {
                $trendClass = 't-improving';
                $trendLabel = 'Improving';
            } elseif (str_contains($status, 'severe') || str_contains($status, 'wasted') || str_contains($status, 'underweight')) {
                $trendClass = 't-regressing';
                $trendLabel = 'Regressing';
                $bmiClass = 'bmi-down';
            }

            // Attendance is counted over the sessions the rule judged: present
            // out of confirmed. An unconfirmed mark votes neither way, and the
            // 120-day cycle length is not a denominator — a learner is not 3%
            // attended because the programme has 116 days left to run.
            $marks = $marksByRecord->get($record->id, []);
            $confirmedSessions = count(array_filter($marks, static fn ($mark) => $mark !== null));
            $attendanceCount = $rule->presentCount($marks);
            $attendancePercent = $rule->attendanceRate($marks);

            $details = is_array($record->student_details) ? $record->student_details : [];

            return [
                'id' => $record->id,
                'student_name' => $record->student_name,
                'section' => $record->section,
                'grade_level' => $this->resolveGradeLevel((string) $record->section),
                'gender' => $this->resolveGenderLabel((string) ($details['gender'] ?? '')),
                'baseline_weight' => $baselineWeight === null ? null : number_format($baselineWeight, 1),
                'current_weight' => number_format($currentWeight, 1),
                'bmi_range' => $bmiBaseline === null
                    ? number_format($bmiCurrent, 1)
                    : number_format($bmiBaseline, 1).' - '.number_format($bmiCurrent, 1),
                'bmi_class' => $bmiClass,
                'bmi_value' => number_format($bmiCurrent, 1),
                // Null, not 0: no confirmed session is not a turnout of nothing.
                'attendance' => $confirmedSessions > 0
                    ? $attendanceCount.'/'.$confirmedSessions.' sessions'
                    : 'No session yet',
                'attendance_count' => $attendanceCount,
                'attendance_sessions' => $confirmedSessions,
                'attendance_percent' => $attendancePercent === null ? null : (int) round($attendancePercent),
                'nutritional_status' => $resolvedStatus,
                'is_attendance_eligible' => $isAttendanceEligible,
                'is_at_risk' => $rule->isAtRisk($marks),
                'trend_label' => $trendLabel,
                'trend_class' => $trendClass,
            ];
        })->values();

        $studentCount = $studentRows->count();
        $improvingCount = $studentRows->where('trend_label', 'Improving')->count();
        $totalPresentAttendance = (int) $studentRows->sum('attendance_count');
        $totalConfirmedSessions = (int) $studentRows->sum('attendance_sessions');
        $attendanceRate = $totalConfirmedSessions > 0
            ? (int) round(($totalPresentAttendance / $totalConfirmedSessions) * 100)
            : null;

        $atRiskStudents = $studentRows
            ->filter(fn (array $student): bool => (bool) ($student['is_at_risk'] ?? false))
            ->values();

        $latestImport = SchemaCache::hasTable('attendance_imports')
            ? AttendanceImport::latestForPeriod($institutionId)
            : null;

        return view('feedingcor-dashboard.feed-program', [
            'isReadOnly' => $isReadOnly,
            'programStats' => [
                'enrolled_students' => $studentCount,
                'program_day' => $programDay.'/'.self::PROGRAM_DURATION_DAYS,
                'avg_attendance' => $attendanceRate === null ? '—' : $attendanceRate.'%',
                'improving_rate' => $studentCount > 0 ? (int) round(($improvingCount / $studentCount) * 100).'%' : '0%',
                'improving_hint' => $improvingCount.' of '.$studentCount.' students',
                'at_risk_count' => $atRiskStudents->count(),
                // The school's own threshold, never a constant: the System
                // Admin sets it per school, and the flags beside it were
                // decided by exactly this figure.
                'at_risk_threshold' => rtrim(rtrim(number_format($rule->thresholdPercent(), 1), '0'), '.'),
                'at_risk_rule' => $rule->describe(),
                'at_risk_threshold_count' => $atRiskThresholdCount,
                'sessions_held' => $sessionsHeld,
            ],
            'students' => $studentRows,
            'atRiskStudents' => $atRiskStudents,
            'gradeOptions' => $gradeOptions,
            'selectedGrade' => $selectedGrade,
            'hasGradeFilter' => $gradeOptions->isNotEmpty(),
            'scanningEnabled' => ! $isReadOnly
                && config('feeding.scanning.enabled')
                && AttendanceSheetScanner::isConfigured(),
            'pendingReviewCount' => $this->pendingReviewCount($institutionId),
            'attendanceRoster' => $this->buildAttendanceRoster($studentRows),
            'latestImport' => $latestImport,
        ]);
    }

    /**
     * The one list of beneficiaries this page renders: every eligible learner
     * exactly once, carrying both their measurements and their attendance.
     *
     * There used to be two tables — a beneficiaries table and an attendance
     * table — so every learner was drawn twice, with grade, section and
     * attendance repeated between them. One learner is one row.
     *
     * A learner no sheet has covered yet still appears, with their attendance
     * figures left null for the view to render as "—". Absence from an
     * attendance sheet is not a fact about the learner, and dropping them from
     * the list would hide a beneficiary the coordinator is responsible for.
     *
     * Attendance is counted in PHP rather than with a SUM(CASE WHEN is_present
     * ...): a NULL mark is one no human has confirmed, and it must stay out of
     * both the numerator and the denominator (see FeedingAtRiskRule).
     *
     * @param  Collection<int, array<string, mixed>>  $studentRows
     * @return Collection<int, array<string, mixed>>
     */
    private function buildAttendanceRoster(Collection $studentRows): Collection
    {
        if ($studentRows->isEmpty()) {
            return collect();
        }

        // Every recorded session counts here, including one dated ahead of
        // today. This table reports what the uploaded sheets say about each
        // learner, so it has to add up to the file the coordinator just
        // uploaded — a sheet covering three days that showed one absence read
        // as the upload having failed. The at-risk flag is a separate question
        // and keeps its own, stricter rule: refreshAttendanceRiskFlags() still
        // ignores sessions that have not happened, so nobody is flagged for
        // missing a feeding day that is still in the future.
        $marksByRecord = SchemaCache::hasTable('feeding_attendances')
            ? FeedingAttendance::query()
                ->whereIn('student_health_record_id', $studentRows->pluck('id')->all())
                ->orderBy('session_date')
                ->get(array_merge(
                    ['student_health_record_id', 'session_date', 'is_present'],
                    $this->hasReviewColumns() ? ['needs_review'] : []
                ))
                ->groupBy('student_health_record_id')
            : collect();

        return $studentRows
            ->map(function (array $student) use ($marksByRecord): array {
                // Null where the adviser has not measured a baseline yet, so
                // the roster prints an em dash instead of a weight change
                // against a reading nobody took.
                $baselineWeight = $student['baseline_weight'] === null ? null : (float) $student['baseline_weight'];
                $currentWeight = (float) ($student['current_weight'] ?? 0);

                $row = [
                    'student_name' => $student['student_name'],
                    'section' => $student['section'],
                    'grade_level' => $student['grade_level'],
                    'gender' => $student['gender'],
                    'nutritional_status' => $student['nutritional_status'],
                    'baseline_weight' => $baselineWeight,
                    'current_weight' => $currentWeight,
                    'weight_change' => $baselineWeight === null ? null : round($currentWeight - $baselineWeight, 1),
                    'is_at_risk' => (bool) ($student['is_at_risk'] ?? false),
                    'sessions' => 0,
                    'present' => 0,
                    'absent' => 0,
                    'pending' => 0,
                    'rate' => null,
                    'last_session' => null,
                ];

                $marks = $marksByRecord->get($student['id']);

                if ($marks === null || $marks->isEmpty()) {
                    return $row; // On no sheet yet — listed, with nothing claimed.
                }

                $isPending = fn ($mark): bool => (bool) ($mark->needs_review ?? false) || $mark->is_present === null;
                $pending = $marks->filter($isPending);
                $present = $marks->reject($isPending)->filter(fn ($mark): bool => $mark->is_present === true);
                $absent = $marks->reject($isPending)->filter(fn ($mark): bool => $mark->is_present === false);
                $confirmed = $present->count() + $absent->count();

                return array_merge($row, [
                    'sessions' => $marks->count(),
                    'present' => $present->count(),
                    'absent' => $absent->count(),
                    'pending' => $pending->count(),
                    'rate' => $confirmed > 0 ? (int) round(($present->count() / $confirmed) * 100) : null,
                    'last_session' => optional($marks->last())->session_date,
                ]);
            })
            ->values();
    }

    /**
     * Whether the photo-scan/review migration has been applied here.
     *
     * The whole review layer is additive, so a database that has not run the
     * migration must keep working exactly as it did before — spreadsheet import,
     * at-risk recomputation and the program page all still function, with
     * scanning simply unavailable. Guarding the columns (not just the table)
     * follows the same Schema::hasTable pattern used across these controllers.
     * Memoized because it is consulted on every attendance write.
     */
    private function hasReviewColumns(): bool
    {
        // Cached on the instance, not in a static: the controller is built per
        // request, so this stays a single lookup per request without pinning a
        // schema fact for the lifetime of the process.
        return $this->hasReviewColumns ??= SchemaCache::hasTable('feeding_attendances')
            && SchemaCache::hasColumn('feeding_attendances', 'needs_review');
    }

    /** Scanned marks in this school still waiting on a human decision. */
    private function pendingReviewCount(?int $institutionId): int
    {
        if (! $this->hasReviewColumns()) {
            return 0;
        }

        return FeedingAttendance::query()
            ->awaitingReview()
            ->whereIn(
                'student_health_record_id',
                StudentHealthRecord::query()
                    ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
                    ->select('id')
            )
            ->count();
    }

    /**
     * The coordinator uploads a filled feeding-attendance sheet (CSV/XLSX).
     * We read each learner's session-by-session attendance, match every row to
     * a student in this school, record the sessions, and recompute at-risk from
     * attendance alone — nobody is flagged just for their nutritional status.
     */
    public function importAttendance(Request $request): RedirectResponse
    {
        $activeRole = strtolower(trim((string) $request->session()->get('active_role', '')));
        $allowedCoordinatorRoles = ['feeding_coor', 'feedingcoor', 'feeding_coordinator', 'feeding coordinator'];
        if (! in_array($activeRole, $allowedCoordinatorRoles, true)) {
            return redirect()->route('login')->with('error', 'Only the Feeding Coordinator can upload attendance sheets.');
        }

        $request->validate([
            'attendance_file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx,xls'],
            'grade' => ['nullable', 'string', 'max:255'],
        ]);

        if (! SchemaCache::hasTable('student_health_records') || ! SchemaCache::hasTable('feeding_attendances')) {
            return back()->with('error', 'Attendance tracking tables are not ready. Run migrations first.');
        }

        $institutionId = $request->session()->get('active_institution_id');
        $selectedGrade = trim((string) $request->input('grade', 'all'));
        if ($selectedGrade === '') {
            $selectedGrade = 'all';
        }

        $file = $request->file('attendance_file');

        try {
            $parsed = (new AttendanceSheetParser)->parse($file);
        } catch (Throwable $e) {
            return back()->with('error', 'Could not read the uploaded file. Make sure it is a valid CSV or Excel sheet. ('.$e->getMessage().')');
        }

        if (! empty($parsed['error'])) {
            return back()->with('error', $parsed['error']);
        }
        if (empty($parsed['sessions'])) {
            return back()->with('error', 'No attendance marks were found. Make sure there is at least one session column to the right of NAME / GRADE / SECTION with cells marked (P or H/M = present, A or blank = absent).');
        }
        if (empty($parsed['students'])) {
            return back()->with('error', 'No learner rows were found under the NAME column.');
        }

        $pool = $this->buildMatchPool($institutionId);

        $matched = [];
        $unmatched = [];
        foreach ($parsed['students'] as $row) {
            $poolIndex = $this->matchStudent($pool, $row['name'], $row['grade'], $row['section']);
            if ($poolIndex === null) {
                $unmatched[] = $row['name'];

                continue;
            }
            $pool[$poolIndex]['matched'] = true;
            $matched[$pool[$poolIndex]['id']] = $row['present'];
        }

        if (empty($matched)) {
            return back()->with('error', 'None of the '.count($parsed['students']).' rows matched a learner in this school. Check that the names, grade, and section match the adviser records.');
        }

        $now = now();
        $hasReviewColumns = $this->hasReviewColumns();
        $hasRecorder = SchemaCache::hasColumn('feeding_attendances', 'recorded_by_name');
        // Whoever uploaded the sheet is who these marks came from — encrypted
        // here because an upsert bypasses the model's casts.
        $uploader = (string) $request->session()->get('active_name', 'Feeding Coordinator');
        $upserts = [];
        foreach ($matched as $recordId => $present) {
            foreach ($parsed['sessions'] as $index => $session) {
                $row = [
                    'student_health_record_id' => $recordId,
                    'session_date' => $session['date'],
                    'is_present' => (bool) ($present[$index] ?? false),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if ($hasReviewColumns) {
                    // A spreadsheet cell is an explicit human entry, so it is
                    // confirmed on arrival — only scanned marks await review.
                    $row['source'] = FeedingAttendance::SOURCE_SPREADSHEET;
                    $row['needs_review'] = false;
                }

                if ($hasRecorder) {
                    $row['recorded_by_name'] = Crypt::encryptString($uploader);
                }

                $upserts[] = $row;
            }
        }

        // Everything lands in a single transaction so a mid-way failure never
        // leaves a half-written period (no silent partial write). The batch
        // record is created here too, so "attendance uploaded for this period"
        // is only ever true once the whole import succeeded.
        DB::transaction(function () use ($upserts, $institutionId, $file, $matched, $parsed, $unmatched, $request, $hasReviewColumns, $hasRecorder): void {
            // A re-uploaded spreadsheet supersedes a pending scanned mark for
            // the same session, and clears its review flag along with it.
            $updateColumns = $hasReviewColumns
                ? ['is_present', 'source', 'needs_review', 'updated_at']
                : ['is_present', 'updated_at'];

            if ($hasRecorder) {
                $updateColumns[] = 'recorded_by_name';
            }

            FeedingAttendance::query()->upsert(
                $upserts,
                ['student_health_record_id', 'session_date'],
                $updateColumns
            );

            $this->refreshAttendanceRiskFlags($institutionId);

            $storedPath = EncryptedFileStorage::store($file, 'feeding-attendance-sheets/'.($institutionId ?? 'unscoped'));

            AttendanceImport::create([
                'institution_id' => $institutionId,
                'school_year' => StudentHealthRecord::currentSchoolYear(),
                'uploaded_by_name' => (string) $request->session()->get('active_name', 'Feeding Coordinator'),
                'original_filename' => $file->getClientOriginalName(),
                'stored_path' => $storedPath,
                'sessions_count' => count($parsed['sessions']),
                'matched_count' => count($matched),
                'unmatched_count' => count($unmatched),
                'row_errors' => array_values($unmatched),
            ]);
        });

        // No success banner: the page the coordinator lands on already shows the
        // result — the learners, their attendance, and who is flagged at-risk.
        // Only problems worth acting on are reported below.
        // The Attendance tab is where imported marks land and are read.
        $redirect = redirect()->route('dashboard.feedingcor-attendance', ['grade' => $selectedGrade]);

        if (! empty($unmatched)) {
            // The names themselves are deliberately not echoed back: a row that
            // matched nobody is not a learner of this school, and an unverified
            // name off an uploaded sheet has no business being rendered as one.
            // The batch keeps them in its encrypted row_errors for the audit.
            $redirect->with('error', count($unmatched).' row(s) were skipped because they are not on the adviser records for this school.');
        }

        return $redirect;
    }

    /**
     * The coordinator photographs the physically-marked attendance sheet for one
     * session; Claude reads it against the known roster (see
     * AttendanceSheetScanner) and every mark it could not read confidently lands
     * in the review queue instead of being guessed.
     *
     * Nothing is written unless the whole scan succeeds, and the photo is kept
     * (encrypted) only while marks from it are still unconfirmed — a reviewer
     * cannot resolve a "?" without seeing the sheet.
     */
    public function scanAttendancePhoto(Request $request, AttendanceSheetScanner $scanner): RedirectResponse
    {
        if (! $this->isFeedingCoordinator($request)) {
            return redirect()->route('login')->with('error', 'Only the Feeding Coordinator can upload attendance sheets.');
        }

        if (! config('feeding.scanning.enabled') || ! AttendanceSheetScanner::isConfigured()) {
            return back()->with('error', 'Attendance photo scanning is not configured on this server.');
        }

        if (! $this->hasReviewColumns()) {
            return back()->with('error', 'Attendance scanning is not ready on this database. Run migrations first.');
        }

        $request->validate([
            'attendance_photo' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:'.(int) config('feeding.scanning.max_upload_kb', 10240)],
            // Optional: the sheet's own column headers are the source of truth
            // for dates. This only anchors a partial header ("8", "Oct 8") and
            // stands in for a single column whose header cannot be read.
            'session_date' => ['nullable', 'date', 'before_or_equal:today'],
            'grade' => ['nullable', 'string', 'max:255'],
        ]);

        if (! SchemaCache::hasTable('student_health_records') || ! SchemaCache::hasTable('feeding_attendances')) {
            return back()->with('error', 'Attendance tracking tables are not ready. Run migrations first.');
        }

        $institutionId = $request->session()->get('active_institution_id');
        $anchorDate = filled($request->input('session_date'))
            ? Carbon::parse((string) $request->input('session_date'))->toDateString()
            : null;
        $selectedGrade = trim((string) $request->input('grade', 'all')) ?: 'all';
        $photo = $request->file('attendance_photo');

        $roster = $this->buildScanRoster($institutionId);
        if ($roster === []) {
            return back()->with('error', 'No learners are on file for this school yet, so there is nothing to match the sheet against.');
        }

        try {
            $result = $scanner->scan($photo, $roster, $anchorDate);
        } catch (Throwable $e) {
            // Deliberately no partial write: a failed scan leaves the period
            // exactly as it was.
            return back()->with('error', 'Could not read the attendance photo. '.$e->getMessage());
        }

        $sessions = $this->normalizeScannedSessions($result, $roster);

        if ($sessions === []) {
            return back()->with('error', 'No dated attendance column could be read from that photo, so nothing was recorded. '.$result['note']);
        }

        $counts = ['present' => 0, 'absent' => 0, 'unclear' => 0];
        foreach ($sessions as $session) {
            foreach ($session['marks'] as $mark) {
                $counts[match ($mark) {
                    AttendanceSheetScanner::MARK_PRESENT => 'present',
                    AttendanceSheetScanner::MARK_ABSENT => 'absent',
                    default => 'unclear',
                }]++;
            }
        }

        $sessionDates = array_column($sessions, 'date');
        sort($sessionDates);
        $dateSummary = count($sessionDates) === 1
            ? $sessionDates[0]
            : count($sessionDates).' sessions ('.$sessionDates[0].' to '.end($sessionDates).')';

        $hasRecorder = SchemaCache::hasColumn('feeding_attendances', 'recorded_by_name');
        $photographer = (string) $request->session()->get('active_name', 'Feeding Coordinator');

        $import = DB::transaction(function () use ($result, $roster, $sessions, $sessionDates, $institutionId, $photo, $request, $counts, $hasRecorder, $photographer): AttendanceImport {
            $now = now();
            $storedPath = EncryptedFileStorage::store($photo, 'feeding-attendance-photos/'.($institutionId ?? 'unscoped'));

            $import = AttendanceImport::create([
                'institution_id' => $institutionId,
                'school_year' => StudentHealthRecord::currentSchoolYear(),
                'uploaded_by_name' => (string) $request->session()->get('active_name', 'Feeding Coordinator'),
                'original_filename' => $photo->getClientOriginalName(),
                'stored_path' => $storedPath,
                'kind' => AttendanceImport::KIND_PHOTO,
                // The batch is stamped with its latest session; the individual
                // dates live on the attendance rows themselves.
                'session_date' => end($sessionDates),
                'sessions_count' => count($sessions),
                'matched_count' => $counts['present'] + $counts['absent'],
                'unmatched_count' => 0,
                'unclear_count' => $counts['unclear'],
                'row_errors' => $result['note'] !== '' ? [$result['note']] : [],
            ]);

            $upserts = [];
            foreach ($sessions as $session) {
                foreach ($roster as $entry) {
                    $mark = $session['marks'][$entry['id']] ?? AttendanceSheetScanner::MARK_UNCLEAR;
                    $unclear = $mark === AttendanceSheetScanner::MARK_UNCLEAR;

                    $row = [
                        'student_health_record_id' => $entry['record_id'],
                        'session_date' => $session['date'],
                        // NULL, not false — an unread mark is not an absence.
                        'is_present' => $unclear ? null : ($mark === AttendanceSheetScanner::MARK_PRESENT),
                        'needs_review' => $unclear,
                        'source' => FeedingAttendance::SOURCE_PHOTO_SCAN,
                        'attendance_import_id' => $import->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if ($hasRecorder) {
                        // Who photographed the sheet these marks were read from
                        // — encrypted here, since an upsert bypasses the casts.
                        $row['recorded_by_name'] = Crypt::encryptString($photographer);
                    }

                    $upserts[] = $row;
                }
            }

            $updateColumns = ['is_present', 'needs_review', 'source', 'attendance_import_id', 'updated_at'];
            if ($hasRecorder) {
                $updateColumns[] = 'recorded_by_name';
            }

            FeedingAttendance::query()->upsert(
                $upserts,
                ['student_health_record_id', 'session_date'],
                $updateColumns
            );

            $this->refreshAttendanceRiskFlags($institutionId);

            return $import;
        });

        AuditTrail::record(
            'created',
            'AttendanceImport',
            $import->id,
            "Attendance photo scanned for {$dateSummary}: {$counts['present']} present, {$counts['absent']} absent, {$counts['unclear']} needing review"
        );

        // Nothing pending means nothing to look at the photo for.
        $this->purgeScanPhotoIfReviewed($import);

        // The Attendance tab is where imported marks land and are read.
        $redirect = redirect()->route('dashboard.feedingcor-attendance', ['grade' => $selectedGrade]);

        if ($result['unreadable']) {
            return $redirect->with('error', 'The photo could not be read — every mark for '.$dateSummary.' is waiting for review. '.$result['note']);
        }

        $redirect->with('success', 'Sheet scanned for '.$dateSummary.': '.$counts['present'].' present, '.$counts['absent'].' absent.');

        if ($counts['unclear'] > 0) {
            $redirect->with('error', $counts['unclear'].' mark(s) could not be read confidently and are waiting for your review — they do not count either way until you confirm them.');
        }

        return $redirect;
    }

    /**
     * Turns a scan result into the sessions we are willing to write.
     *
     * Two rules are enforced here, at the point of write, rather than trusting
     * the scanner alone — a contradictory result must never be able to put an
     * unread mark into a learner's record:
     *
     * 1. A scan that calls the sheet unreadable is not trusted for any mark,
     *    even the ones it returned confidently.
     * 2. Every session needs a real, non-future date. A column we cannot date is
     *    dropped rather than filed under a guess.
     *
     * @param  array{sessions?: mixed, unreadable: bool, note: string}  $result
     * @param  list<array{id:int,record_id:int,name:string,grade:string,section:string}>  $roster
     * @return list<array{date: string, marks: array<int,string>}>
     */
    private function normalizeScannedSessions(array $result, array $roster): array
    {
        $rosterIds = array_column($roster, 'id');
        $today = now()->toDateString();
        $sessions = [];
        $seen = [];

        foreach ((array) ($result['sessions'] ?? []) as $session) {
            if (! is_array($session)) {
                continue;
            }

            $date = trim((string) ($session['date'] ?? ''));
            if ($date === '' || $date > $today || ! strtotime($date)) {
                continue;
            }

            $date = Carbon::parse($date)->toDateString();
            if (isset($seen[$date])) {
                continue;
            }

            $seen[$date] = true;
            $marks = is_array($session['marks'] ?? null) ? $session['marks'] : [];

            $sessions[] = [
                'date' => $date,
                'marks' => $result['unreadable']
                    ? array_fill_keys($rosterIds, AttendanceSheetScanner::MARK_UNCLEAR)
                    : $marks,
            ];
        }

        return $sessions;
    }

    /** The queue of scanned marks no human has confirmed yet. */
    public function attendanceReviewQueue(Request $request): View
    {
        $institutionId = $request->session()->get('active_institution_id');

        $pending = collect();
        if ($this->hasReviewColumns()) {
            $pending = FeedingAttendance::query()
                ->awaitingReview()
                ->whereIn(
                    'student_health_record_id',
                    StudentHealthRecord::query()
                        ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
                        ->select('id')
                )
                ->with('studentHealthRecord')
                ->orderBy('session_date')
                ->get()
                ->map(fn (FeedingAttendance $row) => [
                    'id' => $row->id,
                    'student_name' => (string) ($row->studentHealthRecord->student_name ?? 'Unknown learner'),
                    'section' => (string) ($row->studentHealthRecord->section ?? ''),
                    'session_date' => optional($row->session_date)->toDateString(),
                    'import_id' => $row->attendance_import_id,
                ])
                ->values();
        }

        return view('feedingcor-dashboard.attendance-review', [
            'pending' => $pending,
            'ruleDescription' => FeedingAtRiskRule::forInstitution($institutionId)->describe(),
        ]);
    }

    /**
     * The fast lane for a coordinator standing at the feeding line: one screen
     * listing every beneficiary, one tap per learner, one save. The alternative
     * — opening each learner in turn — is why sessions went unrecorded.
     *
     * Marks already on file for the chosen date pre-select their rows, so
     * re-opening the screen corrects a session instead of starting a blank one.
     */
    public function recordAttendanceForm(Request $request): View|RedirectResponse
    {
        if (! $this->isFeedingCoordinator($request)) {
            return redirect()->route('login')->with('error', 'Only the Feeding Coordinator can record attendance.');
        }

        $institutionId = $request->session()->get('active_institution_id');

        $sessionDate = filled($request->query('date'))
            ? Carbon::parse((string) $request->query('date'))
            : now();
        if ($sessionDate->isFuture()) {
            $sessionDate = now();
        }
        $sessionDate = $sessionDate->toDateString();

        $beneficiaries = collect();
        if (SchemaCache::hasTable('student_health_records')) {
            $beneficiaries = StudentHealthRecord::query()
                ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
                ->forCurrentSchoolYear()
                ->get()
                ->filter(fn (StudentHealthRecord $record): bool => $this->isEnrolledBeneficiary($record))
                ->values();
        }

        $existing = collect();
        if ($beneficiaries->isNotEmpty() && SchemaCache::hasTable('feeding_attendances')) {
            $existing = FeedingAttendance::query()
                ->whereIn('student_health_record_id', $beneficiaries->pluck('id'))
                ->whereDate('session_date', $sessionDate)
                ->get()
                ->keyBy('student_health_record_id');
        }

        // student_name is encrypted at rest, so sorting happens in PHP.
        $rows = $beneficiaries
            ->map(function (StudentHealthRecord $record) use ($existing): array {
                [$grade, $section] = $this->splitGradeSection((string) $record->section);
                $mark = $existing->get($record->id);

                return [
                    'id' => $record->id,
                    'name' => (string) $record->student_name,
                    'grade' => $grade,
                    'section' => $section,
                    // An unconfirmed scanned mark arrives with nothing selected,
                    // so saving this screen is what confirms it.
                    'mark' => match (true) {
                        $mark === null, (bool) ($mark->needs_review ?? false), $mark->is_present === null => '',
                        (bool) $mark->is_present => 'present',
                        default => 'absent',
                    },
                    'remarks' => (string) ($mark->remarks ?? ''),
                ];
            })
            ->sortBy(fn (array $row) => strtolower($row['name']))
            ->values();

        // A recorded session is closed as a whole, so this screen becomes a
        // record too: no inputs, no save. The same rule the Attendance tab's
        // sheet and this endpoint's POST apply, asked in one place.
        $sessionLocked = $this->sessionIsRecorded($beneficiaries->pluck('id')->map(fn ($id): int => (int) $id)->all(), $sessionDate);

        return view('feedingcor-dashboard.record-attendance', [
            'rows' => $rows,
            'sessionDate' => $sessionDate,
            'sessionLabel' => Carbon::parse($sessionDate)->format('M d, Y'),
            'today' => now()->toDateString(),
            'alreadyRecorded' => $existing->count(),
            'sessionLocked' => $sessionLocked,
            'isFeedingDay' => FeedingProgramCycle::isFeedingDay($sessionDate),
        ]);
    }

    /**
     * One learner's attendance, session by session — what the dashboard's
     * "View" opens from the at-risk list.
     *
     * Keyed by the record id, never by name: a learner's name has no business
     * in a URL, which is logged, shared and kept in browser history. The record
     * is re-scoped to the coordinator's school here, so an id off the wire
     * cannot reach another school's learner.
     */
    public function learnerAttendance(Request $request, int $record): View|RedirectResponse
    {
        if (! $this->isFeedingCoordinator($request)) {
            return redirect()->route('login')->with('error', 'Only the Feeding Coordinator can view attendance.');
        }

        $institutionId = $request->session()->get('active_institution_id');

        $learner = StudentHealthRecord::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->forCurrentSchoolYear()
            ->whereKey($record)
            ->first();

        if (! $learner) {
            return redirect()
                ->route('dashboard.feedingcor-dashboard')
                ->with('error', 'That learner is not on this school&rsquo;s roster.');
        }

        $columns = ['id', 'session_date', 'is_present', 'source'];
        if ($this->hasReviewColumns()) {
            $columns[] = 'needs_review';
        }
        if (SchemaCache::hasColumn('feeding_attendances', 'remarks')) {
            $columns[] = 'remarks';
        }

        $sessions = SchemaCache::hasTable('feeding_attendances')
            ? FeedingAttendance::query()
                ->where('student_health_record_id', $learner->id)
                ->whereDate('session_date', '<=', now()->toDateString())
                ->orderByDesc('session_date')
                ->get($columns)
            : collect();

        // Marks in date order, NULL where a scan is still unconfirmed — the
        // exact input the rule judges, so this page and the flag agree.
        $marks = $sessions
            ->sortBy('session_date')
            ->map(fn (FeedingAttendance $row) => ($row->needs_review ?? false) ? null : $row->is_present)
            ->values()
            ->all();

        $rule = FeedingAtRiskRule::forInstitution($institutionId);
        [$grade, $section] = $this->splitGradeSection((string) $learner->section);

        return view('feedingcor-dashboard.learner-attendance', [
            'learner' => [
                'name' => (string) $learner->student_name,
                'grade' => $grade,
                'section' => $section,
                'nutritional_status' => (string) $learner->nutritional_status,
            ],
            'sessions' => $sessions->map(fn (FeedingAttendance $row): array => [
                'date' => optional($row->session_date)->format('M d, Y'),
                'status' => match (true) {
                    (bool) ($row->needs_review ?? false), $row->is_present === null => 'unconfirmed',
                    (bool) $row->is_present => 'present',
                    default => 'absent',
                },
                'remarks' => trim((string) ($row->remarks ?? '')),
            ])->values(),
            'summary' => [
                'rate' => $rule->attendanceRate($marks),
                'present' => $rule->presentCount($marks),
                'confirmed' => count(array_filter($marks, static fn ($mark) => $mark !== null)),
                'unconfirmed' => count(array_filter($marks, static fn ($mark) => $mark === null)),
                'at_risk' => $rule->isAtRisk($marks),
                'threshold' => $rule->thresholdPercent(),
                'rule' => $rule->describe(),
            ],
        ]);
    }

    /**
     * One beneficiary's own page — what clicking a learner's name on the
     * Beneficiaries tab opens.
     *
     * Every figure here is derived at read time from the same sources the tabs
     * read: the enrolment stamp, the adviser's baseline/endline measurements,
     * and the confirmed attendance marks judged by the school's own threshold.
     * Nothing on this page is stored or hand-entered, so it cannot drift from
     * the cards that counted the learner.
     *
     * Keyed by record id, never by name — URLs are logged, shared and kept in
     * browser history. The record is re-scoped to the coordinator's school, so
     * an id off the wire cannot reach another school's learner. It is
     * deliberately not scoped to the current school year: the Beneficiaries tab
     * lets the coordinator read an earlier year, and the year is displayed from
     * the record rather than being a boundary — the institution is the boundary.
     */
    public function beneficiaryProfile(Request $request, int $record): View|RedirectResponse
    {
        if (! $this->isFeedingCoordinator($request)) {
            return redirect()->route('login')->with('error', 'Only the Feeding Coordinator can view beneficiaries.');
        }

        $institutionId = $request->session()->get('active_institution_id');

        $learner = StudentHealthRecord::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->whereKey($record)
            ->first();

        if (! $learner) {
            return redirect()
                ->route('dashboard.feedingcor-health-records')
                ->with('error', 'That learner is not on this school’s roster.');
        }

        // Every session that has covered this learner, oldest first. The
        // Attendance tab lists them and the rule judges them, both from this
        // one reading — so the sessions on screen are the sessions that decided
        // the rate printed above them.
        $columns = ['id', 'session_date', 'is_present', 'source'];
        if ($this->hasReviewColumns()) {
            $columns[] = 'needs_review';
            $columns[] = 'reviewed_by_name';
        }
        if (SchemaCache::hasColumn('feeding_attendances', 'remarks')) {
            $columns[] = 'remarks';
        }
        if (SchemaCache::hasColumn('feeding_attendances', 'recorded_by_name')) {
            $columns[] = 'recorded_by_name';
        }

        $sessions = SchemaCache::hasTable('feeding_attendances')
            ? FeedingAttendance::query()
                ->where('student_health_record_id', $learner->id)
                ->whereDate('session_date', '<=', now()->toDateString())
                ->orderBy('session_date')
                ->get($columns)
            : collect();

        // Marks in date order, NULL where a scanned mark is still unconfirmed —
        // the exact input the rule judges, so this page and the flag beside the
        // learner's name on the roster always agree.
        $marks = $sessions
            ->map(fn (FeedingAttendance $row) => ($row->needs_review ?? false) ? null : $row->is_present)
            ->values()
            ->all();

        $rule = FeedingAtRiskRule::forInstitution($institutionId);
        [$grade, $section] = $this->splitGradeSection((string) $learner->section);

        $present = $rule->presentCount($marks);
        $confirmed = count(array_filter($marks, static fn ($mark) => $mark !== null));

        $qualified = $this->isAttendanceEligible($learner->nutritional_status);
        $enrolled = $learner->feeding_enrolled_at !== null;
        $atRisk = $enrolled && $qualified && $rule->isAtRisk($marks);

        // A learner's height, weight and BMI live on the baseline columns once
        // the adviser has taken the measurement; before that the only reading
        // on file is the one captured with the health card, so it is the
        // fallback rather than showing a beneficiary with no measurement at all.
        $details = is_array($learner->student_details) ? $learner->student_details : [];

        return view('feedingcor-dashboard.beneficiary-detail', [
            'learner' => [
                'id' => $learner->id,
                'name' => (string) $learner->student_name,
                'grade' => $grade !== '' ? $grade : 'Unassigned',
                'section' => $section,
                // Sex lives in the encrypted details blob, so it is read here
                // rather than being a column anything could filter on.
                'sex' => $this->resolveGenderLabel((string) ($details['gender'] ?? '')),
                'school_year' => (string) $learner->school_year,
            ],
            'standing' => [
                'qualified' => $qualified,
                'enrolled' => $enrolled,
                'at_risk' => $atRisk,
            ],
            'baseline' => [
                'height_cm' => $this->measurement($learner->baseline_height_cm ?? $details['height_cm'] ?? null),
                'weight_kg' => $this->measurement($learner->baseline_weight_kg ?? $details['weight_kg'] ?? null),
                'bmi' => $this->measurement($learner->baseline_bmi_value ?? $learner->bmi_value ?? $details['bmi_value'] ?? null),
                'status' => trim((string) ($learner->baseline_nutritional_status ?: $learner->nutritional_status)),
                // Height-for-age is classified by the adviser at entry time and
                // kept in the encrypted details blob, not in its own column.
                'height_for_age' => trim((string) ($details['nutritional_status_height_for_age'] ?? '')),
                'recorded_at' => $learner->baseline_recorded_at?->format('F j, Y'),
            ],
            'endline' => [
                'height_cm' => $this->measurement($learner->endline_height_cm),
                'weight_kg' => $this->measurement($learner->endline_weight_kg),
                'bmi' => $this->measurement($learner->endline_bmi_value),
                'status' => trim((string) $learner->endline_nutritional_status),
                'recorded_at' => $learner->endline_recorded_at?->format('F j, Y'),
            ],
            'program' => [
                'enrolled_at' => $learner->feeding_enrolled_at?->format('F j, Y'),
                'enrolled_by' => trim((string) $learner->feeding_enrolled_by),
                'school_year' => (string) $learner->school_year,
                'total_days' => self::PROGRAM_DURATION_DAYS,
                'days_completed' => $this->sessionsHeld($institutionId, (string) $learner->school_year),
            ],
            'attendance' => [
                'present' => $present,
                'absent' => $confirmed - $present,
                'unconfirmed' => count($marks) - $confirmed,
                'confirmed' => $confirmed,
                // Null, not zero: no confirmed session is not a 0% turnout.
                'rate' => $rule->attendanceRate($marks),
                'threshold' => $rule->thresholdPercent(),
                'rule' => $rule->describe(),
            ],
            // The school's feeding days, newest month first, with this
            // learner's mark on each — see sessionCalendar().
            'sessionMonths' => $this->sessionCalendar($learner, $institutionId, $sessions),
        ]);
    }

    /**
     * Every feeding day the school has held, grouped by month with this
     * learner's mark on each.
     *
     * The calendar is the school's, not the learner's: a day the school fed but
     * no sheet covered this child appears as **unmarked**, never as an absence,
     * which is the only way a coordinator can see a gap and correct it. That is
     * also why the rows carry their date — the correction endpoint is keyed by
     * learner and date, so a missing mark is as correctable as a wrong one.
     *
     * @param  Collection<int, FeedingAttendance>  $sessions  this learner's marks, oldest first
     * @return list<array{label: string, rows: list<array<string, mixed>>}>
     */
    private function sessionCalendar(StudentHealthRecord $learner, ?int $institutionId, Collection $sessions): array
    {
        if (! SchemaCache::hasTable('feeding_attendances')) {
            return [];
        }

        $dates = FeedingAttendance::query()
            ->when($institutionId, fn ($q) => $q->whereIn(
                'student_health_record_id',
                StudentHealthRecord::query()
                    ->where('institution_id', $institutionId)
                    ->forCurrentSchoolYear((string) $learner->school_year ?: null)
                    ->select('id')
            ))
            ->whereDate('session_date', '<=', now()->toDateString())
            ->distinct()
            ->orderByDesc('session_date')
            ->pluck('session_date');

        $marks = $sessions->keyBy(fn (FeedingAttendance $row): string => optional($row->session_date)->toDateString());

        $months = [];

        foreach ($dates as $date) {
            $day = $date instanceof Carbon ? $date : Carbon::parse((string) $date);
            $key = $day->toDateString();
            $row = $marks->get($key);

            $months[$day->format('F Y')][] = [
                'date' => $key,
                'day_label' => $day->format('M j'),
                'status' => match (true) {
                    $row === null => 'unmarked',
                    (bool) ($row->needs_review ?? false), $row->is_present === null => 'unconfirmed',
                    (bool) $row->is_present => 'present',
                    default => 'absent',
                },
                // Who the mark came from: the human who last decided it, else
                // whoever recorded it, else what the row itself can say.
                'recorded_by' => $row === null ? '' : $this->attendanceAttribution($row),
                'remarks' => $row === null ? '' : trim((string) ($row->remarks ?? '')),
            ];
        }

        return collect($months)
            ->map(fn (array $rows, string $label): array => ['label' => $label, 'rows' => $rows])
            ->values()
            ->all();
    }

    /**
     * Who a mark came from, in the order the record can vouch for: the human
     * who last decided it, then whoever recorded it, then — for rows written
     * before either name was kept — what its source says. Never a guess at a
     * person: an unattributed mark says how it arrived, not who sent it.
     */
    private function attendanceAttribution(FeedingAttendance $row): string
    {
        $reviewer = trim((string) ($row->reviewed_by_name ?? ''));
        if ($reviewer !== '') {
            return $reviewer;
        }

        $recorder = trim((string) ($row->recorded_by_name ?? ''));
        if ($recorder !== '') {
            return $recorder;
        }

        return match ((string) $row->source) {
            FeedingAttendance::SOURCE_SPREADSHEET => 'Spreadsheet upload',
            FeedingAttendance::SOURCE_PHOTO_SCAN => 'Photographed sheet',
            FeedingAttendance::SOURCE_MANUAL_ENTRY => 'Recorded on site',
            FeedingAttendance::SOURCE_MANUAL_REVIEW => 'Reviewed mark',
            default => '',
        };
    }

    /**
     * Feeding days the school has actually held so far: distinct recorded
     * session dates, not the number of days since the cycle started. A day
     * nobody recorded was not a feeding day this page can claim.
     */
    private function sessionsHeld(?int $institutionId, string $schoolYear): int
    {
        if (! SchemaCache::hasTable('feeding_attendances')) {
            return 0;
        }

        return FeedingAttendance::query()
            ->when($institutionId, fn ($q) => $q->whereIn(
                'student_health_record_id',
                StudentHealthRecord::query()
                    ->where('institution_id', $institutionId)
                    ->forCurrentSchoolYear($schoolYear ?: null)
                    ->select('id')
            ))
            ->whereDate('session_date', '<=', now()->toDateString())
            ->distinct()
            ->count('session_date');
    }

    /**
     * A measurement as a number the view can format, or null when nothing was
     * recorded. Measurements are encrypted at rest, so they come back as
     * strings — and an empty string is "not measured", never 0.
     */
    private function measurement(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Saves one session's marks in a single transaction.
     *
     * A learner left unmarked is written as nothing at all — not as an absence.
     * The coordinator may have skipped the row; only an explicit present/absent
     * is evidence, and inventing an absence here would flag a learner nobody
     * observed missing.
     */
    public function storeRecordedAttendance(Request $request): RedirectResponse
    {
        if (! $this->isFeedingCoordinator($request)) {
            return redirect()->route('login')->with('error', 'Only the Feeding Coordinator can record attendance.');
        }

        $request->validate([
            'session_date' => ['required', 'date', 'before_or_equal:today'],
            'marks' => ['required', 'array'],
            'marks.*' => ['nullable', 'in:present,absent'],
            'remarks' => ['nullable', 'array'],
            'remarks.*' => ['nullable', 'string', 'max:255'],
            // Which screen the coordinator saved from, so they land back on it.
            'return_to' => ['nullable', 'in:attendance,dashboard'],
        ]);

        if (! SchemaCache::hasTable('student_health_records') || ! SchemaCache::hasTable('feeding_attendances')) {
            return back()->with('error', 'Attendance tracking tables are not ready. Run migrations first.');
        }

        $institutionId = $request->session()->get('active_institution_id');

        // A session outside the running cycle is a slip, not a decision: a
        // mistyped year would open a feeding day the programme never had and
        // change the denominator every at-risk flag is judged against.
        $cycle = FeedingProgramCycle::forInstitution($institutionId);
        $cycleStart = $cycle->startDateIso();
        $requestedDate = Carbon::parse((string) $request->input('session_date'))->toDateString();

        // Nobody is fed on a Saturday or a Sunday, so a weekend mark is not a
        // session the school held — it is a mistyped date that would add a day
        // to the denominator every at-risk rate is divided by.
        if (! FeedingProgramCycle::isFeedingDay($requestedDate)) {
            return back()->with(
                'error',
                Carbon::parse($requestedDate)->format('F j, Y').' is a '
                    .Carbon::parse($requestedDate)->format('l').'. There are no feeding sessions on weekends.'
            );
        }

        if ($cycleStart !== null) {
            $cycleEnd = $cycle->endDateIso();

            if ($requestedDate < $cycleStart || $requestedDate > $cycleEnd) {
                return back()->with(
                    'error',
                    'That date is outside the active feeding program ('
                        .Carbon::parse($cycleStart)->format('M j, Y').' to '.Carbon::parse($cycleEnd)->format('M j, Y').').'
                );
            }
        }
        $sessionDate = Carbon::parse((string) $request->input('session_date'))->toDateString();

        $submitted = collect($request->input('marks', []))
            ->filter(fn ($mark): bool => in_array($mark, ['present', 'absent'], true));

        if ($submitted->isEmpty()) {
            return back()->with('error', 'No learner was marked, so nothing was recorded.');
        }

        // Only this school's eligible learners may be written, whatever the form
        // posted: the ids come off the wire and are not to be trusted.
        $allowedIds = StudentHealthRecord::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->forCurrentSchoolYear()
            ->get()
            ->filter(fn (StudentHealthRecord $record): bool => $this->isEnrolledBeneficiary($record))
            ->pluck('id')
            ->all();

        $marks = $submitted->filter(fn ($mark, $recordId): bool => in_array((int) $recordId, $allowedIds, true));

        if ($marks->isEmpty()) {
            return back()->with('error', 'None of the submitted learners belong to this school.');
        }

        // A recorded session is closed, as a whole.
        //
        // Once a human has confirmed any mark for this date the session is a
        // record rather than a form: the sheet reports it, the record dialog will
        // not reopen it, and this endpoint refuses it — because a stale tab or a
        // replayed form reaches here all the same, and a read-only screen whose
        // endpoint still accepts writes is only a suggestion. A wrong mark, and a
        // learner the session skipped, are both put right on that learner's
        // beneficiary record, where the change is attributed and audited.
        //
        // An UNCONFIRMED scanned mark does not close a session: nobody has read
        // it, and recording on site is exactly how it gets decided.
        if ($this->sessionIsRecorded($allowedIds, $sessionDate)) {
            return back()->with(
                'error',
                'Attendance for '.Carbon::parse($sessionDate)->format('F j, Y').' has already been recorded. '
                    .'Correct a mark on the learner’s beneficiary record.'
            );
        }

        $now = now();
        $hasReviewColumns = $this->hasReviewColumns();
        $hasRemarks = SchemaCache::hasColumn('feeding_attendances', 'remarks');
        $hasRecorder = SchemaCache::hasColumn('feeding_attendances', 'recorded_by_name');
        $recorder = (string) $request->session()->get('active_name', 'Feeding Coordinator');
        $remarks = collect($request->input('remarks', []));

        $upserts = $marks->map(function ($mark, $recordId) use ($sessionDate, $now, $hasReviewColumns, $hasRemarks, $hasRecorder, $recorder, $remarks): array {
            $row = [
                'student_health_record_id' => (int) $recordId,
                'session_date' => $sessionDate,
                'is_present' => $mark === 'present',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($hasReviewColumns) {
                // Typed by the person who was there: confirmed on arrival, and
                // it supersedes any unread scanned mark for the same session.
                $row['source'] = FeedingAttendance::SOURCE_MANUAL_ENTRY;
                $row['needs_review'] = false;
            }

            if ($hasRemarks) {
                // A remark explains an absence; a learner marked present keeps
                // none. An upsert bypasses the model's casts, so the value is
                // encrypted here rather than landing in the column as plaintext.
                $remark = $mark === 'absent' ? trim((string) ($remarks[$recordId] ?? '')) : '';
                $row['remarks'] = $remark === '' ? null : Crypt::encryptString($remark);
            }

            if ($hasRecorder) {
                // The mark says who took it. Encrypted here for the same reason
                // the remark is: the upsert bypasses the model's casts.
                $row['recorded_by_name'] = Crypt::encryptString($recorder);
            }

            return $row;
        })->values()->all();

        $presentCount = $marks->filter(fn ($mark): bool => $mark === 'present')->count();

        DB::transaction(function () use ($upserts, $hasReviewColumns, $hasRemarks, $hasRecorder, $institutionId, $sessionDate, $marks, $recorder): void {
            $updateColumns = ['is_present', 'updated_at'];
            if ($hasReviewColumns) {
                $updateColumns = array_merge($updateColumns, ['source', 'needs_review']);
            }
            if ($hasRemarks) {
                $updateColumns[] = 'remarks';
            }
            if ($hasRecorder) {
                $updateColumns[] = 'recorded_by_name';
            }

            FeedingAttendance::query()->upsert(
                $upserts,
                ['student_health_record_id', 'session_date'],
                $updateColumns
            );

            $this->refreshAttendanceRiskFlags($institutionId);

            // The batch record is what makes "attendance exists for this period"
            // true, so a hand-recorded session gates the workflow exactly as an
            // uploaded sheet does.
            AttendanceImport::create([
                'institution_id' => $institutionId,
                'school_year' => StudentHealthRecord::currentSchoolYear(),
                'uploaded_by_name' => $recorder,
                'original_filename' => 'Recorded on site',
                'stored_path' => null,
                'kind' => AttendanceImport::KIND_MANUAL,
                'session_date' => $sessionDate,
                'sessions_count' => 1,
                'matched_count' => $marks->count(),
                'unmatched_count' => 0,
                'unclear_count' => 0,
                'row_errors' => [],
            ]);
        });

        AuditTrail::record(
            'created',
            'FeedingAttendance',
            null,
            'Attendance for '.$sessionDate.' recorded on site by '.$recorder
                .': '.$presentCount.' present, '.($marks->count() - $presentCount).' absent'
        );

        // Back to the screen the marks were entered on, with the same session
        // still open — a coordinator correcting one learner should not have to
        // navigate back to the day they were working on.
        $redirect = $request->input('return_to') === 'attendance'
            ? redirect()->route('dashboard.feedingcor-attendance', ['date' => $sessionDate])
            : redirect()->route('dashboard.feedingcor-dashboard');

        return $redirect->with('success', $marks->count().' mark(s) recorded for '.Carbon::parse($sessionDate)->format('M d, Y').'.');
    }

    /**
     * A human confirms one unreadable mark. This is the only path that turns a
     * NULL into a real attendance value, and it is always attributed and logged
     * — no mark a machine was unsure about changes a learner's flag silently.
     */
    public function resolveAttendanceReview(Request $request, int $attendance): RedirectResponse
    {
        if (! $this->isFeedingCoordinator($request)) {
            return redirect()->route('login')->with('error', 'Only the Feeding Coordinator can confirm attendance marks.');
        }

        $request->validate(['mark' => ['required', 'in:present,absent']]);

        if (! $this->hasReviewColumns()) {
            return back()->with('error', 'Attendance review is not ready on this database. Run migrations first.');
        }

        $institutionId = $request->session()->get('active_institution_id');

        $row = FeedingAttendance::query()
            ->where('id', $attendance)
            ->whereIn(
                'student_health_record_id',
                StudentHealthRecord::query()
                    ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
                    ->select('id')
            )
            ->first();

        if (! $row) {
            return back()->with('error', 'That attendance mark is not available for this school.');
        }

        $isPresent = $request->input('mark') === 'present';
        $reviewer = (string) $request->session()->get('active_name', 'Feeding Coordinator');

        DB::transaction(function () use ($row, $isPresent, $reviewer, $institutionId): void {
            $row->update([
                'is_present' => $isPresent,
                'needs_review' => false,
                'source' => FeedingAttendance::SOURCE_MANUAL_REVIEW,
                'reviewed_by_name' => $reviewer,
                'reviewed_at' => now(),
            ]);

            $this->refreshAttendanceRiskFlags($institutionId);
        });

        AuditTrail::record(
            'updated',
            'FeedingAttendance',
            $row->id,
            'Unclear scanned mark for '.optional($row->session_date)->toDateString()
                .' confirmed as '.($isPresent ? 'present' : 'absent').' by '.$reviewer
        );

        if ($row->attendanceImport) {
            $this->purgeScanPhotoIfReviewed($row->attendanceImport);
        }

        return back()->with('success', 'Mark confirmed as '.($isPresent ? 'present' : 'absent').'.');
    }

    /**
     * The coordinator corrects one learner's mark for one session, from that
     * learner's beneficiary record.
     *
     * A mark decides whether a child is flagged as at-risk, so it must be
     * correctable when it is wrong — and every correction is attributed and
     * logged with what it changed *from*, because the value it replaced is the
     * evidence someone may later need. Audit rows are never rewritten: a
     * correction adds a record, it does not edit the old one.
     *
     * Keyed by learner + session date rather than by mark id, so the one path
     * also covers a learner today's sheet skipped: an explicit correction is a
     * human's observation, which is exactly what a mark is allowed to be. The
     * date must be a session the school actually held, so no one can invent a
     * feeding day for a single learner.
     */
    public function correctAttendance(Request $request, int $record): RedirectResponse
    {
        if (! $this->isFeedingCoordinator($request)) {
            return redirect()->route('login')->with('error', 'Only the Feeding Coordinator can correct attendance.');
        }

        $validated = $request->validate([
            'session_date' => ['required', 'date', 'before_or_equal:today'],
            'mark' => ['required', 'in:present,absent'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        if (! SchemaCache::hasTable('feeding_attendances')) {
            return back()->with('error', 'Attendance tracking tables are not ready. Run migrations first.');
        }

        $institutionId = $request->session()->get('active_institution_id');

        $learner = StudentHealthRecord::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->whereKey($record)
            ->first();

        if (! $learner) {
            return redirect()
                ->route('dashboard.feedingcor-health-records')
                ->with('error', 'That learner is not on this school’s roster.');
        }

        $sessionDate = Carbon::parse($validated['session_date'])->toDateString();

        // The school's own feeding days are the only dates a mark may carry.
        // Without this, a correction could conjure a session that never
        // happened and change the denominator the at-risk rule divides by.
        $sessionExists = FeedingAttendance::query()
            ->whereDate('session_date', $sessionDate)
            ->whereIn(
                'student_health_record_id',
                StudentHealthRecord::query()
                    ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
                    ->forCurrentSchoolYear((string) $learner->school_year ?: null)
                    ->select('id')
            )
            ->exists();

        if (! $sessionExists) {
            return back()->with('error', 'No feeding session was recorded on that date, so there is no mark to correct.');
        }

        $existing = FeedingAttendance::query()
            ->where('student_health_record_id', $learner->id)
            ->whereDate('session_date', $sessionDate)
            ->first();

        $isPresent = $validated['mark'] === 'present';

        // What the mark was before this correction — kept for the audit entry,
        // because "changed to present" says nothing without it.
        $previous = match (true) {
            $existing === null => 'unmarked',
            (bool) ($existing->needs_review ?? false), $existing->is_present === null => 'unconfirmed',
            (bool) $existing->is_present => 'present',
            default => 'absent',
        };

        if ($previous === $validated['mark'] && trim((string) ($validated['remarks'] ?? '')) === trim((string) ($existing->remarks ?? ''))) {
            return back()->with('success', 'That mark already reads '.$validated['mark'].'.');
        }

        $corrector = (string) $request->session()->get('active_name', 'Feeding Coordinator');
        // A remark explains an absence; a learner marked present keeps none.
        $remark = $isPresent ? '' : trim((string) ($validated['remarks'] ?? ''));

        $attributes = [
            'is_present' => $isPresent,
            'source' => FeedingAttendance::SOURCE_MANUAL_REVIEW,
            'reviewed_by_name' => $corrector,
            'reviewed_at' => now(),
        ];

        if ($this->hasReviewColumns()) {
            // A human has now decided this mark, whatever a scan made of it.
            $attributes['needs_review'] = false;
        }
        if (SchemaCache::hasColumn('feeding_attendances', 'remarks')) {
            $attributes['remarks'] = $remark === '' ? null : $remark;
        }
        if ($existing === null && SchemaCache::hasColumn('feeding_attendances', 'recorded_by_name')) {
            // A mark that did not exist before is recorded by whoever corrected
            // it; an existing one keeps whoever originally took it.
            $attributes['recorded_by_name'] = $corrector;
        }

        // Through the model, never a raw upsert: the casts are what keep the
        // staff name and the remark encrypted at rest.
        $row = DB::transaction(function () use ($existing, $learner, $sessionDate, $attributes, $institutionId): FeedingAttendance {
            $row = $existing ?? new FeedingAttendance([
                'student_health_record_id' => $learner->id,
                'session_date' => $sessionDate,
            ]);

            $row->fill($attributes)->save();

            $this->refreshAttendanceRiskFlags($institutionId);

            return $row;
        });

        AuditTrail::record(
            'updated',
            'FeedingAttendance',
            $row->id,
            'Attendance for '.$sessionDate.' corrected from '.$previous.' to '.$validated['mark'].' by '.$corrector,
            [
                'student_health_record_id' => $learner->id,
                'session_date' => $sessionDate,
                'old' => ['is_present' => $previous],
                'new' => ['is_present' => $validated['mark'], 'remarks' => $remark !== '' ? $remark : null],
            ]
        );

        // A scan whose last unclear mark this correction settled has no reason
        // to keep its photograph any longer.
        if ($row->attendanceImport) {
            $this->purgeScanPhotoIfReviewed($row->attendanceImport);
        }

        return back()->with('success', 'Attendance for '.Carbon::parse($sessionDate)->format('M d, Y').' corrected to '.$validated['mark'].'.');
    }

    /**
     * Deletes the scanned image once every mark from it is confirmed. Holding it
     * any longer serves no purpose — the sheet is a photograph of children's
     * records, so the retention window is exactly "while a human still needs to
     * look at it".
     */
    private function purgeScanPhotoIfReviewed(AttendanceImport $import): void
    {
        if (! config('feeding.scanning.purge_photo_after_review', true)) {
            return;
        }

        if ($import->kind !== AttendanceImport::KIND_PHOTO || blank($import->stored_path) || $import->photo_purged_at) {
            return;
        }

        if ($import->pendingReviewCount() > 0) {
            return;
        }

        EncryptedFileStorage::delete((string) $import->stored_path);

        $import->update(['stored_path' => null, 'photo_purged_at' => now()]);

        AuditTrail::record(
            'deleted',
            'AttendanceImport',
            $import->id,
            'Scanned attendance photo purged after all marks were confirmed'
        );
    }

    /**
     * The roster handed to the model: real names, so it matches rather than
     * transcribes. `id` is a per-request index, not a database key — the model
     * never sees an internal identifier.
     *
     * @return list<array{id:int,record_id:int,name:string,grade:string,section:string}>
     */
    private function buildScanRoster(?int $institutionId): array
    {
        $roster = [];
        $index = 1;

        StudentHealthRecord::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->forCurrentSchoolYear()
            ->get()
            ->each(function (StudentHealthRecord $record) use (&$roster, &$index): void {
                [$grade, $section] = $this->splitGradeSection((string) $record->section);

                $roster[] = [
                    'id' => $index++,
                    'record_id' => $record->id,
                    'name' => (string) $record->student_name,
                    'grade' => $grade,
                    'section' => $section,
                ];
            });

        return $roster;
    }

    private function isFeedingCoordinator(Request $request): bool
    {
        $role = strtolower(trim((string) $request->session()->get('active_role', '')));

        return in_array($role, ['feeding_coor', 'feedingcoor', 'feeding_coordinator', 'feeding coordinator'], true);
    }

    /**
     * Builds the pool of this school's current-year learners with normalized
     * name tokens / grade / section for fuzzy matching against uploaded rows.
     *
     * @return list<array{id: int, tokens: list<string>, grade_num: string|null, section: string, matched: bool}>
     */
    private function buildMatchPool(?int $institutionId): array
    {
        return StudentHealthRecord::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->forCurrentSchoolYear()
            ->get()
            ->map(function (StudentHealthRecord $record): array {
                [$grade, $section] = $this->splitGradeSection((string) $record->section);

                return [
                    'id' => $record->id,
                    'tokens' => $this->nameTokens((string) $record->student_name),
                    'grade_num' => $this->gradeNumber($grade),
                    'section' => strtoupper(trim($section)),
                    'matched' => false,
                ];
            })
            ->all();
    }

    /**
     * Resolves one uploaded row to a learner the adviser has actually
     * registered, or to nothing at all.
     *
     * The bar is deliberately high, because the failure this guards against is
     * silent: a name nobody enrolled quietly becoming attendance on a real
     * learner's record. Only two shapes count as the same person —
     *
     *   - the name tokens are identical ("Bautista, Andrei M." ≡ "Andrei Bautista"),
     *   - or one name's tokens are wholly contained in the other's, which is what
     *     a dropped middle name or a missing suffix looks like.
     *
     * A partial overlap ("Juan Dela Cruz" against "Juan Cruz Reyes") is not a
     * match, and a tie no section can settle is not a match either. Both come
     * back as null, and the caller skips the row rather than guessing which
     * child it belongs to.
     *
     * @param  list<array{id: int, tokens: list<string>, grade_num: string|null, section: string, matched: bool}>  $pool
     */
    private function matchStudent(array $pool, string $name, string $grade, string $section): ?int
    {
        $tokens = $this->nameTokens($name);
        if (empty($tokens)) {
            return null;
        }
        $gradeNum = $this->gradeNumber($grade);
        $sectionUpper = strtoupper(trim($section));

        $candidates = [];
        foreach ($pool as $index => $entry) {
            if ($entry['matched']) {
                continue;
            }
            if ($gradeNum !== null && $entry['grade_num'] !== null && $entry['grade_num'] !== $gradeNum) {
                continue;
            }

            if ($entry['tokens'] === $tokens) {
                $candidates[] = ['index' => $index, 'score' => 2];

                continue;
            }

            // One name fully inside the other: a dropped middle name or suffix.
            $overlap = count(array_intersect($tokens, $entry['tokens']));
            if ($overlap >= 2 && ($overlap === count($tokens) || $overlap === count($entry['tokens']))) {
                $candidates[] = ['index' => $index, 'score' => 1];
            }
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $topScore = $candidates[0]['score'];
        $top = array_values(array_filter($candidates, fn (array $c): bool => $c['score'] === $topScore));

        if (count($top) === 1) {
            return $top[0]['index'];
        }

        // Namesakes: the section decides, and only when it picks out exactly one.
        if ($sectionUpper !== '') {
            $bySection = array_values(array_filter(
                $top,
                fn (array $candidate): bool => $pool[$candidate['index']]['section'] === $sectionUpper
            ));

            if (count($bySection) === 1) {
                return $bySection[0]['index'];
            }
        }

        // Still ambiguous — refuse rather than attach the row to a coin flip.
        return null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitGradeSection(string $section): array
    {
        $parts = explode(' / ', $section, 2);

        return [trim($parts[0]), trim($parts[1] ?? '')];
    }

    private function gradeNumber(string $grade): ?string
    {
        return preg_match('/\d+/', $grade, $matches) ? $matches[0] : null;
    }

    /**
     * Normalizes a display name to a sorted set of significant tokens so
     * "Bautista, Andrei M." and "Andrei M. Bautista" match despite ordering.
     * Single-letter middle initials are dropped.
     *
     * @return list<string>
     */
    private function nameTokens(string $name): array
    {
        $name = mb_strtoupper($name);
        $name = str_replace([',', '.'], ' ', $name);
        $name = (string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $name);

        $tokens = array_values(array_filter(
            preg_split('/\s+/', trim($name)) ?: [],
            fn (string $token): bool => mb_strlen($token) > 1
        ));

        sort($tokens);

        return $tokens;
    }

    /**
     * Grade level is the part of the plain "section" string before the
     * "Grade X / Section" slash. Rows with no section land in "Unassigned"
     * so they still surface under a filterable bucket.
     */
    private function resolveGradeLevel(string $section): string
    {
        $grade = trim(explode(' / ', $section, 2)[0]);

        return $grade !== '' ? $grade : 'Unassigned';
    }

    /**
     * Gender is adviser-entered free text inside the encrypted student_details
     * blob, so it is normalised to the two labels the filter offers; anything
     * else (blank, "prefer not to say") stays unlabelled rather than guessed.
     */
    /**
     * Kept as a thin pass-through to the one normalizer every coordinator
     * screen reads sex through. Two copies of this logic is how two tabs start
     * disagreeing about who is in a filtered roll.
     */
    private function resolveGenderLabel(string $gender): string
    {
        $value = strtolower(trim($gender));

        return match (true) {
            $value === '' => '',
            str_starts_with($value, 'm') => 'Male',
            str_starts_with($value, 'f') => 'Female',
            default => '',
        };
    }

    /**
     * A beneficiary of the programme: qualified by the adviser's measurement
     * AND enrolled by the coordinator.
     *
     * Qualifying alone is not enough — a learner the coordinator has not
     * enrolled is on the waiting list, not on the feeding line. Attendance
     * import and photo scanning deliberately still match against the whole
     * roster: a sheet is evidence of who was fed, and dropping a name because
     * of a missing enrolment would lose that evidence.
     */
    private function isEnrolledBeneficiary(StudentHealthRecord $record): bool
    {
        return $record->feeding_enrolled_at !== null
            && $this->isAttendanceEligible($record->nutritional_status);
    }

    private function isAttendanceEligible(?string $nutritionalStatus): bool
    {
        $status = strtolower((string) $nutritionalStatus);
        $status = preg_replace('/\s+/', ' ', trim($status)) ?? '';

        return $status === 'wasted'
            || $status === 'severely wasted'
            || $status === 'severly wasted'
            || $status === 'underweight';
    }

    private function normalizeNutritionalStatus(?string $nutritionalStatus, ?float $bmi): string
    {
        $status = trim((string) $nutritionalStatus);
        $normalized = strtolower($status);

        if (str_contains($normalized, 'severe')) {
            return 'Severely Wasted';
        }
        if (str_contains($normalized, 'wast')) {
            return 'Wasted';
        }
        if (str_contains($normalized, 'underweight')) {
            return 'Underweight';
        }
        if (str_contains($normalized, 'over')) {
            return 'Overweight';
        }

        if ($bmi !== null) {
            $bmiValue = (float) $bmi;
            if ($bmiValue < 16.0) {
                return 'Severely Wasted';
            }
            if ($bmiValue < 17.0) {
                return 'Wasted';
            }
            if ($bmiValue < 18.5) {
                return 'Underweight';
            }
            if ($bmiValue >= 25.0) {
                return 'Overweight';
            }
        }

        return $status !== '' ? $status : 'Normal';
    }

    private function resolveProgramDay(?int $institutionId = null): int
    {
        return FeedingProgramCycle::forInstitution($institutionId)->day();
    }

    /**
     * Whether this session has already been recorded for this school.
     *
     * A session is closed by the first confirmed mark on it, not learner by
     * learner: recording is one act covering the whole roll, so reopening it for
     * whoever the sheet happened to miss would let two people write the same day
     * from two screens. The gap is filled on the learner's own record instead,
     * where the change carries who made it.
     *
     * @param  list<int>  $recordIds  the school's own beneficiaries
     */
    private function sessionIsRecorded(array $recordIds, string $sessionDate): bool
    {
        return $this->settledMarkIds($recordIds, $sessionDate) !== [];
    }

    /**
     * Of these learners, the ones whose mark for this session a human has
     * already decided — present or absent, not awaiting review.
     *
     * `is_present` is nullable and `needs_review` marks a scanned mark nobody
     * has read; neither counts as settled, so an unread scan stays open for the
     * coordinator to decide on site.
     *
     * @param  list<int>  $recordIds
     * @return list<int>
     */
    private function settledMarkIds(array $recordIds, string $sessionDate): array
    {
        if ($recordIds === [] || ! SchemaCache::hasTable('feeding_attendances')) {
            return [];
        }

        return FeedingAttendance::query()
            ->whereIn('student_health_record_id', $recordIds)
            ->whereDate('session_date', $sessionDate)
            ->whereNotNull('is_present')
            ->when(
                $this->hasReviewColumns(),
                fn ($query) => $query->where(fn ($q) => $q->where('needs_review', false)->orWhereNull('needs_review'))
            )
            ->pluck('student_health_record_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * At-risk is driven purely by feeding-session attendance — a learner is
     * never auto-flagged just for being wasted/severely wasted. The rule itself
     * (rate threshold vs consecutive absences) lives in FeedingAtRiskRule and is
     * config-driven; this method only feeds it each learner's marks in date
     * order and writes the verdict.
     *
     * Marks awaiting human review are passed through as NULL and the rule
     * excludes them, so an unread scan can neither flag nor unflag anyone.
     * Every change of flag is written to the audit log.
     */
    private function refreshAttendanceRiskFlags(?int $institutionId = null): void
    {
        // The school's own threshold decides its learners — never the platform
        // default, or a school that set 90% would still be flagged at 80%.
        $rule = FeedingAtRiskRule::forInstitution($institutionId);
        $todayDate = now()->toDateString();

        // Fetched, not aggregated in SQL: NULL marks must survive to the rule,
        // and a SUM(CASE ...) would silently fold them into "absent".
        $marksByRecord = FeedingAttendance::query()
            ->when($institutionId, fn ($q) => $q->whereIn(
                'student_health_record_id',
                StudentHealthRecord::query()->where('institution_id', $institutionId)->select('id')
            ))
            ->whereDate('session_date', '<=', $todayDate)
            ->orderBy('session_date')
            ->get(array_merge(
                ['student_health_record_id', 'session_date', 'is_present'],
                $this->hasReviewColumns() ? ['needs_review'] : []
            ))
            ->groupBy('student_health_record_id')
            ->map(fn ($rows) => $rows
                // Before the review migration every mark is confirmed by
                // definition, so nothing is excluded.
                ->map(fn ($row) => ($row->needs_review ?? false) ? null : $row->is_present)
                ->values()
                ->all());

        $changed = 0;

        StudentHealthRecord::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->forCurrentSchoolYear()
            ->each(function (StudentHealthRecord $record) use ($marksByRecord, $rule, &$changed): void {
                $marks = $marksByRecord->get($record->id, []);
                $isAtRisk = $rule->isAtRisk($marks);
                $presentCount = $rule->presentCount($marks);

                if ((bool) $record->is_at_risk !== $isAtRisk) {
                    $changed++;
                }

                $record->update([
                    'attendance_sessions_count' => $presentCount,
                    'is_at_risk' => $isAtRisk,
                ]);
            });

        if ($changed > 0) {
            AuditTrail::record(
                'updated',
                'StudentHealthRecord',
                null,
                "At-risk recomputed ({$rule->describe()}): {$changed} learner flag(s) changed"
            );
        }
    }
}
