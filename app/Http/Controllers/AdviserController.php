<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdviserController extends Controller
{
    public function create(): View
    {
        return view('adviser.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $birthMonth = $request->input('birth_month');
        $birthDay = $request->input('birth_day');
        $birthYear = $request->input('birth_year');
        $birthDate = $request->input('birth_date');

        if ((!$birthMonth || !$birthDay || !$birthYear) && is_string($birthDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
            [$yearPart, $monthPart, $dayPart] = explode('-', $birthDate);
            $request->merge([
                'birth_year' => (int) $yearPart,
                'birth_month' => (int) $monthPart,
                'birth_day' => (int) $dayPart,
            ]);
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
}
