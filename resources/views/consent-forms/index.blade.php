<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Parent's Consent - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @php $classAdviserCssPath = resource_path('css/class-adviser.css'); @endphp
    @if (file_exists($classAdviserCssPath))
        <style>{!! file_get_contents($classAdviserCssPath) !!}</style>
    @endif
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
        </div>

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
                    <select id="consentStatusFilter" class="ms-filter-select" aria-label="Filter by consent status">
                        <option value="all">All Students</option>
                        <option value="approved">Approved</option>
                        <option value="partial">Partial</option>
                        <option value="declined">Declined</option>
                        <option value="pending">Pending Upload</option>
                    </select>
                    <button type="button" class="btn" id="openUploadConsent">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        Upload Consent
                    </button>
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
                                $detail = [
                                    'name' => $row['name'],
                                    'lrn' => $row['lrn'],
                                    'guardian' => $row['parent_guardian'] ?: null,
                                    'status' => $row['status'],
                                    'status_label' => $statusLabels[$row['status']],
                                    'school_year' => $schoolYear,
                                    'dated_at' => $row['dated_at'],
                                    'notes' => $row['notes'],
                                    'has_form_file' => $row['has_form_file'],
                                    'has_med_cert' => $row['has_med_cert'],
                                    'file_name' => $row['file_name'],
                                    'med_cert_name' => $row['med_cert_name'],
                                    'recorded_response' => $row['recorded_response'],
                                ];
                            @endphp
                            <tr class="js-consent-row"
                                data-name="{{ strtolower($row['name']) }}"
                                data-lrn="{{ strtolower($row['lrn']) }}"
                                data-status="{{ $row['status'] }}"
                                data-detail='@json($detail)'>
                                <td><strong>{{ $row['lrn'] !== '' ? $row['lrn'] : '-' }}</strong></td>
                                <td class="ms-student-name">{{ $row['name'] }}</td>
                                <td>{{ $row['parent_guardian'] !== '' ? $row['parent_guardian'] : '-' }}</td>
                                <td>
                                    <span class="ms-badge ms-consent-{{ $row['status'] }}">{{ $statusLabels[$row['status']] }}</span>
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
                                        <button type="button" class="ms-act ms-act-view js-consent-view" title="View Details" aria-label="View Details">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </button>
                                        <button type="button" class="ms-act ms-act-consent js-consent-upload" title="Upload Form" aria-label="Upload Form" data-lrn="{{ $row['lrn'] }}">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr class="js-consent-empty">
                                <td colspan="8">
                                    <div class="ms-empty-state">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                        <h4>No Learners Yet</h4>
                                        <p>Enrol learners on My Students before preparing their consent forms.</p>
                                        <a class="btn" href="{{ route('dashboard.class-adviser', ['tab' => 'saved']) }}">Go to My Students</a>
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

                <div class="pc-field">
                    <label for="pc_school_year">School Year <span class="pc-req">*</span></label>
                    <div class="pc-input">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <input id="pc_school_year" type="text" value="{{ $schoolYear }} (Current)" readonly>
                    </div>
                </div>

                <div class="pc-field">
                    <label>Consent Status <span class="pc-req">*</span></label>
                    <p class="pc-hint">Based on what the parent indicated on the signed form:</p>
                    <div class="pc-choices">
                        <label class="pc-choice selected">
                            <input type="radio" name="consent_type" value="full" checked>
                            <span><b>Full Consent</b>Parent checked &ldquo;Oo, ako mutugot&rdquo;</span>
                        </label>
                        <label class="pc-choice">
                            <input type="radio" name="consent_type" value="partial">
                            <span><b>Partial Consent</b>Parent checked &ldquo;gawas lamang niini&rdquo;</span>
                        </label>
                        <label class="pc-choice">
                            <input type="radio" name="consent_type" value="refused">
                            <span><b>Declined</b>Parent checked &ldquo;Dili ko mutugot&rdquo;</span>
                        </label>
                    </div>
                </div>

                <div class="pc-field" id="pcPartialField" hidden>
                    <label for="pc_partial_exception">Services not consented to</label>
                    <div class="pc-input">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                        <input id="pc_partial_exception" name="partial_exception" type="text" maxlength="500">
                    </div>
                </div>

                <div class="pc-field" id="pcRefusedField" hidden>
                    <label for="pc_refused_reason">Reason for declining</label>
                    <div class="pc-input">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                        <input id="pc_refused_reason" name="refused_reason" type="text" maxlength="500">
                    </div>
                </div>

                <div class="pc-field">
                    <label>Upload Signed Sulat-Pahibalo Form <span class="pc-req">*</span></label>
                    <div class="pc-drop" id="pcDrop" role="button" tabindex="0">
                        <div id="pcDropBody">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/><polyline points="12 16 12 11"/><polyline points="9.5 13 12 10.5 14.5 13"/></svg>
                            <p>Click to upload or drag and drop</p>
                            <p class="pc-drop-hint">PNG, JPG, or PDF (Max 5MB)</p>
                        </div>
                    </div>
                    <input type="file" id="pc_file" name="consent" accept=".png,.jpg,.jpeg,.pdf" hidden>
                </div>

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

