<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Record Paper Form - {{ $form->student_name }} - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @php $cfCss = resource_path('css/consent-form.css'); @endphp
    @if (file_exists($cfCss)) <style>{!! file_get_contents($cfCss) !!}</style> @endif
    @php $paperCss = resource_path('css/consent-paper.css'); @endphp
    @if (file_exists($paperCss)) <style>{!! file_get_contents($paperCss) !!}</style> @endif
    <style>{!! file_get_contents(resource_path('css/lusog-palette.css')) !!}</style>
</head>
<body>
@php use App\Models\HealthConsentForm; @endphp

<header class="cf-topbar">
    <img src="{{ asset('images/lusog-logo.png') }}" alt="SIGLA">
    <div>
        <div class="cf-topbar-title">Record the Signed Paper Form</div>
        <div class="cf-topbar-sub">{{ $form->student_name }} &middot; SY {{ $form->school_year }}</div>
    </div>
    <a href="{{ route('consent-forms.show', $form) }}" class="cf-back">&larr; Back to the form</a>
</header>

<div class="cf-wrap">
    @if ($errors->any())
        <div class="cf-flash cf-flash-err">{{ $errors->first() }}</div>
    @endif

    {{-- What this screen is, said before anything is filled in. The reading is
         a proposal; the adviser holding the paper is the one who decides. --}}
    <div class="cf-card">
        <div class="cf-card-head"><h2>1 &middot; The photograph</h2></div>
        <div class="cf-card-body">
            <p class="pf-lede">
                Photograph the signed form and attach it here. Nothing is saved until you
                have checked every answer below against the paper and pressed
                <b>Save the parent's answers</b>.
            </p>

            <label class="pf-drop" for="paperFile">
                <input type="file" id="paperFile" accept="image/jpeg,image/png,image/webp,image/heic" hidden>
                <span class="pf-drop-label" id="paperFileLabel">Choose a photograph of the signed form</span>
                <span class="pf-drop-hint">JPG, PNG or WebP &middot; up to 8&nbsp;MB</span>
            </label>

            @if ($scannerReady)
                <div class="pf-scan-row">
                    <button type="button" class="cf-btn cf-btn-outline" id="scanBtn" disabled>Read the form for me</button>
                    <span class="pf-scan-state" id="scanState">Optional. It fills the answers in; you still check them.</span>
                </div>
            @else
                <div class="pf-note">Automatic reading is not set up on this server &mdash; fill the answers in from the paper yourself.</div>
            @endif

            <div class="pf-preview" id="paperPreview" hidden>
                <img id="paperPreviewImg" alt="The signed form as photographed">
            </div>
        </div>
    </div>

    {{-- The reading and the record side by side: the photograph stays on
         screen while the answers are checked, because checking a transcription
         against a document you cannot see is not checking it. --}}
    <form method="POST" action="{{ route('consent-forms.paper.store', $form) }}" enctype="multipart/form-data" id="paperForm" class="cf-card" style="margin-top:16px;">
        @csrf
        <input type="file" name="paper_form" id="paperFormFile" accept="image/jpeg,image/png,image/webp,image/heic" hidden required>

        <div class="cf-card-head"><h2>2 &middot; The parent's answers</h2></div>
        <div class="cf-card-body">
            <div class="pf-flags" id="scanFlags" hidden></div>

            <fieldset class="pf-field">
                <legend>Consent given</legend>
                @foreach ([
                    HealthConsentForm::CONSENT_ALL => 'Agreed to all the services listed',
                    HealthConsentForm::CONSENT_SPECIFIC => 'Agreed, except for certain services',
                    HealthConsentForm::CONSENT_DENY => 'Did not agree',
                ] as $value => $label)
                    <label class="pf-radio">
                        <input type="radio" name="consent_choice" value="{{ $value }}" @checked(old('consent_choice') === $value) required>
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
                <div class="pf-flag" data-flag-for="consent_choice" hidden></div>
            </fieldset>

            <div class="pf-field" data-when="specific">
                <label for="consent_exceptions">Services the parent excepted</label>
                <input type="text" name="consent_exceptions" id="consent_exceptions" maxlength="1000" value="{{ old('consent_exceptions') }}">
                <div class="pf-flag" data-flag-for="consent_exceptions" hidden></div>
            </div>

            <div class="pf-field" data-when="deny">
                <label for="refusal_reason">Reason the parent gave</label>
                <input type="text" name="refusal_reason" id="refusal_reason" maxlength="1000" value="{{ old('refusal_reason') }}">
                <div class="pf-flag" data-flag-for="refusal_reason" hidden></div>
            </div>

            @foreach ([
                'allergy_food' => 'Food the child is allergic to',
                'allergy_medicine' => 'Medicine the child is allergic to',
                'prev_immunization' => 'Reaction to a previous immunization',
                'other_illness' => 'Current or other illness',
            ] as $field => $label)
                <div class="pf-field">
                    <label for="{{ $field }}">{{ $label }}</label>
                    <input type="text" name="{{ $field }}" id="{{ $field }}" maxlength="1000" value="{{ old($field) }}">
                    <div class="pf-flag" data-flag-for="{{ $field }}" hidden></div>
                </div>
            @endforeach

            <div class="pf-field">
                <label for="parent_guardian_name">Parent / guardian who signed</label>
                <input type="text" name="parent_guardian_name" id="parent_guardian_name" maxlength="255"
                       value="{{ old('parent_guardian_name', $form->parent_guardian_name) }}" required>
                <div class="pf-flag" data-flag-for="parent_guardian_name" hidden></div>
            </div>

            <label class="pf-confirm">
                <input type="checkbox" name="confirmed" value="1" required>
                <span>I have read the paper form and these are the answers on it.</span>
            </label>

            <div class="cf-actions" style="margin-top:14px;">
                <button type="submit" class="cf-btn cf-btn-primary">Save the parent's answers</button>
                <a href="{{ route('consent-forms.show', $form) }}" class="cf-btn cf-btn-ghost">Cancel</a>
            </div>
        </div>
    </form>
