<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <link rel="shortcut icon" href="{{ asset('images/lusog-logo.png') }}">
    <title>Class Adviser Dashboard - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    @php $classAdviserCssPath = resource_path('css/class-adviser.css'); @endphp
    @if (file_exists($classAdviserCssPath))
        <style>{!! file_get_contents($classAdviserCssPath) !!}</style>
    @endif
    {{-- One shared palette for pages not yet on lusog-theme.css. Loaded
         last so it overrides this page's own :root colours. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-palette.css')) !!}</style>
</head>
<body>
@include('partials.adviser-sidebar', ['active' => in_array(request('tab'), ['saved', 'form'], true) ? 'students' : 'dashboard'])

<div class="asb-main">
    @include('partials.adviser-topbar', ['breadcrumb' => match (request('tab')) {
        'saved' => 'My Students',
        'form' => 'Enroll Student',
        default => 'Dashboard',
    }])
    <div class="content">
        @php
            $assignedGradeLevel = session('assigned_grade_level');
            $assignedSection = session('assigned_section');
            $assignedClassLabel = ($assignedGradeLevel && $assignedSection)
                ? ($assignedGradeLevel . ' / ' . $assignedSection)
                : 'Not Assigned';
        @endphp
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
            $completeRecordsTotal = $prototypeRecords->filter(fn ($row) => !empty($row['examination']) && isset($lrnsWithCertificates[$row['lrn'] ?? '']))->count();
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
            $adviserTab = request('tab');
        @endphp

        <section id="prototype-dashboard-panel" class="section-panel {{ $adviserTab === 'saved' || $adviserTab === 'form' ? '' : 'active' }}" style="margin-top:12px;">
            @php
                $greetHour = (int) now()->format('G');
                $greeting = $greetHour < 12 ? 'Good morning' : ($greetHour < 18 ? 'Good afternoon' : 'Good evening');
                $ov = $overview;
            @endphp

            {{-- Scoped to this panel: My Students and Enroll Student each carry
                 their own heading, so a shared page title only duplicates them. --}}
            <h1 class="title">Class Adviser <i>Encoding Workspace</i></h1>
            <p class="sub" style="margin-bottom:12px;">School Health Card encoding for your assigned class.</p>

            <div class="hero-banner">
                <h1 class="hero-title">{{ $greeting }}, {{ session('active_name', 'Class Adviser') }}</h1>
                <p class="hero-sub">{{ $ov['grade_section'] }} &middot; Manage your students' health profiles and track their well-being.</p>
                <div class="hero-stats">
                    <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>{{ $ov['total'] }} Total students</span>
                    <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>{{ $ov['complete'] }} Complete profiles</span>
                    <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>{{ $ov['pending'] }} Pending</span>
                    <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>{{ $ov['needs_followup'] }} Needs follow-up</span>
                    <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>{{ $ov['priority'] }} Priority</span>
                </div>
            </div>

            <div class="dashboard-stat-row">
                <article class="card dashboard-stat-card dashboard-total">
                    <div class="dsc-icon dsc-icon-total"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
                    <div><b>{{ $ov['total'] }}</b><span>Total Students</span><small>All enrolled in your class</small></div>
                </article>
                <article class="card dashboard-stat-card dashboard-complete">
                    <div class="dsc-icon dsc-icon-complete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
                    <div><b>{{ $ov['complete'] }}</b><span>Complete Profiles</span><small>Full health assessment done</small></div>
                </article>
                <article class="card dashboard-stat-card dashboard-pending">
                    <div class="dsc-icon dsc-icon-pending"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
                    <div><b>{{ $ov['pending'] }}</b><span>Pending Assessments</span><small>Health assessment needed</small></div>
                </article>
                <article class="card dashboard-stat-card dashboard-wasted">
                    <div class="dsc-icon dsc-icon-alert"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
                    <div><b>{{ $ov['needs_followup'] }}</b><span>Needs Follow-up</span><small>Requires medical attention</small></div>
                </article>
            </div>

            <div class="dashboard-panels-two" id="needs-attention">
                {{-- Learners whose health assessment reports a chronic or
                     life-threatening condition. The list is derived on every
                     read by App\Support\PriorityHealthRule — correcting an
                     assessment corrects this table. --}}
                <article class="card section">
                    <div class="panel-head">
                        <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px;color:#b91c1c;vertical-align:-2px;"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg> Priority Students</h3>
                        <a href="{{ route('dashboard.class-adviser', ['tab' => 'saved']) }}" class="panel-head-link">View all students</a>
                    </div>

                    @if ($ov['priority_students']->isEmpty())
                        <p class="muted" style="padding:8px 0;font-size:.82rem;">No learners are flagged as priority right now.</p>
                    @else
                        <div class="ps-scroll">
                            <table class="ps-table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Section</th>
                                        <th>Condition</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($ov['priority_students'] as $item)
                                        <tr>
                                            <td>
                                                <div class="ps-name">{{ $item['name'] }}</div>
                                                <div class="ps-lrn">LRN {{ $item['lrn'] }}</div>
                                            </td>
                                            <td class="ps-section">{{ $item['section'] }}</td>
                                            <td>
                                                <div class="ps-conditions">
                                                    @foreach ($item['reasons'] as $reason)
                                                        <span class="ps-chip">{{ $reason }}</span>
                                                    @endforeach
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="ps-foot">{{ $ov['priority_students']->count() }} {{ Str::plural('learner', $ov['priority_students']->count()) }} needing advance care planning.</div>
                    @endif
                </article>

                <article class="card section">
                    <div class="panel-head">
                        <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px;color:#1F8A4C;vertical-align:-2px;"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg> Recent Activity</h3>
                    </div>
                    {{-- Server-rendered first paint; the panel then keeps itself
                         current from the activity feed (see recentActivityLive). --}}
                    <div id="recentActivityList"
                         data-feed-url="{{ route('dashboard.class-adviser.activity') }}"
                         data-pulse-url="{{ route('dashboard.class-adviser.activity.pulse') }}"
                         data-stamp="{{ $ov['activity_stamp'] }}">
                        @forelse ($ov['recent_activity'] as $event)
                            <div class="ra-row" data-activity-id="{{ $event['id'] }}">
                                <div class="ra-icon ra-icon-{{ $event['icon'] }}">
                                    @if ($event['icon'] === 'declined')
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                                    @elseif ($event['icon'] === 'student')
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                                    @elseif ($event['icon'] === 'certificate')
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                    @else
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                                    @endif
                                </div>
                                <div class="ra-body">
                                    <div class="ra-text">{{ $event['text'] }}</div>
                                    <div class="ra-meta">
                                        <span class="ra-badge">{{ $event['badge'] }}</span>
                                        <time class="ra-ago" datetime="{{ $event['at']->toIso8601String() }}">{{ $event['at']->diffForHumans() }}</time>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <p class="muted ra-empty" style="padding:8px 0;font-size:.82rem;">No recent activity yet.</p>
                        @endforelse
                    </div>
                </article>
            </div>

            <div class="dashboard-panels-two">
                @include('partials.announcements')
                @include('partials.upcoming-events')
            </div>

            <div class="quick-action-grid">
                <button type="button" class="quick-action-card" id="openSavedFromDashboard">
                    <div class="qa-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg></div>
                    <div><div class="qa-title">Manage Students</div><div class="qa-desc">View and manage all your students' health records</div></div>
                    <svg class="qa-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </button>
                <a href="{{ route('consent-forms.index') }}" class="quick-action-card">
                    <div class="qa-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg></div>
                    <div><div class="qa-title">Consent Forms</div><div class="qa-desc">Review and manage parent consent forms</div></div>
                    <svg class="qa-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
                <a href="{{ route('dashboard.class-adviser.feeding-status') }}" class="quick-action-card">
                    <div class="qa-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg></div>
                    <div><div class="qa-title">Feeding Status</div><div class="qa-desc">Track students' feeding program participation</div></div>
                    <svg class="qa-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
            </div>
        </section>

        <section id="prototype-saved-panel" class="section-panel {{ $adviserTab === 'saved' ? 'active' : '' }}" style="margin-top:12px;">
            @php
                $needsFollowupTotal = $prototypeRecords->filter(function ($row) use ($rosterMeta) {
                    $status = strtolower((string) ($row['nutritional_status_bmi_for_age'] ?? ''));
                    $atRisk = (bool) ($rosterMeta[(string) ($row['lrn'] ?? '')]['at_risk'] ?? false);

                    return $atRisk
                        || str_contains($status, 'wasted')
                        || str_contains($status, 'underweight')
                        || str_contains($status, 'obese');
                })->count();

                $profileBadges = [
                    'complete' => 'Complete',
                    'partial' => 'Partial',
                    'pending' => 'Pending',
                ];
                $consentBadges = [
                    'approved' => 'Approved',
                    'partial' => 'Partial',
                    'declined' => 'Declined',
                    'pending' => 'Pending',
                ];
            @endphp

            <div class="ms-page-header">
                <div>
                    <h2 class="ms-page-title">My Students</h2>
                    <p class="ms-page-sub">{{ $ov['grade_section'] }} &middot; Manage your students' health profiles and track their well-being.</p>
                </div>
                <div class="ms-header-actions">
                    <button type="button" class="btn" id="openAddStudentBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Enroll Student
                    </button>
                </div>
            </div>

            <div class="ms-stats-bar">
                <div class="ms-stat">
                    <div class="ms-stat-icon ms-icon-total"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
                    <div><div class="ms-stat-number">{{ $studentsTotal }}</div><div class="ms-stat-label">Total Students</div></div>
                </div>
                <div class="ms-stat">
                    <div class="ms-stat-icon ms-icon-complete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
                    <div><div class="ms-stat-number">{{ $ov['complete'] }}</div><div class="ms-stat-label">Complete Profiles</div></div>
                </div>
                <div class="ms-stat">
                    <div class="ms-stat-icon ms-icon-pending"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
                    <div><div class="ms-stat-number">{{ $ov['pending'] }}</div><div class="ms-stat-label">Pending Assessments</div></div>
                </div>
                <div class="ms-stat">
                    <div class="ms-stat-icon ms-icon-alert"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
                    <div><div class="ms-stat-number">{{ $needsFollowupTotal }}</div><div class="ms-stat-label">Needs Follow-up</div></div>
                </div>
            </div>

            <article class="ms-table-container">
                <div class="ms-table-header">
                    <h3>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                        Student Records
                        <span class="ms-count-badge" id="studentsCountBadge">{{ $studentsTotal }}</span>
                    </h3>
                    <div class="ms-table-actions">
                        <div class="ms-search-wrapper">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input id="studentsSearch" class="ms-search-input" type="text" placeholder="Search by name or LRN..." autocomplete="off">
                        </div>
                        <select id="studentsStatusFilter" class="ms-filter-select" aria-label="Filter by profile status">
                            <option value="all">All Students</option>
                            <option value="complete">Complete Profile</option>
                            <option value="partial">Partial Profile</option>
                            <option value="pending">Pending Assessment</option>
                        </select>
                    </div>
                </div>

                <div class="ms-table-scroll">
                    <table class="ms-table">
                        <thead>
                            <tr>
                                <th>LRN</th>
                                <th>Student Name</th>
                                <th>Sex</th>
                                <th>Age</th>
                                <th>Health Status</th>
                                <th>Profile Status</th>
                                <th>Consent</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="studentsTableBody">
                            @forelse ($prototypeRecords as $index => $prototypeRecord)
                                @php
                                    $middle = trim((string) ($prototypeRecord['middle_name'] ?? ''));
                                    $middleInitial = $middle !== '' ? (' ' . strtoupper(substr($middle, 0, 1)) . '.') : '';
                                    $fullName = trim(($prototypeRecord['last_name'] ?? '') . ', ' . ($prototypeRecord['first_name'] ?? '') . $middleInitial);
                                    $rowLrn = (string) ($prototypeRecord['lrn'] ?? '');
                                    $meta = $rosterMeta[$rowLrn] ?? ['has_assessment' => false, 'consent' => 'pending', 'at_risk' => false];
                                    $isExamined = !empty($prototypeRecord['examination']);
                                    $profileKey = $meta['has_assessment'] ? 'complete' : ($isExamined ? 'partial' : 'pending');
                                    $consentKey = $meta['consent'];
                                    $healthStatus = trim((string) ($prototypeRecord['nutritional_status_bmi_for_age'] ?? ''));
                                @endphp
                                <tr class="js-student-row"
                                    data-name="{{ strtolower($fullName) }}"
                                    data-lrn="{{ strtolower($rowLrn) }}"
                                    data-status="{{ $profileKey }}">
                                    <td><strong>{{ $rowLrn !== '' ? $rowLrn : '-' }}</strong></td>
                                    <td class="ms-student-name">{{ $fullName }}</td>
                                    <td>{{ $prototypeRecord['gender'] ?? '-' }}</td>
                                    <td>{{ $prototypeRecord['age'] ?? '-' }}</td>
                                    <td>{{ $healthStatus !== '' ? $healthStatus : 'Not assessed' }}</td>
                                    <td><span class="ms-badge ms-profile-{{ $profileKey }}">{{ $profileBadges[$profileKey] }}</span></td>
                                    <td><span class="ms-badge ms-consent-{{ $consentKey }}">{{ $consentBadges[$consentKey] }}</span></td>
                                    <td>
                                        <div class="ms-actions">
                                            <a href="{{ route('dashboard.class-adviser.student-profile', $rowLrn) }}" class="ms-act ms-act-view" title="View Profile" aria-label="View Profile">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            </a>
                                            <button type="button" class="ms-act ms-act-edit js-student-edit" title="Edit Profile" aria-label="Edit Profile" data-record='@json($prototypeRecord)' data-lrn="{{ $rowLrn }}">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>
                                            </button>
                                            <form method="POST" action="{{ route('consent-forms.open') }}" class="ms-act-form">
                                                @csrf
                                                <input type="hidden" name="lrn" value="{{ $rowLrn }}">
                                                <button type="submit" class="ms-act ms-act-consent" title="Parent's Consent" aria-label="Parent's Consent">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h6"/><polyline points="14 2 14 8 20 8"/><path d="M14.5 18.5c1-1.5 2-1.5 3 0s2 1.5 3 0"/><path d="M12 16c1.5-3.5 3-5 4-3.5s-1 3.5-2 5"/></svg>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr class="js-students-empty">
                                    <td colspan="8">
                                        <div class="ms-empty-state">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                                            <h4>No Students Yet</h4>
                                            <p>Start by enrolling your first student into {{ $ov['grade_section'] }}.</p>
                                            <button type="button" class="btn" id="openAddStudentEmptyBtn">Enroll Student</button>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                            <tr class="js-students-nomatch" hidden>
                                <td colspan="8">
                                    <div class="ms-empty-state">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                        <h4>No Students Found</h4>
                                        <p>No students match your current search or filter.</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="ms-table-footer">
                    <div class="ms-footer-info">
                        Showing <strong id="msShowingStart">0</strong> to <strong id="msShowingEnd">0</strong> of <strong id="msShowingTotal">0</strong> students
                    </div>
                    <div class="ms-pagination" id="msPagination"></div>
                </div>
            </article>
        </section>

        <section id="prototype-form-panel" class="card section section-panel {{ $adviserTab === 'form' ? 'active' : '' }}" style="margin-top:12px;">
            <div class="add-head">
                <div class="add-head-left">
                    <a href="{{ route('dashboard.class-adviser', ['tab' => 'saved']) }}" class="add-back" aria-label="Back to My Students">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
                    </a>
                    <div>
                        <h3 class="add-title" id="enrolFormTitle">Enroll Student</h3>
                        <p class="add-sub" id="enrolFormSub">Mandatory Learner's Health Assessment. Both sheets are open &mdash; fill them in any order.</p>
                    </div>
                </div>
                <div class="add-date" id="currentDate">-</div>
            </div>

            <div class="class-box">
                <div class="class-box-row"><span>Your assigned class:</span><span class="class-box-value" id="assignedClassDisplay">{{ $assignedClassLabel }}</span></div>
                <div class="class-box-note">Students will be automatically added to this grade and section.</div>
            </div>

                {{-- Both sheets are reachable from the moment the form opens: Sheet 2
                     is never gated behind completing Sheet 1. --}}
                <div class="sheet-tabs" role="tablist" aria-label="Health assessment sheets">
                    <button type="button" class="sheet-tab active" role="tab" id="sheetTab1" data-sheet="sheetPanel1" aria-selected="true" aria-controls="sheetPanel1">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                        Sheet 1
                        <span class="sheet-tab-badge">Learner Info &amp; Vital Signs</span>
                    </button>
                    <button type="button" class="sheet-tab" role="tab" id="sheetTab2" data-sheet="sheetPanel2" aria-selected="false" aria-controls="sheetPanel2">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4.8 2.3A.3.3 0 1 0 5 2H4a2 2 0 0 0-2 2v5a6 6 0 0 0 6 6v0a6 6 0 0 0 6-6V4a2 2 0 0 0-2-2h-1a.2.2 0 1 0 .3.3"/><path d="M8 15v1a6 6 0 0 0 6 6v0a6 6 0 0 0 6-6v-4"/><circle cx="20" cy="10" r="2"/></svg>
                        Sheet 2
                        <span class="sheet-tab-badge">Systems Review</span>
                    </button>
                </div>

                <form id="studentForm" method="POST" action="{{ route('adviser.store') }}" autocomplete="off">
                    @csrf
                    <input id="proto_birth_month" name="birth_month" type="hidden" value="{{ old('birth_month') }}">
                    <input id="proto_birth_day" name="birth_day" type="hidden" value="{{ old('birth_day') }}">
                    <input id="proto_birth_year" name="birth_year" type="hidden" value="{{ old('birth_year') }}">
                    <input id="proto_height_cm" name="height_cm" type="hidden" value="{{ old('height_cm') }}">
                    <input type="hidden" name="grade_level" value="{{ $assignedGradeLevel ?? '' }}">
                    <input type="hidden" name="section" value="{{ $assignedSection ?? '' }}">

                <div class="sheet-panel active" id="sheetPanel1" role="tabpanel" aria-labelledby="sheetTab1">
                <div class="student-section">
                    <h4>Student Information</h4>
                    <div class="student-grid">
                        <div class="field"><label for="proto_last_name">Last Name <span style="color:#D95C5C">*</span></label><input id="proto_last_name" name="last_name" type="text" placeholder="e.g., Dela Cruz" value="{{ old('last_name') }}" required></div>
                        <div class="field"><label for="proto_first_name">First Name <span style="color:#D95C5C">*</span></label><input id="proto_first_name" name="first_name" type="text" placeholder="e.g., Maria" value="{{ old('first_name') }}" required></div>
                        <div class="field"><label for="proto_middle_name">Middle Name</label><input id="proto_middle_name" name="middle_name" type="text" placeholder="e.g., Santos" value="{{ old('middle_name') }}"></div>
                        <div class="field"><label for="proto_lrn">LRN <span style="color:#D95C5C">*</span></label><input id="proto_lrn" name="lrn" type="text" placeholder="12-digit Learner Reference Number" value="{{ old('lrn') }}" inputmode="numeric" required></div>
                        <div class="field"><label for="birthDate">Date of Birth <span style="color:#D95C5C">*</span></label><input id="birthDate" name="birth_date" type="date" value="{{ old('birth_year') && old('birth_month') && old('birth_day') ? old('birth_year') . '-' . str_pad(old('birth_month'), 2, '0', STR_PAD_LEFT) . '-' . str_pad(old('birth_day'), 2, '0', STR_PAD_LEFT) : '' }}" required></div>
                        <div class="field"><label for="proto_birthplace">Birthplace</label><input id="proto_birthplace" name="birthplace" type="text" placeholder="City/Municipality of birth" value="{{ old('birthplace') }}" required></div>
                        <div class="field full"><label for="gender">Gender <span style="color:#D95C5C">*</span></label><select id="gender" name="gender" required><option value="">Select Gender</option><option {{ old('gender') === 'Male' ? 'selected' : '' }}>Male</option><option {{ old('gender') === 'Female' ? 'selected' : '' }}>Female</option></select></div>
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

                @php
                    // Same fallback rule as Sheet 2: the array's presence, not a
                    // key's, decides whether to use the default checked state.
                    $hh = old('health_history');
                    $hhPosted = is_array($hh);
                    $hhFlag = fn (string $key, bool $default = false) => $hhPosted ? ! empty($hh[$key]) : $default;
                    $hhText = fn (string $key, string $default = '') => $hhPosted ? (string) ($hh[$key] ?? '') : $default;
                @endphp

                <div class="student-section">
                    <h4>Medical History</h4>
                    <div class="sr-checkgrid">
                        <label><input type="checkbox" name="health_history[med_asthma]" value="1" {{ $hhFlag('med_asthma') ? 'checked' : '' }}> Asthma</label>
                        <label><input type="checkbox" name="health_history[med_diabetes]" value="1" {{ $hhFlag('med_diabetes') ? 'checked' : '' }}> Diabetes</label>
                        <label><input type="checkbox" name="health_history[med_seizure]" value="1" {{ $hhFlag('med_seizure') ? 'checked' : '' }}> Seizure Disorder</label>
                        <label><input type="checkbox" name="health_history[med_infections]" value="1" {{ $hhFlag('med_infections') ? 'checked' : '' }}> Frequent Infections</label>
                        <label><input type="checkbox" name="health_history[med_heart]" value="1" {{ $hhFlag('med_heart') ? 'checked' : '' }}> Heart Condition</label>
                        <label><input type="checkbox" name="health_history[med_tuberculosis]" value="1" {{ $hhFlag('med_tuberculosis') ? 'checked' : '' }}> Tuberculosis</label>
                    </div>
                    <div class="student-grid" style="margin-top:10px;">
                        <div class="field">
                            <label class="sr-inline-check"><input type="checkbox" name="health_history[med_allergies]" value="1" {{ $hhFlag('med_allergies') ? 'checked' : '' }}> Allergies</label>
                            <input id="hh_allergies_detail" name="health_history[allergies_detail]" type="text" placeholder="Specify allergies" value="{{ $hhText('allergies_detail') }}">
                        </div>
                        <div class="field">
                            <label class="sr-inline-check"><input type="checkbox" name="health_history[med_hospitalization]" value="1" {{ $hhFlag('med_hospitalization') ? 'checked' : '' }}> Hospitalization / Surgery</label>
                            <input id="hh_hospitalization_detail" name="health_history[hospitalization_detail]" type="text" placeholder="Specify procedure and year" value="{{ $hhText('hospitalization_detail') }}">
                        </div>
                        <div class="field"><label for="hh_current_medications">Current Medications</label><input id="hh_current_medications" name="health_history[current_medications]" type="text" value="{{ $hhText('current_medications') }}"></div>
                        <div class="field"><label for="hh_other_conditions">Other Conditions</label><input id="hh_other_conditions" name="health_history[other_conditions]" type="text" value="{{ $hhText('other_conditions') }}"></div>
                    </div>
                </div>

                <div class="student-section">
                    <h4>Family History</h4>
                    <div class="sr-checkgrid">
                        <label><input type="checkbox" name="health_history[fam_hypertension]" value="1" {{ $hhFlag('fam_hypertension') ? 'checked' : '' }}> Hypertension</label>
                        <label><input type="checkbox" name="health_history[fam_diabetes]" value="1" {{ $hhFlag('fam_diabetes') ? 'checked' : '' }}> Diabetes</label>
                        <label><input type="checkbox" name="health_history[fam_heart]" value="1" {{ $hhFlag('fam_heart') ? 'checked' : '' }}> Heart Disease</label>
                        <label><input type="checkbox" name="health_history[fam_cancer]" value="1" {{ $hhFlag('fam_cancer') ? 'checked' : '' }}> Cancer</label>
                        <label><input type="checkbox" name="health_history[fam_mental]" value="1" {{ $hhFlag('fam_mental') ? 'checked' : '' }}> Mental Health Conditions</label>
                    </div>
                    <div class="student-grid" style="margin-top:10px;">
                        <div class="field full"><label for="hh_genetic_disorders">Genetic / Hereditary Disorders</label><input id="hh_genetic_disorders" name="health_history[genetic_disorders]" type="text" value="{{ $hhText('genetic_disorders') }}"></div>
                    </div>
                </div>

                <div class="student-section">
                    <h4>General Appearance</h4>
                    @php $consciousness = $hhText('consciousness', 'Alert'); $posture = $hhText('posture', 'Normal'); $hygiene = $hhText('hygiene', 'Adequate'); @endphp
                    <div class="student-grid">
                        <div class="field">
                            <label>Level of Consciousness</label>
                            <div class="sr-radios">
                                <label><input type="radio" name="health_history[consciousness]" value="Alert" {{ $consciousness === 'Alert' ? 'checked' : '' }}> Alert</label>
                                <label><input type="radio" name="health_history[consciousness]" value="Drowsy" {{ $consciousness === 'Drowsy' ? 'checked' : '' }}> Drowsy</label>
                                <label><input type="radio" name="health_history[consciousness]" value="Other" {{ $consciousness === 'Other' ? 'checked' : '' }}> Other</label>
                            </div>
                            <input id="hh_consciousness_other" name="health_history[consciousness_other]" type="text" placeholder="If other, specify" value="{{ $hhText('consciousness_other') }}">
                        </div>
                        <div class="field">
                            <label>Posture / Gait</label>
                            <div class="sr-radios">
                                <label><input type="radio" name="health_history[posture]" value="Normal" {{ $posture === 'Normal' ? 'checked' : '' }}> Normal</label>
                                <label><input type="radio" name="health_history[posture]" value="Abnormal" {{ $posture === 'Abnormal' ? 'checked' : '' }}> Abnormal</label>
                            </div>
                            <input id="hh_posture_detail" name="health_history[posture_detail]" type="text" placeholder="If abnormal, specify" value="{{ $hhText('posture_detail') }}">
                        </div>
                        <div class="field">
                            <label>Hygiene / Grooming</label>
                            <div class="sr-radios">
                                <label><input type="radio" name="health_history[hygiene]" value="Adequate" {{ $hygiene === 'Adequate' ? 'checked' : '' }}> Adequate</label>
                                <label><input type="radio" name="health_history[hygiene]" value="Needs Attention" {{ $hygiene === 'Needs Attention' ? 'checked' : '' }}> Needs Attention</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="student-section">
                    <h4>Vital Signs</h4>
                    <div class="student-grid" style="margin-bottom:10px;">
                        <div class="field"><label for="weight">Weight (kg) <span style="color:#D95C5C">*</span></label><input id="weight" name="weight_kg" type="number" step="0.1" min="0.1" max="200" placeholder="e.g., 34" value="{{ old('weight_kg') }}" required><div class="muted" style="font-size:.7rem;">Valid range: 0.1 - 200 kg</div></div>
                        <div class="field"><label for="height">Height (m) <span style="color:#D95C5C">*</span></label><input id="height" name="height_m" type="number" step="0.01" min="0.50" max="2.50" placeholder="e.g., 1.27" value="{{ old('height_cm') ? number_format(old('height_cm') / 100, 2, '.', '') : '' }}" required><div class="muted" style="font-size:.7rem;">Convert cm to m: 127 cm = 1.27 m | Valid range: 0.50 - 2.50 m</div></div>
                        <div class="field"><label for="temperature">Temp (&deg;C)</label><input id="temperature" name="temperature_c" type="number" step="0.1" min="25" max="45" placeholder="e.g., 36.5" value="{{ old('temperature_c') }}"></div>
                        <div class="field"><label for="pulse">Pulse (BPM)</label><input id="pulse" name="pulse_bpm" type="number" step="1" min="20" max="250" placeholder="e.g., 72" value="{{ old('pulse_bpm') }}"></div>
                        <div class="field"><label for="bloodPressure">BP (mmHg)</label><input id="bloodPressure" name="blood_pressure" type="text" maxlength="20" placeholder="e.g., 110/70" value="{{ old('blood_pressure') }}"></div>
                    </div>
                </div>
                </div>{{-- end Sheet 1 --}}

                @php
                    // On a validation redirect, unchecked boxes are simply absent from
                    // old input — so the presence of the array, not of each key, decides
                    // whether to fall back to the default checked state.
                    $sr = old('systems_review');
                    $srPosted = is_array($sr);
                    $srFlag = fn (string $key, bool $default = false) => $srPosted ? ! empty($sr[$key]) : $default;
                    $srText = fn (string $key, string $default = '') => $srPosted ? (string) ($sr[$key] ?? '') : $default;
                @endphp

                <div class="sheet-panel" id="sheetPanel2" role="tabpanel" aria-labelledby="sheetTab2">
                    <div class="student-section">
                        <h4>Systems Review</h4>

                        <div class="sr-block">
                            <span class="sr-label">Skin / Integumentary</span>
                            <div class="sr-checkgrid">
                                <label><input type="checkbox" name="systems_review[skin_normal]" value="1" {{ $srFlag('skin_normal', true) ? 'checked' : '' }}> Normal</label>
                                <label><input type="checkbox" name="systems_review[skin_lesions]" value="1" {{ $srFlag('skin_lesions') ? 'checked' : '' }}> Lesions / Rashes</label>
                                <label><input type="checkbox" name="systems_review[skin_pallor]" value="1" {{ $srFlag('skin_pallor') ? 'checked' : '' }}> Pallor</label>
                            </div>
                        </div>

                        <div class="sr-block">
                            <span class="sr-label">HEENT (Head, Eyes, Ears, Nose, Throat)</span>
                            <div class="sr-checkgrid">
                                <label><input type="checkbox" name="systems_review[heent_normal]" value="1" {{ $srFlag('heent_normal', true) ? 'checked' : '' }}> Normal</label>
                                <label><input type="checkbox" name="systems_review[heent_abnormal]" value="1" {{ $srFlag('heent_abnormal') ? 'checked' : '' }}> Abnormal</label>
                            </div>
                            <div class="student-grid" style="margin-top:8px;">
                                <div class="field"><label for="sr_right_eye">Right Eye</label><input id="sr_right_eye" name="systems_review[right_eye]" type="text" placeholder="e.g., 20/20" value="{{ $srText('right_eye') }}"></div>
                                <div class="field"><label for="sr_left_eye">Left Eye</label><input id="sr_left_eye" name="systems_review[left_eye]" type="text" placeholder="e.g., 20/25" value="{{ $srText('left_eye') }}"></div>
                            </div>
                        </div>

                        <div class="sr-block">
                            <span class="sr-label">Respiratory</span>
                            <div class="sr-checkgrid">
                                <label><input type="checkbox" name="systems_review[resp_clear]" value="1" {{ $srFlag('resp_clear', true) ? 'checked' : '' }}> Clear breath sounds</label>
                                <label><input type="checkbox" name="systems_review[resp_cough]" value="1" {{ $srFlag('resp_cough') ? 'checked' : '' }}> Cough</label>
                            </div>
                        </div>

                        <div class="sr-block">
                            <span class="sr-label">Cardiovascular</span>
                            <div class="sr-checkgrid">
                                <label><input type="checkbox" name="systems_review[cardio_regular]" value="1" {{ $srFlag('cardio_regular', true) ? 'checked' : '' }}> Regular rhythm</label>
                                <label><input type="checkbox" name="systems_review[cardio_irregular]" value="1" {{ $srFlag('cardio_irregular') ? 'checked' : '' }}> Irregular</label>
                            </div>
                        </div>

                        <div class="sr-block">
                            <span class="sr-label">Abdomen / GI</span>
                            <div class="sr-checkgrid">
                                <label><input type="checkbox" name="systems_review[abdo_soft]" value="1" {{ $srFlag('abdo_soft', true) ? 'checked' : '' }}> Soft, non-tender</label>
                                <label><input type="checkbox" name="systems_review[abdo_pain]" value="1" {{ $srFlag('abdo_pain') ? 'checked' : '' }}> Pain</label>
                            </div>
                        </div>

                        <div class="sr-block">
                            <span class="sr-label">Neurologic</span>
                            <div class="sr-checkgrid">
                                <label><input type="checkbox" name="systems_review[neuro_alert]" value="1" {{ $srFlag('neuro_alert', true) ? 'checked' : '' }}> Alert, oriented</label>
                                <label><input type="checkbox" name="systems_review[neuro_reflexes]" value="1" {{ $srFlag('neuro_reflexes', true) ? 'checked' : '' }}> Reflexes normal</label>
                                <label><input type="checkbox" name="systems_review[neuro_abnormal]" value="1" {{ $srFlag('neuro_abnormal') ? 'checked' : '' }}> Abnormal</label>
                            </div>
                        </div>

                        <div class="sr-block">
                            <span class="sr-label">Dental</span>
                            <div class="sr-checkgrid">
                                <label><input type="checkbox" name="systems_review[dental_good]" value="1" {{ $srFlag('dental_good', true) ? 'checked' : '' }}> Good</label>
                                <label><input type="checkbox" name="systems_review[dental_fair]" value="1" {{ $srFlag('dental_fair') ? 'checked' : '' }}> Fair</label>
                                <label><input type="checkbox" name="systems_review[dental_poor]" value="1" {{ $srFlag('dental_poor') ? 'checked' : '' }}> Poor</label>
                                <label><input type="checkbox" name="systems_review[dental_caries]" value="1" {{ $srFlag('dental_caries') ? 'checked' : '' }}> Dental caries</label>
                                <label><input type="checkbox" name="systems_review[dental_gum]" value="1" {{ $srFlag('dental_gum') ? 'checked' : '' }}> Gum inflammation</label>
                                <label><input type="checkbox" name="systems_review[dental_referral]" value="1" {{ $srFlag('dental_referral') ? 'checked' : '' }}> Referral to dentist</label>
                            </div>
                        </div>

                        <div class="sr-block">
                            <span class="sr-label">Immunization Status</span>
                            <div class="sr-checkgrid">
                                <label><input type="checkbox" name="systems_review[immun_complete]" value="1" {{ $srFlag('immun_complete', true) ? 'checked' : '' }}> Complete</label>
                                <label><input type="checkbox" name="systems_review[immun_incomplete]" value="1" {{ $srFlag('immun_incomplete') ? 'checked' : '' }}> Incomplete</label>
                                <label><input type="checkbox" name="systems_review[immun_not_available]" value="1" {{ $srFlag('immun_not_available') ? 'checked' : '' }}> Not available</label>
                            </div>
                            <div class="student-grid" style="margin-top:8px;">
                                <div class="field"><label for="sr_immun_date">Date Record Reviewed</label><input id="sr_immun_date" name="systems_review[immun_date]" type="date" value="{{ $srText('immun_date') }}"></div>
                            </div>
                        </div>
                    </div>

                    <div class="student-section">
                        <h4>Findings &amp; Recommendations</h4>
                        <div class="student-grid">
                            <div class="field full"><label for="sr_notes">Notes / Details</label><textarea id="sr_notes" name="systems_review[notes]" rows="2" placeholder="Additional notes and findings...">{{ $srText('notes') }}</textarea></div>
                            <div class="field full"><label for="sr_summary">Summary of Findings</label><textarea id="sr_summary" name="systems_review[summary]" rows="2" placeholder="Summary of health assessment findings...">{{ $srText('summary') }}</textarea></div>
                            <div class="field full"><label for="sr_recommendations">Recommendations / Referrals</label><textarea id="sr_recommendations" name="systems_review[recommendations]" rows="2" placeholder="Recommendations and referrals...">{{ $srText('recommendations') }}</textarea></div>

                            <div class="field full">
                                <label>Examiner Signature</label>
                                <div class="sig-wrapper">
                                    <div class="sig-tabs">
                                        <button type="button" class="sig-tab active" data-sigpanel="sigPanelDraw">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                                            Draw Signature
                                        </button>
                                        <button type="button" class="sig-tab" data-sigpanel="sigPanelUpload">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                            Upload Image
                                        </button>
                                    </div>
                                    <div class="sig-panel active" id="sigPanelDraw">
                                        <canvas id="signatureCanvas"></canvas>
                                        <div class="sig-actions">
                                            <button type="button" class="sig-clear" id="signatureClear">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 20H7L3 16a2 2 0 0 1 0-3l9-9a2 2 0 0 1 3 0l5 5a2 2 0 0 1 0 3l-8 8"/></svg>
                                                Clear
                                            </button>
                                            <span class="sig-hint">Sign above using your mouse or touchscreen</span>
                                        </div>
                                    </div>
                                    <div class="sig-panel" id="sigPanelUpload">
                                        <div class="sig-upload" id="signatureUploadArea" role="button" tabindex="0">
                                            <div id="signatureUploadPreview">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                                <p>Click to upload a signature image</p>
                                                <p class="sig-hint">PNG or JPG, max 2MB</p>
                                            </div>
                                        </div>
                                        <input type="file" id="signatureFileInput" accept="image/png,image/jpeg" hidden>
                                    </div>
                                </div>
                                <input type="hidden" id="examinerSignatureData" name="systems_review[examiner_signature]" value="{{ $srText('examiner_signature') }}">
                            </div>

                            <div class="field"><label for="sr_examiner_name">Examiner Name</label><input id="sr_examiner_name" name="systems_review[examiner_name]" type="text" placeholder="e.g., Maria Santos, RN" value="{{ $srText('examiner_name', session('active_name', '')) }}"></div>
                            <div class="field"><label for="sr_examiner_date">Date</label><input id="sr_examiner_date" name="systems_review[examiner_date]" type="date" value="{{ $srText('examiner_date') }}"></div>
                        </div>
                    </div>
                </div>{{-- end Sheet 2 --}}

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
            <div class="confirm-info">Please review the information before submitting.</div>
            <div id="summaryContainer"></div>
            <div class="confirm-actions">
                <button type="button" class="btn btn-secondary" id="confirmEditBtn">Edit</button>
                <button type="button" class="btn" id="confirmSubmitBtn">Confirm &amp; Submit</button>
            </div>
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

