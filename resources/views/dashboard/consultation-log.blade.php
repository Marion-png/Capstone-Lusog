<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Consultation Log - LUSOG</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --g950: #052e16; --g900: #14532d; --g800: #166534;
            --g700: #15803d; --g600: #16a34a; --g500: #22c55e;
            --g400: #4ade80; --g300: #86efac; --g200: #bbf7d0;
            --g100: #dcfce7; --g50: #f0fdf4;
            --sidebar-w: 248px;
            --topbar-h: 64px;
            --cream: #f7f8f5;
            --card: #ffffff;
            --border: #e4ece7;
            --text-1: #0d1f14;
            --text-2: #3d5c47;
            --text-3: #7a9e87;
            --red: #ef4444;
            --amber: #f59e0b;
            --blue: #3b82f6;
            --shadow-card: 0 1px 4px rgba(5,46,22,.06), 0 4px 16px rgba(5,46,22,.06);
            --radius: 16px;
            --radius-sm: 10px;
        }

        html, body { height: 100%; font-family: 'DM Sans', sans-serif; background: var(--cream); color: var(--text-1); overflow: hidden; }

        .sidebar {
            position: fixed; left: 0; top: 0; bottom: 0;
            width: var(--sidebar-w); background: var(--g900);
            display: flex; flex-direction: column; z-index: 100; overflow: hidden;
        }
        .sidebar::after {
            content: ''; position: absolute; inset: 0;
            background: radial-gradient(ellipse 120% 40% at 50% 100%, rgba(34,197,94,.18) 0%, transparent 70%),
                        radial-gradient(ellipse 80% 30% at 80% 0%, rgba(74,222,128,.1) 0%, transparent 60%);
            pointer-events: none;
        }
        .sb-grid {
            position: absolute; inset: 0;
            background-image: linear-gradient(rgba(134,239,172,.05) 1px, transparent 1px),
                              linear-gradient(90deg, rgba(134,239,172,.05) 1px, transparent 1px);
            background-size: 28px 28px;
        }
        .sb-logo { padding: 24px 20px 20px; position: relative; z-index: 2; border-bottom: 1px solid rgba(255,255,255,.08); }
        .sb-logo-inner { display: flex; align-items: center; gap: 11px; }
        .sb-logo-icon { width: 38px; height: 38px; border-radius: 10px; background: var(--g500); display: grid; place-items: center; flex-shrink: 0; }
        .sb-logo-icon svg { width: 20px; height: 20px; fill: white; }
        .sb-logo-name { font-family: 'DM Serif Display', serif; font-size: 1.2rem; color: white; line-height: 1; }
        .sb-logo-sub { font-size: .6rem; color: var(--g300); letter-spacing: .1em; text-transform: uppercase; font-weight: 500; display: block; margin-top: 3px; }
        .sb-nav { flex: 1; overflow-y: auto; padding: 16px 12px; position: relative; z-index: 2; scrollbar-width: none; }
        .sb-nav::-webkit-scrollbar { display: none; }
        .sb-section-label { font-size: .6rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: rgba(134,239,172,.5); padding: 0 8px; margin: 20px 0 8px; }
        .sb-section-label:first-child { margin-top: 0; }
        .sb-link { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: var(--radius-sm); text-decoration: none; color: rgba(255,255,255,.6); font-size: .83rem; font-weight: 500; transition: background .15s, color .15s; margin-bottom: 2px; }
        .sb-link:hover { background: rgba(255,255,255,.08); color: rgba(255,255,255,.9); }
        .sb-link.active { background: rgba(34,197,94,.18); color: var(--g300); }
        .sb-link svg { width: 16px; height: 16px; flex-shrink: 0; }
        .sb-link .badge { margin-left: auto; background: var(--red); color: white; font-size: .62rem; font-weight: 700; padding: 2px 6px; border-radius: 999px; }
        .sb-user { padding: 14px 16px; border-top: 1px solid rgba(255,255,255,.08); display: flex; align-items: center; gap: 11px; position: relative; z-index: 2; }
        .sb-avatar { width: 34px; height: 34px; border-radius: 50%; background: var(--g600); display: grid; place-items: center; font-size: .8rem; font-weight: 700; color: white; flex-shrink: 0; }
        .sb-user-name { font-size: .8rem; font-weight: 600; color: white; line-height: 1.2; }
        .sb-user-role { font-size: .68rem; color: var(--g300); }
        .sb-logout { margin-left: auto; background: none; border: none; color: rgba(255,255,255,.35); cursor: pointer; padding: 4px; border-radius: 6px; transition: color .15s, background .15s; display: grid; place-items: center; }
        .sb-logout:hover { color: var(--red); background: rgba(239,68,68,.1); }
        .sb-logout svg { width: 15px; height: 15px; }

        .main { margin-left: var(--sidebar-w); height: 100vh; display: flex; flex-direction: column; overflow: hidden; }

        .topbar { height: var(--topbar-h); flex-shrink: 0; background: white; border-bottom: 1px solid var(--border); display: flex; align-items: center; padding: 0 28px; gap: 14px; }
        .topbar-breadcrumb { display: flex; align-items: center; gap: 8px; flex: 1; }
        .bc-home { font-size: .8rem; color: var(--text-3); text-decoration: none; }
        .bc-home:hover { color: var(--g600); }
        .bc-sep { color: var(--border); font-size: .9rem; }
        .bc-current { font-size: .8rem; font-weight: 700; color: var(--text-1); }
        .topbar-chip { display: flex; align-items: center; gap: 7px; background: var(--g50); border: 1px solid var(--g200); border-radius: 999px; padding: 6px 14px; font-size: .75rem; font-weight: 600; color: var(--g700); }
        .topbar-chip .dot { width: 6px; height: 6px; border-radius: 50%; background: var(--g500); }

        .content { flex: 1; overflow-y: auto; padding: 24px 28px 40px; scrollbar-width: thin; scrollbar-color: var(--g200) transparent; }
        .content::-webkit-scrollbar { width: 5px; }
        .content::-webkit-scrollbar-thumb { background: var(--g200); border-radius: 99px; }

        .page-header {
            display: flex; align-items: flex-start; justify-content: space-between;
            margin-bottom: 24px;
        }
        .page-eyebrow { font-size: .68rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: var(--g600); margin-bottom: 6px; }
        .page-title { font-family: 'DM Serif Display', serif; font-size: 1.75rem; color: var(--text-1); line-height: 1.15; }
        .page-title span { font-style: italic; color: var(--g700); }
        .page-sub { font-size: .82rem; color: var(--text-3); margin-top: 4px; }
        .page-header-actions { display: flex; gap: 10px; align-items: center; }

        .btn {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 10px 18px; border-radius: var(--radius-sm);
            font-family: 'DM Sans', sans-serif; font-size: .82rem; font-weight: 600;
            cursor: pointer; border: none; transition: all .18s; text-decoration: none;
        }
        .btn svg { width: 15px; height: 15px; flex-shrink: 0; }
        .btn-primary { background: var(--g700); color: white; box-shadow: 0 3px 14px rgba(22,101,52,.3); }
        .btn-primary:hover { background: var(--g800); transform: translateY(-1px); box-shadow: 0 5px 20px rgba(22,101,52,.4); }
        .btn-ghost { background: white; color: var(--text-2); border: 1.5px solid var(--border); }
        .btn-ghost:hover { border-color: var(--g300); color: var(--g700); background: var(--g50); }

        .mini-stats {
            display: flex; gap: 12px; margin-bottom: 18px;
            flex-wrap: wrap;
        }
        .mini-stat {
            background: white; border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 12px 18px;
            display: flex; align-items: center; gap: 10px; flex: 1; min-width: 220px;
            box-shadow: var(--shadow-card);
        }
        .mini-stat-icon { width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; flex-shrink: 0; }
        .mini-stat-icon svg { width: 15px; height: 15px; }
        .mini-stat-val { font-family: 'DM Serif Display', serif; font-size: 1.4rem; color: var(--text-1); line-height: 1; }
        .mini-stat-label { font-size: .69rem; color: var(--text-3); font-weight: 500; margin-top: 1px; }

        .filter-bar {
            display: flex; gap: 10px; margin-bottom: 18px;
            flex-wrap: wrap;
        }
        .search-wrap { position: relative; flex: 1; min-width: 240px; }
        .search-wrap svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: var(--text-3); pointer-events: none; }
        .search-input {
            width: 100%; padding: 10px 14px 10px 40px;
            border: 1.5px solid var(--border); border-radius: var(--radius-sm);
            background: white; font-family: 'DM Sans', sans-serif;
            font-size: .83rem; color: var(--text-1); outline: none;
        }
        .filter-select {
            padding: 10px 36px 10px 14px; border: 1.5px solid var(--border);
            border-radius: var(--radius-sm); background: white;
            font-family: 'DM Sans', sans-serif; font-size: .83rem;
            color: var(--text-1); outline: none; cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%237a9e87' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 12px center;
            min-width: 180px;
        }

        .chart-grid {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }
        .chart-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow-card);
            overflow: hidden;
        }
        .chart-head {
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
            font-size: .78rem;
            font-weight: 700;
            color: var(--text-2);
        }
        .chart-body { padding: 10px; }
        .bars {
            height: 170px;
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 6px;
            align-items: end;
        }
        .bar {
            background: #2f6a32;
            border-radius: 6px 6px 0 0;
        }
        .line {
            width: 100%;
            height: 170px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: linear-gradient(#f7faf9 1px, transparent 1px), linear-gradient(90deg, #f7faf9 1px, transparent 1px);
            background-size: 30px 30px;
        }

        .table-card {
            background: white; border: 1px solid var(--border);
            border-radius: var(--radius); box-shadow: var(--shadow-card);
            overflow: hidden;
        }
        .table-head-bar {
            padding: 16px 20px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
        }
        .table-head-label { font-size: .78rem; font-weight: 700; color: var(--text-2); }
        .table-count { font-size: .72rem; color: var(--text-3); background: var(--g50); border: 1px solid var(--g200); padding: 3px 10px; border-radius: 999px; font-weight: 600; }

        table { width: 100%; border-collapse: collapse; }
        thead th {
            padding: 11px 16px; text-align: left;
            font-size: .68rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
            color: var(--text-3); background: var(--cream);
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        tbody tr { border-bottom: 1px solid var(--border); transition: background .12s; }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: var(--g50); }

        td { padding: 13px 16px; font-size: .79rem; color: var(--text-1); vertical-align: middle; }

        .badge-pill {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 9px; border-radius: 999px; font-size: .68rem; font-weight: 700;
        }
        .badge-pill .dot { width: 5px; height: 5px; border-radius: 50%; }
        .bp-green  { background: var(--g100);  color: var(--g700); }
        .bp-amber  { background: #fef3c7; color: #92400e; }

        .foot {
            padding: 10px 16px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            font-size: .72rem;
            color: var(--text-3);
        }

        @media (max-width: 1100px) {
            .chart-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 780px) {
            :root { --sidebar-w: 0px; }
            .sidebar { display: none; }
            .mini-stat { min-width: 100%; }
        }
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="sb-grid"></div>
    <div class="sb-logo">
        <div class="sb-logo-inner">
            <div class="sb-logo-icon">
                <svg viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
            </div>
            <div>
                <div class="sb-logo-name">LUSOG</div>
                <span class="sb-logo-sub">Clinic Management</span>
            </div>
        </div>
    </div>
    <nav class="sb-nav">
        <div class="sb-section-label">Main</div>
        <a href="{{ route('dashboard.school-nurse') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Dashboard
        </a>
        <a href="{{ route('dashboard.student-health-records') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Health Records
            <span class="badge">3</span>
        </a>
        <a href="{{ route('dashboard.consultation-log') }}" class="sb-link active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4"/><path d="M21 12c0 4.97-4.03 9-9 9S3 16.97 3 12 7.03 3 12 3s9 4.03 9 9z"/></svg>
            Consultation Log
        </a>
        <div class="sb-section-label">Health Programs</div>
        <a href="#" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
            Feeding Program
        </a>
        <a href="#" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 3-7-3"/><path d="M3 9v6l7 3 7-3V9"/><polyline points="3 9 12 6 21 9"/></svg>
            Deworming Program
        </a>
        <div class="sb-section-label">Inventory</div>
        <a href="#" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="2" width="18" height="20" rx="2"/><path d="M9 2v4h6V2"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
            Medicine Inventory
        </a>
        <a href="#" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Dispensing Log
        </a>
        <div class="sb-section-label">Reports</div>
        <a href="#" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            Data Visualization
        </a>
        <a href="#" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Generate Reports
        </a>
    </nav>
    <div class="sb-user">
        <div class="sb-avatar">{{ substr(auth()->user()->name ?? 'SN', 0, 2) }}</div>
        <div>
            <div class="sb-user-name">{{ auth()->user()->name ?? 'School Nurse' }}</div>
            <div class="sb-user-role">School Nurse - DCNHS</div>
        </div>
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
            <span class="bc-current">Consultation Log</span>
        </div>
        <div class="topbar-chip"><div class="dot"></div>DCNHS - SY 2025-2026</div>
    </header>

    <div class="content">
        <div class="page-header">
            <div>
                <div class="page-eyebrow">Consultation Management</div>
                <h1 class="page-title">School Clinic <span>Consultation Log</span></h1>
                <p class="page-sub">Manage and track all student clinic visits, conditions, and treatment records.</p>
            </div>
            <div class="page-header-actions">
                <a href="{{ route('dashboard.school-nurse') }}" class="btn btn-ghost">Dashboard</a>
                <a href="{{ route('dashboard.student-health-records') }}" class="btn btn-ghost">Health Records</a>
                <button class="btn btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    New Consultation
                </button>
            </div>
        </div>

        <div class="mini-stats">
            <div class="mini-stat">
                <div class="mini-stat-icon" style="background:var(--g100);color:var(--g700)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                </div>
                <div>
                    <div class="mini-stat-val">1,247</div>
                    <div class="mini-stat-label">Total Consultations (SY)</div>
                </div>
            </div>
            <div class="mini-stat">
                <div class="mini-stat-icon" style="background:#dbeafe;color:#1d4ed8">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
                <div>
                    <div class="mini-stat-val">215</div>
                    <div class="mini-stat-label">This Month</div>
                </div>
            </div>
            <div class="mini-stat">
                <div class="mini-stat-icon" style="background:#fef3c7;color:#92400e">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <div>
                    <div class="mini-stat-val">48</div>
                    <div class="mini-stat-label">This Week</div>
                </div>
            </div>
            <div class="mini-stat">
                <div class="mini-stat-icon" style="background:#fee2e2;color:var(--red)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/></svg>
                </div>
                <div>
                    <div class="mini-stat-val">23</div>
                    <div class="mini-stat-label">Referral Cases</div>
                </div>
            </div>
        </div>

        <div class="filter-bar">
            <div class="search-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" class="search-input" placeholder="Search by student name, LRN, or grade...">
            </div>
            <select class="filter-select"><option>All Dates</option></select>
            <select class="filter-select"><option>All Conditions</option></select>
            <select class="filter-select"><option>All Grades</option></select>
        </div>

        <div class="chart-grid">
            <div class="chart-card">
                <div class="chart-head">Most Common Conditions (This Month)</div>
                <div class="chart-body">
                    <div class="bars">
                        <div class="bar" style="height:94%"></div>
                        <div class="bar" style="height:86%"></div>
                        <div class="bar" style="height:68%"></div>
                        <div class="bar" style="height:54%"></div>
                        <div class="bar" style="height:48%"></div>
                        <div class="bar" style="height:36%"></div>
                    </div>
                </div>
            </div>
            <div class="chart-card">
                <div class="chart-head">Daily Consultation Trend (This Week)</div>
                <div class="chart-body">
                    <svg class="line" viewBox="0 0 500 220" preserveAspectRatio="none">
                        <polyline points="10,90 90,130 170,80 250,120 330,50 410,180 490,210" fill="none" stroke="#daa33f" stroke-width="4" />
                    </svg>
                </div>
            </div>
        </div>

        <div class="table-card">
            <div class="table-head-bar">
                <span class="table-head-label">Consultation Entries</span>
                <span class="table-count">Showing 1-5 of 5 records</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Date and Time</th>
                        <th>Student Name</th>
                        <th>Grade and Section</th>
                        <th>Condition</th>
                        <th>Treatment Given</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>2026-03-29 09:15</td>
                        <td>Dela Cruz, Juan</td>
                        <td>Grade 10 - Rizal</td>
                        <td>Fever, Cough</td>
                        <td>Paracetamol 500mg, advised rest</td>
                        <td><span class="badge-pill bp-green"><span class="dot" style="background:var(--g500)"></span>Treated</span></td>
                        <td>View - Edit</td>
                    </tr>
                    <tr>
                        <td>2026-03-28 08:30</td>
                        <td>Santos, Maria</td>
                        <td>Grade 8 - Mabini</td>
                        <td>Headache</td>
                        <td>Mefenamic Acid 250mg</td>
                        <td><span class="badge-pill bp-green"><span class="dot" style="background:var(--g500)"></span>Treated</span></td>
                        <td>View - Edit</td>
                    </tr>
                    <tr>
                        <td>2026-03-28 09:00</td>
                        <td>Rizal, Jose</td>
                        <td>Grade 12 - Dahlia</td>
                        <td>Abrasion</td>
                        <td>Cleaned wound, applied bandage</td>
                        <td><span class="badge-pill bp-green"><span class="dot" style="background:var(--g500)"></span>Treated</span></td>
                        <td>View - Edit</td>
                    </tr>
                    <tr>
                        <td>2026-03-27 14:00</td>
                        <td>Gonzales, Ana</td>
                        <td>Grade 7 - Aquino</td>
                        <td>Abdominal Pain</td>
                        <td>Antacid, advised to eat properly</td>
                        <td><span class="badge-pill bp-green"><span class="dot" style="background:var(--g500)"></span>Treated</span></td>
                        <td>View - Edit</td>
                    </tr>
                    <tr>
                        <td>2026-03-27 10:45</td>
                        <td>Mendoza, Carlo</td>
                        <td>Grade 9 - Bonifacio</td>
                        <td>Skin Allergy</td>
                        <td>Antihistamine, Calamine lotion</td>
                        <td><span class="badge-pill bp-amber"><span class="dot" style="background:var(--amber)"></span>Referred</span></td>
                        <td>View - Edit</td>
                    </tr>
                </tbody>
            </table>
            <div class="foot">
                <span>Showing 1-5 of 5 records</span>
                <span>Page 1 of 1</span>
            </div>
        </div>
    </div>
</div>
</body>
</html>
