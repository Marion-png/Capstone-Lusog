<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class AdviserController extends Controller
{
    public function create(): View
    {
        return view('adviser.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $birthDate = trim((string) $request->input('birth_date', ''));

<<<<<<< Updated upstream
        if ($birthDate !== '') {
            try {
                $parsedBirthDate = Carbon::createFromFormat('Y-m-d', $birthDate);
                $request->merge([
                    'birth_year' => (int) $parsedBirthDate->format('Y'),
                    'birth_month' => (int) $parsedBirthDate->format('n'),
                    'birth_day' => (int) $parsedBirthDate->format('j'),
                ]);
            } catch (\Throwable $_) {
                // Keep existing month/day/year inputs when date parsing fails.
            }
=======
        if (is_string($birthMonth) && ctype_digit($birthMonth)) {
            $birthMonth = (int) $birthMonth;
            $request->merge(['birth_month' => $birthMonth]);
        }

        if (is_string($birthDay) && ctype_digit($birthDay)) {
            $birthDay = (int) $birthDay;
            $request->merge(['birth_day' => $birthDay]);
        }

        if (is_string($birthYear) && ctype_digit($birthYear)) {
            $birthYear = (int) $birthYear;
            $request->merge(['birth_year' => $birthYear]);
        }

        if ((!$birthMonth || !$birthDay || !$birthYear) && is_string($birthDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
            [$yearPart, $monthPart, $dayPart] = explode('-', $birthDate);
            $request->merge([
                'birth_year' => (int) $yearPart,
                'birth_month' => (int) $monthPart,
                'birth_day' => (int) $dayPart,
            ]);
>>>>>>> Stashed changes
        }

        $heightCm = $request->input('height_cm');
        $heightMeters = $request->input('height_m');
        if ((!is_numeric($heightCm) || (float) $heightCm <= 0) && is_numeric($heightMeters)) {
            $request->merge([
                'height_cm' => round(((float) $heightMeters) * 100, 2),
            ]);
        }

        $validated = $request->validate([
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'lrn' => ['required', 'string', 'max:50'],
            'birth_month' => ['required', 'integer', 'between:1,12'],
            'birth_day' => ['required', 'integer', 'between:1,31'],
            'birth_year' => ['required', 'integer', 'between:1900,2100'],
            'birthplace' => ['required', 'string', 'max:255'],
            'parent_guardian' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'school_id' => ['required', 'string', 'max:100'],
            'region' => ['required', 'string', 'max:255'],
            'division' => ['required', 'string', 'max:255'],
            'telephone_no' => ['required', 'string', 'max:50'],
            'gender' => ['nullable', 'string', 'max:20'],
            'height_cm' => ['required', 'numeric', 'min:30', 'max:250'],
            'weight_kg' => ['required', 'numeric', 'min:0.1', 'max:250'],
            'grade_level' => ['required', 'string', 'max:50'],
            'section' => ['required', 'string', 'max:100'],
        ]);

        $assignedGradeLevel = (string) $request->session()->get('assigned_grade_level', '');
        $assignedSection = (string) $request->session()->get('assigned_section', '');

        if ($assignedGradeLevel !== '') {
            $validated['grade_level'] = $assignedGradeLevel;
        }
        if ($assignedSection !== '') {
            $validated['section'] = $assignedSection;
        }

        $records = $request->session()->get('school_health_card_records', []);

        $birthYear = (int) $validated['birth_year'];
        $birthMonth = (int) $validated['birth_month'];
        $birthDay = (int) $validated['birth_day'];

        $age = $this->resolveAge($birthYear, $birthMonth, $birthDay);
        $heightCm = (float) $validated['height_cm'];
        $weightKg = (float) $validated['weight_kg'];
        $bmi = $this->computeBmi($heightCm, $weightKg);
        $nutritionalStatusBmiForAge = $this->classifyBmiForAge($bmi, $age);
        $nutritionalStatusHeightForAge = $this->classifyHeightForAge($heightCm, $age);

        $records[] = [
            'last_name' => $validated['last_name'],
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'lrn' => $validated['lrn'],
            'birth_month' => $validated['birth_month'],
            'birth_day' => $validated['birth_day'],
            'birth_year' => $validated['birth_year'],
            'birthplace' => $validated['birthplace'],
            'parent_guardian' => $validated['parent_guardian'],
            'address' => $validated['address'],
            'school_id' => $validated['school_id'],
            'region' => $validated['region'],
            'division' => $validated['division'],
            'telephone_no' => $validated['telephone_no'],
            'gender' => $validated['gender'] ?? null,
            'height_cm' => $validated['height_cm'],
            'weight_kg' => $validated['weight_kg'],
            'age' => $age,
            'bmi_value' => $bmi,
            'nutritional_status_bmi_for_age' => $nutritionalStatusBmiForAge,
            'nutritional_status_height_for_age' => $nutritionalStatusHeightForAge,
            'grade_level' => $validated['grade_level'],
            'section' => $validated['section'],
            'examination' => [],
        ];

        $request->session()->put('school_health_card_records', $records);

<<<<<<< Updated upstream
        // Mirror adviser submissions to DB so Feeding Coordinator modules can load them immediately.
        if (Schema::hasTable('student_health_records')) {
            $middleName = trim((string) ($validated['middle_name'] ?? ''));
            $middleInitial = $middleName !== '' ? (' ' . strtoupper(substr($middleName, 0, 1)) . '.') : '';
            $studentName = trim($validated['last_name'] . ', ' . $validated['first_name'] . $middleInitial);
            $schoolName = (string) $request->session()->get('assigned_school_name', '');

            $recordPayload = [
                'student_name' => $studentName,
                'section' => trim((string) $validated['grade_level'] . ' / ' . (string) $validated['section']),
                'weight' => (float) $validated['weight_kg'],
                'bmi_value' => $bmi,
                'nutritional_status' => $nutritionalStatusBmiForAge,
                'baseline_age' => $age,
                'baseline_height_cm' => (float) $validated['height_cm'],
                'baseline_weight_kg' => (float) $validated['weight_kg'],
                'baseline_bmi_value' => $bmi,
                'baseline_nutritional_status' => $nutritionalStatusBmiForAge,
                'baseline_recorded_at' => now()->toDateString(),
            ];

            if (Schema::hasColumn('student_health_records', 'school_name')) {
                $recordPayload['school_name'] = $schoolName !== '' ? $schoolName : null;
            }

            StudentHealthRecord::query()->updateOrCreate(
                ['student_id' => (string) $validated['lrn']],
                $recordPayload
=======
        if (Schema::hasTable('student_health_records')) {
            $studentName = $this->buildStudentName(
                (string) $validated['last_name'],
                (string) $validated['first_name'],
                (string) ($validated['middle_name'] ?? '')
            );

            $schoolName = (string) $request->session()->get('assigned_school_name', '');
            if ($schoolName === '') {
                $schoolName = (string) ($validated['division'] ?? '');
            }

            $sectionLabel = trim((string) $validated['grade_level'] . ' / ' . (string) $validated['section']);

            StudentHealthRecord::query()->updateOrCreate(
                [
                    'student_id' => (string) $validated['lrn'],
                ],
                [
                    'student_name' => $studentName,
                    'school_name' => $schoolName !== '' ? $schoolName : null,
                    'section' => $sectionLabel !== '' ? $sectionLabel : (string) $validated['section'],
                    'weight' => (float) $validated['weight_kg'],
                    'bmi_value' => $bmi,
                    'nutritional_status' => $nutritionalStatusBmiForAge,
                    'baseline_age' => $age,
                    'baseline_height_cm' => $heightCm,
                    'baseline_weight_kg' => $weightKg,
                    'baseline_bmi_value' => $bmi,
                    'baseline_nutritional_status' => $nutritionalStatusBmiForAge,
                    'baseline_recorded_at' => now()->toDateString(),
                ]
>>>>>>> Stashed changes
            );
        }

        return redirect()
            ->route('dashboard.class-adviser')
            ->with('success', 'Record submitted to School Nurse.');
    }

    public function success(): View
    {
        return view('adviser.success');
    }

    private function resolveAge(int $birthYear, int $birthMonth, int $birthDay): ?int
    {
        if ($birthYear <= 0 || $birthMonth <= 0 || $birthDay <= 0) {
            return null;
        }

        try {
            $birthDate = Carbon::createFromDate($birthYear, $birthMonth, $birthDay);
        } catch (\Throwable $_) {
            return null;
        }

        return $birthDate->isFuture() ? null : $birthDate->age;
    }

    private function computeBmi(float $heightCm, float $weightKg): ?float
    {
        if ($heightCm <= 0 || $weightKg <= 0) {
            return null;
        }

        $heightMeters = $heightCm / 100;
        return round($weightKg / ($heightMeters * $heightMeters), 2);
    }

    private function classifyBmiForAge(?float $bmi, ?int $age): string
    {
        if ($bmi === null || $age === null) {
            return 'Not enough data';
        }

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

    private function classifyHeightForAge(float $heightCm, ?int $age): string
    {
        if ($heightCm <= 0 || $age === null) {
            return 'Not enough data';
        }

        // Simple prototype rule-based classification for dashboard use.
        $minNormalHeight = 70 + ($age * 5);
        if ($heightCm < ($minNormalHeight - 8)) {
            return 'Severely Stunted';
        }
        if ($heightCm < $minNormalHeight) {
            return 'Stunted';
        }

        return 'Normal Height-for-Age';
    }

    private function buildStudentName(string $lastName, string $firstName, string $middleName): string
    {
        $middleName = trim($middleName);
        $middleInitial = $middleName !== '' ? (' ' . strtoupper(substr($middleName, 0, 1)) . '.') : '';

        return trim(trim($lastName) . ', ' . trim($firstName) . $middleInitial);
    }
}
