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
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        if (Schema::hasTable('student_health_records')) {
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

            return [
                'id' => $record->id,
                'student_name' => $record->student_name,
                'section' => $record->section,
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
        ]);
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
        return $this->hasReviewColumns ??= Schema::hasTable('feeding_attendances')
            && Schema::hasColumn('feeding_attendances', 'needs_review');
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

        if (! Schema::hasTable('student_health_records') || ! Schema::hasTable('feeding_attendances')) {
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

        $atRiskCount = StudentHealthRecord::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->forCurrentSchoolYear()
            ->where('is_at_risk', true)
            ->count();

        $redirect = redirect()
            ->route('dashboard.feedingcor-program', ['grade' => $selectedGrade])
            ->with('success', 'Attendance sheet processed: matched '.count($matched).' learner(s) across '.count($parsed['sessions']).' session(s). '.$atRiskCount.' learner(s) now flagged at-risk (attendance below '.self::AT_RISK_THRESHOLD_PERCENT.'%).');

        if (! empty($unmatched)) {
            $shown = array_slice($unmatched, 0, 10);
            $more = count($unmatched) > 10 ? ' (+'.(count($unmatched) - 10).' more)' : '';
            $redirect->with('error', count($unmatched).' row(s) did not match a learner and were skipped: '.implode('; ', $shown).$more);
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
            'session_date' => ['required', 'date', 'before_or_equal:today'],
            'grade' => ['nullable', 'string', 'max:255'],
        ]);

        if (! Schema::hasTable('student_health_records') || ! Schema::hasTable('feeding_attendances')) {
            return back()->with('error', 'Attendance tracking tables are not ready. Run migrations first.');
        }

        $institutionId = $request->session()->get('active_institution_id');
        $sessionDate = Carbon::parse((string) $request->input('session_date'))->toDateString();
        $selectedGrade = trim((string) $request->input('grade', 'all')) ?: 'all';
        $photo = $request->file('attendance_photo');

        $roster = $this->buildScanRoster($institutionId);
        if ($roster === []) {
            return back()->with('error', 'No learners are on file for this school yet, so there is nothing to match the sheet against.');
        }

        try {
            $result = $scanner->scan($photo, $roster, $sessionDate);
        } catch (Throwable $e) {
            // Deliberately no partial write: a failed scan leaves the period
            // exactly as it was.
            return back()->with('error', 'Could not read the attendance photo. '.$e->getMessage());
        }

        // A scan that reports the sheet unreadable is not trusted for any mark,
        // even if it also returned some. Enforced here, at the point of write,
        // rather than only inside the scanner — a contradictory result must not
        // be able to put an unread mark into a learner's record.
        if ($result['unreadable']) {
            $result['marks'] = array_fill_keys(
                array_keys($result['marks']) ?: array_column($roster, 'id'),
                AttendanceSheetScanner::MARK_UNCLEAR
            );
        }

        $counts = ['present' => 0, 'absent' => 0, 'unclear' => 0];
        foreach ($result['marks'] as $mark) {
            $counts[match ($mark) {
                AttendanceSheetScanner::MARK_PRESENT => 'present',
                AttendanceSheetScanner::MARK_ABSENT => 'absent',
                default => 'unclear',
            }]++;
        }

        $import = DB::transaction(function () use ($result, $roster, $sessionDate, $institutionId, $photo, $request, $counts): AttendanceImport {
            $now = now();
            $storedPath = EncryptedFileStorage::store($photo, 'feeding-attendance-photos/'.($institutionId ?? 'unscoped'));

            $import = AttendanceImport::create([
                'institution_id' => $institutionId,
                'school_year' => StudentHealthRecord::currentSchoolYear(),
                'uploaded_by_name' => (string) $request->session()->get('active_name', 'Feeding Coordinator'),
                'original_filename' => $photo->getClientOriginalName(),
                'stored_path' => $storedPath,
                'kind' => AttendanceImport::KIND_PHOTO,
                'session_date' => $sessionDate,
                'sessions_count' => 1,
                'matched_count' => $counts['present'] + $counts['absent'],
                'unmatched_count' => 0,
                'unclear_count' => $counts['unclear'],
                'row_errors' => $result['note'] !== '' ? [$result['note']] : [],
            ]);

            $upserts = [];
            foreach ($roster as $entry) {
                $mark = $result['marks'][$entry['id']] ?? AttendanceSheetScanner::MARK_UNCLEAR;
                $unclear = $mark === AttendanceSheetScanner::MARK_UNCLEAR;

                $upserts[] = [
                    'student_health_record_id' => $entry['record_id'],
                    'session_date' => $sessionDate,
                    // NULL, not false — an unread mark is not an absence.
                    'is_present' => $unclear ? null : ($mark === AttendanceSheetScanner::MARK_PRESENT),
                    'needs_review' => $unclear,
                    'source' => FeedingAttendance::SOURCE_PHOTO_SCAN,
                    'attendance_import_id' => $import->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
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
            "Attendance photo scanned for {$sessionDate}: {$counts['present']} present, {$counts['absent']} absent, {$counts['unclear']} needing review"
        );

        // Nothing pending means nothing to look at the photo for.
        $this->purgeScanPhotoIfReviewed($import);

        $redirect = redirect()->route('dashboard.feedingcor-program', ['grade' => $selectedGrade]);

        if ($result['unreadable']) {
            return $redirect->with('error', 'The photo could not be read — every mark for '.$sessionDate.' is waiting for review. '.$result['note']);
        }

        $redirect->with('success', 'Sheet scanned for '.$sessionDate.': '.$counts['present'].' present, '.$counts['absent'].' absent.');

        if ($counts['unclear'] > 0) {
            $redirect->with('error', $counts['unclear'].' mark(s) could not be read confidently and are waiting for your review — they do not count either way until you confirm them.');
        }

        return $redirect;
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
                $candidates[] = ['index' => $index, 'score' => 3];

                continue;
            }

            $overlap = count(array_intersect($tokens, $entry['tokens']));
            if ($overlap >= 2 && ($overlap === count($tokens) || $overlap === count($entry['tokens']))) {
                $candidates[] = ['index' => $index, 'score' => 2];
            } elseif ($overlap >= 2) {
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

        // Tie-break by section when the upload provides one.
        foreach ($top as $candidate) {
            if ($sectionUpper !== '' && $pool[$candidate['index']]['section'] === $sectionUpper) {
                return $candidate['index'];
            }
        }

        return $top[0]['index'];
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

        if (Schema::hasTable('feeding_attendances')) {
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

        if ($programDay === 0 && Schema::hasTable('consultations')) {
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
