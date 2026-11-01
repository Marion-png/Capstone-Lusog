{{--
    Upload the signed Sulat-Pahibalo — one dialog, two pages.

    The Consent Forms page offers it for any learner in the adviser's class,
    and a learner's own profile offers it from the Consent tab with that
    learner already chosen. Both post to the one write path
    (ParentalConsentFormController::store), which re-checks the class on
    every upload, so the dialog is drawn here once rather than copied: two
    copies of a form are two chances to disagree about what was filed.

    Any control marked [data-consent-upload] opens it; a data-lrn on it picks
    the learner in the student list. The host page inlines class-adviser.css
    and consent-copy.css. Exactly one instance per page: the ids are fixed.

    Expects $schoolYear (the year an upload is filed under — always the
    current one), $scannerReady and $plannedServices, plus either
    $uploadStudents (each with lrn and name, for the student list) or
    $uploadLearner (one lrn and name, fixed).
--}}
@php
    $uploadLearner = $uploadLearner ?? null;
    $uploadStudents = $uploadStudents ?? [];
@endphp
<div class="confirm-overlay pc-modal" id="uploadConsentModal" aria-hidden="true">
    <div class="confirm-modal" role="dialog" aria-modal="true" aria-label="Upload Sulat-Pahibalo">
        <div class="confirm-head">
            <div class="confirm-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                Upload Sulat-Pahibalo
                <small>Parent's Consent Form</small>
            </div>
            <button type="button" class="confirm-close" id="uploadConsentClose" aria-label="Close upload">&times;</button>
        </div>
        <form method="POST" action="{{ route('parental-consent.store') }}" enctype="multipart/form-data" id="uploadConsentForm">
            @csrf
            <div class="confirm-body">
                @if ($uploadLearner !== null)
                    {{-- Opened from the learner's own profile: the form is
                         theirs, so there is no list to choose from. --}}
                    <div class="pc-field">
                        <label for="pc_student">Student</label>
                        <div class="pc-input">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                            <input id="pc_student" type="text" value="{{ $uploadLearner['name'] }} ({{ $uploadLearner['lrn'] }})" readonly>
                        </div>
                        <input type="hidden" id="pc_lrn" name="lrn" value="{{ $uploadLearner['lrn'] }}">
                    </div>
                @else
                    <div class="pc-field">
                        <label for="pc_lrn">Select Student <span class="pc-req">*</span></label>
                        <div class="pc-input">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                            <select id="pc_lrn" name="lrn" required>
                                <option value="">Select Student</option>
                                @foreach ($uploadStudents as $student)
                                    <option value="{{ $student['lrn'] }}">{{ $student['name'] }} ({{ $student['lrn'] }})</option>
                                @endforeach
                            </select>
                        </div>
                        <p class="pc-hint">Select the student whose parent signed the consent form.</p>
                    </div>
                @endif

                {{-- Two forms come back from parents, and they answer different
                     questions: the Sulat-Pahibalo covers the health services, the
                     feeding program consent is what lets the Feeding Coordinator
                     enrol a learner in the SBFP. They are filed apart so neither
                     is ever read as the other. --}}
                <div class="pc-field">
                    <label for="pc_program">Which form is this? <span class="pc-req">*</span></label>
                    <div class="pc-input">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        <select id="pc_program" name="program">
                            @foreach (\App\Models\ParentalConsentForm::PROGRAMS as $programValue => $programLabel)
                                <option value="{{ $programValue }}" @selected(old('program', \App\Models\ParentalConsentForm::PROGRAM_HEALTH_SERVICES) === $programValue)>{{ $programLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <p class="pc-hint">A learner measured as wasted can be enrolled in the feeding program only once their parent's signed feeding program consent is filed here.</p>
                </div>

                <div class="pc-field">
                    <label for="pc_school_year">School Year <span class="pc-req">*</span></label>
                    <div class="pc-input">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <input id="pc_school_year" type="text" value="{{ $schoolYear }} (Current)" readonly>
                    </div>
                </div>

                <div class="pc-field">
                    <label id="pcFileLabel">Upload Signed Sulat-Pahibalo Form <span class="pc-req">*</span></label>
                    <div class="pc-drop" id="pcDrop" role="button" tabindex="0">
                        <div id="pcDropBody">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/><polyline points="12 16 12 11"/><polyline points="9.5 13 12 10.5 14.5 13"/></svg>
                            <p>Click to upload or drag and drop</p>
                            <p class="pc-drop-hint">PNG, JPG, or PDF (Max 5MB)</p>
                        </div>
                    </div>
                    <input type="file" id="pc_file" name="consent" accept=".png,.jpg,.jpeg,.pdf" hidden>
                </div>

                {{-- What the guardian answered. It sits under the file because
                     the photo is what the answer is read off — by the adviser,
                     or by the consent reader where one is set up.

                     This was once left out, on the reasoning that two screens
                     asking the same question is how they come to disagree. The
                     answer is still recorded in full on Record Signed Paper
                     Form, field by field and attested — but leaving it out here
                     meant a filed form read as PENDING forever, so the school
                     could see that a consent had come back and never whether it
                     said yes or no.

                     They cannot disagree, because ConsentStanding::for() does
                     not treat them as equals: the parent's own answer, or an
                     attested paper record, is read FIRST and returned, and this
                     is consulted only when there is no answered form. It is the
                     fallback that was always empty, not a second opinion.

                     Optional on purpose. An adviser filing a stack of forms
                     must never be made to guess, and "not recorded yet" is a
                     real answer here exactly as it is in the scanner: an upload
                     with no answer still reads as PENDING and authorises
                     nothing. --}}
                <div class="pc-field">
                    <label for="pc_consent_type">What did the guardian answer? <span class="pc-optional" id="pcAnswerOptional">(Optional)</span></label>
                    @if ($scannerReady)
                        <div class="pc-read">
                            <button type="button" class="btn btn-secondary" id="pcReadAnswer" disabled>Read the answer from the photo</button>
                            <span class="pc-read-state" id="pcReadState" aria-live="polite">Attach a photo of the signed form first.</span>
                        </div>
                        <div class="pc-copy" id="pcCopy" hidden></div>
                    @endif
                    <div class="pc-input">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                        <select id="pc_consent_type" name="consent_type">
                            <option value="" id="pcNotRecordedOption">Not recorded yet</option>
                            <option value="full" @selected(old('consent_type') === 'full')
                                data-feeding-label="Agreed &mdash; the parent consents to the feeding program">Full consent &mdash; agreed to every service</option>
                            <option value="partial" id="pcPartialOption" @selected(old('consent_type') === 'partial')>Partial consent &mdash; agreed, with exceptions</option>
                            <option value="refused" @selected(old('consent_type') === 'refused')
                                data-feeding-label="Declined &mdash; the parent does not consent">Declined &mdash; did not agree</option>
                        </select>
                    </div>
                    {{-- One span, so the hint's flex layout keeps the bold words
                         inside the sentence instead of splitting it into columns. --}}
                    <p class="pc-hint" id="pcAnswerHint"><span>
                        Read it off the signed form. Leave it as <strong>Not recorded yet</strong> if you are
                        filing the document now and will read it later &mdash; the form still counts as returned,
                        but no service may be given until an answer is on file.
                    </span></p>
                    @if ($scannerReady)
                        {{-- The reader's answer goes into the select above and is
                             filed only once the adviser ticks this — the server
                             refuses it otherwise. Choosing an answer by hand
                             makes it the adviser's own and hides the box again. --}}
                        <input type="hidden" name="answer_from_scan" id="pcAnswerFromScan" value="0">
                        <label class="pc-confirm" id="pcAnswerConfirm" hidden>
                            <input type="checkbox" name="answer_confirmed" value="1" id="pcAnswerConfirmed">
                            <span>I checked this answer against the signed form.</span>
                        </label>
                    @endif
                </div>

                {{-- What the rest of the paper says, so View Details can read
                     the form back as it was signed rather than as one word.
                     The services are the ones ticked in the letter's list; an
                     upload with none ticked reads as the program it is filed
                     for, as uploads always did. --}}
                <fieldset class="pc-field pc-fieldset">
                    <legend>Services listed on the form</legend>
                    <div class="pc-services" id="pcServices">
                        <label class="pc-check pc-check-all"><input type="checkbox" id="pcServicesAll"> All services</label>
                        @foreach ($plannedServices as $key => $label)
                            <label class="pc-check">
                                <input type="checkbox" name="services[]" value="{{ $key }}" data-parent="{{ \App\Models\HealthConsentForm::parentService($key) }}">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div class="pc-field" id="pcPartialField" hidden>
                    <label for="pc_partial_exception">Services the parent excepted</label>
                    <div class="pc-input is-plain">
                        <input id="pc_partial_exception" name="partial_exception" type="text" maxlength="500" autocomplete="off">
                    </div>
                </div>

                <div class="pc-field" id="pcRefusedField" hidden>
                    <label for="pc_refused_reason">Reason given</label>
                    <div class="pc-input is-plain">
                        <input id="pc_refused_reason" name="refused_reason" type="text" maxlength="500" autocomplete="off">
                    </div>
                </div>

                <fieldset class="pc-field pc-fieldset">
                    <legend>Allergies and illnesses</legend>
                    <div class="pc-writeins">
                        @foreach ([
                            'allergy_food_detail' => 'Food allergy',
                            'allergy_medicine_detail' => 'Medicine allergy',
                            'prev_immunization_detail' => 'Previous immunization',
                            'other_illness_detail' => 'Other illnesses',
                        ] as $field => $caption)
                            <label class="pc-writein">
                                <span>{{ $caption }}</span>
                                <span class="pc-input is-plain"><input name="{{ $field }}" type="text" maxlength="255" autocomplete="off"></span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div class="pc-field">
                    <label>Medical Certificate <span class="pc-optional">(Optional)</span></label>
                    <p class="pc-hint">Upload if the parent attached a medical certificate with the consent form.</p>
                    <div class="pc-drop" id="pcMedDrop" role="button" tabindex="0">
                        <div id="pcMedDropBody">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/><polyline points="12 16 12 11"/><polyline points="9.5 13 12 10.5 14.5 13"/></svg>
                            <p>Click to upload Medical Certificate</p>
                            <p class="pc-drop-hint">PNG, JPG, or PDF (Max 5MB)</p>
                        </div>
                    </div>
                    <input type="file" id="pc_med_cert" name="medical_certificate" accept=".png,.jpg,.jpeg,.pdf" hidden>
                </div>

                <div class="pc-field">
                    <label for="pc_notes">Additional Notes <span class="pc-optional">(Optional)</span></label>
                    <div class="pc-input">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                        <textarea id="pc_notes" name="notes" rows="2" maxlength="1000" placeholder="Any additional notes about this consent form..."></textarea>
                    </div>
                </div>

                <div class="confirm-actions">
                    <button type="button" class="btn btn-secondary" id="uploadConsentCancel">Cancel</button>
                    <button type="submit" class="btn">Upload Consent</button>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
(() => {
    const uploadModal = document.getElementById('uploadConsentModal');
    const uploadForm = document.getElementById('uploadConsentForm');
    if (!uploadModal || !uploadForm) return;

    let returnFocus = null;
    const open = () => {
        returnFocus = document.activeElement;
        uploadModal.classList.add('open');
        uploadModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        uploadModal.querySelector('button')?.focus();
    };
    const close = () => {
        if (!uploadModal.classList.contains('open')) return;
        uploadModal.classList.remove('open');
        uploadModal.setAttribute('aria-hidden', 'true');
        if (!document.querySelector('.confirm-overlay.open')) document.body.style.overflow = '';
        returnFocus?.focus();
    };

    // A select on the Consent Forms page; a hidden input on the learner's
    // own profile, where the learner is already chosen.
    const studentSelect = document.getElementById('pc_lrn');
    const programSelect = document.getElementById('pc_program');
    const answerSelect = document.getElementById('pc_consent_type');
    const submitButton = uploadForm.querySelector('button[type="submit"]');
    const isHealthForm = () => programSelect?.value === @json(\App\Models\ParentalConsentForm::PROGRAM_HEALTH_SERVICES);

    // ── Which form this is ──────────────────────────────────────────
    // The feeding program consent is a yes or a no on its own signed form: no
    // "except", no "not recorded yet", and no photo reader, which reads the
    // Sulat-Pahibalo. The server holds the same rules whatever is drawn here.
    const feeding = @json(\App\Models\ParentalConsentForm::PROGRAM_FEEDING);
    const fileLabel = document.getElementById('pcFileLabel');
    const read = uploadModal.querySelector('.pc-read');
    const swapped = [document.getElementById('pcPartialOption'), document.getElementById('pcNotRecordedOption')];
    const relabelled = Array.from(answerSelect.querySelectorAll('option[data-feeding-label]'));
    relabelled.forEach((option) => { option.dataset.healthLabel = option.textContent; });

    const syncProgram = () => {
        const isFeeding = programSelect?.value === feeding;

        swapped.forEach((option) => {
            if (!option) return;
            option.hidden = isFeeding;
            option.disabled = isFeeding;
        });
        if (isFeeding && (answerSelect.value === 'partial' || answerSelect.value === '')) answerSelect.value = 'full';

        relabelled.forEach((option) => {
            option.textContent = isFeeding ? option.dataset.feedingLabel : option.dataset.healthLabel;
        });
        answerSelect.required = isFeeding;

        ['pcAnswerOptional', 'pcAnswerHint'].forEach((id) => {
            const el = document.getElementById(id);
            if (el) el.hidden = isFeeding;
        });
        if (read) read.hidden = isFeeding;
        const copy = document.getElementById('pcCopy');
        if (copy) copy.hidden = isFeeding || copy.innerHTML.trim() === '';
        if (isFeeding) {
            const confirmBox = document.getElementById('pcAnswerConfirm');
            const fromScan = document.getElementById('pcAnswerFromScan');
            if (confirmBox) confirmBox.hidden = true;
            if (fromScan) fromScan.value = '0';
        }
        if (fileLabel) {
            fileLabel.firstChild.textContent = isFeeding
                ? 'Upload the Signed Feeding Program Consent '
                : 'Upload Signed Sulat-Pahibalo Form ';
        }
    };

    // Both drop zones behave identically: click, keyboard, or drag a file in.
    const wireDropZone = (dropId, bodyId, inputId) => {
        const drop = document.getElementById(dropId);
        const body = document.getElementById(bodyId);
        const input = document.getElementById(inputId);

        if (!drop || !body || !input) {
            return () => {};
        }

        const emptyMarkup = body.innerHTML;

        const showFile = (file) => {
            body.textContent = '';
            const name = document.createElement('p');
            name.className = 'pc-drop-file';
            name.textContent = file.name;
            body.appendChild(name);
        };

        drop.addEventListener('click', () => input.click());
        drop.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                input.click();
            }
        });
        drop.addEventListener('dragover', (event) => {
            event.preventDefault();
            drop.classList.add('dragging');
        });
        drop.addEventListener('dragleave', () => drop.classList.remove('dragging'));
        drop.addEventListener('drop', (event) => {
            event.preventDefault();
            drop.classList.remove('dragging');
            const files = event.dataTransfer?.files;
            if (files && files.length) {
                input.files = files;
                // Setting .files fires nothing, and everything listening
                // for a new file (the name, the reader) listens on change.
                input.dispatchEvent(new Event('change'));
            }
        });
        input.addEventListener('change', () => {
            const file = input.files && input.files[0];
            if (file) {
                showFile(file);
            }
        });

        // form.reset() clears the input but not the rendered file name.
        return () => { body.innerHTML = emptyMarkup; };
    };

    const resetConsentDrop = wireDropZone('pcDrop', 'pcDropBody', 'pc_file');
    const resetMedCertDrop = wireDropZone('pcMedDrop', 'pcMedDropBody', 'pc_med_cert');

    // The signed form is marked required, so don't let the modal create a
    // record with no scan behind it — those can only ever read as pending.
    const consentDrop = document.getElementById('pcDrop');
    const consentFile = document.getElementById('pc_file');

    consentFile?.addEventListener('change', () => consentDrop?.classList.remove('invalid'));

    // ── What the rest of the paper says ─────────────────────────────
    const servicesBox = document.getElementById('pcServices');
    const servicesAll = document.getElementById('pcServicesAll');
    const serviceBoxes = Array.from(uploadForm.querySelectorAll('input[name="services[]"]'));
    const answerLines = { partial: document.getElementById('pcPartialField'), refused: document.getElementById('pcRefusedField') };

    // The exception line belongs to a partial consent and the reason to a
    // refusal; the server keeps only the one the answer calls for.
    const syncAnswerLines = () => {
        Object.entries(answerLines).forEach(([answer, field]) => {
            if (field) field.hidden = answerSelect.value !== answer;
        });
    };

    const syncAllServices = () => {
        if (!servicesAll) return;
        const ticked = serviceBoxes.filter((box) => box.checked).length;
        servicesAll.checked = ticked === serviceBoxes.length;
        servicesAll.indeterminate = ticked > 0 && ticked < serviceBoxes.length;
        if (ticked > 0) servicesBox?.classList.remove('invalid');
    };

    answerSelect.addEventListener('change', syncAnswerLines);
    serviceBoxes.forEach((box) => box.addEventListener('change', syncAllServices));
    servicesAll?.addEventListener('change', () => {
        serviceBoxes.forEach((box) => { box.checked = servicesAll.checked; });
        syncAllServices();
    });

    uploadForm.addEventListener('submit', (event) => {
        if (consentFile && !(consentFile.files && consentFile.files.length > 0)) {
            event.preventDefault();
            consentDrop?.classList.add('invalid');
            consentDrop?.scrollIntoView({ block: 'center', behavior: 'smooth' });
            return;
        }

        // An agreement is an agreement to the services the letter lists,
        // so it is not filed until they are ticked.
        const agreed = ['full', 'partial'].includes(answerSelect.value || '');
        if (agreed && !serviceBoxes.some((box) => box.checked)) {
            event.preventDefault();
            servicesBox?.classList.add('invalid');
            servicesBox?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    });

    // ── Reading the answer off the photo ────────────────────────────
    // The consent reader proposes; the adviser records. Its answer lands
    // in the same select an adviser would set by hand, and is filed only
    // once they tick the confirmation — the server refuses it otherwise.
    // Only rendered where a reader is configured.
    const readButton = document.getElementById('pcReadAnswer');
    const readState = document.getElementById('pcReadState');
    const answerFromScan = document.getElementById('pcAnswerFromScan');
    const answerConfirm = document.getElementById('pcAnswerConfirm');
    const answerConfirmed = document.getElementById('pcAnswerConfirmed');
    const copyBox = document.getElementById('pcCopy');
    let readVersion = 0;
    let readRequest = null;

    // The sheet as the reader saw it, drawn by the server in the form's
    // own words. It belongs to one photo and one learner, like the rest.
    const showCopy = (html) => {
        if (!copyBox) return;
        copyBox.innerHTML = html || '';
        copyBox.hidden = !html;
    };

    const setReaderAnswer = (fromScan) => {
        if (!answerFromScan || !answerConfirm || !answerConfirmed) return;
        answerFromScan.value = fromScan ? '1' : '0';
        answerConfirm.hidden = !fromScan;
        answerConfirmed.required = fromScan;
        answerConfirmed.checked = false;
    };

    // The upload takes a PDF too, but the reader reads photographs only.
    const readable = (file) => Boolean(file) && ['image/jpeg', 'image/png'].includes(file.type);

    // The reader checks the name on the sheet against the learner the
    // answer will be filed for, so it reads once one is chosen.
    const chosenLearner = () => studentSelect?.value || '';

    const syncReader = () => {
        if (!readButton || !readState) return;
        const file = consentFile?.files && consentFile.files[0];
        readButton.disabled = !readable(file) || chosenLearner() === '' || !isHealthForm();
        readState.textContent = !file
            ? 'Attach a photo of the signed form first.'
            : (!readable(file)
                ? 'Only a JPG or PNG photo can be read. Choose the answer from the paper.'
                : (chosenLearner() === '' ? 'Select the student first.' : ''));
    };

    // The written lines the reader transcribes, keyed by the field each
    // one fills here.
    const writtenFields = {
        consent_exceptions: 'partial_exception',
        refusal_reason: 'refused_reason',
        allergy_food: 'allergy_food_detail',
        allergy_medicine: 'allergy_medicine_detail',
        prev_immunization: 'prev_immunization_detail',
        other_illness: 'other_illness_detail',
    };
    let filledFromScan = false;

    const fillFromReading = (draft) => {
        const services = draft.services || {};
        const ticked = (key) => services[key] === 'yes';

        // A sub-item is read off its own box; a ticked heading with none
        // of its own sub-items ticked covers them all, as on the letter.
        serviceBoxes.forEach((box) => {
            const parent = box.dataset.parent || '';
            const siblingTicked = parent !== '' && serviceBoxes.some((other) => other.dataset.parent === parent && ticked(other.value));
            box.checked = ticked(box.value) || (parent !== '' && ticked(parent) && !siblingTicked);
        });
        syncAllServices();

        Object.entries(writtenFields).forEach(([from, to]) => {
            const input = uploadForm.querySelector('[name="' + to + '"]');
            if (input) input.value = (draft.written && draft.written[from] && draft.written[from].text) || '';
        });
        filledFromScan = true;
    };

    const clearReading = () => {
        if (!filledFromScan) return;
        serviceBoxes.forEach((box) => { box.checked = false; });
        syncAllServices();
        Object.values(writtenFields).forEach((name) => {
            const input = uploadForm.querySelector('[name="' + name + '"]');
            if (input) input.value = '';
        });
        filledFromScan = false;
    };

    // A read still on its way belongs to a photo, a learner or an answer
    // that is no longer the one on screen, so what it brings back is
    // dropped rather than written over the adviser's choice.
    const cancelRead = () => {
        readVersion += 1;
        readRequest?.abort();
        readRequest = null;
        if (submitButton) submitButton.disabled = false;
    };

    // A reading belongs to the photo it was taken from, and to the
    // learner it was checked against.
    const dropReading = () => {
        cancelRead();
        showCopy(null);
        if (answerFromScan?.value === '1') {
            answerSelect.value = '';
            setReaderAnswer(false);
            syncAnswerLines();
        }
        clearReading();
        syncReader();
    };

    consentFile?.addEventListener('change', dropReading);
    studentSelect?.addEventListener('change', dropReading);
    programSelect?.addEventListener('change', () => {
        dropReading();
        syncProgram();
    });

    // Choosing by hand makes the answer the adviser's own.
    answerSelect.addEventListener('change', () => {
        cancelRead();
        setReaderAnswer(false);
        syncReader();
    });

    if (readButton && readState) {
        const choiceToAnswer = { all: 'full', specific: 'partial', deny: 'refused' };

        readButton.addEventListener('click', async () => {
            const file = consentFile?.files && consentFile.files[0];
            const lrn = chosenLearner();
            if (!readable(file) || lrn === '' || !isHealthForm()) return;

            dropReading();
            const version = readVersion;
            readRequest = new AbortController();
            readButton.disabled = true;
            if (submitButton) submitButton.disabled = true;
            readState.textContent = 'Reading the form…';

            const body = new FormData();
            body.append('photo', file);
            body.append('lrn', lrn);

            try {
                const response = await fetch(@json(route('consent-forms.scan')), {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        Accept: 'application/json',
                    },
                    body,
                    signal: readRequest.signal,
                });
                const data = await response.json().catch(() => ({}));

                // The adviser swapped the photo, the learner or the form, or
                // chose the answer themselves, while it was being read.
                if (version !== readVersion || !consentFile.files || consentFile.files[0] !== file || chosenLearner() !== lrn || !isHealthForm()) return;

                if (!response.ok) {
                    readState.textContent = data.message || 'The form could not be read. Choose the answer from the paper.';
                    return;
                }

                const draft = data.draft || {};
                const answer = choiceToAnswer[draft.consent_choice] || '';

                // Unclear is never a guess either way: the select goes back
                // to "Not recorded yet" and the reason is said instead.
                answerSelect.value = answer;
                setReaderAnswer(answer !== '');
                syncAnswerLines();

                // Another child's sheet, or one nothing could be read
                // off, fills nothing in.
                clearReading();
                if (!draft.unreadable && !draft.learner_mismatch) {
                    fillFromReading(draft);
                }
                showCopy(data.copy_html);

                if (draft.unreadable) {
                    readState.textContent = 'The photo could not be read. Choose the answer from the paper.';
                } else if (answer === '') {
                    readState.textContent = draft.consent_reason || 'The answer could not be made out. Choose it from the paper.';
                } else {
                    const label = answerSelect.options[answerSelect.selectedIndex]?.text || answer;
                    readState.textContent = 'Read as "' + label + '". Check it against the paper before uploading.'
                        + (draft.signature_present ? '' : ' No parent\'s signature was found on the photo.');
                }
            } catch (_err) {
                if (version === readVersion) readState.textContent = 'The form could not be read. Choose the answer from the paper.';
            } finally {
                if (version === readVersion) {
                    readRequest = null;
                    readButton.disabled = !readable(consentFile?.files && consentFile.files[0]) || chosenLearner() === '' || !isHealthForm();
                    if (submitButton) submitButton.disabled = false;
                }
            }
        });
    }

    const openUpload = (lrn) => {
        uploadForm.reset();
        resetConsentDrop();
        resetMedCertDrop();
        cancelRead();
        setReaderAnswer(false);
        filledFromScan = false;
        showCopy(null);
        syncAnswerLines();
        syncAllServices();
        servicesBox?.classList.remove('invalid');
        consentDrop?.classList.remove('invalid');
        // Only the student list can be pointed at a learner; a learner
        // fixed by their own profile stays theirs.
        if (studentSelect?.tagName === 'SELECT' && lrn) studentSelect.value = lrn;
        syncProgram();
        syncReader();
        open();
    };

    document.querySelectorAll('[data-consent-upload]').forEach((button) => {
        button.addEventListener('click', () => openUpload(button.dataset.lrn || ''));
    });

    const closeUpload = () => {
        cancelRead();
        close();
    };
    document.getElementById('uploadConsentClose')?.addEventListener('click', closeUpload);
    document.getElementById('uploadConsentCancel')?.addEventListener('click', closeUpload);
    uploadModal.addEventListener('click', (event) => {
        if (event.target === uploadModal) {
            closeUpload();
        }
    });

    // Escape closes the dialog, and Tab keeps focus inside it while it is
    // open rather than wandering into the page behind.
    document.addEventListener('keydown', (event) => {
        if (!uploadModal.classList.contains('open')) return;
        if (event.key === 'Escape') {
            closeUpload();
            return;
        }
        if (event.key !== 'Tab') return;
        const focusable = Array.from(uploadModal.querySelectorAll('button, a[href], input, select, textarea, [tabindex="0"]'))
            .filter((node) => !node.disabled && node.getClientRects().length > 0);
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault(); last?.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault(); first?.focus();
        }
    });

    syncProgram();
})();
</script>
