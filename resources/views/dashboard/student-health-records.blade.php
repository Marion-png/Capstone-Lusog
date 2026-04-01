<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Health Records — LUSOG</title>
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
            --shadow-xl: 0 20px 60px rgba(5,46,22,.18);
            --radius: 16px;
            --radius-sm: 10px;
        }

        html, body { height: 100%; font-family: 'DM Sans', sans-serif; background: var(--cream); color: var(--text-1); overflow: hidden; }

        /* ══════════════════════════════
           SIDEBAR  (shared component)
        ══════════════════════════════ */
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

        /* ══════════════════════════════
           MAIN LAYOUT
        ══════════════════════════════ */
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

        /* ══════════════════════════════
           PAGE HEADER
        ══════════════════════════════ */
        .page-header {
            display: flex; align-items: flex-start; justify-content: space-between;
            margin-bottom: 24px; animation: fadeUp .45s ease both;
        }
        .page-header-left {}
        .page-eyebrow { font-size: .68rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: var(--g600); margin-bottom: 6px; }
        .page-title { font-family: 'DM Serif Display', serif; font-size: 1.75rem; color: var(--text-1); line-height: 1.15; }
        .page-title span { font-style: italic; color: var(--g700); }
        .page-sub { font-size: .82rem; color: var(--text-3); margin-top: 4px; }
        .page-header-actions { display: flex; gap: 10px; align-items: center; }

        /* ══════════════════════════════
           BUTTONS
        ══════════════════════════════ */
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
        .btn-danger { background: #fee2e2; color: var(--red); border: 1.5px solid #fecaca; }
        .btn-danger:hover { background: #fecaca; }
        .btn-sm { padding: 6px 12px; font-size: .75rem; }

        /* ══════════════════════════════
           FILTER BAR
        ══════════════════════════════ */
        .filter-bar {
            display: flex; gap: 10px; margin-bottom: 18px;
            animation: fadeUp .5s ease both; animation-delay: .06s;
            flex-wrap: wrap;
        }
        .search-wrap { position: relative; flex: 1; min-width: 220px; }
        .search-wrap svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: var(--text-3); pointer-events: none; }
        .search-input {
            width: 100%; padding: 10px 14px 10px 40px;
            border: 1.5px solid var(--border); border-radius: var(--radius-sm);
            background: white; font-family: 'DM Sans', sans-serif;
            font-size: .83rem; color: var(--text-1); outline: none;
            transition: border-color .18s, box-shadow .18s;
        }
        .search-input::placeholder { color: var(--text-3); }
        .search-input:focus { border-color: var(--g400); box-shadow: 0 0 0 3px rgba(34,197,94,.1); }

        .filter-select {
            padding: 10px 36px 10px 14px; border: 1.5px solid var(--border);
            border-radius: var(--radius-sm); background: white;
            font-family: 'DM Sans', sans-serif; font-size: .83rem;
            color: var(--text-1); outline: none; cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%237a9e87' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 12px center;
            transition: border-color .18s;
        }
        .filter-select:focus { border-color: var(--g400); outline: none; }
        .filter-select:disabled { background: #f5f7f6; color: var(--text-3); cursor: not-allowed; }

        .filter-tabs { display: flex; gap: 0; background: white; border: 1.5px solid var(--border); border-radius: var(--radius-sm); overflow: hidden; }
        .filter-tab { padding: 9px 16px; font-size: .78rem; font-weight: 600; color: var(--text-3); cursor: pointer; border: none; background: none; transition: background .15s, color .15s; white-space: nowrap; }
        .filter-tab.active { background: var(--g700); color: white; }
        .filter-tab:not(.active):hover { background: var(--g50); color: var(--g700); }

        /* ══════════════════════════════
           STATS MINI ROW
        ══════════════════════════════ */
        .mini-stats {
            display: flex; gap: 12px; margin-bottom: 18px;
            animation: fadeUp .5s ease both; animation-delay: .1s;
        }
        .mini-stat {
            background: white; border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 12px 18px;
            display: flex; align-items: center; gap: 10px; flex: 1;
            box-shadow: var(--shadow-card);
        }
        .mini-stat-icon { width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; flex-shrink: 0; }
        .mini-stat-icon svg { width: 15px; height: 15px; }
        .mini-stat-val { font-family: 'DM Serif Display', serif; font-size: 1.4rem; color: var(--text-1); line-height: 1; }
        .mini-stat-label { font-size: .69rem; color: var(--text-3); font-weight: 500; margin-top: 1px; }

        /* ══════════════════════════════
           RECORDS TABLE
        ══════════════════════════════ */
        .table-card {
            background: white; border: 1px solid var(--border);
            border-radius: var(--radius); box-shadow: var(--shadow-card);
            overflow: hidden;
            animation: fadeUp .55s ease both; animation-delay: .14s;
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
            white-space: nowrap; cursor: pointer; user-select: none;
        }
        thead th:hover { color: var(--g700); }
        thead th .sort-icon { display: inline-block; margin-left: 4px; opacity: .4; font-size: .7rem; }
        thead th.sorted .sort-icon { opacity: 1; color: var(--g600); }

        tbody tr { border-bottom: 1px solid var(--border); transition: background .12s; cursor: pointer; }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: var(--g50); }

        td { padding: 13px 16px; font-size: .81rem; color: var(--text-1); vertical-align: middle; }

        .td-patient { display: flex; align-items: center; gap: 10px; }
        .td-avatar {
            width: 32px; height: 32px; border-radius: 50%;
            background: var(--g100); display: grid; place-items: center;
            font-size: .7rem; font-weight: 700; color: var(--g700); flex-shrink: 0;
        }
        .td-name { font-weight: 600; color: var(--text-1); line-height: 1.2; }
        .td-id { font-size: .67rem; color: var(--text-3); }

        .badge-pill {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 9px; border-radius: 999px; font-size: .68rem; font-weight: 700;
        }
        .badge-pill .dot { width: 5px; height: 5px; border-radius: 50%; }
        .bp-green  { background: var(--g100);  color: var(--g700); }
        .bp-amber  { background: #fef3c7;       color: #92400e; }
        .bp-red    { background: #fee2e2;       color: var(--red); }
        .bp-blue   { background: #dbeafe;       color: #1d4ed8; }
        .bp-gray   { background: #f3f4f6;       color: #4b5563; }

        .bmi-chip { font-size: .72rem; font-weight: 600; }

        .td-actions { display: flex; gap: 6px; justify-content: flex-end; }
        .action-btn {
            width: 30px; height: 30px; border-radius: 7px;
            background: none; border: 1.5px solid var(--border);
            display: grid; place-items: center; cursor: pointer;
            color: var(--text-3); transition: all .15s;
        }
        .action-btn:hover { border-color: var(--g300); color: var(--g600); background: var(--g50); }
        .action-btn.del:hover { border-color: #fecaca; color: var(--red); background: #fee2e2; }
        .action-btn svg { width: 13px; height: 13px; }

        /* Pagination */
        .pagination {
            padding: 14px 20px; border-top: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
        }
        .pg-info { font-size: .75rem; color: var(--text-3); }
        .pg-btns { display: flex; gap: 4px; }
        .pg-btn {
            width: 30px; height: 30px; border-radius: 7px;
            display: grid; place-items: center; cursor: pointer;
            border: 1.5px solid var(--border); background: white;
            font-size: .78rem; font-weight: 600; color: var(--text-2);
            transition: all .15s;
        }
        .pg-btn:hover:not(:disabled) { border-color: var(--g300); color: var(--g700); background: var(--g50); }
        .pg-btn.active { background: var(--g700); color: white; border-color: var(--g700); }
        .pg-btn:disabled { opacity: .35; cursor: not-allowed; }
        .pg-btn svg { width: 12px; height: 12px; }

        /* ══════════════════════════════
           ADD / EDIT RECORD MODAL
        ══════════════════════════════ */
        .modal-backdrop {
            position: fixed; inset: 0; background: rgba(5,46,22,.45);
            backdrop-filter: blur(4px); z-index: 200;
            display: none; align-items: center; justify-content: center;
            animation: fadeIn .2s ease;
        }
        .modal-backdrop.open { display: flex; }

        .modal {
            background: white; border-radius: 20px;
            width: 100%; max-width: 640px; max-height: 92vh;
            display: flex; flex-direction: column;
            box-shadow: var(--shadow-xl);
            animation: slideUp .28s cubic-bezier(.34,1.3,.64,1);
        }
        .modal-header {
            padding: 24px 28px 0;
            display: flex; align-items: flex-start; justify-content: space-between;
            border-bottom: 1px solid var(--border); padding-bottom: 18px; flex-shrink: 0;
        }
        .modal-title { font-family: 'DM Serif Display', serif; font-size: 1.3rem; color: var(--text-1); }
        .modal-sub { font-size: .78rem; color: var(--text-3); margin-top: 3px; }
        .modal-close {
            width: 32px; height: 32px; border-radius: 8px; border: 1.5px solid var(--border);
            background: none; cursor: pointer; color: var(--text-3);
            display: grid; place-items: center; transition: all .15s; flex-shrink: 0;
        }
        .modal-close:hover { background: #fee2e2; border-color: #fecaca; color: var(--red); }
        .modal-close svg { width: 14px; height: 14px; }

        .modal-body { padding: 22px 28px; overflow-y: auto; flex: 1; scrollbar-width: thin; }
        .modal-footer {
            padding: 16px 28px; border-top: 1px solid var(--border);
            display: flex; gap: 10px; justify-content: flex-end; flex-shrink: 0;
        }

        /* form grid inside modal */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-grid .span-2 { grid-column: span 2; }
        .form-section-label {
            font-size: .68rem; font-weight: 700; letter-spacing: .12em;
            text-transform: uppercase; color: var(--g600);
            padding-bottom: 10px; border-bottom: 1px solid var(--border);
            margin-bottom: 16px; margin-top: 8px; grid-column: span 2;
        }
        .form-section-label:first-child { margin-top: 0; }

        .field { display: flex; flex-direction: column; gap: 6px; }
        .field label { font-size: .72rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--text-2); }
        .field input, .field select, .field textarea {
            padding: 10px 14px; border: 1.5px solid var(--border);
            border-radius: var(--radius-sm); background: var(--cream);
            font-family: 'DM Sans', sans-serif; font-size: .85rem;
            color: var(--text-1); outline: none; transition: border-color .18s, box-shadow .18s;
        }
        .field input::placeholder, .field textarea::placeholder { color: var(--text-3); }
        .field input:focus, .field select:focus, .field textarea:focus {
            border-color: var(--g400); box-shadow: 0 0 0 3px rgba(34,197,94,.1);
            background: white;
        }
        .field textarea { resize: vertical; min-height: 80px; }
        .field select { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%237a9e87' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 36px; cursor: pointer; }

        .bmi-display {
            background: var(--g50); border: 1.5px solid var(--g200);
            border-radius: var(--radius-sm); padding: 10px 14px;
            display: flex; align-items: center; justify-content: space-between;
        }
        .bmi-display .bmi-val { font-family: 'DM Serif Display', serif; font-size: 1.3rem; color: var(--g700); }
        .bmi-display .bmi-class { font-size: .75rem; font-weight: 600; color: var(--g600); }

        /* ══════════════════════════════
           VIEW RECORD SLIDE-OVER
        ══════════════════════════════ */
        .slideover-backdrop {
            position: fixed; inset: 0; background: rgba(5,46,22,.35);
            backdrop-filter: blur(3px); z-index: 200;
            display: none;
        }
        .slideover-backdrop.open { display: block; }

        .slideover {
            position: fixed; top: 0; right: -560px; bottom: 0;
            width: 520px; background: white; z-index: 201;
            display: flex; flex-direction: column;
            box-shadow: -8px 0 40px rgba(5,46,22,.15);
            transition: right .3s cubic-bezier(.4,0,.2,1);
        }
        .slideover.open { right: 0; }

        .so-header {
            padding: 24px 24px 18px;
            border-bottom: 1px solid var(--border); flex-shrink: 0;
            display: flex; align-items: flex-start; gap: 14px;
        }
        .so-avatar {
            width: 48px; height: 48px; border-radius: 50%;
            background: var(--g100); display: grid; place-items: center;
            font-size: 1rem; font-weight: 700; color: var(--g700); flex-shrink: 0;
        }
        .so-name { font-family: 'DM Serif Display', serif; font-size: 1.2rem; color: var(--text-1); line-height: 1.2; }
        .so-meta { font-size: .75rem; color: var(--text-3); margin-top: 3px; }
        .so-close { margin-left: auto; width: 32px; height: 32px; border-radius: 8px; border: 1.5px solid var(--border); background: none; cursor: pointer; color: var(--text-3); display: grid; place-items: center; flex-shrink: 0; transition: all .15s; }
        .so-close:hover { background: #fee2e2; border-color: #fecaca; color: var(--red); }
        .so-close svg { width: 14px; height: 14px; }

        .so-body { flex: 1; overflow-y: auto; padding: 20px 24px; scrollbar-width: thin; scrollbar-color: var(--g200) transparent; }
        .so-footer { padding: 14px 24px; border-top: 1px solid var(--border); display: flex; gap: 8px; flex-shrink: 0; }

        .so-section { margin-bottom: 22px; }
        .so-section-label { font-size: .65rem; font-weight: 700; letter-spacing: .13em; text-transform: uppercase; color: var(--g600); margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid var(--border); }
        .so-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .so-field {}
        .so-field-label { font-size: .67rem; color: var(--text-3); font-weight: 600; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 3px; }
        .so-field-val { font-size: .83rem; color: var(--text-1); font-weight: 500; }

        .at-risk-banner {
            background: #fee2e2; border: 1.5px solid #fecaca;
            border-radius: var(--radius-sm); padding: 10px 14px;
            display: flex; align-items: center; gap: 10px; margin-bottom: 16px;
        }
        .at-risk-banner svg { width: 16px; height: 16px; color: var(--red); flex-shrink: 0; }
        .at-risk-banner-text { font-size: .78rem; color: #991b1b; font-weight: 600; }

        .visit-timeline { display: flex; flex-direction: column; gap: 0; }
        .visit-item {
            display: flex; gap: 14px; padding: 10px 0;
            border-bottom: 1px dashed var(--border);
        }
        .visit-item:last-child { border-bottom: none; }
        .visit-dot-col { display: flex; flex-direction: column; align-items: center; gap: 0; padding-top: 4px; }
        .visit-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--g400); flex-shrink: 0; }
        .visit-line { width: 1px; flex: 1; background: var(--g200); min-height: 16px; }
        .visit-date { font-size: .68rem; color: var(--text-3); font-weight: 600; white-space: nowrap; min-width: 72px; padding-top: 2px; }
        .visit-complaint { font-size: .78rem; color: var(--text-1); font-weight: 600; line-height: 1.3; }
        .visit-treatment { font-size: .72rem; color: var(--text-3); margin-top: 2px; }

        /* ══════════════════════════════
           ANIMATIONS
        ══════════════════════════════ */
        @keyframes fadeUp  { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
        @keyframes fadeIn  { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(24px) scale(.97); } to { opacity: 1; transform: none; } }

        /* responsive */
        @media (max-width: 900px) {
            .form-grid { grid-template-columns: 1fr; }
            .form-grid .span-2 { grid-column: span 1; }
            .so-grid { grid-template-columns: 1fr; }
            .mini-stats { flex-wrap: wrap; }
        }
        @media (max-width: 780px) {
            :root { --sidebar-w: 0px; }
            .sidebar { display: none; }
            .slideover { width: 100%; }
        }
    </style>
</head>
<body>

<!-- ═══════════ SIDEBAR ═══════════ -->
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
        <a href="{{ route('dashboard') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Dashboard
        </a>
        <a href="{{ route('health-records.index') }}" class="sb-link active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Health Records
            <span class="badge">3</span>
        </a>
        <a href="{{ route('dashboard.consultation-log') }}" class="sb-link">
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
            <div class="sb-user-role">School Nurse · DCNHS</div>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="sb-logout" title="Sign out">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </button>
        </form>
    </div>
</aside>

<!-- ═══════════ MAIN ═══════════ -->
<div class="main">

    <!-- TOPBAR -->
    <header class="topbar">
        <div class="topbar-breadcrumb">
            <a href="{{ route('dashboard') }}" class="bc-home">Dashboard</a>
            <span class="bc-sep">›</span>
            <span class="bc-current">Health Records</span>
        </div>
        <div class="topbar-chip"><div class="dot"></div>DCNHS · SY 2025–2026</div>
    </header>

    <!-- CONTENT -->
    <div class="content">

        <!-- Page header -->
        <div class="page-header">
            <div class="page-header-left">
                <div class="page-eyebrow">Health Records Management</div>
                <h1 class="page-title">Student &amp; Personnel <span>Health Records</span></h1>
                <p class="page-sub">Manage consultation logs, BMI profiles, and clinical histories for all learners and staff.</p>
            </div>
            <div class="page-header-actions">
                <button class="btn btn-ghost" onclick="exportRecords()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Export PDF
                </button>
            </div>
        </div>

        <!-- Mini stats -->
        <div class="mini-stats">
            <div class="mini-stat">
                <div class="mini-stat-icon" style="background:var(--g100);color:var(--g700)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                </div>
                <div>
                    <div class="mini-stat-val">2,841</div>
                    <div class="mini-stat-label">Total Records</div>
                </div>
            </div>
            <div class="mini-stat">
                <div class="mini-stat-icon" style="background:#dbeafe;color:#1d4ed8">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
                <div>
                    <div class="mini-stat-val">47</div>
                    <div class="mini-stat-label">Consultations Today</div>
                </div>
            </div>
            <div class="mini-stat">
                <div class="mini-stat-icon" style="background:#fee2e2;color:var(--red)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/></svg>
                </div>
                <div>
                    <div class="mini-stat-val">8</div>
                    <div class="mini-stat-label">At-Risk Flagged</div>
                </div>
            </div>
            <div class="mini-stat">
                <div class="mini-stat-icon" style="background:#fef3c7;color:#92400e">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <div>
                    <div class="mini-stat-val">3</div>
                    <div class="mini-stat-label">Pending Follow-ups</div>
                </div>
            </div>
        </div>

        <!-- Filter bar -->
        <div class="filter-bar">
            <div class="search-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" class="search-input" placeholder="Search by name, ID, complaint…" id="searchInput" oninput="filterTable()">
            </div>
            <select class="filter-select" id="gradeFilter" onchange="filterTable()">
                <option value="">All Grade Levels</option>
                <option>Grade 7</option><option>Grade 8</option><option>Grade 9</option>
                <option>Grade 10</option><option>Grade 11</option><option>Grade 12</option>
                <option>Personnel</option>
            </select>
            <select class="filter-select" id="typeFilter" onchange="syncPersonnelFilters(); filterTable()">
                <option value="">All Types</option>
                <option>Student</option><option>Personnel</option>
            </select>
            <div class="filter-tabs">
                <button class="filter-tab active" onclick="setTab(this,'all')">All</button>
                <button class="filter-tab" onclick="setTab(this,'at-risk')">At-Risk</button>
                <button class="filter-tab" onclick="setTab(this,'followup')">Follow-up</button>
            </div>
        </div>

        <!-- Records table -->
        <div class="table-card">
            <div class="table-head-bar">
                <span class="table-head-label">Consultation Records</span>
                <span class="table-count" id="recordCount">Showing 10 of 2,841</span>
            </div>

            <table id="recordsTable">
                <thead>
                    <tr>
                        <th onclick="sortTable(0)">Patient <span class="sort-icon">↕</span></th>
                        <th onclick="sortTable(1)">Grade / Dept <span class="sort-icon">↕</span></th>
                        <th onclick="sortTable(2)">Date <span class="sort-icon sorted">↓</span></th>
                        <th>Chief Complaint</th>
                        <th>Diagnosis</th>
                        <th>BMI</th>
                        <th>Status</th>
                        <th style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <!-- rows injected by JS below for demo -->
                </tbody>
            </table>

            <div class="pagination">
                <span class="pg-info">Page 1 of 285</span>
                <div class="pg-btns">
                    <button class="pg-btn" disabled>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
                    </button>
                    <button class="pg-btn active">1</button>
                    <button class="pg-btn">2</button>
                    <button class="pg-btn">3</button>
                    <span style="padding:0 4px;color:var(--text-3);font-size:.8rem;line-height:30px">…</span>
                    <button class="pg-btn">285</button>
                    <button class="pg-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                    </button>
                </div>
            </div>
        </div>

    </div><!-- /content -->
</div><!-- /main -->

<!-- ═══════════ ADD RECORD MODAL ═══════════ -->
<div class="modal-backdrop" id="modalBackdrop" onclick="closeModalOutside(event)">
    <div class="modal" id="modal">
        <div class="modal-header">
            <div>
                <div class="modal-title">New Consultation Record</div>
                <div class="modal-sub">Log a new clinic visit and health record entry.</div>
            </div>
            <button class="modal-close" onclick="closeModal()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <form id="recordForm" method="POST" action="{{ route('health-records.store') }}">
                @csrf
                <div class="form-grid">

                    <div class="form-section-label">Patient Information</div>

                    <div class="field span-2">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" placeholder="e.g. Juan Dela Cruz" required>
                    </div>
                    <div class="field">
                        <label>LRN / Employee ID</label>
                        <input type="text" name="lrn" placeholder="123456789012">
                    </div>
                    <div class="field">
                        <label>Patient Type *</label>
                        <select name="patient_type" required>
                            <option value="">Select type</option>
                            <option value="student">Student</option>
                            <option value="personnel">Personnel</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Grade Level / Department *</label>
                        <select name="grade_level" required>
                            <option value="">Select</option>
                            <option>Grade 7</option><option>Grade 8</option><option>Grade 9</option>
                            <option>Grade 10</option><option>Grade 11</option><option>Grade 12</option>
                            <option>Teaching Personnel</option><option>Non-Teaching Personnel</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Section</label>
                        <input type="text" name="section" placeholder="e.g. Rizal">
                    </div>
                    <div class="field">
                        <label>Sex *</label>
                        <select name="sex" required>
                            <option value="">Select</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Age</label>
                        <input type="number" name="age" placeholder="14" min="5" max="70" id="ageInput" oninput="computeBMI()">
                    </div>

                    <div class="form-section-label">BMI Computation</div>

                    <div class="field">
                        <label>Height (cm) *</label>
                        <input type="number" name="height_cm" placeholder="150" step="0.1" id="heightInput" oninput="computeBMI()">
                    </div>
                    <div class="field">
                        <label>Weight (kg) *</label>
                        <input type="number" name="weight_kg" placeholder="45" step="0.1" id="weightInput" oninput="computeBMI()">
                    </div>
                    <div class="field span-2">
                        <label>Computed BMI</label>
                        <div class="bmi-display">
                            <span class="bmi-val" id="bmiVal">—</span>
                            <span class="bmi-class" id="bmiClass">Enter height &amp; weight</span>
                        </div>
                        <input type="hidden" name="bmi" id="bmiHidden">
                        <input type="hidden" name="bmi_classification" id="bmiClassHidden">
                    </div>

                    <div class="form-section-label">Consultation Details</div>

                    <div class="field">
                        <label>Date of Consultation *</label>
                        <input type="date" name="consultation_date" id="consultDate" required>
                    </div>
                    <div class="field">
                        <label>Time</label>
                        <input type="time" name="consultation_time" id="consultTime">
                    </div>
                    <div class="field span-2">
                        <label>Chief Complaint *</label>
                        <input type="text" name="chief_complaint" placeholder="e.g. Headache, Fever, Stomach ache" required>
                    </div>
                    <div class="field span-2">
                        <label>Diagnosis / Assessment</label>
                        <input type="text" name="diagnosis" placeholder="e.g. Upper Respiratory Tract Infection">
                    </div>
                    <div class="field span-2">
                        <label>Treatment / Medication Administered</label>
                        <textarea name="treatment" placeholder="e.g. Paracetamol 500mg — 1 tablet, rest and observation"></textarea>
                    </div>
                    <div class="field span-2">
                        <label>Follow-up Notes</label>
                        <textarea name="followup_notes" placeholder="Any follow-up instructions or referrals…"></textarea>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary" onclick="submitRecord()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                Save Record
            </button>
        </div>
    </div>
</div>

<!-- ═══════════ VIEW RECORD SLIDE-OVER ═══════════ -->
<div class="slideover-backdrop" id="soBackdrop" onclick="closeSlideOver()"></div>
<div class="slideover" id="slideover">
    <div class="so-header">
        <div class="so-avatar" id="soAvatar">AJ</div>
        <div>
            <div class="so-name" id="soName">Andrei J. Santos</div>
            <div class="so-meta" id="soMeta">Grade 10 · Rizal Sec 3 · Student · LRN: 100234560012</div>
        </div>
        <button class="so-close" onclick="closeSlideOver()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <div class="so-body">
        <div class="at-risk-banner" id="soRiskBanner">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/></svg>
            <span class="at-risk-banner-text">⚑ At-Risk — 9 clinic visits in the past 2 weeks. Follow-up required.</span>
        </div>

        <div class="so-section">
            <div class="so-section-label">Patient Profile</div>
            <div class="so-grid">
                <div class="so-field"><div class="so-field-label">Age</div><div class="so-field-val" id="soAge">15 years old</div></div>
                <div class="so-field"><div class="so-field-label">Sex</div><div class="so-field-val" id="soSex">Male</div></div>
                <div class="so-field"><div class="so-field-label">BMI</div><div class="so-field-val" id="soBMI">17.4 — Wasted</div></div>
                <div class="so-field"><div class="so-field-label">Height / Weight</div><div class="so-field-val" id="soHW">158 cm / 43.5 kg</div></div>
            </div>
        </div>

        <div class="so-section">
            <div class="so-section-label">Latest Consultation</div>
            <div class="so-grid">
                <div class="so-field"><div class="so-field-label">Date</div><div class="so-field-val" id="soDate">April 1, 2026</div></div>
                <div class="so-field"><div class="so-field-label">Time</div><div class="so-field-val">8:42 AM</div></div>
                <div class="so-field"><div class="so-field-label">Chief Complaint</div><div class="so-field-val" id="soComplaint">Headache, dizziness</div></div>
                <div class="so-field"><div class="so-field-label">Diagnosis</div><div class="so-field-val" id="soDiagnosis">Tension headache</div></div>
                <div class="so-field so-grid-full" style="grid-column:span 2"><div class="so-field-label">Treatment</div><div class="so-field-val" id="soTreatment">Paracetamol 500mg — 1 tablet. Advised rest and hydration.</div></div>
                <div class="so-field" style="grid-column:span 2"><div class="so-field-label">Follow-up Notes</div><div class="so-field-val" id="soFollowup">Refer to guidance counselor if symptoms persist. Contact parents.</div></div>
            </div>
        </div>

        <div class="so-section">
            <div class="so-section-label">Recent Visit History</div>
            <div class="visit-timeline">
                <div class="visit-item">
                    <div class="visit-dot-col"><div class="visit-dot" style="background:var(--red)"></div><div class="visit-line"></div></div>
                    <div>
                        <div class="visit-date">Apr 1, 2026</div>
                        <div class="visit-complaint">Headache, dizziness</div>
                        <div class="visit-treatment">Paracetamol 500mg · Rest advised</div>
                    </div>
                </div>
                <div class="visit-item">
                    <div class="visit-dot-col"><div class="visit-dot"></div><div class="visit-line"></div></div>
                    <div>
                        <div class="visit-date">Mar 28, 2026</div>
                        <div class="visit-complaint">Stomach ache, nausea</div>
                        <div class="visit-treatment">Mefenamic acid · Oral rehydration</div>
                    </div>
                </div>
                <div class="visit-item">
                    <div class="visit-dot-col"><div class="visit-dot"></div><div class="visit-line"></div></div>
                    <div>
                        <div class="visit-date">Mar 25, 2026</div>
                        <div class="visit-complaint">Fever (38.2°C)</div>
                        <div class="visit-treatment">Paracetamol 500mg · Sent home</div>
                    </div>
                </div>
                <div class="visit-item">
                    <div class="visit-dot-col"><div class="visit-dot"></div></div>
                    <div>
                        <div class="visit-date">Mar 20, 2026</div>
                        <div class="visit-complaint">Cough, colds</div>
                        <div class="visit-treatment">Antihistamine · Rest advised</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="so-footer">
        <button class="btn btn-ghost btn-sm" onclick="closeSlideOver()">Close</button>
        <button class="btn btn-ghost btn-sm">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Print Record
        </button>
        <button class="btn btn-primary btn-sm" onclick="editFromSlideOver()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Edit Record
        </button>
    </div>
</div>

<!-- ═══════════ JS ═══════════ -->
<script>
/* ── Sample data ── */
const records = [
    { id:1, name:'Andrei J. Santos',   initials:'AJ', lrn:'100234560012', grade:'Grade 10', section:'Rizal Sec 3',   type:'Student',   date:'2026-04-01', complaint:'Headache, dizziness',     diagnosis:'Tension headache',       bmi:17.4, bmiClass:'Wasted',          status:'at-risk', age:15, sex:'Male',   hw:'158cm / 43.5kg', treatment:'Paracetamol 500mg', followup:'Refer to counselor' },
    { id:2, name:'Maria L. Dela Cruz', initials:'ML', lrn:'100234560034', grade:'Grade 8',  section:'Bonifacio Sec 1', type:'Student', date:'2026-04-01', complaint:'Fever (38.5°C)',           diagnosis:'Viral fever',            bmi:15.1, bmiClass:'Severely Wasted', status:'at-risk', age:13, sex:'Female', hw:'148cm / 33kg',   treatment:'Paracetamol, rest',  followup:'Contact parents' },
    { id:3, name:'Carlo R. Mendoza',   initials:'CM', lrn:'100234560078', grade:'Grade 9',  section:'Mabini Sec 2',   type:'Student',   date:'2026-04-01', complaint:'Wound — right knee',      diagnosis:'Laceration, minor',      bmi:20.1, bmiClass:'Normal',          status:'normal',  age:14, sex:'Male',   hw:'162cm / 52.5kg', treatment:'Betadine, bandage',  followup:'None' },
    { id:4, name:'Sofia A. Reyes',     initials:'SR', lrn:'100234560091', grade:'Grade 11', section:'STEM Sec A',     type:'Student',   date:'2026-03-31', complaint:'Dysmenorrhea',            diagnosis:'Primary dysmenorrhea',   bmi:21.4, bmiClass:'Normal',          status:'normal',  age:16, sex:'Female', hw:'155cm / 51.5kg', treatment:'Mefenamic acid',     followup:'None' },
    { id:5, name:'James P. Ocampo',    initials:'JO', lrn:'100234560102', grade:'Grade 12', section:'ABM Sec B',      type:'Student',   date:'2026-03-31', complaint:'Eye strain, headache',    diagnosis:'Asthenopia',             bmi:22.0, bmiClass:'Normal',          status:'followup',age:17, sex:'Male',   hw:'170cm / 63.5kg', treatment:'Rest, eye drops',    followup:'Advise eyeglass check' },
    { id:6, name:'Lorna G. Santos',    initials:'LS', lrn:'EMP-2019-041', grade:'Teaching', section:'English Dept',  type:'Personnel', date:'2026-03-30', complaint:'Hypertension monitoring', diagnosis:'Stage 1 hypertension',   bmi:27.8, bmiClass:'Overweight',       status:'followup',age:42, sex:'Female', hw:'160cm / 71kg',   treatment:'BP monitored 140/90', followup:'Refer to physician' },
    { id:7, name:'Renz M. Villanueva', initials:'RV', lrn:'100234560115', grade:'Grade 7',  section:'Luna Sec 4',    type:'Student',   date:'2026-03-29', complaint:'Cough, colds',            diagnosis:'Upper respiratory tract', bmi:16.2,bmiClass:'Wasted',         status:'normal',  age:12, sex:'Male',   hw:'145cm / 34kg',   treatment:'Antihistamine, rest', followup:'None' },
    { id:8, name:'Bianca T. Lim',      initials:'BL', lrn:'100234560122', grade:'Grade 10', section:'Aguinaldo Sec 2',type:'Student',  date:'2026-03-28', complaint:'Stomachache, vomiting',   diagnosis:'Gastroenteritis',        bmi:19.5, bmiClass:'Normal',          status:'normal',  age:15, sex:'Female', hw:'158cm / 48.8kg', treatment:'ORS, rest',          followup:'None' },
    { id:9, name:'Miguel A. Cruz',     initials:'MC', lrn:'100234560139', grade:'Grade 8',  section:'Rizal Sec 1',   type:'Student',   date:'2026-03-27', complaint:'Sprained ankle',          diagnosis:'Grade I ankle sprain',   bmi:18.9, bmiClass:'Normal',          status:'normal',  age:13, sex:'Male',   hw:'155cm / 45.4kg', treatment:'RICE, pain relief',  followup:'Rest from PE for 3 days' },
    { id:10, name:'Ana P. Garcia',     initials:'AG', lrn:'100234560148', grade:'Grade 9',  section:'Bonifacio Sec 4',type:'Student',  date:'2026-03-26', complaint:'Allergic reaction',       diagnosis:'Urticaria',              bmi:20.3, bmiClass:'Normal',          status:'normal',  age:14, sex:'Female', hw:'160cm / 52kg',   treatment:'Antihistamine',      followup:'Advise avoidance of allergen' },
];

let activeTab = 'all';

function renderTable(data) {
    const tbody = document.getElementById('tableBody');
    tbody.innerHTML = '';
    data.forEach(r => {
        const bmiColor = r.bmiClass === 'Normal' ? 'bp-green' : r.bmiClass === 'Wasted' ? 'bp-amber' : r.bmiClass === 'Severely Wasted' ? 'bp-red' : 'bp-blue';
        const statusBadge = r.status === 'at-risk'
            ? `<span class="badge-pill bp-red"><div class="dot" style="background:var(--red)"></div>At-Risk</span>`
            : r.status === 'followup'
            ? `<span class="badge-pill bp-amber"><div class="dot" style="background:var(--amber)"></div>Follow-up</span>`
            : `<span class="badge-pill bp-green"><div class="dot" style="background:var(--g500)"></div>Normal</span>`;
        tbody.innerHTML += `
        <tr onclick="openSlideOver(${r.id})">
            <td>
                <div class="td-patient">
                    <div class="td-avatar">${r.initials}</div>
                    <div>
                        <div class="td-name">${r.name}</div>
                        <div class="td-id">${r.lrn}</div>
                    </div>
                </div>
            </td>
            <td>${r.grade}<br><span style="font-size:.7rem;color:var(--text-3)">${r.section}</span></td>
            <td>${formatDate(r.date)}</td>
            <td>${r.complaint}</td>
            <td style="color:var(--text-2)">${r.diagnosis}</td>
            <td><span class="badge-pill bmi-chip ${bmiColor}">${r.bmi} — ${r.bmiClass}</span></td>
            <td>${statusBadge}</td>
            <td>
                <div class="td-actions" onclick="event.stopPropagation()">
                    <button class="action-btn" title="View" onclick="openSlideOver(${r.id})">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                    <button class="action-btn" title="Edit">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </button>
                    <button class="action-btn del" title="Delete">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                    </button>
                </div>
            </td>
        </tr>`;
    });
    document.getElementById('recordCount').textContent = `Showing ${data.length} of 2,841`;
}

function filterTable() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    const grade = document.getElementById('gradeFilter').value;
    const type = document.getElementById('typeFilter').value;
    let filtered = records.filter(r => {
        const matchQ = !q || r.name.toLowerCase().includes(q) || r.lrn.includes(q) || r.complaint.toLowerCase().includes(q);
        const matchGrade = !grade || r.grade === grade;
        const matchType = !type || r.type === type;
        const matchTab = activeTab === 'all' || r.status === activeTab;
        return matchQ && matchGrade && matchType && matchTab;
    });
    renderTable(filtered);
}

function syncPersonnelFilters() {
    const gradeFilter = document.getElementById('gradeFilter');
    const typeFilter = document.getElementById('typeFilter');

    if (typeFilter.value === 'Personnel') {
        gradeFilter.value = 'Personnel';
        gradeFilter.disabled = true;
        return;
    }

    if (gradeFilter.disabled) {
        gradeFilter.disabled = false;
        if (gradeFilter.value === 'Personnel') {
            gradeFilter.value = '';
        }
    }
}

function setTab(el, tab) {
    document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    activeTab = tab;
    filterTable();
}

function formatDate(s) {
    return new Date(s).toLocaleDateString('en-PH', { month:'short', day:'numeric', year:'numeric' });
}

/* ── Modal ── */
function openModal() {
    document.getElementById('modalBackdrop').classList.add('open');
    const now = new Date();
    document.getElementById('consultDate').value = now.toISOString().split('T')[0];
    document.getElementById('consultTime').value = now.toTimeString().slice(0,5);
}
function closeModal() { document.getElementById('modalBackdrop').classList.remove('open'); }
function closeModalOutside(e) { if (e.target === document.getElementById('modalBackdrop')) closeModal(); }
function submitRecord() { document.getElementById('recordForm').submit(); }

/* ── BMI ── */
function computeBMI() {
    const h = parseFloat(document.getElementById('heightInput').value) / 100;
    const w = parseFloat(document.getElementById('weightInput').value);
    if (!h || !w || h <= 0) return;
    const bmi = (w / (h * h)).toFixed(1);
    let cls = bmi < 16 ? 'Severely Wasted' : bmi < 18.5 ? 'Wasted' : bmi < 25 ? 'Normal' : bmi < 30 ? 'Overweight' : 'Obese';
    let color = cls === 'Normal' ? 'var(--g700)' : cls === 'Wasted' ? '#92400e' : cls === 'Severely Wasted' ? 'var(--red)' : 'var(--blue)';
    document.getElementById('bmiVal').textContent = bmi;
    document.getElementById('bmiVal').style.color = color;
    document.getElementById('bmiClass').textContent = cls;
    document.getElementById('bmiHidden').value = bmi;
    document.getElementById('bmiClassHidden').value = cls;
}

/* ── Slide-over ── */
function openSlideOver(id) {
    const r = records.find(x => x.id === id);
    if (!r) return;
    document.getElementById('soAvatar').textContent = r.initials;
    document.getElementById('soName').textContent = r.name;
    document.getElementById('soMeta').textContent = `${r.grade} · ${r.section} · ${r.type} · LRN: ${r.lrn}`;
    document.getElementById('soAge').textContent = `${r.age} years old`;
    document.getElementById('soSex').textContent = r.sex;
    document.getElementById('soBMI').textContent = `${r.bmi} — ${r.bmiClass}`;
    document.getElementById('soHW').textContent = r.hw;
    document.getElementById('soDate').textContent = formatDate(r.date);
    document.getElementById('soComplaint').textContent = r.complaint;
    document.getElementById('soDiagnosis').textContent = r.diagnosis;
    document.getElementById('soTreatment').textContent = r.treatment;
    document.getElementById('soFollowup').textContent = r.followup;
    document.getElementById('soRiskBanner').style.display = r.status === 'at-risk' ? 'flex' : 'none';
    document.getElementById('soBackdrop').classList.add('open');
    document.getElementById('slideover').classList.add('open');
}
function closeSlideOver() {
    document.getElementById('soBackdrop').classList.remove('open');
    document.getElementById('slideover').classList.remove('open');
}
function editFromSlideOver() { closeSlideOver(); openModal(); }

function exportRecords() { alert('Generating PDF report…\n(Connect to Laravel PDF route)'); }
function sortTable(col) { /* connect to backend sort param */ }

/* ── Init ── */
syncPersonnelFilters();
renderTable(records);
</script>
</body>
</html>
