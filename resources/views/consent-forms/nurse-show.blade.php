<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Consent Form - {{ $form->student_name }} - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @php $cfCss = resource_path('css/consent-form.css'); @endphp
    @if (file_exists($cfCss)) <style>{!! file_get_contents($cfCss) !!}</style> @endif
</head>
<body>
@php $badge = $form->statusBadge(); @endphp

<header class="cf-topbar">
    <img src="{{ asset('images/lusog-logo.png') }}" alt="SIGLA">
    <div>
        <div class="cf-topbar-title">Consent Form &mdash; {{ $form->student_name }}</div>
        <div class="cf-topbar-sub">Read-only &middot; SY {{ $form->school_year }}</div>
    </div>
    <a href="{{ route('consent-forms.nurse-index') }}" class="cf-back">&larr; All Consent Forms</a>
</header>

<div class="cf-wrap">
    <div class="cf-card">
        <div class="cf-card-head">
            <h2>Status</h2>
            <span class="cf-badge" style="background: {{ $badge['bg'] }}; color: {{ $badge['fg'] }};">{{ $badge['label'] }}</span>
        </div>
        <div class="cf-card-body">
            <div class="cf-actions">
                <a href="{{ route('consent-forms.print', $form) }}" target="_blank" class="cf-btn cf-btn-ghost">Print / Export PDF</a>
                <span style="font-size:.76rem; color:var(--muted);">Consent information is read-only for the Clinical Teacher.</span>
            </div>
        </div>
    </div>

    @include('consent-forms._document', ['form' => $form, 'mode' => 'locked'])

    <div class="cf-card" style="margin-top:16px;">
        <div class="cf-card-head"><h2>Audit Trail</h2></div>
        <div class="cf-card-body">
            <ul class="cf-audit">
                @forelse ($form->audit ?? [] as $entry)
                    <li>
                        <span class="cf-audit-time">{{ $entry['at'] }}</span>
                        <span class="cf-audit-actor">{{ str_replace('_', ' ', ucwords($entry['actor_role'], '_')) }} &middot; {{ $entry['actor_name'] }}</span>
                        <span>{{ $entry['action'] }}</span>
                    </li>
                @empty
                    <li>No activity yet.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
</body>
</html>
