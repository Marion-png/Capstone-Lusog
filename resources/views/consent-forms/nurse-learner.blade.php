<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="{{ asset('images/sigla-mark.png') }}">
    <title>Consent Form - {{ $detail['name'] }} - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @php $cfCss = resource_path('css/consent-form.css'); @endphp
    @if (file_exists($cfCss)) <style>{!! file_get_contents($cfCss) !!}</style> @endif
    <style>{!! file_get_contents(resource_path('css/consent-copy.css')) !!}</style>
    {{-- One shared palette for pages not yet on lusog-theme.css. Loaded
         last so it overrides this page's own :root colours. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-palette.css')) !!}</style>
</head>
<body>
@php
    use App\Models\HealthConsentForm;
    use App\Support\ConsentStanding;

    $answer = $detail['answer'];
    $photo = $detail['photo'];
    $letter = $detail['letter'];
    $upload = $detail['upload'];
    $serviceName = $service !== '' ? HealthConsentForm::PLANNED_SERVICES[$service] : 'All services';
    $classLine = trim($detail['grade'].' – '.$detail['section'], ' –');
    $answered = $letter !== null && in_array($letter->status, [HealthConsentForm::STATUS_SIGNED, HealthConsentForm::STATUS_REVIEWED], true);
@endphp

{{-- Read-only for every role that reaches it. Nothing here uploads, replaces
     or edits a form, and nothing calls the photo reader: what is shown is
     what the class adviser confirmed. --}}
<header class="cf-topbar">
    <a href="{{ $backUrl }}" class="cf-back cf-back-start" id="consentClose">&larr; All Consent Forms</a>
    <img src="{{ asset('images/sigla-mark.png') }}" alt="SIGLA">
    <div>
        <div class="cf-topbar-title">{{ $detail['name'] }}</div>
        <div class="cf-topbar-sub">
            {{ $classLine !== '' ? $classLine : 'Class not recorded' }}
            &middot; Adviser: {{ $detail['adviser'] !== '' ? $detail['adviser'] : 'not on record' }}
            &middot; Signed in as {{ $roleLabel }} &middot; view only
        </div>
    </div>
</header>

<div class="cf-wrap">
    @include('consent-forms._simulated-banner')

    <div class="cf-card">
        <div class="cf-card-head">
            <h2>{{ $serviceName }}</h2>
            <span class="cf-badge is-{{ $detail['status'] }}">{{ ConsentStanding::SERVICE_LABELS[$detail['status']] }}</span>
            <span class="cf-muted">LRN {{ $detail['lrn_masked'] }} &middot; SY {{ $detail['school_year'] }}</span>
            <a href="{{ $backUrl }}" class="cf-btn cf-btn-ghost cf-close">Close</a>
        </div>
        @if ($detail['source'] === null || ($answer === null && $photo['url'] === null))
            {{-- Nothing on file that answers: no form at all, or a letter still
                 out with the parent. --}}
            <div class="cf-card-body">
                <p class="cf-empty-note"><b>No form yet.</b> Follow up with the class adviser.</p>
                @if ($letter !== null && $letter->status === HealthConsentForm::STATUS_SENT)
                    <p class="cf-muted">A letter was sent to the parent on {{ optional($letter->sent_at)->format('M j, Y') }} and has not been answered.</p>
                @endif
            </div>
        @endif
    </div>

    @if ($photo['url'] !== null || $photo['online'])
        <div class="cf-card">
            <div class="cf-card-head"><h2>Uploaded form</h2></div>
            <div class="cf-card-body">
                @if ($photo['url'] !== null && $photo['is_image'])
                    {{-- Loaded on this page only, through the role-checked
                         endpoint, never from a public address. --}}
                    <figure class="cf-photo">
                        <img src="{{ $photo['url'] }}" alt="Photo of the signed Sulat-Pahibalo for {{ $detail['name'] }}" loading="lazy">
                        @if ($simulatedData) <span class="cf-photo-mark" aria-hidden="true">SAMPLE · FICTITIOUS DATA</span> @endif
                    </figure>
                    <button type="button" class="cf-btn cf-btn-outline" id="photoEnlarge">Enlarge</button>
                    <dialog class="cf-dialog" id="photoDialog" aria-label="Signed form, full size">
                        <figure class="cf-photo cf-photo-full">
                            <img src="{{ $photo['url'] }}" alt="Photo of the signed Sulat-Pahibalo for {{ $detail['name'] }}, full size">
                            @if ($simulatedData) <span class="cf-photo-mark" aria-hidden="true">SAMPLE · FICTITIOUS DATA</span> @endif
                        </figure>
                        <button type="button" class="cf-btn cf-btn-primary" id="photoDialogClose">Close</button>
                    </dialog>
                @elseif ($photo['url'] !== null)
                    <p>The signed form was uploaded as a document.
                        <a href="{{ $photo['url'] }}" target="_blank" rel="noopener" class="cf-btn cf-btn-outline">Open the scanned form</a></p>
                @else
                    <p class="cf-muted">The parent signed this letter online, so there is no photograph.</p>
                @endif
                @if ($answered)
                    <p style="margin-top:12px;"><a href="{{ route('consent-forms.nurse-show', $letter) }}" class="cf-btn cf-btn-ghost">Open the full letter</a></p>
                @endif
            </div>
        </div>
    @endif

    @if ($photo['url'] !== null && $answer === null)
        <div class="cf-card">
            <div class="cf-card-body">
                <p class="cf-empty-note"><b>The parent's answer on this form has not been recorded.</b> Follow up with the class adviser.</p>
                @if ($detail['copy'] !== null)
                    @include('consent-forms._paper-copy', ['copy' => $detail['copy']])
                @endif
            </div>
        </div>
    @endif

    @if ($answer !== null)
        <div class="cf-card">
            <div class="cf-card-head"><h2>{{ $photo['online'] ? 'As the parent answered online' : 'Read from the uploaded photo' }}</h2></div>
            <div class="cf-card-body">
                @if ($detail['missing'] === [])
                    <div class="cf-flash cf-flash-ok">All required fields completed.</div>
                @else
                    <div class="cf-flash cf-flash-err">
                        Missing from this form: {{ implode(', ', $detail['missing']) }}. Follow up with the class adviser.
                    </div>
                @endif
                @if ($detail['copy'] !== null)
                    @include('consent-forms._paper-copy', ['copy' => $detail['copy']])
                @endif
            </div>
        </div>
    @endif

    @if ($detail['filed'] !== null)
        <p class="cf-footnote">
            {{ $detail['filed']['how'] }} by {{ $detail['filed']['by'] }}{{ $detail['filed']['on'] !== '' ? ' on '.$detail['filed']['on'] : '' }}.
            Valid for all service cycles in SY {{ $detail['school_year'] }}.
            Only the class adviser can upload or replace this form.
        </p>
    @endif
</div>

<script>
    // Enlarge opens the full-size photo; Close, Esc or a click outside it
    // closes it. Esc on the page itself goes back to the list.
    (function () {
        const dialog = document.getElementById('photoDialog');
        const open = document.getElementById('photoEnlarge');
        const close = document.getElementById('photoDialogClose');
        const back = document.getElementById('consentClose');

        if (dialog && open && typeof dialog.showModal === 'function') {
            open.addEventListener('click', () => dialog.showModal());
            close?.addEventListener('click', () => dialog.close());
            dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
        } else if (open) {
            open.hidden = true;
        }

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !(dialog && dialog.open) && back) {
                window.location.href = back.href;
            }
        });
    })();
</script>
</body>
</html>
