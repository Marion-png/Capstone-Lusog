{{--
    The RxNorm allergy check on the medicine about to be given
    (App\Support\AllergyCheck), shown before the save: a red warning that
    needs an override reason, a yellow caution or a grey "check manually"
    that needs an acknowledgement, a block with no override, or a clear note.

    Shared by the New Consultation dialog and the standalone page. It carries
    the learner's LRN (`student_lrn`), set by the host when a learner is chosen
    off the roll and announced with a `consult:learner` event; the host's
    medicine field is named by id. The answer here decides nothing — the save
    runs the same check again.

    Expects: $prefix (unique per page), $medicineField (the select's id),
    $lrn (the learner the page opened for, if any).
--}}
@if (\App\Support\AllergyCheck::supported())
    <input type="hidden" name="student_lrn" id="{{ $prefix }}_student_lrn" value="{{ old('student_lrn', $lrn ?? '') }}">
    <div class="allergy-check" id="{{ $prefix }}_allergy_check" hidden aria-live="polite"
         data-url="{{ route('student-allergies.check') }}"
         data-medicine="{{ $medicineField }}"
         data-lrn="{{ $prefix }}_student_lrn">
        <div class="allergy-check-box" data-allergy-result>
            <div class="allergy-check-message" data-allergy-message></div>
            <div class="allergy-check-note">{{ \App\Support\AllergyCheck::NOTE }}</div>
        </div>
        <label class="allergy-check-ack" data-allergy-ack hidden>
            <input type="checkbox" name="allergy_acknowledged" value="1" @checked(old('allergy_acknowledged'))>
            <span>I have checked the learner's allergies.</span>
        </label>
        <div class="allergy-check-override" data-allergy-override hidden>
            <label for="{{ $prefix }}_allergy_override_reason">Override reason</label>
            <textarea id="{{ $prefix }}_allergy_override_reason" name="allergy_override_reason" maxlength="500"
                      minlength="{{ \App\Support\AllergyCheck::OVERRIDE_MIN }}" rows="2">{{ old('allergy_override_reason') }}</textarea>
        </div>
        @foreach (['allergy_acknowledged', 'allergy_override_reason'] as $allergyField)
            @if ($errors->consultation->has($allergyField))
                <div class="allergy-check-error">{{ $errors->consultation->first($allergyField) }}</div>
            @endif
        @endforeach
    </div>

    <style>
        .allergy-check { margin: 4px 0 10px; display: grid; gap: 8px; }
        .allergy-check[hidden], .allergy-check [hidden] { display: none; }
        .allergy-check-box { padding: 9px 12px; border-radius: 8px; border: 1px solid #DDE3DF; background: #F6F8F7; font-size: .8rem; line-height: 1.4; color: #1d3c31; }
        .allergy-check-box.is-warning, .allergy-check-box.is-blocked { border-color: #F2C9C9; background: var(--lg-danger-tint, #FBECEC); color: var(--lg-danger-ink, #9B2C2C); }
        .allergy-check-box.is-caution { border-color: #EFDCB2; background: var(--lg-amber-tint, #FDF5E3); color: var(--lg-amber-ink, #7A5410); }
        .allergy-check-box.is-unverified { border-color: #DDE3DF; background: #EEF1EF; color: #3E4B44; }
        .allergy-check-box.is-clear { border-color: #C4E4D0; background: var(--lg-mint, #EAF6EF); color: var(--lg-success-ink, #1F6B43); }
        .allergy-check-message { font-weight: 600; }
        .allergy-check-note { margin-top: 3px; font-size: .7rem; opacity: .85; }
        .allergy-check-ack { display: flex; gap: 8px; align-items: flex-start; font-size: .8rem; color: #1d3c31; cursor: pointer; }
        .allergy-check-ack input { margin-top: 2px; }
        .allergy-check-override label { display: block; font-size: .74rem; font-weight: 600; margin-bottom: 4px; }
        .allergy-check-override textarea { width: 100%; box-sizing: border-box; font: inherit; font-size: .84rem; padding: 7px 9px; border: 1px solid #DDE3DF; border-radius: 8px; resize: vertical; }
        .allergy-check-error { font-size: .76rem; color: var(--lg-danger-ink, #9B2C2C); }
    </style>

    <script>
    (() => {
        const box = document.getElementById(@json($prefix.'_allergy_check'));
        if (!box) return;

        const select = document.getElementById(box.dataset.medicine);
        const lrnField = document.getElementById(box.dataset.lrn);
        const result = box.querySelector('[data-allergy-result]');
        const message = box.querySelector('[data-allergy-message]');
        const ack = box.querySelector('[data-allergy-ack]');
        const ackInput = ack.querySelector('input');
        const override = box.querySelector('[data-allergy-override]');
        const reason = override.querySelector('textarea');
        const submit = box.closest('form')?.querySelector('button[type="submit"]');
        let token = 0;

        const apply = (data) => {
            const requires = data && data.result ? data.requires : null;

            box.hidden = requires === null;
            result.className = 'allergy-check-box' + (requires !== null ? ' is-' + data.result : '');
            message.textContent = requires !== null ? (data.message || '') : '';
            ack.hidden = requires !== 'acknowledge';
            ackInput.required = requires === 'acknowledge';
            override.hidden = requires !== 'override';
            reason.required = requires === 'override';
            if (submit) submit.disabled = requires === 'blocked';
        };

        const refresh = () => {
            token += 1;
            const mine = token;

            if (!select || select.value === '') {
                apply(null);
                return;
            }

            const url = new URL(box.dataset.url, window.location.origin);
            url.searchParams.set('medicine_id', select.value);
            if (lrnField && lrnField.value !== '') url.searchParams.set('lrn', lrnField.value);

            fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
                .then((data) => { if (mine === token) apply(data); })
                .catch(() => {
                    if (mine !== token) return;
                    apply({ result: 'unverified', requires: 'acknowledge', message: 'The allergy check could not be loaded. Check the learner\'s allergies manually.' });
                });
        };

        select?.addEventListener('change', refresh);
        document.addEventListener('consult:learner', refresh);
        refresh();
    })();
    </script>
@endif
