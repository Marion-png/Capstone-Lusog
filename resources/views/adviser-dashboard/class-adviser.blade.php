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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    @php $classAdviserCssPath = resource_path('css/class-adviser.css'); @endphp
    @if (file_exists($classAdviserCssPath))
        <style>{!! file_get_contents($classAdviserCssPath) !!}</style>
    @endif
</head>
<body>
<aside class="sidebar">
    <div class="sb-logo"><img src="{{ asset('images/lusog-logo.png') }}" alt="LUSOG Logo"></div>
    <nav class="sb-nav">
        <a href="#" class="sb-link active js-proto-nav" data-target="prototype-dashboard-panel">
            <svg class="sb-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <rect x="3" y="3" width="7" height="7"/>
                <rect x="14" y="3" width="7" height="4"/>
                <rect x="14" y="12" width="7" height="9"/>
                <rect x="3" y="14" width="7" height="7"/>
            </svg>
            <span class="sb-link-label">Dashboard</span>
        </a>
        <a href="#" class="sb-link js-proto-nav" data-target="prototype-form-panel">
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
        <a href="{{ route('dashboard.class-adviser.deworming') }}" class="sb-link">
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
    <header class="top"><div class="crumb">Dashboard > Class Adviser</div></header>
    <div class="content">
        @php
            $assignedGradeLevel = session('assigned_grade_level');
            $assignedSection = session('assigned_section');
            $assignedSchoolName = session('assigned_school_name');
            $assignedClassLabel = ($assignedGradeLevel && $assignedSection)
                ? ($assignedGradeLevel . ' / ' . $assignedSection)
                : 'Not Assigned';
        @endphp
        <h1 class="title">Class Adviser <i>Encoding Workspace</i></h1>
        <p class="sub">School Health Card prototype workflow for adviser submission and nurse follow-up.</p>
        <div class="assigned-class-banner">
            <div>
                <div class="assigned-class-label">Assigned School</div>
                <div class="assigned-class-value">{{ $assignedSchoolName ?: 'Not Assigned' }}</div>
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
            <div class="flash flash-err">{{ $errors->first() }}</div>
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

            $studentsTotal = $prototypeRecords->count();
            $pendingReviewTotal = $prototypeRecords->filter(fn ($row) => empty($row['examination']))->count();
            $completeRecordsTotal = $prototypeRecords->filter(fn ($row) => !empty($row['examination']))->count();
            $wastedStudentsTotal = $prototypeRecords->filter(function ($row) {
                $status = strtolower((string) ($row['nutritional_status_bmi_for_age'] ?? ''));
                return str_contains($status, 'wasted');
            })->count();
            $underweightStudentsTotal = $prototypeRecords->filter(function ($row) {
                $status = strtolower((string) ($row['nutritional_status_bmi_for_age'] ?? ''));
                return str_contains($status, 'underweight');
            })->count();
            $overweightStudentsTotal = $prototypeRecords->filter(function ($row) {
                $status = strtolower((string) ($row['nutritional_status_bmi_for_age'] ?? ''));
                return str_contains($status, 'overweight') || str_contains($status, 'obese');
            })->count();
            $normalStudentsTotal = max(0, $studentsTotal - ($wastedStudentsTotal + $underweightStudentsTotal + $overweightStudentsTotal));

            $safePercent = static function ($count, $total) {
                if ($total <= 0) {
                    return 0;
                }

                return (int) round(($count / $total) * 100);
            };

            $recentStudents = $prototypeRecords->take(5);

            $nutritionLabelOrder = ['Normal', 'Wasted', 'Underweight', 'Overweight', 'Obese', 'Severely Wasted'];
            $nutritionCounts = [
                'Normal' => 0,
                'Wasted' => 0,
                'Underweight' => 0,
                'Overweight' => 0,
                'Obese' => 0,
                'Severely Wasted' => 0,
            ];

            foreach ($prototypeRecords as $row) {
                $rawStatus = strtolower(trim((string) ($row['nutritional_status_bmi_for_age'] ?? '')));
                if ($rawStatus === '') {
                    continue;
                }

                if (str_contains($rawStatus, 'severely wasted')) {
                    $nutritionCounts['Severely Wasted']++;
                } elseif (str_contains($rawStatus, 'wasted')) {
                    $nutritionCounts['Wasted']++;
                } elseif (str_contains($rawStatus, 'underweight')) {
                    $nutritionCounts['Underweight']++;
                } elseif (str_contains($rawStatus, 'overweight')) {
                    $nutritionCounts['Overweight']++;
                } elseif (str_contains($rawStatus, 'obese')) {
                    $nutritionCounts['Obese']++;
                } else {
                    $nutritionCounts['Normal']++;
                }
            }

            $chartNutritionLabels = [];
            $chartNutritionValues = [];
            foreach ($nutritionLabelOrder as $label) {
                $chartNutritionLabels[] = $label;
                $chartNutritionValues[] = (int) ($nutritionCounts[$label] ?? 0);
            }

            $wastedRows = $prototypeRecords->filter(function ($row) {
                $status = strtolower((string) ($row['nutritional_status_bmi_for_age'] ?? ''));
                return str_contains($status, 'wasted');
            })->take(7)->values();

            $fallbackBaselineWeights = [35.7, 41.8, 36.9, 34.5, 39.1, 37.4, 33.8];
            $fallbackEndlineWeights = [36.9, 43.1, 37.8, 35.6, 40.7, 38.5, 34.7];
            $chartParticipationLabels = [];
            $chartBaselineValues = [];
            $chartEndlineValues = [];

            $baselineMonthLabel = now()->subMonthNoOverflow()->format('M');
            $endlineMonthLabel = now()->format('M');

            if ($wastedRows->isEmpty()) {
                $chartParticipationLabels = ['No Data'];
                $chartBaselineValues = [0];
                $chartEndlineValues = [0];
            } else {
                foreach ($wastedRows as $index => $row) {
                    $lastName = trim((string) ($row['last_name'] ?? 'Student ' . ($index + 1)));
                    $chartParticipationLabels[] = $lastName !== '' ? $lastName : ('Student ' . ($index + 1));

                    $baselineWeight = $row['baseline_weight_kg'] ?? $row['weight_kg'] ?? null;
                    $endlineWeight = $row['endline_weight_kg'] ?? null;

                    if (!is_numeric($baselineWeight)) {
                        $baselineWeight = (float) ($fallbackBaselineWeights[$index] ?? 0);
                    }

                    if (!is_numeric($endlineWeight)) {
                        $endlineWeight = (float) ($fallbackEndlineWeights[$index] ?? $baselineWeight);
                    }

                    $chartBaselineValues[] = round((float) $baselineWeight, 1);
                    $chartEndlineValues[] = round((float) $endlineWeight, 1);
                }
            }
        @endphp

        <section id="prototype-dashboard-panel" class="section-panel active" style="margin-top:12px;">
            <div class="adviser-dashboard-grid">
                <article class="card dashboard-stat-card dashboard-total">
                    <span>Total Students</span>
                    <b>{{ $studentsTotal }}</b>
                    <small>Assigned class records</small>
                </article>
                <article class="card dashboard-stat-card dashboard-wasted">
                    <span>Wasted Students</span>
                    <b>{{ $wastedStudentsTotal }}</b>
                    <small>Needs attention</small>
                </article>
                <article class="card dashboard-stat-card dashboard-complete">
                    <span>Complete Records</span>
                    <b>{{ $completeRecordsTotal }}</b>
                    <small>{{ $safePercent($completeRecordsTotal, $studentsTotal) }}% completion</small>
                </article>
                <article class="card dashboard-stat-card dashboard-pending">
                    <span>Pending Nurse Review</span>
                    <b>{{ $pendingReviewTotal }}</b>
                    <small>For examination follow-up</small>
                </article>
            </div>

            <div class="dashboard-panels-two">
                <article class="card section">
                    <h3>Class Nutritional Status</h3>
                    <div class="chart-wrap">
                        <canvas id="nutritionPieChart"></canvas>
                    </div>
                    <p class="chart-note">Baseline distribution for your assigned class.</p>
                </article>

                <article class="card section">
                    <h3>Wasted Students Participation</h3>
                    <div class="chart-wrap">
                        <canvas id="participationBarChart"></canvas>
                    </div>
                    <p class="chart-note">Comparison of baseline ({{ $baselineMonthLabel }}) and endline ({{ $endlineMonthLabel }}) values.</p>
                </article>
            </div>

            <article class="card section" style="margin-top:12px;">
                <h3>Recent Student Records</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>LRN</th>
                            <th>BMI Status</th>
                            <th>Record State</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recentStudents as $recentRecord)
                            @php
                                $recentMiddle = trim((string) ($recentRecord['middle_name'] ?? ''));
                                $recentMiddleInitial = $recentMiddle !== '' ? (' ' . strtoupper(substr($recentMiddle, 0, 1)) . '.') : '';
                                $recentFullName = trim(($recentRecord['last_name'] ?? '') . ', ' . ($recentRecord['first_name'] ?? '') . $recentMiddleInitial);
                                $recentStatus = !empty($recentRecord['examination']) ? 'Complete' : 'Pending';
                            @endphp
                            <tr>
                                <td>{{ $recentFullName }}</td>
                                <td>{{ $recentRecord['lrn'] ?? '-' }}</td>
                                <td>{{ $recentRecord['nutritional_status_bmi_for_age'] ?? '-' }}</td>
                                <td>
                                    @if ($recentStatus === 'Complete')
                                        <span class="badge ok">Complete</span>
                                    @else
                                        <span class="badge warn">Pending</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="muted">No student records yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </article>

            <div class="dashboard-quick-actions">
                <button type="button" class="btn" data-target="prototype-form-panel" id="openAddStudentFromDashboard">Add New Student</button>
                <button type="button" class="btn btn-secondary" data-target="prototype-saved-panel" id="openSavedFromDashboard">View My Students</button>
                <a href="{{ route('dashboard.class-adviser.deworming') }}" class="btn btn-secondary">Open Deworming</a>
            </div>
        </section>

        <section id="prototype-saved-panel" class="section-panel" style="margin-top:12px;">
            <article class="card my-students-card">
                <div class="my-students-head">
                    <div>
                        <h3 class="my-students-title">My Students</h3>
                        <p class="my-students-sub">View all students in your class</p>
                    </div>
                    <div class="my-students-right">
                        <span id="myStudentsDate">-</span>
                        <button type="button" class="btn" data-target="prototype-form-panel" id="openAddStudentBtn">+ Add Student</button>
                    </div>
                </div>

                <div class="my-students-stats">
                    <div class="my-stat-box box-total"><span>Total Students</span><b>{{ $studentsTotal }}</b></div>
                    <div class="my-stat-box box-pending"><span>Pending Nurse Review</span><b>{{ $pendingReviewTotal }}</b></div>
                    <div class="my-stat-box box-complete"><span>Complete Records</span><b>{{ $completeRecordsTotal }}</b></div>
                    <div class="my-stat-box box-wasted"><span>Wasted Students</span><b>{{ $wastedStudentsTotal }}</b></div>
                </div>

                <div class="my-students-tools">
                    <input id="studentsSearch" type="text" placeholder="Search by name or LRN...">
                    <select id="studentsStatusFilter">
                        <option value="all">All Status</option>
                        <option value="pending">Pending Nurse Review</option>
                        <option value="complete">Complete Record</option>
                    </select>
                    <button type="button" class="btn btn-secondary" id="studentsClearBtn">Clear</button>
                </div>

                <div class="my-students-table-wrap">
                    <table class="my-students-table">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>LRN</th>
                                <th>Gender</th>
                                <th>BMI</th>
                                <th>Nutritional Status</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="studentsTableBody">
                            @forelse ($prototypeRecords as $index => $prototypeRecord)
                                @php
                                    $middle = trim((string) ($prototypeRecord['middle_name'] ?? ''));
                                    $middleInitial = $middle !== '' ? (' ' . strtoupper(substr($middle, 0, 1)) . '.') : '';
                                    $fullName = trim(($prototypeRecord['last_name'] ?? '') . ', ' . ($prototypeRecord['first_name'] ?? '') . $middleInitial);
                                    $isExamined = !empty($prototypeRecord['examination']);
                                    $statusKey = $isExamined ? 'complete' : 'pending';
                                    $genderValue = $prototypeRecord['gender'] ?? '-';
                                @endphp
                                <tr class="js-student-row" data-name="{{ strtolower($fullName) }}" data-lrn="{{ strtolower((string) ($prototypeRecord['lrn'] ?? '')) }}" data-status="{{ $statusKey }}">
                                    <td>{{ $fullName }}</td>
                                    <td>{{ $prototypeRecord['lrn'] ?? '-' }}</td>
                                    <td>{{ $genderValue }}</td>
                                    <td>{{ $prototypeRecord['bmi_value'] ?? '-' }}</td>
                                    <td>{{ $prototypeRecord['nutritional_status_bmi_for_age'] ?? '-' }}</td>
                                    <td>
                                        @if ($isExamined)
                                            <span class="my-status-badge status-complete">Complete Record</span>
                                        @else
                                            <span class="my-status-badge status-pending">Pending Nurse Review</span>
                                        @endif
                                    </td>
                                    <td>
                                        <button type="button" class="profile-open-btn js-profile-open" data-route="{{ route('nurse.examine', $index) }}" data-record='@json($prototypeRecord)'>View Profile</button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="muted">No School Health Card prototype submissions yet for your assigned class.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </article>
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
                        <div class="field"><label for="birthDate">Date of Birth <span style="color:#ef4444">*</span></label><input id="birthDate" name="birth_date" type="date" value="{{ old('birth_year') && old('birth_month') && old('birth_day') ? old('birth_year') . '-' . str_pad(old('birth_month'), 2, '0', STR_PAD_LEFT) . '-' . str_pad(old('birth_day'), 2, '0', STR_PAD_LEFT) : '' }}" required></div>
                        <div class="field"><label for="proto_birthplace">Birthplace</label><input id="proto_birthplace" name="birthplace" type="text" placeholder="City/Municipality of birth" value="{{ old('birthplace') }}" required></div>
                        <div class="field full"><label for="gender">Gender <span style="color:#ef4444">*</span></label><select id="gender" name="gender" required><option value="">Select Gender</option><option {{ old('gender') === 'Male' ? 'selected' : '' }}>Male</option><option {{ old('gender') === 'Female' ? 'selected' : '' }}>Female</option></select></div>
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
                        <div class="field"><label for="weight">Weight (kg) <span style="color:#ef4444">*</span></label><input id="weight" name="weight_kg" type="number" step="0.1" min="0.1" max="200" placeholder="e.g., 34" value="{{ old('weight_kg') }}" required><div class="muted" style="font-size:.7rem;">Valid range: 0.1 - 200 kg</div></div>
                        <div class="field"><label for="height">Height (m) <span style="color:#ef4444">*</span></label><input id="height" name="height_m" type="number" step="0.01" min="0.50" max="2.50" placeholder="e.g., 1.27" value="{{ old('height_cm') ? number_format(old('height_cm') / 100, 2, '.', '') : '' }}" required><div class="muted" style="font-size:.7rem;">Convert cm to m: 127 cm = 1.27 m | Valid range: 0.50 - 2.50 m</div></div>
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
    <div class="profile-modal student-profile-modal" role="dialog" aria-modal="true" aria-label="Student Profile">
        <div class="student-profile-topline">
            <button type="button" class="student-profile-back" id="profileClose">&larr;</button>
            <div>
                <div class="student-profile-titleline">Student Profile</div>
                <div class="student-profile-subline">Health Record (Adviser Entry)</div>
            </div>
        </div>

        <div class="student-profile-hero">
            <div>
                <div class="student-profile-name" id="vpName">-</div>
                <div class="student-profile-lrn" id="vpLrn">LRN: -</div>
            </div>
            <span class="my-status-badge status-pending" id="vpStatusBadge">Pending Nurse Review</span>
        </div>

        <div class="student-profile-body">
            <section class="student-profile-section">
                <h4>Student Information</h4>
                <div class="student-profile-grid">
                    <div><span>Date of Birth:</span><b id="vpDob">-</b></div>
                    <div><span>Birthplace:</span><b id="vpBirthplace">-</b></div>
                    <div><span>Gender:</span><b id="vpGender">-</b></div>
                    <div><span>Grade &amp; Section:</span><b id="vpGradeSection">-</b></div>
                </div>
            </section>

            <section class="student-profile-section">
                <h4>Parent/Guardian</h4>
                <div class="student-profile-grid">
                    <div><span>Name:</span><b id="vpGuardian">-</b></div>
                    <div><span>Contact Number:</span><b id="vpContact">-</b></div>
                    <div class="full"><span>Address:</span><b id="vpAddress">-</b></div>
                </div>
            </section>

            <section class="student-profile-section">
                <h4>Health Data (Baseline) - Adviser Entry</h4>
                <div class="student-profile-grid metrics">
                    <div><span>Weight:</span><b id="vpWeight">-</b></div>
                    <div><span>Height:</span><b id="vpHeight">-</b></div>
                    <div><span>(Height)<sup>2</sup>:</span><b id="vpHeightSquared">-</b></div>
                    <div><span>BMI:</span><b id="vpBmi">-</b></div>
                    <div><span>Nutritional Status:</span><b id="vpNutri">-</b></div>
                    <div><span>Height-for-Age:</span><b id="vpHfa">-</b></div>
                </div>
            </section>

            <section class="student-profile-section" id="vpNurseSection" style="display:none;">
                <h4>Nurse Examination Record (Read-only)</h4>
                <div class="student-profile-grid">
                    <div><span>Date of Examination:</span><b id="vpExamDate">-</b></div>
                    <div><span>Examined By:</span><b id="vpExaminedBy">-</b></div>
                    <div><span>Temperature / BP:</span><b id="vpTempBp">-</b></div>
                    <div><span>Heart Rate:</span><b id="vpHeartRate">-</b></div>
                    <div><span>Pulse Rate:</span><b id="vpPulseRate">-</b></div>
                    <div><span>Respiratory Rate:</span><b id="vpRespRate">-</b></div>
                    <div><span>Nutritional Status (BMI/Wt-for-Age):</span><b id="vpExamNutriBmi">-</b></div>
                    <div><span>Nutritional Status (Height-for-Age):</span><b id="vpExamNutriHfa">-</b></div>
                    <div><span>Vision Screening:</span><b id="vpVision">-</b></div>
                    <div><span>Auditory Screening:</span><b id="vpAuditory">-</b></div>
                    <div><span>Skin/Scalp:</span><b id="vpSkin">-</b></div>
                    <div><span>Eyes/Ears/Nose:</span><b id="vpEyesEarsNose">-</b></div>
                    <div><span>Mouth/Throat/Neck:</span><b id="vpMouthThroatNeck">-</b></div>
                    <div><span>Lungs/Heart:</span><b id="vpLungsHeart">-</b></div>
                    <div><span>Abdomen:</span><b id="vpAbdomen">-</b></div>
                    <div><span>Deformities:</span><b id="vpDeformities">-</b></div>
                    <div><span>Iron Supplementation:</span><b id="vpIron">-</b></div>
                    <div><span>Deworming:</span><b id="vpDeworming">-</b></div>
                    <div><span>Immunization:</span><b id="vpImmunization">-</b></div>
                    <div><span>SBFP Beneficiary:</span><b id="vpSbfp">-</b></div>
                    <div><span>4Ps Beneficiary:</span><b id="vp4ps">-</b></div>
                    <div><span>Menarche:</span><b id="vpMenarche">-</b></div>
                    <div class="full"><span>Others:</span><b id="vpOthers">-</b></div>
                </div>
            </section>

            <section class="pending-note-box" id="vpPendingBox">
                <h5>Pending Nurse Review</h5>
                <p>This student's health record is pending completion by the school nurse.</p>
            </section>
        </div>
    </div>
