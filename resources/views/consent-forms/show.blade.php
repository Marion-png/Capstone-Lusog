<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Consent Form - {{ $form->student_name }} - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @php $cfCss = resource_path('css/consent-form.css'); @endphp
    @if (file_exists($cfCss)) <style>{!! file_get_contents($cfCss) !!}</style> @endif
</head>
<body>
@php
    use App\Models\HealthConsentForm;
    $badge = $form->statusBadge();
    $isDraft = $form->status === HealthConsentForm::STATUS_DRAFT;
@endphp

<header class="cf-topbar">
    <img src="{{ asset('images/lusog-logo.png') }}" alt="SIGLA">
    <div>
        <div class="cf-topbar-title">Health Services Consent Form</div>
        <div class="cf-topbar-sub">{{ $form->student_name }} &middot; SY {{ $form->school_year }}</div>
    </div>
    <a href="{{ route('consent-forms.index') }}" class="cf-back">&larr; All Consent Forms</a>
</header>

<div class="cf-wrap">
    @if (session('success')) <div class="cf-flash cf-flash-ok">{{ session('success') }}</div> @endif
    @if (session('error')) <div class="cf-flash cf-flash-err">{{ session('error') }}</div> @endif

    <div class="cf-card">
        <div class="cf-card-head">
            <h2>Status</h2>
            <span class="cf-badge" style="background: {{ $badge['bg'] }}; color: {{ $badge['fg'] }};">{{ $badge['label'] }}</span>
        </div>
        <div class="cf-card-body">
            <div class="cf-actions">
                @if ($isDraft)
                    <button type="submit" form="adviserForm" formaction="{{ route('consent-forms.draft', $form) }}" class="cf-btn cf-btn-outline">Save as Draft</button>
                    <button type="submit" form="adviserForm" formaction="{{ route('consent-forms.send', $form) }}" class="cf-btn cf-btn-primary"
                            onclick="return confirm('Send this form to the parent/guardian? You will no longer be able to edit the selected services.');">
                        Send to Parent
                    </button>
                @elseif ($form->status === HealthConsentForm::STATUS_SIGNED)
                    <form method="POST" action="{{ route('consent-forms.review', $form) }}">
                        @csrf
                        <button type="submit" class="cf-btn cf-btn-primary">Mark as Reviewed &mdash; Release to School Nurse</button>
                    </form>
                @endif
                <a href="{{ route('consent-forms.print', $form) }}" target="_blank" class="cf-btn cf-btn-ghost">Print / Export PDF</a>
            </div>

            @if ($form->token && $form->status === HealthConsentForm::STATUS_SENT)
                <div class="cf-share">
                    <b style="font-size:.76rem; color:var(--g900);">Parent link:</b>
                    <input type="text" id="parentLink" readonly value="{{ route('consent-forms.parent', $form->token) }}">
                    <button type="button" class="cf-btn cf-btn-outline" onclick="navigator.clipboard.writeText(document.getElementById('parentLink').value); this.textContent='Copied!';">Copy</button>
                </div>
                <p style="font-size:.74rem; color:var(--muted); margin-top:6px;">
                    Share this link with the parent/guardian (e.g. via SMS or messenger). It opens the consent section for them to sign — no account needed.
                </p>
            @endif
        </div>
    </div>

    @if ($isDraft)
        <form method="POST" id="adviserForm">
            @csrf
            @include('consent-forms._document', ['form' => $form, 'mode' => 'adviser-edit'])
        </form>
    @else
        @include('consent-forms._document', ['form' => $form, 'mode' => 'locked'])
    @endif

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
