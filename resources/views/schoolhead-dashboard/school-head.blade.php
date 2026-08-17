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

        {{-- The figures first: the head opens this screen to read them, and a
             row of controls above them is a row of controls between the reader
             and the reading. The scope that produced them sits underneath. --}}
        <section class="kpi-grid live-pane" id="sh-stats">
            @include('schoolhead-dashboard.partials.stat-cards')
        </section>

        {{-- Scope. School year, grade and section move every panel
             together; only the school year is a SQL filter, because every other
             column these read is encrypted at rest. --}}
        <form method="GET" class="card sh-toolbar" id="shToolbar">
            <div class="sh-filter">
                <label class="field-label" for="shYear">School year</label>
                <select class="select" name="school_year" id="shYear">
                    @foreach ($schoolYears as $year)
                        <option value="{{ $year }}" @selected($filters['school_year'] === $year)>{{ $year }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sh-filter">
                <label class="field-label" for="shGrade">Grade</label>
                <select class="select" name="grade" id="shGrade">
                    <option value="">All grades</option>
                    @foreach ($filterOptions['grades'] as $grade)
                        <option value="{{ $grade }}" @selected($filters['grade'] === $grade)>{{ $grade }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sh-filter">
                <label class="field-label" for="shSection">Section</label>
                <select class="select" name="section" id="shSection">
                    <option value="">All sections</option>
                    @foreach ($filterOptions['sections'] as $section)
                        <option value="{{ $section }}" @selected($filters['section'] === $section)>{{ $section }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sh-filter sh-filter-action">
                @if ($filters['grade'] !== '' || $filters['section'] !== '')
                    <a class="btn btn-secondary" href="{{ route('dashboard.school-head', ['school_year' => $filters['school_year']]) }}">Whole school</a>
                @endif
            </div>

            {{-- No-JS fallback: without it the selects would be unreachable
                 controls on a page that never reloads. --}}
            <noscript><button type="submit" class="btn btn-secondary">Apply</button></noscript>
        </form>

        {{-- The queue is the reason this screen exists, so it sits above
             everything that merely reports. --}}
        <section class="card section sh-queue-card">
            <div class="section-head">
                <h2 class="section-title">Attention Required</h2>
                <div class="section-meta">Generated from live records &middot; updated <span id="sh-updated">{{ $generatedAt }}</span></div>
            </div>
            <div class="live-pane" id="sh-queue">
                @include('schoolhead-dashboard.partials.action-queue')
            </div>
        </section>

        <section class="grid-2">
            <article class="card section">
                <div class="section-head">
                    <h2 class="section-title">Health Program Performance</h2>
                </div>
                <div class="live-pane" id="sh-performance">
                    @include('schoolhead-dashboard.partials.performance')
                </div>
            </article>

            <article class="card section">
                <div class="section-head">
                    <h2 class="section-title">Clinic Overview</h2>
                    <a class="sh-section-link" href="{{ route('dashboard.school-head.health') }}">Health Overview</a>
                </div>
                <div class="live-pane" id="sh-clinic">
                    @include('schoolhead-dashboard.partials.clinic-panel')
                </div>
            </article>
        </section>

        <section class="card section">
            <div class="section-head">
                <h2 class="section-title">Feeding Program</h2>
                <a class="sh-section-link" href="{{ route('dashboard.school-head.program') }}">Feeding Dashboard</a>
            </div>
            <div class="live-pane" id="sh-feeding">
                @include('schoolhead-dashboard.partials.feeding-panel')
            </div>
        </section>

        <section class="card section">
            <div class="section-head">
                <h2 class="section-title">Nutritional Assessment</h2>
                <a class="sh-section-link" href="{{ route('dashboard.school-head.reports') }}">Reports</a>
            </div>
            <div class="live-pane" id="sh-nutrition">
                @include('schoolhead-dashboard.partials.nutrition-panel')
            </div>
        </section>

        <section class="grid-2">
            <article class="card section">
                <div class="section-head">
                    <h2 class="section-title">Consent Compliance</h2>
                </div>
                <div class="live-pane" id="sh-consent">
                    @include('schoolhead-dashboard.partials.consent-panel')
                </div>
            </article>

            <article class="card section">
                <div class="section-head">
                    <h2 class="section-title">Medicine Inventory</h2>
                </div>
                <div class="live-pane" id="sh-inventory">
                    @include('schoolhead-dashboard.partials.inventory-panel')
                </div>
            </article>
        </section>

        <section class="grid-2">
            <article class="card section">
                <div class="section-head">
                    <h2 class="section-title">Program Overview</h2>
                    <div class="section-meta">From this school&rsquo;s own records</div>
                </div>
                <div class="live-pane" id="sh-programs">
                    @include('schoolhead-dashboard.partials.program-overview')
                </div>
            </article>

            <article class="card section">
                <div class="section-head">
                    <h2 class="section-title">Grade Level Snapshot</h2>
                    <div class="section-meta">Latest weighing</div>
                </div>
                <div class="live-pane" id="sh-snapshot">
                    @include('schoolhead-dashboard.partials.grade-snapshot')
                </div>
            </article>
        </section>

        {{-- Where the head goes next. One card per remaining tab. --}}
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
        // The scope selects reload the page: every panel is server-rendered
        // from one scoped reading, so a filter is a new request, never a
        // client-side hide.
        document.querySelectorAll('#shToolbar select').forEach(function (control) {
            control.addEventListener('change', function () {
                control.form.requestSubmit ? control.form.requestSubmit() : control.form.submit();
            });
        });

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
            performance: document.getElementById('sh-performance'),
            clinic: document.getElementById('sh-clinic'),
            feeding: document.getElementById('sh-feeding'),
            nutrition: document.getElementById('sh-nutrition'),
            consent: document.getElementById('sh-consent'),
            inventory: document.getElementById('sh-inventory'),
        };
        const updated = document.getElementById('sh-updated');
        // The refresh has to carry the page's own scope, or a filtered
        // dashboard would silently redraw itself as the whole school.
        const metricsUrl = root.dataset.metricsUrl + (window.location.search || '');
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
