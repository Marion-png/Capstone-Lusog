<?php

namespace App\Http\Controllers;

use App\Models\FeedingAttendance;
use App\Models\StudentHealthRecord;
use App\Support\FeedingAttendanceMark;
use App\Support\FeedingBeneficiarySummary;
use App\Support\NutritionalHealthStatus;
use App\Support\SchemaCache;
use App\Support\SchoolLetterhead;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class NutritionCoordinatorController extends Controller
{
    /**
     * The DepEd scale has no Underweight column, and the classifier no longer
     * emits one: a record saved under that retired label is counted as Wasted
     * here exactly as it is on every other screen, so the school is not told
     * about two groups of children where there is one.
     */
    private const STATUS_LABELS = [
        'severely_wasted' => 'Severely Wasted',
        'wasted' => 'Wasted',
        'normal' => 'Normal',
        'overweight' => 'Overweight',
    ];

    /** The documents the Report Center prints, by the key in their URL. */
    public const REPORTS = [
        'annex-a' => 'Annex A: Master List of Beneficiaries',
        'annex-b' => 'Annex B: Summary by Grade Level and Nutritional Status',
        'terminal' => 'Terminal Report',
        'monthly' => 'Monthly Report',
        'analytics' => 'Analytics Report',
        'at-risk' => 'At-Risk Report',
    ];

    public function dashboard(): View
    {
        $this->guard();

        $records = $this->records();
        $summary = $this->summary($records);

        return view('nutricor.nutricor-dashboard', [
            'records' => $records,
            'summary' => $summary,
            'priorityOne' => $this->priorityRows($records, ['severely_wasted']),
            'priorityTwo' => $this->priorityRows($records, ['wasted']),
        ]);
    }

    public function beneficiaries(): View
    {
        $this->guard();

        $records = $this->records();

        return view('nutricor.beneficiaries', [
            'records' => $records,
        ]);
    }

    public function analytics(): View
    {
        $this->guard();

        $records = $this->records();

        return view('nutricor.analytics', [
            'summary' => $this->summary($records),
            'sectionSummary' => $this->sectionSummary($records),
        ]);
    }

    public function atRisk(): View
    {
        $this->guard();

        $records = $this->records();
        $riskRows = $this->riskRows($records);

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
        $this->guard();

        $records = $this->records();

        return view('nutricor.reports', [
            'summary' => $this->summary($records),
            'sectionSummary' => $this->sectionSummary($records),
            'reportRows' => $this->comparisonRows($records),
        ]);
    }

    public function comparison(): View
    {
        $this->guard();

        $records = $this->records();

        return view('nutricor.comparison', [
            'summary' => $this->summary($records),
            'reportRows' => $this->comparisonRows($records),
        ]);
    }

    /**
     * One document from the Report Center, laid out for paper. As everywhere else
     * in this app, a PDF is the browser's print dialog saving the page.
     */
    public function printReport(string $report): View
    {
        $this->guard();

        abort_unless(array_key_exists($report, self::REPORTS), 404);

        $records = $this->records();
        $institutionId = session('active_institution_id');

        return view('nutricor.report-print', [
            'report' => $report,
            'title' => self::REPORTS[$report],
            'schoolYear' => StudentHealthRecord::currentSchoolYear(),
            'letterhead' => SchoolLetterhead::for($institutionId ? (int) $institutionId : null, (string) session('active_school_name', '')),
            'seals' => SchoolLetterhead::seals(),
            'labels' => self::STATUS_LABELS,
            'summary' => $this->summary($records),
            'data' => match ($report) {
                'annex-a' => $this->beneficiaryRows($records),
                'annex-b' => $this->gradeSummary($records),
                'terminal' => $this->comparisonRows($records),
                'monthly' => $this->monthlyRows($records),
                'analytics' => $this->sectionSummary($records),
                'at-risk' => $this->riskRows($records),
            },
        ]);
    }

    /** Every page here is the Nutrition Coordinator's, and names the whole school's learners. */
    private function guard(): void
    {
        abort_unless(session('active_role') === 'nutricor', 403);
    }

    private function records(): Collection
    {
        $institutionId = session('active_institution_id');

        // No school means no learners, never every school's.
        if (! $institutionId || ! SchemaCache::hasTable('student_health_records')) {
            return collect();
        }

        // student_name is encrypted at rest, so the final sort happens in PHP.
        return StudentHealthRecord::query()
            ->where('institution_id', $institutionId)
            ->forCurrentSchoolYear()
            ->get()
            ->sortBy([
                ['school_name', 'asc'],
                ['section', 'asc'],
                ['student_name', 'asc'],
            ])
            ->values();
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

        $trackedTotal = $trackedRows->count();

        return [
            'total_population' => $records->count(),
            'baseline_total' => $records->filter(fn (StudentHealthRecord $record): bool => $this->hasBaseline($record))->count(),
            'endline_total' => $records->filter(fn (StudentHealthRecord $record): bool => $this->hasEndline($record))->count(),
            'baseline_counts' => $baselineCounts,
            'endline_counts' => $endlineCounts,
            'priority_1' => $baselineCounts['severely_wasted'],
            'priority_2' => $baselineCounts['wasted'],
            'at_risk' => $baselineCounts['severely_wasted'] + $baselineCounts['wasted'],
            'tracked_total' => $trackedRows->count(),
            // Nobody measured twice is no rate at all, never 0%.
            'improvement_rate' => $trackedTotal > 0 ? round(($movement['improved'] / $trackedTotal) * 100, 1) : null,
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

    private function riskRows(Collection $records): Collection
    {
        return $records
            ->map(fn (StudentHealthRecord $record): array => $this->riskRow($record))
            ->filter(fn (array $row): bool => $row['risk'] !== 'Low')
            ->sortBy(fn (array $row): int => ['High' => 0, 'Medium' => 1][$row['risk']] ?? 2)
            ->values();
    }

    /** Annex A: the enrolled roll with each learner's baseline reading. */
    private function beneficiaryRows(Collection $records): Collection
    {
        return $records
            ->filter(fn (StudentHealthRecord $record): bool => FeedingBeneficiarySummary::isBeneficiary($record))
            ->map(function (StudentHealthRecord $record): array {
                $baseline = NutritionalHealthStatus::forRecord($record)['baseline'];

                return [
                    'name' => (string) $record->student_name,
                    'section' => (string) $record->section,
                    'sex' => FeedingBeneficiarySummary::sexOf($record),
                    'height_cm' => $baseline['height_cm'],
                    'weight_kg' => $baseline['weight_kg'],
                    'bmi' => $baseline['bmi'],
                    'bmi_status' => $baseline['bmi_status'],
                    'hfa_status' => $baseline['hfa_status'],
                ];
            })
            ->values();
    }

    /** Annex B: each grade's learners by status, at both weighings. */
    private function gradeSummary(Collection $records): Collection
    {
        return $records
            ->groupBy(fn (StudentHealthRecord $record): string => trim(explode(' / ', (string) $record->section)[0]) ?: 'Unassigned')
            ->sortKeys(SORT_NATURAL)
            ->map(fn (Collection $rows, string $grade): array => [
                'grade' => $grade,
                'total' => $rows->count(),
                'baseline' => $this->statusCounts($rows, 'baseline'),
                'endline' => $this->statusCounts($rows, 'endline'),
            ])
            ->values();
    }

    /**
     * The Monthly Report: weighings taken and feeding sessions held, month by
     * month. A confirmed mark is the only kind counted, so the rate is empty for
     * a month with no confirmed attendance rather than 0%.
     */
    private function monthlyRows(Collection $records): Collection
    {
        $months = [];
        $month = function (string $key) use (&$months): void {
            $months[$key] ??= ['baseline' => 0, 'endline' => 0, 'sessions' => [], 'present' => 0, 'confirmed' => 0];
        };

        foreach ($records as $record) {
            foreach (['baseline' => $record->baseline_recorded_at, 'endline' => $record->endline_recorded_at] as $phase => $date) {
                if ($date !== null) {
                    $key = Carbon::parse($date)->format('Y-m');
                    $month($key);
                    $months[$key][$phase]++;
                }
            }
        }

        $marks = $records->isEmpty() ? collect() : FeedingAttendance::query()
            ->whereIn('student_health_record_id', $records->pluck('id'))
            ->get(['session_date', 'is_present', 'is_excused', 'needs_review']);

        foreach ($marks as $mark) {
            $date = Carbon::parse($mark->session_date);
            $key = $date->format('Y-m');
            $month($key);
            $months[$key]['sessions'][$date->toDateString()] = true;

            $reading = FeedingAttendanceMark::forRule($mark);
            if ($reading !== null) {
                $months[$key]['confirmed']++;
                $months[$key]['present'] += $reading ? 1 : 0;
            }
        }

        ksort($months);

        return collect($months)->map(fn (array $row, string $key): array => [
            'month' => Carbon::createFromFormat('Y-m-d', $key.'-01')->format('F Y'),
            'baseline' => $row['baseline'],
            'endline' => $row['endline'],
            'sessions' => count($row['sessions']),
            'rate' => $row['confirmed'] > 0 ? round($row['present'] / $row['confirmed'] * 100, 1) : null,
        ])->values();
    }

    private function riskRow(StudentHealthRecord $record): array
    {
        $status = $this->statusKey((string) ($record->endline_nutritional_status ?: $record->baseline_nutritional_status ?: $record->nutritional_status));
        // Nutritional risk only. Whether a learner is flagged for feeding
        // follow-up is the Feeding Coordinator's and the School Nurse's to see
        // (FeedingAttendanceFlag::VISIBLE_TO), so it never colours this label.
        $risk = match ($status) {
            'severely_wasted' => 'High',
            'wasted' => 'Medium',
            default => 'Low',
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
            if ($period === 'baseline' && ! $this->hasBaseline($record)) {
                continue;
            }
            if ($period === 'endline' && ! $this->hasEndline($record)) {
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
        // The retired label reads as what it always meant here.
        if (str_contains($normalized, 'underweight')) {
            return 'wasted';
        }
        if (str_contains($normalized, 'wast')) {
            return 'wasted';
        }
        // Obese (above +2 SD on the WHO 2007 reference) has no column of its
        // own in these four-column reports, so it is counted with Overweight —
        // as NutricorController counts it — and never falls through to Normal.
        if (str_contains($normalized, 'over') || str_contains($normalized, 'obes')) {
            return 'overweight';
        }

        return 'normal';
    }

    private function statusRank(string $key): int
    {
        return [
            'severely_wasted' => 0,
            'wasted' => 1,
            'overweight' => 3,
            'normal' => 4,
        ][$key] ?? 4;
    }
}
