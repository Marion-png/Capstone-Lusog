<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Consent Forms - School Nurse - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @php $cfCss = resource_path('css/consent-form.css'); @endphp
    @if (file_exists($cfCss)) <style>{!! file_get_contents($cfCss) !!}</style> @endif
    {{-- One shared palette for pages not yet on lusog-theme.css. Loaded
         last so it overrides this page's own :root colours. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-palette.css')) !!}</style>
</head>
{{-- .cf-rise plays the bottom-up entrance defined in css/consent-form.css,
     picking up where the nurse rail's exit transition leaves off. --}}
<body class="cf-rise">
@php use App\Models\HealthConsentForm; @endphp

<header class="cf-topbar">
    <img src="{{ asset('images/lusog-logo.png') }}" alt="SIGLA">
    <div>
        <div class="cf-topbar-title">Health Services Consent Forms</div>
        <div class="cf-topbar-sub">School Nurse &middot; read-only access</div>
    </div>
    <a href="{{ route('dashboard.school-nurse') }}" class="cf-back">&larr; Back to Dashboard</a>
</header>

<div class="cf-wrap">
    <h1 class="cf-page-title">Completed Consent Forms</h1>
    <p class="cf-page-sub">Consent forms signed by parents/guardians. These are read-only — consent information cannot be altered.</p>

    @if (session('error')) <div class="cf-flash cf-flash-err">{{ session('error') }}</div> @endif

    <div class="cf-card">
        <div class="cf-card-head"><h2>Signed &amp; Reviewed Forms</h2></div>
        @if ($forms->isEmpty())
            <div class="cf-empty">No signed consent forms yet.</div>
        @else
            <table class="cf-table">
                <thead>
                    <tr>
                        <th>Learner</th>
                        <th>Grade / Section</th>
                        <th>Consent</th>
                        <th>Signed</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($forms as $form)
                        @php
                            $badge = $form->statusBadge();
                            $consentLabels = [
                                HealthConsentForm::CONSENT_ALL => ['Consented to all services', '#14653C', '#E7F5EC'],
                                HealthConsentForm::CONSENT_SPECIFIC => ['Consented with exceptions', '#8A5A06', '#FDF4E2'],
                                HealthConsentForm::CONSENT_DENY => ['Did not consent', '#A32B2B', '#FCECEC'],
                            ];
                            [$cLabel, $cFg, $cBg] = $consentLabels[$form->consent_choice] ?? ['—', '#475569', '#f1f5f9'];
                        @endphp
                        <tr>
                            <td><b>{{ $form->student_name }}</b><br><span style="color:var(--muted); font-size:.74rem;">LRN {{ $form->student_lrn }}</span></td>
                            <td>{{ $form->grade_level }} / {{ $form->section }}</td>
                            <td><span class="cf-badge" style="background: {{ $cBg }}; color: {{ $cFg }};">{{ $cLabel }}</span></td>
                            <td>{{ optional($form->signed_at)->format('M j, Y g:i A') }}</td>
                            <td><span class="cf-badge" style="background: {{ $badge['bg'] }}; color: {{ $badge['fg'] }};">{{ $badge['label'] }}</span></td>
                            <td style="text-align:right;">
                                <a href="{{ route('consent-forms.nurse-show', $form) }}" class="cf-btn cf-btn-outline">View</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
</body>
</html>
