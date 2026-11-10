<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/sigla-mark.png') }}">
    <title>Parent's Consent - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @php $classAdviserCssPath = resource_path('css/class-adviser.css'); @endphp
    @if (file_exists($classAdviserCssPath))
        <style>{!! file_get_contents($classAdviserCssPath) !!}</style>
    @endif
    <style>{!! file_get_contents(resource_path('css/consent-copy.css')) !!}</style>
    {{-- One shared palette for pages not yet on lusog-theme.css. Loaded
         last so it overrides this page's own :root colours. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-palette.css')) !!}</style>
</head>
<body>
@include('partials.adviser-sidebar', ['active' => 'consent'])

<div class="asb-main">
    @include('partials.adviser-topbar', ['breadcrumb' => "Parent's Consent"])
    <div class="content">
        @if (session('success')) <div class="flash flash-ok">{{ session('success') }}</div> @endif
        @if (session('consent_success')) <div class="flash flash-ok">{{ session('consent_success') }}</div> @endif
        @if (session('error')) <div class="flash flash-err">{{ session('error') }}</div> @endif
        @if ($errors->any()) <div class="flash flash-err">{{ $errors->first() }}</div> @endif

        @if ($unreadCount > 0)
            <div class="flash flash-ok"><b>{{ $unreadCount }}</b> consent {{ Str::plural('form', $unreadCount) }} signed by parents since your last visit.</div>
        @endif

        @php
            $pcGradeSection = trim(session('assigned_grade_level', '').' / '.session('assigned_section', ''), ' /') ?: 'Not Assigned';
        @endphp

        <div class="ms-page-header">
            <div>
                <h2 class="ms-page-title">Parent's Consent</h2>
                <p class="ms-page-sub">{{ $pcGradeSection }} &middot; School Year {{ $schoolYear }}</p>
            </div>
            {{-- Consents are filed per school year and kept after it ends, so
                 an earlier year's forms are one choice away rather than gone
                 from the page on the first of June. --}}
            <form method="GET" class="pc-year" id="consentYearForm">
                <label for="consentYear">School year</label>
                <select name="school_year" id="consentYear" class="ms-filter-select" onchange="this.form.submit()">
                    @foreach ($schoolYears as $year)
                        <option value="{{ $year }}" @selected($schoolYear === $year)>S.Y. {{ $year }}{{ $year === $currentSchoolYear ? ' (current)' : '' }}</option>
                    @endforeach
                </select>
                <noscript><button type="submit" class="btn btn-secondary">Show</button></noscript>
            </form>
        </div>

        {{-- An earlier year is a record being read, not a list to work from:
             nothing here can be uploaded or changed, and a new consent is
             always filed under the current year. --}}
        @unless ($isCurrentYear)
            <div class="pc-strip pc-past-year" id="pastYearNotice">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <span>
                    <b>S.Y. {{ $schoolYear }} has ended.</b> These are your class's consent records as they were when
                    the year closed, with the signed forms that were uploaded. They are read-only.
                </span>
                <a href="{{ route('consent-forms.index') }}" class="pc-strip-link">Back to S.Y. {{ $currentSchoolYear }}</a>
            </div>
        @endunless

        <div class="pc-banner">
            <div>
                <h2 class="pc-banner-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M14.5 18.5c1-1.5 2-1.5 3 0s2 1.5 3 0"/></svg>
                    Sulat-Pahibalo
                </h2>
                <p class="pc-banner-sub">{{ $stats['pending'] }} awaiting parent response &middot; {{ $stats['declined'] }} declined</p>
            </div>
            <div class="pc-banner-chips">
                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><b>{{ $stats['rate'] }}%</b> Consent Rate</span>
                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg><b>{{ $stats['with_consent'] }}</b>/{{ $stats['total'] }} Students</span>
            </div>
        </div>

        <div class="ms-stats-bar">
            <div class="ms-stat">
                <div class="ms-stat-icon ms-icon-complete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
                <div><div class="ms-stat-number">{{ $stats['approved'] }}</div><div class="ms-stat-label">Approved</div></div>
            </div>
            <div class="ms-stat">
                <div class="ms-stat-icon ms-icon-pending"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg></div>
                <div><div class="ms-stat-number">{{ $stats['partial'] }}</div><div class="ms-stat-label">Partial Consent</div></div>
            </div>
            <div class="ms-stat">
                <div class="ms-stat-icon ms-icon-alert"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div>
                <div><div class="ms-stat-number">{{ $stats['declined'] }}</div><div class="ms-stat-label">Refused</div></div>
            </div>
            <div class="ms-stat">
                <div class="ms-stat-icon ms-icon-total"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
                <div><div class="ms-stat-number">{{ $stats['pending'] }}</div><div class="ms-stat-label">Pending Upload</div></div>
            </div>
        </div>

        <article class="ms-table-container">
            <div class="ms-table-header">
                <h3>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h6"/><polyline points="14 2 14 8 20 8"/><path d="M14.5 18.5c1-1.5 2-1.5 3 0s2 1.5 3 0"/><path d="M12 16c1.5-3.5 3-5 4-3.5s-1 3.5-2 5"/></svg>
                    Student Consent Records
                    <span class="ms-count-badge" id="consentCountBadge">{{ $stats['total'] }}</span>
                </h3>
                <div class="ms-table-actions">
                    <div class="ms-search-wrapper">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input id="consentSearch" class="ms-search-input" type="text" placeholder="Search by name or LRN..." autocomplete="off">
                    </div>
                    {{-- The same two questions the nurse's list asks — did this
                         parent agree, or not — worded the same way, so one
                         answer does not read as two different things on two
                         screens. "Agreed" covers the parent who agreed *except*
                         for certain services: they did consent, and the precise
                         answers stay below it because the next question about an
                         exception is always which services it covered.
                         "Awaiting response" is the adviser's own: their list is
                         one row per learner, so the ones still to reply are the
                         chase list, and the nurse never sees those at all. --}}
                    <select id="consentStatusFilter" class="ms-filter-select" aria-label="Filter by the parent's answer">
                        <option value="all">All students</option>
                        <option value="agreed">Agreed to the consent</option>
                        <option value="declined">Refused</option>
                        <option value="approved">&mdash; Agreed to all services</option>
                        <option value="partial">&mdash; Agreed, with exceptions</option>
                        <option value="pending">Awaiting response</option>
                    </select>
                    @if ($isCurrentYear)
                        <button type="button" class="btn" id="openUploadConsent" data-consent-upload>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            Upload Consent
                        </button>
                    @endif
                </div>
            </div>

            <div class="ms-table-scroll">
                <table class="ms-table">
                    <thead>
                        <tr>
                            <th>LRN</th>
                            <th>Student Name</th>
                            <th>Guardian</th>
                            <th>Consent Status</th>
                            <th>Date</th>
                            <th>Form</th>
                            <th>Med Cert</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="consentTableBody">
                        @php
                            $statusLabels = ['approved' => 'Valid Consent', 'partial' => 'Valid Consent', 'declined' => 'Refused', 'pending' => 'Pending Upload'];
                        @endphp
                        @forelse ($rows as $row)
                            @php
                                $form = $row['form'];
                                // Every learner starts the school year at No form
                                // yet: nothing has been sent or uploaded for them
                                // this year (the school-year handover). Still the
                                // pending state underneath, so filters and counts
                                // are unchanged.
                                $rowStatusLabel = match (true) {
                                    $row['status'] === 'pending' && $form === null && $row['upload'] === null => 'No form yet',
                                    // Missing the parent's name or signature:
                                    // filed, and gives no service.
                                    $row['status'] === 'pending' && (bool) $row['upload']?->incomplete => 'Incomplete',
                                    default => $statusLabels[$row['status']],
                                };
                            @endphp
                            <tr class="js-consent-row"
                                data-name="{{ strtolower($row['name']) }}"
                                data-lrn="{{ strtolower($row['lrn']) }}"
                                data-status="{{ $row['status'] }}">
                                <td><strong>{{ $row['lrn'] !== '' ? $row['lrn'] : '-' }}</strong></td>
                                <td class="ms-student-name">{{ $row['name'] }}</td>
                                <td>{{ $row['parent_guardian'] !== '' ? $row['parent_guardian'] : '-' }}</td>
                                <td>
                                    <span class="ms-badge ms-consent-{{ $row['status'] }}">{{ $rowStatusLabel }}</span>
                                    @if ($form && $form->adviser_unread)<span class="ms-badge ms-consent-declined" style="margin-left:5px;">New</span>@endif
                                </td>
                                <td>{{ $row['dated_at'] ?? '-' }}</td>
                                <td>
                                    @if ($row['has_form_file'])
                                        <span class="pc-tick pc-tick-on" title="Signed form uploaded">&#10003;</span>
                                    @else
                                        <span class="pc-tick" title="No uploaded form">&ndash;</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($row['has_med_cert'])
                                        <span class="pc-tick pc-tick-on" title="Medical certificate attached">&#10003;</span>
                                    @else
                                        <span class="pc-tick" title="No medical certificate">&ndash;</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="ms-actions">
                                        <button type="button" class="ms-act ms-act-view js-consent-view" title="View Details" aria-label="View Details" data-lrn="{{ $row['lrn'] }}">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </button>
                                        @if ($isCurrentYear)
                                            <button type="button" class="ms-act ms-act-consent js-consent-upload" title="Upload Form" aria-label="Upload Form" data-consent-upload data-lrn="{{ $row['lrn'] }}">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr class="js-consent-empty">
                                <td colspan="8">
                                    <div class="ms-empty-state">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                        @if ($isCurrentYear)
                                            <h4>No Learners Yet</h4>
                                            <p>Enrol learners on My Students before preparing their consent forms.</p>
                                            <a class="btn" href="{{ route('dashboard.class-adviser', ['tab' => 'saved']) }}">Go to My Students</a>
                                        @else
                                            <h4>No Learners in S.Y. {{ $schoolYear }}</h4>
                                            <p>Nobody was enrolled in {{ $pcGradeSection }} that school year.</p>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                        <tr class="js-consent-nomatch" hidden>
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
                    Showing <strong id="pcShowingStart">0</strong> to <strong id="pcShowingEnd">0</strong> of <strong id="pcShowingTotal">0</strong> learners
                </div>
                <div class="ms-pagination" id="pcPagination"></div>
            </div>
        </article>
    </div>
