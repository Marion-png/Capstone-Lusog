<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class NutritionCoordinatorController extends Controller
{
    private const STATUS_LABELS = [
        'severely_wasted' => 'Severely Wasted',
        'wasted' => 'Wasted',
        'underweight' => 'Underweight',
        'normal' => 'Normal',
        'overweight' => 'Overweight',
    ];

    public function dashboard(): View
    {
        $records = $this->records();
        $summary = $this->summary($records);

        return view('nutricor.nutricor-dashboard', [
            'records' => $records,
            'summary' => $summary,
            'priorityOne' => $this->priorityRows($records, ['severely_wasted']),
            'priorityTwo' => $this->priorityRows($records, ['wasted', 'underweight']),
        ]);
    }

    public function beneficiaries(): View
    {
        $records = $this->records();

        return view('nutricor.beneficiaries', [
            'records' => $records,
        ]);
    }

    public function analytics(): View
    {
        $records = $this->records();

        return view('nutricor.analytics', [
            'summary' => $this->summary($records),
            'sectionSummary' => $this->sectionSummary($records),
        ]);
    }

    public function atRisk(): View
    {
        $records = $this->records();
        $riskRows = $records
            ->map(fn (StudentHealthRecord $record): array => $this->riskRow($record))
            ->filter(fn (array $row): bool => $row['risk'] !== 'Low')
            ->sortBy(fn (array $row): int => ['High' => 0, 'Medium' => 1][$row['risk']] ?? 2)
            ->values();

        return view('nutricor.atrisk', [
            'riskRows' => $riskRows,
            'riskCounts' => [
                'high' => $riskRows->where('risk', 'High')->count(),
                'medium' => $riskRows->where('risk', 'Medium')->count(),
                'low' => max(0, $records->count() - $riskRows->count()),
            ],
        ]);
    }

    public function reports(): View
    {
        $records = $this->records();

        return view('nutricor.reports', [
            'summary' => $this->summary($records),
            'sectionSummary' => $this->sectionSummary($records),
            'reportRows' => $this->comparisonRows($records),
        ]);
    }

    public function comparison(): View
    {
        $records = $this->records();

        return view('nutricor.comparison', [
            'summary' => $this->summary($records),
            'reportRows' => $this->comparisonRows($records),
        ]);
    }

    private function records(): Collection
    {
        if (!Schema::hasTable('student_health_records')) {
            return collect();
        }

        return StudentHealthRecord::query()
            ->orderBy('school_name')
            ->orderBy('section')
            ->orderBy('student_name')
            ->get();
    }

    private function summary(Collection $records): array
    {
        $baselineCounts = $this->statusCounts($records, 'baseline');
        $endlineCounts = $this->statusCounts($records, 'endline');
        $trackedRows = $records->filter(fn (StudentHealthRecord $record): bool => $this->hasBaseline($record) && $this->hasEndline($record));

        $movement = [
            'improved' => 0,
            'regressed' => 0,
            'no_change' => 0,
        ];

        foreach ($trackedRows as $record) {
            $baselineRank = $this->statusRank($this->statusKey((string) ($record->baseline_nutritional_status ?: $record->nutritional_status)));
            $endlineRank = $this->statusRank($this->statusKey((string) $record->endline_nutritional_status));

            if ($endlineRank > $baselineRank) {
                $movement['improved']++;
            } elseif ($endlineRank < $baselineRank) {
                $movement['regressed']++;
            } else {
                $movement['no_change']++;
            }
        }

        $trackedTotal = max(1, $trackedRows->count());

        return [
            'total_population' => $records->count(),
            'baseline_total' => $records->filter(fn (StudentHealthRecord $record): bool => $this->hasBaseline($record))->count(),
            'endline_total' => $records->filter(fn (StudentHealthRecord $record): bool => $this->hasEndline($record))->count(),
            'baseline_counts' => $baselineCounts,
            'endline_counts' => $endlineCounts,
            'priority_1' => $baselineCounts['severely_wasted'],
            'priority_2' => $baselineCounts['wasted'] + $baselineCounts['underweight'],
            'at_risk' => $baselineCounts['severely_wasted'] + $baselineCounts['wasted'] + $baselineCounts['underweight'],
            'tracked_total' => $trackedRows->count(),
            'improvement_rate' => round(($movement['improved'] / $trackedTotal) * 100, 1),
            ...$movement,
        ];
    }

    private function comparisonRows(Collection $records): Collection
    {
        $baselineCounts = $this->statusCounts($records, 'baseline');
        $endlineCounts = $this->statusCounts($records, 'endline');

        return collect(self::STATUS_LABELS)->map(function (string $label, string $key) use ($baselineCounts, $endlineCounts): array {
            $baseline = $baselineCounts[$key] ?? 0;
            $endline = $endlineCounts[$key] ?? 0;
            $change = $endline - $baseline;

            return [
                'key' => $key,
                'label' => $label,
                'baseline' => $baseline,
                'endline' => $endline,
                'change' => $change,
                'percent_change' => $baseline > 0 ? round(($change / $baseline) * 100, 1) : null,
            ];
        })->values();
    }

    private function sectionSummary(Collection $records): Collection
    {
        return $records
            ->groupBy(fn (StudentHealthRecord $record): string => (string) ($record->section ?: 'Unassigned'))
            ->map(function (Collection $rows, string $section): array {
                return [
                    'section' => $section,
                    'total' => $rows->count(),
                    'baseline' => $this->statusCounts($rows, 'baseline'),
                    'endline' => $this->statusCounts($rows, 'endline'),
                ];
            })
            ->values();
    }

    private function priorityRows(Collection $records, array $keys): Collection
    {
        return $records
            ->filter(fn (StudentHealthRecord $record): bool => in_array($this->statusKey((string) ($record->baseline_nutritional_status ?: $record->nutritional_status)), $keys, true))
            ->take(8)
            ->values();
    }

    private function riskRow(StudentHealthRecord $record): array
    {
        $status = $this->statusKey((string) ($record->endline_nutritional_status ?: $record->baseline_nutritional_status ?: $record->nutritional_status));
        $risk = match ($status) {
            'severely_wasted' => 'High',
            'wasted', 'underweight' => 'Medium',
            default => $record->is_at_risk ? 'Medium' : 'Low',
        };

        return [
            'risk' => $risk,
            'student_name' => $record->student_name,
            'section' => $record->section,
            'bmi' => $record->endline_bmi_value ?: $record->baseline_bmi_value ?: $record->bmi_value,
            'status' => self::STATUS_LABELS[$status] ?? ($record->nutritional_status ?: 'Not classified'),
            'indicators' => $risk === 'High' ? 'Severe wasting' : 'Low BMI or at-risk flag',
            'action' => $risk === 'High' ? 'Immediate referral and weekly nutrition check' : 'Biweekly follow-up and diet coaching',
        ];
    }

    private function statusCounts(Collection $records, string $period): array
    {
        $counts = array_fill_keys(array_keys(self::STATUS_LABELS), 0);

        foreach ($records as $record) {
            if ($period === 'baseline' && !$this->hasBaseline($record)) {
                continue;
            }
            if ($period === 'endline' && !$this->hasEndline($record)) {
                continue;
            }

            $status = $period === 'endline'
                ? (string) $record->endline_nutritional_status
                : (string) ($record->baseline_nutritional_status ?: $record->nutritional_status);

            $counts[$this->statusKey($status)]++;
        }

        return $counts;
    }

    private function hasBaseline(StudentHealthRecord $record): bool
    {
        return $record->baseline_bmi_value !== null || $record->baseline_nutritional_status !== null;
    }

    private function hasEndline(StudentHealthRecord $record): bool
    {
        return $record->endline_bmi_value !== null || $record->endline_nutritional_status !== null;
    }

    private function statusKey(string $status): string
    {
        $normalized = strtolower($status);

        if (str_contains($normalized, 'severe')) {
            return 'severely_wasted';
        }
        if (str_contains($normalized, 'underweight')) {
            return 'underweight';
        }
        if (str_contains($normalized, 'wast')) {
            return 'wasted';
        }
        if (str_contains($normalized, 'over')) {
            return 'overweight';
        }

        return 'normal';
    }

    private function statusRank(string $key): int
    {
        return [
            'severely_wasted' => 0,
            'wasted' => 1,
            'underweight' => 2,
            'overweight' => 3,
            'normal' => 4,
        ][$key] ?? 4;
    }
}
