<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Medicine Inventory - LUSOG</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
        @php $pageCssPath = resource_path('css/school-nurse-medicine-inventory.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
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
        <a href="{{ route('dashboard.school-nurse.feeding-program') }}" class="sb-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>Feeding Program</a>
        <a href="{{ route('dashboard.school-nurse.deworming') }}" class="sb-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 3-7-3"/><path d="M3 9v6l7 3 7-3V9"/><polyline points="3 9 12 6 21 9"/></svg>Deworming Program</a>
        <div class="sb-section-label">Inventory</div>
        <a href="{{ route('dashboard.medicine-inventory') }}" class="sb-link active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="2" width="18" height="20" rx="2"/><path d="M9 2v4h6V2"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>Medicine Inventory</a>
        <a href="#" class="sb-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Dispensing Log</a>
        <div class="sb-section-label">Reports</div>
        <a href="{{ route('dashboard.data-visualization') }}" class="sb-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>Data Visualization</a>
    </nav>
    <div class="sb-user">
        <div class="sb-avatar">{{ substr(session('active_name', 'School Nurse'), 0, 2) }}</div>
        <div class="sb-user-meta"><div class="sb-user-name">{{ session('active_name', 'School Nurse') }}</div><div class="sb-user-role">School Nurse - DCNHS</div></div>
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
