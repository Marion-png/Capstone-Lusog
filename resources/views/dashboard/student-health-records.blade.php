<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Health Records - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
        @php $pageCssPath = resource_path('css/school-nurse-student-health-records.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
</head>
<body>
@php
    // Keyed by raw session index so "Fill Medical Record" always opens the
    // learner whose row it was rendered on (see NurseController::dedupedRoster).
    $records = \App\Http\Controllers\NurseController::dedupedRoster(session('school_health_card_records', []));

    $pendingCount = collect($records)->filter(fn ($row) => empty($row['examination']))->count();
    $doneCount = collect($records)->filter(fn ($row) => ! empty($row['examination']))->count();

    // Filter chips are built from what is actually on the roster, so no chip
    // can ever match zero learners.
    $gradeOptions = collect($records)
        ->map(fn ($row) => trim((string) ($row['grade_level'] ?? '')))
        ->filter()
        ->countBy()
        ->sortKeys();

    $sectionOptions = collect($records)
        ->map(fn ($row) => trim((string) ($row['section'] ?? '')))
        ->filter()
        ->countBy()
        ->sortKeys();

    $sexOptions = collect($records)
        ->map(fn ($row) => trim((string) ($row['gender'] ?? '')))
        ->filter()
        ->countBy()
        ->sortKeys();
@endphp
@include('partials.nurse-sidebar', ['active' => 'records'])

<div class="main">
    <header class="topbar">
        <div class="topbar-breadcrumb">
            <a href="{{ route('dashboard.school-nurse') }}" class="bc-home">Dashboard</a>
            <span class="bc-sep">&rsaquo;</span>
            <span class="bc-current">Health Records</span>
        </div>
        <div class="topbar-chip"><div class="dot"></div>School Nurse</div>
        @include('partials.live-clock')
    </header>

    <div class="content">
        {{-- ── List view ─────────────────────────────────────────────── --}}
        <div id="recordsListView">
            <div class="page-eyebrow">School Health Card Workflow</div>
            <h1 class="page-title">Student <span>Health Profiles</span></h1>
            <p class="page-sub">This page displays submitted adviser forms. Consultation records are handled in Consultation Log.</p>

            <div class="cards">
                <div class="mini-card"><div class="val">{{ count($records) }}</div><div class="lbl">Total Submissions</div></div>
                <div class="mini-card"><div class="val">{{ $pendingCount }}</div><div class="lbl">Pending School Nurse Examination</div></div>
                <div class="mini-card"><div class="val">{{ $doneCount }}</div><div class="lbl">Examined by School Nurse</div></div>
            </div>

            <section class="shr-filter">
                <div class="shr-filter-head">
                    <div class="shr-filter-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg>
                        Filter Students
                        <span class="shr-filter-total" id="shrTotalLabel">{{ count($records) }} {{ Str::plural('student', count($records)) }}</span>
                    </div>
                    <div class="shr-search">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" id="shrSearch" placeholder="Search by name or LRN..." autocomplete="off">
                    </div>
                </div>

                <div class="shr-filter-row">
                    <div class="shr-filter-group">
                        <span class="shr-filter-label">Grade Level</span>
                        <div class="shr-filter-btns" id="shrGradeBtns">
                            <button type="button" class="shr-fbtn active" data-filter="grade" data-value="all">All</button>
                            @foreach ($gradeOptions as $grade => $count)
                                <button type="button" class="shr-fbtn" data-filter="grade" data-value="{{ $grade }}">
                                    {{ $grade }}<span class="shr-count">{{ $count }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="shr-filter-group">
                        <span class="shr-filter-label">Sex</span>
                        <div class="shr-filter-btns" id="shrSexBtns">
                            <button type="button" class="shr-fbtn active" data-filter="sex" data-value="all">All</button>
                            @foreach ($sexOptions as $sex => $count)
                                <button type="button" class="shr-fbtn {{ strtolower($sex) === 'male' ? 'is-male' : (strtolower($sex) === 'female' ? 'is-female' : '') }}" data-filter="sex" data-value="{{ strtolower($sex) }}">
                                    {{ $sex }}<span class="shr-count">{{ $count }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="shr-sections" id="shrSectionBtns">
                    <button type="button" class="shr-sbtn active" data-filter="section" data-value="all">All Sections</button>
                    @foreach ($sectionOptions as $section => $count)
                        <button type="button" class="shr-sbtn" data-filter="section" data-value="{{ strtolower($section) }}">
                            {{ $section }}<span class="shr-count">{{ $count }}</span>
                        </button>
                    @endforeach
                </div>
            </section>

            <article class="shr-table-card">
                <div class="shr-table-head">
                    <h4>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                        Student Records
                        <span class="shr-count-badge" id="shrCountBadge">{{ count($records) }}</span>
                    </h4>
                    <div class="shr-showing">Showing: <b id="shrShowingLabel">All Students</b></div>
                </div>

                <div class="shr-scroll">
                    <table class="shr-table">
                        <thead>
                            <tr>
                                <th>LRN</th>
                                <th>Student Name</th>
                                <th>Sex</th>
                                <th>Age</th>
                                <th>Section</th>
                                <th>Health Status</th>
                                <th>Profile</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="shrTableBody">
                            @forelse ($records as $index => $record)
                                @php
                                    $middle = trim((string) ($record['middle_name'] ?? ''));
                                    $middleInitial = $middle !== '' ? (' ' . strtoupper(substr($middle, 0, 1)) . '.') : '';
                                    $fullName = trim(trim(($record['last_name'] ?? '') . ', ' . ($record['first_name'] ?? '') . $middleInitial), ', ');
                                    $rowLrn = trim((string) ($record['lrn'] ?? ''));
                                    $rowGrade = trim((string) ($record['grade_level'] ?? ''));
                                    $rowSection = trim((string) ($record['section'] ?? ''));
                                    $rowSex = trim((string) ($record['gender'] ?? ''));
                                    $rowHealth = trim((string) ($record['nutritional_status_bmi_for_age'] ?? ''));
                                    $examined = ! empty($record['examination']);
                                @endphp
                                <tr class="js-record-row"
                                    data-record='@json($record, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)'
                                    data-route="{{ route('nurse.examine', $index) }}"
                                    data-search="{{ strtolower($fullName . ' ' . $rowLrn) }}"
                                    data-grade="{{ $rowGrade }}"
                                    data-section="{{ strtolower($rowSection) }}"
                                    data-sex="{{ strtolower($rowSex) }}">
                                    <td><strong>{{ $rowLrn !== '' ? $rowLrn : '-' }}</strong></td>
                                    <td class="shr-name">{{ $fullName !== '' ? $fullName : '-' }}</td>
                                    <td>{{ $rowSex !== '' ? $rowSex : '-' }}</td>
                                    <td>{{ $record['age'] ?? '-' }}</td>
                                    <td>{{ $rowSection !== '' ? $rowSection : '-' }}</td>
                                    <td>{{ $rowHealth !== '' ? $rowHealth : 'Not assessed' }}</td>
                                    <td>
                                        <span class="shr-status {{ $examined ? 'is-done' : 'is-pending' }}">{{ $examined ? 'Examined' : 'Pending' }}</span>
                                    </td>
                                    <td>
                                        <button type="button" class="shr-action js-view-profile">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            View Profile
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr class="js-records-empty">
                                    <td colspan="8">
                                        <div class="shr-empty">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                                            <h4>No Adviser Submissions Yet</h4>
                                            <p>Learner health cards appear here once a Class Adviser submits them.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                            <tr class="js-records-nomatch" hidden>
                                <td colspan="8">
                                    <div class="shr-empty">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                        <h4>No Students Found</h4>
                                        <p>No learners match your current grade, sex, section, or search filters.</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </article>
        </div>

    </div>{{-- /.content --}}
</div>{{-- /.main --}}

{{-- ── Student profile — same modal presentation as the Class Adviser's
     view-profile, so one learner reads the same way in both roles. ── --}}
<div class="profile-backdrop" id="profileBackdrop" aria-hidden="true">
    <div class="profile-modal student-profile-modal" role="dialog" aria-modal="true" aria-label="Student Profile">
        <div class="student-profile-topline">
            <button type="button" class="student-profile-back" id="profileClose" aria-label="Back to student list">&larr;</button>
            <div>
                <div class="student-profile-titleline">Student Profile</div>
                <div class="student-profile-subline">View only &mdash; use Fill Medical Record to record an examination</div>
            </div>
        </div>

        <div class="sp-cover"></div>

        <div class="sp-identity">
            <div class="sp-avatar" id="pAvatar">&ndash;</div>
            <div class="sp-identity-body">
                <div class="sp-name" id="pName">-</div>
                <div class="sp-class" id="pGrade">-</div>
                <div class="sp-meta">
                    <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><circle cx="8.5" cy="10" r="2"/><path d="M5 17c.7-1.7 2-2.5 3.5-2.5S11.3 15.3 12 17"/><line x1="15" y1="9" x2="19" y2="9"/><line x1="15" y1="13" x2="19" y2="13"/></svg>LRN: <b id="pLrn">-</b></span>
                    <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="10" cy="14" r="5"/><path d="M19 5l-5.4 5.4M15 5h4v4"/></svg>Sex: <b id="pSex">-</b></span>
                    <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Age: <b id="pAge">-</b></span>
                    <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>DOB: <b id="pDob">-</b></span>
                </div>
            </div>
            {{-- The nurse also records the examination, so Print sits beside it. --}}
            <div class="sp-identity-actions">
                <button type="button" class="btn btn-secondary" id="profilePrint">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Print
                </button>
                <a href="#" id="profileFillLink" class="btn btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>
                    Fill Medical Record
                </a>
            </div>
        </div>

        <div class="sp-tabs" role="tablist" aria-label="Student profile sections">
            <button type="button" class="sp-tab active" role="tab" aria-selected="true" data-panel="p-sheet1">Sheet 1 <span class="sp-tab-badge">Learner Info</span></button>
            <button type="button" class="sp-tab" role="tab" aria-selected="false" data-panel="p-sheet2">Sheet 2 <span class="sp-tab-badge">Systems Review</span></button>
            <button type="button" class="sp-tab" role="tab" aria-selected="false" data-panel="p-consent">Consent <span class="sp-tab-badge" id="pConsentBadge">&ndash;</span></button>
            <button type="button" class="sp-tab" role="tab" aria-selected="false" data-panel="p-clinic-notes">Clinic Notes <span class="sp-tab-badge" id="pNotesBadge">0</span></button>
            <button type="button" class="sp-tab" role="tab" aria-selected="false" data-panel="p-consultation">Consultation Log <span class="sp-tab-badge" id="pConsultBadge">0</span></button>
            <button type="button" class="sp-tab" role="tab" aria-selected="false" data-panel="p-documents">Documents <span class="sp-tab-badge" id="pDocsBadge">0</span></button>
        </div>
        <div class="student-profile-body">
            <section id="p-sheet1" class="sp-panel active">
                <div class="profile-grid">
                    <div class="student-profile-section">
                        <h4>Personal Information</h4>
                        <div class="kv"><div class="k">Full Name:</div><div class="v" id="pdName">-</div></div>
                        <div class="kv"><div class="k">LRN:</div><div class="v" id="pdLrn">-</div></div>
                        <div class="kv"><div class="k">Date of Birth:</div><div class="v" id="pdDob">-</div></div>
                        <div class="kv"><div class="k">Birthplace:</div><div class="v" id="pdBirthplace">-</div></div>
                        <div class="kv"><div class="k">Address:</div><div class="v" id="pdAddress">-</div></div>
                    </div>
                    <div class="student-profile-section">
                        <h4>Parent/Guardian Information</h4>
                        <div class="kv"><div class="k">Parent/Guardian:</div><div class="v" id="pdGuardian">-</div></div>
                        <div class="kv"><div class="k">Contact Number:</div><div class="v" id="pdContact">-</div></div>
                        <div class="kv"><div class="k">Region/Division:</div><div class="v" id="pdRegionDivision">-</div></div>
                    </div>
                </div>

                <div class="student-profile-section">
                    <h4>Medical &amp; Family History</h4>
                    <div id="pdHealthHistory"></div>
                </div>

                <div class="student-profile-section">
                    <h4>SHD Form 2 Snapshot</h4>
                    <div class="kv"><div class="k">Grade Level:</div><div class="v" id="psGrade">-</div></div>
                    <div class="kv"><div class="k">Examination:</div><div class="v" id="psStatus">-</div></div>
                    <div class="kv"><div class="k">Medical Alerts:</div><div class="v" id="paStatus">Pending School Nurse review.</div></div>
                </div>

                <div class="student-profile-section">
                    <h4>Growth &amp; Nutrition</h4>
                    <div class="kv"><div class="k">Height:</div><div class="v" id="pgHeight">-</div></div>
                    <div class="kv"><div class="k">Weight:</div><div class="v" id="pgWeight">-</div></div>
                    <div class="growth-chart-wrap">
                        <div class="growth-chart-head">
                            <div class="growth-chart-title">Growth Over Time</div>
                            <div class="growth-delta" id="pgDelta" style="display:none;"></div>
                        </div>

                        <div class="growth-empty" id="pgAwaitingExam" style="display:none;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
                            <p>Baseline measurement recorded. A growth comparison will appear here once the School Nurse completes an endline examination.</p>
                        </div>

                        <div id="pgChartBody">
                            <svg id="pgTrendChart" class="growth-chart" viewBox="0 0 520 180" aria-label="Growth and nutrition line chart">
                                <line x1="48" y1="20" x2="500" y2="20" class="growth-grid-line" />
                                <line x1="48" y1="150" x2="500" y2="150" class="growth-grid-line growth-grid-baseline" />
                                <line x1="48" y1="85" x2="500" y2="85" class="growth-grid-line" />

                                <polyline id="pgHeightLine" class="growth-line-height" points="" />
                                <rect id="pgWeightBarStart" class="growth-bar-weight" x="119" y="130" width="24" height="20" rx="7" />
                                <rect id="pgWeightBarEnd" class="growth-bar-weight" x="379" y="124" width="24" height="26" rx="7" />

                                <circle id="pgHeightStart" class="growth-dot-height" r="5" cx="130" cy="120" />
                                <circle id="pgHeightEnd" class="growth-dot-height" r="5" cx="390" cy="96" />

                                <text x="130" y="168" class="growth-axis-label" text-anchor="middle">Baseline</text>
                                <text x="390" y="168" class="growth-axis-label" text-anchor="middle">Latest</text>

                                <text id="pgHeightStartLabel" x="130" y="108" class="growth-value-label growth-value-height">-</text>
                                <text id="pgHeightEndLabel" x="390" y="84" class="growth-value-label growth-value-height">-</text>
                                <text id="pgWeightStartLabel" x="131" y="122" class="growth-value-label growth-value-weight">-</text>
                                <text id="pgWeightEndLabel" x="391" y="116" class="growth-value-label growth-value-weight">-</text>
                            </svg>
                            <div class="growth-legend">
                                <span><i class="legend-dot height"></i>Height (cm)</span>
                                <span><i class="legend-dot weight"></i>Weight (kg)</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
                <div class="student-profile-section">
                    <h4>Health History <span style="font-size:.72rem;font-weight:400;color:var(--text-3);">(across school years)</span></h4>
                    <div id="pHistoryList">
                        <div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">Select a student to view history.</div></div>
                    </div>
                </div>
            </section>

            <section id="p-sheet2" class="sp-panel">
                <div class="student-profile-section">
                    <h4>Systems Review</h4>
                    <div id="pdSystemsReview"></div>
                </div>
                <div class="student-profile-section">
                    <h4>Health Assessment <span style="font-size:.72rem;font-weight:400;color:var(--text-3);">(MLHAT)</span></h4>
                    <div id="phaStatus">
                        <div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">Select a student to view assessment.</div></div>
                    </div>
                </div>
            </section>

            <section id="p-consent" class="sp-panel">
                <div class="student-profile-section">
                    <h4>Parental Consent &mdash; Health Services (Sulat-Pahibalo)</h4>
                    <div id="pcConsentStatus">
                        <div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">Select a student to view consent status.</div></div>
                    </div>
                </div>
            </section>

            <section id="p-clinic-notes" class="sp-panel">
                <div class="student-profile-section">
                    <h4>Add Clinic Note</h4>
                    <form id="clinicNoteForm" class="cn-form" autocomplete="off">
                        @csrf
                        <input type="hidden" name="lrn" id="cnLrn" value="">
                        <label class="cn-label" for="cnNote">Note <span style="color:var(--red);">*</span></label>
                        <textarea id="cnNote" name="note" rows="3" maxlength="5000" required placeholder="Clinical observation, follow-up, or recommendation"></textarea>
                        <div class="cn-row">
                            <div>
                                <label class="cn-label" for="cnFollowUp">Follow-up Date</label>
                                <input type="date" id="cnFollowUp" name="follow_up_date">
                            </div>
                            <div>
                                <label class="cn-label" for="cnAuthor">Recorded By</label>
                                <input type="text" id="cnAuthor" name="author_name" maxlength="255" value="{{ session('active_name', 'School Nurse') }}">
                            </div>
                        </div>
                        <div class="cn-actions">
                            <span class="cn-feedback" id="cnFeedback" role="status" aria-live="polite"></span>
                            <button type="submit" class="btn btn-primary" id="cnSubmit">Add Note</button>
                        </div>
                    </form>
                </div>
                <div class="student-profile-section">
                    <h4>Note History</h4>
                    <div id="pNotesList">
                        <div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">Select a student to view notes.</div></div>
                    </div>
                </div>
            </section>

            <section id="p-consultation" class="sp-panel">
                <div class="student-profile-section">
                    <h4>Consultation Log</h4>
                    <div id="pConsultList">
                        <div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">Select a student to view consultations.</div></div>
                    </div>
                </div>
            </section>

            <section id="p-documents" class="sp-panel">
                {{-- The same component the class adviser uses: one list of the
                     learner's documents, whichever desk filed them. --}}
                <div class="student-profile-section">
                    @include('partials.student-documents-panel')
                </div>
                <div class="student-profile-section">
                    <h4>Health Conditions</h4>
                    <div id="shcConditionsList">
                        <div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">Select a student to view conditions.</div></div>
                    </div>
                </div>
            </section>
        </div>{{-- /.student-profile-body --}}
    </div>{{-- /.student-profile-modal --}}
</div>{{-- /.profile-backdrop --}}

@include('partials.student-documents-script')

<script>
(() => {
    const backdrop = document.getElementById('profileBackdrop');
    const rows = Array.from(document.querySelectorAll('.js-record-row'));
    const closeBtn = document.getElementById('profileClose');
    const printBtn = document.getElementById('profilePrint');
    const fillLink = document.getElementById('profileFillLink');

    if (!backdrop) {
        return;
    }

    const setText = (id, value) => {
        const node = document.getElementById(id);
        if (node) {
            node.textContent = value && String(value).trim() !== '' ? String(value) : '-';
        }
    };

    const setBadge = (id, value) => {
        const node = document.getElementById(id);
        if (node) {
            node.textContent = String(value);
        }
    };

    // ── Sheet 1 / Sheet 2 read-only rendering, mirroring the adviser's
    //    view-profile so the same learner reads the same way in both roles.
    //    Built from DOM nodes, never innerHTML: every value is free text
    //    typed by an adviser.
    const REVIEW_GROUPS = {
        history: [
            ['Medical History', [['med_asthma', 'Asthma'], ['med_diabetes', 'Diabetes'], ['med_seizure', 'Seizure Disorder'], ['med_infections', 'Frequent Infections'], ['med_heart', 'Heart Condition'], ['med_tuberculosis', 'Tuberculosis'], ['med_allergies', 'Allergies'], ['med_hospitalization', 'Hospitalization / Surgery']]],
            ['Family History', [['fam_hypertension', 'Hypertension'], ['fam_diabetes', 'Diabetes'], ['fam_heart', 'Heart Disease'], ['fam_cancer', 'Cancer'], ['fam_mental', 'Mental Health Conditions']]],
        ],
        systems: [
            ['Skin / Integumentary', [['skin_normal', 'Normal'], ['skin_lesions', 'Lesions / Rashes'], ['skin_pallor', 'Pallor']]],
            ['HEENT', [['heent_normal', 'Normal'], ['heent_abnormal', 'Abnormal']]],
            ['Respiratory', [['resp_clear', 'Clear breath sounds'], ['resp_cough', 'Cough']]],
            ['Cardiovascular', [['cardio_regular', 'Regular rhythm'], ['cardio_irregular', 'Irregular']]],
            ['Abdomen / GI', [['abdo_soft', 'Soft, non-tender'], ['abdo_pain', 'Pain']]],
            ['Neurologic', [['neuro_alert', 'Alert, oriented'], ['neuro_reflexes', 'Reflexes normal'], ['neuro_abnormal', 'Abnormal']]],
            ['Dental', [['dental_good', 'Good'], ['dental_fair', 'Fair'], ['dental_poor', 'Poor'], ['dental_caries', 'Dental caries'], ['dental_gum', 'Gum inflammation'], ['dental_referral', 'Referral to dentist']]],
            ['Immunization', [['immun_complete', 'Complete'], ['immun_incomplete', 'Incomplete'], ['immun_not_available', 'Not available']]],
        ],
    };

    const REVIEW_TEXT = {
        history: [
            ['allergies_detail', 'Allergy details'], ['hospitalization_detail', 'Hospitalization / Surgery'],
            ['current_medications', 'Current Medications'], ['other_conditions', 'Other Conditions'],
            ['genetic_disorders', 'Genetic / Hereditary Disorders'],
            ['consciousness', 'Level of Consciousness'], ['posture', 'Posture / Gait'], ['hygiene', 'Hygiene / Grooming'],
        ],
        systems: [
            ['right_eye', 'Right Eye'], ['left_eye', 'Left Eye'], ['immun_date', 'Date Record Reviewed'],
            ['notes', 'Notes / Details'], ['summary', 'Summary of Findings'],
            ['recommendations', 'Recommendations / Referrals'],
            ['examiner_name', 'Examiner Name'], ['examiner_date', 'Date'],
        ],
    };

    const renderReview = (hostId, data, kind, emptyMessage) => {
        const host = document.getElementById(hostId);
        if (!host) {
            return;
        }

        host.textContent = '';

        if (!data || typeof data !== 'object' || Object.keys(data).length === 0) {
            const empty = document.createElement('p');
            empty.className = 'sp-note';
            empty.textContent = emptyMessage;
            host.appendChild(empty);

            return;
        }

        REVIEW_GROUPS[kind].forEach(([title, items]) => {
            const block = document.createElement('div');
            block.className = 'sp-review-block';

            const heading = document.createElement('span');
            heading.className = 'sp-review-title';
            heading.textContent = title;
            block.appendChild(heading);

            const list = document.createElement('div');
            list.className = 'sp-review-items';
            items.forEach(([key, label]) => {
                const on = Boolean(data[key]);
                const item = document.createElement('span');
                item.className = on ? 'sp-review-item on' : 'sp-review-item';
                item.textContent = (on ? '✓ ' : '– ') + label;
                list.appendChild(item);
            });
            block.appendChild(list);
            host.appendChild(block);
        });

        const values = { ...data };
        // The roster carries a flag rather than the signature image itself.
        if (values.examiner_signature_present) {
            values.examiner_signature_present = 'Signed';
        }

        const notes = REVIEW_TEXT[kind]
            .concat(values.examiner_signature_present ? [['examiner_signature_present', 'Examiner Signature']] : [])
            .filter(([key]) => {
                const value = values[key];
                return value !== null && value !== undefined && String(value).trim() !== '';
            });

        if (notes.length) {
            const grid = document.createElement('div');
            grid.className = 'student-profile-grid';
            grid.style.marginTop = '12px';
            notes.forEach(([key, label]) => {
                const cell = document.createElement('div');
                if (['notes', 'summary', 'recommendations'].includes(key)) {
                    cell.className = 'full';
                }
                const k = document.createElement('span');
                k.textContent = label + ':';
                const v = document.createElement('b');
                v.textContent = String(values[key]);
                cell.append(k, v);
                grid.appendChild(cell);
            });
            host.appendChild(grid);
        }
    };

    const renderHealthHistory = (history) => renderReview(
        'pdHealthHistory', history, 'history',
        'No medical or family history was recorded for this learner.'
    );

    const renderSystemsReview = (review) => renderReview(
        'pdSystemsReview', review, 'systems',
        'No systems review was recorded for this learner.'
    );

    const drawGrowthTrend = (record) => {
        const toNum = (value) => {
            const parsed = Number(value);
            return Number.isFinite(parsed) ? parsed : null;
        };

        const awaitingEl = document.getElementById('pgAwaitingExam');
        const chartBodyEl = document.getElementById('pgChartBody');
        const deltaEl = document.getElementById('pgDelta');

        // Until the School Nurse records an endline measurement, "current" has no
        // real value of its own — show a clear waiting state instead of a
        // chart that plots the baseline against itself.
        const hasEndlineData = Boolean(
            (record?.examination && Object.keys(record.examination).length > 0) || record?.endline_snapshot
        );

        if (!hasEndlineData) {
            if (awaitingEl) awaitingEl.style.display = 'flex';
            if (chartBodyEl) chartBodyEl.style.display = 'none';
            if (deltaEl) deltaEl.style.display = 'none';
            return;
        }

        if (awaitingEl) awaitingEl.style.display = 'none';
        if (chartBodyEl) chartBodyEl.style.display = '';

        const baselineHeight = toNum(record?.baseline_snapshot?.height_cm ?? record?.height_cm);
        const currentHeight = toNum(record?.examination?.height_cm ?? record?.endline_snapshot?.height_cm ?? record?.height_cm);
        const baselineWeight = toNum(record?.baseline_snapshot?.weight_kg ?? record?.weight_kg);
        const currentWeight = toNum(record?.examination?.weight_kg ?? record?.endline_snapshot?.weight_kg ?? record?.weight_kg);

        if (deltaEl) {
            const pills = [];
            if (baselineHeight !== null && currentHeight !== null) {
                const d = currentHeight - baselineHeight;
                pills.push(`<span class="growth-delta-pill">Height ${d >= 0 ? '+' : ''}${d.toFixed(1)} cm</span>`);
            }
            if (baselineWeight !== null && currentWeight !== null) {
                const d = currentWeight - baselineWeight;
                pills.push(`<span class="growth-delta-pill is-weight">Weight ${d >= 0 ? '+' : ''}${d.toFixed(1)} kg</span>`);
            }
            deltaEl.innerHTML = pills.join('');
            deltaEl.style.display = pills.length ? 'flex' : 'none';
        }

        const yForMetric = (value, minVal, maxVal) => {
            if (value === null) {
                return 150;
            }
            const span = Math.max(1, maxVal - minVal);
            const ratio = (value - minVal) / span;
            return 150 - (ratio * 100);
        };

        const bx = 130;
        const cx = 390;
        const barWidth = 24;

        const heightValues = [baselineHeight, currentHeight].filter((value) => value !== null);
        const weightValues = [baselineWeight, currentWeight].filter((value) => value !== null);

        const minHeight = heightValues.length ? Math.min(...heightValues) * 0.95 : 0;
        const maxHeight = heightValues.length ? Math.max(...heightValues) * 1.05 : 1;
        const minWeight = 0;
        const maxWeight = weightValues.length ? Math.max(...weightValues) * 1.15 : 1;

        const byH = yForMetric(baselineHeight, minHeight, maxHeight);
        const cyH = yForMetric(currentHeight, minHeight, maxHeight);
        const byW = yForMetric(baselineWeight, minWeight, maxWeight);
        const cyW = yForMetric(currentWeight, minWeight, maxWeight);

        const setAttr = (id, attr, value) => {
            const node = document.getElementById(id);
            if (node) {
                node.setAttribute(attr, String(value));
            }
        };

        setAttr('pgHeightLine', 'points', `${bx},${byH} ${cx},${cyH}`);

        setAttr('pgHeightStart', 'cx', bx); setAttr('pgHeightStart', 'cy', byH);
        setAttr('pgHeightEnd', 'cx', cx); setAttr('pgHeightEnd', 'cy', cyH);
        setAttr('pgWeightBarStart', 'x', bx - (barWidth / 2));
        setAttr('pgWeightBarStart', 'y', byW);
        setAttr('pgWeightBarStart', 'width', barWidth);
        setAttr('pgWeightBarStart', 'height', Math.max(2, 150 - byW));
        setAttr('pgWeightBarEnd', 'x', cx - (barWidth / 2));
        setAttr('pgWeightBarEnd', 'y', cyW);
        setAttr('pgWeightBarEnd', 'width', barWidth);
        setAttr('pgWeightBarEnd', 'height', Math.max(2, 150 - cyW));

        const setLabel = (id, x, y, text) => {
            const node = document.getElementById(id);
            if (!node) {
                return;
            }
            node.setAttribute('x', String(x));
            node.setAttribute('y', String(y));
            node.textContent = text;
        };

        setLabel('pgHeightStartLabel', bx, byH - 8, baselineHeight !== null ? `${baselineHeight.toFixed(1)}` : '-');
        setLabel('pgHeightEndLabel', cx, cyH - 8, currentHeight !== null ? `${currentHeight.toFixed(1)}` : '-');
        setLabel('pgWeightStartLabel', bx, byW - 8, baselineWeight !== null ? `${baselineWeight.toFixed(1)}` : '-');
        setLabel('pgWeightEndLabel', cx, cyW - 8, currentWeight !== null ? `${currentWeight.toFixed(1)}` : '-');
    };

    const openProfile = (record, route) => {
        if (fillLink) {
            fillLink.setAttribute('href', route || '#');
        }
        const fullName = [record.last_name, ',', record.first_name, record.middle_name ? (' ' + String(record.middle_name).charAt(0).toUpperCase() + '.') : '']
            .join(' ')
            .replace(' ,', ',')
            .replace(/\s+/g, ' ')
            .trim();
        const dob = [record.birth_year, record.birth_month, record.birth_day].filter(Boolean).join('-');
        const examined = record.examination && Object.keys(record.examination).length > 0;

        const initials = ((record.first_name || '').charAt(0) + (record.last_name || '').charAt(0)).toUpperCase();
        setText('pAvatar', initials || '?');
        setText('pName', fullName || '-');
        setText('pLrn', record.lrn || '-');
        setText('pSex', record.gender || '-');
        setText('pAge', record.age || '-');
        setText('pDob', dob || '-');
        setText('pGrade', [record.grade_level, record.section].filter(Boolean).join(' - ') || '-');

        setText('pdName', fullName || '-');
        setText('pdLrn', record.lrn || '-');
        setText('pdDob', dob || '-');
        setText('pdBirthplace', record.birthplace || '-');
        setText('pdAddress', record.address || '-');
        setText('pdGuardian', record.parent_guardian || '-');
        setText('pdContact', record.telephone_no || '-');
        setText('pdRegionDivision', [record.region, record.division].filter(Boolean).join(' / ') || '-');

        setText('psGrade', record.grade_level || '-');
        setText('psStatus', examined ? 'Examined by School Nurse' : 'Pending School Nurse Examination');

        setText('pgHeight', (record.height_cm || '-') + ' cm');
        setText('pgWeight', (record.weight_kg || '-') + ' kg');
        drawGrowthTrend(record);
        setText('paStatus', examined ? 'School Nurse examination details are available.' : 'Pending School Nurse review.');

        renderHealthHistory(record.health_history);
        renderSystemsReview(record.systems_review);

        const lrn = record.lrn || '';
        const lrnField = document.getElementById('cnLrn');
        if (lrnField) {
            lrnField.value = lrn;
        }

        StudentDocuments.load(lrn);
        loadConditions(lrn);
        loadConsentStatus(lrn);
        loadHealthAssessment(lrn);
        loadHealthHistory(lrn);
        loadConsultations(lrn);
        loadClinicNotes(lrn);

        // Each learner opens on the first tab, scrolled to the top.
        resetTabs();
        const body = document.querySelector('.student-profile-body');
        if (body) {
            body.scrollTop = 0;
        }

        backdrop.classList.add('open');
        backdrop.setAttribute('aria-hidden', 'false');
    };


    const loadConsentStatus = async (lrn) => {
        const statusEl = document.getElementById('pcConsentStatus');
        if (!statusEl) return;

        if (!lrn) {
            setBadge('pConsentBadge', '–');
            statusEl.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">No LRN available.</div></div>';
            return;
        }

        statusEl.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">Loading&hellip;</div></div>';
        setBadge('pConsentBadge', '…');

        try {
            const resp = await fetch('/api/student-consent-status?lrn=' + encodeURIComponent(lrn), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!resp.ok) {
                statusEl.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">Could not check consent status.</div></div>';
                return;
            }

            const data = await resp.json();

            if (data.has_consent) {
                setBadge('pConsentBadge', data.consent_type === 'refused'
                    ? 'Declined'
                    : (data.consent_type === 'partial' ? 'Partial' : 'Approved'));

                // Status banner colour by consent_type
                const bannerStyle = data.consent_type === 'refused'
                    ? 'background:#f3f4f6;border:1px solid #d1d5db;color:#374151;'
                    : data.consent_type === 'partial'
                        ? 'background:#fef3c7;border:1px solid #fcd34d;color:#92400e;'
                        : 'background:#dcfce7;border:1px solid #86efac;color:#166534;';

                const consentLabel = data.consent_type === 'refused'
                    ? 'Consent Refused'
                    : data.consent_type === 'partial'
                        ? 'Partial Consent on file'
                        : 'Full Consent on file';

                const bannerIcon = data.consent_type === 'refused'
                    ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>'
                    : data.consent_type === 'partial'
                        ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
                        : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';

                // Conditional sub-info for partial/refused
                const exceptionRow = data.consent_type === 'partial' && data.partial_exception
                    ? `<div class="kv"><div class="k">Exception:</div><div class="v">${data.partial_exception}</div></div>`
                    : '';
                const refusedRow = data.consent_type === 'refused' && data.refused_reason
                    ? `<div class="kv"><div class="k">Reason:</div><div class="v">${data.refused_reason}</div></div>`
                    : '';

                // Allergy rows
                const allergyRows = [
                    data.allergy_food
                        ? `<div class="kv"><div class="k">Food Allergy:</div><div class="v">${data.allergy_food_detail || 'Yes'}</div></div>`
                        : '',
                    data.allergy_medicine
                        ? `<div class="kv"><div class="k">Medicine Allergy:</div><div class="v">${data.allergy_medicine_detail || 'Yes'}</div></div>`
                        : '',
                    data.prev_immunization
                        ? `<div class="kv"><div class="k">Prev. Immunization Reaction:</div><div class="v">${data.prev_immunization_detail || 'Yes'}</div></div>`
                        : '',
                ].filter(Boolean).join('');

                const allergySection = allergyRows
                    ? `<div style="margin:10px 0 4px;padding:10px 12px;background:#f7faf8;border-radius:8px;border:1px solid #d1dbd5;">
                           <div style="font-size:.68rem;font-weight:700;color:#334a3f;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;">Allergy Information</div>
                           ${allergyRows}
                       </div>`
                    : '';

                const otherIllnessRow = data.has_other_illness
                    ? `<div class="kv"><div class="k">Other Illness:</div><div class="v">${data.other_illness_detail || 'Yes'}</div></div>`
                    : '';

                const medCertRow = `<div class="kv"><div class="k">Med. Cert Attached:</div><div class="v">${data.medical_cert_attached ? 'Yes' : 'No'}</div></div>`;

                const viewBtn = data.has_file && data.consent_id
                    ? `<div style="margin-top:14px;">
                           <a href="/parental-consent/${data.consent_id}/download" target="_blank" rel="noopener noreferrer"
                              style="display:inline-flex;align-items:center;gap:6px;background:#15803d;color:#fff;border-radius:7px;padding:7px 14px;font-size:.78rem;font-weight:700;text-decoration:none;">
                               <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                               View Consent Form
                           </a>
                       </div>`
                    : '';

                statusEl.innerHTML = `
                    <div style="display:flex;align-items:center;gap:8px;padding:10px 12px;${bannerStyle}border-radius:8px;font-size:.82rem;font-weight:700;margin-bottom:12px;">
                        ${bannerIcon}
                        ${consentLabel} &mdash; SY ${data.school_year || '—'}
                    </div>
                    <div class="kv"><div class="k">Consent Type:</div><div class="v" style="font-weight:600;">${data.consent_type === 'full' ? 'Full Consent' : data.consent_type === 'partial' ? 'Partial Consent' : 'Refused'}</div></div>
                    ${exceptionRow}
                    ${refusedRow}
                    ${allergySection}
                    ${otherIllnessRow}
                    ${medCertRow}
                    <div class="kv" style="margin-top:8px;border-top:1px solid #e4ece7;padding-top:8px;"><div class="k">School Year:</div><div class="v">${data.school_year || '—'}</div></div>
                    <div class="kv"><div class="k">Submitted By:</div><div class="v">${data.uploaded_by || '—'}</div></div>
                    <div class="kv"><div class="k">Submitted On:</div><div class="v">${data.uploaded_at || '—'}</div></div>
                    ${viewBtn}`;

            } else {
                setBadge('pConsentBadge', 'Pending');
                statusEl.innerHTML = `
                    <div style="display:flex;align-items:center;gap:8px;padding:10px 12px;background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;font-size:.82rem;font-weight:700;color:#991b1b;margin-bottom:12px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                        No consent on file for SY ${data.school_year || '—'}
                    </div>
                    <div class="kv"><div class="k">School Year:</div><div class="v">${data.school_year || '—'}</div></div>
                    <div style="margin-top:10px;padding:10px 12px;background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;font-size:.78rem;color:#92400e;line-height:1.5;">
                        The Class Adviser has not yet submitted a parental consent record for this student.
                    </div>`;
            }
        } catch (_err) {
            statusEl.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">Could not load consent status.</div></div>';
        }
    };

    const loadHealthAssessment = async (lrn) => {
        const el = document.getElementById('phaStatus');
        if (!el) return;
        if (!lrn) { el.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">No LRN available.</div></div>'; return; }
        el.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">Loading&hellip;</div></div>';
        try {
            const resp = await fetch('/api/student-health-assessment?lrn=' + encodeURIComponent(lrn), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!resp.ok) { el.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">Could not load assessment.</div></div>'; return; }
            const d = await resp.json();
            if (!d.has_assessment) {
                el.innerHTML = `
                    <div style="display:flex;align-items:center;gap:8px;padding:10px 12px;background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;font-size:.82rem;font-weight:700;color:#991b1b;margin-bottom:12px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                        No health assessment on file for SY ${d.school_year || '—'}
                    </div>
                    <div style="padding:10px 12px;background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;font-size:.78rem;color:#92400e;">
                        The Class Adviser has not yet submitted an MLHAT health assessment for this student.
                    </div>`;
                return;
            }

            const bool = (v, yes = 'Yes', no = 'No') => v ? `<span style="color:#15803d;font-weight:600;">${yes}</span>` : no;
            const row = (k, v) => v ? `<div class="kv"><div class="k">${k}:</div><div class="v">${v}</div></div>` : '';
            const section = (title, html) => `<div style="margin-bottom:12px;padding:10px 12px;background:#f7faf8;border-radius:8px;border:1px solid #d1dbd5;">
                <div style="font-size:.68rem;font-weight:700;color:#334a3f;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;">${title}</div>${html}</div>`;

            // Medical history
            const medItems = [
                d.med_asthma && 'Asthma', d.med_diabetes && 'Diabetes',
                d.med_seizure_disorder && 'Seizure Disorder', d.med_frequent_infections && 'Frequent Infections',
                d.med_allergies && ('Allergies' + (d.med_allergies_detail ? ': ' + d.med_allergies_detail : '')),
                d.med_heart_condition && 'Heart Condition', d.med_tuberculosis && 'Tuberculosis',
                d.med_hospitalization_surgery && ('Hospitalization/Surgery' + (d.med_hospitalization_detail ? ': ' + d.med_hospitalization_detail : '')),
            ].filter(Boolean);
            const medHistHtml = medItems.length
                ? `<div style="font-size:.8rem;color:#1d3c31;">${medItems.join(', ')}</div>` + (d.med_current_medications ? row('Current Medications', d.med_current_medications) : '') + (d.med_other_conditions ? row('Other Conditions', d.med_other_conditions) : '')
                : '<div class="kv"><div class="k">Findings:</div><div class="v" style="color:#7a9e87;">None reported</div></div>';

            // Family history
            const famItems = [
                d.fam_hypertension && 'Hypertension', d.fam_diabetes && 'Diabetes',
                d.fam_heart_disease && 'Heart Disease', d.fam_cancer && 'Cancer',
                d.fam_mental_health && 'Mental Health Conditions',
            ].filter(Boolean);
            const famHistHtml = (famItems.length ? `<div style="font-size:.8rem;color:#1d3c31;">${famItems.join(', ')}</div>` : '<div class="kv"><div class="k">Findings:</div><div class="v" style="color:#7a9e87;">None reported</div></div>') +
                (d.fam_genetic_hereditary ? row('Genetic/Hereditary', d.fam_genetic_hereditary) : '');

            // Vital signs
            const vitals = [
                d.vital_height_cm && row('Height', d.vital_height_cm + ' cm'),
                d.vital_weight_kg && row('Weight', d.vital_weight_kg + ' kg'),
                d.vital_bmi && row('BMI', d.vital_bmi),
                d.vital_temperature_c && row('Temperature', d.vital_temperature_c + ' °C'),
                d.vital_pulse_rate && row('Pulse Rate', d.vital_pulse_rate + ' bpm'),
                d.vital_blood_pressure && row('Blood Pressure', d.vital_blood_pressure + ' mmHg'),
            ].filter(Boolean).join('');

            // Body systems
            const systemLabels = {
                integumentary:'Integumentary', heent_head:'HEENT-Head/Scalp', heent_eyes:'HEENT-Eyes',
                heent_ears:'HEENT-Ears', heent_nose:'HEENT-Nose', heent_throat:'HEENT-Throat',
                respiratory:'Respiratory', cardiovascular:'Cardiovascular', gastrointestinal:'Gastrointestinal',
                genitourinary:'Genitourinary', musculoskeletal:'Musculoskeletal', neurological:'Neurological',
            };
            const systems = d.body_systems || {};
            const systemRows = Object.entries(systems).map(([key, val]) => {
                const label = systemLabels[key] || key;
                const findings = (val.findings || []).join(', ');
                const notes = val.notes || '';
                if (!findings && !notes) return '';
                return row(label, findings + (notes ? ` <span style="color:#7a9e87;font-size:.76rem;">(${notes})</span>` : ''));
            }).filter(Boolean).join('');

            // Oral health
            const teethStr = (d.teeth_condition || []).join(', ');

            el.innerHTML = `
                <div style="display:flex;align-items:center;gap:8px;padding:10px 12px;background:#dcfce7;border:1px solid #86efac;border-radius:8px;font-size:.82rem;font-weight:700;color:#166534;margin-bottom:12px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    Health Assessment on file &mdash; SY ${d.school_year || '—'}
                </div>
                ${row('Date of Assessment', d.date_of_assessment)}
                ${row('Assessed By', d.assessed_by)}
                ${row('Submitted By', d.submitted_by + (d.submitted_at ? ' on ' + d.submitted_at : ''))}
                ${section('B. Medical History', medHistHtml)}
                ${section('C. Family History', famHistHtml)}
                ${vitals ? section('E. Vital Signs', vitals) : ''}
                ${systemRows ? section('F. Body Systems', systemRows) : ''}
                ${(d.vision_right_eye || d.vision_left_eye || d.vision_result || d.hearing_result) ? section('G. Vision & Hearing',
                    row('Vision', [d.vision_right_eye && ('R: ' + d.vision_right_eye), d.vision_left_eye && ('L: ' + d.vision_left_eye), d.vision_result].filter(Boolean).join(' | ')) +
                    row('Hearing', d.hearing_result)) : ''}
                ${teethStr || d.last_dental_visit || d.dental_referral ? section('H. Oral Health',
                    row('Teeth Condition', teethStr) + row('Last Dental Visit', d.last_dental_visit) +
                    (d.dental_referral ? '<div class="kv"><div class="k">Referral:</div><div class="v" style="color:#dc2626;font-weight:600;">Referral to Dentist Recommended</div></div>' : '')) : ''}
                ${d.immunization_status || d.missing_needed_vaccines ? section('I. Immunization Status',
                    row('Status', d.immunization_status) + row('Missing/Needed Vaccines', d.missing_needed_vaccines) + row('Date Reviewed', d.immunization_date_reviewed)) : ''}
                ${d.summary_of_findings || d.recommendations ? section('J. Summary & Recommendations',
                    row('Summary of Findings', d.summary_of_findings) + row('Recommendations', d.recommendations) + row('Examiner Signature', d.examiner_signature)) : ''}`;
        } catch (_err) {
            el.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">Could not load assessment.</div></div>';
        }
    };

    const loadHealthHistory = async (lrn) => {
        const el = document.getElementById('pHistoryList');
        if (!el) return;
        if (!lrn) { el.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">No LRN available.</div></div>'; return; }
        el.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">Loading&hellip;</div></div>';
        try {
            const resp = await fetch('/api/student-health-history?lrn=' + encodeURIComponent(lrn), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!resp.ok) { el.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">Could not load history.</div></div>'; return; }
            const d = await resp.json();
            const years = d.years || [];

            if (!years.length) {
                el.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">No records on file for this student yet.</div></div>';
                return;
            }

            const fmt = (v, unit) => (v === null || v === undefined || v === '') ? '—' : (v + (unit || ''));

            el.innerHTML = `
                <div style="font-size:.76rem;color:#3d5c47;margin-bottom:10px;">
                    ${years.length} school year${years.length > 1 ? 's' : ''} on file for this student. Each year's data is preserved separately — promoting a student to a new grade never overwrites prior years.
                </div>
            ` + years.slice().reverse().map(y => `
                <div style="border:1px solid ${y.is_current ? '#86efac' : '#e4ece7'};background:${y.is_current ? '#f0fdf4' : '#fff'};border-radius:10px;padding:10px 12px;margin-bottom:10px;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                        <span style="font-size:.86rem;font-weight:700;color:#1d3c31;">SY ${y.school_year}</span>
                        ${y.is_current ? '<span style="font-size:.66rem;font-weight:700;padding:2px 8px;border-radius:999px;background:#15803d;color:#fff;">Current</span>' : ''}
                        <span style="font-size:.76rem;color:#7a9e87;">&mdash; ${y.section || '—'}</span>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                        <div>
                            <div style="font-size:.66rem;font-weight:700;color:#7a9e87;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Baseline (${fmt(y.baseline.recorded_at)})</div>
                            <div style="font-size:.78rem;color:#1d3c31;">Ht ${fmt(y.baseline.height_cm, ' cm')} &middot; Wt ${fmt(y.baseline.weight_kg, ' kg')} &middot; BMI ${fmt(y.baseline.bmi)}</div>
                            <div style="font-size:.76rem;color:#3d5c47;">${fmt(y.baseline.status)}</div>
                        </div>
                        <div>
                            <div style="font-size:.66rem;font-weight:700;color:#7a9e87;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Endline (${fmt(y.endline.recorded_at)})</div>
                            <div style="font-size:.78rem;color:#1d3c31;">Ht ${fmt(y.endline.height_cm, ' cm')} &middot; Wt ${fmt(y.endline.weight_kg, ' kg')} &middot; BMI ${fmt(y.endline.bmi)}</div>
                            <div style="font-size:.76rem;color:#3d5c47;">${fmt(y.endline.status)}</div>
                        </div>
                    </div>
                </div>
            `).join('');
        } catch (_err) {
            el.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">Could not load history.</div></div>';
        }
    };

    // ── Consultation log for this learner ──────────────────────────
    const loadConsultations = async (lrn) => {
        const el = document.getElementById('pConsultList');
        if (!el) return;

        setBadge('pConsultBadge', 0);

        if (!lrn) {
            el.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">No LRN available.</div></div>';
            return;
        }

        el.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">Loading&hellip;</div></div>';

        try {
            const resp = await fetch('/api/student-consultations?lrn=' + encodeURIComponent(lrn), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!resp.ok) {
                el.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">Could not load consultations.</div></div>';
                return;
            }

            const rows = (await resp.json()).consultations || [];
            setBadge('pConsultBadge', rows.length);

            if (!rows.length) {
                el.innerHTML = '<p class="sp-note">No clinic consultations logged for this learner yet.</p>';
                return;
            }

            el.textContent = '';
            rows.forEach((row) => {
                const card = document.createElement('div');
                card.className = 'cn-entry';

                const head = document.createElement('div');
                head.className = 'cn-entry-head';
                const when = document.createElement('span');
                when.className = 'cn-entry-date';
                when.textContent = row.date || '—';
                const status = document.createElement('span');
                status.className = row.status === 'referred' ? 'cn-pill is-warn' : 'cn-pill';
                status.textContent = row.status === 'referred' ? 'Referred' : 'Treated';
                head.append(when, status);

                const grid = document.createElement('div');
                grid.className = 'student-profile-grid';
                [['Condition', row.condition], ['Treatment', row.treatment], ['Grade / Section', row.grade_section]]
                    .filter(([, value]) => value && String(value).trim() !== '')
                    .forEach(([label, value]) => {
                        const cell = document.createElement('div');
                        const k = document.createElement('span');
                        k.textContent = label + ':';
                        const v = document.createElement('b');
                        v.textContent = String(value);
                        cell.append(k, v);
                        grid.appendChild(cell);
                    });

                card.append(head, grid);
                el.appendChild(card);
            });
        } catch (_err) {
            el.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">Could not load consultations.</div></div>';
        }
    };

    // ── Clinic notes ───────────────────────────────────────────────
    const renderClinicNotes = (notes) => {
        const el = document.getElementById('pNotesList');
        if (!el) return;

        setBadge('pNotesBadge', notes.length);

        if (!notes.length) {
            el.innerHTML = '<p class="sp-note">No clinic notes recorded for this learner yet.</p>';
            return;
        }

        el.textContent = '';
        notes.forEach((note) => {
            const card = document.createElement('div');
            card.className = 'cn-entry';

            const head = document.createElement('div');
            head.className = 'cn-entry-head';
            const when = document.createElement('span');
            when.className = 'cn-entry-date';
            when.textContent = note.recorded_at || '—';
            const author = document.createElement('span');
            author.className = 'cn-entry-author';
            author.textContent = note.author || '—';
            head.append(when, author);

            const body = document.createElement('p');
            body.className = 'cn-entry-body';
            body.textContent = note.note || '';

            card.append(head, body);

            if (note.follow_up_date) {
                const followUp = document.createElement('span');
                followUp.className = 'cn-pill is-followup';
                followUp.textContent = 'Follow-up: ' + note.follow_up_date;
                card.appendChild(followUp);
            }

            el.appendChild(card);
        });
    };

    const loadClinicNotes = async (lrn) => {
        const el = document.getElementById('pNotesList');
        if (!el) return;

        setBadge('pNotesBadge', 0);

        if (!lrn) {
            el.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">No LRN available.</div></div>';
            return;
        }

        el.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">Loading&hellip;</div></div>';

        try {
            const resp = await fetch('/api/student-clinic-notes?lrn=' + encodeURIComponent(lrn), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!resp.ok) {
                el.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">Could not load clinic notes.</div></div>';
                return;
            }

            renderClinicNotes((await resp.json()).notes || []);
        } catch (_err) {
            el.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">Could not load clinic notes.</div></div>';
        }
    };

    const noteForm = document.getElementById('clinicNoteForm');
    if (noteForm) {
        noteForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            const feedback = document.getElementById('cnFeedback');
            const submit = document.getElementById('cnSubmit');
            const lrn = (document.getElementById('cnLrn') || {}).value || '';
            const noteText = (document.getElementById('cnNote') || {}).value || '';

            if (!lrn || noteText.trim() === '') {
                if (feedback) {
                    feedback.textContent = 'Enter a note first.';
                    feedback.className = 'cn-feedback is-error';
                }
                return;
            }

            if (submit) submit.disabled = true;
            if (feedback) {
                feedback.textContent = 'Saving…';
                feedback.className = 'cn-feedback';
            }

            try {
                const resp = await fetch('/api/student-clinic-notes', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        lrn,
                        note: noteText,
                        follow_up_date: (document.getElementById('cnFollowUp') || {}).value || null,
                        author_name: (document.getElementById('cnAuthor') || {}).value || null,
                    }),
                });

                if (!resp.ok) {
                    if (feedback) {
                        feedback.textContent = resp.status === 404
                            ? 'No health record on file for this learner.'
                            : 'Could not save the note.';
                        feedback.className = 'cn-feedback is-error';
                    }
                    return;
                }

                document.getElementById('cnNote').value = '';
                document.getElementById('cnFollowUp').value = '';
                if (feedback) {
                    feedback.textContent = 'Note saved.';
                    feedback.className = 'cn-feedback is-ok';
                }
                await loadClinicNotes(lrn);
            } catch (_err) {
                if (feedback) {
                    feedback.textContent = 'Could not save the note.';
                    feedback.className = 'cn-feedback is-error';
                }
            } finally {
                if (submit) submit.disabled = false;
            }
        });
    }

    /**
     * Health Conditions list. The certificates attached to a condition are no
     * longer listed here — every document for the learner, whichever desk filed
     * it, is in the Medical Documents component above, with working preview and
     * download links. This block keeps what that list cannot show: a condition
     * on file that has no document backing it yet.
     */
    const loadConditions = async (lrn) => {
        const listEl = document.getElementById('shcConditionsList');
        if (!listEl) return;

        if (!lrn) {
            listEl.innerHTML = '<div style="font-size:.78rem;color:#7a9e87;">No LRN available for this record.</div>';
            return;
        }

        listEl.innerHTML = '<div style="font-size:.78rem;color:#7a9e87;">Loading&hellip;</div>';

        try {
            const resp = await fetch('/api/student-conditions?lrn=' + encodeURIComponent(lrn), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!resp.ok) {
                listEl.innerHTML = '<div style="font-size:.78rem;color:#7a9e87;">No conditions recorded.</div>';
                return;
            }

            const conditions = (await resp.json()).conditions || [];

            if (!conditions.length) {
                listEl.innerHTML = '<div style="font-size:.78rem;color:#7a9e87;">No health conditions on file for this student.</div>';
                return;
            }

            listEl.innerHTML = conditions.map(c => {
                const badge = c.is_verified
                    ? '<span style="font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:999px;background:#dcfce7;color:#15803d;margin-left:6px;">Verified / Diagnosed</span>'
                    : '<span style="font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:999px;background:#fef3c7;color:#92400e;margin-left:6px;">Self-reported</span>';

                return `<div style="border-bottom:1px solid #edf5ef;padding:8px 0;">
                    <div style="display:flex;align-items:center;gap:4px;">
                        <span style="font-size:.88rem;font-weight:700;color:#1d3c31;">${c.condition_name}</span>
                        ${badge}
                    </div>
                </div>`;
            }).join('');
        } catch (_err) {
            listEl.innerHTML = '<div style="font-size:.78rem;color:#7a9e87;">Could not load conditions.</div>';
        }
    };

    const closeProfile = () => {
        backdrop.classList.remove('open');
        backdrop.setAttribute('aria-hidden', 'true');
    };

    const tabs = Array.from(document.querySelectorAll('.sp-tab'));
    const panels = Array.from(document.querySelectorAll('.sp-panel'));

    const activateTab = (tab) => {
        const target = tab.getAttribute('data-panel');
        tabs.forEach((t) => {
            t.classList.remove('active');
            t.setAttribute('aria-selected', 'false');
        });
        panels.forEach((p) => p.classList.remove('active'));
        tab.classList.add('active');
        tab.setAttribute('aria-selected', 'true');
        const panel = document.getElementById(target || '');
        if (panel) {
            panel.classList.add('active');
        }
    };

    function resetTabs() {
        if (tabs.length) {
            activateTab(tabs[0]);
        }
    }

    tabs.forEach((tab) => tab.addEventListener('click', () => activateTab(tab)));

    // Medical Documents is the same component the class adviser's profile uses,
    // reading and writing the same list; the tab badge counts what it holds.
    StudentDocuments.init({
        onCount: (count) => setBadge('pDocsBadge', count),
    });

    rows.forEach((row) => {
        const open = () => {
            let record = {};
            try {
                record = JSON.parse(row.getAttribute('data-record') || '{}');
            } catch (_e) {
                record = {};
            }
            openProfile(record, row.getAttribute('data-route') || '#');
        };

        const button = row.querySelector('.js-view-profile');
        if (button) {
            button.addEventListener('click', (event) => {
                event.stopPropagation();
                open();
            });
        }
        row.addEventListener('click', open);
    });

    if (closeBtn) {
        closeBtn.addEventListener('click', closeProfile);
    }
    if (printBtn) {
        printBtn.addEventListener('click', () => window.print());
    }
    backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) {
            closeProfile();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && backdrop.classList.contains('open')) {
            closeProfile();
        }
    });

    // ── Grade / sex / section chips + search over the rendered rows ──
    const search = document.getElementById('shrSearch');
    const noMatchRow = document.querySelector('.js-records-nomatch');
    const countBadge = document.getElementById('shrCountBadge');
    const totalLabel = document.getElementById('shrTotalLabel');
    const showingLabel = document.getElementById('shrShowingLabel');
    const filters = { grade: 'all', sex: 'all', section: 'all' };

    const applyFilters = () => {
        const keyword = search ? search.value.trim().toLowerCase() : '';
        let visible = 0;

        rows.forEach((row) => {
            const matches = (!keyword || (row.dataset.search || '').includes(keyword))
                && (filters.grade === 'all' || (row.dataset.grade || '') === filters.grade)
                && (filters.sex === 'all' || (row.dataset.sex || '') === filters.sex)
                && (filters.section === 'all' || (row.dataset.section || '') === filters.section);

            row.hidden = !matches;
            if (matches) {
                visible += 1;
            }
        });

        if (noMatchRow) {
            noMatchRow.hidden = rows.length === 0 || visible > 0;
        }
        if (countBadge) {
            countBadge.textContent = String(visible);
        }
        if (totalLabel) {
            totalLabel.textContent = visible + (visible === 1 ? ' student' : ' students');
        }
        if (showingLabel) {
            const parts = [];
            if (filters.grade !== 'all') parts.push(filters.grade);
            if (filters.sex !== 'all') parts.push(filters.sex.charAt(0).toUpperCase() + filters.sex.slice(1));
            if (filters.section !== 'all') parts.push(filters.section.charAt(0).toUpperCase() + filters.section.slice(1));
            if (keyword) parts.push('"' + keyword + '"');
            showingLabel.textContent = parts.length ? parts.join(' · ') : 'All Students';
        }
    };

    document.querySelectorAll('[data-filter]').forEach((button) => {
        button.addEventListener('click', () => {
            const group = button.dataset.filter;
            if (!(group in filters)) {
                return;
            }

            filters[group] = button.dataset.value || 'all';
            button.parentElement.querySelectorAll('[data-filter="' + group + '"]').forEach((sibling) => {
                sibling.classList.toggle('active', sibling === button);
            });
            applyFilters();
        });
    });

    if (search) {
        search.addEventListener('input', applyFilters);
    }

    applyFilters();
})();
</script>
</body>
</html>