// Shared by the sidebar's real links (?tab=...), the Cancel button, and the
// dashboard's quick-action cards — switches the visible .section-panel
// directly, without needing a clickable nav element for every tab.
const ADVISER_TAB_LABELS = {
    'prototype-dashboard-panel': 'Dashboard',
    'prototype-saved-panel': 'My Students',
    'prototype-form-panel': 'Enroll Student',
};

window.switchAdviserTab = (targetId) => {
    document.querySelectorAll('.section-panel').forEach((panel) => {
        panel.classList.toggle('active', panel.id === targetId);
    });

    // Keep the topbar breadcrumb on the panel that is actually showing.
    const label = ADVISER_TAB_LABELS[targetId];
    if (label) {
        const title = document.getElementById('asbCrumbTitle');
        const current = document.getElementById('asbCrumbCurrent');
        if (title) title.textContent = label;
        if (current) current.textContent = label;
    }
};

(() => {
    if (!document.querySelector('.section-panel')) {
        return;
    }

    const tabParam = new URLSearchParams(window.location.search).get('tab');
    const targetId = tabParam === 'saved'
        ? 'prototype-saved-panel'
        : tabParam === 'form'
            ? 'prototype-form-panel'
            : 'prototype-dashboard-panel';

    window.switchAdviserTab(targetId);
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
                    backgroundColor: ['#126B3A', '#F2B84B', '#10b981', '#3D8FA3', '#8b5cf6', '#D95C5C'],
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
                        backgroundColor: '#126B3A',
                        borderRadius: 8,
                        yAxisID: 'y',
                    },
                    {
                        label: `Endline (${dashboardEndlineMonthLabel})`,
                        data: dashboardEndlineValues,
                        backgroundColor: '#3D8FA3',
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
                title: 'Vital Signs',
                rows: [
                    ['Weight', `${byId('weight')?.value || '-'} kg`],
                    ['Height', `${byId('height')?.value || '-'} m`],
                    ['Temperature', byId('temperature')?.value ? `${byId('temperature').value} °C` : '-'],
                    ['Pulse', byId('pulse')?.value ? `${byId('pulse').value} bpm` : '-'],
                    ['Blood Pressure', byId('bloodPressure')?.value || '-'],
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
        // A control inside a hidden sheet can't be focused, so reportValidity()
        // would fail without ever showing its message. Reveal the sheet holding
        // the first invalid field before validating.
        const firstInvalid = form.querySelector(':invalid');
        const invalidSheet = firstInvalid?.closest('.sheet-panel');
        if (invalidSheet && !invalidSheet.classList.contains('active')) {
            window.showAdviserSheet?.(invalidSheet.id);
        }

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
        window.showAdviserSheet?.('sheetPanel1');
        // The form is only reachable from My Students, so Cancel returns there.
        window.switchAdviserTab?.('prototype-saved-panel');
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

// My Students table: search + profile-status filter + pagination. Every row is
// rendered server-side; this only decides which of them are on screen.
(() => {
    const searchInput = document.getElementById('studentsSearch');
    const statusSelect = document.getElementById('studentsStatusFilter');
    const tbody = document.getElementById('studentsTableBody');

    if (!searchInput || !statusSelect || !tbody) {
        return;
    }

    const rows = Array.from(tbody.querySelectorAll('.js-student-row'));
    const noMatchRow = tbody.querySelector('.js-students-nomatch');
    const pagination = document.getElementById('msPagination');
    const startOut = document.getElementById('msShowingStart');
    const endOut = document.getElementById('msShowingEnd');
    const totalOut = document.getElementById('msShowingTotal');
    const countBadge = document.getElementById('studentsCountBadge');
    const perPage = 8;

    let page = 1;
    let matches = rows.slice();

    const setPage = (next) => {
        page = next;
        render();
    };

    const renderPagination = (totalPages) => {
        if (!pagination) {
            return;
        }

        pagination.innerHTML = '';
        if (matches.length <= perPage) {
            return;
        }

        const addButton = (label, targetPage, { disabled = false, active = false } = {}) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = label;
            button.disabled = disabled;
            if (active) {
                button.classList.add('active');
            }
            if (!disabled && !active) {
                button.addEventListener('click', () => setPage(targetPage));
            }
            pagination.appendChild(button);
        };

        addButton('‹', page - 1, { disabled: page === 1 });

        const maxVisible = 5;
        let startPage = Math.max(1, page - Math.floor(maxVisible / 2));
        const endPage = Math.min(totalPages, startPage + maxVisible - 1);
        startPage = Math.max(1, Math.min(startPage, endPage - maxVisible + 1));

        for (let i = startPage; i <= endPage; i += 1) {
            addButton(String(i), i, { active: i === page });
        }

        addButton('›', page + 1, { disabled: page === totalPages });
    };

    const render = () => {
        const totalPages = Math.max(1, Math.ceil(matches.length / perPage));
        page = Math.min(Math.max(page, 1), totalPages);

        const start = (page - 1) * perPage;
        const end = Math.min(start + perPage, matches.length);
        const onPage = new Set(matches.slice(start, end));

        rows.forEach((row) => {
            row.style.display = onPage.has(row) ? '' : 'none';
        });

        // Only shown when a search or filter hides every row — the server-rendered
        // "no students yet" row covers a genuinely empty roster.
        if (noMatchRow) {
            noMatchRow.hidden = rows.length === 0 || matches.length > 0;
        }

        if (startOut) startOut.textContent = String(matches.length === 0 ? 0 : start + 1);
        if (endOut) endOut.textContent = String(end);
        if (totalOut) totalOut.textContent = String(matches.length);
        if (countBadge) countBadge.textContent = String(matches.length);

        renderPagination(totalPages);
    };

    const applyFilters = () => {
        const keyword = searchInput.value.trim().toLowerCase();
        const status = statusSelect.value;

        matches = rows.filter((row) => {
            const name = row.dataset.name || '';
            const lrn = row.dataset.lrn || '';
            const keywordMatch = !keyword || name.includes(keyword) || lrn.includes(keyword);
            const statusMatch = status === 'all' || (row.dataset.status || '') === status;

            return keywordMatch && statusMatch;
        });

        // A narrower result set can leave the current page out of range.
        page = 1;
        render();
    };

    searchInput.addEventListener('input', applyFilters);
    statusSelect.addEventListener('change', applyFilters);

    // The topbar search (?tab=saved&q=...) feeds this same input rather than
    // duplicating filter logic server-side.
    const urlQuery = new URLSearchParams(window.location.search).get('q');
    if (urlQuery) {
        searchInput.value = urlQuery;
    }

    applyFilters();
})();

// Enrolment form sheets. Both tabs are always enabled — Sheet 2 is reachable
// before Sheet 1 is filled in.
window.showAdviserSheet = (panelId) => {
    const panels = Array.from(document.querySelectorAll('.sheet-panel'));
    if (!panels.length || !panelId) {
        return;
    }

    panels.forEach((panel) => panel.classList.toggle('active', panel.id === panelId));
    document.querySelectorAll('.sheet-tab').forEach((tab) => {
        const selected = tab.dataset.sheet === panelId;
        tab.classList.toggle('active', selected);
        tab.setAttribute('aria-selected', selected ? 'true' : 'false');
    });

    // The signature canvas can only be sized once Sheet 2 is actually visible.
    if (panelId === 'sheetPanel2') {
        requestAnimationFrame(() => window.initSignaturePad?.());
    }
};

(() => {
    document.querySelectorAll('.sheet-tab').forEach((tab) => {
        tab.addEventListener('click', () => window.showAdviserSheet(tab.dataset.sheet));
    });
})();

// Examiner signature — draw on a canvas or upload an image. Either way the
// result lands in one hidden input as a data URL.
(() => {
    const canvas = document.getElementById('signatureCanvas');
    const dataInput = document.getElementById('examinerSignatureData');
    const uploadArea = document.getElementById('signatureUploadArea');
    const uploadPreview = document.getElementById('signatureUploadPreview');
    const fileInput = document.getElementById('signatureFileInput');

    if (!canvas || !dataInput) {
        return;
    }

    const MAX_BYTES = 2 * 1024 * 1024;
    const EMPTY_UPLOAD = uploadPreview ? uploadPreview.innerHTML : '';
    let ctx = null;
    let drawing = false;

    const wipeCanvas = () => {
        if (!ctx) {
            return;
        }
        const rect = canvas.getBoundingClientRect();
        ctx.clearRect(0, 0, rect.width, rect.height);
    };

    // Resizing a canvas clears its bitmap, so anything already captured is
    // painted back afterwards.
    const restore = () => {
        const value = dataInput.value;
        if (!value || !ctx || value.indexOf('data:image/') !== 0) {
            return;
        }
        const rect = canvas.getBoundingClientRect();
        const image = new Image();
        image.onload = () => ctx.drawImage(image, 0, 0, rect.width, rect.height);
        image.src = value;
    };

    // The pad lives inside a hidden panel until Sheet 2 is opened, and a hidden
    // element measures 0 wide — so sizing is deferred until it is on screen.
    const ensureCanvas = () => {
        const rect = canvas.getBoundingClientRect();
        if (rect.width === 0) {
            return false;
        }

        const ratio = window.devicePixelRatio || 1;
        const width = Math.round(rect.width * ratio);
        const height = Math.round(rect.height * ratio);

        if (ctx && canvas.width === width && canvas.height === height) {
            return true;
        }

        canvas.width = width;
        canvas.height = height;
        ctx = canvas.getContext('2d');
        ctx.scale(ratio, ratio);
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#0d2b1e';
        restore();
        return true;
    };

    const pointAt = (event) => {
        const rect = canvas.getBoundingClientRect();
        return { x: event.clientX - rect.left, y: event.clientY - rect.top };
    };

    canvas.addEventListener('pointerdown', (event) => {
        if (!ensureCanvas()) {
            return;
        }
        drawing = true;
        canvas.setPointerCapture(event.pointerId);
        const point = pointAt(event);
        ctx.beginPath();
        ctx.moveTo(point.x, point.y);
        event.preventDefault();
    });

    canvas.addEventListener('pointermove', (event) => {
        if (!drawing) {
            return;
        }
        const point = pointAt(event);
        ctx.lineTo(point.x, point.y);
        ctx.stroke();
        event.preventDefault();
    });

    const stopDrawing = (event) => {
        if (!drawing) {
            return;
        }
        drawing = false;
        if (canvas.hasPointerCapture(event.pointerId)) {
            canvas.releasePointerCapture(event.pointerId);
        }
        dataInput.value = canvas.toDataURL('image/png');
    };

    canvas.addEventListener('pointerup', stopDrawing);
    canvas.addEventListener('pointercancel', stopDrawing);

    const resetUpload = () => {
        if (uploadPreview) {
            uploadPreview.innerHTML = EMPTY_UPLOAD;
        }
        if (fileInput) {
            fileInput.value = '';
        }
    };

    window.initSignaturePad = () => { ensureCanvas(); };

    window.clearSignaturePad = () => {
        wipeCanvas();
        dataInput.value = '';
        resetUpload();
    };

    document.getElementById('signatureClear')?.addEventListener('click', () => window.clearSignaturePad());

    document.querySelectorAll('.sig-tab').forEach((tab) => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.sig-tab').forEach((t) => t.classList.toggle('active', t === tab));
            document.querySelectorAll('.sig-panel').forEach((panel) => {
                panel.classList.toggle('active', panel.id === tab.dataset.sigpanel);
            });
            if (tab.dataset.sigpanel === 'sigPanelDraw') {
                requestAnimationFrame(() => ensureCanvas());
            }
        });
    });

    uploadArea?.addEventListener('click', () => fileInput?.click());
    uploadArea?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            fileInput?.click();
        }
    });

    fileInput?.addEventListener('change', () => {
        const file = fileInput.files && fileInput.files[0];
        if (!file) {
            return;
        }

        const reject = (message) => {
            resetUpload();
            if (uploadPreview) {
                const error = document.createElement('p');
                error.className = 'sig-error';
                error.textContent = message;
                uploadPreview.appendChild(error);
            }
        };

        if (!['image/png', 'image/jpeg'].includes(file.type)) {
            reject('That file is not a PNG or JPG.');
            return;
        }
        if (file.size > MAX_BYTES) {
            reject('That image is larger than 2MB.');
            return;
        }

        const reader = new FileReader();
        reader.onload = (event) => {
            dataInput.value = event.target.result;
            if (uploadPreview) {
                uploadPreview.textContent = '';
                const image = document.createElement('img');
                image.src = event.target.result;
                image.alt = 'Uploaded signature';
                uploadPreview.appendChild(image);
            }
            // An upload replaces anything drawn, so the two never disagree.
            wipeCanvas();
        };
        reader.readAsDataURL(file);
    });
})();

