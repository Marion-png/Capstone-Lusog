<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Feeding Status - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @php $classAdviserCssPath = resource_path('css/class-adviser.css'); @endphp
    @if (file_exists($classAdviserCssPath))
        <style>{!! file_get_contents($classAdviserCssPath) !!}</style>
    @endif
</head>
<body>
@include('partials.adviser-sidebar', ['active' => 'feeding'])

<div class="asb-main">
    @include('partials.adviser-topbar', ['breadcrumb' => 'Feeding Status'])

    <div class="content">
        @php
            $statusLabels = [
                'normal' => 'Normal',
                'wasted' => 'Wasted',
                'severely-wasted' => 'Severely Wasted',
                'underweight' => 'Underweight',
                'overweight' => 'Overweight',
                'obese' => 'Obese',
                'not-assessed' => 'Not Assessed',
                'other' => 'Other',
            ];
            $statusTones = [
                'normal' => 'fs-tone-ok',
                'wasted' => 'fs-tone-warn',
                'underweight' => 'fs-tone-warn',
                'severely-wasted' => 'fs-tone-bad',
                'overweight' => 'fs-tone-info',
                'obese' => 'fs-tone-info',
                'not-assessed' => 'fs-tone-mute',
                'other' => 'fs-tone-mute',
            ];
            $programLabels = [
                'not-enrolled' => 'Not Enrolled',
                'ongoing' => 'Ongoing',
                'completed' => 'Completed',
            ];
            $programTones = [
                'not-enrolled' => 'fs-tone-mute',
                'ongoing' => 'fs-tone-warn',
                'completed' => 'fs-tone-ok',
            ];
            $assessmentLabels = [
                'complete' => 'Baseline &amp; Endline',
                'pending' => 'Endline Pending',
                'none' => 'Not Started',
            ];
            $assessmentTones = [
                'complete' => 'fs-tone-ok',
                'pending' => 'fs-tone-warn',
                'none' => 'fs-tone-mute',
            ];

<<<<<<< Updated upstream
            // Only the classifications actually present become filter options,
            // so the dropdown never offers a choice that matches nothing.
            $presentStatusKeys = $students->pluck('status_key')->unique()->sort()->values();
        @endphp

        <div class="ms-page-header">
            <div>
                <h2 class="ms-page-title">SBFP Feeding Status</h2>
                <p class="ms-page-sub">{{ $gradeSection }} &middot; School Year {{ $schoolYear }}</p>
            </div>
        </div>

        <div class="pc-banner">
            <div>
                <h2 class="pc-banner-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
                    School-Based Feeding Program
                </h2>
                <p class="pc-banner-sub">{{ $stats['at_risk'] }} at-risk &middot; {{ $stats['total'] }} learners in your class</p>
            </div>
            <div class="pc-banner-chips">
                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg><b>{{ $stats['enrolled'] }}</b> Enrolled</span>
                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><b>{{ $stats['ongoing'] }}</b> Ongoing</span>
                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><b>{{ $stats['completed'] }}</b> Completed</span>
            </div>
        </div>

        <div class="ms-stats-bar">
            <div class="ms-stat">
                <div class="ms-stat-icon ms-icon-complete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
                <div><div class="ms-stat-number">{{ $stats['normal'] }}</div><div class="ms-stat-label">Normal</div></div>
            </div>
            <div class="ms-stat">
                <div class="ms-stat-icon ms-icon-pending"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
                <div><div class="ms-stat-number">{{ $stats['wasted'] }}</div><div class="ms-stat-label">Wasted</div></div>
            </div>
            <div class="ms-stat">
                <div class="ms-stat-icon ms-icon-alert"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
                <div><div class="ms-stat-number">{{ $stats['severely_wasted'] }}</div><div class="ms-stat-label">Severely Wasted</div></div>
            </div>
            <div class="ms-stat">
                <div class="ms-stat-icon ms-icon-total"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="9 16 11 18 15 14"/></svg></div>
                <div><div class="ms-stat-number">{{ $stats['attendance_rate'] }}%</div><div class="ms-stat-label">Overall Attendance</div></div>
            </div>
        </div>

        <article class="ms-table-container">
            <div class="ms-table-header">
                <h3>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
                    Feeding Attendance &amp; Progress
                    <span class="ms-count-badge" id="feedingCountBadge">{{ $stats['total'] }}</span>
                </h3>
                <div class="ms-table-actions">
                    <div class="ms-search-wrapper">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input id="feedingSearch" class="ms-search-input" type="text" placeholder="Search by name or LRN..." autocomplete="off">
                    </div>
                    <select id="feedingProgramFilter" class="ms-filter-select" aria-label="Filter by feeding program">
                        <option value="all">All Programs</option>
                        <option value="enrolled">Enrolled</option>
                        <option value="not-enrolled">Not Enrolled</option>
                        <option value="ongoing">Ongoing</option>
                        <option value="completed">Completed</option>
                        <option value="at-risk">At-Risk</option>
                    </select>
                    <select id="feedingStatusFilter" class="ms-filter-select" aria-label="Filter by nutritional status">
                        <option value="all">All Statuses</option>
                        @foreach ($presentStatusKeys as $key)
                            <option value="{{ $key }}">{{ $statusLabels[$key] ?? ucfirst($key) }}</option>
                        @endforeach
                    </select>
