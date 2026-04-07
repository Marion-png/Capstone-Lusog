<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <link rel="shortcut icon" href="{{ asset('images/lusog-logo.png') }}">
    <title>Class Adviser Dashboard - LUSOG</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite('resources/css/class-adviser.css')
</head>
<body>
<aside class="sidebar">
    <div class="sb-logo"><img src="{{ asset('images/lusog-logo.png') }}" alt="LUSOG Logo"></div>
    <nav class="sb-nav">
        <a href="#" class="sb-link active js-proto-nav" data-target="prototype-form-panel">
            <svg class="sb-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
            <span class="sb-link-label">School Health Card Form</span>
        </a>
        <a href="#" class="sb-link js-proto-nav" data-target="prototype-saved-panel">
            <svg class="sb-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <line x1="8" y1="6" x2="21" y2="6"/>
                <line x1="8" y1="12" x2="21" y2="12"/>
                <line x1="8" y1="18" x2="21" y2="18"/>
                <line x1="3" y1="6" x2="3.01" y2="6"/>
                <line x1="3" y1="12" x2="3.01" y2="12"/>
                <line x1="3" y1="18" x2="3.01" y2="18"/>
            </svg>
            <span class="sb-link-label">Saved Submissions</span>
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
    <header class="top"><div class="crumb">Dashboard > Class Adviser</div></header>
    <div class="content">
        @php
            $assignedGradeLevel = session('assigned_grade_level');
            $assignedSection = session('assigned_section');
            $assignedClassLabel = ($assignedGradeLevel && $assignedSection)
                ? ($assignedGradeLevel . ' / ' . $assignedSection)
                : 'Not Assigned';
        @endphp
        <h1 class="title">Class Adviser <i>Encoding Workspace</i></h1>
        <p class="sub">School Health Card prototype workflow for adviser submission and nurse follow-up.</p>
        <div class="assigned-class-banner">
            <div>
                <div class="assigned-class-label">Assigned Class</div>
                <div class="assigned-class-value">{{ $assignedClassLabel }}</div>
            </div>
            <div class="assigned-class-note">Only learners from this grade and section are shown and can be encoded.</div>
        </div>

        @if (session('success'))
            <div class="toast-success" id="successToast" role="status" aria-live="polite">
                <span>{{ session('success') }}</span>
                <button type="button" class="toast-close" id="toastClose" aria-label="Close">x</button>
            </div>
        @endif
        @if ($errors->any())
            <div class="flash flash-err">Incomplete fields detected. Please complete all required entries before submitting.</div>
        @endif

        @php
            $allPrototypeRecords = session('school_health_card_records', []);
            $prototypeRecords = collect($allPrototypeRecords)->filter(function ($row) use ($assignedGradeLevel, $assignedSection) {
                if (!$assignedGradeLevel || !$assignedSection) {
                    return true;
                }

                return (string) ($row['grade_level'] ?? '') === (string) $assignedGradeLevel
                    && (string) ($row['section'] ?? '') === (string) $assignedSection;
            });
        @endphp

        <section id="prototype-saved-panel" class="card section section-panel" style="margin-top:12px;">
            <h3>Saved School Health Card Submissions</h3>
            <table>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>LRN</th>
                        <th>Grade Level</th>
                        <th>Section</th>
                        <th>Height</th>
                        <th>Weight</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($prototypeRecords as $index => $prototypeRecord)
                        @php
                            $middle = trim((string) ($prototypeRecord['middle_name'] ?? ''));
                            $middleInitial = $middle !== '' ? (' ' . strtoupper(substr($middle, 0, 1)) . '.') : '';
                            $fullName = trim(($prototypeRecord['last_name'] ?? '') . ', ' . ($prototypeRecord['first_name'] ?? '') . $middleInitial);
                            $isExamined = !empty($prototypeRecord['examination']);
                        @endphp
                        <tr>
                            <td>{{ $fullName }}</td>
                            <td>{{ $prototypeRecord['lrn'] ?? '-' }}</td>
                            <td>{{ $prototypeRecord['grade_level'] ?? '-' }}</td>
                            <td>{{ $prototypeRecord['section'] ?? '-' }}</td>
                            <td>{{ $prototypeRecord['height_cm'] ?? '-' }}</td>
                            <td>{{ $prototypeRecord['weight_kg'] ?? '-' }}</td>
                            <td>
                                @if ($isExamined)
                                    <span class="badge ok">Examined by Nurse</span>
                                @else
                                    <span class="badge warn">Pending Nurse Examination</span>
                                @endif
                            </td>
                            <td>
                                <button type="button" class="profile-open-btn js-profile-open" data-route="{{ route('nurse.examine', $index) }}" data-record='@json($prototypeRecord)'>Student Profile</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="muted">No School Health Card prototype submissions yet for your assigned class.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section id="prototype-form-panel" class="card section section-panel active" style="margin-top:12px;">
            <div class="add-head">
                <div class="add-head-left">
                    <a href="{{ route('dashboard.class-adviser') }}" class="add-back" aria-label="Back to class adviser dashboard">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
                    </a>
                    <div>
                        <h3 class="add-title">Add New Student</h3>
                        <p class="add-sub">Enter basic information, weight, and height. BMI and nutritional status will be auto-calculated.</p>
                    </div>
                </div>
                <div class="add-date" id="currentDate">-</div>
            </div>

            <div class="class-box">
                <div class="class-box-row"><span>Your assigned class:</span><span class="class-box-value" id="assignedClassDisplay">{{ $assignedClassLabel }}</span></div>
                <div class="class-box-note">Students will be automatically added to this grade and section.</div>
            </div>

            <form id="studentForm" method="POST" action="{{ route('adviser.store') }}" autocomplete="off">
                @csrf
                <input id="proto_birth_month" name="birth_month" type="hidden" value="{{ old('birth_month') }}">
                <input id="proto_birth_day" name="birth_day" type="hidden" value="{{ old('birth_day') }}">
                <input id="proto_birth_year" name="birth_year" type="hidden" value="{{ old('birth_year') }}">
                <input id="proto_height_cm" name="height_cm" type="hidden" value="{{ old('height_cm') }}">
                <input type="hidden" name="grade_level" value="{{ $assignedGradeLevel ?? '' }}">
                <input type="hidden" name="section" value="{{ $assignedSection ?? '' }}">

                <div class="student-section">
                    <h4>Student Information</h4>
                    <div class="student-grid">
                        <div class="field"><label for="proto_last_name">Last Name <span style="color:#ef4444">*</span></label><input id="proto_last_name" name="last_name" type="text" placeholder="e.g., Dela Cruz" value="{{ old('last_name') }}" required></div>
                        <div class="field"><label for="proto_first_name">First Name <span style="color:#ef4444">*</span></label><input id="proto_first_name" name="first_name" type="text" placeholder="e.g., Maria" value="{{ old('first_name') }}" required></div>
                        <div class="field"><label for="proto_middle_name">Middle Name</label><input id="proto_middle_name" name="middle_name" type="text" placeholder="e.g., Santos" value="{{ old('middle_name') }}"></div>
                        <div class="field"><label for="proto_lrn">LRN <span style="color:#ef4444">*</span></label><input id="proto_lrn" name="lrn" type="text" placeholder="12-digit Learner Reference Number" value="{{ old('lrn') }}" inputmode="numeric" required></div>
                        <div class="field"><label for="birthDate">Date of Birth <span style="color:#ef4444">*</span></label><input id="birthDate" type="date" value="{{ old('birth_year') && old('birth_month') && old('birth_day') ? old('birth_year') . '-' . str_pad(old('birth_month'), 2, '0', STR_PAD_LEFT) . '-' . str_pad(old('birth_day'), 2, '0', STR_PAD_LEFT) : '' }}" required></div>
                        <div class="field"><label for="proto_birthplace">Birthplace</label><input id="proto_birthplace" name="birthplace" type="text" placeholder="City/Municipality of birth" value="{{ old('birthplace') }}" required></div>
                        <div class="field full"><label for="gender">Gender <span style="color:#ef4444">*</span></label><select id="gender" required><option value="">Select Gender</option><option>Male</option><option>Female</option></select></div>
                    </div>
                </div>

                <div class="student-section">
                    <h4>Parent/Guardian Information</h4>
                    <div class="student-grid">
                        <div class="field full"><label for="proto_parent_guardian">Parent/Guardian Name</label><input id="proto_parent_guardian" name="parent_guardian" type="text" placeholder="Full name of parent or guardian" value="{{ old('parent_guardian') }}" required></div>
                        <div class="field"><label for="proto_telephone_no">Contact Number</label><input id="proto_telephone_no" name="telephone_no" type="text" placeholder="e.g., 09171234567" value="{{ old('telephone_no') }}" inputmode="tel" required></div>
                        <div class="field full"><label for="proto_address">Address</label><textarea id="proto_address" name="address" rows="2" required>{{ old('address') }}</textarea></div>
                    </div>
                </div>

                <div class="student-section">
                    <h4>Health Data (Baseline)</h4>
                    <div class="student-grid" style="margin-bottom:10px;">
                        <div class="field"><label for="weight">Weight (kg) <span style="color:#ef4444">*</span></label><input id="weight" name="weight_kg" type="number" step="0.1" min="0" max="200" placeholder="e.g., 34" value="{{ old('weight_kg') }}" required><div class="muted" style="font-size:.7rem;">Valid range: 0 - 200 kg</div></div>
                        <div class="field"><label for="height">Height (m) <span style="color:#ef4444">*</span></label><input id="height" type="number" step="0.01" min="0.50" max="2.50" placeholder="e.g., 1.27" value="{{ old('height_cm') ? number_format(old('height_cm') / 100, 2, '.', '') : '' }}" required><div class="muted" style="font-size:.7rem;">Convert cm to m: 127 cm = 1.27 m | Valid range: 0.50 - 2.50 m</div></div>
                    </div>

                    <div class="calc-box">
                        <div style="font-size:.78rem;color:#48685a;font-weight:700;">Auto-Calculated Results</div>
                        <div class="calc-grid">
                            <div class="calc-item"><div class="label">(Height)^2</div><div class="value" id="heightSquared">-</div></div>
                            <div class="calc-item"><div class="label">BMI (kg/m^2)</div><div class="value" id="bmiDisplay">-</div></div>
                            <div class="calc-item"><div class="label">Nutritional Status</div><div class="value" id="nutriStatusDisplay">-</div></div>
                            <div class="calc-item"><div class="label">Height-for-Age</div><div class="value" id="hfaDisplay">-</div></div>
                        </div>
                        <div style="margin-top:10px;padding-top:8px;border-top:1px solid #dbe9e1;font-size:.71rem;color:#6f8c7a;line-height:1.4;">
                            <div style="margin-bottom:4px;font-weight:700;">Classification Legend:</div>
                            <div><b>BMI-for-Age:</b> Normal / Wasted / Severely Wasted / Underweight / Overweight / Obese</div>
                            <div><b>Height-for-Age:</b> Normal / Stunted / Severely Stunted / Tall</div>
                        </div>
                    </div>
                </div>

                <div class="student-grid" style="display:none;">
                    <input id="proto_school_id" name="school_id" type="hidden" value="{{ old('school_id', 'DCNHS-001') }}">
                    <input id="proto_region" name="region" type="hidden" value="{{ old('region', 'NCR') }}">
                    <input id="proto_division" name="division" type="hidden" value="{{ old('division', 'Quezon City') }}">
                </div>

                <div class="note-box">After submission, the school nurse will complete the remaining SHD form fields. You can view complete updates once nurse review is done.</div>

                <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:10px;">
                    <button type="button" class="btn btn-secondary" id="cancelAddStudent">Cancel</button>
                    <button type="button" class="btn" id="reviewSubmitBtn">Review &amp; Submit</button>
                </div>
            </form>
        </section>
    </div>
