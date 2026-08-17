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
        <div class="topbar-bc"><span>School Head</span><span class="bc-sep">&rsaquo;</span><span>Dashboard</span></div>
        @include('partials.live-clock')
    </header>

    <div class="content">
        <div class="content-inner"
             id="sh-dashboard"
             data-stamp="{{ $stamp }}"
             data-metrics-url="{{ route('dashboard.school-head.metrics') }}"
             data-pulse-url="{{ route('dashboard.school-head.metrics.pulse') }}">

        <div class="page-header sh-header">
            <div class="sh-headline">
                <h1 class="page-title">Good day, <span>{{ $headName }}</span></h1>
                <p class="sh-meta">
                    <span>{{ $todayLabel }}</span>
                    <span class="sh-sep">&middot;</span>
                    <span>{{ $schoolName }}</span>
                    <span class="sh-sep">&middot;</span>
                    <span class="tnum">S.Y. {{ $schoolYear }}</span>
                </p>
            </div>
            <div class="live-pane sh-header-cycle" id="sh-cycle">
                @include('schoolhead-dashboard.partials.cycle-bar')
            </div>
        </div>

        @if (session('success'))
            <div class="flash ok">{{ session('success') }}</div>
        @endif

        @if (session('error'))
            <div class="flash err">{{ session('error') }}</div>
        @endif

        @include('partials.announcements')

        <section class="kpi-grid live-pane" id="sh-stats">
            @include('schoolhead-dashboard.partials.stat-cards')
        </section>

        {{-- The queue is the reason this screen exists, so it sits above
             everything that merely reports. --}}
        <section class="card section sh-queue-card">
            <div class="section-head">
                <h2 class="section-title">What needs you today</h2>
                <div class="section-meta">Generated from live records &middot; updated <span id="sh-updated">{{ $generatedAt }}</span></div>
            </div>
            <div class="live-pane" id="sh-queue">
                @include('schoolhead-dashboard.partials.action-queue')
            </div>
        </section>

        <section class="grid-2">
            <article class="card section">
                <div class="section-head">
                    <h2 class="section-title">Program overview</h2>
                    <div class="section-meta">From this school&rsquo;s own records</div>
                </div>
                <div class="live-pane" id="sh-programs">
                    @include('schoolhead-dashboard.partials.program-overview')
                </div>
            </article>

            <article class="card section">
                <div class="section-head">
                    <h2 class="section-title">Grade level snapshot</h2>
                    <div class="section-meta">Latest weighing</div>
                </div>
                <div class="live-pane" id="sh-snapshot">
                    @include('schoolhead-dashboard.partials.grade-snapshot')
                </div>
            </article>
        </section>

        {{-- Where the head goes next. Three cards, one per remaining tab. --}}
        <section class="sh-links">
            <a class="card sh-link" href="{{ route('dashboard.school-head.program') }}">
                <span class="sh-link-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/></svg></span>
                <span class="sh-link-text">
                    <strong>Feeding Program</strong>
                    <span>The 120-day grid, turnout and nutritional status by grade.</span>
                </span>
            </a>
            <a class="card sh-link" href="{{ route('dashboard.school-head.reports') }}">
                <span class="sh-link-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="m19 9-5 5-4-4-3 3"/></svg></span>
                <span class="sh-link-text">
                    <strong>Reports</strong>
                    <span>Baseline against endline, and the reports awaiting your decision.</span>
                </span>
            </a>
            <a class="card sh-link" href="{{ route('dashboard.school-head.masterlist') }}">
                <span class="sh-link-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/></svg></span>
                <span class="sh-link-text">
                    <strong>Masterlist</strong>
                    <span>Every learner, their measurements and their attendance standing.</span>
                </span>
            </a>
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

        const panes = {
            stats: document.getElementById('sh-stats'),
            queue: document.getElementById('sh-queue'),
            cycle: document.getElementById('sh-cycle'),
            snapshot: document.getElementById('sh-snapshot'),
            programs: document.getElementById('sh-programs'),
        };
        const updated = document.getElementById('sh-updated');
        const metricsUrl = root.dataset.metricsUrl;
        const pulseUrl = root.dataset.pulseUrl;

        // Cheap enough to ask often; the answer is a stamp, and the expensive
        // rebuild only runs when the stamp actually moves.
        const PULSE_MS = 20000;

        const live = Object.values(panes).filter(Boolean);
        const setRefreshing = function (on) {
            live.forEach(function (pane) { pane.classList.toggle('is-refreshing', on); });
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
                Object.keys(panes).forEach(function (key) {
                    if (panes[key] && typeof payload.html[key] === 'string') {
                        panes[key].innerHTML = payload.html[key];
                    }
                });
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
