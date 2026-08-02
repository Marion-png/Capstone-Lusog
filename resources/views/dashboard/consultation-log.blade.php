<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Consultation Log - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
        @php $pageCssPath = resource_path('css/school-nurse-consultation-log.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
</head>
<body>
@include('partials.nurse-sidebar', ['active' => 'consultations'])

<div class="main">
    <header class="topbar">
        <div class="topbar-breadcrumb">
            <a href="{{ route('dashboard.school-nurse') }}" class="bc-home">Dashboard</a>
            <span class="bc-sep">&rsaquo;</span>
            <span class="bc-current">Consultation Log</span>
        </div>
        <div class="topbar-chip"><div class="dot"></div>DCNHS - SY 2025-2026</div>
        @include('partials.live-clock')
    </header>

    <div class="content">
        @if (session('success'))
            <div class="flash-success">{{ session('success') }}</div>
        @endif
        <div class="page-header">
            <div>
                <div class="page-eyebrow">Consultation Management</div>
                <h1 class="page-title">School Clinic <span>Consultation Log</span></h1>
                <p class="page-sub">Manage and track all student consultations</p>
            </div>
            <div class="page-header-actions">
                <a href="{{ route('dashboard.school-nurse') }}" class="btn btn-ghost">Dashboard</a>
                <a href="{{ route('dashboard.student-health-records') }}" class="btn btn-ghost">Health Records</a>
                <a href="{{ route('consultations.create') }}" class="btn btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    New Consultation
                </a>
            </div>
        </div>

        <div class="mini-stats">
            <div class="mini-stat">
                <div class="mini-stat-icon" style="background:var(--g100);color:var(--g700)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                </div>
                <div>
                    <div class="mini-stat-val">{{ number_format($stats['total'] ?? 0) }}</div>
                    <div class="mini-stat-label">Total Consultations</div>
                </div>
            </div>
            <div class="mini-stat">
                <div class="mini-stat-icon" style="background:#dbeafe;color:#1d4ed8">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
                <div>
                    <div class="mini-stat-val">{{ number_format($stats['month'] ?? 0) }}</div>
                    <div class="mini-stat-label">This Month</div>
                </div>
            </div>
            <div class="mini-stat">
                <div class="mini-stat-icon" style="background:#fef3c7;color:#92400e">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <div>
                    <div class="mini-stat-val">{{ number_format($stats['week'] ?? 0) }}</div>
                    <div class="mini-stat-label">This Week</div>
                </div>
            </div>
            <div class="mini-stat">
                <div class="mini-stat-icon" style="background:#dcfce7;color:#166534">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9 12l2 2 4-4"/></svg>
                </div>
                <div>
                    <div class="mini-stat-val">{{ number_format($stats['today'] ?? 0) }}</div>
                    <div class="mini-stat-label">Today</div>
                </div>
            </div>
            <div class="mini-stat">
                <div class="mini-stat-icon" style="background:#fee2e2;color:var(--red)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/></svg>
                </div>
                <div>
                    <div class="mini-stat-val">{{ number_format($stats['referrals'] ?? 0) }}</div>
                    <div class="mini-stat-label">Referred Cases</div>
                </div>
            </div>
        </div>

        <div class="filter-bar">
            <div class="search-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" class="search-input" placeholder="Search by student name, condition, or grade..." id="searchInput">
            </div>
            <select class="filter-select" id="dateFilter"><option>All Dates</option></select>
            <select class="filter-select" id="conditionFilter"><option>All Conditions</option></select>
            <select class="filter-select" id="gradeFilter"><option>All Grades</option></select>
        </div>

        <div class="chart-grid">
            <div class="chart-card">
                <div class="chart-head">Most Common Conditions (This Month)</div>
                <div class="chart-body" style="height:250px;">
                    <canvas id="conditionsChart"></canvas>
                </div>
            </div>
            <div class="chart-card">
                <div class="chart-head">Daily Consultation Trend (This Week)</div>
                <div class="chart-body" style="height:250px;">
                    <canvas id="dailyTrendChart"></canvas>
                </div>
            </div>
        </div>

        <div class="table-card">
            <div class="table-head-bar">
                <span class="table-head-label">Consultation Records</span>
                <span class="table-count">Showing {{ $consultations->count() }} of {{ $consultations->total() }} records</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Date &amp; Time</th>
                        <th>Student Name</th>
                        <th>Grade &amp; Section</th>
                        <th>Condition</th>
                        <th>Treatment Given</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="consultationRows">
                    @forelse($consultations as $consultation)
                        <tr>
                            <td>{{ optional($consultation->consulted_at)->format('Y-m-d H:i') }}</td>
                            <td>{{ $consultation->student_name }}</td>
                            <td>{{ $consultation->grade_section }}</td>
                            <td>{{ $consultation->condition }}</td>
                            <td>{{ $consultation->treatment_given ?: 'N/A' }}</td>
                            <td>
                                @if($consultation->status === 'referred')
                                    <span class="badge-pill bp-amber"><span class="dot" style="background:var(--amber)"></span>Referred</span>
                                @else
                                    <span class="badge-pill bp-green"><span class="dot" style="background:var(--g500)"></span>Treated</span>
                                @endif
                            </td>
                            <td>View</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">No consultations yet. Click New Consultation to add the first entry.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="foot">
                <span>Showing {{ $consultations->count() }} of {{ $consultations->total() }} records</span>
                <span>Page {{ $consultations->currentPage() }} of {{ max($consultations->lastPage(), 1) }}</span>
            </div>
        </div>
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

<script>
(() => {
    const conditionLabels = @json($conditionChartLabels);
    const conditionValues = @json($conditionChartValues);
    const dailyLabels = @json($dailyTrendLabels);
    const dailyValues = @json($dailyTrendValues);

    const conditionCanvas = document.getElementById('conditionsChart');
    if (conditionCanvas && Array.isArray(conditionLabels) && conditionLabels.length > 0) {
        new Chart(conditionCanvas, {
            type: 'bar',
            data: {
                labels: conditionLabels,
                datasets: [{
                    data: conditionValues,
                    backgroundColor: '#19574B',
                    borderRadius: 6,
                    maxBarThickness: 38,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 },
                    },
                },
            },
        });
    }

    const dailyCanvas = document.getElementById('dailyTrendChart');
    if (dailyCanvas && Array.isArray(dailyLabels) && dailyLabels.length > 0) {
        new Chart(dailyCanvas, {
            type: 'line',
            data: {
                labels: dailyLabels,
                datasets: [{
                    data: dailyValues,
                    borderColor: '#f59e0b',
                    backgroundColor: 'rgba(245, 158, 11, 0.14)',
                    tension: 0.35,
                    fill: true,
                    pointRadius: 3,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 },
                    },
                },
            },
        });
    }
})();
</script>
</body>
</html>
