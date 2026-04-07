<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Adviser Form</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">School Health Card Prototype - Class Adviser</h1>
        <a href="{{ route('nurse.index') }}" class="btn btn-outline-primary btn-sm">Go to Nurse Dashboard</a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('adviser.store') }}">
                @csrf

                <h2 class="h6 text-secondary mt-1">Student Info</h2>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">First Name</label>
                        <input type="text" name="first_name" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" class="form-control">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">LRN</label>
                        <input type="text" name="lrn" class="form-control" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Birth Month</label>
                        <input type="text" name="birth_month" class="form-control" placeholder="MM" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Birth Day</label>
                        <input type="text" name="birth_day" class="form-control" placeholder="DD" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Birth Year</label>
                        <input type="text" name="birth_year" class="form-control" placeholder="YYYY" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Birthplace</label>
                        <input type="text" name="birthplace" class="form-control" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Parent/Guardian</label>
                        <input type="text" name="parent_guardian" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Address</label>
                        <input type="text" name="address" class="form-control" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">School ID</label>
                        <input type="text" name="school_id" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Region</label>
                        <input type="text" name="region" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Division</label>
                        <input type="text" name="division" class="form-control" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Telephone No.</label>
                        <input type="text" name="telephone_no" class="form-control" required>
                    </div>
                </div>

                <hr class="my-4">

                <h2 class="h6 text-secondary">Health Measurements</h2>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Height (cm)</label>
                        <input type="text" name="height_cm" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Weight (kg)</label>
                        <input type="text" name="weight_kg" id="proto_weight_kg" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">BMI (Auto)</label>
                        <input type="text" id="proto_bmi_value" class="form-control" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nutritional Status (BMI for Age) - Auto</label>
                        <input type="text" id="proto_nutritional_status_bmi_for_age" class="form-control" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nutritional Status (Height for Age) - Auto</label>
                        <input type="text" id="proto_nutritional_status_height_for_age" class="form-control" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Grade Level</label>
                        <select name="grade_level" class="form-select" required>
                            <option value="">Select Grade Level</option>
                            <option>Kinder/SPED</option>
                            <option>Grade 1/SPED</option>
                            <option>Grade 2/SPED</option>
                            <option>Grade 3/SPED</option>
                            <option>Grade 4/SPED</option>
                            <option>Grade 5/SPED</option>
                            <option>Grade 6/SPED</option>
                            <option>Grade 7/SPED</option>
                            <option>Grade 8/SPED</option>
                            <option>Grade 9/SPED</option>
                            <option>Grade 10/SPED</option>
                            <option>Grade 11/SPED</option>
                            <option>Grade 12/SPED</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Section</label>
                        <input type="text" name="section" class="form-control" placeholder="e.g., SPED-A" required>
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-success">Submit to School Nurse</button>
                    <a href="{{ route('nurse.index') }}" class="btn btn-outline-secondary">View Nurse Dashboard</a>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
(() => {
    const heightInput = document.querySelector('input[name="height_cm"]');
    const weightInput = document.getElementById('proto_weight_kg');
    const monthInput = document.querySelector('input[name="birth_month"]');
    const dayInput = document.querySelector('input[name="birth_day"]');
    const yearInput = document.querySelector('input[name="birth_year"]');
    const bmiOut = document.getElementById('proto_bmi_value');
    const bmiAgeOut = document.getElementById('proto_nutritional_status_bmi_for_age');
    const hfaOut = document.getElementById('proto_nutritional_status_height_for_age');

    if (!heightInput || !weightInput || !monthInput || !dayInput || !yearInput || !bmiOut || !bmiAgeOut || !hfaOut) {
        return;
    }

    const toNum = (value) => {
        const num = Number(value);
        return Number.isFinite(num) ? num : null;
    };

    const getAge = () => {
        const month = Number(monthInput.value);
        const day = Number(dayInput.value);
        const year = Number(yearInput.value);
        if (!month || !day || !year) {
            return null;
        }

        const birth = new Date(year, month - 1, day);
        if (Number.isNaN(birth.getTime())) {
            return null;
        }

        const today = new Date();
        let age = today.getFullYear() - birth.getFullYear();
        const monthDiff = today.getMonth() - birth.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) {
            age -= 1;
        }

        return age >= 0 ? age : null;
    };

    const classifyBmiForAge = (bmi, age) => {
        if (bmi === null || age === null) {
            return 'Not enough data';
        }

        let severe = 13.0;
        let wasted = 14.5;
        let overweight = 21.0;
        if (age <= 10) {
            severe = 12.8;
            wasted = 14.2;
            overweight = 20.5;
        } else if (age >= 15) {
            severe = 13.5;
            wasted = 15.2;
            overweight = 22.5;
        }

        if (bmi < severe) return 'Severely Wasted';
        if (bmi < wasted) return 'Wasted';
        if (bmi > overweight) return 'Overweight';
        return 'Normal';
    };

    const classifyHeightForAge = (heightCm, age) => {
        if (heightCm === null || age === null) {
            return 'Not enough data';
        }

        const minNormal = 70 + (age * 5);
        if (heightCm < (minNormal - 8)) return 'Severely Stunted';
        if (heightCm < minNormal) return 'Stunted';
        return 'Normal Height-for-Age';
    };

    const recalc = () => {
        const heightCm = toNum(heightInput.value);
        const weightKg = toNum(weightInput.value);
        const age = getAge();

        if (!heightCm || !weightKg || heightCm <= 0 || weightKg <= 0) {
            bmiOut.value = '';
            bmiAgeOut.value = 'Not enough data';
            hfaOut.value = classifyHeightForAge(heightCm, age);
            return;
        }

        const bmi = weightKg / Math.pow(heightCm / 100, 2);
        bmiOut.value = bmi.toFixed(2);
        bmiAgeOut.value = classifyBmiForAge(bmi, age);
        hfaOut.value = classifyHeightForAge(heightCm, age);
    };

    [heightInput, weightInput, monthInput, dayInput, yearInput].forEach((node) => {
        node.addEventListener('input', recalc);
        node.addEventListener('change', recalc);
    });
    recalc();
})();
</script>
</body>
</html>