</div>

<script>
(() => {
    const picker = document.getElementById('paperFile');
    const hidden = document.getElementById('paperFormFile');
    const label = document.getElementById('paperFileLabel');
    const preview = document.getElementById('paperPreview');
    const previewImg = document.getElementById('paperPreviewImg');
    const scanBtn = document.getElementById('scanBtn');
    const scanState = document.getElementById('scanState');
    const flags = document.getElementById('scanFlags');
    const form = document.getElementById('paperForm');
    if (!picker || !hidden || !form) return;

    // One file, chosen once: the picker above and the field the form posts are
    // the same photograph, so what was read can never be a different sheet
    // from what is saved.
    const carry = (file) => {
        const bag = new DataTransfer();
        bag.items.add(file);
        hidden.files = bag.files;
    };

    picker.addEventListener('change', () => {
        const file = picker.files && picker.files[0];
        if (!file) return;

        carry(file);
        if (label) label.textContent = file.name;
        if (previewImg) previewImg.src = URL.createObjectURL(file);
        if (preview) preview.hidden = false;
        if (scanBtn) scanBtn.disabled = false;
    });

    const setFlag = (field, text) => {
        const el = form.querySelector('[data-flag-for="' + field + '"]');
        if (!el) return;
        el.textContent = text || '';
        el.hidden = !text;
    };

    const setValue = (field, value) => {
        const el = form.querySelector('[name="' + field + '"]');
        if (el && typeof value === 'string') el.value = value;
    };

    // Filling the form in is all the reader does. Every field stays editable,
    // nothing is submitted, and anything it was unsure of is named on the
    // field itself rather than in a summary the eye slides past.
    const apply = (draft) => {
        flags.textContent = '';
        flags.hidden = true;

        const notes = [];
        if (draft.unreadable) notes.push('The photograph could not be read. Fill the answers in from the paper.');
        if (!draft.signature_present) notes.push('No signature was found in the signature area — check the paper before saving.');
        if (draft.note) notes.push(draft.note);

        if (!draft.unreadable) {
            if (draft.consent_choice && draft.consent_choice !== 'unclear') {
                const radio = form.querySelector('input[name="consent_choice"][value="' + draft.consent_choice + '"]');
                if (radio) { radio.checked = true; radio.dispatchEvent(new Event('change', { bubbles: true })); }
                setFlag('consent_choice', '');
            } else {
                setFlag('consent_choice', 'Could not tell which box was shaded — read it off the paper.');
            }

            Object.entries(draft.written || {}).forEach(([field, entry]) => {
                if (!entry) return;
                setValue(field, entry.text || '');

                // Three states, three things to say. "Unreadable" is in the
                // field rather than leaving it blank, because a blank field is
                // the claim that the parent wrote nothing there.
                const el = form.querySelector('[name="' + field + '"]');
                if (el) el.classList.toggle('is-unreadable', entry.readable === false);

                if (entry.readable === false) {
                    setFlag(field, 'Something is written here and it could not be read — type it from the paper.');
                } else if (entry.text && !entry.confident) {
                    setFlag(field, 'Handwriting was hard to read — check this against the paper.');
                } else {
                    setFlag(field, '');
                }
            });

            if (draft.parent_guardian_name) setValue('parent_guardian_name', draft.parent_guardian_name);
        }

        if (notes.length) {
            notes.forEach((text) => {
                flags.appendChild(Object.assign(document.createElement('div'), {
                    className: 'pf-flags-item',
                    textContent: text,
                }));
            });
            flags.hidden = false;
        }
    };

    if (scanBtn) {
        scanBtn.addEventListener('click', async () => {
            const file = hidden.files && hidden.files[0];
            if (!file) return;

            scanBtn.disabled = true;
            scanState.textContent = 'Reading the form…';

            const body = new FormData();
            body.append('photo', file);

            try {
                const response = await fetch(@json(route('consent-forms.scan')), {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        Accept: 'application/json',
                    },
                    body,
                });
                const data = await response.json();

                if (!response.ok) {
                    scanState.textContent = data.message || 'The form could not be read. Fill it in from the paper.';
                    scanBtn.disabled = false;
                    return;
                }

                apply(data.draft || {});
                scanState.textContent = data.draft && data.draft.needs_review
                    ? 'Filled in — some answers need checking, see the notes.'
                    : 'Filled in. Check every answer against the paper before saving.';
            } catch (_e) {
                scanState.textContent = 'The form could not be read. Fill it in from the paper.';
            }

            scanBtn.disabled = false;
        });
    }

    // The two conditional blanks belong to the choice above them.
    const syncConditionals = () => {
        const choice = form.querySelector('input[name="consent_choice"]:checked');
        form.querySelectorAll('[data-when]').forEach((block) => {
            const show = choice && block.dataset.when === choice.value;
            block.hidden = !show;
            const input = block.querySelector('input');
            if (input) input.required = Boolean(show);
        });
    };

    form.querySelectorAll('input[name="consent_choice"]').forEach((radio) => {
        radio.addEventListener('change', syncConditionals);
    });
    syncConditionals();
})();
</script>
</body>
</html>
