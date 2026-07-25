<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Reports - Feeding Coordinator - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    @php $pageCssPath = resource_path('css/feeding-feed-healthrec.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
    <style>
        .flash{padding:10px 14px;border-radius:10px;font-size:.82rem;margin-bottom:12px}
        .flash.ok{background:#f0fdf4;border:1px solid #dcfce7;color:#166534}
        .flash.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
        .toolbar{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin:6px 0 14px}
        .toolbar form{display:flex;align-items:center;gap:8px}
        .toolbar select{padding:7px 10px;border:1px solid #cbd5e1;border-radius:8px;font-family:inherit;font-size:.82rem}
        .btn-export{padding:8px 14px;border-radius:8px;background:#0d9488;color:#fff;font-size:.8rem;font-weight:600;text-decoration:none}
        .locked{padding:26px 18px;text-align:center;background:#fffbeb;border:1.5px dashed #fcd34d;border-radius:12px}
        .locked h3{margin:8px 0 4px;color:#92400e}
        .locked a{display:inline-block;margin-top:10px;padding:8px 16px;border-radius:8px;background:#0d9488;color:#fff;text-decoration:none;font-weight:600;font-size:.82rem}
        .notice{padding:10px 12px;border-radius:10px;font-size:.78rem;margin-bottom:8px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}
        .notice.warn{background:#fff7ed;border-color:#fed7aa;color:#9a3412}
        .status-card{display:flex;flex-wrap:wrap;gap:16px;padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:14px;font-size:.78rem}
        .status-card b{display:block;font-size:1.05rem;color:#0f172a}
        .pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:.68rem;font-weight:700}
        .pill.risk{background:#fee2e2;color:#991b1b}
        .delta-up{color:#166534;font-weight:600}.delta-down{color:#991b1b;font-weight:600}.delta-none{color:#64748b}
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="sb-logo"><img src="{{ asset('images/lusog-logo.png') }}" alt="SIGLA Logo"></div>
    <nav class="sb-nav">
        <a href="{{ route('dashboard.feedingcor-dashboard') }}" class="sb-link">Dashboard</a>
        <a href="{{ route('dashboard.feedingcor-program') }}" class="sb-link">Feeding Program</a>
        <a href="{{ route('dashboard.feedingcor-health-records') }}" class="sb-link">Student Health Records</a>
        <a href="{{ route('dashboard.feedingcor-baseline') }}" class="sb-link">Baseline Entry</a>
        <a href="{{ route('dashboard.feedingcor-endline') }}" class="sb-link">Endline Entry</a>
        <a href="{{ route('dashboard.feedingcor-reports') }}" class="sb-link active">Reports</a>
        <a href="{{ route('dashboard.feedingcor-sbfp-forms') }}" class="sb-link">SBFP Forms</a>
    </nav>
    <div class="sb-user">
        <div class="sb-avatar">{{ strtoupper(substr(session('active_name', 'FC'), 0, 2)) }}</div>
        <div class="sb-user-meta">
            <div class="sb-user-name">{{ session('active_name', 'Feeding Coordinator') }}</div>
            <div class="sb-user-role" style="font-size:.68rem;color:var(--g300);line-height:1.2;">{{ session('active_school_name', 'No school assigned') }}</div>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="sb-logout" title="Sign out" aria-label="Sign out">&#x23Fb;</button>
        </form>
    </div>
</aside>

<div class="main">
    <div class="content">
        <div class="page-eyebrow">Feeding Program</div>
        <h1 class="page-title">SBFP <span>Reports</span></h1>
        <p class="page-sub">Read-only. Every figure is computed live from the uploaded attendance and the baseline/endline records — nothing here is hand-encoded.</p>

        @if (session('error'))<div class="flash err">{{ session('error') }}</div>@endif
        @if (session('success'))<div class="flash ok">{{ session('success') }}</div>@endif

        <div class="toolbar">
            <form method="GET" action="{{ route('dashboard.feedingcor-reports') }}">
                <label for="yearSelect" style="font-size:.8rem;font-weight:600;">School Year:</label>
                <select id="yearSelect" name="year" onchange="this.form.submit()">
                    @foreach ($schoolYears as $year)
                        <option value="{{ $year }}" {{ $selectedYear === $year ? 'selected' : '' }}>{{ $year }}</option>
                    @endforeach
                </select>
            </form>
            @if ($attendanceUploaded)
                <a class="btn-export" href="{{ route('dashboard.feedingcor-reports.export', ['year' => $selectedYear]) }}">Export CSV</a>
            @endif
        </div>

        @if (! $attendanceUploaded)
            <div class="locked">
                <svg viewBox="0 0 24 24" fill="none" stroke="#92400e" stroke-width="1.6" style="width:34px;height:34px"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <h3>Attendance not uploaded for SY {{ $selectedYear }}</h3>
                <p style="font-size:.82rem;color:#92400e;max-width:460px;margin:0 auto;">Reports are generated from feeding-session attendance. Upload the attendance sheet for this school year first, then the reports below will compute automatically.</p>
                <a href="{{ route('dashboard.feedingcor-program') }}">Go to Feeding Program &rsaquo;</a>
            </div>
        @else
            @if ($lastImport)
                <div class="status-card">
                    <div><b>{{ $report['totalStudents'] }}</b>Students on file</div>
                    <div><b>{{ $report['qualifiedCount'] }}</b>Qualified beneficiaries</div>
                    <div><b>{{ $report['atRisk']->count() }}</b>At-risk (&lt; {{ $atRiskThreshold }}%)</div>
                    <div><b>{{ $report['totalSessions'] }}</b>Feeding sessions</div>
                    <div style="margin-left:auto;color:#64748b;">Attendance uploaded {{ optional($lastImport->created_at)->format('M d, Y g:ia') }} by {{ $lastImport->uploaded_by_name }} · {{ $lastImport->matched_count }} matched@if($lastImport->unmatched_count), {{ $lastImport->unmatched_count }} unmatched@endif</div>
                </div>
            @endif

            {{-- Completeness — show what's missing instead of zeros --}}
            @if ($report['missingBaseline']->isNotEmpty())
                <div class="notice warn">Baseline missing for {{ $report['missingBaseline']->count() }} qualified learner(s): {{ $report['missingBaseline']->pluck('name')->take(6)->implode('; ') }}{{ $report['missingBaseline']->count() > 6 ? ' …' : '' }}. Enter their baseline for a complete comparison.</div>
            @endif
            @if ($report['missingEndline']->isNotEmpty())
                <div class="notice">Endline pending for {{ $report['missingEndline']->count() }} learner(s) with a baseline — the baseline-vs-endline report will fill in as endline measurements are recorded.</div>
            @endif

            {{-- Masterlist of qualified + at-risk --}}
            <div class="section-title">Masterlist of Qualified Beneficiaries</div>
            <div class="table-card">
                <table>
                    <thead><tr><th>Student</th><th>Grade / Section</th><th>Nutritional Status</th><th>Attendance</th><th>Risk</th></tr></thead>
                    <tbody>
                        @forelse ($report['qualified'] as $row)
                            <tr>
                                <td>{{ $row['name'] }}</td>
                                <td>{{ $row['grade'] }}{{ $row['section'] ? ' / '.$row['section'] : '' }}</td>
                                <td>{{ $row['status'] }}</td>
                                <td>{{ $row['attendance_pct'] === null ? '—' : $row['attendance_pct'].'%' }} ({{ $row['present'] }}/{{ $report['totalSessions'] }})</td>
                                <td>@if ($row['is_at_risk'])<span class="pill risk">At-Risk</span>@else — @endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="5">No qualified beneficiaries for this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Per-grade nutrition summary --}}
            <div class="section-title">Nutrition Summary by Grade</div>
            <div class="table-card">
                <table>
                    <thead><tr><th>Grade</th><th>Total</th><th>Sev. Wasted</th><th>Wasted</th><th>Underweight</th><th>Normal</th><th>Overweight</th><th>At-Risk</th></tr></thead>
                    <tbody>
                        @forelse ($report['gradeSummary'] as $g)
                            <tr><td>{{ $g['grade'] }}</td><td>{{ $g['total'] }}</td><td>{{ $g['severely_wasted'] }}</td><td>{{ $g['wasted'] }}</td><td>{{ $g['underweight'] }}</td><td>{{ $g['normal'] }}</td><td>{{ $g['overweight'] }}</td><td>{{ $g['at_risk'] }}</td></tr>
                        @empty
                            <tr><td colspan="8">No data.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Baseline vs Endline comparison --}}
            <div class="section-title">Baseline vs Endline</div>
            <div class="table-card">
                <table>
                    <thead><tr><th>Student</th><th>Grade</th><th>Baseline BMI</th><th>Baseline Status</th><th>Endline BMI</th><th>Endline Status</th><th>Change</th></tr></thead>
                    <tbody>
                        @forelse ($report['comparison'] as $row)
                            <tr>
                                <td>{{ $row['name'] }}</td>
                                <td>{{ $row['grade'] }}</td>
                                <td>{{ $row['baseline_bmi'] ?? '—' }}</td>
                                <td>{{ $row['baseline_status'] ?: '—' }}</td>
                                <td>{{ $row['has_endline'] ? $row['endline_bmi'] : '—' }}</td>
                                <td>{{ $row['has_endline'] ? ($row['endline_status'] ?: '—') : 'Pending' }}</td>
                                <td>
                                    @if ($row['delta'] === null)
                                        <span class="delta-none">Pending endline</span>
                                    @elseif ($row['delta'] > 0)
                                        <span class="delta-up">+{{ number_format($row['delta'], 2) }}</span>
                                    @elseif ($row['delta'] < 0)
                                        <span class="delta-down">{{ number_format($row['delta'], 2) }}</span>
                                    @else
                                        <span class="delta-none">No change</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7">No baseline records yet for this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@includeIf('partials.sidebar-hover-pin')
</body>
</html>
