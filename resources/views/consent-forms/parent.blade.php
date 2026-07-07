<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Sulat-Pahibalo - {{ $form->student_name }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @php $cfCss = resource_path('css/consent-form.css'); @endphp
    @if (file_exists($cfCss)) <style>{!! file_get_contents($cfCss) !!}</style> @endif
</head>
<body>
@php
    use App\Models\HealthConsentForm;
    $locked = $form->isLockedForParent();
@endphp

<header class="cf-topbar">
    <img src="{{ asset('images/lusog-logo.png') }}" alt="SIGLA">
    <div>
        <div class="cf-topbar-title">{{ $form->school_name }}</div>
        <div class="cf-topbar-sub">Health Services Consent Form (Sulat-Pahibalo) &middot; SY {{ $form->school_year }}</div>
    </div>
</header>

<div class="cf-wrap">
    @if (session('success')) <div class="cf-flash cf-flash-ok">{{ session('success') }}</div> @endif
    @if (session('error')) <div class="cf-flash cf-flash-err">{{ session('error') }}</div> @endif
    @if ($errors->any()) <div class="cf-flash cf-flash-err">{{ $errors->first() }}</div> @endif

    @if ($locked)
        <div class="cf-flash cf-flash-ok">
            This consent form was submitted on <b>{{ optional($form->signed_at)->format('F j, Y g:i A') }}</b> and can no longer be edited.
        </div>
        @include('consent-forms._document', ['form' => $form, 'mode' => 'locked'])
    @else
        <div class="cf-card">
            <div class="cf-card-body" style="font-size:.85rem; color:var(--muted);">
                Good day, <b style="color:var(--text);">{{ $form->parent_guardian_name ?: 'Parent/Guardian' }}</b>!
                Please read the letter below, complete the <b>consent section at the bottom</b>, draw your
                electronic signature, then press <b>Submit Consent Form</b>.
            </div>
        </div>

        <form method="POST" action="{{ route('consent-forms.parent-submit', $form->token) }}" id="parentForm">
            @csrf
            @include('consent-forms._document', ['form' => $form, 'mode' => 'parent'])

            <div class="cf-card" style="margin-top:16px;">
                <div class="cf-card-body">
                    <div class="cf-actions">
                        <button type="submit" class="cf-btn cf-btn-primary" id="submitBtn">Submit Consent Form</button>
                        <span style="font-size:.76rem; color:var(--muted);">A consent choice and your signature are required before submitting.</span>
                    </div>
                </div>
            </div>
        </form>
    @endif
</div>

@unless ($locked)
<script>
(function () {
    var canvas = document.getElementById('sigPad');
    var hint = document.getElementById('sigHint');
    var dataInput = document.getElementById('sigData');
    var form = document.getElementById('parentForm');
    var ctx = canvas.getContext('2d');
    var drawing = false;
    var hasInk = false;

    // Match the canvas bitmap to its displayed size for crisp strokes.
    function resize() {
        var snapshot = hasInk ? canvas.toDataURL() : null;
        var ratio = window.devicePixelRatio || 1;
        canvas.width = canvas.offsetWidth * ratio;
        canvas.height = 160 * ratio;
        ctx.scale(ratio, ratio);
        ctx.lineWidth = 2.2;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#1e2a35';
        if (snapshot) {
            var img = new Image();
            img.onload = function () { ctx.drawImage(img, 0, 0, canvas.offsetWidth, 160); };
            img.src = snapshot;
        }
    }
    resize();
    window.addEventListener('resize', resize);

    function pos(e) {
        var rect = canvas.getBoundingClientRect();
        return { x: e.clientX - rect.left, y: e.clientY - rect.top };
    }

    canvas.addEventListener('pointerdown', function (e) {
        e.preventDefault();
        canvas.setPointerCapture(e.pointerId);
        drawing = true;
        hasInk = true;
        hint.style.display = 'none';
        var p = pos(e);
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
        ctx.lineTo(p.x + 0.1, p.y + 0.1);
        ctx.stroke();
    });
    canvas.addEventListener('pointermove', function (e) {
        if (!drawing) return;
        e.preventDefault();
        var p = pos(e);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
    });
    ['pointerup', 'pointercancel'].forEach(function (evt) {
        canvas.addEventListener(evt, function () { drawing = false; });
    });

    document.getElementById('sigClear').addEventListener('click', function () {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        hasInk = false;
        hint.style.display = 'grid';
        dataInput.value = '';
    });

    form.addEventListener('submit', function (e) {
        if (!document.querySelector('input[name="consent_choice"]:checked')) {
            e.preventDefault();
            alert('Please choose one consent option before submitting.');
            return;
        }
        if (!hasInk) {
            e.preventDefault();
            alert('Please draw your signature before submitting.');
            return;
        }
        dataInput.value = canvas.toDataURL('image/png');
    });
})();
</script>
@endunless
</body>
</html>