</div>

<div id="confirmationModal" class="confirm-overlay" aria-hidden="true">
    <div class="confirm-modal" role="dialog" aria-modal="true" aria-label="Confirm student information">
        <div class="confirm-head">
            <div class="confirm-title">Confirm Student Information</div>
            <button type="button" class="confirm-close" id="confirmCloseBtn">x</button>
        </div>
        <div class="confirm-body">
            <div class="confirm-info">Please review the information before submitting. The school nurse will complete the remaining health record.</div>
            <div id="summaryContainer"></div>
            <div class="confirm-actions">
                <button type="button" class="btn btn-secondary" id="confirmEditBtn">Edit</button>
                <button type="button" class="btn" id="confirmSubmitBtn">Confirm &amp; Submit</button>
            </div>
        </div>
    </div>
</div>

<div class="profile-backdrop" id="profileBackdrop" aria-hidden="true">
    <div class="profile-modal" role="dialog" aria-modal="true" aria-label="Student Health Record Preview">
        <div class="profile-head">
            <div>
                <div class="profile-title" id="pName">-</div>
                <div class="profile-meta" id="pLrn">LRN: -</div>
            </div>
            <div class="profile-right">Grade &amp; Section<b id="pGrade">-</b></div>
        </div>
        <div class="profile-tabs">
            <button type="button" class="profile-tab active" data-panel="p-demographics">Demographics</button>
            <button type="button" class="profile-tab" data-panel="p-shd">SHD Form 2</button>
            <button type="button" class="profile-tab" data-panel="p-growth">Growth &amp; Nutrition</button>
            <button type="button" class="profile-tab" data-panel="p-alerts">Medical Alerts</button>
            <button type="button" class="profile-tab" data-panel="p-timeline">Health Timeline</button>
        </div>
        <div class="profile-body">
            <section id="p-demographics" class="profile-panel active">
                <div class="profile-grid">
                    <div class="profile-block">
                        <h4>Personal Information</h4>
                        <div class="kv"><div class="k">Full Name:</div><div class="v" id="pdName">-</div></div>
                        <div class="kv"><div class="k">LRN:</div><div class="v" id="pdLrn">-</div></div>
                        <div class="kv"><div class="k">Date of Birth:</div><div class="v" id="pdDob">-</div></div>
                        <div class="kv"><div class="k">Birthplace:</div><div class="v" id="pdBirthplace">-</div></div>
                        <div class="kv"><div class="k">Address:</div><div class="v" id="pdAddress">-</div></div>
                    </div>
                    <div class="profile-block">
                        <h4>Parent/Guardian Information</h4>
                        <div class="kv"><div class="k">Parent/Guardian:</div><div class="v" id="pdGuardian">-</div></div>
                        <div class="kv"><div class="k">Contact Number:</div><div class="v" id="pdContact">-</div></div>
                        <div class="kv"><div class="k">School ID:</div><div class="v" id="pdSchoolId">-</div></div>
                        <div class="kv"><div class="k">Region/Division:</div><div class="v" id="pdRegionDivision">-</div></div>
                    </div>
                </div>
            </section>
            <section id="p-shd" class="profile-panel">
                <div class="profile-block">
                    <h4>SHD Form 2 Snapshot</h4>
                    <div class="kv"><div class="k">Grade Level:</div><div class="v" id="psGrade">-</div></div>
                    <div class="kv"><div class="k">Status:</div><div class="v" id="psStatus">-</div></div>
                    <div class="kv"><div class="k">BMI:</div><div class="v" id="psBmi">-</div></div>
                    <div class="kv"><div class="k">BMI for Age:</div><div class="v" id="psBmiForAge">-</div></div>
                    <div class="kv"><div class="k">Height for Age:</div><div class="v" id="psHeightForAge">-</div></div>
                </div>
            </section>
            <section id="p-growth" class="profile-panel">
                <div class="profile-block">
                    <h4>Growth &amp; Nutrition</h4>
                    <div class="growth-wrap">
                        <div class="growth-card">
                            <h5>BMI Trend: Baseline to Endline</h5>
                            <svg class="growth-chart" viewBox="0 0 380 170" preserveAspectRatio="none" aria-label="Baseline to endline BMI chart">
                                <line class="growth-chart-axis" x1="40" y1="140" x2="350" y2="140"></line>
                                <line class="growth-chart-axis" x1="40" y1="20" x2="40" y2="140"></line>
                                <polyline id="pgTrendLine" class="growth-chart-line" points="80,120 300,100"></polyline>
                                <circle id="pgBaseDot" class="growth-chart-dot" cx="80" cy="120" r="5"></circle>
                                <circle id="pgEndDot" class="growth-chart-dot end" cx="300" cy="100" r="5"></circle>
                                <text id="pgBasePointText" class="growth-point-label" x="54" y="112">Baseline</text>
                                <text id="pgEndPointText" class="growth-point-label" x="276" y="92">Endline</text>
                            </svg>
                            <div class="growth-metrics">
                                <div class="growth-metric">
                                    <div class="lbl">Baseline BMI</div>
                                    <div class="val" id="pgBaseBmi">-</div>
                                </div>
                                <div class="growth-metric">
                                    <div class="lbl">Endline BMI</div>
                                    <div class="val" id="pgEndBmi">-</div>
                                </div>
                            </div>
                        </div>
                        <div class="growth-card">
                            <h5>Monthly Attendance</h5>
                            <div class="attendance-bars" id="pgAttendanceBars" aria-label="Monthly attendance chart"></div>
                            <div class="kv" style="margin-top:8px"><div class="k">Latest Attendance Month:</div><div class="v" id="pgAttendanceLatest">-</div></div>
                            <div class="kv"><div class="k">Total Recorded Sessions:</div><div class="v" id="pgAttendanceTotal">-</div></div>
                        </div>
                    </div>
                </div>
            </section>
            <section id="p-alerts" class="profile-panel">
                <div class="profile-block">
                    <h4>Medical Alerts</h4>
                    <div class="kv"><div class="k">Current Note:</div><div class="v" id="paStatus">Pending nurse review.</div></div>
                </div>
            </section>
            <section id="p-timeline" class="profile-panel">
                <div class="profile-block">
                    <h4>Health Timeline</h4>
                    <div class="kv"><div class="k">Submission:</div><div class="v">Class Adviser submitted this form.</div></div>
                    <div class="kv"><div class="k">Next Step:</div><div class="v" id="ptNext">Nurse examination pending.</div></div>
                </div>
            </section>
        </div>
        <div class="profile-actions">
            <button type="button" class="btn btn-secondary" id="profileClose">Close</button>
            <a href="#" class="btn" id="profileFillLink">Fill Medical Record</a>
        </div>
    </div>
