<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Deworming Program - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    @php $pageCssPath = resource_path('css/school-nurse-deworming.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
    {{-- One shared palette for pages not yet on lusog-theme.css. Loaded
         last so it overrides this page's own :root colours. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-palette.css')) !!}</style>
</head>
<body>
@include('partials.nurse-sidebar', ['active' => 'deworming'])

@php
    $requests = collect($dewormingRequests ?? collect());
    $pendingCount = $requests->where('status', 'pending')->count();
    $approvedCount = $requests->whereIn('status', ['approved', 'prepared', 'released', 'commented'])->count();
    $totalTablets = (int) $requests->sum(fn ($item) => (int) ($item['tablets_requested'] ?? 0));
@endphp

<div class="main">
    <header class="topbar">
        <div class="topbar-breadcrumb">
            <a href="{{ route('dashboard.school-nurse') }}" class="bc-home">Dashboard</a>
            <span class="bc-sep">&rsaquo;</span>
            <span class="bc-current">Deworming Program</span>
        </div>
        <div class="topbar-chip">Class Adviser Request Monitor</div>
        @include('partials.live-clock')
    </header>

    <div class="content">
        @if (session('success'))
            <div class="flash ok">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="flash err">{{ session('error') }}</div>
        @endif
        @error('nurse_comment')
            <div class="flash err">{{ $message }}</div>
        @enderror

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
                        <th>School Nurse Comment</th>
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
                                @if (!empty($item['nurse_comment']))
                                    <div class="comment-text">{{ $item['nurse_comment'] }}</div>
                                @else
                                    <span class="muted">-</span>
                                @endif
                            </td>
                            <td>
                                @if ($status === 'pending')
                                    <div class="actions">
                                        <form method="POST" action="{{ route('dashboard.school-nurse.deworming.decide', ['requestId' => (string) ($item['id'] ?? ''), 'decision' => 'accept']) }}">
                                            @csrf
                                            <button type="submit" class="action-btn accept">Accept</button>
                                        </form>
                                        <form method="POST" action="{{ route('dashboard.school-nurse.deworming.comment', ['requestId' => (string) ($item['id'] ?? '')]) }}" class="comment-form">
                                            @csrf
                                            <input type="text" name="nurse_comment" placeholder="Add comment..." maxlength="500" required>
                                            <button type="submit" class="action-btn comment">Comment</button>
                                        </form>
                                    </div>
                                @else
                                    <span class="muted">Reviewed</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="empty">No deworming requests submitted yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>
</div>
</body>
</html>
