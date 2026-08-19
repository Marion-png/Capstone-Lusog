<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Dashboard - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <script>document.documentElement.classList.add('js');</script>
    {{-- LUSOG order: theme, then this page's sheet, then the nurse rail. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
    @php $pageCssPath = resource_path('css/school-nurse.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
    <style>{!! file_get_contents(resource_path('css/nurse-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.nurse-lusog-sidebar', ['active' => 'dashboard'])

<div class="main">
    @php
        $nurseName = session('active_name', 'School Nurse');
        $schoolName = session('active_school_name', 'No school assigned');
        $schoolYear = \App\Models\StudentHealthRecord::currentSchoolYear();
        $greetHour = (int) now()->format('G');
        $greeting = $greetHour < 12 ? 'Good morning' : ($greetHour < 18 ? 'Good afternoon' : 'Good evening');
    @endphp

    <header class="topbar">
        <div class="topbar-bc"><span>School Nurse</span><span class="bc-sep">&rsaquo;</span><span>Dashboard</span></div>

        @include('partials.nurse-learner-search')

        <div class="topbar-chip"><span class="dot"></span>{{ $schoolName }} &middot; SY {{ $schoolYear }}</div>
        @include('partials.live-clock')
    </header>

    <div class="content">
        <div class="page-header">
            <div class="card-head" style="margin-bottom:0">
                <div>
                    <div class="page-eyebrow">{{ $greeting }}, School Nurse</div>
                    <h1 class="page-title">Dashboard <span>School Clinic</span></h1>
                    <p class="page-sub">
                        {{ $nurseName }} &middot; {{ $schoolName }} &middot; School Year {{ $schoolYear }}.
                        Today's consultations, learners needing attention, and stock running short.
                    </p>
                </div>
                <span class="badge badge-normal">Clinic Open</span>
            </div>
        </div>

        <div class="kpi-grid">
            <div class="card kpi accent-brand">
                <div class="kpi-top">
                    <div class="kpi-label">Total Records</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($totalRecords) }}</div>
                <div class="kpi-hint">Learner health cards on file</div>
            </div>

            <div class="card kpi accent-info">
                <div class="kpi-top">
                    <div class="kpi-label">Consultations Today</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 12l2 2 4-4"/><path d="M21 12c0 4.97-4.03 9-9 9S3 16.97 3 12 7.03 3 12 3s9 4.03 9 9z"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($consultationsToday) }}</div>
                <div class="kpi-hint">Logged at the clinic today</div>
            </div>

            <div class="card kpi accent-orange">
                <div class="kpi-top">
                    <div class="kpi-label">At-Risk Learners</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($atRiskCount) }}</div>
                <div class="kpi-hint">Flagged for follow-up</div>
            </div>

            <div class="card kpi accent-amber">
                <div class="kpi-top">
                    <div class="kpi-label">Low Stock Medicines</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="2" width="18" height="20" rx="2"/><path d="M9 2v4h6V2"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($lowStockCount) }}</div>
                <div class="kpi-hint">At or below reorder point</div>
            </div>
        </div>

        <div class="board-row" style="margin-top:20px">
            @include('partials.announcements')
            @include('partials.upcoming-events')
        </div>

        <div class="toolbar">
            <div style="flex:1;min-width:240px">
                <label class="field-label" for="consultSearch">Search</label>
                <div class="lg-search">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" id="consultSearch" placeholder="Search learner, complaint, section..." autocomplete="off">
                </div>
            </div>
            <div>
                <label class="field-label" for="consultDateFilter">Date</label>
                <select class="select" id="consultDateFilter">
                    <option value="all">All Dates</option>
                    <option value="today">Today</option>
                    <option value="week">This Week</option>
                    <option value="month">This Month</option>
                </select>
            </div>
            <div>
                <label class="field-label" for="consultLevelFilter">Level</label>
                <select class="select" id="consultLevelFilter">
                    <option value="all">All Levels</option>
                    <option value="junior">Junior High</option>
                    <option value="senior">Senior High</option>
                    <option value="personnel">Personnel</option>
                </select>
            </div>
            <div class="spacer"></div>
            <div class="toolbar-count" id="consultCount">{{ $recentConsultations->count() }} {{ Str::plural('entry', $recentConsultations->count()) }}</div>
        </div>

        <div class="table-card">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Grade / Section</th>
                            <th>Date</th>
                            <th>Condition</th>
                            <th>Treatment</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="consultTableBody">
                        @forelse($recentConsultations as $c)
                        @php
                            $studentName = trim((string) $c->student_name);
                            $gradeSection = trim((string) $c->grade_section);
                            $condition = trim((string) $c->condition);
                            $treatment = trim((string) $c->treatment_given);

                            $nameParts = array_values(array_filter(explode(' ', $studentName)));
                            $initials = $nameParts === []
                                ? '?'
                                : strtoupper(substr($nameParts[0], 0, 1).substr(end($nameParts), 0, 1));

                            // grade_section is encrypted, so the level bucket is derived
                            // here in PHP from the decrypted label rather than in SQL.
                            preg_match('/\d{1,2}/', $gradeSection, $gradeMatch);
                            $gradeNumber = isset($gradeMatch[0]) ? (int) $gradeMatch[0] : null;
                            $level = match (true) {
                                $gradeNumber !== null && $gradeNumber >= 7 && $gradeNumber <= 10 => 'junior',
                                $gradeNumber !== null && $gradeNumber >= 11 && $gradeNumber <= 12 => 'senior',
                                default => 'personnel',
                            };

                            $consultedAt = $c->consulted_at;
                            $isToday = $consultedAt?->isToday() ?? false;
                            $isThisWeek = $consultedAt?->isSameWeek(now()) ?? false;
                            $isThisMonth = $consultedAt?->isSameMonth(now()) ?? false;
                        @endphp
                        <tr class="js-consult-row"
                            data-search="{{ strtolower($studentName.' '.$gradeSection.' '.$condition.' '.$treatment) }}"
                            data-level="{{ $level }}"
                            data-today="{{ $isToday ? '1' : '0' }}"
                            data-week="{{ $isThisWeek ? '1' : '0' }}"
                            data-month="{{ $isThisMonth ? '1' : '0' }}">
                            <td>
                                <div class="td-person">
                                    <div class="td-avatar">{{ $initials }}</div>
                                    <div class="td-name">{{ $studentName !== '' ? $studentName : '—' }}</div>
                                </div>
                            </td>
                            <td>{{ $gradeSection !== '' ? $gradeSection : '—' }}</td>
                            <td class="tnum">{{ $consultedAt?->format('M j, Y') ?? '—' }}</td>
                            <td>{{ $condition !== '' ? $condition : '—' }}</td>
                            <td>{{ $treatment !== '' ? $treatment : '—' }}</td>
                            <td>
                                @if($c->status === 'referred')
                                    <span class="badge badge-monitor">Referred</span>
                                @else
                                    <span class="badge badge-normal">Treated</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="table-empty">No consultations recorded yet.</td>
                        </tr>
                        @endforelse
                        <tr id="consultNoMatch" hidden>
                            <td colspan="6" class="table-empty">No consultations match your search or filters.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="summary-row">
            <div class="card summary-panel">
                <div class="card-head">
                    <div>
                        <div class="card-title">Top Consultation Cases</div>
                        <div class="card-sub">This month</div>
                    </div>
                </div>
                @php $maxConditionCount = $topConditions->max('total') ?: 1; @endphp
                @forelse($topConditions as $cond)
                <div class="meter-row">
                    <div class="meter-label">{{ Str::ucfirst($cond['name']) }}</div>
                    <div class="meter-track">
                        <div class="meter-fill" style="width:{{ round($cond['total'] / $maxConditionCount * 100) }}%"></div>
                    </div>
                    <div class="meter-value">{{ $cond['total'] }}</div>
                </div>
                @empty
                <div class="empty-panel">No consultations this month.</div>
                @endforelse
            </div>

            <div class="card summary-panel">
                <div class="card-head">
                    <div>
                        <div class="card-title">Medicine Stock Monitor</div>
                        <div class="card-sub">At or below reorder point</div>
                    </div>
                </div>
                @forelse($lowStockMedicines as $med)
                @php
                    $pct = $med->minimum_threshold > 0
                        ? min(100, (int) round($med->stock_quantity / $med->minimum_threshold * 100))
                        : 0;
                    // A quarter of the reorder point or less is critical; the rest
                    // is still worth watching but not yet an emergency.
                    $stockState = $pct <= 25 ? 'is-critical' : 'is-low';
                @endphp
                <div class="stock-row {{ $stockState }}">
                    <div class="stock-meta">
                        <strong>{{ $med->name }}</strong>
                        <span class="tnum">{{ $med->stock_quantity }} / {{ $med->minimum_threshold }} {{ $med->unit }}</span>
                    </div>
                    <div class="meter-track"><div class="meter-fill" style="width:{{ $pct }}%"></div></div>
                </div>
                @empty
                <div class="empty-panel">All medicines are adequately stocked.</div>
                @endforelse
            </div>
        </div>

        <div class="quick-access">
            <a href="{{ route('dashboard.student-health-records') }}" class="card qa-card accent-brand">
                <div class="qa-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <div>
                    <div class="qa-title">Health Records</div>
                    <div class="qa-desc">Review and examine learner health cards</div>
                </div>
            </a>
            <a href="{{ route('dashboard.consultation-log') }}" class="card qa-card accent-info">
                <div class="qa-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 12l2 2 4-4"/><path d="M21 12c0 4.97-4.03 9-9 9S3 16.97 3 12 7.03 3 12 3s9 4.03 9 9z"/></svg>
                </div>
                <div>
                    <div class="qa-title">Consultation Log</div>
                    <div class="qa-desc">Record and track clinic consultations</div>
                </div>
            </a>
            <a href="{{ route('dashboard.medicine-inventory') }}" class="card qa-card accent-amber">
                <div class="qa-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="2" width="18" height="20" rx="2"/><path d="M9 2v4h6V2"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
                </div>
                <div>
                    <div class="qa-title">Medicine Inventory</div>
                    <div class="qa-desc">Monitor stock levels and dispensing</div>
                </div>
            </a>
            <a href="{{ route('consent-forms.nurse-index') }}" class="card qa-card accent-orange">
                <div class="qa-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>
                </div>
                <div>
                    <div class="qa-title">Consent Forms</div>
                    <div class="qa-desc">Read released parent consent forms</div>
                </div>
            </a>
        </div>
    </div>
