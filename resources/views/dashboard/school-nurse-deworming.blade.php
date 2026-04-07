<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deworming Program - LUSOG</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    @php $pageCssPath = resource_path('css/school-nurse-deworming.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
</head>
<body>
<aside class="sidebar">
    <div class="sb-grid"></div>
    <div class="sb-logo"><img src="{{ asset('images/lusog-logo.png') }}" alt="LUSOG Logo" class="sb-logo-full"></div>
    <nav class="sb-nav">
        <div class="sb-section-label">Main</div>
        <a href="{{ route('dashboard.school-nurse') }}" class="sb-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>Dashboard</a>
        <a href="{{ route('dashboard.student-health-records') }}" class="sb-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>Health Records</a>
        <a href="{{ route('dashboard.consultation-log') }}" class="sb-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4"/><path d="M21 12c0 4.97-4.03 9-9 9S3 16.97 3 12 7.03 3 12 3s9 4.03 9 9z"/></svg>Consultation Log</a>
        <div class="sb-section-label">Health Programs</div>
        <a href="{{ route('dashboard.school-nurse.feeding-program') }}" class="sb-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>Feeding Program</a>
        <a href="{{ route('dashboard.school-nurse.deworming') }}" class="sb-link active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 3-7-3"/><path d="M3 9v6l7 3 7-3V9"/><polyline points="3 9 12 6 21 9"/></svg>Deworming Program</a>
        <div class="sb-section-label">Inventory</div>
        <a href="{{ route('dashboard.medicine-inventory') }}" class="sb-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="2" width="18" height="20" rx="2"/><path d="M9 2v4h6V2"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>Medicine Inventory</a>
        <a href="#" class="sb-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Dispensing Log</a>
        <div class="sb-section-label">Reports</div>
        <a href="{{ route('dashboard.data-visualization') }}" class="sb-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>Data Visualization</a>
        <a href="#" class="sb-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Generate Reports</a>
    </nav>
    <div class="sb-user">
        <div class="sb-avatar">{{ substr(session('active_name', 'School Nurse'), 0, 2) }}</div>
        <div class="sb-user-meta">
            <div class="sb-user-name">{{ session('active_name', 'School Nurse') }}</div>
            <div class="sb-user-role">School Nurse - DCNHS</div>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="sb-logout" title="Sign out">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </button>
        </form>
    </div>
</aside>

@php
    $requests = collect($dewormingRequests ?? collect());
    $pendingCount = $requests->where('status', 'pending')->count();
    $approvedCount = $requests->whereIn('status', ['approved', 'prepared', 'released', 'declined'])->count();
    $totalTablets = (int) $requests->sum(fn ($item) => (int) ($item['tablets_requested'] ?? 0));
@endphp

<div class="main">
    <header class="topbar">
        <div class="topbar-breadcrumb">
            <a href="{{ route('dashboard.school-nurse') }}" class="bc-home">Dashboard</a>
            <span class="bc-sep">></span>
            <span class="bc-current">Deworming Program</span>
        </div>
        <div class="topbar-chip">Class Adviser Request Monitor</div>
    </header>

    <div class="content">
        @if (session('success'))
            <div class="flash ok">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="flash err">{{ session('error') }}</div>
        @endif

        <div class="page-eyebrow">Health Programs</div>
        <h1 class="page-title">Deworming <span>Requests</span></h1>
        <p class="page-sub">Shows requests submitted by Class Advisers, including tablets requested for each class.</p>

        <section class="stats">
            <article class="stat-card">
                <div class="stat-label">Total Requests</div>
                <div class="stat-value">{{ $requests->count() }}</div>
            </article>
            <article class="stat-card">
                <div class="stat-label">Pending</div>
                <div class="stat-value">{{ $pendingCount }}</div>
            </article>
            <article class="stat-card">
                <div class="stat-label">Reviewed</div>
                <div class="stat-value">{{ $approvedCount }}</div>
            </article>
            <article class="stat-card">
                <div class="stat-label">Total Tablets Requested</div>
                <div class="stat-value">{{ $totalTablets }}</div>
            </article>
        </section>

        <section class="table-card" style="margin-top:14px;">
            <div class="table-head">Class Adviser Deworming Requests</div>
            <table>
                <thead>
                    <tr>
                        <th>Date Submitted</th>
                        <th>Campaign</th>
                        <th>Grade &amp; Section</th>
                        <th>Total Students</th>
                        <th>Consenting</th>
                        <th>Tablets Requested</th>
                        <th>Status</th>
                        <th>Release Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($requests as $item)
                        @php
                            $status = (string) ($item['status'] ?? 'pending');
                            $statusClass = $status === 'released'
                                ? 'badge ok'
                                : ($status === 'approved' || $status === 'prepared' ? 'badge risk' : 'badge warn');
                            $gradeLevel = (string) ($item['grade_level'] ?? '');
                            $section = (string) ($item['section'] ?? '');
                            $classLabel = trim($gradeLevel . ($section !== '' ? ' / ' . $section : ''));
                        @endphp
                        <tr>
                            <td>{{ isset($item['submitted_at']) ? \Illuminate\Support\Carbon::parse($item['submitted_at'])->format('Y-m-d') : '-' }}</td>
                            <td>{{ ($item['campaign'] ?? '') === 'start' ? 'Start of SY' : 'End of SY' }}</td>
                            <td>{{ $classLabel !== '' ? $classLabel : '-' }}</td>
                            <td>{{ $item['total_students'] ?? '-' }}</td>
                            <td>{{ $item['consenting_students'] ?? '-' }}</td>
                            <td><strong>{{ $item['tablets_requested'] ?? '-' }}</strong></td>
                            <td><span class="{{ $statusClass }}">{{ ucfirst($status) }}</span></td>
                            <td>{{ $item['released_date'] ?? '-' }}</td>
                            <td>
                                @if ($status === 'pending')
                                    <div class="actions">
                                        <form method="POST" action="{{ route('dashboard.school-nurse.deworming.decide', ['requestId' => (string) ($item['id'] ?? ''), 'decision' => 'accept']) }}">
                                            @csrf
                                            <button type="submit" class="action-btn accept">Accept</button>
                                        </form>
                                        <form method="POST" action="{{ route('dashboard.school-nurse.deworming.decide', ['requestId' => (string) ($item['id'] ?? ''), 'decision' => 'decline']) }}">
                                            @csrf
                                            <button type="submit" class="action-btn decline">Decline</button>
                                        </form>
                                    </div>
                                @else
                                    <span class="muted">Reviewed</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="empty">No deworming requests submitted yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>
</div>
</body>
</html>
