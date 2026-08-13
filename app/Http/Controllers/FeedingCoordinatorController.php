<?php

namespace App\Http\Controllers;

use App\Models\FeedingAttendance;
use App\Models\StudentHealthRecord;
use App\Support\FeedingAtRiskRule;
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
            $this->buildDashboardMetrics($institutionId) + ['stamp' => $this->metricsStamp($institutionId)]
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
        $metrics = $this->buildDashboardMetrics($institutionId);

        return response()->json([
            'stamp' => $this->metricsStamp($institutionId),
            'generatedAt' => $metrics['generatedAt'],
            'html' => [
                'cards' => view('feedingcor-dashboard.partials.kpi-cards', $metrics)->render(),
                'attendance' => view('feedingcor-dashboard.partials.attendance-monitoring', $metrics)->render(),
                'nutrition' => view('feedingcor-dashboard.partials.nutrition-status', $metrics)->render(),
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
     * @return array<string, mixed>
     */
    private function buildDashboardMetrics(?int $institutionId): array
    {
        $students = collect();
        if (SchemaCache::hasTable('student_health_records')) {
            $q = StudentHealthRecord::query();
            if ($institutionId) {
                $q->where('institution_id', $institutionId);
            }
            $students = $q->forCurrentSchoolYear()->get();
        }

        // The feeding cycle the header counts down comes from the school's first
        // recorded session, so this page and the Feeding Program page never
        // disagree about which day it is.
        $cycle = FeedingProgramCycle::forInstitution($institutionId);
        $programDay = $cycle->day();

        // nutritional_status is encrypted at rest, so eligibility is decided in
        // PHP after fetch — the same test the Feeding Program page applies.
        $beneficiaries = $students
            ->filter(fn (StudentHealthRecord $record): bool => $this->isQualifiedForFeeding((string) $record->nutritional_status))
            ->values();

        $beneficiaryStats = $this->buildBeneficiaryStats($beneficiaries);

        return [
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
            'todayAttendance' => $this->buildTodayAttendance($beneficiaries),
            'roster' => $this->buildRoster($students),
            'generatedAt' => now()->format('g:i A'),
        ];
    }

    /**
     * The beneficiary roll broken down by baseline nutritional status. The
     * counts always sum to the beneficiary total, so the panel and the headline
     * card can never tell two different stories.
     *
     * The full DepEd scale is listed even where a row is zero: a coordinator
     * reading "Obese 0" learns something, a missing row only looks like a bug.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @return array{total: int, rows: list<array{label: string, count: int, badge: string, eligible: bool}>}
     */
    private function buildNutritionStatus(Collection $beneficiaries): array
    {
        // Wasted and Severely Wasted decide eligibility, so they lead the table
        // and carry the loudest badges.
        $scale = [
            ['label' => 'Severely Wasted', 'badge' => 'badge-critical', 'eligible' => true],
            ['label' => 'Wasted', 'badge' => 'badge-risk', 'eligible' => true],
            ['label' => 'Underweight', 'badge' => 'badge-risk', 'eligible' => true],
            ['label' => 'Normal', 'badge' => 'badge-normal', 'eligible' => false],
            ['label' => 'Overweight', 'badge' => 'badge-monitor', 'eligible' => false],
            ['label' => 'Obese', 'badge' => 'badge-monitor', 'eligible' => false],
        ];

        $counts = [];
        foreach ($beneficiaries as $record) {
            // Baseline is what the programme enrolled them on; a learner with no
            // baseline on file is counted under their current status instead.
            $status = $this->normalizeStatus((string) ($record->baseline_nutritional_status ?: $record->nutritional_status));
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return [
            'total' => $beneficiaries->count(),
            'rows' => array_map(
                fn (array $row): array => $row + ['count' => (int) ($counts[$row['label']] ?? 0)],
                $scale
            ),
        ];
    }

    /**
     * Today's feeding session, learner by learner.
     *
     * Four states, not two: present and absent are confirmed marks, unconfirmed
     * is a scanned mark no human has read, and "not recorded" is a learner
     * today's sheet has not covered at all. Collapsing either of the last two
     * into "absent" would report an absence nobody witnessed.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @return array<string, mixed>
     */
    private function buildTodayAttendance(Collection $beneficiaries): array
    {
        $today = now()->toDateString();
        $marks = collect();

        if ($beneficiaries->isNotEmpty() && SchemaCache::hasTable('feeding_attendances')) {
            $hasReviewColumn = SchemaCache::hasColumn('feeding_attendances', 'needs_review');

            $marks = FeedingAttendance::query()
                ->whereIn('student_health_record_id', $beneficiaries->pluck('id'))
                ->whereDate('session_date', $today)
                ->get(array_merge(
                    ['student_health_record_id', 'is_present'],
                    $hasReviewColumn ? ['needs_review'] : []
                ))
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
                    'status' => $status,
                ];
            })
            ->sortBy(fn (array $row) => strtolower($row['name']))
            ->values()
            ->all();

        $expected = $beneficiaries->count();

        return [
            'date_label' => now()->format('M d, Y'),
            'expected' => $expected,
            'present' => $counts['present'],
            'absent' => $counts['absent'],
            'unconfirmed' => $counts['unconfirmed'],
            'unrecorded' => $counts['unrecorded'],
            // Share of the expected headcount confirmed present today; null when
            // the session has not been recorded at all.
            'percent' => $expected > 0 && $counts['unrecorded'] < $expected
                ? round(($counts['present'] / $expected) * 100, 1)
                : null,
            'recorded' => $counts['unrecorded'] < $expected,
            'rows' => $rows,
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
    private function buildBeneficiaryStats(Collection $beneficiaries): array
    {
        $rule = FeedingAtRiskRule::fromConfig();

        $marksByRecord = collect();
        if ($beneficiaries->isNotEmpty() && SchemaCache::hasTable('feeding_attendances')) {
            $hasReviewColumn = SchemaCache::hasColumn('feeding_attendances', 'needs_review');

            $marksByRecord = FeedingAttendance::query()
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

        $confirmedSessions = 0;
        $presentSessions = 0;
        $awaiting = 0;

        foreach ($beneficiaries as $record) {
            $marks = $marksByRecord->get($record->id, []);
            if ($marks === []) {
                $awaiting++;

                continue;
            }

            $confirmed = array_filter($marks, static fn ($mark) => $mark !== null);
            $confirmedSessions += count($confirmed);
            $presentSessions += $rule->presentCount($marks);
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
            'at_risk' => $beneficiaries->filter(fn ($r) => (bool) $r->is_at_risk)->count(),
            'at_risk_rule' => $rule->describe(),
            'awaiting_enrollment' => $awaiting,
        ];
    }

    /**
     * Per-student roster powering the Student Roster panel and the Weekly
     * Check-ins table: name, grade, latest weight/BMI, the BMI change (endline
     * vs baseline) and a derived status. Learners needing attention sort first.
     *
     * @param  Collection<int, StudentHealthRecord>  $students
     * @return array{students: list<array<string, mixed>>, improving: int, attention: int, stable: int}
     */
    private function buildRoster(Collection $students): array
    {
        $priority = ['attention' => 0, 'improving' => 1, 'stable' => 2];

        $rows = $students->map(function (StudentHealthRecord $record): array {
            [$grade] = $this->splitSection((string) $record->section);
            $baseline = $record->baseline_bmi_value;
            $endline = $record->endline_bmi_value;
            $currentBmi = $endline ?? $record->bmi_value;

            $change = ($endline !== null && $baseline !== null)
                ? round((float) $endline - (float) $baseline, 1)
                : 0.0;
            $trend = $change > 0.05 ? 'up' : ($change < -0.05 ? 'down' : 'flat');

            $statusNorm = $this->resolveStatus((string) $record->nutritional_status);
            if ($trend === 'up') {
                $status = 'improving';
            } elseif ($trend === 'down' || in_array($statusNorm, ['severe', 'wasted'], true)) {
                $status = 'attention';
            } else {
                $status = 'stable';
            }

            return [
                'initials' => $this->initials((string) $record->student_name),
                'name' => (string) $record->student_name,
                'grade' => $grade,
                'weight' => $record->weight !== null ? number_format((float) $record->weight, 1) : '—',
                'bmi' => $currentBmi !== null ? number_format((float) $currentBmi, 1) : '—',
                'change' => $change,
                'trend' => $trend,
                'status' => $status,
            ];
        })->sortBy(fn (array $r) => sprintf('%d-%s', $priority[$r['status']], strtolower($r['name'])))->values();

        return [
            'students' => $rows->all(),
            'improving' => $rows->where('status', 'improving')->count(),
            'attention' => $rows->where('status', 'attention')->count(),
            'stable' => $rows->where('status', 'stable')->count(),
        ];
    }

    /** Two-letter monogram for a "Last, First M." (or plain) student name. */
    private function initials(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '—';
        }

        if (str_contains($name, ',')) {
            [$last, $first] = array_pad(array_map('trim', explode(',', $name, 2)), 2, '');
            $letters = mb_substr($first, 0, 1).mb_substr($last, 0, 1);
        } else {
            $parts = preg_split('/\s+/', $name) ?: [];
            $letters = mb_substr($parts[0] ?? '', 0, 1).mb_substr($parts[1] ?? '', 0, 1);
        }

        $letters = trim($letters);

        return mb_strtoupper($letters !== '' ? $letters : mb_substr($name, 0, 2));
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
