<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Medicine Inventory - LUSOG</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --g900: #14532d; --g800: #166534; --g700: #15803d; --g600: #16a34a; --g500: #22c55e;
            --g300: #86efac; --g200: #bbf7d0; --g100: #dcfce7; --g50: #f0fdf4;
            --sidebar-w: 248px; --sidebar-collapsed-w: 76px; --topbar-h: 64px;
            --cream: #f7f8f5; --card: #ffffff; --border: #e4ece7;
            --text-1: #0d1f14; --text-2: #3d5c47; --text-3: #7a9e87;
            --red: #ef4444; --amber: #f59e0b;
            --radius: 14px; --radius-sm: 10px;
            --shadow: 0 1px 4px rgba(5,46,22,.06), 0 4px 16px rgba(5,46,22,.06);
        }
        html, body { height: 100%; font-family: 'DM Sans', sans-serif; background: var(--cream); color: var(--text-1); overflow: hidden; }
        .sidebar { position: fixed; left: 0; top: 0; bottom: 0; width: var(--sidebar-collapsed-w); background: var(--g900); display: flex; flex-direction: column; z-index: 10; overflow: hidden; transition: width .24s ease; }
        .sidebar:hover { width: var(--sidebar-w); }
        .sidebar::after { content: ''; position: absolute; inset: 0; background: radial-gradient(ellipse 120% 40% at 50% 100%, rgba(34,197,94,.18) 0%, transparent 70%), radial-gradient(ellipse 80% 30% at 80% 0%, rgba(74,222,128,.1) 0%, transparent 60%); pointer-events: none; }
        .sb-grid { position: absolute; inset: 0; background-image: linear-gradient(rgba(134,239,172,.05) 1px, transparent 1px), linear-gradient(90deg, rgba(134,239,172,.05) 1px, transparent 1px); background-size: 28px 28px; }
        .sb-logo { padding: 14px 10px; position: relative; z-index: 2; border-bottom: 1px solid rgba(255,255,255,.08); display: flex; justify-content: center; transition: padding .24s ease; }
        .sb-logo-full { width: 48px; max-width: 100%; height: auto; display: block; transition: width .24s ease; }
        .sidebar:hover .sb-logo { padding: 20px 20px 18px; }
        .sidebar:hover .sb-logo-full { width: 176px; }
        .sb-nav { flex: 1; overflow-y: auto; padding: 16px 8px; position: relative; z-index: 2; scrollbar-width: none; transition: padding .24s ease; }
        .sidebar:hover .sb-nav { padding: 16px 12px; }
        .sb-nav::-webkit-scrollbar { display: none; }
        .sb-section-label { font-size: .6rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: rgba(134,239,172,.5); padding: 0 8px; margin: 20px 0 8px; }
        .sb-link { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: var(--radius-sm); text-decoration: none; color: rgba(255,255,255,.6); font-size: .83rem; font-weight: 500; transition: background .15s, color .15s, padding .24s ease; margin-bottom: 2px; white-space: nowrap; overflow: hidden; }
        .sb-link:hover { background: rgba(255,255,255,.08); color: rgba(255,255,255,.9); }
        .sb-link.active { background: rgba(34,197,94,.18); color: var(--g300); }
        .sb-link svg { width: 16px; height: 16px; flex-shrink: 0; }
        .sidebar:not(:hover) .sb-section-label { display: none; }
        .sidebar:not(:hover) .sb-link { justify-content: center; font-size: 0; padding: 10px; gap: 0; }
        .sb-user { padding: 14px 16px; border-top: 1px solid rgba(255,255,255,.08); display: flex; align-items: center; gap: 11px; position: relative; z-index: 2; }
        .sb-avatar { width: 34px; height: 34px; border-radius: 50%; background: var(--g600); display: grid; place-items: center; font-size: .8rem; font-weight: 700; color: white; }
        .sb-user-meta { min-width: 0; }
        .sb-user-name { font-size: .8rem; font-weight: 600; color: white; line-height: 1.2; }
        .sb-user-role { font-size: .68rem; color: var(--g300); }
        .sidebar:not(:hover) .sb-user { padding: 14px 10px; }
        .sidebar:not(:hover) .sb-user-meta { display: none; }

        .main { margin-left: var(--sidebar-collapsed-w); height: 100vh; display: flex; flex-direction: column; overflow: hidden; transition: margin-left .24s ease; }
        .sidebar:hover ~ .main { margin-left: var(--sidebar-w); }
        .topbar { height: var(--topbar-h); flex-shrink: 0; background: white; border-bottom: 1px solid var(--border); display: flex; align-items: center; padding: 0 28px; }
        .topbar-breadcrumb { display: flex; align-items: center; gap: 8px; }
        .bc-home { font-size: .8rem; color: var(--text-3); text-decoration: none; }
        .bc-sep { color: var(--border); font-size: .9rem; }
        .bc-current { font-size: .8rem; font-weight: 700; color: var(--text-1); }

        .content { flex: 1; overflow-y: auto; padding: 24px 28px 36px; }
        .page-title { font-family: 'DM Serif Display', serif; font-size: 1.75rem; line-height: 1.1; }
        .page-title span { color: var(--g700); font-style: italic; }
        .page-sub { font-size: .84rem; color: var(--text-3); margin-top: 6px; }
        .flash { margin-top: 12px; border: 1px solid var(--g200); background: var(--g50); color: var(--g800); padding: 10px 12px; border-radius: var(--radius-sm); font-size: .8rem; font-weight: 600; }

        .stats { margin-top: 16px; display: grid; grid-template-columns: repeat(3, minmax(180px, 1fr)); gap: 12px; }
        .stat { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-sm); box-shadow: var(--shadow); padding: 12px 14px; }
        .stat-label { font-size: .72rem; color: var(--text-3); text-transform: uppercase; letter-spacing: .06em; }
        .stat-value { margin-top: 4px; font-family: 'DM Serif Display', serif; font-size: 1.5rem; }

        .forecast-card { margin-top: 14px; background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-sm); box-shadow: var(--shadow); overflow: hidden; }
        .forecast-grid { display: grid; grid-template-columns: 1.05fr 1fr; }
        .forecast-main { padding: 16px 16px 14px; border-right: 1px solid var(--border); }
        .forecast-eyebrow { font-size: .68rem; letter-spacing: .1em; text-transform: uppercase; color: var(--g700); font-weight: 700; }
        .forecast-title { margin-top: 6px; font-size: 1.05rem; font-weight: 700; color: var(--text-1); }
        .forecast-sub { margin-top: 6px; color: var(--text-3); font-size: .8rem; line-height: 1.45; }
        .forecast-kpis { margin-top: 12px; display: grid; grid-template-columns: repeat(3, minmax(120px, 1fr)); gap: 8px; }
        .kpi { border: 1px solid var(--border); border-radius: 9px; padding: 8px 10px; background: #fcfefd; }
        .kpi-label { font-size: .67rem; color: var(--text-3); text-transform: uppercase; letter-spacing: .06em; }
        .kpi-value { margin-top: 2px; font-size: .97rem; font-weight: 700; color: var(--text-1); }
        .kpi-value.danger { color: #b91c1c; }
        .forecast-graph { padding: 14px 14px 12px; }
        .graph-title { font-size: .76rem; font-weight: 700; color: var(--text-2); margin-bottom: 8px; }
        .line-chart { height: 190px; border: 1px solid var(--border); border-radius: 10px; background: linear-gradient(180deg, #fcfffd, #f4faf6); }
        .line-chart svg { width: 100%; height: 100%; display: block; }
        .grid-line { stroke: #dbe9df; stroke-width: 1; }
        .axis-text { fill: #7a9e87; font-size: 10px; font-weight: 600; }
        .usage-line { fill: none; stroke: #1f7a3d; stroke-width: 3; stroke-linecap: round; stroke-linejoin: round; }
        .usage-area { fill: rgba(34, 197, 94, 0.14); }
        .usage-point { fill: #1f7a3d; stroke: #ffffff; stroke-width: 2; }
        .usage-point.peak { fill: #d97706; }
        .graph-note { margin-top: 8px; font-size: .72rem; color: var(--text-3); }

        .grid { margin-top: 16px; display: grid; grid-template-columns: 1fr 1.25fr; gap: 12px; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-sm); box-shadow: var(--shadow); }
        .card-head { padding: 12px 14px; border-bottom: 1px solid var(--border); font-size: .8rem; font-weight: 700; color: var(--text-2); }
        .card-body { padding: 14px; }
        .field { margin-bottom: 10px; }
        .field:last-child { margin-bottom: 0; }
        label { display: block; font-size: .74rem; font-weight: 700; margin-bottom: 5px; color: var(--text-2); }
        input { width: 100%; border: 1px solid var(--border); border-radius: 8px; padding: 9px 10px; font: inherit; font-size: .84rem; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .err { margin-top: 4px; font-size: .72rem; color: #b91c1c; }
        .btn { border: none; background: var(--g700); color: white; border-radius: 8px; padding: 10px 12px; font-weight: 600; font-size: .82rem; cursor: pointer; }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 12px; border-bottom: 1px solid var(--border); text-align: left; font-size: .79rem; }
        th { background: var(--cream); font-size: .68rem; letter-spacing: .08em; text-transform: uppercase; color: var(--text-3); }
        tr:last-child td { border-bottom: none; }
        .pill { display: inline-flex; align-items: center; border-radius: 999px; padding: 3px 8px; font-size: .68rem; font-weight: 700; }
        .ok { background: var(--g100); color: var(--g800); }
        .low { background: #fef3c7; color: #92400e; }
        .critical { background: #fee2e2; color: #b91c1c; }

        @media (max-width: 1050px) { .grid { grid-template-columns: 1fr; } }
        @media (max-width: 780px) {
            :root { --sidebar-w: 0px; --sidebar-collapsed-w: 0px; }
            .sidebar { display: none; }
            .stats { grid-template-columns: 1fr; }
            .forecast-grid { grid-template-columns: 1fr; }
            .forecast-main { border-right: none; border-bottom: 1px solid var(--border); }
            .forecast-kpis { grid-template-columns: 1fr; }
            .row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="sb-grid"></div>
    <div class="sb-logo"><img src="{{ asset('images/lusog-logo.png') }}" alt="LUSOG Logo" class="sb-logo-full"></div>
    <nav class="sb-nav">
        <div class="sb-section-label">Main</div>
        <a href="{{ route('dashboard.school-nurse') }}" class="sb-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>Dashboard</a>
        <a href="{{ route('dashboard.student-health-records') }}" class="sb-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>Health Records</a>
        <a href="{{ route('dashboard.consultation-log') }}" class="sb-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4"/><path d="M21 12c0 4.97-4.03 9-9 9S3 16.97 3 12 7.03 3 12 3s9 4.03 9 9z"/></svg>Consultation Log</a>
        <div class="sb-section-label">Health Programs</div>
        <a href="#" class="sb-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>Feeding Program</a>
        <a href="#" class="sb-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 3-7-3"/><path d="M3 9v6l7 3 7-3V9"/><polyline points="3 9 12 6 21 9"/></svg>Deworming Program</a>
        <div class="sb-section-label">Inventory</div>
        <a href="{{ route('dashboard.medicine-inventory') }}" class="sb-link active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="2" width="18" height="20" rx="2"/><path d="M9 2v4h6V2"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>Medicine Inventory</a>
        <a href="#" class="sb-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Dispensing Log</a>
        <div class="sb-section-label">Reports</div>
        <a href="{{ route('dashboard.data-visualization') }}" class="sb-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>Data Visualization</a>
    </nav>
    <div class="sb-user">
        <div class="sb-avatar">{{ substr(auth()->user()->name ?? 'SN', 0, 2) }}</div>
        <div class="sb-user-meta"><div class="sb-user-name">{{ auth()->user()->name ?? 'School Nurse' }}</div><div class="sb-user-role">School Nurse - DCNHS</div></div>
    </div>
</aside>

<div class="main">
    <header class="topbar">
        <div class="topbar-breadcrumb">
            <a href="{{ route('dashboard.school-nurse') }}" class="bc-home">Dashboard</a>
            <span class="bc-sep">></span>
            <span class="bc-current">Medicine Inventory</span>
        </div>
    </header>

    <div class="content">
        <h1 class="page-title">Medicine <span>Inventory</span></h1>
        <p class="page-sub">Track current stock against reorder thresholds and add medicines quickly.</p>

        @if (session('success'))
            <div class="flash">{{ session('success') }}</div>
        @endif

        <section class="stats">
            <article class="stat"><div class="stat-label">Total Medicines</div><div class="stat-value">{{ $stats['total'] }}</div></article>
            <article class="stat"><div class="stat-label">Above Threshold</div><div class="stat-value">{{ $stats['good'] }}</div></article>
            <article class="stat"><div class="stat-label">Low Stock</div><div class="stat-value">{{ $stats['low'] }}</div></article>
        </section>

        <section class="forecast-card">
            <div class="forecast-grid">
                <div class="forecast-main">
                    <div class="forecast-eyebrow">Predictive Reorder Module</div>
                    <h2 class="forecast-title">{{ $prediction['medicine_name'] }} stock tends to spike in January.</h2>
                    <p class="forecast-sub">Based on the latest monthly dispensing pattern, January usage is the highest. The system applies a 20% safety buffer and recommends the next month stock target to reduce stockout risk.</p>
                    <div class="forecast-kpis">
                        <div class="kpi">
                            <div class="kpi-label">Current Stock</div>
                            <div class="kpi-value">{{ $prediction['current_stock'] }} {{ $prediction['unit'] }}</div>
                        </div>
                        <div class="kpi">
                            <div class="kpi-label">Target For {{ $prediction['next_month'] }}</div>
                            <div class="kpi-value">{{ $prediction['recommended_doses'] }} {{ $prediction['unit'] }}</div>
                        </div>
                        <div class="kpi">
                            <div class="kpi-label">Recommended Order</div>
                            <div class="kpi-value {{ $prediction['recommended_order'] > 0 ? 'danger' : '' }}">{{ $prediction['recommended_order'] }} {{ $prediction['unit'] }}</div>
                        </div>
                    </div>
                </div>
                <div class="forecast-graph">
                    <div class="graph-title">Monthly Usage Report ({{ $prediction['medicine_name'] }})</div>
                    @php
                        $usageSeries = collect($prediction['monthly_usage'])->values();
                        $chartWidth = 560;
                        $chartHeight = 190;
                        $padX = 36;
                        $padY = 16;
                        $plotWidth = $chartWidth - ($padX * 2);
                        $plotHeight = $chartHeight - ($padY * 2);
                        $maxUsage = max(1, (int) $prediction['max_usage']);
                        $axisStep = max(10, (int) ceil(($maxUsage / 4) / 10) * 10);
                        $axisMax = $axisStep * 4;
                        $pointCount = max(1, $usageSeries->count());

                        $plotPoints = $usageSeries->map(function ($point, $index) use ($pointCount, $padX, $plotWidth, $padY, $plotHeight, $axisMax) {
                            $x = $padX + ($pointCount === 1 ? $plotWidth / 2 : ($index / ($pointCount - 1)) * $plotWidth);
                            $y = $padY + $plotHeight - (((int) $point['used'] / $axisMax) * $plotHeight);

                            return [
                                'month' => $point['month'],
                                'used' => (int) $point['used'],
                                'x' => round($x, 2),
                                'y' => round($y, 2),
                            ];
                        });

                        $linePoints = $plotPoints->map(fn ($p) => $p['x'] . ',' . $p['y'])->implode(' ');
                        $areaPoints = $linePoints . ' ' . ($padX + $plotWidth) . ',' . ($padY + $plotHeight) . ' ' . $padX . ',' . ($padY + $plotHeight);
                    @endphp
                    <div class="line-chart" role="img" aria-label="Monthly usage line graph for {{ $prediction['medicine_name'] }}">
                        <svg viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" aria-hidden="true" focusable="false">
                            @for ($i = 0; $i <= 4; $i++)
                                @php
                                    $y = $padY + (($plotHeight / 4) * $i);
                                    $label = $axisMax - ($axisStep * $i);
                                @endphp
                                <line x1="{{ $padX }}" y1="{{ $y }}" x2="{{ $padX + $plotWidth }}" y2="{{ $y }}" class="grid-line" />
                                <text x="8" y="{{ $y + 3 }}" class="axis-text">{{ $label }}</text>
                            @endfor

                            <polygon points="{{ $areaPoints }}" class="usage-area"></polygon>
                            <polyline points="{{ $linePoints }}" class="usage-line"></polyline>

                            @foreach($plotPoints as $point)
                                <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="5" class="usage-point {{ $point['month'] === 'Jan' ? 'peak' : '' }}"></circle>
                                <text x="{{ $point['x'] }}" y="{{ $chartHeight - 6 }}" text-anchor="middle" class="axis-text">{{ $point['month'] }}</text>
                            @endforeach
                        </svg>
                    </div>
                    <div class="graph-note">January is highlighted because it had the highest consumption and triggered repeated low-stock events.</div>
                </div>
            </div>
        </section>

        <section class="grid">
            <article class="card">
                <div class="card-head">Add Medicine</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('medicine-inventory.store') }}">
                        @csrf
                        <div class="field">
                            <label for="name">Medicine Name</label>
                            <input id="name" name="name" type="text" value="{{ old('name') }}" placeholder="e.g. Paracetamol" required>
                            @error('name')<div class="err">{{ $message }}</div>@enderror
                        </div>
                        <div class="row">
                            <div class="field">
                                <label for="stock_quantity">Current Stock</label>
                                <input id="stock_quantity" name="stock_quantity" type="number" min="0" value="{{ old('stock_quantity', 0) }}" required>
                                @error('stock_quantity')<div class="err">{{ $message }}</div>@enderror
                            </div>
                            <div class="field">
                                <label for="minimum_threshold">Minimum Threshold</label>
                                <input id="minimum_threshold" name="minimum_threshold" type="number" min="0" value="{{ old('minimum_threshold', 20) }}" required>
                                @error('minimum_threshold')<div class="err">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="row">
                            <div class="field">
                                <label for="unit">Unit</label>
                                <input id="unit" name="unit" type="text" value="{{ old('unit', 'pcs') }}" required>
                                @error('unit')<div class="err">{{ $message }}</div>@enderror
                            </div>
                            <div class="field">
                                <label for="notes">Notes</label>
                                <input id="notes" name="notes" type="text" value="{{ old('notes') }}" placeholder="Optional">
                                @error('notes')<div class="err">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <button type="submit" class="btn">Save Medicine</button>
                    </form>
                </div>
            </article>

            <article class="card">
                <div class="card-head">Current Inventory</div>
                <div class="card-body" style="padding:0;">
                    <table>
                        <thead>
                            <tr>
                                <th>Medicine</th>
                                <th>Stock</th>
                                <th>Minimum</th>
                                <th>Status</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($medicines as $medicine)
                            @php
                                $isCritical = $medicine->stock_quantity === 0;
                                $isLow = $medicine->stock_quantity > 0 && $medicine->stock_quantity < $medicine->minimum_threshold;
                            @endphp
                            <tr>
                                <td>{{ $medicine->name }}</td>
                                <td>{{ $medicine->stock_quantity }} {{ $medicine->unit }}</td>
                                <td>{{ $medicine->minimum_threshold }} {{ $medicine->unit }}</td>
                                <td>
                                    @if ($isCritical)
                                        <span class="pill critical">Out of Stock</span>
                                    @elseif ($isLow)
                                        <span class="pill low">Low Stock</span>
                                    @else
                                        <span class="pill ok">In Stock</span>
                                    @endif
                                </td>
                                <td>{{ $medicine->updated_at->format('Y-m-d') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">No medicine records yet. Add your first item from the form.</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </article>
        </section>
    </div>
</div>
</body>
</html>
