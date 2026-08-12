<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Data Visualization - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
        @php $pageCssPath = resource_path('css/school-nurse-data-visualization.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
    {{-- One shared palette for pages not yet on lusog-theme.css. Loaded
         last so it overrides this page's own :root colours. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-palette.css')) !!}</style>
</head>
<body>
@include('partials.nurse-sidebar', ['active' => 'visualization'])

<div class="main">
    <header class="topbar">
        <div class="topbar-breadcrumb">
            <a href="{{ route('dashboard.school-nurse') }}" class="bc-home">Dashboard</a>
            <span class="bc-sep">&rsaquo;</span>
            <span class="bc-current">Data Visualization</span>
        </div>
        <div class="topbar-chip">DCNHS - SY 2025-2026</div>
        @include('partials.live-clock')
    </header>

    <div class="content">
        <h1 class="page-title">Manuscript-Based <span>Data Visualization</span></h1>
        <p class="page-sub">Input-Process-Output aligned view: BMI status, consultations, trends, inventory thresholds, and feeding program outcomes.</p>

        <section class="viz-grid">
            <article class="viz-card">
                <div class="viz-head"><div class="viz-title">Nutritional Status Donut Chart</div><div class="viz-meta">Source: Student Profile Module (BMI baseline: height and weight)</div></div>
                <div class="viz-body donut-wrap">
                    <div class="donut" aria-hidden="true"></div>
                    <div>
                        <div class="legend-item"><span class="legend-dot" style="background:#1F8A4C"></span>Normal - 49%</div>
                        <div class="legend-item"><span class="legend-dot" style="background:#F2B84B"></span>Wasted - 24%</div>
                        <div class="legend-item"><span class="legend-dot" style="background:#D95C5C"></span>Severely Wasted - 14%</div>
                        <div class="legend-item"><span class="legend-dot" style="background:#3D8FA3"></span>Overweight/Obese - 13%</div>
                    </div>
                </div>
            </article>

            <article class="viz-card">
                <div class="viz-head"><div class="viz-title">Top Consultation Cases Bar Chart</div><div class="viz-meta">Source: Consultation Module (condition field)</div></div>
                <div class="viz-body">
                    <div class="bars">
                        <div class="bar" style="height:95%"></div>
                        <div class="bar" style="height:84%"></div>
                        <div class="bar" style="height:70%"></div>
                        <div class="bar" style="height:57%"></div>
                        <div class="bar" style="height:48%"></div>
                        <div class="bar" style="height:35%"></div>
                    </div>
                </div>
            </article>

            <article class="viz-card">
                <div class="viz-head"><div class="viz-title">Consultation Trend Line Chart</div><div class="viz-meta">Source: Consultation Module (dates grouped by month)</div></div>
                <div class="viz-body">
                    <svg class="line" viewBox="0 0 560 220" preserveAspectRatio="none" aria-label="Monthly consultation trend">
                        <polyline points="18,166 96,138 174,150 252,112 330,94 408,120 486,78 542,98" fill="none" stroke="#3D8FA3" stroke-width="4" />
                        <circle cx="18" cy="166" r="4" fill="#3D8FA3" /><circle cx="96" cy="138" r="4" fill="#3D8FA3" /><circle cx="174" cy="150" r="4" fill="#3D8FA3" /><circle cx="252" cy="112" r="4" fill="#3D8FA3" /><circle cx="330" cy="94" r="4" fill="#3D8FA3" /><circle cx="408" cy="120" r="4" fill="#3D8FA3" /><circle cx="486" cy="78" r="4" fill="#3D8FA3" /><circle cx="542" cy="98" r="4" fill="#3D8FA3" />
                    </svg>
                </div>
            </article>

            <article class="viz-card">
                <div class="viz-head"><div class="viz-title">Medicine Inventory Gauges</div><div class="viz-meta">Source: Medicine Inventory Module (stock quantity vs minimum threshold)</div></div>
                <div class="viz-body">
                    <div class="gauge"><div class="gauge-top"><span>Paracetamol</span><span>18% / min 20%</span></div><div class="gauge-bar"><div class="gauge-fill" style="width:18%;background:#D95C5C"></div></div></div>
                    <div class="gauge"><div class="gauge-top"><span>Amoxicillin</span><span>24% / min 20%</span></div><div class="gauge-bar"><div class="gauge-fill" style="width:24%;background:#F2B84B"></div></div></div>
                    <div class="gauge"><div class="gauge-top"><span>Antihistamine</span><span>34% / min 20%</span></div><div class="gauge-bar"><div class="gauge-fill" style="width:34%;background:#F2B84B"></div></div></div>
                    <div class="gauge"><div class="gauge-top"><span>Vitamin C</span><span>67% / min 20%</span></div><div class="gauge-bar"><div class="gauge-fill" style="width:67%;background:#1F8A4C"></div></div></div>
                </div>
            </article>

            <article class="viz-card" style="grid-column:1 / -1;">
                <div class="viz-head"><div class="viz-title">Feeding Program Stacked Bar Chart</div><div class="viz-meta">Source: Feeding Program Module (baseline vs endline nutritional status)</div></div>
                <div class="viz-body">
                    <div class="stack-wrap">
                        <div class="stack"><div class="seg-g" style="height:34%"></div><div class="seg-a" style="height:24%"></div><div class="seg-r" style="height:20%"></div></div>
                        <div class="stack"><div class="seg-g" style="height:30%"></div><div class="seg-a" style="height:30%"></div><div class="seg-r" style="height:16%"></div></div>
                        <div class="stack"><div class="seg-g" style="height:44%"></div><div class="seg-a" style="height:20%"></div><div class="seg-r" style="height:12%"></div></div>
                        <div class="stack"><div class="seg-g" style="height:50%"></div><div class="seg-a" style="height:18%"></div><div class="seg-r" style="height:10%"></div></div>
                        <div class="stack"><div class="seg-g" style="height:56%"></div><div class="seg-a" style="height:12%"></div><div class="seg-r" style="height:8%"></div></div>
                        <div class="stack"><div class="seg-g" style="height:62%"></div><div class="seg-a" style="height:10%"></div><div class="seg-r" style="height:6%"></div></div>
                    </div>
                    <div class="months"><span>Baseline</span><span>Month 1</span><span>Month 2</span><span>Month 3</span><span>Month 4</span><span>Endline</span></div>
                </div>
            </article>
        </section>
    </div>
</div>
</body>
</html>
