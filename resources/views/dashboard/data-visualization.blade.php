<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Data Visualization - LUSOG</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --g900: #14532d; --g800: #166534; --g700: #15803d; --g600: #16a34a; --g500: #22c55e;
            --g300: #86efac; --g200: #bbf7d0; --g100: #dcfce7; --g50: #f0fdf4;
            --sidebar-w: 248px; --topbar-h: 64px;
            --cream: #f7f8f5; --card: #ffffff; --border: #e4ece7;
            --text-1: #0d1f14; --text-2: #3d5c47; --text-3: #7a9e87;
            --red: #ef4444; --amber: #f59e0b; --blue: #3b82f6;
            --shadow: 0 1px 4px rgba(5,46,22,.06), 0 4px 16px rgba(5,46,22,.06);
            --radius: 16px; --radius-sm: 10px;
        }
        html, body { height: 100%; font-family: 'DM Sans', sans-serif; background: var(--cream); color: var(--text-1); overflow: hidden; }

        .sidebar {
            position: fixed; left: 0; top: 0; bottom: 0; width: var(--sidebar-w); background: var(--g900);
            display: flex; flex-direction: column; z-index: 100; overflow: hidden;
        }
        .sidebar::after {
            content: ''; position: absolute; inset: 0;
            background: radial-gradient(ellipse 120% 40% at 50% 100%, rgba(34,197,94,.18) 0%, transparent 70%),
                        radial-gradient(ellipse 80% 30% at 80% 0%, rgba(74,222,128,.1) 0%, transparent 60%);
            pointer-events: none;
        }
        .sb-grid { position: absolute; inset: 0; background-image: linear-gradient(rgba(134,239,172,.05) 1px, transparent 1px), linear-gradient(90deg, rgba(134,239,172,.05) 1px, transparent 1px); background-size: 28px 28px; }
        .sb-logo { padding: 20px 20px 18px; position: relative; z-index: 2; border-bottom: 1px solid rgba(255,255,255,.08); display: flex; justify-content: center; }
        .sb-logo-full { width: 176px; max-width: 100%; height: auto; display: block; }
        .sb-nav { flex: 1; overflow-y: auto; padding: 16px 12px; position: relative; z-index: 2; scrollbar-width: none; }
        .sb-nav::-webkit-scrollbar { display: none; }
        .sb-section-label { font-size: .6rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: rgba(134,239,172,.5); padding: 0 8px; margin: 20px 0 8px; }
        .sb-link { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: var(--radius-sm); text-decoration: none; color: rgba(255,255,255,.6); font-size: .83rem; font-weight: 500; transition: background .15s, color .15s; margin-bottom: 2px; }
        .sb-link:hover { background: rgba(255,255,255,.08); color: rgba(255,255,255,.9); }
        .sb-link.active { background: rgba(34,197,94,.18); color: var(--g300); }
        .sb-link svg { width: 16px; height: 16px; flex-shrink: 0; }
        .sb-user { padding: 14px 16px; border-top: 1px solid rgba(255,255,255,.08); display: flex; align-items: center; gap: 11px; position: relative; z-index: 2; }
        .sb-avatar { width: 34px; height: 34px; border-radius: 50%; background: var(--g600); display: grid; place-items: center; font-size: .8rem; font-weight: 700; color: white; flex-shrink: 0; }
        .sb-user-name { font-size: .8rem; font-weight: 600; color: white; line-height: 1.2; }
        .sb-user-role { font-size: .68rem; color: var(--g300); }

        .main { margin-left: var(--sidebar-w); height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
        .topbar { height: var(--topbar-h); flex-shrink: 0; background: white; border-bottom: 1px solid var(--border); display: flex; align-items: center; padding: 0 28px; gap: 14px; }
        .topbar-breadcrumb { display: flex; align-items: center; gap: 8px; flex: 1; }
        .bc-home { font-size: .8rem; color: var(--text-3); text-decoration: none; }
        .bc-sep { color: var(--border); font-size: .9rem; }
        .bc-current { font-size: .8rem; font-weight: 700; color: var(--text-1); }
        .topbar-chip { display: flex; align-items: center; gap: 7px; background: var(--g50); border: 1px solid var(--g200); border-radius: 999px; padding: 6px 14px; font-size: .75rem; font-weight: 600; color: var(--g700); }

        .content { flex: 1; overflow-y: auto; padding: 24px 28px 40px; }
        .page-title { font-family: 'DM Serif Display', serif; font-size: 1.75rem; line-height: 1.15; }
        .page-title span { color: var(--g700); font-style: italic; }
        .page-sub { font-size: .84rem; color: var(--text-3); margin-top: 6px; }

        .viz-grid { margin-top: 18px; display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .viz-card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-sm); box-shadow: var(--shadow); }
        .viz-head { padding: 12px 14px; border-bottom: 1px solid var(--border); }
        .viz-title { font-size: .8rem; font-weight: 700; color: var(--text-2); }
        .viz-meta { font-size: .69rem; color: var(--text-3); margin-top: 4px; }
        .viz-body { padding: 14px; }

        .donut-wrap { display: flex; gap: 16px; align-items: center; }
        .donut {
            width: 165px; height: 165px; border-radius: 50%;
            background: conic-gradient(var(--g600) 0 49%, #f59e0b 49% 73%, #ef4444 73% 87%, #3b82f6 87% 100%);
            position: relative;
        }
        .donut::after { content: ''; position: absolute; inset: 24px; background: #fff; border-radius: 50%; box-shadow: inset 0 0 0 1px var(--border); }
        .legend-item { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; font-size: .78rem; color: var(--text-2); }
        .legend-dot { width: 9px; height: 9px; border-radius: 50%; }

        .bars { height: 190px; display: grid; grid-template-columns: repeat(6, 1fr); gap: 8px; align-items: end; }
        .bar { background: linear-gradient(180deg, #2f7d42, #1f5e2f); border-radius: 8px 8px 0 0; }

        .line { width: 100%; height: 190px; border: 1px solid var(--border); border-radius: 8px; background: linear-gradient(#f7faf9 1px, transparent 1px), linear-gradient(90deg, #f7faf9 1px, transparent 1px); background-size: 30px 30px; }

        .gauge { margin-bottom: 11px; }
        .gauge-top { display: flex; justify-content: space-between; font-size: .75rem; color: var(--text-3); margin-bottom: 6px; }
        .gauge-bar { height: 10px; background: #edf5ef; border-radius: 999px; overflow: hidden; }
        .gauge-fill { height: 100%; border-radius: 999px; }

        .stack-wrap { height: 200px; display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px; align-items: end; border-bottom: 1px solid var(--border); padding: 10px 8px; }
        .stack { border-radius: 6px 6px 0 0; overflow: hidden; display: flex; flex-direction: column; justify-content: end; height: 100%; background: #f3f7f5; }
        .seg-r { background: #ef4444; }
        .seg-a { background: #f59e0b; }
        .seg-g { background: #16a34a; }
        .months { display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px; margin-top: 7px; font-size: .68rem; color: var(--text-3); text-align: center; }

        @media (max-width: 1050px) {
            .viz-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 780px) {
            :root { --sidebar-w: 0px; }
            .sidebar { display: none; }
            .donut-wrap { flex-direction: column; align-items: flex-start; }
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
        <div class="sb-section-label">Reports</div>
        <a href="{{ route('dashboard.data-visualization') }}" class="sb-link active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>Data Visualization</a>
    </nav>
    <div class="sb-user">
        <div class="sb-avatar">{{ substr(auth()->user()->name ?? 'SN', 0, 2) }}</div>
        <div><div class="sb-user-name">{{ auth()->user()->name ?? 'School Nurse' }}</div><div class="sb-user-role">School Nurse - DCNHS</div></div>
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