</div>

{{-- ── Upload the signed Sulat-Pahibalo ─────────────────────────────── --}}
{{-- Current year only: an upload is always filed under the current school
     year, so offering it while an earlier year is on screen would file the
     form somewhere other than the list the adviser is looking at. The same
     dialog opens from a learner's own profile, on its Consent tab. --}}
@if ($isCurrentYear)
    @include('consent-forms._upload-modal', ['uploadStudents' => $rows])
@endif
{{-- ── Consent details (read-only) ──────────────────────────────────── --}}
{{-- One learner's form, read back the way the clinic reads it
     (NurseConsentList::reading): the photo the adviser uploaded beside what
     was recorded from it. Each learner's panel is rendered here and cloned in
     on open, so the photo is fetched — through the audited, role-checked
     download — only when somebody opens it. --}}
<div class="confirm-overlay pc-view" id="consentDetailModal" aria-hidden="true">
    <div class="confirm-modal pc-view-modal" role="dialog" aria-modal="true" aria-labelledby="pcViewName">
        <div class="pc-view-body" id="consentDetailBody"></div>
    </div>
</div>

@foreach ($rows as $row)
    @php
        $reading = $row['reading'];
        $photo = $reading['photo'];
        $letter = $reading['letter'];
        $upload = $row['upload'];
        $filed = $reading['filed'];
        $hasAnswer = $reading['answer'] !== null;
        $hasForm = $hasAnswer || $photo['url'] !== null || $photo['online'];
        $shownUpload = $reading['source'] === 'upload' ? $upload : null;
    @endphp
    <template class="pc-view-source" data-view-for="{{ $row['lrn'] }}">
        <div class="pc-view-head">
            <div>
                <h3 class="pc-view-name" id="pcViewName">{{ $row['name'] }}</h3>
                <p class="pc-view-sub">
                    {{ $row['class_line'] !== '' ? $row['class_line'] : 'Class not recorded' }}
                    &middot; Adviser: {{ $adviserName !== '' ? $adviserName : 'not on record' }}
                </p>
                <p class="pc-view-standing">
                    All services &middot; SY {{ $schoolYear }}:
                    <span class="pc-std is-{{ $reading['status'] }}">{{ \App\Support\ConsentStanding::SERVICE_LABELS[$reading['status']] }}</span>
                </p>
            </div>
            <button type="button" class="btn btn-secondary pc-view-close" data-view-close>Close</button>
        </div>

        @if (! $hasForm)
            <div class="pc-view-empty">
                <p><b>No form yet.</b></p>
                {{-- An answer with no scan behind it authorises nothing, so it
                     is said rather than shown as a consent. --}}
                @if ($row['recorded_response'] !== null && ! $row['has_form_file'])
                    <p>Recorded as <b>{{ $row['recorded_response'] }}</b>, but the signed form has not been uploaded.</p>
                @endif
                @if ($letter !== null && $letter->status === \App\Models\HealthConsentForm::STATUS_SENT)
                    <p>A letter was sent to the parent on {{ \App\Support\SchoolClock::local($letter->sent_at)?->format('M j, Y') }} and has not been answered.</p>
                @endif
            </div>
        @else
            <div class="pc-view-grid">
                <section class="pc-view-form">
                    <div class="pc-view-section-head">
                        <h4>{{ $photo['online'] ? 'Signed online' : 'Uploaded form' }} &middot; SY {{ $schoolYear }}</h4>
                        @if ($photo['url'] !== null)
                            <a class="btn btn-secondary pc-view-enlarge" href="{{ $photo['url'] }}" target="_blank" rel="noopener">{{ $photo['is_image'] ? 'Enlarge' : 'Open' }}</a>
                        @endif
                    </div>
                    @if ($photo['url'] !== null && $photo['is_image'])
                        <figure class="pc-view-photo">
                            <img src="{{ $photo['url'] }}" alt="Photo of the signed Sulat-Pahibalo for {{ $row['name'] }}">
                            @if ($simulatedData) <span class="pc-view-mark" aria-hidden="true">SAMPLE · FICTITIOUS DATA</span> @endif
                        </figure>
                    @elseif ($photo['url'] !== null)
                        <iframe class="pc-view-doc" src="{{ $photo['url'] }}" title="Signed Sulat-Pahibalo for {{ $row['name'] }}"></iframe>
                    @else
                        <p class="pc-view-note">The parent signed this letter online, so there is no photograph.</p>
                        @if ($letter !== null)
                            <a class="btn btn-secondary" href="{{ route('consent-forms.show', $letter) }}">Open the full letter</a>
                        @endif
                    @endif
                </section>

                <section class="pc-view-read">
                    <h4>{{ $photo['online'] ? 'As the parent answered online' : 'Read from the uploaded photo' }}</h4>
                    @if (! $hasAnswer)
                        <p class="pc-view-flash is-warn">The parent's answer on this form has not been recorded.</p>
                    @elseif ($reading['missing'] === [])
                        <p class="pc-view-flash is-ok">&#10003; All required fields completed</p>
                    @else
                        <p class="pc-view-flash is-err">Missing from this form: {{ implode(', ', $reading['missing']) }}.</p>
                    @endif
                    @if ($reading['copy'] !== null)
                        @include('consent-forms._paper-copy', ['copy' => $reading['copy']])
                    @endif
                    @if ($hasAnswer && $shownUpload !== null && ($shownUpload->medical_cert_attached || filled($shownUpload->notes)))
                        <dl class="pc-view-kv">
                            @if ($shownUpload->medical_cert_attached)
                                <div class="pc-view-row"><dt>Medical certificate</dt><dd>{{ $shownUpload->med_cert_original_name ?: 'Attached' }}</dd></div>
                            @endif
                            @if (filled($shownUpload->notes))
                                <div class="pc-view-row"><dt>Notes</dt><dd>{{ $shownUpload->notes }}</dd></div>
                            @endif
                        </dl>
                    @endif
                </section>
            </div>

            @if ($filed !== null)
                <p class="pc-view-foot">
                    {{ $filed['how'] }} by {{ $filed['by'] }}{{ $filed['on'] !== '' ? ' on '.$filed['on'] : '' }}.
                    Valid for all service cycles in SY {{ $schoolYear }}.
                    Only the class adviser can upload or replace this form.
                </p>
            @endif
        @endif
    </template>
@endforeach

<script>
// Table: search + status filter + pagination, mirroring My Students.
(() => {
    const searchInput = document.getElementById('consentSearch');
    const statusSelect = document.getElementById('consentStatusFilter');
    const tbody = document.getElementById('consentTableBody');

    if (!searchInput || !statusSelect || !tbody) {
        return;
    }

    const rows = Array.from(tbody.querySelectorAll('.js-consent-row'));
    const noMatchRow = tbody.querySelector('.js-consent-nomatch');
    const pagination = document.getElementById('pcPagination');
    const startOut = document.getElementById('pcShowingStart');
    const endOut = document.getElementById('pcShowingEnd');
    const totalOut = document.getElementById('pcShowingTotal');
    const countBadge = document.getElementById('consentCountBadge');
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
        const status = statusSelect.value;

        matches = rows.filter((row) => {
            const name = row.dataset.name || '';
            const lrn = row.dataset.lrn || '';
            const keywordMatch = !keyword || name.includes(keyword) || lrn.includes(keyword);
            // Every choice is a list of standings, so the grouped "agreed"
            // and the precise ones are matched by one line rather than by a
            // special case that could disagree with the label above it.
            const wanted = status === 'all'
                ? null
                : (status === 'agreed' ? ['approved', 'partial'] : [status]);
            const statusMatch = wanted === null || wanted.includes(row.dataset.status || '');

            return keywordMatch && statusMatch;
        });

        page = 1;
        render();
    };

    searchInput.addEventListener('input', applyFilters);
    statusSelect.addEventListener('change', applyFilters);
    applyFilters();
})();

// Details
// Each learner's panel is server-rendered into a <template>; opening one
// clones it in, and closing empties the dialog so the photo goes with it.
// The upload dialog carries its own script (consent-forms._upload-modal).
(() => {
    const detailModal = document.getElementById('consentDetailModal');
    const detailBody = document.getElementById('consentDetailBody');
    if (!detailModal || !detailBody) return;

    let returnFocus = null;
    const open = () => {
        returnFocus = document.activeElement;
        detailModal.classList.add('open');
        detailModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    };
    const closeDetail = () => {
        if (!detailModal.classList.contains('open')) return;
        detailModal.classList.remove('open');
        detailModal.setAttribute('aria-hidden', 'true');
        detailBody.textContent = '';
        if (!document.querySelector('.confirm-overlay.open')) document.body.style.overflow = '';
        returnFocus?.focus();
    };

    const panels = new Map();
    document.querySelectorAll('template.pc-view-source').forEach((tpl) => panels.set(tpl.dataset.viewFor, tpl));

    document.querySelectorAll('.js-consent-view').forEach((button) => {
        button.addEventListener('click', () => {
            const tpl = panels.get(button.dataset.lrn || '');
            if (!tpl) return;
            detailBody.replaceChildren(tpl.content.cloneNode(true));
            open();
            detailBody.querySelector('[data-view-close]')?.focus();
        });
    });

    detailModal.addEventListener('click', (event) => {
        if (event.target === detailModal || event.target.closest('[data-view-close]')) {
            closeDetail();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (!detailModal.classList.contains('open')) return;
        if (event.key === 'Escape') {
            closeDetail();
            return;
        }
        if (event.key !== 'Tab') return;
        const focusable = Array.from(detailModal.querySelectorAll('button, a[href], input, select, textarea, [tabindex="0"]'))
            .filter((node) => !node.disabled && node.getClientRects().length > 0);
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault(); last?.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault(); first?.focus();
        }
    });
})();
</script>
@include('partials.workspace-live')
</body>
</html>
