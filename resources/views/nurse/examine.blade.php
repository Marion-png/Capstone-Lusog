<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nurse Examination</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
@php
    $exam = $record['examination'] ?? [];
    $middle = trim((string) ($record['middle_name'] ?? ''));
    $middleInitial = $middle !== '' ? (' ' . strtoupper(substr($middle, 0, 1)) . '.') : '';
    $studentName = trim(($record['last_name'] ?? '') . ', ' . ($record['first_name'] ?? '') . $middleInitial);
@endphp

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Medical Examination Form</h1>
        <a href="{{ route('dashboard.student-health-records') }}" class="btn btn-outline-secondary btn-sm">Back to Dashboard</a>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h6 text-secondary mb-3">Student Basic Info (Read-only)</h2>
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">Student Name</label><input class="form-control" value="{{ $studentName }}" readonly></div>
                <div class="col-md-4"><label class="form-label">LRN</label><input class="form-control" value="{{ $record['lrn'] ?? '' }}" readonly></div>
                <div class="col-md-4"><label class="form-label">Grade Level</label><input class="form-control" value="{{ $record['grade_level'] ?? '' }}" readonly></div>
                <div class="col-md-6"><label class="form-label">Parent/Guardian</label><input class="form-control" value="{{ $record['parent_guardian'] ?? '' }}" readonly></div>
                <div class="col-md-6"><label class="form-label">Address</label><input class="form-control" value="{{ $record['address'] ?? '' }}" readonly></div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <h2 class="h6 text-secondary mb-3">Medical Findings</h2>
            <form method="POST" action="{{ route('nurse.examine.save', $index) }}">
                @csrf
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label">Date of Examination</label><input type="date" name="date_of_examination" class="form-control" value="{{ $exam['date_of_examination'] ?? '' }}"></div>
                    <div class="col-md-4"><label class="form-label">Temperature / BP</label><input type="text" name="temperature_bp" class="form-control" value="{{ $exam['temperature_bp'] ?? '' }}"></div>
                    <div class="col-md-4"><label class="form-label">Heart Rate</label><input type="text" name="heart_rate" class="form-control" value="{{ $exam['heart_rate'] ?? '' }}"></div>

                    <div class="col-md-4"><label class="form-label">Pulse Rate</label><input type="text" name="pulse_rate" class="form-control" value="{{ $exam['pulse_rate'] ?? '' }}"></div>
                    <div class="col-md-4"><label class="form-label">Respiratory Rate</label><input type="text" name="respiratory_rate" class="form-control" value="{{ $exam['respiratory_rate'] ?? '' }}"></div>
                    <div class="col-md-2"><label class="form-label">Height (cm)</label><input type="text" id="examHeightCm" name="height_cm" class="form-control" value="{{ $exam['height_cm'] ?? ($record['height_cm'] ?? '') }}" readonly></div>
                    <div class="col-md-2"><label class="form-label">Weight (kg)</label><input type="text" id="examWeightKg" name="weight_kg" class="form-control" value="{{ $exam['weight_kg'] ?? ($record['weight_kg'] ?? '') }}" readonly></div>

                    <div class="col-md-6"><label class="form-label">Nutritional Status (BMI/Wt-for-Age)</label><input type="text" id="examNutritionalStatusBmi" name="nutritional_status_bmi" class="form-control" value="{{ $exam['nutritional_status_bmi'] ?? ($record['nutritional_status_bmi_for_age'] ?? '') }}" readonly></div>
                    <div class="col-md-6"><label class="form-label">Nutritional Status (Height-for-Age)</label><input type="text" id="examNutritionalStatusHeightAge" name="nutritional_status_height_age" class="form-control" value="{{ $exam['nutritional_status_height_age'] ?? ($record['nutritional_status_height_for_age'] ?? '') }}" readonly></div>

                    <div class="col-md-6"><label class="form-label">Vision Screening</label><input type="text" name="vision_screening" class="form-control" value="{{ $exam['vision_screening'] ?? '' }}"></div>
                    <div class="col-md-6"><label class="form-label">Auditory Screening</label><input type="text" name="auditory_screening" class="form-control" value="{{ $exam['auditory_screening'] ?? '' }}"></div>

                    <div class="col-md-4"><label class="form-label">Skin/Scalp</label><input type="text" name="skin_scalp" class="form-control" value="{{ $exam['skin_scalp'] ?? '' }}"></div>
                    <div class="col-md-4"><label class="form-label">Eyes/Ears/Nose</label><input type="text" name="eyes_ears_nose" class="form-control" value="{{ $exam['eyes_ears_nose'] ?? '' }}"></div>
                    <div class="col-md-4"><label class="form-label">Mouth/Throat/Neck</label><input type="text" name="mouth_throat_neck" class="form-control" value="{{ $exam['mouth_throat_neck'] ?? '' }}"></div>

                    <div class="col-md-4"><label class="form-label">Lungs/Heart</label><input type="text" name="lungs_heart" class="form-control" value="{{ $exam['lungs_heart'] ?? '' }}"></div>
                    <div class="col-md-4"><label class="form-label">Abdomen</label><input type="text" name="abdomen" class="form-control" value="{{ $exam['abdomen'] ?? '' }}"></div>
                    <div class="col-md-4"><label class="form-label">Deformities</label><input type="text" name="deformities" class="form-control" value="{{ $exam['deformities'] ?? '' }}"></div>

                    <div class="col-md-3">
                        <label class="form-label">Iron Supplementation</label>
                        <select name="iron_supplementation" class="form-select">
                            <option value="">Select</option>
                            <option value="V" @selected(($exam['iron_supplementation'] ?? '') === 'V')>V</option>
                            <option value="X" @selected(($exam['iron_supplementation'] ?? '') === 'X')>X</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Deworming</label>
                        <select name="deworming" class="form-select">
                            <option value="">Select</option>
                            <option value="V" @selected(($exam['deworming'] ?? '') === 'V')>V</option>
                            <option value="X" @selected(($exam['deworming'] ?? '') === 'X')>X</option>
                        </select>
                    </div>
                    <div class="col-md-6"><label class="form-label">Immunization (specify kind)</label><input type="text" name="immunization" class="form-control" value="{{ $exam['immunization'] ?? '' }}"></div>

                    <div class="col-md-3">
                        <label class="form-label">SBFP Beneficiary</label>
                        <select name="sbfp_beneficiary" class="form-select">
                            <option value="">Select</option>
                            <option value="V" @selected(($exam['sbfp_beneficiary'] ?? '') === 'V')>V</option>
                            <option value="X" @selected(($exam['sbfp_beneficiary'] ?? '') === 'X')>X</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">4Ps Beneficiary</label>
                        <select name="four_ps_beneficiary" class="form-select">
                            <option value="">Select</option>
                            <option value="V" @selected(($exam['four_ps_beneficiary'] ?? '') === 'V')>V</option>
                            <option value="X" @selected(($exam['four_ps_beneficiary'] ?? '') === 'X')>X</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Menarche (V the Start)</label>
                        <select name="menarche" class="form-select">
                            <option value="">Select</option>
                            <option value="V" @selected(($exam['menarche'] ?? '') === 'V')>V</option>
                            <option value="X" @selected(($exam['menarche'] ?? '') === 'X')>X</option>
                        </select>
                    </div>
                    <div class="col-md-3"><label class="form-label">Others (specify)</label><input type="text" name="others" class="form-control" value="{{ $exam['others'] ?? '' }}"></div>

                    <div class="col-md-6"><label class="form-label">Examined By</label><input type="text" name="examined_by" class="form-control" value="{{ $exam['examined_by'] ?? '' }}"></div>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-success">Save Examination</button>
                    <a href="{{ route('dashboard.student-health-records') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
