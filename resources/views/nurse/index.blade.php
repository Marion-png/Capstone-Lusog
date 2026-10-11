<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Health Assessment - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <script>document.documentElement.classList.add('js');</script>
    {{-- LUSOG order: theme, then this page's sheet, then the nurse rail. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
    @php $pageCssPath = resource_path('css/school-nurse-review-queue.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
    <style>{!! file_get_contents(resource_path('css/nurse-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.nurse-lusog-sidebar', ['active' => 'queue'])

<div class="main">
    @php
        $schoolName = session('active_school_name', 'No school assigned');
        $schoolYear = \App\Models\StudentHealthRecord::currentSchoolYear();

        // The cards follow the grade and gender scope (never the status
        // filter — see NurseController::index), so they and the table always
        // describe the same learners.
        $queueTotal = $queueCounts['total'];
        $queueExamined = $queueCounts['completed'];
        $queuePending = $queueCounts['pending'];

        $isFiltered = $filters['grade'] !== '' || $filters['sex'] !== '' || $filters['status'] !== '';
        $scopeLabel = implode(' · ', array_filter([$filters['grade'], $filters['sex']]));
        $shownCount = count($records);
    @endphp

    <header class="topbar">
        <div class="topbar-bc"><span>School Nurse</span><span class="bc-sep">&rsaquo;</span><span>Health Assessment</span></div>

        @include('partials.nurse-learner-search')
        <div class="topbar-spacer"></div>
        <div class="topbar-chip"><span class="dot"></span>{{ $schoolName }} &middot; SY {{ $schoolYear }}</div>
        @include('partials.live-clock')
    </header>

    <div class="content">
        @if (session('success'))
            <div class="flash ok" role="status">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="flash err" role="alert">{{ session('error') }}</div>
        @endif

        <div class="page-header">
            <div class="page-eyebrow">Adviser Submissions</div>
            <h1 class="page-title">Health <span>Assessment</span></h1>
            <p class="page-sub">Health cards submitted by class advisers, waiting for the clinic's medical examination.</p>
        </div>

        <div class="kpi-grid cols-3">
            <div class="card kpi accent-brand">
                <div class="kpi-top">
                    <div class="kpi-label">Submitted Cards</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($queueTotal) }}</div>
                <div class="kpi-hint">{{ $scopeLabel !== '' ? $scopeLabel : "In this school's queue" }}</div>
            </div>

            <div class="card kpi accent-amber">
                <div class="kpi-top">
                    <div class="kpi-label">Awaiting Examination</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($queuePending) }}</div>
                <div class="kpi-hint">Still need a medical record</div>
            </div>

            <div class="card kpi accent-success">
                <div class="kpi-top">
                    <div class="kpi-label">Completed</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9 12l2 2 4-4"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($queueExamined) }}</div>
                <div class="kpi-hint">Examination on file</div>
            </div>
        </div>

        <div class="section-title compact queue-title">Submitted Health Cards</div>

        {{-- A GET form, so the choice lives on the query string: the page
             reloads itself when another desk writes, and the reload has to
             come back filtered. Options carry their count over the whole
             queue. The key for Gender stays `sex`, as on every other screen
             that filters by it; only the label reads "Gender". --}}
        <form class="toolbar queue-filters" method="GET" action="{{ route('nurse.index') }}" data-queue-filters>
            <div>
                <label class="field-label" for="queueGrade">Grade Level</label>
                <select class="select" id="queueGrade" name="grade">
                    <option value="">All grade levels ({{ $queueSize }})</option>
                    @foreach ($filterOptions['grade'] as $gradeOption => $gradeCount)
                        <option value="{{ $gradeOption }}" @selected($filters['grade'] === (string) $gradeOption)>{{ $gradeOption }} ({{ $gradeCount }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="field-label" for="queueSex">Gender</label>
                <select class="select" id="queueSex" name="sex">
                    <option value="">All ({{ $queueSize }})</option>
                    @foreach ($filterOptions['sex'] as $sexOption => $sexCount)
                        <option value="{{ $sexOption }}" @selected($filters['sex'] === $sexOption)>{{ $sexOption }} ({{ $sexCount }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="field-label" for="queueStatus">Status</label>
                <select class="select" id="queueStatus" name="status">
                    <option value="">All statuses ({{ $queueSize }})</option>
                    @foreach ($filterOptions['status'] as $statusOption => $statusCount)
                        <option value="{{ $statusOption }}" @selected($filters['status'] === $statusOption)>{{ \App\Http\Controllers\NurseController::QUEUE_STATUSES[$statusOption] }} ({{ $statusCount }})</option>
                    @endforeach
                </select>
            </div>
            {{-- With script the form submits itself on change; without it,
                 this is the way to apply a choice. --}}
            <noscript><button type="submit" class="btn btn-primary">Filter</button></noscript>
            @if ($isFiltered)
                <a href="{{ route('nurse.index') }}" class="btn btn-secondary">Clear filters</a>
            @endif
            <div class="toolbar-count">
                @if ($isFiltered)
                    {{ $shownCount }} of {{ $queueSize }} {{ Str::plural('record', $queueSize) }}
                @else
                    {{ $shownCount }} {{ Str::plural('record', $shownCount) }}
                @endif
            </div>
        </form>

        <div class="table-card">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>LRN</th>
                            <th>Grade Level</th>
                            <th>Gender</th>
                            <th class="num">Height (cm)</th>
                            <th class="num">Weight (kg)</th>
                            <th>Consent</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($records as $index => $record)
                        @php
                            $middle = trim((string) ($record['middle_name'] ?? ''));
                            $middleInitial = $middle !== '' ? (' ' . strtoupper(substr($middle, 0, 1)) . '.') : '';
                            $fullName = trim(($record['last_name'] ?? '') . ', ' . ($record['first_name'] ?? '') . $middleInitial);
                            $examined = ! empty($record['examination']);
                            $rowConsent = $consentByLrn[$record['lrn'] ?? ''] ?? null;
                            // The value the Gender filter matched on, so a
                            // narrowed list shows why each row is in it.
                            $rowSex = \App\Http\Controllers\NurseController::sexOf($record);
                        @endphp
                        <tr>
                            <td><strong>{{ $fullName }}</strong></td>
                            <td class="td-lrn">{{ $record['lrn'] ?? '—' }}</td>
                            <td>{{ $record['grade_level'] ?? '—' }}</td>
                            <td>{{ $rowSex !== '' ? $rowSex : '—' }}</td>
                            <td class="num">{{ $record['height_cm'] ?? '—' }}</td>
                            <td class="num">{{ $record['weight_kg'] ?? '—' }}</td>
                            <td>
                                <div class="consent-cell">
                                    @if ($rowConsent !== null)
                                        @if ($rowConsent->consent_type === 'refused')
                                            <span class="badge badge-neutral">Refused</span>
                                        @elseif ($rowConsent->consent_type === 'partial')
                                            <span class="badge badge-monitor">Partial</span>
                                        @else
                                            <span class="badge badge-normal">Full consent</span>
                                        @endif

                                        @if ($rowConsent->file_path !== null)
                                            <a href="{{ route('parental-consent.download', $rowConsent->id) }}"
                                               target="_blank"
                                               rel="noopener noreferrer"
                                               class="btn-view-consent"
                                               title="View signed consent form">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                                View
                                            </a>
                                        @endif
                                    @else
                                        <span class="badge badge-critical">Missing</span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                @if ($examined)
                                    <span class="badge badge-normal">Completed</span>
                                @else
                                    <span class="badge badge-neutral">Pending</span>
                                @endif
                            </td>
                            <td>
                                {{-- The action follows the Status beside it, read off the
                                     same `$examined` rather than a second test of its own:
                                     a row reading Completed used to offer "Fill Medical
                                     Record", telling the nurse to do work that is already
                                     on file. Both branches open the one existing form
                                     (`nurse.examine`), which prefills from the record and
                                     whose save updates it in place — there is no second
                                     form and no second write path. --}}
                                @if ($examined)
                                    <a href="{{ route('nurse.examine', $index) }}" class="btn btn-secondary"
                                       title="Edit Medical Record" aria-label="Edit Medical Record">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        Edit Medical Record
                                    </a>
                                @else
                                    <a href="{{ route('nurse.examine', $index) }}" class="btn btn-primary">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        Fill Medical Record
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9">
                                <div class="queue-empty">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg>
                                    {{-- An empty filter result is not an empty queue, and
                                         must not read like one. --}}
                                    @if ($isFiltered && $queueSize > 0)
                                        <p>No submitted health cards match these filters.</p>
                                        <a href="{{ route('nurse.index') }}" class="btn btn-secondary queue-empty-clear">Clear filters</a>
                                    @else
                                        <p>No adviser submissions yet. Records appear here once class advisers submit health cards.</p>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Apply a filter the moment it is chosen. Empty choices are left off the URL
// so an unfiltered queue reads /nurse, not /nurse?grade=&sex=&status=.
(() => {
    const form = document.querySelector('[data-queue-filters]');
    if (!form) return;

    form.addEventListener('change', () => {
        const params = new URLSearchParams();
        new FormData(form).forEach((value, key) => {
            if (String(value).trim() !== '') params.set(key, value);
        });
        const query = params.toString();
        window.location.assign(form.action + (query ? '?' + query : ''));
    });
})();
</script>

@include('partials.nurse-page-transition')
@include('partials.workspace-live')
</body>
</html>
