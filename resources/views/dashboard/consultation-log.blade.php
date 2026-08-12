<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Consultation Log - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>document.documentElement.classList.add('js');</script>
    {{-- LUSOG order: theme, then this page's sheet, then the nurse rail. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
    @php $pageCssPath = resource_path('css/school-nurse-consultation-log.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
    <style>{!! file_get_contents(resource_path('css/nurse-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.clinic-rail', ['active' => 'consultations'])

<div class="main">
    @php
        $schoolName = session('active_school_name', 'No school assigned');
        $schoolYear = \App\Models\StudentHealthRecord::currentSchoolYear();
    @endphp

    <header class="topbar">
        <div class="topbar-bc"><span>{{ session('active_role') === 'clinic_staff' ? 'Clinic Staff' : 'School Nurse' }}</span><span class="bc-sep">&rsaquo;</span><span>Consultation Log</span></div>
        <div class="topbar-spacer"></div>
        <div class="topbar-chip"><span class="dot"></span>{{ $schoolName }} &middot; SY {{ $schoolYear }}</div>
        @include('partials.live-clock')
    </header>

    <div class="content">
        @if (session('success'))
            <div class="flash ok">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="flash err">{{ session('error') }}</div>
        @endif

        <div class="page-header">
            <div class="card-head" style="margin-bottom:0">
                <div>
                    <div class="page-eyebrow">Consultation Management</div>
                    <h1 class="page-title">School Clinic <span>Consultation Log</span></h1>
                    <p class="page-sub">Every clinic visit recorded for this school, with the month's most common conditions and this week's daily volume.</p>
                </div>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                    <a href="{{ route('dashboard.student-health-records') }}" class="btn btn-secondary">Health Records</a>
                    <a href="{{ route('consultations.create') }}" class="btn btn-primary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        New Consultation
                    </a>
                </div>
            </div>
        </div>

        <div class="kpi-grid cols-5">
            <div class="card kpi accent-brand">
                <div class="kpi-top">
                    <div class="kpi-label">Total Consultations</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($stats['total'] ?? 0) }}</div>
                <div class="kpi-hint">All time</div>
            </div>

            <div class="card kpi accent-info">
                <div class="kpi-top">
                    <div class="kpi-label">This Month</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($stats['month'] ?? 0) }}</div>
                <div class="kpi-hint">{{ now()->format('F Y') }}</div>
            </div>

            <div class="card kpi accent-info">
                <div class="kpi-top">
                    <div class="kpi-label">This Week</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($stats['week'] ?? 0) }}</div>
                <div class="kpi-hint">Last seven days</div>
            </div>

            <div class="card kpi accent-success">
                <div class="kpi-top">
                    <div class="kpi-label">Today</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9 12l2 2 4-4"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($stats['today'] ?? 0) }}</div>
                <div class="kpi-hint">{{ now()->format('M j') }}</div>
            </div>

            <div class="card kpi accent-orange">
                <div class="kpi-top">
                    <div class="kpi-label">Referred Cases</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($stats['referrals'] ?? 0) }}</div>
                <div class="kpi-hint">Sent onward for care</div>
            </div>
        </div>

        @php
            $conditionChartLabels = collect($topConditionStats ?? [])->map(function ($item) {
                $label = (string) ($item->condition_name ?? 'Unknown');

                return $label !== '' ? ucwords($label) : 'Unknown';
            })->values();
            $conditionChartValues = collect($topConditionStats ?? [])->map(fn ($item) => (int) ($item->total ?? 0))->values();
            $dailyTrendLabels = collect($dailyTrend ?? [])->pluck('label')->values();
            $dailyTrendValues = collect($dailyTrend ?? [])->pluck('count')->map(fn ($v) => (int) $v)->values();
        @endphp

        <div class="chart-grid" style="margin-top:20px">
            <div class="card chart-card">
                <div class="chart-head">Most Common Conditions</div>
                <div class="chart-sub">This month, by number of consultations</div>
                @if ($conditionChartLabels->isEmpty())
                    <div class="chart-empty">No consultations recorded this month.</div>
                @else
                    <div class="chart-body"><canvas id="conditionsChart"></canvas></div>
                @endif
            </div>
            <div class="card chart-card">
                <div class="chart-head">Daily Consultation Trend</div>
                <div class="chart-sub">This week, consultations per day</div>
                @if ($dailyTrendLabels->isEmpty())
                    <div class="chart-empty">No consultations recorded this week.</div>
                @else
                    <div class="chart-body"><canvas id="dailyTrendChart"></canvas></div>
                @endif
            </div>
        </div>

        <div class="toolbar">
            <div style="flex:1;min-width:240px">
                <label class="field-label" for="searchInput">Search</label>
                <div class="lg-search">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" id="searchInput" placeholder="Search by student name, condition, or grade..." autocomplete="off">
                </div>
            </div>
            <div class="spacer"></div>
            <div class="toolbar-count" id="recordCount">Showing {{ $consultations->count() }} of {{ $consultations->total() }} records</div>
        </div>

        <div class="table-card">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Date &amp; Time</th>
                            <th>Student Name</th>
                            <th>Grade &amp; Section</th>
                            <th>Condition</th>
                            <th>Treatment Given</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="consultationRows">
                        @forelse($consultations as $consultation)
                            @php
                                $rowName = trim((string) $consultation->student_name);
                                $rowGrade = trim((string) $consultation->grade_section);
                                $rowCondition = trim((string) $consultation->condition);
                                $rowTreatment = trim((string) $consultation->treatment_given);
                            @endphp
                            <tr class="js-log-row" data-search="{{ strtolower($rowName.' '.$rowGrade.' '.$rowCondition) }}">
                                <td class="tnum">{{ optional($consultation->consulted_at)->format('Y-m-d H:i') ?? '—' }}</td>
                                <td><strong>{{ $rowName !== '' ? $rowName : '—' }}</strong></td>
                                <td>{{ $rowGrade !== '' ? $rowGrade : '—' }}</td>
                                <td>{{ $rowCondition !== '' ? $rowCondition : '—' }}</td>
                                <td>{{ $rowTreatment !== '' ? $rowTreatment : 'N/A' }}</td>
                                <td>
                                    @if($consultation->status === 'referred')
                                        <span class="badge badge-monitor">Referred</span>
                                    @else
                                        <span class="badge badge-normal">Treated</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="table-empty">No consultations yet. Use New Consultation to add the first entry.</td>
                            </tr>
                        @endforelse
                        <tr id="logNoMatch" hidden>
                            <td colspan="6" class="table-empty">No consultations match your search.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="table-foot">
                <span>Showing {{ $consultations->count() }} of {{ $consultations->total() }} records</span>
                <span>Page {{ $consultations->currentPage() }} of {{ max($consultations->lastPage(), 1) }}</span>
            </div>
        </div>
    </div>
