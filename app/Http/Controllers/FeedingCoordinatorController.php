<?php

namespace App\Http\Controllers;

use App\Models\AttendanceImport;
use App\Models\Consultation;
use App\Models\FeedingAttendance;
use App\Models\FeedingReportDetail;
use App\Models\StudentHealthRecord;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FeedingCoordinatorController extends Controller
{
    /**
     * SBFP report *metadata* only — the narrative, milk consignee contacts and
     * signatory blocks that genuinely cannot be computed from data. The report
     * figures themselves live on the read-only Reports screen. Persisted
     * server-side (not localStorage) so they survive and stay per-school.
     */
    public function sbfpForms(Request $request): View
    {
        $institutionId = $request->session()->get('active_institution_id');
        $schoolYear = StudentHealthRecord::currentSchoolYear();

        $details = FeedingReportDetail::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->where('school_year', $schoolYear)
            ->first();

        return view('feedingcor-dashboard.sbfp-forms', [
            'schoolYear' => $schoolYear,
            'narrative' => (array) ($details?->narrative ?? []),
            'consignees' => (array) ($details?->consignees ?? []),
            'signatories' => (array) ($details?->signatories ?? []),
        ]);
    }

    /** Save the manual SBFP report metadata for the current period. */
    public function saveReportDetails(Request $request): RedirectResponse
    {
        $role = strtolower(trim((string) $request->session()->get('active_role', '')));
        if ($role !== 'feeding_coor') {
            return redirect()->route('login')->with('error', 'Only the Feeding Coordinator can edit report details.');
        }

        $validated = $request->validate([
            'narrative' => ['nullable', 'array'],
            'narrative.*' => ['nullable', 'string', 'max:5000'],
            'consignees' => ['nullable', 'array'],
            'consignees.*' => ['nullable', 'array'],
            'consignees.*.*' => ['nullable', 'string', 'max:255'],
            'signatories' => ['nullable', 'array'],
            'signatories.*' => ['nullable', 'string', 'max:255'],
        ]);

        $consignees = array_values(array_filter(
            $validated['consignees'] ?? [],
            fn ($row) => is_array($row) && trim(implode('', array_map('strval', $row))) !== ''
        ));

        FeedingReportDetail::updateOrCreate(
            [
                'institution_id' => $request->session()->get('active_institution_id'),
                'school_year' => StudentHealthRecord::currentSchoolYear(),
            ],
            [
                'narrative' => $validated['narrative'] ?? [],
                'consignees' => $consignees,
                'signatories' => $validated['signatories'] ?? [],
            ]
        );

        return back()->with('success', 'Report details saved.');
    }

    /** Baseline measurement entry — one form, separate from endline. */
    public function baselineForm(Request $request): View
    {
        return view('feedingcor-dashboard.baseline', [
            'studentsByGrade' => $this->studentsForEntry($request->session()->get('active_institution_id')),
        ]);
    }

    /** Endline measurement entry — only openable per student once a baseline exists. */
    public function endlineForm(Request $request): View
    {
        return view('feedingcor-dashboard.endline', [
            'studentsByGrade' => $this->studentsForEntry($request->session()->get('active_institution_id')),
        ]);
    }

    /**
     * Read-only, auto-computed SBFP reports. Nothing here is hand-keyed —
     * every figure is recomputed from stored attendance + student records on
     * each load. Attendance for the period gates report generation; incomplete
     * baseline/endline data surfaces as "what's missing", never as zeros.
     */
    public function reports(Request $request): View
    {
        $institutionId = $request->session()->get('active_institution_id');
        $schoolYears = $this->schoolYearOptions($institutionId);
        $selectedYear = trim((string) $request->query('year', ''));
        if ($selectedYear === '' || ! $schoolYears->contains($selectedYear)) {
            $selectedYear = StudentHealthRecord::currentSchoolYear();
        }

        $attendanceUploaded = AttendanceImport::existsForPeriod($institutionId, $selectedYear);
        $lastImport = AttendanceImport::latestForPeriod($institutionId, $selectedYear);

        // Attendance-first gate: no report data is computed until attendance
        // has been uploaded for the period.
        $report = $attendanceUploaded ? $this->computeReport($institutionId, $selectedYear) : null;

        return view('feedingcor-dashboard.reports', [
            'schoolYears' => $schoolYears,
            'selectedYear' => $selectedYear,
            'attendanceUploaded' => $attendanceUploaded,
            'lastImport' => $lastImport,
            'atRiskThreshold' => 75,
            'report' => $report,
        ]);
    }

    /** CSV export of the computed masterlist (qualified + at-risk + attendance). */
    public function reportsExport(Request $request): StreamedResponse|RedirectResponse
    {
        $institutionId = $request->session()->get('active_institution_id');
        $selectedYear = trim((string) $request->query('year', '')) ?: StudentHealthRecord::currentSchoolYear();

        if (! AttendanceImport::existsForPeriod($institutionId, $selectedYear)) {
            return redirect()
                ->route('dashboard.feedingcor-reports', ['year' => $selectedYear])
                ->with('error', 'Upload attendance for SY '.$selectedYear.' before exporting reports.');
        }

        $report = $this->computeReport($institutionId, $selectedYear);
        $header = ['No.', 'Name', 'Grade', 'Section', 'Nutritional Status', 'Attendance %', 'At-Risk', 'Baseline BMI', 'Baseline Status', 'Endline BMI', 'Endline Status'];

        $filename = 'feeding-report-'.str_replace(' ', '', $selectedYear).'.csv';

        return response()->streamDownload(function () use ($header, $report): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            $i = 1;
            foreach ($report['qualified'] as $row) {
                fputcsv($out, [
                    $i++,
                    $row['name'],
                    $row['grade'],
                    $row['section'],
                    $row['status'],
                    $row['attendance_pct'] === null ? '' : $row['attendance_pct'].'%',
                    $row['is_at_risk'] ? 'YES' : '',
                    $row['baseline_bmi'] ?? '',
                    $row['baseline_status'] ?? '',
                    $row['endline_bmi'] ?? '',
                    $row['endline_status'] ?? '',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The institution's current-year students grouped by grade, each carrying
     * its baseline / endline state so the split forms can pre-fill and gate.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function studentsForEntry(?int $institutionId): array
    {
        if (! Schema::hasTable('student_health_records')) {
            return [];
        }

        $query = StudentHealthRecord::query();
        if ($institutionId) {
            $query->where('institution_id', $institutionId);
        }
        $records = $query->forCurrentSchoolYear()->get();

        $byGrade = [];
        foreach ($records as $record) {
            [$grade, $section] = $this->splitSection((string) $record->section);

            $byGrade[$grade][] = [
                'id' => $record->id,
                'student_id' => (string) $record->student_id,
                'name' => (string) $record->student_name,
                'grade' => $grade,
                'section' => $section,
                'section_raw' => (string) $record->section,
                'has_baseline' => $record->baseline_bmi_value !== null,
                'baseline' => [
                    'age' => $record->baseline_age,
                    'height_cm' => $record->baseline_height_cm,
                    'weight_kg' => $record->baseline_weight_kg,
                    'bmi' => $record->baseline_bmi_value,
                    'status' => $record->baseline_nutritional_status,
                    'recorded_at' => optional($record->baseline_recorded_at)->toDateString(),
                ],
                'endline' => [
                    'age' => $record->endline_age,
                    'height_cm' => $record->endline_height_cm,
                    'weight_kg' => $record->endline_weight_kg,
                    'bmi' => $record->endline_bmi_value,
                    'status' => $record->endline_nutritional_status,
                    'recorded_at' => optional($record->endline_recorded_at)->toDateString(),
                ],
            ];
        }

        uksort($byGrade, fn (string $a, string $b): int => strnatcasecmp($a, $b));
        foreach ($byGrade as $grade => $rows) {
            usort($rows, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
            $byGrade[$grade] = $rows;
        }

        return $byGrade;
    }

    /**
     * Recomputes every SBFP report figure for a period from stored data.
     *
     * @return array<string, mixed>
     */
    private function computeReport(?int $institutionId, string $schoolYear): array
    {
        $records = collect();
        if (Schema::hasTable('student_health_records')) {
            $query = StudentHealthRecord::query();
            if ($institutionId) {
                $query->where('institution_id', $institutionId);
            }
            $records = $query->forCurrentSchoolYear($schoolYear)->get();
        }

        // Denominator for attendance % = distinct feeding-session dates on file.
        $totalSessions = 0;
        if (Schema::hasTable('feeding_attendances') && $records->isNotEmpty()) {
            $totalSessions = FeedingAttendance::query()
                ->whereIn('student_health_record_id', $records->pluck('id'))
                ->distinct()
                ->count('session_date');
        }

        $rows = $records->map(function (StudentHealthRecord $record) use ($totalSessions): array {
            [$grade, $section] = $this->splitSection((string) $record->section);
            $status = $this->normalizeStatus((string) $record->nutritional_status);
            $present = (int) ($record->attendance_sessions_count ?? 0);

            return [
                'name' => (string) $record->student_name,
                'grade' => $grade,
                'section' => $section,
                'status' => $status,
                'qualified' => $this->isQualifiedForFeeding($status),
                'present' => $present,
                'attendance_pct' => $totalSessions > 0 ? (int) round($present / $totalSessions * 100) : null,
                'is_at_risk' => (bool) $record->is_at_risk,
                'baseline_bmi' => $record->baseline_bmi_value,
                'baseline_status' => $record->baseline_nutritional_status ?: $status,
                'endline_bmi' => $record->endline_bmi_value,
                'endline_status' => $record->endline_nutritional_status,
                'has_baseline' => $record->baseline_bmi_value !== null,
                'has_endline' => $record->endline_bmi_value !== null,
            ];
        })->sort(fn (array $a, array $b): int => strnatcasecmp($a['grade'], $b['grade']) ?: strcasecmp($a['name'], $b['name']))->values();

        $qualified = $rows->where('qualified', true)->values();

        $gradeSummary = $rows->groupBy('grade')->map(function (Collection $group, string $grade): array {
            $countStatus = fn (string $needle): int => $group->filter(fn (array $x): bool => strtolower($x['status']) === $needle)->count();

            return [
                'grade' => $grade,
                'total' => $group->count(),
                'severely_wasted' => $countStatus('severely wasted'),
                'wasted' => $countStatus('wasted'),
                'underweight' => $countStatus('underweight'),
                'normal' => $countStatus('normal'),
                'overweight' => $countStatus('overweight'),
                'at_risk' => $group->where('is_at_risk', true)->count(),
            ];
        })->sortKeys(SORT_NATURAL | SORT_FLAG_CASE)->values();

        $comparison = $rows->where('has_baseline', true)->map(function (array $x): array {
            $x['delta'] = ($x['has_endline'] && $x['baseline_bmi'] !== null && $x['endline_bmi'] !== null)
                ? round((float) $x['endline_bmi'] - (float) $x['baseline_bmi'], 2)
                : null;

            return $x;
        })->values();

        return [
            'qualified' => $qualified,
            'atRisk' => $qualified->where('is_at_risk', true)->values(),
            'gradeSummary' => $gradeSummary,
            'comparison' => $comparison,
            'totalSessions' => $totalSessions,
            'totalStudents' => $rows->count(),
            'qualifiedCount' => $qualified->count(),
            'missingBaseline' => $qualified->where('has_baseline', false)->values(),
            'missingEndline' => $qualified->where('has_baseline', true)->where('has_endline', false)->values(),
        ];
    }

    /**
     * Distinct school years on file (period filter), newest first, always
     * including the current year.
     *
     * @return Collection<int, string>
     */
    private function schoolYearOptions(?int $institutionId): Collection
    {
        $years = collect();
        if (Schema::hasTable('student_health_records') && Schema::hasColumn('student_health_records', 'school_year')) {
            $years = StudentHealthRecord::query()
                ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
                ->select('school_year')
                ->whereNotNull('school_year')
                ->where('school_year', '!=', '')
                ->distinct()
                ->pluck('school_year');
        }

        return $years->push(StudentHealthRecord::currentSchoolYear())
            ->unique()
            ->sortDesc()
            ->values();
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
        ]);
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

    private function buildBmiChart(Collection $students): array
    {
        $months = collect(range(5, 0))
            ->map(fn (int $offset) => now()->copy()->subMonths($offset));

        $globalAverage = $students->isNotEmpty()
            ? (float) round((float) $students->avg('bmi_value'), 1)
            : 0.0;

        $values = $months->map(function (Carbon $month) use ($students, $globalAverage): float {
            $monthRows = $students->filter(function ($row) use ($month): bool {
                if (! $row->created_at) {
                    return false;
                }

                return Carbon::parse($row->created_at)->format('Y-m') === $month->format('Y-m');
            });

            if ($monthRows->isEmpty()) {
                return $globalAverage;
            }

            return (float) round((float) $monthRows->avg('bmi_value'), 1);
        })->values();

        $minValue = (float) $values->min();
        $maxValue = (float) $values->max();
        if ($maxValue === $minValue) {
            $maxValue += 1;
        }

        $xPoints = [48, 138, 228, 318, 408, 500];
        $yTop = 62;
        $yBottom = 175;

        $points = $values->map(function (float $value, int $index) use ($xPoints, $minValue, $maxValue, $yTop, $yBottom): array {
            $ratio = ($value - $minValue) / ($maxValue - $minValue);
            $y = $yBottom - ($ratio * ($yBottom - $yTop));

            return [
                'x' => $xPoints[$index],
                'y' => round($y, 1),
                'value' => $value,
            ];
        })->values()->all();

        return [
            'month_labels' => $months->map(fn (Carbon $month) => $month->format('M'))->values()->all(),
            'points' => $points,
            'y_ticks' => [
                round($maxValue, 1),
                round(($maxValue + $minValue) / 2, 1),
                round($minValue, 1),
            ],
        ];
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
