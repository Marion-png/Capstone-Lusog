<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/sigla-mark.png') }}">
    <title>Data Visualization - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <script>document.documentElement.classList.add('js');</script>
    {{-- LUSOG order: theme, then this page's sheet, then the nurse rail. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
    <style>{!! file_get_contents(resource_path('css/school-nurse-data-visualization.css')) !!}</style>
    <style>{!! file_get_contents(resource_path('css/nurse-sidebar.css')) !!}</style>
</head>
<body>
{{-- The School Nurse, the Clinic Teacher and Clinic Staff read the same
     clinic, so each opens this page in their own rail. --}}
@include('partials.clinic-rail', ['active' => 'visualization'])

<div class="main">
    @php
        $roleLabel = \App\Support\AccountSettings::roleLabel(session('active_role'));
        $schoolName = session('active_school_name', 'No school assigned');
        $currentYear = \App\Models\StudentHealthRecord::currentSchoolYear();
    @endphp

    <header class="topbar">
        <div class="topbar-bc"><span>{{ $roleLabel }}</span><span class="bc-sep">&rsaquo;</span><span>Data Visualization</span></div>

        @include('partials.nurse-learner-search')
        <div class="topbar-spacer"></div>
        <div class="topbar-chip"><span class="dot"></span>{{ $schoolName }} &middot; SY {{ $currentYear }}</div>
        @include('partials.live-clock')
    </header>

    <div class="content">
        <div class="page-header">
            <div class="page-eyebrow">Reports</div>
            <h1 class="page-title">Manuscript-Based <span>Data Visualization</span></h1>
            <p class="page-sub">{{ $schoolName }} &middot; S.Y. {{ $schoolYear }}</p>
        </div>

        {{-- One filter row above everything it scopes. The school year is a
             navigation (the grade and section lists belong to its roll); grade
             and section re-read the panels in place; the weighing only picks
             which of the already-rendered phases is on screen. --}}
        <form method="GET" action="{{ route('dashboard.data-visualization') }}" class="card nv-toolbar" id="nvFilters"
            data-sections="{{ json_encode($filterOptions['sectionsByGrade']) }}">
            <div class="nv-filter">
                <label class="field-label" for="nvYear">School year</label>
                <select class="select" name="school_year" id="nvYear">
                    @foreach ($schoolYears as $year)
                        <option value="{{ $year }}" @selected($filters['school_year'] === $year)>{{ $year }}</option>
                    @endforeach
                </select>
            </div>
            <div class="nv-filter">
                <label class="field-label" for="nvGrade">Grade</label>
                <select class="select" name="grade" id="nvGrade">
                    <option value="">All grades</option>
                    @foreach ($filterOptions['grades'] as $grade)
                        <option value="{{ $grade }}" @selected($filters['grade'] === $grade)>{{ $grade }}</option>
                    @endforeach
                </select>
            </div>
            <div class="nv-filter">
                <label class="field-label" for="nvSection">Section</label>
                <select class="select" name="section" id="nvSection">
                    <option value="">All sections</option>
                    @foreach ($filterOptions['sections'] as $section)
                        <option value="{{ $section }}" @selected($filters['section'] === $section)>{{ $section }}</option>
                    @endforeach
                </select>
            </div>
            <fieldset class="nv-filter">
                <legend class="field-label">Weighing</legend>
                <div class="nv-seg">
                    @foreach ($phases as $key => $label)
                        <input type="radio" name="phase" id="nvPhase-{{ $key }}" value="{{ $key }}" @checked($filters['phase'] === $key)>
                        <label for="nvPhase-{{ $key }}">{{ $label }}</label>
                    @endforeach
                </div>
            </fieldset>
            <noscript><button type="submit" class="btn btn-secondary">Apply</button></noscript>
        </form>

        <div class="nv-live" id="nvLive"
            data-phase="{{ $filters['phase'] }}"
            data-source="{{ route('dashboard.data-visualization.data') }}"
            data-pulse="{{ route('workspace.pulse') }}"
            data-stamp="{{ $stamp }}">
            <section class="kpi-grid" data-pane="kpis">
                @include('dashboard.visualization.kpis')
            </section>

            <section class="nv-grid">
                <article class="card nv-card" data-pane="nutrition">
                    @include('dashboard.visualization.nutrition')
                </article>
                <article class="card nv-card" data-pane="grades">
                    @include('dashboard.visualization.grades')
                </article>
                <article class="card nv-card is-wide" data-pane="trend">
                    @include('dashboard.visualization.trend')
                </article>
                <article class="card nv-card" data-pane="cases">
                    @include('dashboard.visualization.cases')
                </article>
                <article class="card nv-card" data-pane="stock">
                    @include('dashboard.visualization.stock')
                </article>
                <article class="card nv-card is-wide" data-pane="feeding">
                    @include('dashboard.visualization.feeding')
                </article>
            </section>
        </div>
    </div>
</div>

{{-- One readout for every chart. Delegated from the document, so panels the
     live refresh swaps in are covered without re-binding anything. --}}
@include('partials.chart-tooltip')
@include('partials.nurse-page-transition')

<script>
(() => {
    const live = document.getElementById('nvLive');
    const form = document.getElementById('nvFilters');
    if (!live || !form) return;

    const PULSE_MS = 20000;
    const dataUrl = live.dataset.source;
    const pulseUrl = live.dataset.pulse;
    let stamp = live.dataset.stamp || '';
    let inflight = null;

    let sectionsByGrade = {};
    try { sectionsByGrade = JSON.parse(form.dataset.sections || '{}') || {}; } catch (e) { sectionsByGrade = {}; }

    // Everything the reader chose that is not in the query string. Re-applied
    // after every refresh, so a live update never undoes a click.
    const ui = { tables: new Set(), hidden: new Set(), expanded: false };

    const sync = () => {
        live.querySelectorAll('[data-pane]').forEach((pane) => {
            const asTable = ui.tables.has(pane.dataset.pane);
            pane.classList.toggle('is-table', asTable);
            pane.querySelectorAll('[data-view-toggle]').forEach((button) => {
                button.setAttribute('aria-pressed', asTable ? 'true' : 'false');
            });
        });

        const trend = live.querySelector('[data-pane="trend"]');
        if (trend) {
            ['treated', 'referred'].forEach((series) => {
                const hidden = ui.hidden.has(series);
                trend.classList.toggle('hide-' + series, hidden);
                trend.querySelectorAll('[data-series-toggle="' + series + '"]').forEach((button) => {
                    button.setAttribute('aria-pressed', hidden ? 'false' : 'true');
                });
            });
        }

        const stock = live.querySelector('[data-pane="stock"]');
        if (stock) {
            stock.classList.toggle('is-expanded', ui.expanded);
            stock.querySelectorAll('[data-stock-toggle]').forEach((button) => {
                button.setAttribute('aria-expanded', ui.expanded ? 'true' : 'false');
            });
        }
    };

    // ── The query string is the shareable state ──
    const params = () => {
        const query = new URLSearchParams();
        new FormData(form).forEach((value, key) => {
            if (String(value) !== '') query.set(key, String(value));
        });
        return query.toString();
    };

    // Kept in the address bar so a reload or a shared link opens the same view.
    const remember = () => {
        const query = params();
        try {
            history.replaceState(null, '', window.location.pathname + (query ? '?' + query : ''));
        } catch (error) {
            // Nothing depends on the address bar; the view itself has changed.
        }
    };

    // ── Re-read every panel, holding the old render dimmed meanwhile ──
    const refresh = async () => {
        if (inflight) inflight.abort();
        const controller = new AbortController();
        inflight = controller;

        live.classList.add('is-refreshing');
        live.setAttribute('aria-busy', 'true');

        try {
            const query = params();
            const response = await fetch(dataUrl + (query ? '?' + query : ''), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            if (!response.ok) return;

            const payload = await response.json();
            if (payload.stamp) stamp = payload.stamp;

            Object.entries(payload.html || {}).forEach(([key, html]) => {
                const pane = live.querySelector('[data-pane="' + key + '"]');
                if (pane) pane.innerHTML = html;
            });
            sync();
        } catch (error) {
            // Superseded by a newer request, or offline: the next pulse retries.
        } finally {
            if (inflight === controller) {
                inflight = null;
                live.classList.remove('is-refreshing');
                live.removeAttribute('aria-busy');
            }
        }
    };

    // ── Filters ──
    const yearSelect = document.getElementById('nvYear');
    const gradeSelect = document.getElementById('nvGrade');
    const sectionSelect = document.getElementById('nvSection');

    const rebuildSections = () => {
        const grade = gradeSelect.value;
        const chosen = sectionSelect.value;
        let names = [];
        if (grade && Array.isArray(sectionsByGrade[grade])) {
            names = sectionsByGrade[grade];
        } else {
            const all = new Set();
            Object.values(sectionsByGrade).forEach((list) => (list || []).forEach((name) => all.add(name)));
            names = Array.from(all).sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));
        }

        sectionSelect.replaceChildren();
        const first = document.createElement('option');
        first.value = '';
        first.textContent = 'All sections';
        sectionSelect.appendChild(first);
        names.forEach((name) => {
            const option = document.createElement('option');
            option.value = name;
            option.textContent = name;
            sectionSelect.appendChild(option);
        });
        sectionSelect.value = names.includes(chosen) ? chosen : '';
    };

    yearSelect?.addEventListener('change', () => {
        // A different year is a different roll: its grades and sections are
        // only known once the page is read for it. form.submit() navigates
        // without firing the submit listener below.
        gradeSelect.value = '';
        sectionSelect.value = '';
        form.submit();
    });

    gradeSelect?.addEventListener('change', () => {
        rebuildSections();
        remember();
        refresh();
    });

    sectionSelect?.addEventListener('change', () => {
        remember();
        refresh();
    });

    form.querySelectorAll('input[name="phase"]').forEach((radio) => {
        radio.addEventListener('change', () => {
            if (!radio.checked) return;
            live.dataset.phase = radio.value;
            remember();
        });
    });

    form.addEventListener('submit', (event) => {
        // Only the year navigates; any other submission (Enter on a control)
        // re-reads in place.
        event.preventDefault();
        remember();
        refresh();
    });

    // ── Card controls (delegated: the panels are replaced on refresh) ──
    live.addEventListener('click', (event) => {
        const viewToggle = event.target.closest('[data-view-toggle]');
        if (viewToggle) {
            const key = viewToggle.closest('[data-pane]')?.dataset.pane;
            if (key) {
                ui.tables.has(key) ? ui.tables.delete(key) : ui.tables.add(key);
                sync();
            }
            return;
        }

        const seriesToggle = event.target.closest('[data-series-toggle]');
        if (seriesToggle) {
            const series = seriesToggle.dataset.seriesToggle;
            if (ui.hidden.has(series)) {
                ui.hidden.delete(series);
            } else if (ui.hidden.size === 0) {
                // Never hide the last line: an empty plot answers nothing.
                ui.hidden.add(series);
            }
            sync();
            return;
        }

        if (event.target.closest('[data-stock-toggle]')) {
            ui.expanded = !ui.expanded;
            sync();
        }
    });

    // ── Donut readout: the centre names whatever slice or legend row is under the pointer ──
    const focusDonut = (donutWrap, status) => {
        const donut = donutWrap.querySelector('.nv-donut');
        const value = donutWrap.querySelector('[data-center-value]');
        const label = donutWrap.querySelector('[data-center-label]');
        if (!donut || !value || !label) return;

        if (!status) {
            donut.classList.remove('is-focusing');
            donutWrap.querySelectorAll('.is-active').forEach((el) => el.classList.remove('is-active'));
            value.textContent = value.dataset.default || '';
            label.textContent = label.dataset.default || '';
            return;
        }

        const source = donutWrap.querySelector('[data-legend][data-status="' + CSS.escape(status) + '"]');
        if (!source) return;

        donut.classList.add('is-focusing');
        donutWrap.querySelectorAll('[data-status]').forEach((el) => {
            el.classList.toggle('is-active', el.dataset.status === status);
        });
        value.textContent = source.dataset.share || '';
        label.textContent = status + ' · ' + (source.dataset.count || '0');
    };

    live.addEventListener('pointerover', (event) => {
        const mark = event.target.closest('[data-donut] [data-status]');
        if (!mark) return;
        focusDonut(mark.closest('[data-donut]'), mark.dataset.status);
    });

    live.addEventListener('pointerout', (event) => {
        const mark = event.target.closest('[data-donut] [data-status]');
        if (!mark) return;
        const next = event.relatedTarget && event.relatedTarget.closest ? event.relatedTarget.closest('[data-donut] [data-status]') : null;
        if (!next) focusDonut(mark.closest('[data-donut]'), null);
    });

    // ── Live: poll the school's shared stamp; re-read only when it moves ──
    const pulse = async () => {
        if (document.hidden || inflight) return;

        try {
            const response = await fetch(pulseUrl, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) return;

            const payload = await response.json();
            if (!payload.stamp || payload.stamp === stamp) return;

            stamp = payload.stamp;
            refresh();
        } catch (error) {
            // Offline, or a dropped request: the next pulse retries.
        }
    };

    window.setInterval(pulse, PULSE_MS);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) pulse();
    });

    sync();
})();
</script>
</body>
</html>
