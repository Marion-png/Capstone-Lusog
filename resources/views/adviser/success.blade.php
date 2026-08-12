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
    {{-- One shared palette for pages not yet on lusog-theme.css. Loaded
         last so it overrides this page's own :root colours. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-palette.css')) !!}</style>
</head>
<body>
@include('partials.adviser-sidebar', ['active' => 'form'])

<div class="asb-main">
    @include('partials.adviser-topbar', ['breadcrumb' => 'Record Submitted'])
    <div class="content">
        <div class="card section" style="max-width:640px;margin-top:12px;">
            <div class="section" style="padding:14px;">
                <h1 class="h4 mb-3">Record submitted to School Nurse.</h1>
                <p class="muted">The student info and adviser measurements are now stored in session for prototype workflow testing.</p>
                <div style="margin-top:12px;display:flex;gap:8px;">
                    <a href="{{ route('adviser.create') }}" class="btn">Enroll Another Student</a>
                    <a href="{{ route('nurse.index') }}" class="btn btn-secondary">Open Nurse Dashboard</a>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