(() => {
    const heightInput = document.getElementById('examHeightCm');
    const weightInput = document.getElementById('examWeightKg');
    const bmiStatusInput = document.getElementById('examNutritionalStatusBmi');
    const hfaStatusInput = document.getElementById('examNutritionalStatusHeightAge');

    if (!heightInput || !weightInput || !bmiStatusInput || !hfaStatusInput) {
        return;
    }

    const existingBmiStatus = bmiStatusInput.value.trim();
    const existingHfaStatus = hfaStatusInput.value.trim();

    const birthYear = Number(@json($record['birth_year'] ?? null));
    const birthMonth = Number(@json($record['birth_month'] ?? null));
    const birthDay = Number(@json($record['birth_day'] ?? null));

    const resolveAge = () => {
        if (!Number.isFinite(birthYear) || !Number.isFinite(birthMonth) || !Number.isFinite(birthDay)) {
            return null;
        }

        const dob = new Date(birthYear, birthMonth - 1, birthDay);
        if (Number.isNaN(dob.getTime())) {
            return null;
        }

        const today = new Date();
        let age = today.getFullYear() - dob.getFullYear();
        const monthDiff = today.getMonth() - dob.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
            age -= 1;
        }

        return age >= 0 ? age : null;
    };

    const classifyBmiForAge = (bmi, age) => {
        if (!Number.isFinite(bmi) || age === null) {
            return 'Not enough data';
        }

        if (bmi < 16.0) return 'Severely Wasted';
        if (bmi < 17.0) return 'Wasted';
        if (bmi < 18.5) return 'Underweight';
        if (bmi >= 25.0) return 'Overweight';
        return 'Normal';
    };

    const classifyHeightForAge = (heightCm, age) => {
        if (!Number.isFinite(heightCm) || age === null || heightCm <= 0) {
            return 'Not enough data';
        }

        const heightM = heightCm / 100;
        if (heightM < 1.20) return 'Severely Stunted';
        if (heightM < 1.30) return 'Stunted';
        if (heightM > 1.70) return 'Tall';
        return 'Normal Height-for-Age';
    };

    const updateStatuses = (force = false) => {
        const heightCm = Number(heightInput.value);
        const weightKg = Number(weightInput.value);
        const age = resolveAge();

        if (!Number.isFinite(heightCm) || !Number.isFinite(weightKg) || heightCm <= 0 || weightKg <= 0) {
            return;
        }

        const heightM = heightCm / 100;
        const bmi = weightKg / (heightM * heightM);

        if (force || bmiStatusInput.value.trim() === '' || bmiStatusInput.value.trim() === existingBmiStatus) {
            bmiStatusInput.value = classifyBmiForAge(bmi, age);
        }

        if (force || hfaStatusInput.value.trim() === '' || hfaStatusInput.value.trim() === existingHfaStatus) {
            hfaStatusInput.value = classifyHeightForAge(heightCm, age);
        }
    };

    heightInput.addEventListener('input', () => updateStatuses(true));
    weightInput.addEventListener('input', () => updateStatuses(true));
    updateStatuses(false);
})();
</script>
</body>
</html>
