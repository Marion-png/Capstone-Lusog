<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class StudentHealthRecordController extends Controller
{
    public function classAdviserDashboard(): View
    {
        $records = collect();

        if (Schema::hasTable('student_health_records')) {
            $records = StudentHealthRecord::query()
                ->orderByDesc('updated_at')
                ->get();
        }

        $todayCount = $records
            ->filter(fn (StudentHealthRecord $record) => optional($record->updated_at)?->isToday())
            ->count();

        $avgBmi = $records->avg('bmi_value') ?: 0;

        $flaggedCount = $records
            ->filter(function (StudentHealthRecord $record): bool {
                $status = strtolower((string) $record->nutritional_status);
                return str_contains($status, 'wast');
            })
            ->count();

        return view('adviser-dashboard.class-adviser', [
            'records' => $records,
            'stats' => [
                'encoded_today' => $todayCount,
                'avg_bmi' => number_format((float) $avgBmi, 1),
                'flagged' => $flaggedCount,
            ],
        ]);
    }

    public function storeBaseline(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'student_name' => ['required', 'string', 'max:255'],
            'student_id' => ['required', 'string', 'max:100'],
            'section' => ['required', 'string', 'max:255'],
            'age' => ['required', 'integer', 'min:2', 'max:25'],
            'height_cm' => ['required', 'numeric', 'min:50', 'max:250'],
            'weight_kg' => ['required', 'numeric', 'min:5', 'max:300'],
            'recorded_at' => ['nullable', 'date'],
        ]);

        $bmi = $this->computeBmi((float) $validated['height_cm'], (float) $validated['weight_kg']);
        $status = $this->classifyStatus($bmi, (int) $validated['age']);
        $recordedAt = $validated['recorded_at'] ?? now()->toDateString();

        StudentHealthRecord::query()->updateOrCreate(
            [
                'student_id' => $validated['student_id'],
            ],
            [
                'student_name' => $validated['student_name'],
                'section' => $validated['section'],
                'weight' => (float) $validated['weight_kg'],
                'bmi_value' => $bmi,
                'nutritional_status' => $status,
                'baseline_age' => (int) $validated['age'],
                'baseline_height_cm' => (float) $validated['height_cm'],
                'baseline_weight_kg' => (float) $validated['weight_kg'],
                'baseline_bmi_value' => $bmi,
                'baseline_nutritional_status' => $status,
                'baseline_recorded_at' => $recordedAt,
            ]
        );

        return back()->with('success', 'Baseline record saved. BMI and nutritional status were computed automatically.');
    }

    public function storeEndline(Request $request, StudentHealthRecord $record): RedirectResponse
    {
        $validated = $request->validate([
            'age' => ['required', 'integer', 'min:2', 'max:25'],
            'height_cm' => ['required', 'numeric', 'min:50', 'max:250'],
            'weight_kg' => ['required', 'numeric', 'min:5', 'max:300'],
            'recorded_at' => ['nullable', 'date'],
        ]);

        $bmi = $this->computeBmi((float) $validated['height_cm'], (float) $validated['weight_kg']);
        $status = $this->classifyStatus($bmi, (int) $validated['age']);
        $recordedAt = $validated['recorded_at'] ?? now()->toDateString();

        $record->update([
            'weight' => (float) $validated['weight_kg'],
            'bmi_value' => $bmi,
            'nutritional_status' => $status,
            'endline_age' => (int) $validated['age'],
            'endline_height_cm' => (float) $validated['height_cm'],
            'endline_weight_kg' => (float) $validated['weight_kg'],
            'endline_bmi_value' => $bmi,
            'endline_nutritional_status' => $status,
            'endline_recorded_at' => $recordedAt,
        ]);

        return back()->with('success', 'Endline record saved. BMI comparison is now available.');
    }

    public function feedingHealthRecords(): View
    {
        $records = collect();

        if (Schema::hasTable('student_health_records')) {
            $records = StudentHealthRecord::query()
                ->orderBy('section')
                ->orderBy('student_name')
                ->get();
        }

        $statusCounts = [
            'severely_wasted' => 0,
            'wasted' => 0,
            'normal' => 0,
            'overweight' => 0,
        ];

        foreach ($records as $record) {
            $key = $this->statusKey((string) $record->nutritional_status);
            $statusCounts[$key]++;
        }

        $sectionSummary = $records
            ->groupBy(fn (StudentHealthRecord $record) => $record->section ?: 'Unassigned')
            ->map(function ($sectionRows, string $section): array {
                $counts = [
                    'severely_wasted' => 0,
                    'wasted' => 0,
                    'normal' => 0,
                    'overweight' => 0,
                ];

                foreach ($sectionRows as $row) {
                    $counts[$this->statusKey((string) $row->baseline_nutritional_status ?: (string) $row->nutritional_status)]++;
                }

                return [
                    'section' => $section,
                    'total' => count($sectionRows),
                    'counts' => $counts,
                ];
            })
            ->values();

        return view('feedingcor-dashboard.feed-healthrec', [
            'records' => $records,
            'statusCounts' => $statusCounts,
            'sectionSummary' => $sectionSummary,
        ]);
    }

    private function computeBmi(float $heightCm, float $weightKg): float
    {
        $heightMeters = $heightCm / 100;
        if ($heightMeters <= 0) {
            return 0;
        }

        return round($weightKg / ($heightMeters * $heightMeters), 2);
    }

    private function classifyStatus(float $bmi, int $age): string
    {
        $severeThreshold = 13.0;
        $wastedThreshold = 14.5;
        $overweightThreshold = 21.0;

        if ($age <= 10) {
            $severeThreshold = 12.8;
            $wastedThreshold = 14.2;
            $overweightThreshold = 20.5;
        } elseif ($age >= 15) {
            $severeThreshold = 13.5;
            $wastedThreshold = 15.2;
            $overweightThreshold = 22.5;
        }

        if ($bmi < $severeThreshold) {
            return 'Severely Wasted';
        }
        if ($bmi < $wastedThreshold) {
            return 'Wasted';
        }
        if ($bmi > $overweightThreshold) {
            return 'Overweight';
        }

        return 'Normal';
    }

    private function statusKey(string $status): string
    {
        $normalized = strtolower($status);

        if (str_contains($normalized, 'severe')) {
            return 'severely_wasted';
        }
        if (str_contains($normalized, 'wast')) {
            return 'wasted';
        }
        if (str_contains($normalized, 'over')) {
            return 'overweight';
        }

        return 'normal';
    }
}