</div>
<script>
const dashboardNutritionLabels = @json($chartNutritionLabels);
const dashboardNutritionValues = @json($chartNutritionValues);
const dashboardParticipationLabels = @json($chartParticipationLabels);
const dashboardBaselineValues = @json($chartBaselineValues);
const dashboardEndlineValues = @json($chartEndlineValues);
const dashboardBaselineMonthLabel = @json($baselineMonthLabel);
const dashboardEndlineMonthLabel = @json($endlineMonthLabel);

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

    const tabParam = new URLSearchParams(window.location.search).get('tab');
    if (tabParam === 'saved') {
        const savedLink = document.querySelector('.js-proto-nav[data-target="prototype-saved-panel"]');
        savedLink?.click();
    } else if (tabParam === 'form') {
        const formLink = document.querySelector('.js-proto-nav[data-target="prototype-form-panel"]');
        formLink?.click();
    } else {
        const dashboardLink = document.querySelector('.js-proto-nav[data-target="prototype-dashboard-panel"]');
        dashboardLink?.click();
    }
})();

(() => {
    if (typeof Chart === 'undefined') {
        return;
    }

    const nutritionCanvas = document.getElementById('nutritionPieChart');
    const participationCanvas = document.getElementById('participationBarChart');

    if (nutritionCanvas) {
        new Chart(nutritionCanvas.getContext('2d'), {
            type: 'pie',
            data: {
                labels: dashboardNutritionLabels,
                datasets: [{
                    data: dashboardNutritionValues,
                    backgroundColor: ['#14532d', '#f59e0b', '#10b981', '#3b82f6', '#8b5cf6', '#ef4444'],
                    borderWidth: 0,
                    hoverOffset: 8,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 11,
                            font: { size: 10 },
                        },
                    },
                },
            },
        });
    }

    if (participationCanvas) {
        new Chart(participationCanvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: dashboardParticipationLabels,
                datasets: [
                    {
                        label: `Baseline (${dashboardBaselineMonthLabel})`,
                        data: dashboardBaselineValues,
                        backgroundColor: '#14532d',
                        borderRadius: 8,
                        yAxisID: 'y',
                    },
                    {
                        label: `Endline (${dashboardEndlineMonthLabel})`,
                        data: dashboardEndlineValues,
                        backgroundColor: '#3b82f6',
                        borderRadius: 8,
                        yAxisID: 'y',
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { font: { size: 10 } },
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Weight (kg)',
                        },
                    },
                },
            },
        });
    }
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
    const dateNode = document.getElementById('myStudentsDate');
    if (!dateNode) {
        return;
    }

    dateNode.textContent = new Date().toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
})();

