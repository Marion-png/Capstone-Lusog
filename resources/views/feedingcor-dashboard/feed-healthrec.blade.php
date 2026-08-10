<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Health Records - Feeding Coordinator - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <script>document.documentElement.classList.add('js');</script>
    <style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
    @php $pageCssPath = resource_path('css/feeding-healthrec.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
    <style>{!! file_get_contents(resource_path('css/role-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.feedingcor-sidebar', ['active' => 'records'])

@php
    $recordRows = $records ?? collect();
    $total = $recordRows->count();
    $endlineDone = $recordRows->filter(fn ($r) => !is_null($r->endline_bmi_value))->count();
    $wastedCount = ($statusCounts['wasted'] ?? 0) + ($statusCounts['severely_wasted'] ?? 0);
    $endlineRate = $total > 0 ? round($endlineDone / $total * 100) : 0;

    // One place decides which badge a nutritional status wears, so the
    // table, the summary and any future panel stay on the same scale.
    $statusBadge = function ($status) {
        $normalized = strtolower((string) $status);
        if (str_contains($normalized, 'severe')) return 'badge-critical';
        if (str_contains($normalized, 'wast') || str_contains($normalized, 'underweight')) return 'badge-risk';
        if (str_contains($normalized, 'over') || str_contains($normalized, 'obese')) return 'badge-monitor';
        if ($normalized === '') return 'badge-neutral';
        return 'badge-normal';
    };
@endphp

<div class="main">
    <header class="topbar">
        <div class="topbar-bc"><span>Dashboard</span><span class="bc-sep">&rsaquo;</span><span>Student Health Records</span></div>
        @include('partials.live-clock')
    </header>

    <div class="content">
        <div class="page-header">
            <h1 class="page-title">Student Health <span>Records</span></h1>
            <p class="page-sub">Tracks BMI progression and nutritional status change per beneficiary.</p>
        </div>

        <section class="kpi-grid">
            <article class="card kpi accent-brand">
                <div class="kpi-top">
                    <div class="kpi-label">Beneficiaries</div>
                    <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
                </div>
                <div class="kpi-value">{{ $total }}</div>
                <div class="kpi-hint">On file for this school</div>
            </article>
            <article class="card kpi accent-info">
                <div class="kpi-top">
                    <div class="kpi-label">With Endline Data</div>
                    <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="8" height="4" x="8" y="2" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/></svg></div>
                </div>
                <div class="kpi-value">{{ $endlineDone }}</div>
                <div class="kpi-hint">{{ $endlineRate }}% of beneficiaries measured</div>
            </article>
            <article class="card kpi accent-orange">
                <div class="kpi-top">
                    <div class="kpi-label">Wasted or Severe</div>
                    <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg></div>
                </div>
                <div class="kpi-value">{{ $wastedCount }}</div>
                <div class="kpi-hint">Requires intervention</div>
            </article>
            <article class="card kpi accent-success">
                <div class="kpi-top">
                    <div class="kpi-label">Normal Status</div>
                    <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/><path d="M3.22 12H9.5l.5-1 2 4.5 2-7 1.5 3.5h5.27"/></svg></div>
                </div>
                <div class="kpi-value">{{ $statusCounts['normal'] ?? 0 }}</div>
                <div class="kpi-hint">Within healthy BMI-for-age</div>
            </article>
        </section>

        <section class="records-section">
            <h2 class="section-title">Per Beneficiary Comparison</h2>

            @php
                $activeFilters = ($filters['grade_level'] ?? '') !== '' || ($filters['section'] ?? '') !== '';
            @endphp
            <form method="GET" class="toolbar" id="recordFilters">
                <div>
                    <label class="field-label" for="recordSearch">Search</label>
                    <div class="lg-search">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="search" id="recordSearch" placeholder="Name or section" autocomplete="off" aria-label="Search beneficiaries by name or section">
                    </div>
                </div>
                <div>
                    <label class="field-label" for="filterGrade">Grade Level</label>
                    <select class="select" name="grade_level" id="filterGrade">
                        <option value="">All grade levels</option>
                        @foreach (($gradeLevels ?? collect()) as $grade)
                            <option value="{{ $grade }}" @selected(($filters['grade_level'] ?? '') === $grade)>{{ $grade }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="field-label" for="filterSection">Section</label>
                    <select class="select" name="section" id="filterSection">
                        <option value="">All sections</option>
                        @foreach (($sections ?? collect()) as $section)
                            <option value="{{ $section }}" @selected(($filters['section'] ?? '') === $section)>{{ $section }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-primary filter-apply">Apply</button>
                @if ($activeFilters)
                    <a class="btn btn-secondary" href="{{ url()->current() }}">Clear</a>
                @endif
                <button type="button" class="btn btn-secondary" id="exportRecordsBtn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Export CSV
                </button>
                <span class="toolbar-count spacer" id="recordCount">
                    Showing {{ $total }} of {{ $totalBeforeFilters ?? $total }} {{ ($totalBeforeFilters ?? $total) === 1 ? 'beneficiary' : 'beneficiaries' }}
                </span>
            </form>

            <div class="table-card">
                <div class="table-scroll">
                    <table id="recordsTable">
                        <thead>
                            <tr>
                                <th class="sortable" data-sort="text" tabindex="0" role="button">Student</th>
                                <th class="sortable" data-sort="text" tabindex="0" role="button">Grade Level</th>
                                <th class="sortable" data-sort="text" tabindex="0" role="button">Section</th>
                                <th class="sortable num" data-sort="number" tabindex="0" role="button">Baseline BMI</th>
                                <th>Baseline Status</th>
                                <th class="sortable num" data-sort="number" tabindex="0" role="button">Endline BMI</th>
                                <th>Endline Status</th>
                                <th class="sortable num" data-sort="number" tabindex="0" role="button">Status Change</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recordRows as $record)
                                @php
                                    $baselineBmi = $record->baseline_bmi_value;
                                    $endlineBmi = $record->endline_bmi_value;
                                    $baselineStatus = $record->baseline_nutritional_status ?: $record->nutritional_status;
                                    $endlineStatus = $record->endline_nutritional_status ?: $record->nutritional_status;

                                    $deltaLabel = 'Pending endline';
                                    $deltaClass = 'delta-none';
                                    $deltaSort = '';
                                    if (!is_null($baselineBmi) && !is_null($endlineBmi)) {
                                        $delta = round((float) $endlineBmi - (float) $baselineBmi, 2);
                                        $deltaSort = $delta;
                                        if ($delta > 0) {
                                            $deltaLabel = '+' . number_format($delta, 2) . ' BMI';
                                            $deltaClass = 'delta-up';
                                        } elseif ($delta < 0) {
                                            $deltaLabel = number_format($delta, 2) . ' BMI';
                                            $deltaClass = 'delta-down';
                                        } else {
                                            $deltaLabel = 'No change';
                                        }
                                    }
                                @endphp
                                <tr data-search="{{ strtolower(trim($record->student_name.' '.$record->section_name)) }}">
                                    <td><strong>{{ $record->student_name }}</strong></td>
                                    <td>{{ $record->grade_level }}</td>
                                    <td>{{ $record->section_name }}</td>
                                    <td class="num" data-value="{{ $baselineBmi }}">{{ !is_null($baselineBmi) ? number_format((float) $baselineBmi, 2) : '—' }}</td>
                                    <td><span class="badge {{ $statusBadge($baselineStatus) }}">{{ $baselineStatus ?: 'Not set' }}</span></td>
                                    <td class="num" data-value="{{ $endlineBmi }}">{{ !is_null($endlineBmi) ? number_format((float) $endlineBmi, 2) : '—' }}</td>
                                    <td><span class="badge {{ $statusBadge($endlineStatus) }}">{{ $endlineStatus ?: 'Not set' }}</span></td>
                                    <td class="num" data-value="{{ $deltaSort }}"><span class="delta {{ $deltaClass }}">{{ $deltaLabel }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="table-empty">{{ $activeFilters ? 'No beneficiaries match this grade level and section.' : 'No records available yet.' }}</td></tr>
                            @endforelse
                            <tr id="recordsNoMatch" style="display:none;"><td colspan="8" class="table-empty">No beneficiaries match this search.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="records-section">
            <h2 class="section-title">Consolidated Baseline Report by Section</h2>
            <div class="table-card">
                <div class="table-scroll">
                    <table class="summary-table">
                        <thead>
                            <tr>
                                <th>Section</th>
                                <th class="count">Total</th>
                                <th class="count">Severely Wasted</th>
                                <th class="count">Wasted</th>
                                <th class="count">Normal</th>
                                <th class="count">Overweight</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse (($sectionSummary ?? collect()) as $summary)
                                <tr>
                                    <td><strong>{{ $summary['section'] }}</strong></td>
                                    <td class="count">{{ $summary['total'] }}</td>
                                    <td class="count">{{ $summary['counts']['severely_wasted'] }}</td>
                                    <td class="count">{{ $summary['counts']['wasted'] }}</td>
                                    <td class="count">{{ $summary['counts']['normal'] }}</td>
                                    <td class="count">{{ $summary['counts']['overweight'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="table-empty">No consolidated data yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>
@include('partials.role-page-transition')
<script>
(() => {
    const form = document.getElementById('recordFilters');
    if (!form) return;
    const grade = document.getElementById('filterGrade');

    form.querySelectorAll('select').forEach((select) => {
        select.addEventListener('change', () => {
            // Sections belong to a grade, so a new grade invalidates the section
            // already chosen. The server rebuilds the list for the new grade.
            if (select === grade) form.querySelector('#filterSection').value = '';
            form.submit();
        });
    });
    // With JS the selects submit themselves; the button stays as the no-JS path.
    form.querySelector('.filter-apply').style.display = 'none';

    const table = document.getElementById('recordsTable');
    const body = table.tBodies[0];
    const noMatch = document.getElementById('recordsNoMatch');
    const rows = Array.from(body.querySelectorAll('tr[data-search]'));
    const count = document.getElementById('recordCount');
    const countTemplate = count.textContent.trim();

    // ── Search: filters the rows already on the page, so the grade/section
    // filters above (which do go to the server) keep their meaning. ──
    const search = document.getElementById('recordSearch');
    const applySearch = () => {
        const q = search.value.trim().toLowerCase();
        let shown = 0;
        rows.forEach((row) => {
            const hit = q === '' || row.dataset.search.includes(q);
            row.style.display = hit ? '' : 'none';
            if (hit) shown += 1;
        });
        noMatch.style.display = (rows.length && shown === 0) ? '' : 'none';
        count.textContent = q === ''
            ? countTemplate
            : 'Showing ' + shown + ' of ' + rows.length + (rows.length === 1 ? ' beneficiary' : ' beneficiaries');
    };
    search.addEventListener('input', applySearch);
    // The search box lives inside the filter form, so Enter would otherwise
    // round-trip the server and drop the term.
    search.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });

    // ── Sort: one column at a time, toggling direction. Numeric columns read
    // data-value so "—" and "Pending endline" sort last instead of as text. ──
    const heads = Array.from(table.tHead.querySelectorAll('th.sortable'));
    const sortBy = (th) => {
        const index = Array.from(th.parentNode.children).indexOf(th);
        const dir = th.dataset.dir === 'asc' ? 'desc' : 'asc';
        heads.forEach((h) => delete h.dataset.dir);
        th.dataset.dir = dir;
        const sign = dir === 'asc' ? 1 : -1;

        rows.sort((a, b) => {
            const ca = a.children[index];
            const cb = b.children[index];
            if (th.dataset.sort === 'number') {
                const va = ca.dataset.value === '' ? null : parseFloat(ca.dataset.value);
                const vb = cb.dataset.value === '' ? null : parseFloat(cb.dataset.value);
                if (va === null && vb === null) return 0;
                if (va === null) return 1;   // blanks always sink
                if (vb === null) return -1;
                return (va - vb) * sign;
            }
            return ca.textContent.trim().localeCompare(cb.textContent.trim()) * sign;
        });
        rows.forEach((row) => body.insertBefore(row, noMatch));
    };
    heads.forEach((th) => {
        th.addEventListener('click', () => sortBy(th));
        th.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            e.preventDefault();
            sortBy(th);
        });
    });

    // ── Export: whatever is on screen, in the order it is on screen. ──
    document.getElementById('exportRecordsBtn').addEventListener('click', () => {
        const cell = (el) => '"' + el.textContent.trim().replace(/\s+/g, ' ').replace(/"/g, '""') + '"';
        const lines = [Array.from(table.tHead.rows[0].cells).map(cell).join(',')];
        rows.filter((row) => row.style.display !== 'none')
            .forEach((row) => lines.push(Array.from(row.cells).map(cell).join(',')));

        const url = URL.createObjectURL(new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = 'health-records-' + new Date().toISOString().slice(0, 10) + '.csv';
        link.click();
        URL.revokeObjectURL(url);
    });
})();
</script>
</body>
</html>
