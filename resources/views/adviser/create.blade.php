<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/sigla-mark.png') }}">
    <link rel="shortcut icon" href="{{ asset('images/sigla-mark.png') }}">
    <title>Class Adviser Form - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @php $classAdviserCssPath = resource_path('css/class-adviser.css'); @endphp
    @if (file_exists($classAdviserCssPath))
        <style>{!! file_get_contents($classAdviserCssPath) !!}</style>
    @endif
    {{-- One shared palette for pages not yet on lusog-theme.css. Loaded
         last so it overrides this page's own :root colours. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-palette.css')) !!}</style>
</head>
<body>
@include('partials.adviser-sidebar', ['active' => 'form'])

<div class="asb-main">
    @include('partials.adviser-topbar', ['breadcrumb' => 'School Health Card'])
    <div class="content">
        <h1 class="title">School Health Card</h1>
        <p class="sub">Fill the form below to submit a student health record to the School Nurse.</p>

        <section class="card section" style="margin-top:12px;">
            <div class="section">
                <form method="POST" action="{{ route('adviser.store') }}">
                    @csrf
                    <input type="hidden" name="form_mode" value="enrol">

                    <h3>Student Info</h3>
                    <div class="form-grid">
                        <div class="field">
                            <label>Last Name</label>
                            <input type="text" name="last_name" required>
                        </div>
                        <div class="field">
                            <label>First Name</label>
                            <input type="text" name="first_name" required>
                        </div>
                        <div class="field">
                            <label>Middle Name</label>
                            <input type="text" name="middle_name">
                        </div>

                        <div class="field">
                            <label>LRN</label>
                            <input type="text" name="lrn" required>
                        </div>
                        <div class="field">
                            <label>Birth Month</label>
                            <input type="text" name="birth_month" placeholder="MM" required>
                        </div>
                        <div class="field">
                            <label>Birth Day</label>
                            <input type="text" name="birth_day" placeholder="DD" required>
                        </div>
                        <div class="field">
                            <label>Birth Year</label>
                            <input type="text" name="birth_year" placeholder="YYYY" required>
                        </div>
                        <div class="field">
                            <label>Gender</label>
                            <select name="gender" required>
                                <option value="">Select Gender</option>
                                <option>Male</option>
                                <option>Female</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Birthplace</label>
                            <input type="text" name="birthplace" required>
                        </div>
                        <div class="field">
                            <label>Parent/Guardian</label>
                            <input type="text" name="parent_guardian" required>
                        </div>
                        <div class="field">
                            <label>Address</label>
                            <input type="text" name="address" required>
                        </div>
                        <div class="field">
                            <label>Region</label>
                            <input type="text" name="region" required>
                        </div>
                        <div class="field">
                            <label>Division</label>
                            <input type="text" name="division" required>
                        </div>
                        <div class="field">
                            <label>Telephone No.</label>
                            <input type="text" name="telephone_no" required>
                        </div>
                    </div>

                    <h3 style="margin-top:12px;">Health Measurements</h3>
                    <div class="form-grid">
                        <div class="field">
                            <label>Height (cm)</label>
                            <input type="text" name="height_cm" required>
                        </div>
                        <div class="field">
                            <label>Weight (kg)</label>
                            <input type="text" name="weight_kg" id="proto_weight_kg" required>
                        </div>
                        <div class="field">
                            <label>BMI (Auto)</label>
                            <input type="text" id="proto_bmi_value" readonly>
                        </div>
                        <div class="field">
                            <label>Nutritional Status (BMI for Age)</label>
                            <input type="text" id="proto_nutritional_status_bmi_for_age" readonly>
                        </div>
                        <div class="field">
                            <label>Nutritional Status (Height for Age)</label>
                            <input type="text" id="proto_nutritional_status_height_for_age" readonly>
                        </div>
                        <div class="field">
                            <label>Grade Level</label>
                            <select name="grade_level" required>
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
                                {{-- Senior High is not offered: the feeding
                                     programme covers Grades 7-10, and a learner
                                     enrolled above that would be measured here
                                     and then dropped by every SBFP report. --}}
                            </select>
                        </div>
                        <div class="field">
                            <label>Section</label>
                            <input type="text" name="section" placeholder="e.g., SPED-A" required>
                        </div>
                    </div>

                    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;">
                        <button type="submit" class="btn">Submit to School Nurse</button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</div>

@include('partials.who-growth-script')
<script>
(() => {
    const heightInput = document.querySelector('input[name="height_cm"]');
    const weightInput = document.getElementById('proto_weight_kg');
    const monthInput = document.querySelector('input[name="birth_month"]');
    const dayInput = document.querySelector('input[name="birth_day"]');
    const yearInput = document.querySelector('input[name="birth_year"]');
    const genderInput = document.querySelector('select[name="gender"]');
    const bmiOut = document.getElementById('proto_bmi_value');
    const bmiAgeOut = document.getElementById('proto_nutritional_status_bmi_for_age');
    const hfaOut = document.getElementById('proto_nutritional_status_height_for_age');

    if (!heightInput || !weightInput || !bmiOut || !bmiAgeOut || !hfaOut || !window.WhoGrowth) {
        return;
    }

    const toNum = (value) => {
        const num = Number(value);
        return Number.isFinite(num) ? num : null;
    };

    // Age in months on today's weigh-in: the WHO 2007 reference is read by
    // sex and month, never by completed years.
    const getAgeInMonths = () => {
        const month = Number(monthInput?.value);
        const day = Number(dayInput?.value);
        const year = Number(yearInput?.value);
        if (!month || !day || !year) return null;
        return window.WhoGrowth.ageInMonths(new Date(year, month - 1, day));
    };

    const recalc = () => {
        const heightCm = toNum(heightInput.value);
        const weightKg = toNum(weightInput.value);
        const age = getAgeInMonths();
        const gender = genderInput ? genderInput.value : '';
        // A reading no learner could have holds the form back with the
        // server's own message (MeasurementPlausibility).
        const found = window.WhoGrowth.problems(heightCm, weightKg, age, gender);
        heightInput.setCustomValidity(found.height);
        weightInput.setCustomValidity(found.weight);
        if (!heightCm || !weightKg || heightCm <= 0 || weightKg <= 0) {
            bmiOut.value = '';
            bmiAgeOut.value = window.WhoGrowth.bmiForAge(null, age, gender);
            hfaOut.value = found.height ? '' : window.WhoGrowth.heightForAge(heightCm, age, gender);
            return;
        }
        const bmi = weightKg / Math.pow(heightCm / 100, 2);
        bmiOut.value = bmi.toFixed(2);
        bmiAgeOut.value = found.weight ? '' : window.WhoGrowth.bmiForAge(bmi, age, gender);
        hfaOut.value = found.height ? '' : window.WhoGrowth.heightForAge(heightCm, age, gender);
    };

    [heightInput, weightInput, monthInput, dayInput, yearInput, genderInput].forEach((node) => {
        if (!node) return;
        node.addEventListener('input', recalc);
        node.addEventListener('change', recalc);
    });
    recalc();
})();
</script>
</body>
</html>