=======
        <section class="card section" style="margin-top:16px;">
            @if ($students->isEmpty())
                <div style="padding:30px;text-align:center;color:var(--muted);font-size:.85rem;">
                    No students with health records found for your assigned class yet.
>>>>>>> Stashed changes
                </div>
            </div>

            <div class="ms-table-scroll">
                <table class="ms-table">
                    <thead>
                        <tr>
                            <th>LRN</th>
                            <th>Student Name</th>
                            <th>BMI</th>
                            <th>Nutritional Status</th>
                            <th>Feeding Program</th>
                            <th>Attendance</th>
                            <th>Progress</th>
                            <th>Assessment</th>
                        </tr>
                    </thead>
                    <tbody id="feedingTableBody">
                        @forelse ($students as $student)
                            @php
                                $statusKey = $student['status_key'];
                                $statusLabel = $student['status'] !== '' ? $student['status'] : $statusLabels['not-assessed'];
                                $rateTone = $student['sessions'] === 0
                                    ? 'is-empty'
                                    : ($student['rate'] >= 75 ? '' : ($student['rate'] >= 50 ? 'is-warn' : 'is-bad'));
                            @endphp
                            <tr class="js-feeding-row"
                                data-name="{{ strtolower($student['name']) }}"
                                data-lrn="{{ strtolower($student['lrn']) }}"
                                data-status="{{ $statusKey }}"
                                data-program="{{ $student['program'] }}"
                                data-enrolled="{{ $student['eligible'] ? '1' : '0' }}"
                                data-risk="{{ $student['at_risk'] ? '1' : '0' }}">
                                <td><strong>{{ $student['lrn'] !== '' ? $student['lrn'] : '-' }}</strong></td>
                                <td class="ms-student-name">{{ $student['name'] !== '' ? $student['name'] : '-' }}</td>
                                <td>
                                    <span class="fs-metric">{{ $student['bmi'] !== '' ? $student['bmi'] : '-' }}</span>
                                    @if ($student['weight'] !== '')
                                        <span class="fs-sub">{{ $student['weight'] }} kg</span>
                                    @endif
                                </td>
                                <td><span class="ms-badge {{ $statusTones[$statusKey] ?? 'fs-tone-mute' }}">{{ $statusLabel }}</span></td>
                                <td>
                                    <div class="fs-badge-stack">
                                        <span class="ms-badge {{ $programTones[$student['program']] }}">{{ $programLabels[$student['program']] }}</span>
                                        @if ($student['at_risk'])
                                            <span class="ms-badge fs-tone-bad">At-Risk</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    @if ($student['sessions'] > 0)
                                        <span class="fs-metric">{{ $student['attended'] }} / {{ $student['sessions'] }}</span>
                                        <span class="fs-sub">sessions</span>
                                    @else
                                        <span class="muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="fs-progress">
                                        <span class="fs-progress-val">{{ $student['sessions'] > 0 ? $student['rate'] . '%' : '-' }}</span>
                                        <span class="fs-progress-bar {{ $rateTone }}"><span style="width:{{ min(100, max(0, $student['rate'])) }}%;"></span></span>
                                    </div>
                                </td>
                                <td><span class="ms-badge {{ $assessmentTones[$student['assessment']] }}">{!! $assessmentLabels[$student['assessment']] !!}</span></td>
                            </tr>
                        @empty
                            <tr class="js-feeding-empty">
                                <td colspan="8">
                                    <div class="ms-empty-state">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
                                        <h4>No Feeding Records Yet</h4>
                                        <p>Enrol learners on My Students to see their nutritional status here.</p>
                                        <a class="btn" href="{{ route('dashboard.class-adviser', ['tab' => 'saved']) }}">Go to My Students</a>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                        <tr class="js-feeding-nomatch" hidden>
                            <td colspan="8">
                                <div class="ms-empty-state">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                    <h4>No Records Found</h4>
                                    <p>No learners match your current search or filter.</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="ms-table-footer">
                <div class="ms-footer-info">
                    Showing <strong id="fsShowingStart">0</strong> to <strong id="fsShowingEnd">0</strong> of <strong id="fsShowingTotal">0</strong> learners
                </div>
                <div class="ms-pagination" id="fsPagination"></div>
            </div>
        </article>
    </div>
