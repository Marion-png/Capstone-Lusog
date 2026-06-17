<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <link rel="shortcut icon" href="{{ asset('images/lusog-logo.png') }}">
    <title>Record Submitted - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @php $classAdviserCssPath = resource_path('css/class-adviser.css'); @endphp
    @if (file_exists($classAdviserCssPath))
        <style>{!! file_get_contents($classAdviserCssPath) !!}</style>
    @endif
</head>
<body>
<aside class="sidebar">
    <div class="sb-grid"></div>
    <div class="sb-logo"><img src="{{ asset('images/lusog-logo.png') }}" alt="SIGLA Logo"></div>
    <nav class="sb-nav">
        <a href="{{ route('dashboard.class-adviser') }}" class="sb-link">
            <svg class="sb-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <rect x="3" y="3" width="7" height="7"/>
                <rect x="14" y="3" width="7" height="4"/>
                <rect x="14" y="12" width="7" height="9"/>
                <rect x="3" y="14" width="7" height="7"/>
            </svg>
            <span class="sb-link-label">Dashboard</span>
        </a>
        <a href="{{ route('dashboard.class-adviser', ['tab' => 'form']) }}" class="sb-link">
            <svg class="sb-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
            <span class="sb-link-label">School Health Card Form</span>
        </a>
        <a href="{{ route('dashboard.class-adviser', ['tab' => 'saved']) }}" class="sb-link">
            <svg class="sb-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <line x1="8" y1="6" x2="21" y2="6"/>
                <line x1="8" y1="12" x2="21" y2="12"/>
                <line x1="8" y1="18" x2="21" y2="18"/>
                <line x1="3" y1="6" x2="3.01" y2="6"/>
                <line x1="3" y1="12" x2="3.01" y2="12"/>
                <line x1="3" y1="18" x2="3.01" y2="18"/>
            </svg>
            <span class="sb-link-label">My Students</span>
        </a>
        <a href="{{ route('dashboard.class-adviser.deworming') }}" class="sb-link active">
            <svg class="sb-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M10.5 6.5l7 7a2.12 2.12 0 1 1-3 3l-7-7a2.12 2.12 0 0 1 3-3z"></path>
                <path d="M8.5 8.5l-3 3"></path>
            </svg>
            <span class="sb-link-label">Deworming Request</span>
        </a>
    </nav>
    <div class="sb-user">
        <div class="sb-avatar">{{ substr(auth()->user()->name ?? 'CA',0,2) }}</div>
        <div class="sb-user-name">{{ auth()->user()->name ?? 'Class Adviser' }}</div>
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
    <header class="top">
        <div class="topbar-breadcrumb crumb">
            <a href="{{ route('dashboard.class-adviser') }}" class="bc-home">Dashboard</a>
            <span class="bc-sep">&rsaquo;</span>
            <span class="bc-current">Class Adviser</span>
        </div>
        <div class="topbar-chip chip"><div class="dot"></div>Class Adviser</div>
    </header>
    <div class="content">
        <div class="card section" style="max-width:640px;margin-top:12px;">
            <div class="section" style="padding:14px;">
                <h1 class="h4 mb-3">Record submitted to School Nurse.</h1>
                <p class="muted">The student info and adviser measurements are now stored in session for prototype workflow testing.</p>
                <div style="margin-top:12px;display:flex;gap:8px;">
                    <a href="{{ route('adviser.create') }}" class="btn">Add Another Student</a>
                    <a href="{{ route('nurse.index') }}" class="btn btn-secondary">Open Nurse Dashboard</a>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