</div>
<script>
(() => {
    const navLinks = Array.from(document.querySelectorAll('.js-proto-nav'));
    const tabPanels = Array.from(document.querySelectorAll('.section-panel'));

    if (!navLinks.length || !tabPanels.length) {
        return;
    }

    navLinks.forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            const targetId = link.getAttribute('data-target');

            navLinks.forEach((navLink) => {
                navLink.classList.remove('active');
            });

            tabPanels.forEach((panel) => {
                panel.classList.remove('active');
            });

            link.classList.add('active');

            const targetPanel = document.getElementById(targetId);
            if (targetPanel) {
                targetPanel.classList.add('active');
            }
        });
    });
})();

(() => {
    const toast = document.getElementById('successToast');
    if (!toast) {
        return;
    }

    const closeBtn = document.getElementById('toastClose');
    const dismiss = () => {
        toast.style.display = 'none';
    };

    if (closeBtn) {
        closeBtn.addEventListener('click', dismiss);
    }

    window.setTimeout(dismiss, 3200);
})();

(() => {
    const birthDate = document.getElementById('birthDate');
    const birthMonth = document.getElementById('proto_birth_month');
    const birthDay = document.getElementById('proto_birth_day');
    const birthYear = document.getElementById('proto_birth_year');

    if (!birthDate || !birthMonth || !birthDay || !birthYear) {
        return;
    }

    const syncBirthParts = () => {
        if (!birthDate.value) {
            birthMonth.value = '';
            birthDay.value = '';
            birthYear.value = '';
            return;
        }

        const parts = birthDate.value.split('-');
        birthYear.value = parts[0] || '';
        birthMonth.value = parts[1] || '';
        birthDay.value = parts[2] || '';
    };

    birthDate.addEventListener('change', syncBirthParts);
    birthDate.addEventListener('input', syncBirthParts);
    syncBirthParts();
})();

