<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Medicine Inventory - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <script>document.documentElement.classList.add('js');</script>
    {{-- LUSOG order: theme, then this page's sheet, then the nurse rail. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
    @php $pageCssPath = resource_path('css/school-nurse-medicine-inventory.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
    <style>{!! file_get_contents(resource_path('css/nurse-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.nurse-lusog-sidebar', ['active' => 'inventory'])

<div class="main">
    @php
        $schoolName = session('active_school_name', 'No school assigned');
        $schoolYear = \App\Models\StudentHealthRecord::currentSchoolYear();
    @endphp

    <header class="topbar">
        <div class="topbar-bc"><span>School Nurse</span><span class="bc-sep">&rsaquo;</span><span>Medicine Inventory</span></div>
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
                    <div class="page-eyebrow">Inventory</div>
                    <h1 class="page-title">Medicine <span>Inventory</span></h1>
                    <p class="page-sub">Track current stock against reorder thresholds and add medicines quickly.</p>
                </div>
                <a href="{{ route('medicine-inventory.create') }}" class="btn btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Add Medicine
                </a>
            </div>
        </div>

        <div class="kpi-grid cols-3">
            <div class="card kpi accent-brand">
                <div class="kpi-top">
                    <div class="kpi-label">Total Medicines</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="2" width="18" height="20" rx="2"/><path d="M9 2v4h6V2"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($stats['total']) }}</div>
                <div class="kpi-hint">Items on the shelf list</div>
            </div>

            <div class="card kpi accent-success">
                <div class="kpi-top">
                    <div class="kpi-label">Above Threshold</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9 12l2 2 4-4"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($stats['good']) }}</div>
                <div class="kpi-hint">Comfortably stocked</div>
            </div>

            <div class="card kpi accent-amber">
                <div class="kpi-top">
                    <div class="kpi-label">Low Stock</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($stats['low']) }}</div>
                <div class="kpi-hint">At or below reorder point</div>
            </div>
        </div>

        <section class="card forecast-card">
            <div class="forecast-grid">
                <div class="forecast-main">
                    <div class="page-eyebrow">Predictive Reorder Module</div>
                    <h2 class="forecast-title">{{ $prediction['medicine_name'] }} stock tends to spike in January.</h2>
                    <p class="forecast-sub">Based on the latest monthly dispensing pattern, January usage is the highest. The system applies a 20% safety buffer and recommends the next month stock target to reduce stockout risk.</p>
                    <div class="fc-stats">
                        <div class="fc-stat">
                            <div class="fc-stat-label">Current Stock</div>
                            <div class="fc-stat-value">{{ $prediction['current_stock'] }} {{ $prediction['unit'] }}</div>
                        </div>
                        <div class="fc-stat">
                            <div class="fc-stat-label">Target For {{ $prediction['next_month'] }}</div>
                            <div class="fc-stat-value">{{ $prediction['recommended_doses'] }} {{ $prediction['unit'] }}</div>
                        </div>
                        <div class="fc-stat">
                            <div class="fc-stat-label">Recommended Order</div>
                            <div class="fc-stat-value {{ $prediction['recommended_order'] > 0 ? 'is-order' : '' }}">{{ $prediction['recommended_order'] }} {{ $prediction['unit'] }}</div>
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

        <div class="section-title" style="margin-top:24px">Current Inventory</div>
        <div class="table-card">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th class="num">Stock</th>
                            <th class="num">Minimum</th>
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
                            <td><strong>{{ $medicine->name }}</strong></td>
                            <td class="num">{{ $medicine->stock_quantity }} {{ $medicine->unit }}</td>
                            <td class="num">{{ $medicine->minimum_threshold }} {{ $medicine->unit }}</td>
                            <td>
                                @if ($isCritical)
                                    <span class="badge badge-critical">Out of Stock</span>
                                @elseif ($isLow)
                                    <span class="badge badge-monitor">Low Stock</span>
                                @else
                                    <span class="badge badge-normal">In Stock</span>
                                @endif
                            </td>
                            <td class="tnum">{{ $medicine->updated_at?->format('Y-m-d') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="table-empty">No medicine records yet. Use Add Medicine to create your first item.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@include('partials.nurse-page-transition')
</body>
</html>
