<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Attendance Review - Feeding Coordinator - SIGLA</title>
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
    <style>{!! file_get_contents(resource_path('css/role-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.feedingcor-sidebar', ['active' => 'program'])

<div class="main">
    <header class="topbar">
        <div class="topbar-bc"><span>Dashboard</span><span class="bc-sep">&rsaquo;</span><span>Feeding Program</span><span class="bc-sep">&rsaquo;</span><span>Attendance Review</span></div>
        @include('partials.live-clock')
    </header>

    <div class="content">
        <div class="page-header">
            <h1 class="page-title">Attendance <span>Review</span></h1>
            <p class="page-sub">Scanned marks awaiting confirmation. These count neither as present nor absent until confirmed.</p>
            <div class="rule-chip">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                At-risk rule: {{ $ruleDescription }}
            </div>
        </div>

        @if (session('success'))
            <div class="flash ok">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="flash err">{{ session('error') }}</div>
        @endif

        <h2 class="section-title">Pending Marks ({{ $pending->count() }})</h2>
        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Section</th>
                        <th>Session Date</th>
                        <th>Confirm</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($pending as $row)
                        <tr>
                            <td><strong>{{ $row['student_name'] }}</strong></td>
                            <td>{{ $row['section'] }}</td>
                            <td>{{ $row['session_date'] }}</td>
                            <td>
                                <div class="review-actions">
                                    <form method="POST" action="{{ route('feedingcor-program.attendance.review.resolve', $row['id']) }}">
                                        @csrf
                                        <input type="hidden" name="mark" value="present">
                                        <button type="submit" class="review-btn review-present">Present</button>
                                    </form>
                                    <form method="POST" action="{{ route('feedingcor-program.attendance.review.resolve', $row['id']) }}">
                                        @csrf
                                        <input type="hidden" name="mark" value="absent">
                                        <button type="submit" class="review-btn review-absent">Absent</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="table-empty">Nothing awaiting review.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@include('partials.role-page-transition')
</body>
</html>