(() => {
    const heightInput = document.getElementById('height');
    const weightInput = document.getElementById('weight');
    const birthDate = document.getElementById('birthDate');
    const heightCmHidden = document.getElementById('proto_height_cm');
    const heightSquaredOut = document.getElementById('heightSquared');
    const bmiOut = document.getElementById('bmiDisplay');
    const bmiAgeOut = document.getElementById('nutriStatusDisplay');
    const hfaOut = document.getElementById('hfaDisplay');

    if (!heightInput || !weightInput || !birthDate || !heightCmHidden || !heightSquaredOut || !bmiOut || !bmiAgeOut || !hfaOut) {
        return;
    }

    const toNum = (value) => {
        const num = Number(value);
        return Number.isFinite(num) ? num : null;
    };

    const getAge = () => {
        if (!birthDate.value) {
            return null;
        }

        const date = new Date(birthDate.value);
        if (Number.isNaN(date.getTime())) {
            return null;
        }

        const today = new Date();
        let age = today.getFullYear() - date.getFullYear();
        const monthDiff = today.getMonth() - date.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < date.getDate())) {
            age -= 1;
        }

        return age >= 0 ? age : null;
    };

    const classifyBmiForAge = (bmi, age) => {
        if (bmi === null || age === null) {
            return 'Not enough data';
        }

        if (bmi < 16.0) return 'Severely Wasted';
        if (bmi < 17.0) return 'Wasted';
        if (bmi < 18.5) return 'Underweight';
        if (bmi < 25.0) return 'Normal';
        if (bmi < 30.0) return 'Overweight';
        return 'Obese';
    };

    const classifyHeightForAge = (heightM, age) => {
        if (heightM === null || age === null) {
            return 'Not enough data';
        }

        if (heightM < 1.20) return 'Severely Stunted';
        if (heightM < 1.30) return 'Stunted';
        if (heightM > 1.70) return 'Tall';
        return 'Normal';
    };

    const recalc = () => {
        const heightM = toNum(heightInput.value);
        const weightKg = toNum(weightInput.value);
        const age = getAge();

        if (!heightM || !weightKg || heightM <= 0 || weightKg <= 0 || heightM > 2.5) {
            heightCmHidden.value = '';
            heightSquaredOut.textContent = '-';
            bmiOut.textContent = '-';
            bmiAgeOut.textContent = 'Not enough data';
            hfaOut.textContent = classifyHeightForAge(heightM, age);
            return;
        }

        const heightCm = heightM * 100;
        heightCmHidden.value = heightCm.toFixed(2);

        const heightSquared = heightM * heightM;
        const bmi = weightKg / heightSquared;

        heightSquaredOut.textContent = heightSquared.toFixed(4);
        bmiOut.textContent = bmi.toFixed(2);
        bmiAgeOut.textContent = classifyBmiForAge(bmi, age);
        hfaOut.textContent = classifyHeightForAge(heightM, age);
    };

    heightInput.addEventListener('input', recalc);
    weightInput.addEventListener('input', recalc);
    birthDate.addEventListener('input', recalc);
    birthDate.addEventListener('change', recalc);
    recalc();
})();

