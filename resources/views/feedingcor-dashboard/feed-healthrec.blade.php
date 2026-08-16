<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Beneficiaries - Feeding Coordinator - SIGLA</title>
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
    <style>{!! file_get_contents(resource_path('css/feeding-enroll-modal.css')) !!}</style>
    <style>{!! file_get_contents(resource_path('css/role-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.feedingcor-sidebar', ['active' => 'records'])

@php
    $recordRows = $records ?? collect();
    // Every learner the table lists. The headline cards count the enrolled
    // roll instead — see partials/beneficiary-cards — so this stays with the
    // table it describes.
    $total = $recordRows->count();

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
        <div class="topbar-bc"><span>Dashboard</span><span class="bc-sep">&rsaquo;</span><span>Beneficiaries</span></div>
        @include('partials.live-clock')
    </header>

    <div class="content" id="bnf-page"
        data-cards-url="{{ route('dashboard.feedingcor-health-records.cards') }}"
        data-pulse-url="{{ route('dashboard.feedingcor.metrics.pulse') }}">
        @php
            // En dash in the school year: it is a range, not a hyphenated word.
            $bnfYear = str_replace('-', '&ndash;', e((string) ($schoolYear ?? '')));
        @endphp
        <div class="page-header sbfp-header">
            <div class="sbfp-headline">
                {{-- Same two voices as every other tab's title: the subject
                     upright, the section italic emerald, year beside it. --}}
                <div class="sbfp-title-row">
                    <h1 class="page-title">SBFP <span>Beneficiaries</span></h1>
                    <span class="sbfp-year">S.Y. {!! $bnfYear !!}</span>
                </div>
                <p class="sbfp-program">Active Program: <strong>School-Based Feeding Program</strong></p>
            </div>

            {{-- The actions ride the far end of the header row, opposite the
                 title. Enrolling is the one that changes the programme, so it
                 leads in the filled button; the two masterlist actions carry
                 the same roster out to paper or a spreadsheet. --}}
            <div class="sbfp-actions">
                <button type="button" class="btn btn-primary" id="enrollBeneficiariesBtn" data-enroll-open>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                    Enroll Beneficiaries
                </button>
                <button type="button" class="btn btn-secondary" id="exportMasterlistBtn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Export Masterlist
                </button>
                <button type="button" class="btn btn-secondary" id="printMasterlistBtn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
                    Print Masterlist
                </button>
            </div>
        </div>

        {{-- The five figures are re-read whenever the coordinator's metrics
             stamp moves, so a mark recorded at the feeding line lands here
             without a reload. --}}
        <section class="kpi-grid cols-5 live-pane" id="bnf-cards">
            @include('feedingcor-dashboard.partials.beneficiary-cards')
        </section>

        {{-- Three views of the roster below, each carrying its own size. --}}
        <div class="live-pane" id="bnf-tabs">
            @include('feedingcor-dashboard.partials.beneficiary-tabs')
        </div>

        <section class="records-section">
            @php
                // The heading names the view being read, so the section and the
                // tab above it always say the same thing.
                $isPending = ($segmentView ?? 'all') === 'pending';
                $sectionTitle = match ($segmentView ?? 'all') {
                    'pending' => 'Pending Enrollment',
                    'at_risk' => 'Attendance At Risk',
                    default => 'Per Beneficiary Comparison',
                };
            @endphp
            <h2 class="section-title">{{ $sectionTitle }}</h2>

            @php
                $ff = $filters ?? [];
                $fo = $filterOptions ?? [];
                // A school year other than the current one counts as a filter
                // too, or there would be no way back from it.
                $activeFilters = collect($ff)->except('school_year')->filter(fn ($value) => (string) $value !== '')->isNotEmpty()
                    || ($ff['school_year'] ?? '') !== \App\Models\StudentHealthRecord::currentSchoolYear();
            @endphp

            {{-- Eight coordinated filters. Year, grade and section are scope:
                 they move the cards and the tabs with them, exactly as they do
                 on the Dashboard. The other five narrow this list alone, so the
                 tab above the table keeps reporting the size of the view rather
                 than the size of the last thing chosen in a select. Only the
                 year is a SQL filter — every other column is encrypted at rest,
                 so the rest are applied in PHP after fetch. --}}
            <form method="GET" class="card bnf-filters" id="recordFilters">
                {{-- Changing a filter must not throw the coordinator back to
                     the default view, so the chosen one rides the submit. --}}
                <input type="hidden" name="view" value="{{ $segmentView ?? 'all' }}">

                {{-- The name comes first: a coordinator looking for one
                     learner types rather than works down eight selects. It
                     filters the rows already on the page, so the selects below
                     (which do go to the server) keep their meaning. --}}
                <div class="bnf-filter bnf-filter-search">
                    <label class="field-label" for="recordSearch">Search learner</label>
                    <div class="lg-search">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="search" id="recordSearch" placeholder="Search by name or section" autocomplete="off" aria-label="Search beneficiaries by name or section">
                    </div>
                </div>

                <div class="bnf-filter">
                    <label class="field-label" for="filterSchoolYear">School Year</label>
                    <select class="select" name="school_year" id="filterSchoolYear">
                        @foreach (($fo['school_years'] ?? []) as $year)
                            <option value="{{ $year }}" @selected(($ff['school_year'] ?? '') === $year)>{{ $year }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="bnf-filter">
                    <label class="field-label" for="filterGrade">Grade Level</label>
                    <select class="select" name="grade_level" id="filterGrade">
                        <option value="">All grade levels</option>
                        @foreach (($fo['grades'] ?? []) as $grade)
                            <option value="{{ $grade }}" @selected(($ff['grade_level'] ?? '') === $grade)>{{ $grade }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="bnf-filter">
                    <label class="field-label" for="filterSection">Section</label>
                    <select class="select" name="section" id="filterSection">
                        <option value="">All sections</option>
                        @foreach (($fo['sections'] ?? []) as $section)
                            <option value="{{ $section }}" @selected(($ff['section'] ?? '') === $section)>{{ $section }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="bnf-filter">
                    <label class="field-label" for="filterSex">Sex</label>
                    <select class="select" name="sex" id="filterSex">
                        <option value="">All</option>
                        @foreach (($fo['sexes'] ?? []) as $sexOption)
                            <option value="{{ $sexOption }}" @selected(($ff['sex'] ?? '') === $sexOption)>{{ $sexOption }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="bnf-filter">
                    <label class="field-label" for="filterBaseline">Baseline Nutritional Status</label>
                    <select class="select" name="baseline_status" id="filterBaseline">
                        <option value="">All statuses</option>
                        @foreach (($fo['statuses'] ?? []) as $statusOption)
                            <option value="{{ $statusOption }}" @selected(($ff['baseline_status'] ?? '') === $statusOption)>{{ $statusOption }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="bnf-filter">
                    <label class="field-label" for="filterAttendance">Attendance Status</label>
                    <select class="select" name="attendance" id="filterAttendance">
                        <option value="">All</option>
                        @foreach (($fo['attendance'] ?? []) as $value => $label)
                            <option value="{{ $value }}" @selected(($ff['attendance'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="bnf-filter">
                    <label class="field-label" for="filterBeneficiary">Beneficiary Status</label>
                    <select class="select" name="beneficiary_status" id="filterBeneficiary">
                        <option value="">All</option>
                        @foreach (($fo['beneficiary'] ?? []) as $value => $label)
                            <option value="{{ $value }}" @selected(($ff['beneficiary_status'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="bnf-filter">
                    <label class="field-label" for="filterEndline">Endline Status</label>
                    <select class="select" name="endline_status" id="filterEndline">
                        <option value="">All statuses</option>
                        @foreach (($fo['statuses'] ?? []) as $statusOption)
                            <option value="{{ $statusOption }}" @selected(($ff['endline_status'] ?? '') === $statusOption)>{{ $statusOption }}</option>
                        @endforeach
                        {{-- Not a missing answer but a real one: the learners
                             the endline weigh-in has still to reach. --}}
                        <option value="not_measured" @selected(($ff['endline_status'] ?? '') === 'not_measured')>Not yet measured</option>
                    </select>
                </div>

                <div class="bnf-filter-actions">
                    {{-- Choosing a filter applies it; this submit is the no-JS
                         path, and the script below hides it. --}}
                    <button type="submit" class="btn btn-primary filter-apply">Apply</button>
                    @if ($activeFilters)
                        <a class="btn btn-ghost" href="{{ url()->current() }}?view={{ $segmentView ?? 'all' }}">Clear</a>
                    @endif
                    <span class="toolbar-count" id="recordCount">
                        Showing {{ $total }} of {{ $totalBeforeFilters ?? $total }} {{ ($totalBeforeFilters ?? $total) === 1 ? 'beneficiary' : 'beneficiaries' }}
                    </span>
                </div>
            </form>

            @if ($isPending)
                {{-- Pending Enrollment: the waiting list, with the decision on
                     it. Qualifying is the adviser's measurement; enrolling is
                     the coordinator's, so under this tab the table carries the
                     action rather than only reporting who is waiting.

                     Baseline status is the column that matters here — it is why
                     the learner qualifies at all — so the roll is kept to who
                     they are and why, and nothing else. There is no Status
                     column on purpose: a learner nobody has enrolled has no
                     attendance standing to report.

                     It posts to the same endpoint the enrolment dialog uses, so
                     a learner enrolled from here and one enrolled from there
                     travel the same audited, scoped, idempotent path. --}}
                <div class="bulk-bar" id="pendingBulk"
                    data-enroll-url="{{ route('feedingcor-program.enrollment.store') }}">
                    <span class="bulk-count" id="pendingCount">None selected</span>
                    <button type="button" class="btn btn-primary btn-sm" id="pendingEnrollSelected" disabled>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                        Enroll selected
                    </button>
                </div>
                <p class="flash err bulk-error" id="pendingError" hidden></p>

                <div class="table-card">
                    <div class="table-scroll">
                        <table id="recordsTable" class="bnf-table pending-table">
                            <thead>
                                <tr>
                                    <th class="bnf-check"><input type="checkbox" id="pendingCheckAll" aria-label="Select every learner shown"></th>
                                    <th class="sortable" data-sort="text" tabindex="0" role="button">Student</th>
                                    <th class="sortable" data-sort="text" tabindex="0" role="button">Grade</th>
                                    <th class="sortable" data-sort="text" tabindex="0" role="button">Section</th>
                                    <th class="sortable" data-sort="text" tabindex="0" role="button">Baseline Status</th>
                                    <th class="bnf-action">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($recordRows as $record)
                                    @php
                                        $baselineStatus = $record->baseline_nutritional_status ?: $record->nutritional_status;
                                        $grade = preg_replace('/^grade\s*/i', '', (string) $record->grade_level);
                                    @endphp
                                    <tr data-search="{{ strtolower(trim($record->student_name.' '.$record->section_name)) }}"
                                        @if ($record->id) data-record="{{ $record->id }}" @endif>
                                        <td class="bnf-check">
                                            @if ($record->id)
                                                <input type="checkbox" data-select="{{ $record->id }}" aria-label="Select {{ $record->student_name }}">
                                            @endif
                                        </td>
                                        {{-- The whole cell opens the learner's
                                             record. A learner with no database
                                             row has none to open. --}}
                                        <td class="bnf-name {{ $record->id ? 'is-link' : '' }}">
                                            @if ($record->id)
                                                <a href="{{ route('feedingcor-program.beneficiary', $record->id) }}"><strong>{{ $record->student_name }}</strong></a>
                                            @else
                                                <strong>{{ $record->student_name }}</strong>
                                            @endif
                                        </td>
                                        <td>{{ $grade !== '' ? $grade : '—' }}</td>
                                        <td>{{ $record->section_name }}</td>
                                        <td><span class="badge {{ $statusBadge($baselineStatus) }}">{{ $baselineStatus ?: 'Not set' }}</span></td>
                                        <td class="bnf-action">
                                            @if ($record->id)
                                                <button type="button" class="btn-link-enroll" data-enroll="{{ $record->id }}">Enroll</button>
                                            @else
                                                {{-- No database row yet, so there is nothing to enrol. --}}
                                                <span class="bnf-none">&mdash;</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="table-empty">{{ $activeFilters ? 'No learner matches these filters.' : 'No learner is waiting to be enrolled.' }}</td></tr>
                                @endforelse
                                <tr id="recordsNoMatch" style="display:none;"><td colspan="6" class="table-empty">No learner matches this search.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            @else
            {{-- One line per learner, and only what a coordinator needs to run
                 the programme. Baseline status stays because it is the reason
                 the learner is a beneficiary at all; the BMI figures and the
                 baseline-to-endline delta were a health profile, which is the
                 nurse's screen, not this one. --}}
            <div class="table-card">
                <div class="table-scroll">
                    <table id="recordsTable" class="bnf-table">
                        <thead>
                            <tr>
                                <th class="bnf-idx">#</th>
                                <th class="sortable" data-sort="text" tabindex="0" role="button">Student</th>
                                <th class="sortable" data-sort="text" tabindex="0" role="button">Grade</th>
                                <th class="sortable" data-sort="text" tabindex="0" role="button">Section</th>
                                <th class="sortable" data-sort="text" tabindex="0" role="button">Sex</th>
                                <th class="sortable" data-sort="text" tabindex="0" role="button">Baseline</th>
                                <th class="sortable num" data-sort="number" tabindex="0" role="button">Attendance</th>
                                <th class="sortable" data-sort="text" tabindex="0" role="button">Status</th>
                                <th class="sortable" data-sort="text" tabindex="0" role="button">Endline</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recordRows as $index => $record)
                                @php
                                    $baselineStatus = $record->baseline_nutritional_status ?: $record->nutritional_status;
                                    $endlineStatus = trim((string) $record->endline_nutritional_status);
                                    $rate = $record->attendance_rate ?? null;

                                    // Grade reads as a number in its own column —
                                    // "Grade 7" under a heading that already says
                                    // Grade repeats the word on every row.
                                    $grade = preg_replace('/^grade\s*/i', '', (string) $record->grade_level);

                                    // Three standings, and only one is a warning:
                                    // enrolled and turning up, enrolled and under
                                    // the school's threshold, or still waiting on
                                    // the coordinator's decision.
                                    [$standing, $standingBadge] = match (true) {
                                        ($record->segment ?? '') === 'pending' => ['Pending', 'badge-monitor'],
                                        (bool) ($record->at_risk ?? false) => ['At Risk', 'badge-risk'],
                                        default => ['Active', 'badge-normal'],
                                    };
                                @endphp
                                {{-- The record id rides the row so the masterlist
                                     export can name exactly the learners on
                                     screen, in the order they are on screen. A
                                     session-fallback row has none and is simply
                                     left out of the file. --}}
                                <tr data-search="{{ strtolower(trim($record->student_name.' '.$record->section_name)) }}"
                                    @if ($record->id) data-record-id="{{ $record->id }}" @endif>
                                    <td class="bnf-idx">{{ $index + 1 }}</td>
                                    {{-- The whole name cell opens the learner's
                                         own beneficiary record. A session-fallback
                                         row has no id, so it stays plain text. --}}
                                    <td class="bnf-name {{ $record->id ? 'is-link' : '' }}">
                                        @if ($record->id)
                                            <a href="{{ route('feedingcor-program.beneficiary', $record->id) }}"><strong>{{ $record->student_name }}</strong></a>
                                        @else
                                            <strong>{{ $record->student_name }}</strong>
                                        @endif
                                    </td>
                                    <td>{{ $grade !== '' ? $grade : '—' }}</td>
                                    <td>{{ $record->section_name }}</td>
                                    <td>{{ $record->sex !== '' ? substr($record->sex, 0, 1) : '—' }}</td>
                                    <td><span class="badge {{ $statusBadge($baselineStatus) }}">{{ $baselineStatus ?: 'Not set' }}</span></td>
                                    {{-- No confirmed session is not a 0% turnout. --}}
                                    <td class="num" data-value="{{ $rate ?? '' }}">{{ ! is_null($rate) ? $rate.'%' : '—' }}</td>
                                    <td>
                                        {{-- The warning glyph replaces the badge's
                                             own dot rather than joining it. --}}
                                        <span class="badge {{ $standingBadge }} {{ $standing === 'At Risk' ? 'has-icon' : '' }}">
                                            @if ($standing === 'At Risk')
                                                <svg class="badge-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                                            @endif
                                            {{ $standing }}
                                        </span>
                                    </td>
                                    {{-- An endline nobody has measured yet is a
                                         dash, not a status the programme can act on. --}}
                                    <td>
                                        @if ($endlineStatus !== '')
                                            <span class="badge {{ $statusBadge($endlineStatus) }}">{{ $endlineStatus }}</span>
                                        @else
                                            <span class="bnf-none">&mdash;</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="table-empty">{{ $activeFilters ? 'No beneficiaries match these filters.' : 'No beneficiaries yet.' }}</td></tr>
                            @endforelse
                            <tr id="recordsNoMatch" style="display:none;"><td colspan="9" class="table-empty">No beneficiaries match this search.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            @endif
        </section>

    </div>
</div>
<script>
// Pending Enrollment: the waiting list with the decision on it.
//
// One learner or many, it posts the same record_ids to the same endpoint the
// enrolment dialog uses — so enrolling from this table is the same audited,
// scoped, idempotent act as enrolling from the modal, and there is no second
// way into the database to keep in step.
(() => {
    const bar = document.getElementById('pendingBulk');
    const table = document.getElementById('recordsTable');
    if (!bar || !table) {
        return;
    }

    const body = table.tBodies[0];
    const checkAll = document.getElementById('pendingCheckAll');
    const countLabel = document.getElementById('pendingCount');
    const enrollBtn = document.getElementById('pendingEnrollSelected');
    const errorNote = document.getElementById('pendingError');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    const selected = new Set();
    let busy = false;

    // Only rows still on screen may be selected — a learner hidden by the
    // search is not part of "select all".
    const boxes = () => Array.from(body.querySelectorAll('[data-select]'))
        .filter((box) => box.closest('tr').style.display !== 'none');

    const setError = (message) => {
        if (!errorNote) return;
        errorNote.textContent = message ?? '';
        errorNote.hidden = !message;
    };

    const sync = () => {
        const shown = boxes();
        // A selection whose row has been filtered away is dropped rather than
        // enrolled unseen.
        Array.from(selected).forEach((id) => {
            if (!shown.some((box) => Number(box.dataset.select) === id)) selected.delete(id);
        });

        if (countLabel) {
            countLabel.textContent = selected.size === 0
                ? 'None selected'
                : selected.size + (selected.size === 1 ? ' learner selected' : ' learners selected');
        }
        if (enrollBtn) {
            enrollBtn.disabled = busy || selected.size === 0;
            enrollBtn.textContent = selected.size > 0 ? 'Enroll selected (' + selected.size + ')' : 'Enroll selected';
        }
        if (checkAll) {
            const all = shown.length > 0 && shown.every((box) => selected.has(Number(box.dataset.select)));
            checkAll.checked = all;
            checkAll.indeterminate = !all && shown.some((box) => selected.has(Number(box.dataset.select)));
        }
    };

    const enroll = async (ids) => {
        if (busy || ids.length === 0) {
            return;
        }

        busy = true;
        sync();
        setError(null);
        try {
            const response = await fetch(bar.dataset.enrollUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                credentials: 'same-origin',
                body: JSON.stringify({ record_ids: ids }),
            });

            if (!response.ok) {
                const payload = await response.json().catch(() => ({}));
                setError(payload.message ?? 'Those learners could not be enrolled.');
                return;
            }

            // The enrolled learners leave this view, the cards and the tab
            // counts all move at once — a reload is the one thing that keeps
            // every figure on the page telling the same story.
            window.location.reload();
        } catch (error) {
            setError('Those learners could not be enrolled. Check your connection and try again.');
        } finally {
            busy = false;
            sync();
        }
    };

    checkAll?.addEventListener('change', () => {
        boxes().forEach((box) => {
            box.checked = checkAll.checked;
            const id = Number(box.dataset.select);
            if (checkAll.checked) selected.add(id);
            else selected.delete(id);
        });
        sync();
    });

    body.addEventListener('change', (event) => {
        const box = event.target.closest('[data-select]');
        if (!box) return;
        const id = Number(box.dataset.select);
        if (box.checked) selected.add(id);
        else selected.delete(id);
        sync();
    });

    body.addEventListener('click', (event) => {
        const one = event.target.closest('[data-enroll]');
        if (one) enroll([Number(one.dataset.enroll)]);
    });

    enrollBtn?.addEventListener('click', () => enroll(Array.from(selected)));

    // The search hides rows, which can invalidate a selection.
    document.getElementById('recordSearch')?.addEventListener('input', sync);

    sync();
})();
</script>
@include('partials.feeding-enroll-modal')
@include('partials.role-page-transition')
<script>
// Live cards. The page asks the coordinator's cheap pulse (a stamp, no data)
// on a timer and pays for the re-render only when the stamp moves — so a mark
// recorded at the feeding line, or a learner enrolled from the dashboard,
// shows up here without anyone reloading.
(() => {
    const root = document.getElementById('bnf-page');
    const panes = {
        cards: document.getElementById('bnf-cards'),
        tabs: document.getElementById('bnf-tabs'),
    };
    if (!root || !panes.cards) return;

    // The refresh carries the page's own grade/section filter, so a live update
    // redraws the view on screen rather than replacing it with the whole school.
    const cardsUrl = root.dataset.cardsUrl + window.location.search;
    const PULSE_MS = 20000;
    let stamp = null;
    let inFlight = false;

    const refresh = async () => {
        if (inFlight) return;
        inFlight = true;
        Object.values(panes).forEach((pane) => pane?.classList.add('is-refreshing'));
        try {
            const response = await fetch(cardsUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) return;
            const payload = await response.json();
            // The server renders the same Blade partials the first paint
            // used, so the live view can never drift from a reloaded one.
            Object.keys(panes).forEach((key) => {
                if (panes[key] && typeof payload.html?.[key] === 'string') {
                    panes[key].innerHTML = payload.html[key];
                }
            });
            if (payload.stamp) stamp = payload.stamp;
        } catch (error) {
            // Offline or a dropped request: keep what is on screen and try
            // again on the next pulse.
        } finally {
            inFlight = false;
            Object.values(panes).forEach((pane) => pane?.classList.remove('is-refreshing'));
        }
    };

    const pulse = async () => {
        if (document.hidden || inFlight) return;
        try {
            const response = await fetch(root.dataset.pulseUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) return;
            const payload = await response.json();
            if (!payload.stamp) return;
            // The first pulse only learns where the data stands; the cards on
            // screen were rendered from it a moment ago.
            if (stamp === null) {
                stamp = payload.stamp;
                return;
            }
            if (payload.stamp !== stamp) {
                stamp = payload.stamp;
                await refresh();
                // The enrolment dialog rides the same pulse rather than polling
                // on its own timer: one question asked of the server, two
                // things kept current.
                document.dispatchEvent(new CustomEvent('fc:records-changed'));
            }
        } catch (error) {
            // Ignored — the next pulse retries.
        }
    };

    // Enrolling a learner changes the cards immediately, without waiting for
    // the next pulse to notice.
    document.addEventListener('fc:refresh-request', () => { refresh(); });

    pulse();
    window.setInterval(pulse, PULSE_MS);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) pulse(); });
})();

(() => {
    const form = document.getElementById('recordFilters');
    if (!form) return;
    const grade = document.getElementById('filterGrade');

    // Empty filters are stripped from the URL so the address stays readable and
    // "no filter" has exactly one representation rather than an empty one too.
    const apply = () => {
        const params = new URLSearchParams(new FormData(form));
        Array.from(params.keys()).forEach((key) => {
            if (params.get(key) === '') params.delete(key);
        });
        const query = params.toString();
        window.location.href = window.location.pathname + (query ? '?' + query : '');
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        apply();
    });

    form.querySelectorAll('select').forEach((select) => {
        select.addEventListener('change', () => {
            // Sections belong to a grade, so a new grade invalidates the section
            // already chosen. The server rebuilds the list for the new grade.
            if (select === grade) form.querySelector('#filterSection').value = '';
            apply();
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

    // The row number counts the rows on screen, so it stays 1..n after a sort
    // reorders them or a search hides some. A frozen 1,2,3 next to reordered
    // learners would be a number that means nothing.
    const renumber = () => {
        let n = 0;
        rows.forEach((row) => {
            const cell = row.querySelector('.bnf-idx');
            if (!cell) return;
            cell.textContent = row.style.display === 'none' ? '' : String(++n);
        });
    };

    const applySearch = () => {
        const q = search.value.trim().toLowerCase();
        let shown = 0;
        rows.forEach((row) => {
            const hit = q === '' || row.dataset.search.includes(q);
            row.style.display = hit ? '' : 'none';
            if (hit) shown += 1;
        });
        noMatch.style.display = (rows.length && shown === 0) ? '' : 'none';
        renumber();
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
        renumber();
    };
    heads.forEach((th) => {
        th.addEventListener('click', () => sortBy(th));
        th.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            e.preventDefault();
            sortBy(th);
        });
    });

    // ── Export Masterlist ──────────────────────────────────────────────
    // The file is the school's own DepEd masterlist form — heading, ruled
    // No./Name/Grade/Section table, and the Prepared by / Noted by block — so
    // it can be printed and handed in rather than retyped onto the form.
    //
    // The server writes it, because a form has a shape that comma-separated
    // text cannot carry, and a real .xlsx opens in Excel on any machine; which
    // program opens a .csv is a setting on the reader's computer that this page
    // has no reach into.
    //
    // What is sent is the ordered ids of the rows still on screen — the roster
    // the coordinator filtered, searched and sorted — so what leaves the page is
    // what they were reading. A short-lived form post is used rather than fetch
    // so the browser handles the download itself.
    document.getElementById('exportMasterlistBtn').addEventListener('click', () => {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = @json(route('dashboard.feedingcor-health-records.masterlist'));
        form.style.display = 'none';

        const field = (name, value) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        };

        field('_token', document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '');
        // The year on screen, so the heading names the period being exported.
        field('school_year', new URLSearchParams(window.location.search).get('school_year') ?? '');

        rows.filter((row) => row.style.display !== 'none' && row.dataset.recordId)
            .forEach((row) => field('record_ids[]', row.dataset.recordId));

        document.body.appendChild(form);
        form.submit();
        form.remove();
    });

    // ── Print Masterlist: the same roster on paper. The print stylesheet
    // drops the rail, the toolbar and the buttons, so the sheet carries the
    // header, the five figures and the table and nothing else. A row hidden
    // by the search is hidden on paper too — one roster, two media. ──
    document.getElementById('printMasterlistBtn').addEventListener('click', () => {
        window.print();
    });
})();
</script>
</body>
</html>
