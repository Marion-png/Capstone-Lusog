<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use App\Support\StudentRosterSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class StudentHealthRecordController extends Controller
{
    public function classAdviserDashboard(Request $request): View|RedirectResponse
    {
        if ($request->session()->get('active_role') !== 'class_adviser') {
            $roleRoutes = [
                'school_nurse' => 'dashboard.school-nurse',
                'clinic_staff' => 'dashboard.clinic-staff',
                'school_head' => 'dashboard.school-head',
                'feeding_coor' => 'dashboard.feedingcor-dashboard',
                'nutricor' => 'dashboard.nutricor-dashboard',
                'system_admin' => 'dashboard.system-admin',
            ];
            $role = (string) $request->session()->get('active_role', '');
            $route = $roleRoutes[$role] ?? 'dashboard.school-nurse';

            return redirect()->route($route);
        }

        $this->stripLegacyDemoRows($request);
        $this->ensureAssignedSchoolName($request);

        // Rebuild the roster from the database so adviser-entered students
        // survive session expiry, re-login, and server restarts.
        StudentRosterSync::syncToSession($request);

        $records = collect();

        if (Schema::hasTable('student_health_records')) {
            $q = StudentHealthRecord::query();
            $institutionId = $request->session()->get('active_institution_id');
            if ($institutionId) {
                $q->where('institution_id', $institutionId);
            }
            $records = $q->orderByDesc('updated_at')->get();
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

        $lrnsWithCertificates = [];
        if (Schema::hasTable('student_health_conditions') && Schema::hasTable('medical_certificates')) {
            $lrnsWithCertificates = array_flip(
                StudentHealthRecord::forActiveInstitution()
                    ->whereHas('healthConditions.certificates')
                    ->pluck('student_id')
                    ->toArray()
            );
        }

        return view('adviser-dashboard.class-adviser', [
            'records' => $records,
            'stats' => [
                'encoded_today' => $todayCount,
                'avg_bmi' => number_format((float) $avgBmi, 1),
                'flagged' => $flaggedCount,
            ],
            'lrnsWithCertificates' => $lrnsWithCertificates,
        ]);
    }

    /**
     * Remove any leftover built-in demo student rows that older sessions may
     * still carry, so they don't reappear after the demo accounts were removed.
     */
    private function stripLegacyDemoRows(Request $request): void
    {
        $demoLrns = ['100234560201', '100234560202', '100234560203'];
        $existing = $request->session()->get('school_health_card_records', []);
        $cleaned = collect($existing)
            ->reject(fn (array $r) => in_array((string) ($r['lrn'] ?? ''), $demoLrns, true))
            ->values()
            ->all();

        if (count($cleaned) !== count($existing)) {
            $request->session()->put('school_health_card_records', $cleaned);
        }
    }

    /**
     * Ensure assigned_school_name is always populated in the session.
     * Sessions created before this key was introduced will not have it, so we
     * recover it from active_school_name or the accounts table on first access.
     */
    private function ensureAssignedSchoolName(Request $request): void
    {
        if ($request->session()->get('assigned_school_name') !== null) {
            return;
        }

        $schoolName = $request->session()->get('active_school_name');

        if ($schoolName === null && Schema::hasTable('accounts')) {
            $username = strtolower((string) $request->session()->get('active_username', ''));
            if ($username !== '') {
                $account = DB::table('accounts')
                    ->whereRaw('LOWER(TRIM(username)) = ?', [$username])
                    ->when($request->session()->get('active_institution_id'), fn ($q, $id) => $q->where('institution_id', $id))
                    ->first();
                $schoolName = $account?->school_name ?? null;
            }
        }

        if ($schoolName !== null) {
            $request->session()->put('assigned_school_name', $schoolName);
        }
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
                'institution_id' => $request->session()->get('active_institution_id'),
                'student_name' => $validated['student_name'],
                'school_name' => $validated['school_name'] ?? session('active_school_name'),
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
            $q = StudentHealthRecord::query();
            $institutionId = $request->session()->get('active_institution_id');
            if ($institutionId) {
                $q->where('institution_id', $institutionId);
            }
            // student_name is encrypted at rest, so sorting happens in PHP.
            $records = $q->get()->sortBy([
                ['section', 'asc'],
                ['student_name', 'asc'],
            ])->values();
        }

        $sessionAtRiskRecords = collect($request->session()->get('school_health_card_records', []))
            ->filter(function (array $row): bool {
                $status = strtolower((string) ($row['nutritional_status_bmi_for_age'] ?? ''));

                return str_contains($status, 'wasted') || str_contains($status, 'underweight');
            })
            ->map(function (array $row): object {
                $middle = trim((string) ($row['middle_name'] ?? ''));
                $middleInitial = $middle !== '' ? (' '.strtoupper(substr($middle, 0, 1)).'.') : '';
                $fullName = trim((string) ($row['last_name'] ?? '').', '.(string) ($row['first_name'] ?? '').$middleInitial);

                $baselineStatus = (string) ($row['nutritional_status_bmi_for_age'] ?? '');
                $baselineBmi = is_numeric($row['bmi_value'] ?? null) ? (float) $row['bmi_value'] : null;

                $endlineBmiRaw = data_get($row, 'endline_snapshot.bmi_value');
                if (! is_numeric($endlineBmiRaw)) {
                    $endlineWeight = data_get($row, 'endline_snapshot.weight_kg');
                    $heightCm = $row['height_cm'] ?? null;
                    if (is_numeric($endlineWeight) && is_numeric($heightCm) && (float) $heightCm > 0) {
                        $heightMeters = ((float) $heightCm) / 100;
                        $endlineBmiRaw = round(((float) $endlineWeight) / ($heightMeters * $heightMeters), 2);
                    }
                }

                return (object) [
                    'student_name' => $fullName !== '' ? $fullName : ((string) ($row['first_name'] ?? 'Unknown Student')),
                    'section' => trim((string) ($row['grade_level'] ?? '').' / '.(string) ($row['section'] ?? '')),
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
        if ($bmi < 16.0) {
            return 'Severely Wasted';
        }
        if ($bmi < 17.0) {
            return 'Wasted';
        }
        if ($bmi < 18.5) {
            return 'Underweight';
        }
        if ($bmi >= 25.0) {
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
