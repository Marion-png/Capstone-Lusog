<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <link rel="shortcut icon" href="{{ asset('images/lusog-logo.png') }}">
    <title>Class Adviser Form - LUSOG</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @php $classAdviserCssPath = resource_path('css/class-adviser.css'); @endphp
    @if (file_exists($classAdviserCssPath))
        <style>{!! file_get_contents($classAdviserCssPath) !!}</style>
    @endif
</head>
<body>
<aside class="sidebar">
    <div class="sb-grid"></div>
    <div class="sb-logo"><img src="{{ asset('images/lusog-logo.png') }}" alt="LUSOG Logo"></div>
    <nav class="sb-nav">
        <a href="{{ route('dashboard.class-adviser') }}" class="sb-link">
            <svg class="sb-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <rect x="3" y="3" width="7" height="7"/>
                <rect x="14" y="3" width="7" height="4"/>
                <rect x="14" y="12" width="7" height="9"/>
                <rect x="3" y="14" width="7" height="7"/>
            </svg>
            <span class="sb-link-label">Dashboard</span>
        </a>
        <a href="{{ route('dashboard.class-adviser', ['tab' => 'form']) }}" class="sb-link">
            <svg class="sb-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
            <span class="sb-link-label">School Health Card Form</span>
        </a>
        <a href="{{ route('dashboard.class-adviser', ['tab' => 'saved']) }}" class="sb-link">
            <svg class="sb-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <line x1="8" y1="6" x2="21" y2="6"/>
                <line x1="8" y1="12" x2="21" y2="12"/>
                <line x1="8" y1="18" x2="21" y2="18"/>
                <line x1="3" y1="6" x2="3.01" y2="6"/>
                <line x1="3" y1="12" x2="3.01" y2="12"/>
                <line x1="3" y1="18" x2="3.01" y2="18"/>
            </svg>
            <span class="sb-link-label">My Students</span>
        </a>
        <a href="{{ route('dashboard.class-adviser.deworming') }}" class="sb-link active">
            <svg class="sb-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M10.5 6.5l7 7a2.12 2.12 0 1 1-3 3l-7-7a2.12 2.12 0 0 1 3-3z"></path>
                <path d="M8.5 8.5l-3 3"></path>
            </svg>
            <span class="sb-link-label">Deworming Request</span>
        </a>
    </nav>
    <div class="sb-user">
        <div class="sb-avatar">{{ substr(auth()->user()->name ?? 'CA',0,2) }}</div>
        <div class="sb-user-name">{{ auth()->user()->name ?? 'Class Adviser' }}</div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="sb-logout" title="Sign out" aria-label="Sign out">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <path d="M16 17l5-5-5-5"/>
                    <path d="M21 12H9"/>
                </svg>
            </button>
        </form>
    </div>
</aside>

<div class="main">
    <header class="top">
        <div class="topbar-breadcrumb crumb">
            <a href="{{ route('dashboard.class-adviser') }}" class="bc-home">Dashboard</a>
            <span class="bc-sep">></span>
            <span class="bc-current">Class Adviser &middot; Health Card</span>
        </div>
        <div class="topbar-chip chip"><div class="dot"></div>Class Adviser</div>
    </header>
    <div class="content">
        <h1 class="title">School Health Card</h1>
        <p class="sub">Fill the form below to submit a student health record to the School Nurse.</p>

        <section class="card section" style="margin-top:12px;">
            <div class="section">
                <form method="POST" action="{{ route('adviser.store') }}">
                    @csrf

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
                            <label>School ID</label>
                            <input type="text" name="school_id" required>
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
                                <option>Grade 11/SPED</option>
                                <option>Grade 12/SPED</option>
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

    if (!heightInput || !weightInput || !bmiOut || !bmiAgeOut || !hfaOut) {
        return;
    }

    const toNum = (value) => {
        const num = Number(value);
        return Number.isFinite(num) ? num : null;
    };

    const getAge = () => {
        const month = Number(monthInput?.value);
        const day = Number(dayInput?.value);
        const year = Number(yearInput?.value);
        if (!month || !day || !year) return null;
        const birth = new Date(year, month - 1, day);
        if (Number.isNaN(birth.getTime())) return null;
        const today = new Date();
        let age = today.getFullYear() - birth.getFullYear();
        const monthDiff = today.getMonth() - birth.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) age -= 1;
        return age >= 0 ? age : null;
    };

    const classifyBmiForAge = (bmi, age) => {
        if (bmi === null || age === null) return 'Not enough data';
        let severe = 13.0, wasted = 14.5, overweight = 21.0;
        if (age <= 10) { severe = 12.8; wasted = 14.2; overweight = 20.5; }
        else if (age >= 15) { severe = 13.5; wasted = 15.2; overweight = 22.5; }
        if (bmi < severe) return 'Severely Wasted';
        if (bmi < wasted) return 'Wasted';
        if (bmi > overweight) return 'Overweight';
        return 'Normal';
    };

    const classifyHeightForAge = (heightCm, age) => {
        if (heightCm === null || age === null) return 'Not enough data';
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
        if (!node) return;
        node.addEventListener('input', recalc);
        node.addEventListener('change', recalc);
    });
    recalc();
})();
</script>
</body>
</html>
