<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feeding Head - Student Health Records</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        :root{--g900:#14532d;--g700:#15803d;--g300:#86efac;--cream:#f7f8f5;--card:#fff;--border:#e4ece7;--text-1:#0d1f14;--text-2:#3d5c47;--text-3:#7a9e87;--red:#e34848;--sidebar-w:248px;--sidebar-collapsed-w:76px;--topbar-h:64px;--radius-sm:10px;--shadow-card:0 1px 4px rgba(5,46,22,.06),0 4px 16px rgba(5,46,22,.06)}
        html,body{height:100%;font-family:'DM Sans',sans-serif;background:var(--cream);color:var(--text-1);overflow:hidden}

        .sidebar{position:fixed;left:0;top:0;bottom:0;width:var(--sidebar-collapsed-w);background:var(--g900);display:flex;flex-direction:column;z-index:100;overflow:hidden;transition:width .24s ease}
        .sidebar:hover{width:var(--sidebar-w)}
        .sb-logo{padding:20px 20px 18px;position:relative;z-index:2;border-bottom:1px solid rgba(255,255,255,.08);display:flex;justify-content:center;transition:padding .24s ease}
        .sb-logo img{width:176px;max-width:100%;height:auto;display:block;transition:width .24s ease}
        .sidebar:not(:hover) .sb-logo{padding:14px 10px}
        .sidebar:not(:hover) .sb-logo img{width:48px}
        .sb-nav{flex:1;overflow-y:auto;padding:16px 12px}
        .sb-link{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:var(--radius-sm);text-decoration:none;color:rgba(255,255,255,.62);font-size:.83rem;font-weight:500;margin-bottom:2px;white-space:nowrap;overflow:hidden}
        .sb-link.active{background:rgba(34,197,94,.18);color:var(--g300)}
        .sidebar:not(:hover) .sb-link{justify-content:center;font-size:0;padding:10px;gap:0}
        .sb-user{padding:14px 16px;border-top:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:11px}
        .sb-avatar{width:34px;height:34px;border-radius:50%;background:#16a34a;display:grid;place-items:center;font-size:.8rem;font-weight:700;color:#fff}
        .sb-user-name{font-size:.8rem;font-weight:600;color:#fff}
        .sidebar:not(:hover) .sb-user-name{display:none}

        .main{margin-left:var(--sidebar-collapsed-w);height:100vh;display:flex;flex-direction:column;overflow:hidden;transition:margin-left .24s ease}
        .sidebar:hover ~ .main{margin-left:var(--sidebar-w)}
        .topbar{height:var(--topbar-h);border-bottom:1px solid var(--border);background:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 22px}
        .topbar-bc{font-size:.76rem;color:var(--text-3);display:flex;gap:6px;align-items:center}
        .topbar-chip{font-size:.72rem;border:1px solid #bbf7d0;color:#15803d;background:#f0fdf4;border-radius:999px;padding:5px 11px}

        .content{overflow:auto;padding:18px}
        .page-eyebrow{font-size:.68rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#15803d;margin-bottom:6px}
        .page-title{font-family:'DM Serif Display',serif;font-size:1.75rem;line-height:1.15}
        .page-title span{font-style:italic;color:#15803d}
        .page-sub{margin-top:5px;font-size:.8rem;color:var(--text-3)}

        .cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}
        .mini-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:12px;box-shadow:var(--shadow-card)}
        .mini-card .val{font-family:'DM Serif Display',serif;font-size:1.4rem}
        .mini-card .lbl{font-size:.72rem;color:var(--text-3)}

        .table-card{margin-top:14px;background:#fff;border:1px solid var(--border);border-radius:10px;overflow:auto;box-shadow:var(--shadow-card)}
        table{width:100%;border-collapse:collapse}
        th,td{padding:10px;border-bottom:1px solid #edf2f1;font-size:.72rem;text-align:left}
        th{color:#7a8f8a;background:#f9fbfa;font-weight:700}
        tr:last-child td{border-bottom:none}

        .status{font-size:.62rem;font-weight:700;border-radius:999px;padding:3px 8px;display:inline-block}
        .s-severe{background:#fee2e2;color:#c81e1e}
        .s-wasted{background:#fef3c7;color:#92400e}
        .s-normal{background:#dcfce7;color:#15803d}
        .s-over{background:#dbeafe;color:#1e40af}
        .delta-up{color:#15803d;font-weight:700}
        .delta-down{color:#c81e1e;font-weight:700}
        .delta-none{color:#64748b;font-weight:700}

        .section-title{margin-top:18px;font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-2)}

        @media (max-width:1024px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media (max-width:780px){.sidebar{display:none}.main{margin-left:0}.cards{grid-template-columns:1fr}}
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="sb-logo"><img src="{{ asset('images/lusog-logo.png') }}" alt="LUSOG Logo"></div>
    <nav class="sb-nav">
        <a href="{{ route('dashboard.feedingcor-dashboard') }}" class="sb-link">Dashboard</a>
        <a href="{{ route('dashboard.feedingcor-health-records') }}" class="sb-link active">Student Health Records</a>
        <a href="{{ route('dashboard.feedingcor-program') }}" class="sb-link">Feeding Program</a>
        <a href="{{ route('dashboard.feedingcor-sbfp-forms') }}" class="sb-link">SBFP Forms</a>
    </nav>
    <div class="sb-user">
        <div class="sb-avatar">{{ substr(auth()->user()->name ?? 'FC',0,2) }}</div>
        <div class="sb-user-name">{{ auth()->user()->name ?? 'Feeding Coordinator' }}</div>
    </div>
</aside>

@php
    $total = ($records ?? collect())->count();
    $endlineDone = ($records ?? collect())->filter(fn ($r) => !is_null($r->endline_bmi_value))->count();
    $wastedCount = ($statusCounts['wasted'] ?? 0) + ($statusCounts['severely_wasted'] ?? 0);
@endphp

<div class="main">
    <header class="topbar">
        <div class="topbar-bc"><span>Dashboard</span><span>&gt;</span><span>Student Health Records</span></div>
        <div class="topbar-chip">Auto-computed BMI and status</div>
    </header>

    <div class="content">
        <div class="page-eyebrow">Feeding Program</div>
        <h1 class="page-title">Baseline and Endline <span>Health Records</span></h1>
        <p class="page-sub">Tracks BMI progression and nutritional status change per beneficiary.</p>

        <div class="cards">
            <div class="mini-card"><div class="val">{{ $total }}</div><div class="lbl">Beneficiaries</div></div>
            <div class="mini-card"><div class="val">{{ $endlineDone }}</div><div class="lbl">With Endline Data</div></div>
            <div class="mini-card"><div class="val">{{ $wastedCount }}</div><div class="lbl">Wasted or Severe</div></div>
            <div class="mini-card"><div class="val">{{ $statusCounts['normal'] ?? 0 }}</div><div class="lbl">Normal Status</div></div>
        </div>

        <div class="section-title">Per Beneficiary Comparison</div>
        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Section</th>
                        <th>Baseline BMI</th>
                        <th>Baseline Status</th>
                        <th>Endline BMI</th>
                        <th>Endline Status</th>
                        <th>Status Change</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($records ?? collect()) as $record)
                        @php
                            $baselineBmi = $record->baseline_bmi_value;
                            $endlineBmi = $record->endline_bmi_value;
                            $baselineStatus = $record->baseline_nutritional_status ?: $record->nutritional_status;
                            $endlineStatus = $record->endline_nutritional_status ?: $record->nutritional_status;

                            $deltaLabel = 'Pending endline';
                            $deltaClass = 'delta-none';
                            if (!is_null($baselineBmi) && !is_null($endlineBmi)) {
                                $delta = round((float) $endlineBmi - (float) $baselineBmi, 2);
                                if ($delta > 0) {
                                    $deltaLabel = '+' . number_format($delta, 2) . ' BMI';
                                    $deltaClass = 'delta-up';
                                } elseif ($delta < 0) {
                                    $deltaLabel = number_format($delta, 2) . ' BMI';
                                    $deltaClass = 'delta-down';
                                } else {
                                    $deltaLabel = 'No change';
                                }
                            }

                            $statusClass = function ($status) {
                                $normalized = strtolower((string) $status);
                                if (str_contains($normalized, 'severe')) return 's-severe';
                                if (str_contains($normalized, 'wast') || str_contains($normalized, 'underweight')) return 's-wasted';
                                if (str_contains($normalized, 'over')) return 's-over';
                                return 's-normal';
                            };
                        @endphp
                        <tr>
                            <td>{{ $record->student_name }}</td>
                            <td>{{ $record->section }}</td>
                            <td>{{ !is_null($baselineBmi) ? number_format((float) $baselineBmi, 2) : '-' }}</td>
                            <td><span class="status {{ $statusClass($baselineStatus) }}">{{ $baselineStatus ?: '-' }}</span></td>
                            <td>{{ !is_null($endlineBmi) ? number_format((float) $endlineBmi, 2) : '-' }}</td>
                            <td><span class="status {{ $statusClass($endlineStatus) }}">{{ $endlineStatus ?: '-' }}</span></td>
                            <td><span class="{{ $deltaClass }}">{{ $deltaLabel }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="7">No records available yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="section-title">Consolidated Baseline Report by Section</div>
        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Section</th>
                        <th>Total</th>
                        <th>Severely Wasted</th>
                        <th>Wasted</th>
                        <th>Normal</th>
                        <th>Overweight</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($sectionSummary ?? collect()) as $summary)
                        <tr>
                            <td>{{ $summary['section'] }}</td>
                            <td>{{ $summary['total'] }}</td>
                            <td>{{ $summary['counts']['severely_wasted'] }}</td>
                            <td>{{ $summary['counts']['wasted'] }}</td>
                            <td>{{ $summary['counts']['normal'] }}</td>
                            <td>{{ $summary['counts']['overweight'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No consolidated data yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
