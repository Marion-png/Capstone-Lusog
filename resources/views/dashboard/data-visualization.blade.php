<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Data Visualization - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <script>document.documentElement.classList.add('js');</script>
    {{-- LUSOG order: theme, then this page's sheet, then the nurse rail. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
    @php $pageCssPath = resource_path('css/school-nurse-data-visualization.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
    <style>{!! file_get_contents(resource_path('css/nurse-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.nurse-lusog-sidebar', ['active' => 'visualization'])

<div class="main">
    @php
        $schoolName = session('active_school_name', 'No school assigned');
        $schoolYear = \App\Models\StudentHealthRecord::currentSchoolYear();
    @endphp

    <header class="topbar">
        <div class="topbar-bc"><span>School Nurse</span><span class="bc-sep">&rsaquo;</span><span>Data Visualization</span></div>

        @include('partials.nurse-learner-search')
        <div class="topbar-spacer"></div>
        <div class="topbar-chip"><span class="dot"></span>{{ $schoolName }} &middot; SY {{ $schoolYear }}</div>
        @include('partials.live-clock')
    </header>

    <div class="content">
        <div class="page-header">
            <div class="page-eyebrow">Reports</div>
            <h1 class="page-title">Manuscript-Based <span>Data Visualization</span></h1>
            <p class="page-sub">Input-Process-Output aligned view: BMI status, consultations, trends, inventory thresholds, and feeding program outcomes.</p>
        </div>

        {{-- These charts are illustrative layouts, not this school's figures:
             every value below is fixed in the markup. Wiring them to the
             student, consultation, inventory and feeding tables is a separate
             piece of work — until then the banner says so, rather than letting
             a reader take the numbers for their own. --}}
        <div class="alert-bar is-info">
            <div class="alert-body">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <div>
                    <strong>Sample layout &mdash; not live data</strong>
                    <span>These charts show the intended reports. The figures are placeholders and are not read from {{ $schoolName }}'s records.</span>
                </div>
            </div>
        </div>

        <section class="viz-grid">
            <article class="card viz-card">
                <div class="viz-head">
                    <div class="viz-title">Nutritional Status Donut Chart</div>
                    <div class="viz-meta">Source: Student Profile Module (BMI baseline: height and weight)</div>
                </div>
                <div class="viz-body donut-wrap">
                    <div class="donut" style="background:conic-gradient(var(--lg-emerald) 0 49%, var(--lg-amber) 49% 73%, var(--lg-danger) 73% 87%, var(--lg-info) 87% 100%)" aria-hidden="true"></div>
                    <div>
                        <div class="legend-item"><span class="legend-dot" style="background:var(--lg-emerald)"></span>Normal &mdash; 49%</div>
                        <div class="legend-item"><span class="legend-dot" style="background:var(--lg-amber)"></span>Wasted &mdash; 24%</div>
                        <div class="legend-item"><span class="legend-dot" style="background:var(--lg-danger)"></span>Severely Wasted &mdash; 14%</div>
                        <div class="legend-item"><span class="legend-dot" style="background:var(--lg-info)"></span>Overweight/Obese &mdash; 13%</div>
                    </div>
                </div>
            </article>

            <article class="card viz-card">
                <div class="viz-head">
                    <div class="viz-title">Top Consultation Cases Bar Chart</div>
                    <div class="viz-meta">Source: Consultation Module (condition field)</div>
                </div>
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

            <article class="card viz-card">
                <div class="viz-head">
                    <div class="viz-title">Consultation Trend Line Chart</div>
                    <div class="viz-meta">Source: Consultation Module (dates grouped by month)</div>
                </div>
                <div class="viz-body">
                    <svg class="line" viewBox="0 0 560 220" preserveAspectRatio="none" aria-label="Monthly consultation trend">
                        <polyline points="18,166 96,138 174,150 252,112 330,94 408,120 486,78 542,98" fill="none" stroke="var(--series-healthy)" stroke-width="3" stroke-linejoin="round" stroke-linecap="round" />
                        {{-- 2px surface ring on each point, so a marker stays
                             legible where the line crosses the grid. --}}
                        @foreach ([[18,166],[96,138],[174,150],[252,112],[330,94],[408,120],[486,78],[542,98]] as [$px, $py])
                            <circle cx="{{ $px }}" cy="{{ $py }}" r="5" fill="var(--series-healthy)" stroke="#fff" stroke-width="2" />
                        @endforeach
                    </svg>
                </div>
            </article>

            <article class="card viz-card">
                <div class="viz-head">
                    <div class="viz-title">Medicine Inventory Gauges</div>
                    <div class="viz-meta">Source: Medicine Inventory Module (stock quantity vs minimum threshold)</div>
                </div>
                <div class="viz-body">
                    @php
                        // Fixed sample rows. Colour follows the status scale:
                        // below the reorder point is critical, just above it is
                        // monitoring, comfortably above is healthy.
                        $gauges = [
                            ['Paracetamol', 18, 'var(--lg-danger)'],
                            ['Amoxicillin', 24, 'var(--lg-amber)'],
                            ['Antihistamine', 34, 'var(--lg-amber)'],
                            ['Vitamin C', 67, 'var(--lg-emerald)'],
                        ];
                    @endphp
                    @foreach ($gauges as [$name, $pct, $colour])
                        <div class="gauge">
                            <div class="gauge-top"><strong>{{ $name }}</strong><span class="tnum">{{ $pct }}% / min 20%</span></div>
                            <div class="gauge-bar"><div class="gauge-fill" style="width:{{ $pct }}%;background:{{ $colour }}"></div></div>
                        </div>
                    @endforeach
                </div>
            </article>

            <article class="card viz-card is-wide" style="grid-column:1 / -1;">
                <div class="viz-head">
                    <div class="viz-title">Feeding Program Stacked Bar Chart</div>
                    <div class="viz-meta">Source: Feeding Program Module (baseline vs endline nutritional status)</div>
                </div>
                <div class="viz-body">
                    <div class="stack-wrap">
                        @foreach ([[34,24,20],[30,30,16],[44,20,12],[50,18,10],[56,12,8],[62,10,6]] as [$g, $a, $r])
                            <div class="stack">
                                <div class="seg-g" style="height:{{ $g }}%"></div>
                                <div class="seg-a" style="height:{{ $a }}%"></div>
                                <div class="seg-r" style="height:{{ $r }}%"></div>
                            </div>
                        @endforeach
                    </div>
                    <div class="months">
                        <span>Baseline</span><span>Month 1</span><span>Month 2</span>
                        <span>Month 3</span><span>Month 4</span><span>Endline</span>
                    </div>
                    <div class="legend-item" style="margin-top:12px">
                        <span class="legend-dot" style="background:var(--lg-emerald)"></span>Normal
                        <span class="legend-dot" style="background:var(--lg-amber);margin-left:14px"></span>Wasted
                        <span class="legend-dot" style="background:var(--lg-danger);margin-left:14px"></span>Severely Wasted
                    </div>
                </div>
            </article>
        </section>
    </div>
</div>

@include('partials.nurse-page-transition')
</body>
</html>