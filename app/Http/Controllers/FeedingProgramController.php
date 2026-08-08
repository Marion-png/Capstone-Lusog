<?php

namespace App\Http\Controllers;

use App\Models\AttendanceImport;
use App\Models\Consultation;
use App\Models\FeedingAttendance;
use App\Models\StudentHealthRecord;
use App\Support\AttendanceSheetParser;
use App\Support\AttendanceSheetScanner;
use App\Support\AuditTrail;
use App\Support\EncryptedFileStorage;
use App\Support\FeedingAtRiskRule;
use App\Support\SchemaCache;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

class FeedingProgramController extends Controller
{
    private const PROGRAM_DURATION_DAYS = 120;

    private const AT_RISK_THRESHOLD_PERCENT = 75;

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
                ->filter(fn (StudentHealthRecord $record): bool => $this->isAttendanceEligible($record->nutritional_status))
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
        $atRiskThresholdCount = $programDay > 0
            ? (int) ceil($programDay * (self::AT_RISK_THRESHOLD_PERCENT / 100))
            : 0;

        $studentRows = $students->map(function (StudentHealthRecord $record) use ($programDay): array {
            $currentWeight = (float) $record->weight;
            $baselineWeight = $record->baseline_weight_kg !== null
                ? (float) $record->baseline_weight_kg
                : max(1, $currentWeight - 0.7);
            $bmiCurrent = (float) $record->bmi_value;
            $bmiBaseline = $record->baseline_bmi_value !== null
                ? (float) $record->baseline_bmi_value
                : max(0, $bmiCurrent - 0.5);
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

            $attendanceCount = (int) ($record->attendance_sessions_count ?? 0);
            $expectedAttendance = max(1, $programDay);
            $attendancePercent = $programDay > 0
                ? (int) round(($attendanceCount / $expectedAttendance) * 100)
                : 0;

            $details = is_array($record->student_details) ? $record->student_details : [];

            return [
                'id' => $record->id,
                'student_name' => $record->student_name,
                'section' => $record->section,
                'grade_level' => $this->resolveGradeLevel((string) $record->section),
                'gender' => $this->resolveGenderLabel((string) ($details['gender'] ?? '')),
                'baseline_weight' => number_format($baselineWeight, 1),
                'current_weight' => number_format($currentWeight, 1),
                'bmi_range' => number_format($bmiBaseline, 1).' - '.number_format($bmiCurrent, 1),
                'bmi_class' => $bmiClass,
                'bmi_value' => number_format($bmiCurrent, 1),
                'attendance' => $attendanceCount.'/'.self::PROGRAM_DURATION_DAYS.' days',
                'attendance_count' => $attendanceCount,
                'attendance_percent' => $attendancePercent,
                'nutritional_status' => $resolvedStatus,
                'is_attendance_eligible' => $isAttendanceEligible,
                'is_at_risk' => (bool) $record->is_at_risk,
                'trend_label' => $trendLabel,
                'trend_class' => $trendClass,
            ];
        })->values();

        $studentCount = $studentRows->count();
        $improvingCount = $studentRows->where('trend_label', 'Improving')->count();
        $totalPresentAttendance = (int) $studentRows->sum('attendance_count');
        $maxPossibleAttendance = max(1, $studentCount * max(1, $programDay));
        $attendanceRate = $programDay > 0
            ? (int) round(($totalPresentAttendance / $maxPossibleAttendance) * 100)
            : 0;

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
                'avg_attendance' => $attendanceRate.'%',
                'improving_rate' => $studentCount > 0 ? (int) round(($improvingCount / $studentCount) * 100).'%' : '0%',
                'improving_hint' => $improvingCount.' of '.$studentCount.' students',
                'at_risk_count' => $atRiskStudents->count(),
                'at_risk_threshold' => self::AT_RISK_THRESHOLD_PERCENT,
                'at_risk_threshold_count' => $atRiskThresholdCount,
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
                $baselineWeight = (float) ($student['baseline_weight'] ?? 0);
                $currentWeight = (float) ($student['current_weight'] ?? 0);

                $row = [
                    'student_name' => $student['student_name'],
                    'section' => $student['section'],
                    'grade_level' => $student['grade_level'],
                    'gender' => $student['gender'],
                    'nutritional_status' => $student['nutritional_status'],
                    'baseline_weight' => $baselineWeight,
                    'current_weight' => $currentWeight,
                    'weight_change' => round($currentWeight - $baselineWeight, 1),
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

                $upserts[] = $row;
            }
        }

        // Everything lands in a single transaction so a mid-way failure never
        // leaves a half-written period (no silent partial write). The batch
        // record is created here too, so "attendance uploaded for this period"
        // is only ever true once the whole import succeeded.
        DB::transaction(function () use ($upserts, $institutionId, $file, $matched, $parsed, $unmatched, $request, $hasReviewColumns): void {
            // A re-uploaded spreadsheet supersedes a pending scanned mark for
            // the same session, and clears its review flag along with it.
            FeedingAttendance::query()->upsert(
                $upserts,
                ['student_health_record_id', 'session_date'],
                $hasReviewColumns
                    ? ['is_present', 'source', 'needs_review', 'updated_at']
                    : ['is_present', 'updated_at']
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
        $redirect = redirect()->route('dashboard.feedingcor-program', ['grade' => $selectedGrade]);

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

        $import = DB::transaction(function () use ($result, $roster, $sessions, $sessionDates, $institutionId, $photo, $request, $counts): AttendanceImport {
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

                    $upserts[] = [
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
                }
            }

            FeedingAttendance::query()->upsert(
                $upserts,
                ['student_health_record_id', 'session_date'],
                ['is_present', 'needs_review', 'source', 'attendance_import_id', 'updated_at']
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

        $redirect = redirect()->route('dashboard.feedingcor-program', ['grade' => $selectedGrade]);

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
            'ruleDescription' => FeedingAtRiskRule::fromConfig()->describe(),
        ]);
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
    private function resolveGenderLabel(string $gender): string
    {
        $value = strtolower(trim($gender));

        if (str_starts_with($value, 'm')) {
            return 'Male';
        }

        if (str_starts_with($value, 'f')) {
            return 'Female';
        }

        return '';
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
        $programDay = 0;
        $todayDate = now()->toDateString();

        if (SchemaCache::hasTable('feeding_attendances')) {
            $firstAttendanceDate = FeedingAttendance::query()
                ->when($institutionId, fn ($q) => $q->whereIn(
                    'student_health_record_id',
                    StudentHealthRecord::query()->where('institution_id', $institutionId)->forCurrentSchoolYear()->select('id')
                ))
                ->whereDate('session_date', '<=', $todayDate)
                ->min('session_date');
            if ($firstAttendanceDate) {
                $programDay = min(self::PROGRAM_DURATION_DAYS, Carbon::parse($firstAttendanceDate)->startOfDay()->diffInDays(now()->startOfDay()) + 1);
            }
        }

        if ($programDay === 0 && SchemaCache::hasTable('consultations')) {
            $firstFeedingDate = Consultation::query()
                ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
                ->min('consulted_at');
            if ($firstFeedingDate) {
                $programDay = min(self::PROGRAM_DURATION_DAYS, Carbon::parse($firstFeedingDate)->startOfDay()->diffInDays(now()->startOfDay()) + 1);
            }
        }

        return $programDay;
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
        $rule = FeedingAtRiskRule::fromConfig();
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
