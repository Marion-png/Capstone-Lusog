<?php

namespace App\Http\Controllers;

use App\Models\FeedingAttendance;
use App\Models\StudentHealthRecord;
use App\Support\FeedingAtRiskRule;
use App\Support\FeedingNutritionProgress;
use App\Support\FeedingProgramCycle;
use App\Support\SchemaCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FeedingCoordinatorController extends Controller
{
    public function sbfpForms(Request $request): View
    {
        $institutionId = $request->session()->get('active_institution_id');

        $records = collect();
        if (SchemaCache::hasTable('student_health_records')) {
            $query = StudentHealthRecord::query();
            if ($institutionId) {
                $query->where('institution_id', $institutionId);
            }
            $records = $query->forCurrentSchoolYear()->get();
        }

        // Group adviser-entered students by grade level so each SBFP form is
        // filled with one grade only — Grade 8 is never mixed with Grade 9.
        // Names and statuses are encrypted at rest, so the grouping and sorting
        // happen in PHP after fetch (the plain "section" column holds the grade).
        $studentsByGrade = [];
        foreach ($records as $record) {
            [$grade, $section] = $this->splitSection((string) $record->section);
            $status = $this->normalizeStatus((string) $record->nutritional_status);

            $studentsByGrade[$grade][] = [
                'name' => (string) $record->student_name,
                'grade' => $grade,
                'section' => $section,
                'status' => $status,
                'bmi' => $record->bmi_value !== null ? (string) $record->bmi_value : '',
                'qualified' => $this->isQualifiedForFeeding($status),
            ];
        }

        uksort($studentsByGrade, fn (string $a, string $b): int => strnatcasecmp($a, $b));
        foreach ($studentsByGrade as $grade => $rows) {
            usort($rows, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
            $studentsByGrade[$grade] = $rows;
        }

        return view('feedingcor-dashboard.sbfp-forms', [
            'studentsByGrade' => $studentsByGrade,
            'gradeOptions' => array_keys($studentsByGrade),
            'bmiValues' => $this->buildBmiValues($records),
            'schoolYear' => StudentHealthRecord::currentSchoolYear(),
            'nurseName' => $this->resolveSchoolNurseName($institutionId, (string) $request->session()->get('active_school_name', '')),
        ]);
    }

    /**
     * The School Nurse registered to this coordinator's school, used to
     * pre-fill the "Prepared by" signatory on the BMI reports. Matched on the
     * same institution (falling back to the plain school name), so a nurse from
     * another school is never pulled in.
     */
    private function resolveSchoolNurseName(?int $institutionId, string $schoolName): string
    {
        if (! SchemaCache::hasTable('accounts')) {
            return '';
        }

        if ($institutionId) {
            $name = (string) (DB::table('accounts')
                ->where('role', 'school_nurse')
                ->where('institution_id', $institutionId)
                ->orderBy('id')
                ->value('name') ?? '');
            if ($name !== '') {
                return $name;
            }
        }

        if (trim($schoolName) !== '') {
            return (string) (DB::table('accounts')
                ->where('role', 'school_nurse')
                ->where('school_name', $schoolName)
                ->orderBy('id')
                ->value('name') ?? '');
        }

        return '';
    }

    /**
     * Tabulates adviser-entered learners into the DepEd BMI report grids —
     * Grades 7-12 plus Overall — for both the Baseline (prefix "bmib") and the
     * Final/endline (prefix "bmif") assessments. Every cell is derived here (in
     * PHP, since names/statuses are encrypted at rest) and rendered read-only,
     * so the reports always mirror the current roster with no hand-keying.
     *
     * @param  Collection<int, StudentHealthRecord>  $records
     * @return array<string, int|string>
     */
    private function buildBmiValues(Collection $records): array
    {
        $gradeKeys = ['g7', 'g8', 'g9', 'g10', 'g11', 'g12'];
        $sexes = ['male', 'female'];
        $nsCols = ['sw', 'w', 'n', 'ow', 'ob'];
        $hfaCols = ['ss', 'st', 'hn', 't'];

        // counts[phase][gradeKey][sex][column] — phase is "bmib" or "bmif".
        $counts = [];
        foreach (['bmib', 'bmif'] as $phase) {
            foreach ($gradeKeys as $gk) {
                foreach ($sexes as $sex) {
                    foreach (array_merge($nsCols, $hfaCols) as $col) {
                        $counts[$phase][$gk][$sex][$col] = 0;
                    }
                }
            }
        }

        foreach ($records as $record) {
            [$gradeLabel] = $this->splitSection((string) $record->section);
            $gk = $this->bmiGradeKey($gradeLabel);
            if ($gk === null) {
                continue; // Report only covers Grades 7-12.
            }

            $details = is_array($record->student_details) ? $record->student_details : [];
            $sex = $this->bmiSexKey((string) ($details['gender'] ?? ''));
            if ($sex === null) {
                continue; // No sex on file — cannot place in a MALE/FEMALE row.
            }

            // Baseline: BMI-for-age from the baseline column, HFA from the
            // classification the adviser computed at entry time.
            if ($ns = $this->bmiNsColumn((string) ($record->baseline_nutritional_status ?: $record->nutritional_status))) {
                $counts['bmib'][$gk][$sex][$ns]++;
            }
            if ($hfa = $this->bmiHfaColumn((string) ($details['nutritional_status_height_for_age'] ?? ''))) {
                $counts['bmib'][$gk][$sex][$hfa]++;
            }

            // Final: only learners with an endline measurement contribute; HFA is
            // recomputed from the endline height/age (endline HFA is not stored).
            if ($ns = $this->bmiNsColumn((string) $record->endline_nutritional_status)) {
                $counts['bmif'][$gk][$sex][$ns]++;
            }
            if ($record->endline_height_cm !== null && $record->endline_age !== null) {
                $endlineHfa = $this->classifyHeightForAge((float) $record->endline_height_cm, (int) $record->endline_age);
                if ($hfa = $this->bmiHfaColumn($endlineHfa)) {
                    $counts['bmif'][$gk][$sex][$hfa]++;
                }
            }
        }

        $values = [];
        foreach (['bmib', 'bmif'] as $phase) {
            foreach ($gradeKeys as $gk) {
                $this->flattenBmiTable($values, $phase.'_'.$gk, $counts[$phase][$gk], $nsCols, $hfaCols);
            }

            // Overall table = column-wise sum of every grade.
            $overall = [];
            foreach ($sexes as $sex) {
                foreach (array_merge($nsCols, $hfaCols) as $col) {
                    $overall[$sex][$col] = array_sum(array_map(fn (string $gk): int => $counts[$phase][$gk][$sex][$col], $gradeKeys));
                }
            }
            $this->flattenBmiTable($values, $phase.'_overall', $overall, $nsCols, $hfaCols);
        }

        return $values;
    }

    /**
     * Expands one grade's male/female counts into flat cell values: per-row
     * Total columns, a computed TOTAL row, and blank (not "0") data cells so an
     * empty report reads like the blank DepEd sheet.
     *
     * @param  array<string, int|string>  $values
     * @param  array<string, array<string, int>>  $table
     * @param  list<string>  $nsCols
     * @param  list<string>  $hfaCols
     */
    private function flattenBmiTable(array &$values, string $prefix, array $table, array $nsCols, array $hfaCols): void
    {
        $totals = [];

        foreach (['male', 'female'] as $sex) {
            $nst = 0;
            $hfat = 0;
            foreach ($nsCols as $col) {
                $v = (int) ($table[$sex][$col] ?? 0);
                $nst += $v;
                $totals[$col] = ($totals[$col] ?? 0) + $v;
                $values[$prefix.'_'.$sex.'_'.$col] = $v > 0 ? $v : '';
            }
            foreach ($hfaCols as $col) {
                $v = (int) ($table[$sex][$col] ?? 0);
                $hfat += $v;
                $totals[$col] = ($totals[$col] ?? 0) + $v;
                $values[$prefix.'_'.$sex.'_'.$col] = $v > 0 ? $v : '';
            }
            $values[$prefix.'_'.$sex.'_nst'] = $nst;
            $values[$prefix.'_'.$sex.'_hfat'] = $hfat;
            $totals['nst'] = ($totals['nst'] ?? 0) + $nst;
            $totals['hfat'] = ($totals['hfat'] ?? 0) + $hfat;
        }

        // TOTAL row and Total columns always show an integer, matching the sheet.
        foreach (array_merge($nsCols, $hfaCols, ['nst', 'hfat']) as $col) {
            $values[$prefix.'_total_'.$col] = $totals[$col] ?? 0;
        }
    }

    /**
     * "Grade 7" → "g7"; the report covers Grades 7-12 (JHS 7-10 plus SHS
     * 11-12), so anything outside that range is left off the grids.
     */
    private function bmiGradeKey(string $gradeLabel): ?string
    {
        if (preg_match('/(\d{1,2})/', $gradeLabel, $m)) {
            $grade = (int) $m[1];
            if ($grade >= 7 && $grade <= 12) {
                return 'g'.$grade;
            }
        }

        return null;
    }

    private function bmiSexKey(string $gender): ?string
    {
        $g = strtolower(trim($gender));
        if (str_starts_with($g, 'm')) {
            return 'male';
        }
        if (str_starts_with($g, 'f')) {
            return 'female';
        }

        return null;
    }

    /** Maps a BMI-for-age status string onto a report column, or null to skip. */
    private function bmiNsColumn(string $status): ?string
    {
        $s = strtolower(trim($status));
        if ($s === '') {
            return null;
        }
        if (str_contains($s, 'severe') && str_contains($s, 'wast')) {
            return 'sw';
        }
        if (str_contains($s, 'wast')) {
            return 'w';
        }
        // The adviser classifier emits "Underweight" (BMI 17.0-18.5); the DepEd
        // BMI-for-age sheet has no such column, so it is grouped under Wasted —
        // consistent with isQualifiedForFeeding() treating both as undernourished.
        if (str_contains($s, 'underweight')) {
            return 'w';
        }
        if (str_contains($s, 'obes')) {
            return 'ob';
        }
        if (str_contains($s, 'over')) {
            return 'ow';
        }
        if (str_contains($s, 'normal')) {
            return 'n';
        }

        return null;
    }

    /** Maps a height-for-age status string onto a report column, or null to skip. */
    private function bmiHfaColumn(string $hfa): ?string
    {
        $s = strtolower(trim($hfa));
        if ($s === '') {
            return null;
        }
        if (str_contains($s, 'severe') && str_contains($s, 'stunt')) {
            return 'ss';
        }
        if (str_contains($s, 'stunt')) {
            return 'st';
        }
        if (str_contains($s, 'tall')) {
            return 't';
        }
        if (str_contains($s, 'normal')) {
            return 'hn';
        }

        return null;
    }

    /**
     * Height-for-age classification for the Final report, mirroring
     * App\Http\Controllers\AdviserController::classifyHeightForAge so endline
     * HFA is derived the same way baseline HFA originally was.
     */
    private function classifyHeightForAge(float $heightCm, int $age): string
    {
        if ($heightCm <= 0 || $age <= 0) {
            return '';
        }

        $minNormalHeight = 70 + ($age * 5);
        if ($heightCm < ($minNormalHeight - 8)) {
            return 'Severely Stunted';
        }
        if ($heightCm < $minNormalHeight) {
            return 'Stunted';
        }

        return 'Normal Height-for-Age';
    }

    /**
     * Splits the plain "Grade X / Section" string into [grade, section].
     * Rows with no section land under "Unassigned" so they stay selectable.
     */
    private function splitSection(string $section): array
    {
        $parts = explode(' / ', $section, 2);
        $grade = trim($parts[0]);
        $sectionName = trim($parts[1] ?? '');

        return [$grade !== '' ? $grade : 'Unassigned', $sectionName];
    }

    private function normalizeStatus(string $status): string
    {
        $normalized = strtolower(trim($status));
        if ($normalized === '') {
            return '';
        }
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
        if (str_contains($normalized, 'normal')) {
            return 'Normal';
        }

        return $status;
    }

    private function isQualifiedForFeeding(string $status): bool
    {
        $normalized = strtolower($status);

        return str_contains($normalized, 'wast')
            || str_contains($normalized, 'severe')
            || str_contains($normalized, 'underweight');
    }

    public function dashboard(Request $request): View
    {
        $institutionId = $request->session()->get('active_institution_id');

        return view(
            'feedingcor-dashboard.feed-dashboard',
            $this->buildDashboardMetrics($institutionId, $this->readFilters($request))
                + ['stamp' => $this->metricsStamp($institutionId)]
        );
    }

    /**
     * The live panels, re-read on demand. The first paint and every refresh run
     * through buildDashboardMetrics and render the same Blade partials, so the
     * screen a coordinator watches can never drift from a reloaded one.
     */
    public function metrics(Request $request): JsonResponse
    {
        if (! $this->isCoordinator($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $institutionId = $request->session()->get('active_institution_id');
        // The page forwards its own query string, so a refresh re-renders the
        // filtered view the coordinator is looking at, not the unfiltered one.
        $metrics = $this->buildDashboardMetrics($institutionId, $this->readFilters($request));

        return response()->json([
            'stamp' => $this->metricsStamp($institutionId),
            'generatedAt' => $metrics['generatedAt'],
            'html' => [
                'cards' => view('feedingcor-dashboard.partials.kpi-cards', $metrics)->render(),
                'attendance' => view('feedingcor-dashboard.partials.attendance-monitoring', $metrics)->render(),
                'nutrition' => view('feedingcor-dashboard.partials.nutrition-status', $metrics)->render(),
                'risk' => view('feedingcor-dashboard.partials.attendance-risk', $metrics)->render(),
                'progress' => view('feedingcor-dashboard.partials.nutrition-progress', $metrics)->render(),
            ],
        ]);
    }

    /**
     * Change-detection only: a hash of row counts and last-touched timestamps.
     * It carries no personal information, so the dashboard can poll it on a
     * timer and pay for the (much heavier) rebuild only when it moves.
     */
    public function pulse(Request $request): JsonResponse
    {
        if (! $this->isCoordinator($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(['stamp' => $this->metricsStamp($request->session()->get('active_institution_id'))]);
    }

    /**
     * The filters the dashboard reads off the query string. Only the school
     * year touches the database; grade, section and the two status filters are
     * applied in PHP, because the columns they read are encrypted at rest.
     *
     * @return array{school_year: string, grade: string, section: string, status: string, attendance: string}
     */
    private function readFilters(Request $request): array
    {
        $clean = fn (string $key): string => trim((string) $request->query($key, ''));

        return [
            'school_year' => $clean('school_year') ?: StudentHealthRecord::currentSchoolYear(),
            'grade' => $clean('grade'),
            'section' => $clean('section'),
            'status' => $clean('status'),
            'attendance' => in_array($clean('attendance'), ['present', 'absent', 'unmarked'], true)
                ? $clean('attendance')
                : '',
        ];
    }

    /**
     * @param  array{school_year: string, grade: string, section: string, status: string, attendance: string}|null  $filters
     * @return array<string, mixed>
     */
    private function buildDashboardMetrics(?int $institutionId, ?array $filters = null): array
    {
        $filters ??= [
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'grade' => '',
            'section' => '',
            'status' => '',
            'attendance' => '',
        ];

        $students = collect();
        $schoolYears = collect();
        if (SchemaCache::hasTable('student_health_records')) {
            $scope = fn () => StudentHealthRecord::query()
                ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId));

            // school_year is a plain lookup column, so the year list and the
            // year filter are the only parts of this that SQL may decide.
            $schoolYears = $scope()->distinct()->orderByDesc('school_year')->pluck('school_year')->values();
            $students = $scope()->forCurrentSchoolYear($filters['school_year'])->get();
        }

        if ($schoolYears->doesntContain($filters['school_year'])) {
            $schoolYears = $schoolYears->push($filters['school_year'])->sortDesc()->values();
        }

        // The feeding cycle the header counts down comes from the school's first
        // recorded session, so this page and the Feeding Program page never
        // disagree about which day it is.
        $cycle = FeedingProgramCycle::forInstitution($institutionId);
        $programDay = $cycle->day();

        // nutritional_status is encrypted at rest, so eligibility is decided in
        // PHP after fetch — the same test the Feeding Program page applies.
        $allBeneficiaries = $students
            ->filter(fn (StudentHealthRecord $record): bool => $this->isQualifiedForFeeding((string) $record->nutritional_status))
            ->values();

        // Grade and section scope every panel; the two status filters narrow
        // the attendance roll alone (see buildTodayAttendance), so the headline
        // stays "of everyone expected today" rather than "of what is on screen".
        $beneficiaries = $allBeneficiaries
            ->filter(function (StudentHealthRecord $record) use ($filters): bool {
                [$grade, $section] = $this->splitSection((string) $record->section);

                return ($filters['grade'] === '' || $grade === $filters['grade'])
                    && ($filters['section'] === '' || $section === $filters['section']);
            })
            ->values();

        $rule = FeedingAtRiskRule::forInstitution($institutionId);
        $beneficiaryStats = $this->buildBeneficiaryStats($beneficiaries, $rule);

        return [
            'filters' => $filters,
            'filterOptions' => $this->buildFilterOptions($allBeneficiaries, $schoolYears, $filters),
            'dashboardStats' => [
                'total_students' => $students->count(),
                'program_day' => $programDay,
                'beneficiaries' => $beneficiaryStats['beneficiaries'],
                'beneficiaries_jhs' => $beneficiaryStats['jhs'],
                'beneficiaries_shs' => $beneficiaryStats['shs'],
                'attendance_rate' => $beneficiaryStats['attendance_rate'],
                'attendance_sessions' => $beneficiaryStats['confirmed_sessions'],
                'at_risk' => $beneficiaryStats['at_risk'],
                'at_risk_rule' => $beneficiaryStats['at_risk_rule'],
                'awaiting_enrollment' => $beneficiaryStats['awaiting_enrollment'],
            ],
            'programCycle' => [
                'school_year' => StudentHealthRecord::currentSchoolYear(),
                'day' => $programDay,
                'duration' => FeedingProgramCycle::DURATION_DAYS,
                'days_remaining' => $cycle->daysRemaining(),
                'percent' => $cycle->percent(),
                'started' => $cycle->hasStarted(),
                'start_date' => $cycle->startDateIso(),
            ],
            'nutritionStatus' => $this->buildNutritionStatus($beneficiaries),
            'todayAttendance' => $this->buildTodayAttendance($beneficiaries, $filters),
            'attendanceRisk' => $this->buildAttendanceRisk($beneficiaries, $rule),
            'nutritionProgress' => FeedingNutritionProgress::build(
                $beneficiaries,
                fn (StudentHealthRecord $record): string => $this->panelStatus($record),
                fn (StudentHealthRecord $record): string => $this->endlineStatus($record),
            ),
            'generatedAt' => now()->format('g:i A'),
        ];
    }

    /**
     * Options for the five coordinated filters. Grades and sections are read
     * off the plain "section" column of the learners actually on file, and the
     * section list narrows to the chosen grade — picking Grade 7 must not leave
     * Grade 8's sections selectable.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @param  Collection<int, string>  $schoolYears
     * @param  array<string, string>  $filters
     * @return array<string, list<array<string, string>>|list<string>>
     */
    private function buildFilterOptions(Collection $beneficiaries, Collection $schoolYears, array $filters): array
    {
        $pairs = $beneficiaries
            ->map(fn (StudentHealthRecord $record): array => $this->splitSection((string) $record->section))
            ->values();

        $grades = $pairs->pluck(0)->filter()->unique()->sort(SORT_NATURAL)->values();
        $sections = $pairs
            ->filter(fn (array $pair): bool => $filters['grade'] === '' || $pair[0] === $filters['grade'])
            ->pluck(1)
            ->filter()
            ->unique()
            ->sort(SORT_NATURAL)
            ->values();

        return [
            'school_years' => $schoolYears->all(),
            'grades' => $grades->all(),
            'sections' => $sections->all(),
            'statuses' => array_column($this->nutritionScale(), 'label'),
            'attendance' => [
                ['value' => 'present', 'label' => 'Present'],
                ['value' => 'absent', 'label' => 'Absent'],
                ['value' => 'unmarked', 'label' => 'Unmarked'],
            ],
        ];
    }

    /**
     * The nutritional-status scale the dashboard reports on.
     *
     * Underweight and Overweight are deliberately absent: "Underweight" is this
     * app's non-standard label for a learner the DepEd sheet counts as Wasted,
     * so panelStatus() folds it there exactly as the BMI reports do, and no
     * Overweight learner is ever a feeding beneficiary. Severely Wasted and
     * Wasted decide eligibility, so they lead and carry the loudest badges.
     *
     * @return list<array{label: string, badge: string, eligible: bool}>
     */
    private function nutritionScale(): array
    {
        return [
            ['label' => 'Severely Wasted', 'badge' => 'badge-critical', 'eligible' => true],
            ['label' => 'Wasted', 'badge' => 'badge-risk', 'eligible' => true],
            ['label' => 'Normal', 'badge' => 'badge-normal', 'eligible' => false],
            ['label' => 'Obese', 'badge' => 'badge-monitor', 'eligible' => false],
        ];
    }

    /**
     * The beneficiary roll broken down by baseline nutritional status. The
     * counts always sum to the beneficiary total, so the panel and the headline
     * card can never tell two different stories.
     *
     * Rows are listed even where the count is zero: a coordinator reading
     * "Obese 0" learns something, a missing row only looks like a bug.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @return array{total: int, rows: list<array{label: string, count: int, badge: string, eligible: bool}>}
     */
    private function buildNutritionStatus(Collection $beneficiaries): array
    {
        $counts = [];
        foreach ($beneficiaries as $record) {
            $status = $this->panelStatus($record);
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return [
            'total' => $beneficiaries->count(),
            'rows' => array_map(
                fn (array $row): array => $row + ['count' => (int) ($counts[$row['label']] ?? 0)],
                $this->nutritionScale()
            ),
        ];
    }

    /**
     * One learner's status as the dashboard reports it.
     *
     * Baseline is what the programme enrolled them on; a learner with no
     * baseline on file is counted under their current status instead. The
     * app's "Underweight" is folded into Wasted so the breakdown always sums
     * to the beneficiary total — see nutritionScale().
     */
    private function panelStatus(StudentHealthRecord $record): string
    {
        $status = $this->normalizeStatus((string) ($record->baseline_nutritional_status ?: $record->nutritional_status));

        // Every status lands on exactly one row of nutritionScale(), including
        // ones the scale does not name — a learner who fell through would make
        // the breakdown stop summing to the beneficiary total.
        return match ($status) {
            'Severely Wasted' => 'Severely Wasted',
            'Wasted', 'Underweight' => 'Wasted',
            'Overweight', 'Obese' => FeedingNutritionProgress::ABOVE_NORMAL,
            default => 'Normal',
        };
    }

    /**
     * Today's feeding session, learner by learner.
     *
     * A learner is Present or Absent once the session is recorded, and carries
     * the coordinator's remark for why they were away. Two states remain that
     * are neither: a scanned mark no human has read, and a learner today's
     * sheet has not covered at all. Both render as "—" rather than an absence,
     * because reporting them as absent would claim an absence nobody witnessed.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @param  array<string, string>  $filters
     * @return array<string, mixed>
     */
    private function buildTodayAttendance(Collection $beneficiaries, array $filters): array
    {
        $today = now()->toDateString();
        $marks = collect();

        if ($beneficiaries->isNotEmpty() && SchemaCache::hasTable('feeding_attendances')) {
            $columns = ['student_health_record_id', 'is_present'];
            if (SchemaCache::hasColumn('feeding_attendances', 'needs_review')) {
                $columns[] = 'needs_review';
            }
            if (SchemaCache::hasColumn('feeding_attendances', 'remarks')) {
                $columns[] = 'remarks';
            }

            $marks = FeedingAttendance::query()
                ->whereIn('student_health_record_id', $beneficiaries->pluck('id'))
                ->whereDate('session_date', $today)
                ->get($columns)
                ->keyBy('student_health_record_id');
        }

        $counts = ['present' => 0, 'absent' => 0, 'unconfirmed' => 0, 'unrecorded' => 0];

        // student_name is encrypted at rest, so sorting happens in PHP.
        $rows = $beneficiaries
            ->map(function (StudentHealthRecord $record) use ($marks, &$counts): array {
                $mark = $marks->get($record->id);
                [$grade, $section] = $this->splitSection((string) $record->section);

                $status = match (true) {
                    $mark === null => 'unrecorded',
                    (bool) ($mark->needs_review ?? false), $mark->is_present === null => 'unconfirmed',
                    (bool) $mark->is_present => 'present',
                    default => 'absent',
                };
                $counts[$status]++;

                return [
                    'name' => (string) $record->student_name,
                    'grade' => $grade,
                    'section' => $section,
                    'nutritional_status' => $this->panelStatus($record),
                    'status' => $status,
                    'remarks' => trim((string) ($mark->remarks ?? '')),
                ];
            })
            ->sortBy(fn (array $row) => strtolower($row['name']))
            ->values();

        $expected = $beneficiaries->count();

        // The headline counts everyone expected today; the two list filters
        // narrow only which of them the table draws.
        $visible = $rows
            ->when($filters['status'] !== '', fn ($rows) => $rows->filter(
                fn (array $row): bool => $row['nutritional_status'] === $filters['status']
            ))
            ->when($filters['attendance'] !== '', fn ($rows) => $rows->filter(
                fn (array $row): bool => $filters['attendance'] === 'unmarked'
                    ? in_array($row['status'], ['unconfirmed', 'unrecorded'], true)
                    : $row['status'] === $filters['attendance']
            ))
            ->values()
            ->all();

        return [
            'date_label' => now()->format('M d, Y'),
            'expected' => $expected,
            'present' => $counts['present'],
            'absent' => $counts['absent'],
            'unconfirmed' => $counts['unconfirmed'],
            'unrecorded' => $counts['unrecorded'],
            // Share of the expected headcount confirmed present today. Zero of
            // them is still a count, so the figure reads the same before and
            // after the session is recorded; the chips say which it is.
            'percent' => $expected > 0
                ? round(($counts['present'] / $expected) * 100, 1)
                : 0.0,
            'recorded' => $counts['unrecorded'] < $expected,
            'filtered' => $filters['status'] !== '' || $filters['attendance'] !== '',
            'rows' => $visible,
        ];
    }

    /**
     * A fingerprint of the tables the dashboard reads — row counts and
     * last-touched timestamps only, never a column holding personal data, so
     * the polling endpoint stays free of it.
     */
    private function metricsStamp(?int $institutionId): string
    {
        $parts = [now()->toDateString()];

        foreach (['student_health_records', 'feeding_attendances'] as $table) {
            if (! SchemaCache::hasTable($table)) {
                $parts[] = '-';

                continue;
            }

            $query = DB::table($table);
            // feeding_attendances inherits its school scope from the parent
            // record, so only the owning table filters. A neighbouring school's
            // write can cost one needless refetch — never a missed change, and
            // nothing of theirs is ever read.
            if ($institutionId && SchemaCache::hasColumn($table, 'institution_id')) {
                $query->where('institution_id', $institutionId);
            }

            $row = $query->selectRaw('COUNT(*) as row_count, MAX(updated_at) as last_touched')->first();
            $parts[] = ((int) ($row->row_count ?? 0)).'@'.((string) ($row->last_touched ?? ''));
        }

        return md5(implode('|', $parts));
    }

    private function isCoordinator(Request $request): bool
    {
        return $request->session()->get('active_role') === 'feeding_coor';
    }

    /**
     * The four headline figures: who the programme feeds, how well they are
     * showing up, who the attendance rule has flagged, and who qualifies but
     * has never been fed yet.
     *
     * Attendance is counted over confirmed marks only. A scanned mark nobody
     * has reviewed is NULL and votes neither way, so it can neither depress the
     * rate nor flag a learner — the same rule FeedingAtRiskRule applies. Never
     * aggregate this in SQL: a SUM(CASE WHEN is_present ...) folds NULL into
     * "absent" and quietly breaks that invariant.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @return array{beneficiaries: int, jhs: int, shs: int, attendance_rate: ?int, confirmed_sessions: int, at_risk: int, at_risk_rule: string, awaiting_enrollment: int}
     */
    private function buildBeneficiaryStats(Collection $beneficiaries, FeedingAtRiskRule $rule): array
    {
        $marksByRecord = $this->marksByRecord($beneficiaries);

        $confirmedSessions = 0;
        $presentSessions = 0;
        $awaiting = 0;
        $atRisk = 0;

        foreach ($beneficiaries as $record) {
            $marks = $marksByRecord->get($record->id, []);
            if ($marks === []) {
                $awaiting++;

                continue;
            }

            $confirmed = array_filter($marks, static fn ($mark) => $mark !== null);
            $confirmedSessions += count($confirmed);
            $presentSessions += $rule->presentCount($marks);

            // Flagged live from the school's current threshold rather than read
            // off the stored is_at_risk column, so raising or lowering the
            // threshold shows on the dashboard at once instead of waiting for
            // the next import to recompute the flags.
            if ($rule->isAtRisk($marks)) {
                $atRisk++;
            }
        }

        return [
            'beneficiaries' => $beneficiaries->count(),
            'jhs' => $beneficiaries->filter(fn ($r) => $this->resolveLevel((string) $r->section) === 'jhs')->count(),
            'shs' => $beneficiaries->filter(fn ($r) => $this->resolveLevel((string) $r->section) === 'shs')->count(),
            // Null, not zero: no confirmed session is not a 0% turnout.
            'attendance_rate' => $confirmedSessions > 0
                ? (int) round(($presentSessions / $confirmedSessions) * 100)
                : null,
            'confirmed_sessions' => $confirmedSessions,
            'at_risk' => $atRisk,
            'at_risk_rule' => $rule->describe(),
            'awaiting_enrollment' => $awaiting,
        ];
    }

    /**
     * Every beneficiary's session marks in date order, NULL where a scanned
     * mark is still unconfirmed.
     *
     * Fetched, never aggregated in SQL: a `SUM(CASE WHEN is_present ...)` folds
     * NULL into "absent", which is the one thing the attendance rule must never
     * do. One query serves the cards, the at-risk panel and the roll.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @return Collection<int, list<bool|null>>
     */
    private function marksByRecord(Collection $beneficiaries): Collection
    {
        if ($beneficiaries->isEmpty() || ! SchemaCache::hasTable('feeding_attendances')) {
            return collect();
        }

        $hasReviewColumn = SchemaCache::hasColumn('feeding_attendances', 'needs_review');

        return FeedingAttendance::query()
            ->whereIn('student_health_record_id', $beneficiaries->pluck('id'))
            ->whereDate('session_date', '<=', now()->toDateString())
            ->orderBy('session_date')
            ->get(array_merge(
                ['student_health_record_id', 'session_date', 'is_present'],
                $hasReviewColumn ? ['needs_review'] : []
            ))
            ->groupBy('student_health_record_id')
            // Before the review migration every mark is confirmed by definition.
            ->map(fn ($rows) => $rows->map(fn ($row) => ($row->needs_review ?? false) ? null : $row->is_present)->all());
    }

    /**
     * The learners the school's attendance threshold has flagged, worst first.
     *
     * Days present is counted over **confirmed** sessions only — the same
     * denominator the rule itself uses — so the fraction on screen is exactly
     * the one that decided the flag, and a learner can never read as "34/47"
     * while being judged on some other 47.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @return array<string, mixed>
     */
    private function buildAttendanceRisk(Collection $beneficiaries, FeedingAtRiskRule $rule): array
    {
        $marksByRecord = $this->marksByRecord($beneficiaries);

        $rows = $beneficiaries
            ->map(function (StudentHealthRecord $record) use ($marksByRecord, $rule): ?array {
                $marks = $marksByRecord->get($record->id, []);

                if (! $rule->isAtRisk($marks)) {
                    return null;
                }

                [$grade, $section] = $this->splitSection((string) $record->section);
                $confirmed = count(array_filter($marks, static fn ($mark) => $mark !== null));

                return [
                    'id' => $record->id,
                    'name' => (string) $record->student_name,
                    'grade' => $grade,
                    'section' => $section,
                    'rate' => $rule->attendanceRate($marks),
                    'present' => $rule->presentCount($marks),
                    'sessions' => $confirmed,
                ];
            })
            ->filter()
            // Lowest attendance first: the learner in most trouble is the one
            // the coordinator should reach today.
            ->sortBy([
                fn (array $a, array $b) => ($a['rate'] ?? 0) <=> ($b['rate'] ?? 0),
                fn (array $a, array $b) => strtolower($a['name']) <=> strtolower($b['name']),
            ])
            ->values()
            ->all();

        return [
            'threshold' => $rule->thresholdPercent(),
            'rule' => $rule->describe(),
            'mode' => $rule->mode(),
            'count' => count($rows),
            'rows' => $rows,
        ];
    }

    /**
     * A learner's endline status on the same scale as panelStatus(), or '' when
     * no endline measurement has been taken yet. An unmeasured learner is not
     * "unchanged" — they are simply not part of the improvement figure.
     */
    private function endlineStatus(StudentHealthRecord $record): string
    {
        $status = trim((string) $record->endline_nutritional_status);

        if ($status === '') {
            return '';
        }

        $normalized = $this->normalizeStatus($status);

        return match ($normalized) {
            // Same folding as panelStatus(): the app's "Underweight" is the
            // DepEd sheet's Wasted, and anything above Normal shares one rung.
            'Underweight' => 'Wasted',
            'Overweight' => FeedingNutritionProgress::ABOVE_NORMAL,
            default => $normalized,
        };
    }

    private function resolveLevel(string $section): string
    {
        $normalized = strtolower($section);
        if (str_contains($normalized, 'shs') || str_contains($normalized, 'grade 11') || str_contains($normalized, 'grade 12') || str_contains($normalized, 'g11') || str_contains($normalized, 'g12')) {
            return 'shs';
        }

        return 'jhs';
    }

    private function resolveStatus(string $status): string
    {
        $normalized = strtolower($status);
        if (str_contains($normalized, 'severe')) {
            return 'severe';
        }
        if (str_contains($normalized, 'wast') || str_contains($normalized, 'underweight')) {
            return 'wasted';
        }
        if (str_contains($normalized, 'over')) {
            return 'over';
        }

        return 'normal';
    }
}
