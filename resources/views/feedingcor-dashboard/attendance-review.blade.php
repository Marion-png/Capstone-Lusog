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
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    <script>document.documentElement.classList.add('js');</script>
    @php $pageCssPath = resource_path('css/feeding-healthrec.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
    <style>{!! file_get_contents(resource_path('css/feedingcor-sidebar.css')) !!}</style>
    <style>
        .review-actions{display:flex;gap:8px}
        .review-btn{font-family:inherit;font-size:.72rem;font-weight:700;border:none;border-radius:8px;padding:6px 13px;cursor:pointer;transition:background .16s ease}
        .review-present{background:#dcfce7;color:#15803d}
        .review-present:hover{background:#bbf7d0}
        .review-absent{background:#fee2e2;color:#c81e1e}
        .review-absent:hover{background:#fecaca}
        .review-empty{padding:26px 14px;text-align:center;font-size:.82rem;color:var(--text-3)}
        .rule-chip{display:inline-block;font-size:.7rem;font-weight:600;color:#0e7490;background:#e9f6fa;border-radius:999px;padding:3px 11px;margin-top:8px}
        .flash{margin-top:14px;padding:10px 13px;border-radius:9px;font-size:.78rem;font-weight:600}
        .flash-ok{background:#dcfce7;color:#15803d;border:1px solid #bbf7d0}
        .flash-err{background:#fee2e2;color:#c81e1e;border:1px solid #fecaca}
    </style>
</head>
<body>
@include('partials.feedingcor-sidebar', ['active' => 'program'])

<div class="main">
    <header class="topbar">
        <div class="topbar-bc"><span>Dashboard</span><span>&rsaquo;</span><span>Feeding Program</span><span>&rsaquo;</span><span>Attendance Review</span></div>
        @include('partials.live-clock')
    </header>

    <div class="content">
        <div class="page-eyebrow">Feeding Program</div>
        <h1 class="page-title">Attendance <span>Review</span></h1>
        <p class="page-sub">Scanned marks awaiting confirmation. These count neither as present nor absent until confirmed.</p>
        <div class="rule-chip">At-risk rule: {{ $ruleDescription }}</div>

        @if (session('success'))
            <div class="flash flash-ok">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="flash flash-err">{{ session('error') }}</div>
        @endif

        <div class="section-title">Pending Marks ({{ $pending->count() }})</div>
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
                            <td>{{ $row['student_name'] }}</td>
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
                        <tr><td colspan="4" class="review-empty">Nothing awaiting review.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@include('partials.feedingcor-page-transition')
</body>
</html>
