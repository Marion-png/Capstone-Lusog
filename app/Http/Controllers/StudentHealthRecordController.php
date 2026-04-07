<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class StudentHealthRecordController extends Controller
{
    public function classAdviserDashboard(Request $request): View
    {
        $this->ensureClassAdviserDemoData($request);

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

    private function ensureClassAdviserDemoData(Request $request): void
    {
        $assignedGradeLevel = (string) $request->session()->get('assigned_grade_level', '');
        $assignedSection = (string) $request->session()->get('assigned_section', '');

        if ($assignedGradeLevel === '' || $assignedSection === '') {
            $assignedGradeLevel = 'Grade 10';
            $assignedSection = 'Rizal';

            $request->session()->put('assigned_grade_level', $assignedGradeLevel);
            $request->session()->put('assigned_section', $assignedSection);
        }

        $records = $request->session()->get('school_health_card_records', []);
        $hasAssignedClassRows = collect($records)->contains(function (array $record) use ($assignedGradeLevel, $assignedSection): bool {
            return (string) ($record['grade_level'] ?? '') === $assignedGradeLevel
                && (string) ($record['section'] ?? '') === $assignedSection;
        });

        if ($hasAssignedClassRows) {
            return;
        }

        $demoRows = [
            [
                'last_name' => 'Santos',
                'first_name' => 'Andrea',
                'middle_name' => 'Lopez',
                'lrn' => '100234560201',
                'birth_month' => 3,
                'birth_day' => 12,
                'birth_year' => 2010,
                'birthplace' => 'Quezon City',
                'parent_guardian' => 'Maria Santos',
                'address' => 'Blk 10 Lot 2, Brgy. Rizal',
                'school_id' => 'DCNHS-001',
                'region' => 'NCR',
                'division' => 'Quezon City',
                'telephone_no' => '09171230001',
                'height_cm' => 151.2,
                'weight_kg' => 43.1,
                'age' => 16,
                'bmi_value' => 18.85,
                'nutritional_status_bmi_for_age' => 'Normal',
                'nutritional_status_height_for_age' => 'Normal Height-for-Age',
                'grade_level' => $assignedGradeLevel,
                'section' => $assignedSection,
                'attendance_by_month' => [
                    '2026-01' => 17,
                    '2026-02' => 18,
                    '2026-03' => 19,
                ],
                'baseline_snapshot' => [
                    'height_cm' => 149.7,
                    'weight_kg' => 41.8,
                ],
                'endline_snapshot' => [
                    'height_cm' => 151.2,
                    'weight_kg' => 43.1,
                    'nutritional_status_bmi' => 'Normal',
                ],
                'examination' => [
                    'date_of_examination' => '2026-03-12',
                    'height_cm' => 151.2,
                    'weight_kg' => 43.1,
                    'nutritional_status_bmi' => 'Normal',
                    'examined_by' => 'Nurse M. Lopez',
                ],
            ],
            [
                'last_name' => 'Dela Cruz',
                'first_name' => 'Joshua',
                'middle_name' => 'Reyes',
                'lrn' => '100234560202',
                'birth_month' => 8,
                'birth_day' => 5,
                'birth_year' => 2010,
                'birthplace' => 'Pasig City',
                'parent_guardian' => 'Liza Dela Cruz',
                'address' => 'Purok 3, Brgy. Commonwealth',
                'school_id' => 'DCNHS-001',
                'region' => 'NCR',
                'division' => 'Quezon City',
                'telephone_no' => '09171230002',
                'height_cm' => 147.5,
                'weight_kg' => 36.9,
                'age' => 15,
                'bmi_value' => 16.96,
                'nutritional_status_bmi_for_age' => 'Normal',
                'nutritional_status_height_for_age' => 'Stunted',
                'grade_level' => $assignedGradeLevel,
                'section' => $assignedSection,
                'attendance_by_month' => [
                    '2026-01' => 15,
                    '2026-02' => 16,
                    '2026-03' => 14,
                ],
                'baseline_snapshot' => [
                    'height_cm' => 146.8,
                    'weight_kg' => 35.7,
                ],
                'endline_snapshot' => [
                    'height_cm' => 147.5,
                    'weight_kg' => 36.9,
                    'nutritional_status_bmi' => 'Normal',
                ],
                'examination' => [],
            ],
            [
                'last_name' => 'Fernandez',
                'first_name' => 'Kim',
                'middle_name' => 'A.',
                'lrn' => '100234560203',
                'birth_month' => 11,
                'birth_day' => 21,
                'birth_year' => 2010,
                'birthplace' => 'Manila',
                'parent_guardian' => 'Nora Fernandez',
                'address' => 'Sitio Maligaya, Brgy. Holy Spirit',
                'school_id' => 'DCNHS-001',
                'region' => 'NCR',
                'division' => 'Quezon City',
                'telephone_no' => '09171230003',
                'height_cm' => 153.4,
                'weight_kg' => 49.8,
                'age' => 15,
                'bmi_value' => 21.16,
                'nutritional_status_bmi_for_age' => 'Normal',
                'nutritional_status_height_for_age' => 'Normal Height-for-Age',
                'grade_level' => $assignedGradeLevel,
                'section' => $assignedSection,
                'attendance_by_month' => [
                    '2026-01' => 18,
                    '2026-02' => 17,
                    '2026-03' => 18,
                ],
                'baseline_snapshot' => [
                    'height_cm' => 152.1,
                    'weight_kg' => 47.6,
                ],
                'endline_snapshot' => [
                    'height_cm' => 153.4,
                    'weight_kg' => 49.8,
                    'nutritional_status_bmi' => 'Normal',
                ],
                'examination' => [
                    'date_of_examination' => '2026-03-05',
                    'height_cm' => 153.4,
                    'weight_kg' => 49.8,
                    'nutritional_status_bmi' => 'Normal',
                    'examined_by' => 'Nurse M. Lopez',
                ],
            ],
        ];

        $request->session()->put('school_health_card_records', array_merge($records, $demoRows));
    }

    public function storeBaseline(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'student_name' => ['required', 'string', 'max:255'],
            'student_id' => ['required', 'string', 'max:100'],
            'school_name' => ['nullable', 'string', 'max:255'],
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
                'school_name' => $validated['school_name'] ?? null,
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

    public function feedingHealthRecords(Request $request): View
    {
        $records = collect();

        if (Schema::hasTable('student_health_records')) {
            $records = StudentHealthRecord::query()
                ->orderBy('section')
                ->orderBy('student_name')
                ->get();
        }

        $sessionAtRiskRecords = collect($request->session()->get('school_health_card_records', []))
            ->filter(function (array $row): bool {
                $status = strtolower((string) ($row['nutritional_status_bmi_for_age'] ?? ''));
                return str_contains($status, 'wasted') || str_contains($status, 'underweight');
            })
            ->map(function (array $row): object {
                $middle = trim((string) ($row['middle_name'] ?? ''));
                $middleInitial = $middle !== '' ? (' ' . strtoupper(substr($middle, 0, 1)) . '.') : '';
                $fullName = trim((string) ($row['last_name'] ?? '') . ', ' . (string) ($row['first_name'] ?? '') . $middleInitial);

                $baselineStatus = (string) ($row['nutritional_status_bmi_for_age'] ?? '');
                $baselineBmi = is_numeric($row['bmi_value'] ?? null) ? (float) $row['bmi_value'] : null;

                $endlineBmiRaw = data_get($row, 'endline_snapshot.bmi_value');
                if (!is_numeric($endlineBmiRaw)) {
                    $endlineWeight = data_get($row, 'endline_snapshot.weight_kg');
                    $heightCm = $row['height_cm'] ?? null;
                    if (is_numeric($endlineWeight) && is_numeric($heightCm) && (float) $heightCm > 0) {
                        $heightMeters = ((float) $heightCm) / 100;
                        $endlineBmiRaw = round(((float) $endlineWeight) / ($heightMeters * $heightMeters), 2);
                    }
                }

                return (object) [
                    'student_name' => $fullName !== '' ? $fullName : ((string) ($row['first_name'] ?? 'Unknown Student')),
                    'section' => trim((string) ($row['grade_level'] ?? '') . ' / ' . (string) ($row['section'] ?? '')),
                    'baseline_bmi_value' => $baselineBmi,
                    'baseline_nutritional_status' => $baselineStatus,
                    'endline_bmi_value' => is_numeric($endlineBmiRaw) ? (float) $endlineBmiRaw : null,
                    'endline_nutritional_status' => data_get($row, 'endline_snapshot.nutritional_status_bmi'),
                    'nutritional_status' => $baselineStatus,
                ];
            });

        $records = $records->concat($sessionAtRiskRecords)->values();

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
            ->groupBy(fn ($record) => (string) ($record->section ?: 'Unassigned'))
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
        if (str_contains($normalized, 'wast') || str_contains($normalized, 'underweight')) {
            return 'wasted';
        }
        if (str_contains($normalized, 'over')) {
            return 'overweight';
        }

        return 'normal';
    }
}
