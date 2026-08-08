<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>School Head Dashboard - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    <script>document.documentElement.classList.add('js');</script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --topbar-h: 68px;
            --cream: #f5f8f4;
            --card: #ffffff;
            --border: #deebe2;
            --text-1: #0d1f14;
            --text-2: #365540;
            --text-3: #6d8f79;
            --red: #dc2626;
            --amber: #d97706;
            --green: #15803d;
            --shadow-card: 0 1px 3px rgba(5,46,22,.05), 0 10px 22px rgba(5,46,22,.06);
            --radius: 16px;
            --radius-sm: 10px;

            /* Chart series. Validated as a pair against the card surface:
               CVD separation ΔE 20.9 (protan), normal-vision ΔE 30.2. The amber
               sits below 3:1 against white, which is why the chart ships direct
               totals and a table view rather than relying on the fill alone. */
            --series-healthy: #166534;
            --series-risk: #ea8c0a;
            --grid-line: #e4eee8;
        }

        html, body { height: 100%; font-family: 'DM Sans', sans-serif; background: radial-gradient(circle at 5% -10%, #e7f7ec 0%, var(--cream) 50%); color: var(--text-1); overflow: hidden; }

        .main { height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
        .topbar { height: var(--topbar-h); border-bottom: 1px solid var(--border); background: rgba(255,255,255,.82); backdrop-filter: blur(6px); display: flex; align-items: center; justify-content: space-between; padding: 0 24px; }
        .topbar-bc { font-size: .76rem; color: var(--text-3); display: flex; gap: 6px; align-items: center; }

        .content { overflow: auto; padding: 20px; }
        .content-inner { max-width: 1240px; margin: 0 auto; }
        .page-header {
            margin-bottom: 16px;
            background: linear-gradient(130deg, #ffffff 0%, #f7fcf8 62%);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-card);
            border-radius: var(--radius);
            padding: 18px;
        }
        .page-eyebrow { font-size: .68rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: #15803d; margin-bottom: 6px; }
        .page-title { font-family: 'DM Serif Display', serif; font-size: clamp(1.45rem, 2.3vw, 1.9rem); color: var(--text-1); line-height: 1.15; }
        .page-title span { font-style: italic; color: #15803d; }
        .page-sub { margin-top: 6px; font-size: .82rem; color: var(--text-3); max-width: 70ch; }

        .flash { margin-bottom: 14px; padding: 11px 12px; border-radius: 10px; font-size: .8rem; border: 1px solid; }
        .flash-success { background: #f0fdf4; color: #166534; border-color: #bbf7d0; }
        .flash-error { background: #fef2f2; color: #991b1b; border-color: #fecaca; }

        .stats { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 12px; margin-bottom: 16px; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; box-shadow: var(--shadow-card); }
        .stat { padding: 14px 15px; position: relative; overflow: hidden; }
        .stat::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: #16a34a; }
        .stat .label { font-size: .68rem; color: var(--text-3); font-weight: 600; letter-spacing: .01em; }
        .stat .num { margin-top: 8px; font-family: 'DM Serif Display', serif; font-size: 1.58rem; line-height: 1; color: #0f2c1c; }
        .stat .hint { margin-top: 7px; font-size: .66rem; color: var(--text-3); }

        .section { padding: 14px; margin-bottom: 12px; }
        .section-head { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; margin-bottom: 10px; }
        .section-title { font-size: .84rem; letter-spacing: .02em; color: var(--text-2); font-weight: 700; }
        .section-meta { font-size: .7rem; color: var(--text-3); }

        .table-wrap { overflow-x: auto; border-radius: 10px; border: 1px solid var(--border); }
        table { width: 100%; border-collapse: collapse; background: #fff; }
        th, td { font-size: .74rem; text-align: left; padding: 9px; border-bottom: 1px solid var(--border); white-space: nowrap; font-variant-numeric: tabular-nums; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #f8fbf8; }
        th { color: var(--text-3); font-weight: 700; font-size: .7rem; text-transform: uppercase; letter-spacing: .04em; background: #f9fdf9; }

        .grid-2 { display: grid; grid-template-columns: 1fr 1.15fr; gap: 12px; }
        .program-item { display: flex; justify-content: space-between; gap: 10px; border-bottom: 1px solid var(--border); padding: 10px 0; }
        .program-item:last-child { border-bottom: none; }
        .program-label { font-size: .75rem; color: var(--text-2); font-weight: 700; }
        .program-sub { font-size: .67rem; color: var(--text-3); margin-top: 2px; }
        .program-note { font-size: .65rem; color: #b45309; margin-top: 3px; font-weight: 600; }
        .pill { border-radius: 999px; padding: 3px 8px; font-size: .63rem; font-weight: 700; align-self: center; white-space: nowrap; }
        .pill-ok { background: #dcfce7; color: #166534; }
        .pill-warn { background: #fef3c7; color: #92400e; }
        .pill-idle { background: #f1f5f4; color: #64748b; }

        /* ── Nutritional status chart ──────────────────────────────────────
           Thin columns on a hairline grid: the data is the only loud thing.
           Marks stay ≤ 24px wide with a 4px cap and a square baseline. */
        .chart-legend { display: flex; align-items: center; gap: 16px; font-size: .68rem; color: var(--text-3); margin-bottom: 12px; }
        .legend-item { display: inline-flex; align-items: center; gap: 6px; }
        .legend-item b { color: var(--text-2); font-variant-numeric: tabular-nums; }
        .legend-dot { width: 9px; height: 9px; border-radius: 3px; display: inline-block; flex: 0 0 auto; }
        .legend-healthy { background: var(--series-healthy); }
        .legend-risk { background: var(--series-risk); }

        /* The top margin is the cap labels' room: a column at the axis maximum
           reaches the top gridline, and its total is drawn above that. */
        .chart-figure { display: grid; grid-template-columns: 30px minmax(0, 1fr); grid-template-rows: 196px auto; column-gap: 10px; row-gap: 7px; margin-top: 14px; }
        .chart-axis { grid-column: 1; grid-row: 1; position: relative; }
        .axis-tick { position: absolute; right: 0; transform: translateY(50%); font-size: .62rem; color: var(--text-3); font-variant-numeric: tabular-nums; line-height: 1; }
        .chart-axis-title { grid-column: 1; grid-row: 2; font-size: .6rem; color: var(--text-3); text-align: right; }
        .chart-plot { grid-column: 2; grid-row: 1; position: relative; }
        .chart-grid { position: absolute; inset: 0; }
        .grid-line { position: absolute; left: 0; right: 0; height: 1px; background: var(--grid-line); }
        .chart-cols, .chart-labels { display: flex; align-items: flex-end; gap: 8px; height: 100%; }
        .chart-labels { grid-column: 2; grid-row: 2; height: auto; }
        .col-label { flex: 1 1 0; min-width: 0; text-align: center; font-size: .64rem; color: var(--text-3); font-variant-numeric: tabular-nums; }

        .chart-col { flex: 1 1 0; min-width: 0; height: 100%; position: relative; display: flex; align-items: flex-end; justify-content: center; outline: none; }
        .col-stack { width: min(24px, 70%); height: 100%; display: flex; flex-direction: column-reverse; justify-content: flex-start; }
        .col-seg { width: 100%; transform-origin: bottom; }
        /* Column-reverse puts the last child on top — that is the data end, so
           it carries the 4px cap while the baseline stays square. */
        .col-seg:last-child { border-radius: 4px 4px 0 0; }
        /* A 2px gap in the surface colour, never a stroke, separates the fills. */
        .col-seg + .col-seg { margin-bottom: 2px; }
        .seg-healthy { background: var(--series-healthy); }
        .seg-risk { background: var(--series-risk); }
        .col-cap { position: absolute; left: 50%; transform: translateX(-50%); font-size: .66rem; font-weight: 700; color: var(--text-2); font-variant-numeric: tabular-nums; line-height: 1; }

        .chart-col::after { content: ''; position: absolute; inset: 0 -4px; }
        /* The card is the tooltip's ceiling: each column sets its own `bottom`
           from its height, capped so a full-height column drops the card into
           the plot instead of pushing it out over the section title. */
        .chart-tip {
            position: absolute; bottom: 60%; left: 50%; transform: translateX(-50%) translateY(4px);
            min-width: 132px; padding: 8px 10px; border-radius: 9px; z-index: 5;
            background: #0d1f14; color: #fff; box-shadow: 0 8px 20px rgba(5,46,22,.22);
            opacity: 0; pointer-events: none; transition: opacity .14s ease, transform .14s ease;
        }
        .chart-col:hover .chart-tip, .chart-col:focus-visible .chart-tip { opacity: 1; transform: translateX(-50%) translateY(0); }
        .chart-col:focus-visible .col-stack { box-shadow: 0 0 0 2px #fff, 0 0 0 4px #15803d; border-radius: 5px; }
        .chart-col:first-child .chart-tip { left: 0; transform: translateX(0) translateY(4px); }
        .chart-col:first-child:hover .chart-tip, .chart-col:first-child:focus-visible .chart-tip { transform: translateX(0) translateY(0); }
        .chart-col:last-child .chart-tip { left: auto; right: 0; transform: translateX(0) translateY(4px); }
        .chart-col:last-child:hover .chart-tip, .chart-col:last-child:focus-visible .chart-tip { transform: translateX(0) translateY(0); }
        .tip-head { font-size: .7rem; font-weight: 700; margin-bottom: 5px; }
        .tip-row { display: flex; align-items: center; gap: 6px; font-size: .66rem; color: rgba(255,255,255,.76); margin-top: 2px; }
        .tip-row b { margin-left: auto; color: #fff; font-variant-numeric: tabular-nums; }
        .tip-total { margin-top: 6px; padding-top: 5px; border-top: 1px solid rgba(255,255,255,.16); font-size: .63rem; color: rgba(255,255,255,.66); }

        .chart-table { margin-top: 12px; }
        .chart-table summary { font-size: .68rem; color: var(--text-3); cursor: pointer; font-weight: 600; padding: 4px 0; width: fit-content; }
        .chart-table summary:hover { color: var(--text-2); }
        .chart-table .table-wrap { margin-top: 7px; }
        .chart-empty { display: grid; place-items: center; min-height: 196px; font-size: .74rem; color: var(--text-3); text-align: center; padding: 0 20px; }

        /* Refetch holds the previous render at reduced opacity — never a
           skeleton flash, never a layout jump. */
        .live-pane { transition: opacity .18s ease; }
        .live-pane.is-refreshing { opacity: .55; }

        .chart.is-animating .col-seg { transform: scaleY(0); animation: growBar .6s cubic-bezier(.22,.61,.36,1) forwards; }
        .chart.is-animating .seg-risk { animation-delay: .05s; }
        .chart.is-animating .seg-healthy { animation-delay: .14s; }
        .chart.is-animating .col-cap { opacity: 0; animation: capIn .4s ease .5s forwards; }

        @keyframes growBar { from { transform: scaleY(0); } to { transform: scaleY(1); } }
        @keyframes capIn { from { opacity: 0; transform: translateX(-50%) translateY(4px); } to { opacity: 1; transform: translateX(-50%) translateY(0); } }

        @media (prefers-reduced-motion: reduce) {
            .chart.is-animating .col-seg, .chart.is-animating .col-cap { animation: none; transform: none; opacity: 1; }
            .chart.is-animating .col-cap { transform: translateX(-50%); }
            .live-pane { transition: none; }
        }

        @media (max-width: 1050px) {
            .stats { grid-template-columns: repeat(2, minmax(0,1fr)); }
            .grid-2 { grid-template-columns: 1fr; }
        }

        @media (max-width: 780px) {
            .topbar { padding: 0 14px; }
            .content { padding: 14px; }
            .page-header { padding: 14px; }
            .stats { grid-template-columns: 1fr; }
        }
    </style>
    {{-- The shared role sidebar panel — loaded last so its .main offset wins. --}}
    <style>{!! file_get_contents(resource_path('css/role-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.schoolhead-sidebar', ['active' => 'dashboard'])

<div class="main">
    <header class="topbar">
        <div class="topbar-bc"><span>Dashboard</span><span>&rsaquo;</span><span>School Head</span></div>
        @include('partials.live-clock')
    </header>

    <div class="content">
        <div class="content-inner"
             id="sh-dashboard"
             data-stamp="{{ $stamp }}"
             data-metrics-url="{{ route('dashboard.school-head.metrics') }}"
             data-pulse-url="{{ route('dashboard.school-head.metrics.pulse') }}">
        <div class="page-header">
            <div class="page-eyebrow">School Head Dashboard</div>
            <h1 class="page-title">School Head <span>Decision Dashboard</span></h1>
            <p class="page-sub">School health reports and program approvals overview.</p>
        </div>

        @if (session('success'))
            <div class="flash flash-success">{{ session('success') }}</div>
        @endif

        @if (session('error'))
            <div class="flash flash-error">{{ session('error') }}</div>
        @endif

        @include('partials.announcements')

        <section class="stats live-pane" id="sh-stats">
            @include('schoolhead-dashboard.partials.stat-cards')
        </section>

        <section class="grid-2">
            <article class="card section">
                <div class="section-head">
                    <h2 class="section-title">Program Overview</h2>
                    <div class="section-meta">Updated <span id="sh-updated">{{ $generatedAt }}</span></div>
                </div>
                <div class="live-pane" id="sh-programs">
                    @include('schoolhead-dashboard.partials.program-overview')
                </div>
            </article>

            <article class="card section">
                <div class="section-head">
                    <h2 class="section-title">Nutritional Status by Grade</h2>
                    <div class="section-meta">{{ \App\Models\StudentHealthRecord::currentSchoolYear() }}</div>
                </div>
                <div class="chart live-pane" id="sh-chart">
                    @include('schoolhead-dashboard.partials.status-chart')
                </div>
            </article>
        </section>
        </div>
    </div>
</div>
<script>
    (function () {
        const root = document.getElementById('sh-dashboard');
        if (!root) {
            return;
        }

        const chart = document.getElementById('sh-chart');
        const stats = document.getElementById('sh-stats');
        const programs = document.getElementById('sh-programs');
        const updated = document.getElementById('sh-updated');
        const metricsUrl = root.dataset.metricsUrl;
        const pulseUrl = root.dataset.pulseUrl;
        const stillMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

        // Cheap enough to ask often; the answer is a stamp, and the expensive
        // rebuild only runs when the stamp actually moves.
        const PULSE_MS = 20000;

        const animateChart = function () {
            if (!chart || stillMotion.matches) {
                return;
            }
            chart.classList.remove('is-animating');
            void chart.offsetWidth;
            chart.classList.add('is-animating');
        };

        const panes = [stats, programs, chart].filter(Boolean);
        const setRefreshing = function (on) {
            panes.forEach(function (pane) { pane.classList.toggle('is-refreshing', on); });
        };

        let inFlight = false;

        const refresh = async function () {
            if (inFlight) {
                return;
            }

            inFlight = true;
            setRefreshing(true);
            try {
                const response = await fetch(metricsUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!response.ok) {
                    return;
                }

                const payload = await response.json();
                if (!payload.html) {
                    return;
                }

                // The server renders the same Blade partials the first paint
                // used, so the live view can never drift from it.
                if (stats && typeof payload.html.stats === 'string') { stats.innerHTML = payload.html.stats; }
                if (programs && typeof payload.html.programs === 'string') { programs.innerHTML = payload.html.programs; }
                if (chart && typeof payload.html.chart === 'string') { chart.innerHTML = payload.html.chart; animateChart(); }
                if (updated && payload.generatedAt) { updated.textContent = payload.generatedAt; }
                if (payload.stamp) { root.dataset.stamp = payload.stamp; }
            } catch (error) {
                // Offline or a dropped request: keep what is on screen and try
                // again on the next pulse.
            } finally {
                inFlight = false;
                setRefreshing(false);
            }
        };

        const pulse = async function () {
            if (document.hidden || inFlight) {
                return;
            }

            try {
                const response = await fetch(pulseUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!response.ok) {
                    return;
                }

                const payload = await response.json();
                if (payload.stamp && payload.stamp !== root.dataset.stamp) {
                    root.dataset.stamp = payload.stamp;
                    await refresh();
                }
            } catch (error) {
                // Ignored — the next pulse retries.
            }
        };

        window.addEventListener('load', animateChart);
        window.addEventListener('pageshow', animateChart);

        // First pulse seeds the stamp; from then on it only fires a rebuild
        // when the underlying records have actually changed.
        pulse();
        window.setInterval(pulse, PULSE_MS);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                pulse();
            }
        });
    })();
</script>
@include('partials.role-page-transition')
</body>
</html>
