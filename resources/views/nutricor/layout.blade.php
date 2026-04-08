<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Nutritional Coordinator')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --g900: #14532d;
            --g800: #166534;
            --g700: #15803d;
            --g600: #16a34a;
            --g300: #86efac;
            --cream: #f7f8f5;
            --card: #ffffff;
            --border: #e4ece7;
            --text-1: #102217;
            --text-2: #385746;
            --text-3: #6f8f7d;
            --sidebar-w: 248px;
            --sidebar-collapsed-w: 76px;
            --topbar-h: 64px;
            --radius: 12px;
            --shadow-card: 0 1px 4px rgba(5,46,22,.06), 0 4px 16px rgba(5,46,22,.06);
            --focus-ring: rgba(34, 197, 94, 0.22);
        }

        html, body {
            height: 100%;
            overflow: hidden;
            background: var(--cream);
            color: var(--text-1);
            font-family: 'DM Sans', sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            width: var(--sidebar-collapsed-w);
            background: var(--g900);
            display: flex;
            flex-direction: column;
            z-index: 10;
            overflow: hidden;
            transition: width .24s ease;
        }
        .sidebar:hover { width: var(--sidebar-w); }
        .sidebar::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse 120% 40% at 50% 100%, rgba(34,197,94,.16) 0%, transparent 70%),
                        radial-gradient(ellipse 80% 30% at 80% 0%, rgba(74,222,128,.1) 0%, transparent 60%);
            pointer-events: none;
        }
        .sb-grid {
            position: absolute;
            inset: 0;
            background-image: linear-gradient(rgba(134,239,172,.05) 1px, transparent 1px),
                              linear-gradient(90deg, rgba(134,239,172,.05) 1px, transparent 1px);
            background-size: 28px 28px;
        }
        .sb-logo {
            padding: 20px 20px 18px;
            border-bottom: 1px solid rgba(255,255,255,.08);
            display: flex;
            justify-content: center;
            position: relative;
            z-index: 2;
            transition: padding .24s ease;
        }
        .sb-logo img { width: 176px; max-width: 100%; transition: width .24s ease; }
        .sidebar:not(:hover) .sb-logo { padding: 14px 10px; }
        .sidebar:not(:hover) .sb-logo img { width: 48px; }

        .sb-nav {
            flex: 1;
            overflow-y: auto;
            padding: 14px 12px;
            position: relative;
            z-index: 2;
        }
        .sidebar:not(:hover) .sb-nav { padding: 12px 8px; }
        .sb-section-label {
            font-size: .6rem;
            font-weight: 700;
            letter-spacing: .14em;
            text-transform: uppercase;
            color: rgba(134,239,172,.52);
            margin: 8px 0;
            padding: 0 8px;
            max-height: 20px;
            transition: max-height .24s ease, opacity .18s ease, transform .24s ease, margin .24s ease;
        }
        .sidebar:not(:hover) .sb-section-label {
            max-height: 0;
            opacity: 0;
            transform: translateX(-6px);
            margin: 0;
        }
        .sb-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 10px;
            color: rgba(255,255,255,.67);
            text-decoration: none;
            font-size: .82rem;
            font-weight: 500;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            transition: background .15s, color .15s, padding .24s ease, gap .24s ease, font-size .24s ease;
        }
        .sb-link:hover { background: rgba(255,255,255,.08); color: rgba(255,255,255,.95); }
        .sb-link.active { background: rgba(34,197,94,.18); color: var(--g300); }
        .sb-link i { width: 16px; text-align: center; }
        .sidebar:not(:hover) .sb-link {
            justify-content: center;
            font-size: 0;
            padding: 10px;
            gap: 0;
        }
        .sidebar:not(:hover) .sb-link i { font-size: 16px; }

        .sb-user {
            padding: 14px 16px;
            border-top: 1px solid rgba(255,255,255,.08);
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
            z-index: 2;
        }
        .sb-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: var(--g600);
            color: #fff;
            display: grid;
            place-items: center;
            font-weight: 700;
            font-size: .8rem;
            flex-shrink: 0;
        }
        .sb-user-meta { transition: max-width .24s ease, opacity .18s ease, transform .24s ease; max-width: 180px; }
        .sb-user-name { color: #fff; font-size: .78rem; font-weight: 600; }
        .sb-user-role { color: var(--g300); font-size: .66rem; }
        .sb-logout {
            margin-left: auto;
            border: none;
            background: none;
            color: rgba(255,255,255,.4);
            cursor: pointer;
            width: 28px;
            height: 28px;
            border-radius: 6px;
        }
        .sb-logout:hover { color: #ef4444; background: rgba(239,68,68,.12); }
        .sidebar:not(:hover) .sb-user { justify-content: center; padding: 10px; }
        .sidebar:not(:hover) .sb-user-meta { max-width: 0; opacity: 0; transform: translateX(-6px); }
        .sidebar:not(:hover) .sb-user form { display: none; }

        .main {
            margin-left: var(--sidebar-collapsed-w);
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transition: margin-left .24s ease;
        }
        .sidebar:hover ~ .main { margin-left: var(--sidebar-w); }

        .topbar {
            height: var(--topbar-h);
            border-bottom: 1px solid var(--border);
            background: rgba(255,255,255,.92);
            backdrop-filter: blur(6px);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 22px;
            position: sticky;
            top: 0;
            z-index: 5;
        }
        .topbar-bc { color: var(--text-3); font-size: .76rem; display: flex; gap: 6px; }
        .topbar-chip {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 5px 11px;
            border-radius: 999px;
            font-size: .72rem;
            background: #f0fdf4;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }
        .dot { width: 6px; height: 6px; border-radius: 50%; background: #22c55e; }

        .content { overflow: auto; padding: 18px; }
        .page-header { margin-bottom: 14px; }
        .page-eyebrow {
            font-size: .68rem;
            font-weight: 700;
            letter-spacing: .14em;
            text-transform: uppercase;
            color: #15803d;
            margin-bottom: 6px;
        }
        .page-title {
            font-family: 'DM Serif Display', serif;
            font-size: 1.72rem;
            line-height: 1.15;
            color: var(--text-1);
        }
        .page-title span { color: #15803d; font-style: italic; }
        .page-sub { margin-top: 6px; color: var(--text-3); font-size: .8rem; }

        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 12px; }
        .grid-4 { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 12px; }
        .grid-5 { display: grid; grid-template-columns: repeat(5, minmax(0,1fr)); gap: 12px; }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-card);
        }
        .card-head {
            padding: 12px 14px;
            border-bottom: 1px solid #edf2ef;
            font-size: .86rem;
            font-weight: 700;
            color: var(--text-2);
        }
        .card-body { padding: 14px; }

        .stat { padding: 12px 14px; border-left: 4px solid var(--g700); }
        .stat .label { font-size: .68rem; color: var(--text-3); }
        .stat .num { margin-top: 7px; font-family: 'DM Serif Display', serif; font-size: 1.46rem; }
        .stat .hint { margin-top: 6px; color: var(--text-3); font-size: .66rem; }

        .summary {
            margin-top: 12px;
            margin-bottom: 12px;
            padding: 16px;
            border-radius: 12px;
            color: #fff;
            background: linear-gradient(120deg, var(--g800), #1b5c4f);
        }
        .summary h3 { font-size: 1rem; margin-bottom: 4px; }
        .summary p { color: #d5f2df; font-size: .74rem; }

        .muted { color: var(--text-3); font-size: .76rem; }

        .search {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 12px;
            font-size: .78rem;
            color: var(--text-2);
            background: #fff;
        }
        .search:focus {
            outline: none;
            border-color: #6ee7b7;
            box-shadow: 0 0 0 3px var(--focus-ring);
        }

        .table-wrap { overflow: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; border-bottom: 1px solid #edf2f1; font-size: .75rem; text-align: left; }
        th { background: #f9fbfa; color: #6d8a7a; font-weight: 700; }
        tbody tr:hover td { background: #f6fbf8; }

        .pill {
            display: inline-block;
            border-radius: 999px;
            padding: 4px 8px;
            font-size: .64rem;
            font-weight: 700;
        }
        .ok { background: #dcfce7; color: #166534; }
        .warn { background: #fef3c7; color: #92400e; }
        .bad { background: #fee2e2; color: #991b1b; }

        .btn {
            border: 1px solid transparent;
            background: var(--g700);
            color: #fff;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: .74rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .btn:hover { background: var(--g800); }

        .report-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

        @media (max-width: 1120px) {
            .grid-5 { grid-template-columns: repeat(2, minmax(0,1fr)); }
            .grid-4 { grid-template-columns: repeat(2, minmax(0,1fr)); }
            .grid-3 { grid-template-columns: 1fr; }
            .grid-2, .report-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 900px) {
            .sidebar { display: none; }
            .main { margin-left: 0; }
            .topbar { padding: 0 12px; }
            .content { padding: 12px; }
        }
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="sb-grid"></div>
    <div class="sb-logo">
        <img src="{{ asset('images/lusog-logo.png') }}" alt="LUSOG Logo">
    </div>
    <nav class="sb-nav">
        <div class="sb-section-label">Main</div>
        <a href="{{ route('dashboard.nutricor-dashboard') }}" class="sb-link {{ request()->routeIs('dashboard.nutricor-dashboard') ? 'active' : '' }}"><i class="fas fa-chart-line"></i>Dashboard</a>
        <a href="{{ route('dashboard.nutricor-beneficiaries') }}" class="sb-link {{ request()->routeIs('dashboard.nutricor-beneficiaries') ? 'active' : '' }}"><i class="fas fa-users"></i>Beneficiaries</a>
        <a href="{{ route('dashboard.nutricor-analytics') }}" class="sb-link {{ request()->routeIs('dashboard.nutricor-analytics') ? 'active' : '' }}"><i class="fas fa-chart-bar"></i>Analytics</a>
        <a href="{{ route('dashboard.nutricor-atrisk') }}" class="sb-link {{ request()->routeIs('dashboard.nutricor-atrisk') ? 'active' : '' }}"><i class="fas fa-exclamation-triangle"></i>At-Risk Learners</a>
        <a href="{{ route('dashboard.nutricor-reports') }}" class="sb-link {{ request()->routeIs('dashboard.nutricor-reports') ? 'active' : '' }}"><i class="fas fa-file-alt"></i>Reports</a>
        <a href="{{ route('dashboard.nutricor-comparison') }}" class="sb-link {{ request()->routeIs('dashboard.nutricor-comparison') ? 'active' : '' }}"><i class="fas fa-code-branch"></i>Baseline/Endline</a>
    </nav>
    <div class="sb-user">
        @php
            $displayName = trim(auth()->user()->name ?? 'Nutritional Coordinator');
            $initials = collect(preg_split('/\s+/', $displayName))
                ->filter()
                ->map(fn ($part) => strtoupper(substr($part, 0, 1)))
                ->take(2)
                ->implode('');
        @endphp
        <div class="sb-avatar">{{ $initials ?: 'NC' }}</div>
        <div class="sb-user-meta">
            <div class="sb-user-name">{{ auth()->user()->name ?? 'Nutritional Coordinator' }}</div>
            <div class="sb-user-role">SBFP Manager</div>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="sb-logout" title="Sign out"><i class="fas fa-sign-out-alt"></i></button>
        </form>
    </div>
</aside>

<div class="main">
    <header class="topbar">
        <div class="topbar-bc"><span>Nutritional Coordinator</span><span>&gt;</span><span>@yield('crumb', 'Dashboard')</span></div>
        <div class="topbar-chip"><span class="dot"></span>{{ now()->format('M d, Y') }}</div>
    </header>

    <div class="content">
        <div class="page-header">
            <div class="page-eyebrow">SBFP Monitoring</div>
            <h1 class="page-title">@yield('page_title')</h1>
            <p class="page-sub">@yield('page_subtitle')</p>
        </div>
        @yield('content')
    </div>
</div>
</body>
</html>
