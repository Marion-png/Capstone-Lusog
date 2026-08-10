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
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <script>document.documentElement.classList.add('js');</script>
    {{-- Shared LUSOG design system, then this page's own styles, then the
         role sidebar panel — the same three-layer order every role uses. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
    <style>{!! file_get_contents(resource_path('css/schoolhead.css')) !!}</style>
    <style>{!! file_get_contents(resource_path('css/role-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.schoolhead-sidebar', ['active' => 'dashboard'])

<div class="main">
    <header class="topbar">
        <div class="topbar-bc"><span>Dashboard</span><span class="bc-sep">&rsaquo;</span><span>School Head</span></div>
        @include('partials.live-clock')
    </header>

    <div class="content">
        <div class="content-inner"
             id="sh-dashboard"
             data-stamp="{{ $stamp }}"
             data-metrics-url="{{ route('dashboard.school-head.metrics') }}"
             data-pulse-url="{{ route('dashboard.school-head.metrics.pulse') }}">
        <div class="page-header">
            <h1 class="page-title">School Head <span>Decision Dashboard</span></h1>
            <p class="page-sub">School health reports and program approvals overview.</p>
        </div>

        @if (session('success'))
            <div class="flash ok">{{ session('success') }}</div>
        @endif

        @if (session('error'))
            <div class="flash err">{{ session('error') }}</div>
        @endif

        @include('partials.announcements')

        <section class="kpi-grid cols-3 live-pane" id="sh-stats">
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
