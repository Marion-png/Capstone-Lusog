<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\StudentHealthRecord;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        ]);
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
