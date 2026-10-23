<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="{{ asset('images/sigla-mark.png') }}">
    <title>Consent Form - {{ $form->student_name }} - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @php $cfCss = resource_path('css/consent-form.css'); @endphp
    @if (file_exists($cfCss)) <style>{!! file_get_contents($cfCss) !!}</style> @endif
    {{-- One shared palette for pages not yet on lusog-theme.css. Loaded
         last so it overrides this page's own :root colours. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-palette.css')) !!}</style>
</head>
<body>
@php
    $badge = $form->statusBadge();
    // Back to the list this form was opened from, in the year it belongs to —
    // a form from an earlier year is reached through that year's list, and
    // the School Head reads consents on their own tab, not the clinic's.
    $backUrl = session('active_role') === 'school_head'
        ? route('dashboard.school-head.consent', ['school_year' => $form->school_year])
        : route('consent-forms.nurse-index', $form->school_year === \App\Models\HealthConsentForm::currentSchoolYear() ? [] : ['school_year' => $form->school_year]);
@endphp

<header class="cf-topbar">
    <a href="{{ $backUrl }}" class="cf-back cf-back-start">&larr; All Consent Forms</a>
    <img src="{{ asset('images/sigla-mark.png') }}" alt="SIGLA">
    <div>
        <div class="cf-topbar-title">Consent Form &mdash; {{ $form->student_name }}</div>
        <div class="cf-topbar-sub">Read-only &middot; SY {{ $form->school_year }}</div>
    </div>
</header>

<div class="cf-wrap">
    <div class="cf-card">
        <div class="cf-card-head">
            <h2>Status</h2>
            <span class="cf-badge" style="background: {{ $badge['bg'] }}; color: {{ $badge['fg'] }};">{{ $badge['label'] }}</span>
        </div>
        <div class="cf-card-body">
            <div class="cf-actions">
                @if ($form->paper_form_path)
                    {{-- A paper consent has no drawn signature: the photograph is
                         the evidence, so whoever acts on the answers below can
                         check them against what the parent actually ticked. --}}
                    <a href="{{ session('active_role') === 'school_head'
                            ? route('consent-forms.head-paper-image', $form)
                            : route('consent-forms.paper.image', $form) }}"
                       target="_blank" rel="noopener" class="cf-btn cf-btn-outline">View Signed Paper Form</a>
                @endif
                <a href="{{ route('consent-forms.print', $form) }}" target="_blank" class="cf-btn cf-btn-ghost">Print / Export PDF</a>
                <span style="font-size:.76rem; color:var(--muted);">Consent information is read-only for the School Nurse.</span>
            </div>
            @if ($form->paper_form_path)
                <p style="font-size:.78rem; color:var(--muted); margin-top:10px;">
                    This consent came back on paper. The answers below were entered by the class adviser
                    from the signed form &mdash; open the photograph to see the parent's own marks and signature.
                </p>
            @endif
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
