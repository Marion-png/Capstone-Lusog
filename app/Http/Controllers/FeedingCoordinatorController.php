<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\StudentHealthRecord;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class FeedingCoordinatorController extends Controller
{
    public function dashboard(): View
    {
        $students = collect();
        if (Schema::hasTable('student_health_records')) {
            $students = StudentHealthRecord::query()->get();
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
        $weeklyBars = $this->buildWeeklyBars($totalStudents);
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
                if (!$row->created_at) {
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

    private function buildWeeklyBars(int $totalStudents): array
    {
        $hasConsultationTable = Schema::hasTable('consultations');

        return collect(range(4, 0))
            ->map(function (int $offset) use ($hasConsultationTable, $totalStudents): array {
                $weekStart = now()->copy()->startOfWeek()->subWeeks($offset);
                $weekEnd = $weekStart->copy()->endOfWeek();

                $present = 0;
                if ($hasConsultationTable) {
                    $present = Consultation::query()
                        ->whereBetween('consulted_at', [$weekStart, $weekEnd])
                        ->distinct('student_name')
                        ->count('student_name');
                }

                if (!$hasConsultationTable || $present === 0) {
                    $present = $totalStudents;
                }

                $present = min($totalStudents, $present);
                $missed = max(0, $totalStudents - $present);

                $base = max(1, $totalStudents);
                return [
                    'label' => 'Week ' . (5 - $offset),
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