(() => {
    const searchInput = document.getElementById('studentsSearch');
    const statusSelect = document.getElementById('studentsStatusFilter');
    const clearBtn = document.getElementById('studentsClearBtn');
    const rows = Array.from(document.querySelectorAll('.js-student-row'));

    if (!searchInput || !statusSelect || !clearBtn || !rows.length) {
        return;
    }

    const applyFilters = () => {
        const keyword = searchInput.value.trim().toLowerCase();
        const status = statusSelect.value;

        rows.forEach((row) => {
            const name = row.getAttribute('data-name') || '';
            const lrn = row.getAttribute('data-lrn') || '';
            const rowStatus = row.getAttribute('data-status') || '';
            const keywordMatch = !keyword || name.includes(keyword) || lrn.includes(keyword);
            const statusMatch = status === 'all' || rowStatus === status;

            row.style.display = keywordMatch && statusMatch ? '' : 'none';
        });
    };

    searchInput.addEventListener('input', applyFilters);
    statusSelect.addEventListener('change', applyFilters);
    clearBtn.addEventListener('click', () => {
        searchInput.value = '';
        statusSelect.value = 'all';
        applyFilters();
    });
})();

(() => {
    const addBtn = document.getElementById('openAddStudentBtn');
    const target = document.querySelector('.js-proto-nav[data-target="prototype-form-panel"]');
    const addFromDashboard = document.getElementById('openAddStudentFromDashboard');
    const savedFromDashboard = document.getElementById('openSavedFromDashboard');
    const savedTarget = document.querySelector('.js-proto-nav[data-target="prototype-saved-panel"]');

    if (!addBtn || !target) {
        if (addFromDashboard && target) {
            addFromDashboard.addEventListener('click', () => target.click());
        }

        if (savedFromDashboard && savedTarget) {
            savedFromDashboard.addEventListener('click', () => savedTarget.click());
        }

        return;
    }

    addBtn.addEventListener('click', () => {
        target.click();
    });

    addFromDashboard?.addEventListener('click', () => {
        target.click();
    });

    savedFromDashboard?.addEventListener('click', () => {
        savedTarget?.click();
    });
})();