(() => {
    const node = document.getElementById('currentDate');
    if (!node) {
        return;
    }

    node.textContent = new Date().toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
})();

(() => {
    const form = document.getElementById('studentForm');
    const reviewBtn = document.getElementById('reviewSubmitBtn');
    const cancelBtn = document.getElementById('cancelAddStudent');
    const modal = document.getElementById('confirmationModal');
    const closeBtn = document.getElementById('confirmCloseBtn');
    const editBtn = document.getElementById('confirmEditBtn');
    const submitBtn = document.getElementById('confirmSubmitBtn');
    const summary = document.getElementById('summaryContainer');

    if (!form || !reviewBtn || !modal || !closeBtn || !editBtn || !submitBtn || !summary) {
        return;
    }

    const byId = (id) => document.getElementById(id);

    const openModal = () => {
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
    };

    const closeModal = () => {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
    };

    const buildSummary = () => {
        const fullName = `${byId('proto_last_name')?.value || ''}, ${byId('proto_first_name')?.value || ''} ${byId('proto_middle_name')?.value || ''}`.trim();
        const assignedClass = byId('assignedClassDisplay')?.textContent || '-';

        const blocks = [
            {
                title: 'Student Information',
                rows: [
                    ['Full Name', fullName || '-'],
                    ['LRN', byId('proto_lrn')?.value || '-'],
                    ['Date of Birth', byId('birthDate')?.value || '-'],
                    ['Gender', byId('gender')?.value || '-'],
                    ['Grade & Section', assignedClass],
                ],
            },
            {
                title: 'Parent/Guardian Information',
                rows: [
                    ['Parent/Guardian', byId('proto_parent_guardian')?.value || 'Not provided'],
                    ['Contact Number', byId('proto_telephone_no')?.value || 'Not provided'],
                    ['Address', byId('proto_address')?.value || 'Not provided'],
                ],
            },
            {
                title: 'Health Data (Baseline)',
                rows: [
                    ['Weight', `${byId('weight')?.value || '-'} kg`],
                    ['Height', `${byId('height')?.value || '-'} m`],
                    ['(Height)^2', byId('heightSquared')?.textContent || '-'],
                    ['BMI', `${byId('bmiDisplay')?.textContent || '-'} kg/m^2`],
                    ['Nutritional Status', byId('nutriStatusDisplay')?.textContent || '-'],
                    ['Height-for-Age', byId('hfaDisplay')?.textContent || '-'],
                ],
            },
        ];

        summary.innerHTML = '';
        blocks.forEach((block) => {
            const card = document.createElement('div');
            card.className = 'summary-card';
            const rowsHtml = block.rows
                .map(([k, v]) => `<div class="summary-k">${k}:</div><div class="summary-v">${v}</div>`)
                .join('');
            card.innerHTML = `<h5>${block.title}</h5><div class="summary-grid">${rowsHtml}</div>`;
            summary.appendChild(card);
        });
    };

    reviewBtn.addEventListener('click', () => {
        if (!form.reportValidity()) {
            return;
        }

        buildSummary();
        openModal();
    });

    cancelBtn?.addEventListener('click', () => {
        form.reset();
        byId('heightSquared').textContent = '-';
        byId('bmiDisplay').textContent = '-';
        byId('nutriStatusDisplay').textContent = '-';
        byId('hfaDisplay').textContent = '-';
    });

    closeBtn.addEventListener('click', closeModal);
    editBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    submitBtn.addEventListener('click', () => {
        closeModal();
        form.requestSubmit();
    });
})();

