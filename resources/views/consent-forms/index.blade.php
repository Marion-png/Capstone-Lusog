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
                <div><div class="ms-stat-number">{{ $stats['declined'] }}</div><div class="ms-stat-label">Declined</div></div>
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
                        <option value="declined">Did not agree</option>
                        <option value="approved">&mdash; Agreed to all services</option>
                        <option value="partial">&mdash; Agreed, with exceptions</option>
                        <option value="pending">Awaiting response</option>
                    </select>
                    @if ($isCurrentYear)
                        <button type="button" class="btn" id="openUploadConsent">
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
                            $statusLabels = ['approved' => 'Approved', 'partial' => 'Partial', 'declined' => 'Declined', 'pending' => 'Pending Upload'];
                        @endphp
                        @forelse ($rows as $row)
                            @php
                                $form = $row['form'];
                                // Every learner starts the school year at No form
                                // yet: nothing has been sent or uploaded for them
                                // this year (the school-year handover). Still the
                                // pending state underneath, so filters and counts
                                // are unchanged.
                                $rowStatusLabel = ($row['status'] === 'pending' && $form === null && $row['upload'] === null)
                                    ? 'No form yet'
                                    : $statusLabels[$row['status']];
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
                                            <button type="button" class="ms-act ms-act-consent js-consent-upload" title="Upload Form" aria-label="Upload Form" data-lrn="{{ $row['lrn'] }}">
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
     form somewhere other than the list the adviser is looking at. --}}
