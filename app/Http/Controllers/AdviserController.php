<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use App\Support\StudentRosterSync;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class AdviserController extends Controller
{
    /**
     * Sheet 2 (Systems Review) checkbox answers on the enrolment form. Kept as
     * an explicit whitelist so only known keys reach the encrypted
     * student_details JSON column.
     */
    private const SYSTEMS_REVIEW_FLAGS = [
        'skin_normal', 'skin_lesions', 'skin_pallor',
        'heent_normal', 'heent_abnormal',
        'resp_clear', 'resp_cough',
        'cardio_regular', 'cardio_irregular',
        'abdo_soft', 'abdo_pain',
        'neuro_alert', 'neuro_reflexes', 'neuro_abnormal',
        'dental_good', 'dental_fair', 'dental_poor', 'dental_caries', 'dental_gum', 'dental_referral',
        'immun_complete', 'immun_incomplete', 'immun_not_available',
    ];

    /**
     * Sheet 1 sections B (Medical History), C (Family History) and
     * D (General Appearance). Whitelisted for the same reason as Sheet 2.
     */
    private const HEALTH_HISTORY_FLAGS = [
        'med_asthma', 'med_diabetes', 'med_seizure', 'med_infections',
        'med_heart', 'med_tuberculosis', 'med_hospitalization', 'med_allergies',
        'fam_hypertension', 'fam_diabetes', 'fam_heart', 'fam_cancer', 'fam_mental',
    ];

    /** Free-text and single-choice answers on Sheet 1 sections B-D. */
    private const HEALTH_HISTORY_TEXT = [
        'allergies_detail', 'hospitalization_detail',
        'current_medications', 'other_conditions', 'genetic_disorders',
        'consciousness', 'consciousness_other',
        'posture', 'posture_detail', 'hygiene',
    ];

    /** Free-text and date answers on Sheet 2. */
    private const SYSTEMS_REVIEW_TEXT = [
        'right_eye', 'left_eye', 'immun_date',
        'notes', 'summary', 'recommendations',
        'examiner_name', 'examiner_date',
    ];

    public function create(): View
    {
        return view('adviser.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $birthMonth = $request->input('birth_month');
        $birthDay = $request->input('birth_day');
        $birthYear = $request->input('birth_year');
        $birthDate = trim((string) $request->input('birth_date', ''));

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
        }

        $birthMonth = $request->input('birth_month');
        $birthDay = $request->input('birth_day');
        $birthYear = $request->input('birth_year');

        if ((! $birthMonth || ! $birthDay || ! $birthYear) && $birthDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
            [$yearPart, $monthPart, $dayPart] = explode('-', $birthDate);
            $request->merge([
                'birth_year' => (int) $yearPart,
                'birth_month' => (int) $monthPart,
                'birth_day' => (int) $dayPart,
            ]);
        }

        $heightCm = $request->input('height_cm');
        $heightMeters = $request->input('height_m');
        if ((! is_numeric($heightCm) || (float) $heightCm <= 0) && is_numeric($heightMeters)) {
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
            'systems_review' => ['nullable', 'array'],
            // A 2MB image is roughly 2.8M characters once base64-encoded.
            'systems_review.examiner_signature' => ['nullable', 'string', 'max:2900000'],
            'health_history' => ['nullable', 'array'],
            'temperature_c' => ['nullable', 'numeric', 'between:25,45'],
            'pulse_bpm' => ['nullable', 'integer', 'between:20,250'],
            'blood_pressure' => ['nullable', 'string', 'max:20'],
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

        $existingRecord = Schema::hasTable('student_health_records')
            ? StudentHealthRecord::query()
                ->where('student_id', (string) $validated['lrn'])
                ->where('institution_id', $request->session()->get('active_institution_id'))
                ->where('school_year', StudentHealthRecord::currentSchoolYear())
                ->first()
            : null;

        $birthYear = (int) $validated['birth_year'];
        $birthMonth = (int) $validated['birth_month'];
        $birthDay = (int) $validated['birth_day'];

        $age = $this->resolveAge($birthYear, $birthMonth, $birthDay);
        $heightCm = (float) $validated['height_cm'];
        $weightKg = (float) $validated['weight_kg'];
        $bmi = $this->computeBmi($heightCm, $weightKg);
        $nutritionalStatusBmiForAge = $this->classifyBmiForAge($bmi, $age);
        $nutritionalStatusHeightForAge = $this->classifyHeightForAge($heightCm, $age);

        // Read the raw input, not $validated: adding a rule for the nested
        // systems_review.examiner_signature key makes validated() return only
        // that key for systems_review. normaliseSystemsReview() whitelists
        // every key it keeps, so unvalidated input cannot leak through.
        $systemsReview = $this->normaliseSystemsReview((array) $request->input('systems_review', []));

        // The roster carries no copy of the signature image, so the pad is blank
        // whenever an existing learner is edited. Blank means "keep what is on
        // file" — re-signing is only needed to replace it.
        if ($systemsReview['examiner_signature'] === null) {
            $stored = $existingRecord?->student_details['systems_review']['examiner_signature'] ?? null;
            $systemsReview['examiner_signature'] = is_string($stored) && $stored !== '' ? $stored : null;
        }

        $sessionRow = [
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
            'temperature_c' => $validated['temperature_c'] ?? null,
            'pulse_bpm' => $validated['pulse_bpm'] ?? null,
            'blood_pressure' => $validated['blood_pressure'] ?? null,
            'health_history' => $this->normaliseHealthHistory((array) $request->input('health_history', [])),
            'age' => $age,
            'bmi_value' => $bmi,
            'nutritional_status_bmi_for_age' => $nutritionalStatusBmiForAge,
            'nutritional_status_height_for_age' => $nutritionalStatusHeightForAge,
            'grade_level' => $validated['grade_level'],
            'section' => $validated['section'],
            'systems_review' => $systemsReview,
            'examination' => [],
        ];

        // Editing a learner re-posts their LRN. Replace that roster row rather
        // than appending, or My Students would list the learner twice — the DB
        // side already resolves the same LRN to one row via updateOrCreate.
        $existingIndex = null;
        foreach ($records as $index => $existingRow) {
            if ((string) ($existingRow['lrn'] ?? '') === (string) $validated['lrn']) {
                $existingIndex = $index;
                break;
            }
        }

        if ($existingIndex !== null) {
            // The nurse owns the examination, and the feeding coordinator owns
            // attendance — an adviser edit must not wipe either.
            $sessionRow['examination'] = $records[$existingIndex]['examination'] ?? [];
            $sessionRow['attendance_by_month'] = $records[$existingIndex]['attendance_by_month'] ?? [];
        }

        // The database keeps the signature image; the session roster does not.
        $rosterRow = StudentRosterSync::withoutSignature($sessionRow);

        if ($existingIndex !== null) {
            $records[$existingIndex] = $rosterRow;
        } else {
            $records[] = $rosterRow;
        }

        $request->session()->put('school_health_card_records', $records);

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

            $sectionLabel = trim((string) $validated['grade_level'].' / '.(string) $validated['section']);

            // Persist the full adviser entry so the roster can be rebuilt after
            // session loss or a server restart. Examination and attendance live
            // in their own columns, so they are excluded here.
            $details = $sessionRow;
            unset($details['examination'], $details['attendance_by_month']);

            $payload = [
                'institution_id' => $request->session()->get('active_institution_id'),
                'school_year' => StudentHealthRecord::currentSchoolYear(),
                'student_name' => $studentName,
                'section' => $sectionLabel !== '' ? $sectionLabel : (string) $validated['section'],
                'student_details' => $details,
                'weight' => (float) $validated['weight_kg'],
                'bmi_value' => $bmi,
                'nutritional_status' => $nutritionalStatusBmiForAge,
                'baseline_age' => $age,
                'baseline_height_cm' => $heightCm,
                'baseline_weight_kg' => $weightKg,
                'baseline_bmi_value' => $bmi,
                'baseline_nutritional_status' => $nutritionalStatusBmiForAge,
                'baseline_recorded_at' => now()->toDateString(),
            ];

            if (Schema::hasColumn('student_health_records', 'school_name')) {
                $payload['school_name'] = $schoolName !== '' ? $schoolName : null;
            }

            // Keep compatibility with databases that have not run newer migrations yet.
            $existingColumns = array_flip(Schema::getColumnListing('student_health_records'));
            $payload = array_intersect_key($payload, $existingColumns);

            StudentHealthRecord::query()->updateOrCreate(
                [
                    'student_id' => (string) $validated['lrn'],
                    'institution_id' => $request->session()->get('active_institution_id'),
                    'school_year' => StudentHealthRecord::currentSchoolYear(),
                ],
                $payload
            );
        }

        return redirect()
            ->route('dashboard.class-adviser')
            ->with('success', $existingRecord !== null ? 'Student record updated.' : 'Student enrolled.');
    }

    public function success(): View
    {
        return view('adviser.success');
    }

    /**
     * Reduce a posted checklist to its whitelisted keys. Unchecked boxes are
     * absent from the request, so every flag is normalised to a boolean and
     * every text answer to a trimmed string or null.
     *
     * @param  array<mixed>  $input
     * @param  array<string>  $flags
     * @param  array<string>  $textKeys
     * @return array<string, bool|string|null>
     */
    private function normaliseChecklist(array $input, array $flags, array $textKeys): array
    {
        $result = [];

        foreach ($flags as $flag) {
            $result[$flag] = filter_var($input[$flag] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        foreach ($textKeys as $key) {
            $value = $input[$key] ?? null;
            $value = is_scalar($value) ? trim((string) $value) : '';
            $result[$key] = $value !== '' ? mb_substr($value, 0, 1000) : null;
        }

        return $result;
    }

    /** @param  array<mixed>  $input */
    private function normaliseSystemsReview(array $input): array
    {
        $review = $this->normaliseChecklist($input, self::SYSTEMS_REVIEW_FLAGS, self::SYSTEMS_REVIEW_TEXT);
        $review['examiner_signature'] = $this->normaliseSignature($input['examiner_signature'] ?? null);

        return $review;
    }

    /** @param  array<mixed>  $input */
    private function normaliseHealthHistory(array $input): array
    {
        return $this->normaliseChecklist($input, self::HEALTH_HISTORY_FLAGS, self::HEALTH_HISTORY_TEXT);
    }

    /**
     * The examiner signature arrives as a data URL, drawn on a canvas or read
     * from an uploaded PNG/JPG. Anything that is not one of those two shapes is
     * discarded rather than stored.
     */
    private function normaliseSignature(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $value) === 1
            ? $value
            : null;
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
        $middleInitial = $middleName !== '' ? (' '.strtoupper(substr($middleName, 0, 1)).'.') : '';

        return trim(trim($lastName).', '.trim($firstName).$middleInitial);
    }
}