(() => {
    const openButtons = Array.from(document.querySelectorAll('.js-profile-open'));
    const backdrop = document.getElementById('profileBackdrop');
    const closeBtn = document.getElementById('profileClose');
    const fillLink = document.getElementById('profileFillLink');

    if (!openButtons.length || !backdrop || !closeBtn || !fillLink) {
        return;
    }

    const setText = (id, value) => {
        const node = document.getElementById(id);
        if (node) {
            node.textContent = value && String(value).trim() !== '' ? String(value) : '-';
        }
    };

    const toNumber = (value) => {
        const num = Number(value);
        return Number.isFinite(num) ? num : null;
    };

    const computeBmi = (heightCm, weightKg) => {
        const height = toNumber(heightCm);
        const weight = toNumber(weightKg);
        if (!height || !weight || height <= 0 || weight <= 0) {
            return null;
        }

        const meters = height / 100;
        return weight / (meters * meters);
    };

    const renderTrendGraph = (baselineBmi, endlineBmi) => {
        const line = document.getElementById('pgTrendLine');
        const baseDot = document.getElementById('pgBaseDot');
        const endDot = document.getElementById('pgEndDot');
        const baseText = document.getElementById('pgBasePointText');
        const endText = document.getElementById('pgEndPointText');

        if (!line || !baseDot || !endDot || !baseText || !endText) {
            return;
        }

        const safeBase = baselineBmi ?? 0;
        const safeEnd = endlineBmi ?? safeBase;
        const maxBmi = Math.max(30, safeBase + 2, safeEnd + 2);
        const toY = (value) => {
            const ratio = Math.max(0, Math.min(1, value / maxBmi));
            return 140 - (ratio * 110);
        };

        const baseY = toY(safeBase);
        const endY = toY(safeEnd);

        line.setAttribute('points', `80,${baseY} 300,${endY}`);
        baseDot.setAttribute('cy', String(baseY));
        endDot.setAttribute('cy', String(endY));
        baseText.setAttribute('y', String(baseY - 10));
        endText.setAttribute('y', String(endY - 10));
        baseText.textContent = `Baseline ${baselineBmi ? baselineBmi.toFixed(1) : '-'}`;
        endText.textContent = `Endline ${endlineBmi ? endlineBmi.toFixed(1) : '-'}`;
    };

    const renderAttendanceBars = (attendanceByMonth) => {
        const barsWrap = document.getElementById('pgAttendanceBars');
        const latestNode = document.getElementById('pgAttendanceLatest');
        const totalNode = document.getElementById('pgAttendanceTotal');

        if (!barsWrap || !latestNode || !totalNode) {
            return;
        }

        const entries = Object.entries(attendanceByMonth || {})
            .filter(([, count]) => Number(count) >= 0)
            .sort(([a], [b]) => a.localeCompare(b));

        barsWrap.innerHTML = '';

        const chartEntries = entries.length ? entries.slice(-6) : [[new Date().toISOString().slice(0, 7), 0]];
        const maxCount = Math.max(1, ...chartEntries.map(([, count]) => Number(count) || 0));
        const total = chartEntries.reduce((sum, [, count]) => sum + (Number(count) || 0), 0);

        chartEntries.forEach(([month, count]) => {
            const value = Number(count) || 0;
            const monthLabel = month.slice(2);
            const height = Math.max(6, Math.round((value / maxCount) * 90));

            const col = document.createElement('div');
            col.className = 'attendance-col';
            col.innerHTML = `<div class="attendance-bar" style="height:${height}px"></div><div class="attendance-month">${monthLabel}</div><div class="attendance-val">${value}</div>`;
            barsWrap.appendChild(col);
        });

        const latest = chartEntries[chartEntries.length - 1];
        setText('pgAttendanceLatest', `${latest[0]} (${latest[1]} session${Number(latest[1]) === 1 ? '' : 's'})`);
        setText('pgAttendanceTotal', `${total}`);
    };

    const openProfile = (record, route) => {
        const fullName = [record.last_name, ',', record.first_name, record.middle_name ? (' ' + String(record.middle_name).charAt(0).toUpperCase() + '.') : '']
            .join(' ')
            .replace(' ,', ',')
            .replace(/\s+/g, ' ')
            .trim();
        const dob = [record.birth_year, record.birth_month, record.birth_day].filter(Boolean).join('-');
        const examined = record.examination && Object.keys(record.examination).length > 0;

        setText('pName', fullName || '-');
        setText('pLrn', 'LRN: ' + (record.lrn || '-'));
        setText('pGrade', [record.grade_level, record.section].filter(Boolean).join(' / ') || '-');

        setText('pdName', fullName || '-');
        setText('pdLrn', record.lrn || '-');
        setText('pdDob', dob || '-');
        setText('pdBirthplace', record.birthplace || '-');
        setText('pdAddress', record.address || '-');
        setText('pdGuardian', record.parent_guardian || '-');
        setText('pdContact', record.telephone_no || '-');
        setText('pdSchoolId', record.school_id || '-');
        setText('pdRegionDivision', [record.region, record.division].filter(Boolean).join(' / ') || '-');

        setText('psGrade', record.grade_level || '-');
        setText('psStatus', examined ? 'Examined by Nurse' : 'Pending');
        setText('psBmi', record.bmi_value || '-');
        setText('psBmiForAge', record.nutritional_status_bmi_for_age || '-');
        setText('psHeightForAge', record.nutritional_status_height_for_age || '-');

        const baselineSnapshot = record.baseline_snapshot || {};
        const endlineSnapshot = record.endline_snapshot || {};
        const examData = record.examination || {};

        const baselineHeight = baselineSnapshot.height_cm ?? record.height_cm;
        const baselineWeight = baselineSnapshot.weight_kg ?? record.weight_kg;
        const endlineHeight = endlineSnapshot.height_cm ?? examData.height_cm ?? record.height_cm;
        const endlineWeight = endlineSnapshot.weight_kg ?? examData.weight_kg ?? record.weight_kg;

        const baselineBmi = computeBmi(baselineHeight, baselineWeight);
        const endlineBmi = computeBmi(endlineHeight, endlineWeight);

        setText('pgBaseBmi', baselineBmi ? baselineBmi.toFixed(1) : '-');
        setText('pgEndBmi', endlineBmi ? endlineBmi.toFixed(1) : '-');
        renderTrendGraph(baselineBmi, endlineBmi);
        renderAttendanceBars(record.attendance_by_month || {});

        setText('paStatus', examined ? 'Nurse examination details are available.' : 'Pending nurse review.');
        setText('ptNext', examined ? 'Record completed by nurse.' : 'Nurse examination pending.');

        fillLink.setAttribute('href', route || '#');
        backdrop.classList.add('open');
        backdrop.setAttribute('aria-hidden', 'false');
    };

    openButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            let record = {};
            try {
                record = JSON.parse(btn.getAttribute('data-record') || '{}');
            } catch (_err) {
                record = {};
            }
            openProfile(record, btn.getAttribute('data-route') || '#');
        });
    });

    const closeProfile = () => {
        backdrop.classList.remove('open');
        backdrop.setAttribute('aria-hidden', 'true');
    };

    closeBtn.addEventListener('click', closeProfile);
    backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) {
            closeProfile();
        }
    });

    const tabs = Array.from(document.querySelectorAll('.profile-tab'));
    const panels = Array.from(document.querySelectorAll('.profile-panel'));
    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            const target = tab.getAttribute('data-panel');
            tabs.forEach((t) => t.classList.remove('active'));
            panels.forEach((p) => p.classList.remove('active'));
            tab.classList.add('active');
            const panel = document.getElementById(target || '');
            if (panel) {
                panel.classList.add('active');
            }
        });
    });
})();
</script>
</body>
</html>
