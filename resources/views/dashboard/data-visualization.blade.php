<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Data Visualization - LUSOG</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
        @php $pageCssPath = resource_path('css/school-nurse-data-visualization.css'); @endphp
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
        <a href="{{ route('dashboard.medicine-inventory') }}" class="sb-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="2" width="18" height="20" rx="2"/><path d="M9 2v4h6V2"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>Medicine Inventory</a>
        <a href="#" class="sb-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Dispensing Log</a>
        <div class="sb-section-label">Reports</div>
        <a href="{{ route('dashboard.data-visualization') }}" class="sb-link active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>Data Visualization</a>
    </nav>
    <div class="sb-user">
        <div class="sb-avatar">{{ substr(session('active_name', 'School Nurse'), 0, 2) }}</div>
        <div class="sb-user-meta"><div class="sb-user-name">{{ session('active_name', 'School Nurse') }}</div><div class="sb-user-role">School Nurse - DCNHS</div></div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="sb-logout" title="Sign out">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </button>
        </form>
    </div>
</aside>

<div class="main">
    <header class="topbar">
        <div class="topbar-breadcrumb">
            <a href="{{ route('dashboard.school-nurse') }}" class="bc-home">Dashboard</a>
            <span class="bc-sep">></span>
            <span class="bc-current">Data Visualization</span>
        </div>
        <div class="topbar-chip">DCNHS - SY 2025-2026</div>
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
                        <div class="legend-item"><span class="legend-dot" style="background:#16a34a"></span>Normal - 49%</div>
                        <div class="legend-item"><span class="legend-dot" style="background:#f59e0b"></span>Wasted - 24%</div>
                        <div class="legend-item"><span class="legend-dot" style="background:#ef4444"></span>Severely Wasted - 14%</div>
                        <div class="legend-item"><span class="legend-dot" style="background:#3b82f6"></span>Overweight/Obese - 13%</div>
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
                        <polyline points="18,166 96,138 174,150 252,112 330,94 408,120 486,78 542,98" fill="none" stroke="#3b82f6" stroke-width="4" />
                        <circle cx="18" cy="166" r="4" fill="#3b82f6" /><circle cx="96" cy="138" r="4" fill="#3b82f6" /><circle cx="174" cy="150" r="4" fill="#3b82f6" /><circle cx="252" cy="112" r="4" fill="#3b82f6" /><circle cx="330" cy="94" r="4" fill="#3b82f6" /><circle cx="408" cy="120" r="4" fill="#3b82f6" /><circle cx="486" cy="78" r="4" fill="#3b82f6" /><circle cx="542" cy="98" r="4" fill="#3b82f6" />
                    </svg>
                </div>
            </article>

            <article class="viz-card">
                <div class="viz-head"><div class="viz-title">Medicine Inventory Gauges</div><div class="viz-meta">Source: Medicine Inventory Module (stock quantity vs minimum threshold)</div></div>
                <div class="viz-body">
                    <div class="gauge"><div class="gauge-top"><span>Paracetamol</span><span>18% / min 20%</span></div><div class="gauge-bar"><div class="gauge-fill" style="width:18%;background:#ef4444"></div></div></div>
                    <div class="gauge"><div class="gauge-top"><span>Amoxicillin</span><span>24% / min 20%</span></div><div class="gauge-bar"><div class="gauge-fill" style="width:24%;background:#f59e0b"></div></div></div>
                    <div class="gauge"><div class="gauge-top"><span>Antihistamine</span><span>34% / min 20%</span></div><div class="gauge-bar"><div class="gauge-fill" style="width:34%;background:#f59e0b"></div></div></div>
                    <div class="gauge"><div class="gauge-top"><span>Vitamin C</span><span>67% / min 20%</span></div><div class="gauge-bar"><div class="gauge-fill" style="width:67%;background:#16a34a"></div></div></div>
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