// Enrol / edit modes share one form. Editing posts the same LRN, which the
// controller resolves to the existing record instead of a second one.
(() => {
    const form = document.getElementById('studentForm');
    const title = document.getElementById('enrolFormTitle');
    const sub = document.getElementById('enrolFormSub');
    const lrnInput = document.getElementById('proto_lrn');

    if (!form) {
        return;
    }

    const ENROL_SUB = sub?.textContent || '';
    const EDIT_SUB = 'Update this learner’s record. The LRN identifies the record, so it stays fixed here.';

    const setValue = (id, value) => {
        const node = document.getElementById(id);
        if (node) {
            node.value = value === null || value === undefined ? '' : String(value);
        }
    };

    // Nudge the BMI / height-for-age calculator and the birth-date part sync,
    // which both listen for real user input.
    const recalculate = () => {
        ['height', 'weight', 'birthDate'].forEach((id) => {
            document.getElementById(id)?.dispatchEvent(new Event('input', { bubbles: true }));
        });
    };

    const setMode = (editing) => {
        if (title) title.textContent = editing ? 'Edit Student Profile' : 'Enroll Student';
        if (sub) sub.textContent = editing ? EDIT_SUB : ENROL_SUB;
        if (lrnInput) lrnInput.readOnly = editing;
        form.dataset.mode = editing ? 'edit' : 'enrol';
    };

    const fillForm = (record) => {
        setValue('proto_last_name', record.last_name);
        setValue('proto_first_name', record.first_name);
        setValue('proto_middle_name', record.middle_name);
        setValue('proto_lrn', record.lrn);
        setValue('proto_birthplace', record.birthplace);
        setValue('gender', record.gender);
        setValue('proto_parent_guardian', record.parent_guardian);
        setValue('proto_telephone_no', record.telephone_no);
        setValue('proto_address', record.address);
        setValue('weight', record.weight_kg);
        setValue('temperature', record.temperature_c);
        setValue('pulse', record.pulse_bpm);
        setValue('bloodPressure', record.blood_pressure);

        const heightCm = Number(record.height_cm);
        setValue('height', Number.isFinite(heightCm) && heightCm > 0 ? (heightCm / 100).toFixed(2) : '');

        const { birth_year: year, birth_month: month, birth_day: day } = record;
        setValue('birthDate', year && month && day
            ? `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
            : '');

        // Both grouped sections repopulate the same way: checkboxes by truthiness,
        // radios by matching value, everything else by assignment.
        const fillGroup = (prefix, values) => {
            const data = values && typeof values === 'object' ? values : {};

            form.querySelectorAll(`[name^="${prefix}["]`).forEach((node) => {
                const key = node.name.slice(prefix.length + 1, -1);

                if (node.type === 'checkbox') {
                    node.checked = Boolean(data[key]);
                } else if (node.type === 'radio') {
                    node.checked = data[key] === node.value;
                } else {
                    node.value = data[key] ?? '';
                }
            });
        };

        fillGroup('systems_review', record.systems_review);
        fillGroup('health_history', record.health_history);

        recalculate();
    };

    window.openEnrolmentForm = () => {
        form.reset();
        // form.reset() restores input values but not the canvas bitmap.
        window.clearSignaturePad?.();
        setMode(false);
        recalculate();
        window.switchAdviserTab?.('prototype-form-panel');
        window.showAdviserSheet?.('sheetPanel1');
    };

    // The roster keeps no copy of the signature image, so the pad opens empty
    // on edit; leaving it blank keeps whatever is already on file.
    window.openEditForm = (record) => {
        form.reset();
        window.clearSignaturePad?.();
        setMode(true);
        fillForm(record);
        window.switchAdviserTab?.('prototype-form-panel');
        window.showAdviserSheet?.('sheetPanel1');
    };

    document.getElementById('openAddStudentBtn')?.addEventListener('click', window.openEnrolmentForm);
    document.getElementById('openAddStudentEmptyBtn')?.addEventListener('click', window.openEnrolmentForm);
    document.getElementById('openSavedFromDashboard')?.addEventListener('click', () => {
        window.switchAdviserTab?.('prototype-saved-panel');
    });

    document.querySelectorAll('.js-student-edit').forEach((button) => {
        button.addEventListener('click', () => {
            let record;
            try {
                record = JSON.parse(button.getAttribute('data-record') || '{}');
            } catch (_err) {
                record = {};
            }
            window.openEditForm(record);
        });
    });

    // The student profile page's Edit Profile button links back here with
    // ?tab=saved&edit=LRN, since editing itself only happens on this page.
    const editLrn = new URLSearchParams(window.location.search).get('edit');
    if (editLrn) {
        document.querySelector(`.js-student-edit[data-lrn="${CSS.escape(editLrn)}"]`)?.click();
    }
})();

// ── Health Assessment: BMI auto-calc + conditional reveals ──────────
(() => {
    const heightInput = document.getElementById('haVitalHeight');
    const weightInput = document.getElementById('haVitalWeight');
    const bmiInput    = document.getElementById('haVitalBmi');

    const recalcBmi = () => {
        const h = parseFloat(heightInput?.value);
        const w = parseFloat(weightInput?.value);
        if (bmiInput && h > 0 && w > 0) {
            bmiInput.value = (w / Math.pow(h / 100, 2)).toFixed(2);
        } else if (bmiInput) {
            bmiInput.value = '';
        }
    };

    heightInput?.addEventListener('input', recalcBmi);
    weightInput?.addEventListener('input', recalcBmi);

    // Allergy checkbox → reveal text input
    const allergyCheck = document.getElementById('haAllergyCheck');
    const allergyDetail = document.querySelector('input[name="med_allergies_detail"]');
    allergyCheck?.addEventListener('change', () => {
        if (allergyDetail) allergyDetail.style.display = allergyCheck.checked ? 'block' : 'none';
    });

    // Hospitalization checkbox → reveal text input
    const hospCheck = document.getElementById('haHospCheck');
    const hospDetail = document.querySelector('input[name="med_hospitalization_detail"]');
    hospCheck?.addEventListener('change', () => {
        if (hospDetail) hospDetail.style.display = hospCheck.checked ? 'block' : 'none';
    });

    // Level of consciousness "Other" radio → reveal text input
    const consciousOtherRadio = document.getElementById('haConsciousOtherRadio');
    const consciousOtherText  = document.getElementById('haConsciousOtherText');
    document.querySelectorAll('input[name="appearance_consciousness"]').forEach((radio) => {
        radio.addEventListener('change', () => {
            if (consciousOtherText) {
                consciousOtherText.style.display = (radio.value === 'Other' && radio.checked) ? 'inline-block' : 'none';
            }
        });
    });

    // Posture/Gait "Abnormal" radio → reveal text input
    const postureDetail = document.getElementById('haPostureDetail');
    document.querySelectorAll('input[name="appearance_posture_gait"]').forEach((radio) => {
        radio.addEventListener('change', () => {
            if (postureDetail) {
                postureDetail.style.display = (radio.value === 'Abnormal' && radio.checked) ? 'inline-block' : 'none';
            }
        });
    });
})();
</script>

<script>
// Recent Activity, live: relative timestamps tick locally every 30s, and a
// no-PII pulse endpoint is polled so the list is only re-fetched when
// something in the class actually changed.
(() => {
    const list = document.getElementById('recentActivityList');
    if (!list) {
        return;
    }

    const feedUrl = list.dataset.feedUrl;
    const pulseUrl = list.dataset.pulseUrl;
    const PULSE_MS = 20000;
    const TICK_MS = 30000;

    const icons = {
        declined: '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>',
        student: '<path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/>',
        certificate: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
        default: '<polyline points="20 6 9 17 4 12"/>',
    };

    // Mirrors Carbon's diffForHumans closely enough to keep the server's first
    // paint and the client's ticking from disagreeing.
    const humanize = (iso) => {
        const then = new Date(iso).getTime();
        if (Number.isNaN(then)) {
            return '';
        }

        const seconds = Math.round((Date.now() - then) / 1000);
        if (seconds < 0) {
            return 'just now';
        }

        const units = [
            [31536000, 'year'],
            [2592000, 'month'],
            [604800, 'week'],
            [86400, 'day'],
            [3600, 'hour'],
            [60, 'minute'],
        ];

        for (const [size, label] of units) {
            if (seconds >= size) {
                const value = Math.floor(seconds / size);
                return `${value} ${label}${value === 1 ? '' : 's'} ago`;
            }
        }

        return seconds < 10 ? 'just now' : `${seconds} seconds ago`;
    };

    const tick = () => {
        list.querySelectorAll('.ra-ago').forEach((el) => {
            const iso = el.getAttribute('datetime');
            if (iso) {
                el.textContent = humanize(iso);
            }
        });
    };

    const buildRow = (item) => {
        const row = document.createElement('div');
        row.className = 'ra-row';
        row.dataset.activityId = item.id;

        const icon = document.createElement('div');
        icon.className = `ra-icon ra-icon-${item.icon}`;
        // Icon glyphs come from the map above, never from the response.
        icon.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">${icons[item.icon] || icons.default}</svg>`;

        const body = document.createElement('div');
        body.className = 'ra-body';

        const text = document.createElement('div');
        text.className = 'ra-text';
        text.textContent = item.text;

        const meta = document.createElement('div');
        meta.className = 'ra-meta';

        const badge = document.createElement('span');
        badge.className = 'ra-badge';
        badge.textContent = item.badge;

        const ago = document.createElement('time');
        ago.className = 'ra-ago';
        ago.setAttribute('datetime', item.at);
        ago.textContent = humanize(item.at) || item.ago;

        meta.append(badge, ago);
        body.append(text, meta);
        row.append(icon, body);

        return row;
    };

    const paint = (items) => {
        list.textContent = '';

        if (!items.length) {
            const empty = document.createElement('p');
            empty.className = 'muted ra-empty';
            empty.style.padding = '8px 0';
            empty.style.fontSize = '.82rem';
            empty.textContent = 'No recent activity yet.';
            list.append(empty);

            return;
        }

        items.forEach((item) => list.append(buildRow(item)));
    };

    let inFlight = false;

    const refresh = async () => {
        if (inFlight) {
            return;
        }

        inFlight = true;
        try {
            const response = await fetch(feedUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) {
                return;
            }

            const payload = await response.json();
            if (Array.isArray(payload.items)) {
                paint(payload.items);
            }
            if (payload.stamp) {
                list.dataset.stamp = payload.stamp;
            }
        } catch (error) {
            // Offline or a dropped request: keep what is on screen and retry
            // on the next pulse.
        } finally {
            inFlight = false;
        }
    };

    const pulse = async () => {
        if (document.hidden) {
            return;
        }

        try {
            const response = await fetch(pulseUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) {
                return;
            }

            const payload = await response.json();
            if (payload.stamp && payload.stamp !== list.dataset.stamp) {
                list.dataset.stamp = payload.stamp;
                await refresh();
            }
        } catch (error) {
            // Ignored — the next pulse retries.
        }
    };

    setInterval(tick, TICK_MS);
    setInterval(pulse, PULSE_MS);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            tick();
            pulse();
        }
    });
})();
</script>
@include('partials.sidebar-hover-pin')
</body>
</html>
