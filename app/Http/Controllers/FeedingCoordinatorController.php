<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\StudentHealthRecord;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class FeedingCoordinatorController extends Controller
{
    public function sbfpForms(Request $request): View
    {
        $institutionId = $request->session()->get('active_institution_id');

        $records = collect();
        if (Schema::hasTable('student_health_records')) {
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
        if (! Schema::hasTable('accounts')) {
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
     * Grades 7-10 plus Overall — for both the Baseline (prefix "bmib") and the
     * Final/endline (prefix "bmif") assessments. Every cell is derived here (in
     * PHP, since names/statuses are encrypted at rest) and rendered read-only,
     * so the reports always mirror the current roster with no hand-keying.
     *
     * @param  Collection<int, StudentHealthRecord>  $records
     * @return array<string, int|string>
     */
    private function buildBmiValues(Collection $records): array
    {
        $gradeKeys = ['g7', 'g8', 'g9', 'g10'];
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
                continue; // Report only covers Grades 7-10.
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

    /** "Grade 7" → "g7"; only Grades 7-10 are on the report. */
    private function bmiGradeKey(string $gradeLabel): ?string
    {
        if (preg_match('/(\d{1,2})/', $gradeLabel, $m)) {
            $grade = (int) $m[1];
            if ($grade >= 7 && $grade <= 10) {
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

    public function dashboard(): View
    {
        $institutionId = session('active_institution_id');

        $students = collect();
        if (Schema::hasTable('student_health_records')) {
            $q = StudentHealthRecord::query();
            if ($institutionId) {
                $q->where('institution_id', $institutionId);
            }
            $students = $q->forCurrentSchoolYear()->get();
        }

        $totalStudents = $students->count();
        $levelCounts = ['jhs' => 0, 'shs' => 0];
        $statusCounts = ['severe' => 0, 'wasted' => 0, 'normal' => 0, 'over' => 0];

        foreach ($students as $student) {
            $level = $this->resolveLevel((string) $student->section);
            $levelCounts[$level]++;

            $status = $this->resolveStatus((string) $student->nutritional_status);
            $statusCounts[$status]++;
        }

        $programDay = 0;
        if ($students->isNotEmpty()) {
            $startDate = $students->min('created_at');
            $programDay = $startDate
                ? min(120, Carbon::parse($startDate)->startOfDay()->diffInDays(now()->startOfDay()) + 1)
                : 0;
        }

        $bmiChart = $this->buildBmiChart($students);
        $weeklyBars = $this->buildWeeklyBars($totalStudents, $institutionId);
        $avgAttendance = $totalStudents > 0
            ? (int) round((collect($weeklyBars)->avg('present') / max(1, $totalStudents)) * 100)
            : 0;

        $improvingCount = $statusCounts['normal'];
        $stableCount = $statusCounts['over'];
        $regressingCount = $statusCounts['severe'] + $statusCounts['wasted'];

        $progressTotal = max(1, $improvingCount + $stableCount + $regressingCount);
        $improvingPct = round(($improvingCount / $progressTotal) * 100, 1);
        $stablePct = round(($stableCount / $progressTotal) * 100, 1);

        return view('feedingcor-dashboard.feed-dashboard', [
            'dashboardStats' => [
                'total_students' => $totalStudents,
                'program_day' => $programDay,
                'improving_rate' => $totalStudents > 0 ? (int) round(($improvingCount / $totalStudents) * 100) : 0,
                'improving_count' => $improvingCount,
                'avg_attendance' => $avgAttendance,
                'jhs_count' => $levelCounts['jhs'],
                'shs_count' => $levelCounts['shs'],
            ],
            'statusCounts' => $statusCounts,
            'progressCounts' => [
                'improving' => $improvingCount,
                'stable' => $stableCount,
                'regressing' => $regressingCount,
                'donut_style' => sprintf(
                    'conic-gradient(var(--teal) 0 %.1f%%, var(--blue) %.1f%% %.1f%%, var(--red) %.1f%% 100%%)',
                    $improvingPct,
                    $improvingPct,
                    $improvingPct + $stablePct,
                    $improvingPct + $stablePct
                ),
            ],
            'bmiChart' => $bmiChart,
            'weeklyBars' => $weeklyBars,
            'roster' => $this->buildRoster($students),
        ]);
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

    /**
     * Average BMI over the last six months, plotted against the WHO BMI-for-age
     * reference bands (underweight < 18.5, healthy 18.5-25, overweight > 25).
     * Every month also carries a click-through summary (headcount, average,
     * nutritional-status breakdown) and an outlier flag so a suspicious month
     * can be verified before reporting. All geometry is pre-computed here.
     */
    private function buildBmiChart(Collection $students): array
    {
        $left = 48.0;
        $right = 900.0;
        $top = 24.0;
        $bottom = 196.0;
        $underweight = 18.5;
        $overweight = 25.0;
        $statusOrder = ['Severely Wasted', 'Wasted', 'Underweight', 'Normal', 'Overweight'];

        $months = collect(range(5, 0))->map(fn (int $offset) => now()->copy()->subMonths($offset));
        $globalAverage = $students->isNotEmpty() ? (float) round((float) $students->avg('bmi_value'), 1) : 0.0;

        // Bucket learners by the month they were recorded; carry the last known
        // average forward so the line stays continuous through empty months.
        $carry = $globalAverage > 0 ? $globalAverage : 21.0;
        $monthsData = $months->map(function (Carbon $month) use ($students, $statusOrder, $underweight, $overweight, &$carry): array {
            $rows = $students->filter(
                fn ($row) => $row->created_at && Carbon::parse($row->created_at)->format('Y-m') === $month->format('Y-m')
            );

            $hasData = $rows->isNotEmpty();
            $avg = $hasData ? (float) round((float) $rows->avg('bmi_value'), 1) : null;
            if ($hasData) {
                $carry = $avg;
            }

            $statusCounts = [];
            foreach ($statusOrder as $label) {
                $count = $rows->filter(fn ($r) => $this->normalizeStatus((string) $r->nutritional_status) === $label)->count();
                if ($count > 0) {
                    $statusCounts[] = ['label' => $label, 'count' => $count];
                }
            }

            return [
                'label' => $month->format('M'),
                'full' => $month->format('F Y'),
                'value' => $hasData ? $avg : $carry,
                'has_data' => $hasData,
                'count' => $rows->count(),
                'avg_bmi' => $avg,
                'band' => $this->bmiBandLabel($hasData ? $avg : $carry, $underweight, $overweight),
                'status' => $statusCounts,
            ];
        })->values();

        // Domain always spans below 18.5 and above 25 so all three bands show.
        $dataValues = $monthsData->where('has_data', true)->pluck('value')->map(fn ($v) => (float) $v)->all();
        $min = min((count($dataValues) ? min($dataValues) : 18.5) - 1, 16.0);
        $max = max((count($dataValues) ? max($dataValues) : 22.0) + 1, 26.0);
        // Snap the ends to even BMI units so the axis reads 28, not 27.4, and
        // the 2-unit grid below lands on whole numbers.
        $min = floor($min / 2) * 2;
        $max = ceil($max / 2) * 2;
        $y = fn (float $v): float => round($bottom - ((max($min, min($max, $v)) - $min) / ($max - $min)) * ($bottom - $top), 1);

        // Outlier = month whose average is far from the median of the real
        // months (robust MAD test, with a 1.5-BMI floor so flat data is quiet).
        $median = $this->median($dataValues);
        $mad = $this->median(array_map(fn ($v) => abs($v - $median), $dataValues));
        $threshold = max(1.5, 3 * $mad);

        $xs = array_map(fn (int $i) => round($left + $i * ($right - $left) / 5, 1), range(0, 5));
        $outlierLabels = [];

        // Delta is measured against the previous month that actually has a
        // reading, so carried-forward months never report a fake "no change".
        $previousReading = null;
        $monthsData = $monthsData->map(function (array $m, int $i) use ($xs, $y, $median, $threshold, $dataValues, &$outlierLabels, &$previousReading): array {
            $isOutlier = $m['has_data'] && count($dataValues) >= 3 && abs((float) $m['value'] - $median) > $threshold;
            if ($isOutlier) {
                $outlierLabels[] = $m['label'];
            }

            $delta = ($m['has_data'] && $previousReading !== null)
                ? round((float) $m['avg_bmi'] - $previousReading, 1)
                : null;
            if ($m['has_data']) {
                $previousReading = (float) $m['avg_bmi'];
            }

            return $m + [
                'index' => $i,
                'x' => $xs[$i],
                'y' => $y((float) $m['value']),
                'is_outlier' => $isOutlier,
                'delta' => $delta,
            ];
        })->values();

        // The most recent reading is drawn a touch larger than the rest.
        $currentIndex = ($monthsData->where('has_data', true)->last()['index'] ?? null)
            ?? $monthsData->count() - 1;
        $monthsData = $monthsData->map(fn (array $m) => $m + ['is_current' => $m['index'] === $currentIndex])->values();

        $bands = [
            ['class' => 'over', 'label' => 'Overweight watch', 'top' => $y($max), 'floor' => $y($overweight)],
            ['class' => 'healthy', 'label' => 'Healthy range', 'top' => $y($overweight), 'floor' => $y($underweight)],
            ['class' => 'under', 'label' => 'Underweight watch', 'top' => $y($underweight), 'floor' => $y($min)],
        ];

        // Labels sit at the band edge furthest from where the curve enters the
        // chart, so the line never runs through the text. Without this the
        // "Underweight watch" label sat right under the 18.5 boundary — exactly
        // where a healthy-but-low average draws its line.
        $curveY = $monthsData->isEmpty() ? null : (float) $monthsData->first()['y'];
        $bands = array_map(function (array $b) use ($curveY): array {
            $height = round($b['floor'] - $b['top'], 1);
            $atTop = $b['top'] + 13;
            $atFloor = $b['floor'] - 7;

            $labelY = ($height >= 30 && $curveY !== null && abs($curveY - $atFloor) > abs($curveY - $atTop))
                ? $atFloor
                : $atTop;

            return [
                'class' => $b['class'],
                'label' => $b['label'],
                'y' => $b['top'],
                'height' => max(0, $height),
                'label_y' => round($labelY, 1),
            ];
        }, $bands);

        // Zone-keyed gradient. The line, its fill and every marker ring draw
        // their colour from this one vertical gradient, so a reading's colour
        // is its WHO band: rose above 25, cyan through the healthy range,
        // indigo below 18.5. Stops sit on the real boundaries (with a short
        // blend either side) so the colour turns over exactly at 18.5 / 25
        // rather than at some arbitrary point along the curve.
        $span = $bottom - $top;
        $offsetOf = fn (float $v): float => $span > 0 ? round(($y($v) - $top) / $span, 4) : 0.0;
        $blend = 0.035;
        $overOffset = $offsetOf($overweight);
        $underOffset = $offsetOf($underweight);

        $gradientStops = [
            ['offset' => 0.0, 'zone' => 'over'],
            ['offset' => max(0.0, round($overOffset - $blend, 4)), 'zone' => 'over'],
            ['offset' => min(1.0, round($overOffset + $blend, 4)), 'zone' => 'healthy'],
            ['offset' => max(0.0, round($underOffset - $blend, 4)), 'zone' => 'healthy'],
            ['offset' => min(1.0, round($underOffset + $blend, 4)), 'zone' => 'under'],
            ['offset' => 1.0, 'zone' => 'under'],
        ];

        // Light horizontal rules every 2 BMI units, so the eye can read a
        // height off the plot without the band tints having to carry it.
        $gridLines = [];
        for ($v = $min; $v <= $max; $v += 2) {
            $gridLines[] = $y((float) $v);
        }

        $line = $monthsData->map(fn (array $m) => $m['x'].','.$m['y'])->implode(' ');
        $points = $monthsData->map(fn (array $m) => [(float) $m['x'], (float) $m['y']])->all();
        $linePath = $this->smoothPath($points);
        $firstX = $points !== [] ? $points[0][0] : $left;
        $lastX = $points !== [] ? $points[count($points) - 1][0] : $right;
        $areaPath = $linePath === '' ? '' : $linePath.' L '.$lastX.' '.$bottom.' L '.$firstX.' '.$bottom.' Z';
        $outlierMonth = $monthsData->firstWhere('is_outlier', true);
        $lastDataMonth = $monthsData->where('has_data', true)->last();
        $defaultIndex = $outlierMonth ? $outlierMonth['index'] : ($lastDataMonth ? $lastDataMonth['index'] : 5);

        return [
            'plot' => ['left' => $left, 'right' => $right, 'top' => $top, 'bottom' => $bottom],
            'bands' => $bands,
            'y_ticks' => array_map(fn (float $v) => ['label' => rtrim(rtrim(number_format($v, 1), '0'), '.'), 'y' => $y($v)], [$max, $overweight, $underweight, $min]),
            // Dashed hairlines mark where a zone changes; the plain grid below
            // is the readable-height scale and stays recessive behind them.
            'zone_lines' => array_map(fn (float $v) => $y($v), [$overweight, $underweight]),
            'grid_lines' => $gridLines,
            'gradient_stops' => $gradientStops,
            'line' => $line,
            'line_path' => $linePath,
            'area' => $left.','.$bottom.' '.$line.' '.$right.','.$bottom,
            'area_path' => $areaPath,
            'months' => $monthsData->all(),
            'has_outlier' => $outlierLabels !== [],
            'outlier_label' => implode(' & ', $outlierLabels),
            'default_index' => $defaultIndex,
        ];
    }

    /**
     * Monotone cubic interpolation (Fritsch-Carlson) through the points,
     * rendered as SVG cubic beziers.
     *
     * Deliberately not Catmull-Rom / cardinal: those overshoot: a flat run of
     * months would bulge above or below its own values, inventing a dip or a
     * peak that the data never had. Fritsch-Carlson limits each tangent to the
     * neighbouring secants, so the curve only bends where the readings actually
     * change direction and stays perfectly flat where they do not.
     *
     * @param  list<array{0: float, 1: float}>  $points
     */
    private function smoothPath(array $points): string
    {
        $count = count($points);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return 'M '.$points[0][0].' '.$points[0][1];
        }

        // Secant slope of each segment.
        $secants = [];
        for ($i = 0; $i < $count - 1; $i++) {
            $dx = $points[$i + 1][0] - $points[$i][0];
            $secants[$i] = $dx == 0.0 ? 0.0 : ($points[$i + 1][1] - $points[$i][1]) / $dx;
        }

        // Tangent at each point: average of the two adjacent secants, forced to
        // zero at every local extremum so the curve cannot overshoot past it.
        $tangents = [$secants[0]];
        for ($i = 1; $i < $count - 1; $i++) {
            $prev = $secants[$i - 1];
            $next = $secants[$i];
            $tangents[$i] = ($prev * $next <= 0.0) ? 0.0 : ($prev + $next) / 2;
        }
        $tangents[$count - 1] = $secants[$count - 2];

        // Fritsch-Carlson limiter: keep each tangent within three times the
        // smaller neighbouring secant, which is the monotonicity guarantee.
        for ($i = 0; $i < $count - 1; $i++) {
            if ($secants[$i] == 0.0) {
                $tangents[$i] = 0.0;
                $tangents[$i + 1] = 0.0;

                continue;
            }

            $a = $tangents[$i] / $secants[$i];
            $b = $tangents[$i + 1] / $secants[$i];
            $s = $a * $a + $b * $b;
            if ($s > 9.0) {
                $t = 3.0 / sqrt($s);
                $tangents[$i] = $t * $a * $secants[$i];
                $tangents[$i + 1] = $t * $b * $secants[$i];
            }
        }

        $path = 'M '.$points[0][0].' '.$points[0][1];
        for ($i = 0; $i < $count - 1; $i++) {
            $dx = ($points[$i + 1][0] - $points[$i][0]) / 3;

            $path .= ' C '.round($points[$i][0] + $dx, 1).' '.round($points[$i][1] + $tangents[$i] * $dx, 1)
                .', '.round($points[$i + 1][0] - $dx, 1).' '.round($points[$i + 1][1] - $tangents[$i + 1] * $dx, 1)
                .', '.round($points[$i + 1][0], 1).' '.round($points[$i + 1][1], 1);
        }

        return $path;
    }

    private function bmiBandLabel(?float $value, float $underweight, float $overweight): string
    {
        if ($value === null) {
            return '';
        }
        if ($value >= $overweight) {
            return 'Overweight watch';
        }
        if ($value < $underweight) {
            return 'Underweight watch';
        }

        return 'Healthy range';
    }

    /** @param  list<float>  $numbers */
    private function median(array $numbers): float
    {
        $count = count($numbers);
        if ($count === 0) {
            return 0.0;
        }
        sort($numbers);
        $mid = intdiv($count, 2);

        return $count % 2 === 1 ? (float) $numbers[$mid] : (float) (($numbers[$mid - 1] + $numbers[$mid]) / 2);
    }

    private function buildWeeklyBars(int $totalStudents, ?int $institutionId = null): array
    {
        $hasConsultationTable = Schema::hasTable('consultations');

        return collect(range(4, 0))
            ->map(function (int $offset) use ($hasConsultationTable, $totalStudents, $institutionId): array {
                $weekStart = now()->copy()->startOfWeek()->subWeeks($offset);
                $weekEnd = $weekStart->copy()->endOfWeek();

                $present = 0;
                if ($hasConsultationTable) {
                    // student_name is encrypted at rest — count distinct students in PHP.
                    $present = Consultation::query()
                        ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
                        ->whereBetween('consulted_at', [$weekStart, $weekEnd])
                        ->pluck('student_name')
                        ->unique()
                        ->count();
                }

                if (! $hasConsultationTable || $present === 0) {
                    $present = $totalStudents;
                }

                $present = min($totalStudents, $present);
                $missed = max(0, $totalStudents - $present);

                $base = max(1, $totalStudents);

                return [
                    'label' => 'Week '.(5 - $offset),
                    'present' => $present,
                    'missed' => $missed,
                    'present_height' => (int) max(8, round(($present / $base) * 136)),
                    'missed_height' => (int) max(0, round(($missed / $base) * 30)),
                ];
            })
            ->values()
            ->all();
    }
}
