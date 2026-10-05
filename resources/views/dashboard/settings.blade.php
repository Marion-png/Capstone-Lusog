<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Settings - {{ $profile['role_label'] }} - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <script>document.documentElement.classList.add('js');</script>
    @php
        // One page, six rails. The body is partials/settings-panels either
        // way — this only decides whose menu is beside it, so a nurse's click
        // never lands on the head's navigation.
        $setRole = $profile['role'];
        $setIsClinic = in_array($setRole, ['school_nurse', 'clinic_staff', 'clinic_teacher'], true);
        $setRail = match ($setRole) {
            'class_adviser' => 'partials.adviser-sidebar',
            'school_head' => 'partials.schoolhead-sidebar',
            'feeding_coor' => 'partials.feedingcor-sidebar',
            'system_admin' => 'partials.system-admin-sidebar',
            default => 'partials.clinic-rail',
        };
    @endphp
    {{-- LUSOG order: theme, then this page's sheet, then the reader's rail. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
    <style>{!! file_get_contents(resource_path('css/settings.css')) !!}</style>
    @if ($setIsClinic)
        <style>{!! file_get_contents(resource_path('css/nurse-sidebar.css')) !!}</style>
    @else
        <style>{!! file_get_contents(resource_path('css/role-sidebar.css')) !!}</style>
    @endif
</head>
<body>
@include($setRail, ['active' => 'settings'])

<div class="main">
    <header class="topbar">
        <div class="topbar-bc"><span>{{ $profile['role_label'] }}</span><span class="bc-sep">&rsaquo;</span><span>Settings</span></div>
        @include('partials.live-clock')
    </header>

    <div class="content">
        <div class="page-header">
            <div class="page-eyebrow">Account</div>
            <h1 class="page-title">Your <span>Settings</span></h1>
            <p class="page-sub">Your account, password@if ($profile['shows_assignment']) and teaching assignment@endif.</p>
        </div>

        @include('partials.settings-panels')
    </div>
</div>

@if ($setIsClinic)
    @include('partials.nurse-page-transition')
@else
    @include('partials.role-page-transition')
@endif
</body>
</html>