</div>

@include('partials.nurse-page-transition')



<script>
// Recent Consultations: search + date/level filters over the rendered rows.
(() => {
    const search = document.getElementById('consultSearch');
    const dateFilter = document.getElementById('consultDateFilter');
    const levelFilter = document.getElementById('consultLevelFilter');
    const tbody = document.getElementById('consultTableBody');

    if (!search || !dateFilter || !levelFilter || !tbody) {
        return;
    }

    const rows = Array.from(tbody.querySelectorAll('.js-consult-row'));
    const noMatch = document.getElementById('consultNoMatch');
    const count = document.getElementById('consultCount');

    // Each row is stamped server-side with today/week/month flags, so the
    // date buckets never depend on the browser's clock or timezone.
    const apply = () => {
        const keyword = search.value.trim().toLowerCase();
        const period = dateFilter.value;
        const level = levelFilter.value;
        let visible = 0;

        rows.forEach((row) => {
            const haystack = row.dataset.search || '';
            const matchesKeyword = !keyword || haystack.includes(keyword);
            const matchesPeriod = period === 'all' || row.dataset[period] === '1';
            const matchesLevel = level === 'all' || (row.dataset.level || '') === level;
            const show = matchesKeyword && matchesPeriod && matchesLevel;

            row.hidden = !show;
            if (show) {
                visible += 1;
            }
        });

        if (noMatch) {
            noMatch.hidden = rows.length === 0 || visible > 0;
        }
        if (count) {
            count.textContent = visible + (visible === 1 ? ' entry' : ' entries');
        }
    };

    search.addEventListener('input', apply);
    dateFilter.addEventListener('change', apply);
    levelFilter.addEventListener('change', apply);
    apply();
})();
</script>
</body>
</html>