{{-- ── Consent details (read-only) ──────────────────────────────────── --}}
<div class="confirm-overlay pc-modal" id="consentDetailModal" aria-hidden="true">
    <div class="confirm-modal" role="dialog" aria-modal="true" aria-label="Consent details">
        <div class="confirm-head">
            <div class="confirm-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h6"/><polyline points="14 2 14 8 20 8"/><path d="M14.5 18.5c1-1.5 2-1.5 3 0s2 1.5 3 0"/><path d="M12 16c1.5-3.5 3-5 4-3.5s-1 3.5-2 5"/></svg>
                Consent Details
                <small>Sulat-Pahibalo</small>
            </div>
            <button type="button" class="confirm-close" id="consentDetailClose">&times;</button>
        </div>
        <div class="confirm-body">
            <div class="pc-detail-grid">
                <div><span>Student Name</span><b id="cdName">-</b></div>
                <div><span>LRN</span><b id="cdLrn">-</b></div>
                <div><span>Guardian</span><b id="cdGuardian">-</b></div>
                <div><span>School Year</span><b><span class="pc-sy-badge" id="cdYear">-</span></b></div>
                <div><span>Date Uploaded</span><b id="cdDate">-</b></div>
                <div><span>Notes</span><b id="cdNotes">-</b></div>
            </div>

            <div class="pc-strip" id="cdStatusStrip">
                <span class="ms-badge" id="cdStatusBadge">-</span>
                <span class="pc-strip-note" id="cdFormState">-</span>
            </div>

            {{-- Shown when an answer was recorded but its signed scan is not in
                 yet, so the recorded answer is never mistaken for consent. --}}
            <div class="pc-strip pc-strip-warn" id="cdAwaitingStrip" hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                Recorded as <b id="cdRecorded">-</b>, but the signed form has not been uploaded.
            </div>

            <div class="pc-strip pc-strip-ok" id="cdFileStrip" hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Uploaded Form: <b id="cdFileName">-</b>
            </div>

            <div class="pc-strip pc-strip-ok" id="cdMedCertStrip" hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
                Medical Certificate: <b id="cdMedCertName">-</b>
            </div>

            <div class="confirm-actions">
                <button type="button" class="btn btn-secondary" id="consentDetailDone">Close</button>
            </div>
        </div>
    </div>
</div>

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
            const statusMatch = status === 'all' || (row.dataset.status || '') === status;

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
        const partialField = document.getElementById('pcPartialField');
        const refusedField = document.getElementById('pcRefusedField');
        const syncChoice = () => {
            const choice = uploadModal.querySelector('input[name="consent_type"]:checked')?.value;
            if (partialField) partialField.hidden = choice !== 'partial';
            if (refusedField) refusedField.hidden = choice !== 'refused';
            uploadModal.querySelectorAll('.pc-choice').forEach((label) => {
                label.classList.toggle('selected', label.querySelector('input')?.checked === true);
            });
        };

        uploadModal.querySelectorAll('input[name="consent_type"]').forEach((radio) => {
            radio.addEventListener('change', syncChoice);
        });

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
                    showFile(files[0]);
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

        uploadForm?.addEventListener('submit', (event) => {
            if (!consentFile || (consentFile.files && consentFile.files.length > 0)) {
                return;
            }
            event.preventDefault();
            consentDrop?.classList.add('invalid');
            consentDrop?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        });

        const openUpload = (lrn) => {
            document.getElementById('uploadConsentForm')?.reset();
            resetConsentDrop();
            resetMedCertDrop();
            if (studentSelect && lrn) studentSelect.value = lrn;
            syncChoice();
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

        syncChoice();
    }

    // ── Details ─────────────────────────────────────────────────────
    if (detailModal) {
        const setText = (id, value) => {
            const node = document.getElementById(id);
            if (node) {
                node.textContent = value === null || value === undefined || value === '' ? '-' : String(value);
            }
        };

        document.querySelectorAll('.js-consent-view').forEach((button) => {
            button.addEventListener('click', () => {
                let detail = {};
                try {
                    detail = JSON.parse(button.closest('tr')?.getAttribute('data-detail') || '{}');
                } catch (_err) {
                    detail = {};
                }

                setText('cdName', detail.name);
                setText('cdLrn', detail.lrn);
                setText('cdGuardian', detail.guardian);
                setText('cdYear', detail.school_year);
                setText('cdDate', detail.dated_at);
                setText('cdNotes', detail.notes);

                const badge = document.getElementById('cdStatusBadge');
                if (badge) {
                    badge.textContent = detail.status_label || '-';
                    badge.className = 'ms-badge ms-consent-' + (detail.status || 'pending');
                }
                setText('cdFormState', detail.has_form_file ? 'Form uploaded' : 'Awaiting signed form');

                // An answer with no scan behind it is reported as pending, so
                // say what was recorded rather than dropping it silently.
                const awaiting = document.getElementById('cdAwaitingStrip');
                if (awaiting) {
                    const unbacked = !detail.has_form_file && Boolean(detail.recorded_response);
                    awaiting.hidden = !unbacked;
                    setText('cdRecorded', detail.recorded_response);
                }

                const fileStrip = document.getElementById('cdFileStrip');
                if (fileStrip) {
                    fileStrip.hidden = !detail.has_form_file;
                    // Advisers may not download consent scans, so file names are
                    // shown as text and never as links.
                    setText('cdFileName', detail.file_name || 'Uploaded file');
                }

                const medStrip = document.getElementById('cdMedCertStrip');
                if (medStrip) {
                    medStrip.hidden = !detail.has_med_cert;
                    setText('cdMedCertName', detail.med_cert_name || 'Attached');
                }

                open(detailModal);
            });
        });

        document.getElementById('consentDetailClose')?.addEventListener('click', () => close(detailModal));
        document.getElementById('consentDetailDone')?.addEventListener('click', () => close(detailModal));
        detailModal.addEventListener('click', (event) => {
            if (event.target === detailModal) {
                close(detailModal);
            }
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            if (uploadModal) close(uploadModal);
            if (detailModal) close(detailModal);
        }
    });
})();
</script>
</body>
</html>