</div>

<script>
// Table: search + program/status filters + pagination, mirroring My Students.
(() => {
    const searchInput = document.getElementById('feedingSearch');
    const programSelect = document.getElementById('feedingProgramFilter');
    const statusSelect = document.getElementById('feedingStatusFilter');
    const tbody = document.getElementById('feedingTableBody');

    if (!searchInput || !programSelect || !statusSelect || !tbody) {
        return;
    }

    const rows = Array.from(tbody.querySelectorAll('.js-feeding-row'));
    const noMatchRow = tbody.querySelector('.js-feeding-nomatch');
    const pagination = document.getElementById('fsPagination');
    const startOut = document.getElementById('fsShowingStart');
    const endOut = document.getElementById('fsShowingEnd');
    const totalOut = document.getElementById('fsShowingTotal');
    const countBadge = document.getElementById('feedingCountBadge');
    const perPage = 8;

    let page = 1;
    let matches = rows.slice();

    const renderPagination = (totalPages) => {
        if (!pagination) {
            return;
        }

        pagination.innerHTML = '';
        if (matches.length <= perPage) {
            return;
        }

        const addButton = (label, targetPage, { disabled = false, active = false } = {}) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = label;
            button.disabled = disabled;
            if (active) {
                button.classList.add('active');
            }
            if (!disabled && !active) {
                button.addEventListener('click', () => { page = targetPage; render(); });
            }
            pagination.appendChild(button);
        };

        addButton('‹', page - 1, { disabled: page === 1 });

        const maxVisible = 5;
        let startPage = Math.max(1, page - Math.floor(maxVisible / 2));
        const endPage = Math.min(totalPages, startPage + maxVisible - 1);
        startPage = Math.max(1, Math.min(startPage, endPage - maxVisible + 1));

        for (let i = startPage; i <= endPage; i += 1) {
            addButton(String(i), i, { active: i === page });
        }

        addButton('›', page + 1, { disabled: page === totalPages });
    };

    const render = () => {
        const totalPages = Math.max(1, Math.ceil(matches.length / perPage));
        page = Math.min(Math.max(page, 1), totalPages);

        const start = (page - 1) * perPage;
        const end = Math.min(start + perPage, matches.length);
        const onPage = new Set(matches.slice(start, end));

        rows.forEach((row) => { row.style.display = onPage.has(row) ? '' : 'none'; });

        if (noMatchRow) {
            noMatchRow.hidden = rows.length === 0 || matches.length > 0;
        }

        if (startOut) startOut.textContent = String(matches.length === 0 ? 0 : start + 1);
        if (endOut) endOut.textContent = String(end);
        if (totalOut) totalOut.textContent = String(matches.length);
        if (countBadge) countBadge.textContent = String(matches.length);

        renderPagination(totalPages);
    };

    const applyFilters = () => {
        const keyword = searchInput.value.trim().toLowerCase();
        const program = programSelect.value;
        const status = statusSelect.value;

        matches = rows.filter((row) => {
            const name = row.dataset.name || '';
            const lrn = row.dataset.lrn || '';
            const keywordMatch = !keyword || name.includes(keyword) || lrn.includes(keyword);

            // "Enrolled" spans both ongoing and completed learners, and
            // "At-Risk" is an attendance flag rather than a program state, so
            // neither can be a plain data-program comparison.
            let programMatch = true;
            if (program === 'enrolled') {
                programMatch = row.dataset.enrolled === '1';
            } else if (program === 'at-risk') {
                programMatch = row.dataset.risk === '1';
            } else if (program !== 'all') {
                programMatch = (row.dataset.program || '') === program;
            }

            const statusMatch = status === 'all' || (row.dataset.status || '') === status;

            return keywordMatch && programMatch && statusMatch;
        });

        page = 1;
        render();
    };

    searchInput.addEventListener('input', applyFilters);
    programSelect.addEventListener('change', applyFilters);
    statusSelect.addEventListener('change', applyFilters);

    render();
})();
</script>
</body>
</html>