(() => {
    const openButtons = Array.from(document.querySelectorAll('.js-profile-open'));
    const backdrop = document.getElementById('profileBackdrop');
    const closeBtn = document.getElementById('profileClose');

    if (!openButtons.length || !backdrop || !closeBtn) {
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

    const openProfile = (record, route) => {
        const fullName = [record.last_name, ',', record.first_name, record.middle_name ? (' ' + String(record.middle_name).charAt(0).toUpperCase() + '.') : '']
            .join(' ')
            .replace(' ,', ',')
            .replace(/\s+/g, ' ')
            .trim();
        const dob = [record.birth_year, record.birth_month, record.birth_day].filter(Boolean).join('-');
        const examined = record.examination && Object.keys(record.examination).length > 0;
        const heightCm = toNumber(record.height_cm);
        const weightKg = toNumber(record.weight_kg);
        const bmi = record.bmi_value ? Number(record.bmi_value) : computeBmi(heightCm, weightKg);
        const heightM = heightCm ? (heightCm / 100) : null;
        const heightSquared = heightM ? (heightM * heightM) : null;
        const statusBadge = document.getElementById('vpStatusBadge');
        const pendingBox = document.getElementById('vpPendingBox');
        const nurseSection = document.getElementById('vpNurseSection');
        const exam = record.examination && typeof record.examination === 'object' ? record.examination : {};

        setText('vpName', fullName || '-');
        setText('vpLrn', 'LRN: ' + (record.lrn || '-'));
        setText('vpDob', dob || '-');
        setText('vpBirthplace', record.birthplace || '-');
        setText('vpGender', record.gender || '-');
        setText('vpGradeSection', [record.grade_level, record.section].filter(Boolean).join(' - ') || '-');

        setText('vpGuardian', record.parent_guardian || '-');
        setText('vpContact', record.telephone_no || '-');
        setText('vpAddress', record.address || '-');

        setText('vpWeight', weightKg ? `${weightKg} kg` : '-');
        setText('vpHeight', heightM ? `${heightM.toFixed(2)} m` : '-');
        setText('vpHeightSquared', heightSquared ? heightSquared.toFixed(4) : '-');
        setText('vpBmi', bmi ? bmi.toFixed(1) : '-');
        setText('vpNutri', record.nutritional_status_bmi_for_age || '-');
        setText('vpHfa', record.nutritional_status_height_for_age || '-');

        setText('vpExamDate', exam.date_of_examination || '-');
        setText('vpExaminedBy', exam.examined_by || '-');
        setText('vpTempBp', exam.temperature_bp || '-');
        setText('vpHeartRate', exam.heart_rate || '-');
        setText('vpPulseRate', exam.pulse_rate || '-');
        setText('vpRespRate', exam.respiratory_rate || '-');
        setText('vpExamNutriBmi', exam.nutritional_status_bmi || '-');
        setText('vpExamNutriHfa', exam.nutritional_status_height_age || '-');
        setText('vpVision', exam.vision_screening || '-');
        setText('vpAuditory', exam.auditory_screening || '-');
        setText('vpSkin', exam.skin_scalp || '-');
        setText('vpEyesEarsNose', exam.eyes_ears_nose || '-');
        setText('vpMouthThroatNeck', exam.mouth_throat_neck || '-');
        setText('vpLungsHeart', exam.lungs_heart || '-');
        setText('vpAbdomen', exam.abdomen || '-');
        setText('vpDeformities', exam.deformities || '-');
        setText('vpIron', exam.iron_supplementation || '-');
        setText('vpDeworming', exam.deworming || '-');
        setText('vpImmunization', exam.immunization || '-');
        setText('vpSbfp', exam.sbfp_beneficiary || '-');
        setText('vp4ps', exam.four_ps_beneficiary || '-');
        setText('vpMenarche', exam.menarche || '-');
        setText('vpOthers', exam.others || '-');

        if (statusBadge) {
            if (examined) {
                statusBadge.textContent = 'Complete Record';
                statusBadge.className = 'my-status-badge status-complete';
            } else {
                statusBadge.textContent = 'Pending Nurse Review';
                statusBadge.className = 'my-status-badge status-pending';
            }
        }

        if (pendingBox) {
            pendingBox.style.display = examined ? 'none' : 'block';
        }

        if (nurseSection) {
            nurseSection.style.display = examined ? 'block' : 'none';
        }

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
})();
</script>
</body>
</html>