</div>

@include('partials.nurse-page-transition')

<script>
// Search across the rendered page of records. Server-side paging still
// applies, so this narrows the current page rather than the whole log.
(() => {
    const search = document.getElementById('searchInput');
    const tbody = document.getElementById('consultationRows');
    if (!search || !tbody) return;

    const rows = Array.from(tbody.querySelectorAll('.js-log-row'));
    const noMatch = document.getElementById('logNoMatch');

    search.addEventListener('input', () => {
        const keyword = search.value.trim().toLowerCase();
        let visible = 0;

        rows.forEach((row) => {
            const show = !keyword || (row.dataset.search || '').includes(keyword);
            row.hidden = !show;
            if (show) visible += 1;
        });

        if (noMatch) noMatch.hidden = rows.length === 0 || visible > 0;
    });
})();

// Charts. Both are single-series, so neither carries a legend — the panel
// title names the measure. Colours are the theme's validated series pair
// (--series-healthy / --series-risk); do not substitute by eye.
(() => {
    if (typeof Chart === 'undefined') return;

    const ink = '#6B7C72';
    const grid = 'rgba(220, 232, 224, .9)';
    const seriesHealthy = '#126B3A';

    const baseScales = {
        y: {
            beginAtZero: true,
            ticks: { precision: 0, color: ink, font: { size: 11 } },
            grid: { color: grid, drawBorder: false },
        },
        x: {
            ticks: { color: ink, font: { size: 11 } },
            grid: { display: false },
        },
    };

    const conditionLabels = @json($conditionChartLabels);
    const conditionValues = @json($conditionChartValues);
    const conditionCanvas = document.getElementById('conditionsChart');

    if (conditionCanvas && conditionLabels.length > 0) {
        new Chart(conditionCanvas, {
            type: 'bar',
            data: {
                labels: conditionLabels,
                datasets: [{
                    label: 'Consultations',
                    data: conditionValues,
                    backgroundColor: seriesHealthy,
                    // 4px rounded data-end, square against the baseline.
                    borderRadius: { topLeft: 4, topRight: 4, bottomLeft: 0, bottomRight: 0 },
                    maxBarThickness: 38,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: baseScales,
            },
        });
    }

    const dailyLabels = @json($dailyTrendLabels);
    const dailyValues = @json($dailyTrendValues);
    const dailyCanvas = document.getElementById('dailyTrendChart');

    if (dailyCanvas && dailyLabels.length > 0) {
        new Chart(dailyCanvas, {
            type: 'line',
            data: {
                labels: dailyLabels,
                datasets: [{
                    label: 'Consultations',
                    data: dailyValues,
                    borderColor: seriesHealthy,
                    backgroundColor: 'rgba(18, 107, 58, .10)',
                    borderWidth: 2,
                    tension: .35,
                    fill: true,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: seriesHealthy,
                    // 2px surface ring so a point stays legible on the fill.
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                interaction: { mode: 'index', intersect: false },
                scales: baseScales,
            },
        });
    }
})();
</script>
</body>
</html>
