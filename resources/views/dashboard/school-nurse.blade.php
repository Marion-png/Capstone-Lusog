<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Dashboard - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
        @php $pageCssPath = resource_path('css/school-nurse.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
</head>
<body>
@include('partials.nurse-sidebar', ['active' => 'dashboard'])

<div class="main">
    @php
        $nurseName = session('active_name', 'School Nurse');
        $schoolName = session('active_school_name', 'No school assigned');
        $schoolYear = \App\Models\StudentHealthRecord::currentSchoolYear();
        $greetHour = (int) now()->format('G');
        $greeting = $greetHour < 12 ? 'Good morning' : ($greetHour < 18 ? 'Good afternoon' : 'Good evening');
    @endphp

    <header class="topbar">
        <div class="topbar-title-block">
            <div class="topbar-eyebrow">SIGLA &nbsp;/&nbsp; Clinic</div>
            <div class="topbar-heading">Dashboard</div>
        </div>
        <div class="topbar-chip"><div class="dot"></div>{{ $schoolName }} &middot; SY {{ $schoolYear }}</div>
        @include('partials.live-clock')
    </header>

    <div class="content">
        <section class="clinic-header">
            <div class="ch-top">
                <div>
                    <div class="ch-greeting">{{ $greeting }}, School Nurse</div>
                    <h1 class="ch-name">{{ $nurseName }}</h1>
                    <p class="ch-sub">{{ $schoolName }} &middot; School Year {{ $schoolYear }}</p>
                </div>
                <div class="ch-status">
                    <div><span class="ch-status-dot"></span><span class="ch-status-text">Clinic Open</span></div>
                    <span class="ch-status-sub">Operational</span>
                </div>
            </div>
            <div class="ch-metrics">
                <div class="ch-metric">
                    <div class="ch-metric-val">{{ number_format($totalRecords) }}</div>
                    <div class="ch-metric-label">Total Records</div>
                </div>
                <div class="ch-metric">
                    <div class="ch-metric-val">{{ number_format($consultationsToday) }}</div>
                    <div class="ch-metric-label">Consultations Today</div>
                </div>
                <div class="ch-metric is-alert">
                    <div class="ch-metric-val">{{ number_format($atRiskCount) }}</div>
                    <div class="ch-metric-label">At-Risk Learners</div>
                </div>
                <div class="ch-metric is-warn">
                    <div class="ch-metric-val">{{ number_format($lowStockCount) }}</div>
                    <div class="ch-metric-label">Low Stock Medicines</div>
                </div>
            </div>
        </section>

        <div class="board-row">
            @include('partials.announcements')
            @include('partials.upcoming-events')
        </div>

        <div class="filter-bar">
            <div class="search-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" class="search-input" id="consultSearch" placeholder="Search learner, complaint, section..." autocomplete="off">
            </div>
            <select class="filter-select" id="consultDateFilter" aria-label="Filter consultations by date">
                <option value="all">All Dates</option>
                <option value="today">Today</option>
                <option value="week">This Week</option>
                <option value="month">This Month</option>
            </select>
            <select class="filter-select" id="consultLevelFilter" aria-label="Filter consultations by level">
                <option value="all">All Levels</option>
                <option value="junior">Junior High</option>
                <option value="senior">Senior High</option>
                <option value="personnel">Personnel</option>
            </select>
        </div>

        <div class="table-card">
            <div class="table-head-bar">
                <span class="table-head-label">Recent Consultations</span>
                <span class="table-count" id="consultCount">{{ $recentConsultations->count() }} {{ Str::plural('entry', $recentConsultations->count()) }}</span>
            </div>

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
                                <div><div class="td-name">{{ $studentName !== '' ? $studentName : '—' }}</div></div>
                            </div>
                        </td>
                        <td>{{ $gradeSection !== '' ? $gradeSection : '—' }}</td>
                        <td>{{ $consultedAt?->format('M j, Y') ?? '—' }}</td>
                        <td>{{ $condition !== '' ? $condition : '—' }}</td>
                        <td>{{ $treatment !== '' ? $treatment : '—' }}</td>
                        <td>
                            @if($c->status === 'referred')
                                <span class="badge-pill bp-amber"><span class="dot" style="background:var(--amber)"></span>Referred</span>
                            @else
                                <span class="badge-pill bp-green"><span class="dot" style="background:var(--g500)"></span>Treated</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" style="text-align:center;padding:2rem;color:var(--text-3);font-size:.9rem">
                            No consultations recorded yet.
                        </td>
                    </tr>
                    @endforelse
                    <tr id="consultNoMatch" hidden>
                        <td colspan="6" style="text-align:center;padding:2rem;color:var(--text-3);font-size:.9rem">
                            No consultations match your search or filters.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="grid-summary">
            <div class="panel">
                <div class="panel-title">Top Consultation Cases <span style="font-size:.75rem;font-weight:400;color:var(--text-3)">(this month)</span></div>
                @php $maxConditionCount = $topConditions->max('total') ?: 1; @endphp
                @forelse($topConditions as $cond)
                <div class="trend-row">
                    <div class="trend-label">{{ Str::ucfirst($cond['name']) }}</div>
                    <div class="trend-track"><div class="trend-fill" style="width:{{ round($cond['total'] / $maxConditionCount * 100) }}%;background:var(--blue)"></div></div>
                    <div class="trend-value">{{ $cond['total'] }}</div>
                </div>
                @empty
                <p style="color:var(--text-3);font-size:.85rem;text-align:center;padding:1.5rem 0">No consultations this month.</p>
                @endforelse
            </div>

            <div class="panel">
                <div class="panel-title">Medicine Stock Monitor <span style="font-size:.75rem;font-weight:400;color:var(--text-3)">(low stock)</span></div>
                @forelse($lowStockMedicines as $med)
                @php
                    $pct   = $med->minimum_threshold > 0 ? min(100, round($med->stock_quantity / $med->minimum_threshold * 100)) : 0;
                    $color = $pct <= 25 ? 'var(--red)' : 'var(--amber)';
                @endphp
                <div class="inventory-item">
                    <div class="inventory-meta">
                        <span>{{ $med->name }}</span>
                        <span>{{ $med->stock_quantity }} / {{ $med->minimum_threshold }} {{ $med->unit }}</span>
                    </div>
                    <div class="trend-track"><div class="trend-fill" style="width:{{ $pct }}%;background:{{ $color }}"></div></div>
                </div>
                @empty
                <p style="color:var(--text-3);font-size:.85rem;text-align:center;padding:1.5rem 0">All medicines are adequately stocked.</p>
                @endforelse
            </div>
        </div>

        <div class="quick-access">
            <a href="{{ route('dashboard.student-health-records') }}" class="qa-card">
                <div class="qa-icon" style="background:var(--g100);color:var(--g700)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <div>
                    <div class="qa-title">Health Records</div>
                    <div class="qa-desc">Review and examine learner health cards</div>
                </div>
            </a>
            <a href="{{ route('dashboard.consultation-log') }}" class="qa-card">
                <div class="qa-icon" style="background:#dbeafe;color:#1d4ed8">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4"/><path d="M21 12c0 4.97-4.03 9-9 9S3 16.97 3 12 7.03 3 12 3s9 4.03 9 9z"/></svg>
                </div>
                <div>
                    <div class="qa-title">Consultation Log</div>
                    <div class="qa-desc">Record and track clinic consultations</div>
                </div>
            </a>
            <a href="{{ route('dashboard.medicine-inventory') }}" class="qa-card">
                <div class="qa-icon" style="background:#fef3c7;color:#92400e">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="2" width="18" height="20" rx="2"/><path d="M9 2v4h6V2"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
                </div>
                <div>
                    <div class="qa-title">Medicine Inventory</div>
                    <div class="qa-desc">Monitor stock levels and dispensing</div>
                </div>
            </a>
            <a href="{{ route('consent-forms.nurse-index') }}" class="qa-card">
                <div class="qa-icon" style="background:#f3e8ff;color:#7e22ce">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>
                </div>
                <div>
                    <div class="qa-title">Consent Forms</div>
                    <div class="qa-desc">Read released parent consent forms</div>
                </div>
            </a>
        </div>
    </div>
</div>

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
