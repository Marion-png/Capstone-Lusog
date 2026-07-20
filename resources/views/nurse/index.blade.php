<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Nurse Review Queue - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    @php $pageCssPath = resource_path('css/school-nurse.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
    <style>
        .tbl-wrap { overflow-x: auto; border-radius: var(--radius); box-shadow: var(--shadow-card); }
        table { width: 100%; border-collapse: collapse; background: var(--card); font-size: .83rem; }
        thead tr { background: var(--g50, #f0fdf4); border-bottom: 2px solid var(--border); }
        th { padding: 12px 16px; text-align: left; font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--text-3); }
        td { padding: 13px 16px; border-bottom: 1px solid var(--border); color: var(--text-1); vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: var(--g50, #f0fdf4); }
        .td-name { font-weight: 600; }
        .td-lrn { font-family: monospace; font-size: .78rem; color: var(--text-2); }
        .badge-done { display: inline-flex; align-items: center; gap: 5px; background: #dcfce7; color: #166534; font-size: .68rem; font-weight: 700; padding: 3px 9px; border-radius: 999px; }
        .badge-pend { display: inline-flex; align-items: center; gap: 5px; background: #f3f4f6; color: #6b7280; font-size: .68rem; font-weight: 700; padding: 3px 9px; border-radius: 999px; }
        .badge-consent { display: inline-flex; align-items: center; gap: 5px; background: #dcfce7; color: #166534; font-size: .68rem; font-weight: 700; padding: 3px 9px; border-radius: 999px; }
        .badge-partial-consent { display: inline-flex; align-items: center; gap: 5px; background: #fef3c7; color: #92400e; font-size: .68rem; font-weight: 700; padding: 3px 9px; border-radius: 999px; }
        .badge-refused-consent { display: inline-flex; align-items: center; gap: 5px; background: #f3f4f6; color: #374151; font-size: .68rem; font-weight: 700; padding: 3px 9px; border-radius: 999px; }
        .badge-no-consent { display: inline-flex; align-items: center; gap: 5px; background: #fee2e2; color: #991b1b; font-size: .68rem; font-weight: 700; padding: 3px 9px; border-radius: 999px; }
        .btn-view-consent { display: inline-flex; align-items: center; gap: 4px; background: none; color: #15803d; font-size: .7rem; font-weight: 700; padding: 2px 6px; border-radius: 5px; text-decoration: none; border: 1.5px solid #86efac; margin-left: 5px; transition: background .15s; }
        .btn-view-consent:hover { background: #dcfce7; }
        .badge-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        .btn-examine { display: inline-flex; align-items: center; gap: 6px; background: linear-gradient(160deg, var(--g700, #15803d), var(--g900, #14532d)); color: white; border: none; border-radius: 8px; padding: 7px 14px; font-size: .78rem; font-weight: 600; cursor: pointer; text-decoration: none; transition: opacity .15s; }
        .btn-examine:hover { opacity: .88; }
        .empty-state { text-align: center; padding: 56px 20px; color: var(--text-3); }
        .empty-state svg { width: 44px; height: 44px; margin-bottom: 12px; opacity: .4; }
        .empty-state p { font-size: .85rem; }
        .flash { padding: 12px 16px; border-radius: 10px; font-size: .82rem; margin-bottom: 16px; }
        .flash-ok { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="sb-grid"></div>
    <div class="sb-logo">
        <img src="{{ asset('images/lusog-logo.png') }}" alt="SIGLA Logo" class="sb-logo-full">
    </div>
    <nav class="sb-nav">
        <div class="sb-section-label">Main</div>
        <a href="{{ route('dashboard.school-nurse') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Dashboard
        </a>
        <a href="{{ route('nurse.index') }}" class="sb-link active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg>
            Review Queue
        </a>
        <a href="{{ route('dashboard.student-health-records') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Health Records
        </a>
        <a href="{{ route('dashboard.consultation-log') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 12l2 2 4-4"/><path d="M21 12c0 4.97-4.03 9-9 9S3 16.97 3 12 7.03 3 12 3s9 4.03 9 9z"/></svg>
            Consultation Log
        </a>
        <div class="sb-section-label">Health Programs</div>
        <a href="{{ route('dashboard.school-nurse.feeding-program') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
            Feeding Program
        </a>
        <a href="{{ route('dashboard.school-nurse.deworming') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M19 9l-7 3-7-3"/><path d="M3 9v6l7 3 7-3V9"/><polyline points="3 9 12 6 21 9"/></svg>
            Deworming Program
        </a>
        <a href="{{ route('consent-forms.nurse-index') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>
            Consent Forms
        </a>
        <a href="{{ route('health-assessments.nurse-index') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
            Health Assessments
        </a>
        <div class="sb-section-label">Inventory</div>
        <a href="{{ route('dashboard.medicine-inventory') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="2" width="18" height="20" rx="2"/><path d="M9 2v4h6V2"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
            Medicine Inventory
        </a>
        <a href="#" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Dispensing Log
        </a>
        <div class="sb-section-label">Reports</div>
        <a href="{{ route('dashboard.data-visualization') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            Data Visualization
        </a>
        <a href="#" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Generate Reports
        </a>
    </nav>
    <div class="sb-user">
        <div class="sb-avatar">{{ strtoupper(substr(session('active_name', 'SN'), 0, 2)) }}</div>
        <div class="sb-user-meta">
            <div class="sb-user-name">{{ session('active_name', 'School Nurse') }}</div>
            <div class="sb-user-role">{{ session('active_school_name', 'No school assigned') }}</div>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="sb-logout" title="Sign out" aria-label="Sign out">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <path d="M16 17l5-5-5-5"/>
                    <path d="M21 12H9"/>
                </svg>
            </button>
        </form>
    </div>
</aside>

<div class="main">
    <header class="topbar">
        <div class="topbar-breadcrumb">
            <a href="{{ route('dashboard.school-nurse') }}" class="bc-home">Dashboard</a>
            <span class="bc-sep">&rsaquo;</span>
            <span class="bc-current">Adviser Submissions</span>
        </div>
        <div class="topbar-chip"><div class="dot"></div>School Nurse</div>
    </header>

    <div class="content">
        <h1 class="page-title">Adviser <i>Submissions</i></h1>
        <p class="page-sub" style="margin-bottom: 20px;">Review and complete medical examinations for adviser-submitted student health cards.</p>

        @if (session('success'))
            <div class="flash flash-ok" role="status">{{ session('success') }}</div>
        @endif

        <div class="tbl-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>LRN</th>
                        <th>Grade Level</th>
                        <th>Height (cm)</th>
                        <th>Weight (kg)</th>
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
                        $examined = !empty($record['examination']);
                    @endphp
                    @php $rowConsent = $consentByLrn[$record['lrn'] ?? ''] ?? null; @endphp
                    <tr>
                        <td class="td-name">{{ $fullName }}</td>
                        <td class="td-lrn">{{ $record['lrn'] ?? '—' }}</td>
                        <td>{{ $record['grade_level'] ?? '—' }}</td>
                        <td>{{ $record['height_cm'] ?? '—' }}</td>
                        <td>{{ $record['weight_kg'] ?? '—' }}</td>
                        <td>
                            @if ($rowConsent !== null)
                                @if ($rowConsent->consent_type === 'refused')
                                    <span class="badge-refused-consent"><span class="badge-dot"></span>Refused</span>
                                @elseif ($rowConsent->consent_type === 'partial')
                                    <span class="badge-partial-consent"><span class="badge-dot"></span>Partial</span>
                                @else
                                    <span class="badge-consent"><span class="badge-dot"></span>Full consent</span>
                                @endif
                                @if ($rowConsent->file_path !== null)
                                    <a href="{{ route('parental-consent.download', $rowConsent->id) }}"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       class="btn-view-consent"
                                       title="View signed consent form">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                        View
                                    </a>
                                @endif
                            @else
                                <span class="badge-no-consent"><span class="badge-dot"></span>Missing</span>
                            @endif
                        </td>
                        <td>
                            @if ($examined)
                                <span class="badge-done"><span class="badge-dot"></span>Completed</span>
                            @else
                                <span class="badge-pend"><span class="badge-dot"></span>Pending</span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('nurse.examine', $index) }}" class="btn-examine">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                Fill Medical Record
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg>
                                <p>No adviser submissions yet. Records will appear here once class advisers submit health cards.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@include('partials.sidebar-hover-pin')
</body>
</html>
