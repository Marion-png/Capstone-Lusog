<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Endline Entry - Feeding Coordinator - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    @php $pageCssPath = resource_path('css/feeding-feed-healthrec.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
    <style>
        .flash{padding:10px 14px;border-radius:10px;font-size:.82rem;margin-bottom:12px}
        .flash.ok{background:#f0fdf4;border:1px solid #dcfce7;color:#166534}
        .flash.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
        .entry-card{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;margin-bottom:8px;background:#fff}
        .entry-card.blocked{background:#f8fafc;opacity:.75}
        .entry-card .entry-name{flex:1 1 200px;font-weight:600;font-size:.85rem}
        .entry-card .entry-name span{display:block;font-weight:400;font-size:.7rem;color:#64748b}
        .entry-card input[type=number],.entry-card input[type=date]{width:92px;padding:6px 8px;border:1px solid #cbd5e1;border-radius:8px;font-size:.8rem;font-family:inherit}
        .entry-card input[type=date]{width:140px}
        .entry-base{font-size:.72rem;color:#475569;min-width:120px}
        .entry-status{font-size:.72rem;font-weight:600;color:#0f766e;min-width:110px}
        .entry-card button{padding:7px 14px;border:none;border-radius:8px;background:#0d9488;color:#fff;font-size:.78rem;font-weight:600;cursor:pointer}
        .entry-blocked-note{font-size:.74rem;color:#b45309;font-weight:600}
        .grade-block{margin-top:18px}
        .grade-block h3{font-size:.92rem;margin:0 0 8px;color:#0f172a}
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="sb-logo"><img src="{{ asset('images/lusog-logo.png') }}" alt="SIGLA Logo"></div>
    <nav class="sb-nav">
        <a href="{{ route('dashboard.feedingcor-dashboard') }}" class="sb-link">Dashboard</a>
        <a href="{{ route('dashboard.feedingcor-program') }}" class="sb-link">Feeding Program</a>
        <a href="{{ route('dashboard.feedingcor-health-records') }}" class="sb-link">Student Health Records</a>
        <a href="{{ route('dashboard.feedingcor-baseline') }}" class="sb-link">Baseline Entry</a>
        <a href="{{ route('dashboard.feedingcor-endline') }}" class="sb-link active">Endline Entry</a>
        <a href="{{ route('dashboard.feedingcor-reports') }}" class="sb-link">Reports</a>
        <a href="{{ route('dashboard.feedingcor-sbfp-forms') }}" class="sb-link">SBFP Forms</a>
    </nav>
    <div class="sb-user">
        <div class="sb-avatar">{{ strtoupper(substr(session('active_name', 'FC'), 0, 2)) }}</div>
        <div class="sb-user-meta">
            <div class="sb-user-name">{{ session('active_name', 'Feeding Coordinator') }}</div>
            <div class="sb-user-role" style="font-size:.68rem;color:var(--g300);line-height:1.2;">{{ session('active_school_name', 'No school assigned') }}</div>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="sb-logout" title="Sign out" aria-label="Sign out">&#x23Fb;</button>
        </form>
    </div>
</aside>

<div class="main">
    <div class="content">
        <div class="page-eyebrow">Feeding Program</div>
        <h1 class="page-title">Endline <span>Measurement</span></h1>
        <p class="page-sub">Record each beneficiary's Day&nbsp;120 measurement. Only learners who already have a baseline can be encoded — the rest are locked until their baseline is entered.</p>

        @if (session('success'))<div class="flash ok">{{ session('success') }}</div>@endif
        @if (session('error'))<div class="flash err">{{ session('error') }}</div>@endif

        @forelse (($studentsByGrade ?? []) as $grade => $students)
            <div class="grade-block">
                <h3>{{ $grade }}</h3>
                @foreach ($students as $s)
                    @if ($s['has_baseline'])
                        <form method="POST" action="{{ route('feedingcor.endline.store', $s['id']) }}" class="entry-card">
                            @csrf
                            <div class="entry-name">{{ $s['name'] }}<span>{{ $s['section'] }}</span></div>
                            <span class="entry-base">Baseline: {{ $s['baseline']['bmi'] ?: '-' }} ({{ $s['baseline']['status'] ?: '-' }})</span>
                            <input type="number" name="age" placeholder="Age" value="{{ $s['endline']['age'] }}" min="2" max="25" required aria-label="Age">
                            <input type="number" name="height_cm" step="0.1" placeholder="Height cm" value="{{ $s['endline']['height_cm'] }}" min="50" max="250" required aria-label="Height in cm">
                            <input type="number" name="weight_kg" step="0.1" placeholder="Weight kg" value="{{ $s['endline']['weight_kg'] }}" min="5" max="300" required aria-label="Weight in kg">
                            <input type="date" name="recorded_at" value="{{ $s['endline']['recorded_at'] }}" aria-label="Date measured">
                            <span class="entry-status">{{ $s['endline']['status'] ?: 'Pending' }}</span>
                            <button type="submit">{{ $s['endline']['bmi'] ? 'Update' : 'Save' }}</button>
                        </form>
                    @else
                        <div class="entry-card blocked">
                            <div class="entry-name">{{ $s['name'] }}<span>{{ $s['section'] }}</span></div>
                            <span class="entry-blocked-note">Locked — record a baseline first.</span>
                            <a href="{{ route('dashboard.feedingcor-baseline') }}" style="margin-left:auto;font-size:.76rem;color:#0d9488;font-weight:600;">Go to Baseline &rsaquo;</a>
                        </div>
                    @endif
                @endforeach
            </div>
        @empty
            <div class="flash">No beneficiaries on file for this school year yet.</div>
        @endforelse
    </div>
</div>
@includeIf('partials.sidebar-hover-pin')
</body>
</html>