@if ($isCurrentYear)
<div class="confirm-overlay pc-modal" id="uploadConsentModal" aria-hidden="true">
    <div class="confirm-modal" role="dialog" aria-modal="true" aria-label="Upload Sulat-Pahibalo">
        <div class="confirm-head">
            <div class="confirm-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                Upload Sulat-Pahibalo
                <small>Parent's Consent Form</small>
            </div>
            <button type="button" class="confirm-close" id="uploadConsentClose">&times;</button>
        </div>
        <form method="POST" action="{{ route('parental-consent.store') }}" enctype="multipart/form-data" id="uploadConsentForm">
            @csrf
            <div class="confirm-body">
                <div class="pc-field">
                    <label for="pc_lrn">Select Student <span class="pc-req">*</span></label>
                    <div class="pc-input">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                        <select id="pc_lrn" name="lrn" required>
                            <option value="">Select Student</option>
                            @foreach ($rows as $row)
                                <option value="{{ $row['lrn'] }}">{{ $row['name'] }} ({{ $row['lrn'] }})</option>
                            @endforeach
                        </select>
                    </div>
                    <p class="pc-hint">Select the student whose parent signed the consent form.</p>
                </div>

                {{-- Two forms come back from parents, and they answer different
                     questions: the Sulat-Pahibalo covers the health services, the
                     feeding program consent is what lets the Feeding Coordinator
                     enrol a learner in the SBFP. They are filed apart so neither
                     is ever read as the other. --}}
                <div class="pc-field">
                    <label for="pc_program">Which form is this? <span class="pc-req">*</span></label>
                    <div class="pc-input">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        <select id="pc_program" name="program">
                            @foreach (\App\Models\ParentalConsentForm::PROGRAMS as $programValue => $programLabel)
                                <option value="{{ $programValue }}" @selected(old('program', \App\Models\ParentalConsentForm::PROGRAM_HEALTH_SERVICES) === $programValue)>{{ $programLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <p class="pc-hint">A learner measured as wasted can be enrolled in the feeding program only once their parent's signed feeding program consent is filed here.</p>
                </div>

                <div class="pc-field">
                    <label for="pc_school_year">School Year <span class="pc-req">*</span></label>
                    <div class="pc-input">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <input id="pc_school_year" type="text" value="{{ $schoolYear }} (Current)" readonly>
                    </div>
                </div>

                <div class="pc-field">
                    <label id="pcFileLabel">Upload Signed Sulat-Pahibalo Form <span class="pc-req">*</span></label>
                    <div class="pc-drop" id="pcDrop" role="button" tabindex="0">
                        <div id="pcDropBody">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/><polyline points="12 16 12 11"/><polyline points="9.5 13 12 10.5 14.5 13"/></svg>
                            <p>Click to upload or drag and drop</p>
                            <p class="pc-drop-hint">PNG, JPG, or PDF (Max 5MB)</p>
                        </div>
                    </div>
                    <input type="file" id="pc_file" name="consent" accept=".png,.jpg,.jpeg,.pdf" hidden>
                </div>

                {{-- What the guardian answered. It sits under the file because
                     the photo is what the answer is read off — by the adviser,
                     or by the consent reader where one is set up.

                     This was once left out, on the reasoning that two screens
                     asking the same question is how they come to disagree. The
                     answer is still recorded in full on Record Signed Paper
                     Form, field by field and attested — but leaving it out here
                     meant a filed form read as PENDING forever, so the school
                     could see that a consent had come back and never whether it
                     said yes or no.

                     They cannot disagree, because ConsentStanding::for() does
                     not treat them as equals: the parent's own answer, or an
                     attested paper record, is read FIRST and returned, and this
                     is consulted only when there is no answered form. It is the
                     fallback that was always empty, not a second opinion.

                     Optional on purpose. An adviser filing a stack of forms
                     must never be made to guess, and "not recorded yet" is a
                     real answer here exactly as it is in the scanner: an upload
                     with no answer still reads as PENDING and authorises
                     nothing. --}}
                <div class="pc-field">
                    <label for="pc_consent_type">What did the guardian answer? <span class="pc-optional" id="pcAnswerOptional">(Optional)</span></label>
                    @if ($scannerReady)
                        <div class="pc-read">
                            <button type="button" class="btn btn-secondary" id="pcReadAnswer" disabled>Read the answer from the photo</button>
                            <span class="pc-read-state" id="pcReadState" aria-live="polite">Attach a photo of the signed form first.</span>
                        </div>
                        <div class="pc-copy" id="pcCopy" hidden></div>
                    @endif
                    <div class="pc-input">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                        <select id="pc_consent_type" name="consent_type">
                            <option value="" id="pcNotRecordedOption">Not recorded yet</option>
                            <option value="full" @selected(old('consent_type') === 'full')
                                data-feeding-label="Agreed &mdash; the parent consents to the feeding program">Full consent &mdash; agreed to every service</option>
                            <option value="partial" id="pcPartialOption" @selected(old('consent_type') === 'partial')>Partial consent &mdash; agreed, with exceptions</option>
                            <option value="refused" @selected(old('consent_type') === 'refused')
                                data-feeding-label="Declined &mdash; the parent does not consent">Declined &mdash; did not agree</option>
                        </select>
                    </div>
                    {{-- One span, so the hint's flex layout keeps the bold words
                         inside the sentence instead of splitting it into columns. --}}
                    <p class="pc-hint" id="pcAnswerHint"><span>
                        Read it off the signed form. Leave it as <strong>Not recorded yet</strong> if you are
                        filing the document now and will read it later &mdash; the form still counts as returned,
                        but no service may be given until an answer is on file.
                    </span></p>
                    @if ($scannerReady)
                        {{-- The reader's answer goes into the select above and is
                             filed only once the adviser ticks this — the server
                             refuses it otherwise. Choosing an answer by hand
                             makes it the adviser's own and hides the box again. --}}
                        <input type="hidden" name="answer_from_scan" id="pcAnswerFromScan" value="0">
                        <label class="pc-confirm" id="pcAnswerConfirm" hidden>
                            <input type="checkbox" name="answer_confirmed" value="1" id="pcAnswerConfirmed">
                            <span>I checked this answer against the signed form.</span>
                        </label>
                    @endif
                </div>

                {{-- What the rest of the paper says, so View Details can read
                     the form back as it was signed rather than as one word.
                     The services are the ones ticked in the letter's list; an
                     upload with none ticked reads as the program it is filed
                     for, as uploads always did. --}}
                <fieldset class="pc-field pc-fieldset">
                    <legend>Services listed on the form</legend>
                    <div class="pc-services" id="pcServices">
                        <label class="pc-check pc-check-all"><input type="checkbox" id="pcServicesAll"> All services</label>
                        @foreach ($plannedServices as $key => $label)
                            <label class="pc-check">
                                <input type="checkbox" name="services[]" value="{{ $key }}" data-parent="{{ \App\Models\HealthConsentForm::parentService($key) }}">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div class="pc-field" id="pcPartialField" hidden>
                    <label for="pc_partial_exception">Services the parent excepted</label>
                    <div class="pc-input is-plain">
                        <input id="pc_partial_exception" name="partial_exception" type="text" maxlength="500" autocomplete="off">
                    </div>
                </div>

                <div class="pc-field" id="pcRefusedField" hidden>
                    <label for="pc_refused_reason">Reason given</label>
                    <div class="pc-input is-plain">
                        <input id="pc_refused_reason" name="refused_reason" type="text" maxlength="500" autocomplete="off">
                    </div>
                </div>

                <fieldset class="pc-field pc-fieldset">
                    <legend>Allergies and illnesses</legend>
                    <div class="pc-writeins">
                        @foreach ([
                            'allergy_food_detail' => 'Food allergy',
                            'allergy_medicine_detail' => 'Medicine allergy',
                            'prev_immunization_detail' => 'Previous immunization',
                            'other_illness_detail' => 'Other illnesses',
                        ] as $field => $caption)
                            <label class="pc-writein">
                                <span>{{ $caption }}</span>
                                <span class="pc-input is-plain"><input name="{{ $field }}" type="text" maxlength="255" autocomplete="off"></span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div class="pc-field">
                    <label>Medical Certificate <span class="pc-optional">(Optional)</span></label>
                    <p class="pc-hint">Upload if the parent attached a medical certificate with the consent form.</p>
                    <div class="pc-drop" id="pcMedDrop" role="button" tabindex="0">
                        <div id="pcMedDropBody">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/><polyline points="12 16 12 11"/><polyline points="9.5 13 12 10.5 14.5 13"/></svg>
                            <p>Click to upload Medical Certificate</p>
                            <p class="pc-drop-hint">PNG, JPG, or PDF (Max 5MB)</p>
                        </div>
                    </div>
                    <input type="file" id="pc_med_cert" name="medical_certificate" accept=".png,.jpg,.jpeg,.pdf" hidden>
                </div>

                <div class="pc-field">
                    <label for="pc_notes">Additional Notes <span class="pc-optional">(Optional)</span></label>
                    <div class="pc-input">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                        <textarea id="pc_notes" name="notes" rows="2" maxlength="1000" placeholder="Any additional notes about this consent form..."></textarea>
                    </div>
                </div>

                <div class="confirm-actions">
                    <button type="button" class="btn btn-secondary" id="uploadConsentCancel">Cancel</button>
                    <button type="submit" class="btn">Upload Consent</button>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
    // The feeding program consent is a yes or a no on its own signed form: no
    // "except", no "not recorded yet", and no photo reader, which reads the
    // Sulat-Pahibalo. The server holds the same rules whatever is drawn here.
    (function () {
        const program = document.getElementById('pc_program');
        const answer = document.getElementById('pc_consent_type');
        if (!program || !answer) return;

        const feeding = @json(\App\Models\ParentalConsentForm::PROGRAM_FEEDING);
        const fileLabel = document.getElementById('pcFileLabel');
        const read = document.querySelector('#uploadConsentModal .pc-read');
        const confirmBox = document.getElementById('pcAnswerConfirm');
        const fromScan = document.getElementById('pcAnswerFromScan');
        const swapped = [document.getElementById('pcPartialOption'), document.getElementById('pcNotRecordedOption')];
        const relabelled = Array.from(answer.querySelectorAll('option[data-feeding-label]'));
        relabelled.forEach((option) => { option.dataset.healthLabel = option.textContent; });

        const sync = () => {
            const isFeeding = program.value === feeding;

            swapped.forEach((option) => {
                if (!option) return;
                option.hidden = isFeeding;
                option.disabled = isFeeding;
            });
            if (isFeeding && (answer.value === 'partial' || answer.value === '')) answer.value = 'full';

            relabelled.forEach((option) => {
                option.textContent = isFeeding ? option.dataset.feedingLabel : option.dataset.healthLabel;
            });
            answer.required = isFeeding;

            ['pcAnswerOptional', 'pcAnswerHint'].forEach((id) => {
                const el = document.getElementById(id);
                if (el) el.hidden = isFeeding;
            });
            if (read) read.hidden = isFeeding;
            const copy = document.getElementById('pcCopy');
            if (copy) copy.hidden = isFeeding || copy.innerHTML.trim() === '';
            if (isFeeding) {
                if (confirmBox) confirmBox.hidden = true;
                if (fromScan) fromScan.value = '0';
            }
            if (fileLabel) {
                fileLabel.firstChild.textContent = isFeeding
                    ? 'Upload the Signed Feeding Program Consent '
                    : 'Upload Signed Sulat-Pahibalo Form ';
            }
        };

        program.addEventListener('change', sync);
        sync();
    })();
</script>
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
                    <p>A letter was sent to the parent on {{ optional($letter->sent_at)->format('M j, Y') }} and has not been answered.</p>
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

// Modals
(() => {
    const open = (modal) => {
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
    };
    const close = (modal) => {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
    };

    const uploadModal = document.getElementById('uploadConsentModal');
    const detailModal = document.getElementById('consentDetailModal');

    // ── Upload ──────────────────────────────────────────────────────
    if (uploadModal) {
        const studentSelect = document.getElementById('pc_lrn');

        // Both drop zones behave identically: click, keyboard, or drag a file in.
        const wireDropZone = (dropId, bodyId, inputId) => {
            const drop = document.getElementById(dropId);
            const body = document.getElementById(bodyId);
            const input = document.getElementById(inputId);

            if (!drop || !body || !input) {
                return () => {};
            }

            const emptyMarkup = body.innerHTML;

            const showFile = (file) => {
                body.textContent = '';
                const name = document.createElement('p');
                name.className = 'pc-drop-file';
                name.textContent = file.name;
                body.appendChild(name);
            };

            drop.addEventListener('click', () => input.click());
            drop.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    input.click();
                }
            });
            drop.addEventListener('dragover', (event) => {
                event.preventDefault();
                drop.classList.add('dragging');
            });
            drop.addEventListener('dragleave', () => drop.classList.remove('dragging'));
            drop.addEventListener('drop', (event) => {
                event.preventDefault();
                drop.classList.remove('dragging');
                const files = event.dataTransfer?.files;
                if (files && files.length) {
                    input.files = files;
                    // Setting .files fires nothing, and everything listening
                    // for a new file (the name, the reader) listens on change.
                    input.dispatchEvent(new Event('change'));
                }
            });
            input.addEventListener('change', () => {
                const file = input.files && input.files[0];
                if (file) {
                    showFile(file);
                }
            });

            // form.reset() clears the input but not the rendered file name.
            return () => { body.innerHTML = emptyMarkup; };
        };

        const resetConsentDrop = wireDropZone('pcDrop', 'pcDropBody', 'pc_file');
        const resetMedCertDrop = wireDropZone('pcMedDrop', 'pcMedDropBody', 'pc_med_cert');

        // The signed form is marked required, so don't let the modal create a
        // record with no scan behind it — those can only ever read as pending.
        const uploadForm = document.getElementById('uploadConsentForm');
        const consentDrop = document.getElementById('pcDrop');
        const consentFile = document.getElementById('pc_file');

        consentFile?.addEventListener('change', () => consentDrop?.classList.remove('invalid'));

        // ── What the rest of the paper says ──────────────────────────
        const answerField = document.getElementById('pc_consent_type');
        const servicesBox = document.getElementById('pcServices');
        const servicesAll = document.getElementById('pcServicesAll');
        const serviceBoxes = Array.from(uploadForm?.querySelectorAll('input[name="services[]"]') || []);
        const answerLines = { partial: document.getElementById('pcPartialField'), refused: document.getElementById('pcRefusedField') };

        // The exception line belongs to a partial consent and the reason to a
        // refusal; the server keeps only the one the answer calls for.
        const syncAnswerLines = () => {
            Object.entries(answerLines).forEach(([answer, field]) => {
                if (field) field.hidden = answerField?.value !== answer;
            });
        };

        const syncAllServices = () => {
            if (!servicesAll) return;
            const ticked = serviceBoxes.filter((box) => box.checked).length;
            servicesAll.checked = ticked === serviceBoxes.length;
            servicesAll.indeterminate = ticked > 0 && ticked < serviceBoxes.length;
            if (ticked > 0) servicesBox?.classList.remove('invalid');
        };

        answerField?.addEventListener('change', syncAnswerLines);
        serviceBoxes.forEach((box) => box.addEventListener('change', syncAllServices));
        servicesAll?.addEventListener('change', () => {
            serviceBoxes.forEach((box) => { box.checked = servicesAll.checked; });
            syncAllServices();
        });

        uploadForm?.addEventListener('submit', (event) => {
            if (consentFile && !(consentFile.files && consentFile.files.length > 0)) {
                event.preventDefault();
                consentDrop?.classList.add('invalid');
                consentDrop?.scrollIntoView({ block: 'center', behavior: 'smooth' });
                return;
            }

            // An agreement is an agreement to the services the letter lists,
            // so it is not filed until they are ticked.
            const agreed = ['full', 'partial'].includes(answerField?.value || '');
            if (agreed && !serviceBoxes.some((box) => box.checked)) {
                event.preventDefault();
                servicesBox?.classList.add('invalid');
                servicesBox?.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        });

        // ── Reading the answer off the photo ─────────────────────────
        // The consent reader proposes; the adviser records. Its answer lands
        // in the same select an adviser would set by hand, and is filed only
        // once they tick the confirmation — the server refuses it otherwise.
        // Only rendered where a reader is configured.
        const readButton = document.getElementById('pcReadAnswer');
        const readState = document.getElementById('pcReadState');
        const answerSelect = document.getElementById('pc_consent_type');
        const answerFromScan = document.getElementById('pcAnswerFromScan');
        const answerConfirm = document.getElementById('pcAnswerConfirm');
        const answerConfirmed = document.getElementById('pcAnswerConfirmed');
        const copyBox = document.getElementById('pcCopy');

        // The sheet as the reader saw it, drawn by the server in the form's
        // own words. It belongs to one photo and one learner, like the rest.
        const showCopy = (html) => {
            if (!copyBox) return;
            copyBox.innerHTML = html || '';
            copyBox.hidden = !html;
        };

        const setReaderAnswer = (fromScan) => {
            if (!answerFromScan || !answerConfirm || !answerConfirmed) return;
            answerFromScan.value = fromScan ? '1' : '0';
            answerConfirm.hidden = !fromScan;
            answerConfirmed.required = fromScan;
            answerConfirmed.checked = false;
        };

        // The upload takes a PDF too, but the reader reads photographs only.
        const readable = (file) => Boolean(file) && ['image/jpeg', 'image/png'].includes(file.type);

        // The reader checks the name on the sheet against the learner the
        // answer will be filed for, so it reads once one is chosen.
        const chosenLearner = () => studentSelect?.value || '';

        const syncReader = () => {
            if (!readButton || !readState) return;
            const file = consentFile?.files && consentFile.files[0];
            readButton.disabled = !readable(file) || chosenLearner() === '';
            readState.textContent = !file
                ? 'Attach a photo of the signed form first.'
                : (!readable(file)
                    ? 'Only a JPG or PNG photo can be read. Choose the answer from the paper.'
                    : (chosenLearner() === '' ? 'Select the student first.' : ''));
        };

        // The written lines the reader transcribes, keyed by the field each
        // one fills here.
        const writtenFields = {
            consent_exceptions: 'partial_exception',
            refusal_reason: 'refused_reason',
            allergy_food: 'allergy_food_detail',
            allergy_medicine: 'allergy_medicine_detail',
            prev_immunization: 'prev_immunization_detail',
            other_illness: 'other_illness_detail',
        };
        let filledFromScan = false;

        const fillFromReading = (draft) => {
            const services = draft.services || {};
            const ticked = (key) => services[key] === 'yes';

            // A sub-item is read off its own box; a ticked heading with none
            // of its own sub-items ticked covers them all, as on the letter.
            serviceBoxes.forEach((box) => {
                const parent = box.dataset.parent || '';
                const siblingTicked = parent !== '' && serviceBoxes.some((other) => other.dataset.parent === parent && ticked(other.value));
                box.checked = ticked(box.value) || (parent !== '' && ticked(parent) && !siblingTicked);
            });
            syncAllServices();

            Object.entries(writtenFields).forEach(([from, to]) => {
                const input = uploadForm?.querySelector('[name="' + to + '"]');
                if (input) input.value = (draft.written && draft.written[from] && draft.written[from].text) || '';
            });
            filledFromScan = true;
        };

        const clearReading = () => {
            if (!filledFromScan) return;
            serviceBoxes.forEach((box) => { box.checked = false; });
            syncAllServices();
            Object.values(writtenFields).forEach((name) => {
                const input = uploadForm?.querySelector('[name="' + name + '"]');
                if (input) input.value = '';
            });
            filledFromScan = false;
        };

        if (readButton && answerSelect) {
            // A reading belongs to the photo it was taken from, and to the
            // learner it was checked against.
            const dropReading = () => {
                showCopy(null);
                if (answerFromScan?.value === '1') {
                    answerSelect.value = '';
                    setReaderAnswer(false);
                    syncAnswerLines();
                }
                clearReading();
                syncReader();
            };

            consentFile?.addEventListener('change', dropReading);
            studentSelect?.addEventListener('change', dropReading);

            // Choosing by hand makes the answer the adviser's own.
            answerSelect.addEventListener('change', () => setReaderAnswer(false));

            const choiceToAnswer = { all: 'full', specific: 'partial', deny: 'refused' };

            readButton.addEventListener('click', async () => {
                const file = consentFile?.files && consentFile.files[0];
                const lrn = chosenLearner();
                if (!readable(file) || lrn === '') return;

                readButton.disabled = true;
                readState.textContent = 'Reading the form…';
                showCopy(null);

                const body = new FormData();
                body.append('photo', file);
                body.append('lrn', lrn);

                try {
                    const response = await fetch(@json(route('consent-forms.scan')), {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                            Accept: 'application/json',
                        },
                        body,
                    });
                    const data = await response.json().catch(() => ({}));

                    // The adviser swapped the photo, or the learner, while it
                    // was being read.
                    if (!consentFile.files || consentFile.files[0] !== file || chosenLearner() !== lrn) return;

                    if (!response.ok) {
                        readState.textContent = data.message || 'The form could not be read. Choose the answer from the paper.';
                        return;
                    }

                    const draft = data.draft || {};
                    const answer = choiceToAnswer[draft.consent_choice] || '';

                    // Unclear is never a guess either way: the select goes back
                    // to "Not recorded yet" and the reason is said instead.
                    answerSelect.value = answer;
                    setReaderAnswer(answer !== '');
                    syncAnswerLines();

                    // Another child's sheet, or one nothing could be read
                    // off, fills nothing in.
                    clearReading();
                    if (!draft.unreadable && !draft.learner_mismatch) {
                        fillFromReading(draft);
                    }
                    showCopy(data.copy_html);

                    if (draft.unreadable) {
                        readState.textContent = 'The photo could not be read. Choose the answer from the paper.';
                    } else if (answer === '') {
                        readState.textContent = draft.consent_reason || 'The answer could not be made out. Choose it from the paper.';
                    } else {
                        const label = answerSelect.options[answerSelect.selectedIndex]?.text || answer;
                        readState.textContent = 'Read as "' + label + '". Check it against the paper before uploading.'
                            + (draft.signature_present ? '' : ' No parent\'s signature was found on the photo.');
                    }
                } catch (_err) {
                    readState.textContent = 'The form could not be read. Choose the answer from the paper.';
                } finally {
                    readButton.disabled = !readable(consentFile?.files && consentFile.files[0]) || chosenLearner() === '';
                }
            });
        }

        const openUpload = (lrn) => {
            document.getElementById('uploadConsentForm')?.reset();
            resetConsentDrop();
            resetMedCertDrop();
            setReaderAnswer(false);
            filledFromScan = false;
            showCopy(null);
            syncAnswerLines();
            syncAllServices();
            servicesBox?.classList.remove('invalid');
            if (studentSelect && lrn) studentSelect.value = lrn;
            syncReader();
            open(uploadModal);
        };

        document.getElementById('openUploadConsent')?.addEventListener('click', () => openUpload(''));
        document.querySelectorAll('.js-consent-upload').forEach((button) => {
            button.addEventListener('click', () => openUpload(button.dataset.lrn || ''));
        });

        document.getElementById('uploadConsentClose')?.addEventListener('click', () => close(uploadModal));
        document.getElementById('uploadConsentCancel')?.addEventListener('click', () => close(uploadModal));
        uploadModal.addEventListener('click', (event) => {
            if (event.target === uploadModal) {
                close(uploadModal);
            }
        });
    }

    // ── Details ─────────────────────────────────────────────────────
    // Each learner's panel is server-rendered into a <template>; opening one
    // clones it in, and closing empties the dialog so the photo goes with it.
    const detailBody = document.getElementById('consentDetailBody');
    const closeDetail = () => {
        if (!detailModal) return;
        close(detailModal);
        if (detailBody) detailBody.textContent = '';
    };

    if (detailModal && detailBody) {
        const panels = new Map();
        document.querySelectorAll('template.pc-view-source').forEach((tpl) => panels.set(tpl.dataset.viewFor, tpl));

        document.querySelectorAll('.js-consent-view').forEach((button) => {
            button.addEventListener('click', () => {
                const tpl = panels.get(button.dataset.lrn || '');
                if (!tpl) return;
                detailBody.replaceChildren(tpl.content.cloneNode(true));
                open(detailModal);
                detailBody.querySelector('[data-view-close]')?.focus();
            });
        });

        detailModal.addEventListener('click', (event) => {
            if (event.target === detailModal || event.target.closest('[data-view-close]')) {
                closeDetail();
            }
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            if (uploadModal) close(uploadModal);
            closeDetail();
        }
    });
})();
</script>
@include('partials.workspace-live')
</body>
</html>
