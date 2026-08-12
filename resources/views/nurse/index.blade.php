<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Review Queue - SIGLA</title>
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

        $queueTotal = count($records);
        $queueExamined = collect($records)->filter(fn ($r) => ! empty($r['examination']))->count();
        $queuePending = $queueTotal - $queueExamined;
    @endphp

    <header class="topbar">
        <div class="topbar-bc"><span>School Nurse</span><span class="bc-sep">&rsaquo;</span><span>Review Queue</span></div>
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
            <h1 class="page-title">Review <span>Queue</span></h1>
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
                <div class="kpi-hint">In this school's queue</div>
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

        <div class="toolbar" style="margin-top:20px">
            <div class="section-title compact" style="margin-bottom:0;padding-bottom:9px">Submitted Health Cards</div>
            <div class="spacer"></div>
            <div class="toolbar-count">{{ $queueTotal }} {{ Str::plural('record', $queueTotal) }}</div>
        </div>

        <div class="table-card">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>LRN</th>
                            <th>Grade Level</th>
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
                        @endphp
                        <tr>
                            <td><strong>{{ $fullName }}</strong></td>
                            <td class="td-lrn">{{ $record['lrn'] ?? '—' }}</td>
                            <td>{{ $record['grade_level'] ?? '—' }}</td>
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
                                <a href="{{ route('nurse.examine', $index) }}" class="btn btn-primary">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    Fill Medical Record
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <div class="queue-empty">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg>
                                    <p>No adviser submissions yet. Records appear here once class advisers submit health cards.</p>
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

@include('partials.nurse-page-transition')
</body>
</html>
