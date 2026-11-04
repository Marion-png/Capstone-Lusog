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

    Upload Consent does not file straight away. It opens a confirmation that
    lists every health service the form approves and does not approve; on an
    agreed form the adviser marks them there, and those marks are what is
    filed (`services`, and a partial consent's `excepted_services`).

    Expects $schoolYear (the year an upload is filed under — always the
    current one), $scannerReady and $plannedServices, plus either
    $uploadStudents (each with lrn and name, for the student list) or
    $uploadLearner (one lrn and name, fixed).
--}}
@php
    $uploadLearner = $uploadLearner ?? null;
    $uploadStudents = $uploadStudents ?? [];
    // The services the confirmation lists, worded as the printed letter words
    // them (in Cebuano) under the paper's own headings. Every learner in the
    // class is in the adviser's grade, so a service one grade alone is given
    // is listed only for that grade.
    $uploadGrade = \App\Support\LearnerPlacement::gradeNumber((string) session('assigned_grade_level', ''));
    $confirmServices = collect(array_keys($plannedServices ?? \App\Models\HealthConsentForm::PLANNED_SERVICES))
        ->filter(fn (string $key): bool => (\App\Support\ConsentStanding::GRADE_ONLY_SERVICES[$key] ?? $uploadGrade) === $uploadGrade)
        ->map(fn (string $key): array => \App\Models\HealthConsentForm::printedService($key))
        ->values()
        ->all();
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


                <div class="pc-field">
                    <label for="pc_school_year">School Year <span class="pc-req">*</span></label>
                    <div class="pc-input">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <input id="pc_school_year" type="text" value="{{ $schoolYear }} (Current)" readonly>
                    </div>
                </div>

                <div class="pc-field">
                    <label>Upload Signed Sulat-Pahibalo Form <span class="pc-req">*</span></label>
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
                    <label for="pc_consent_type">What did the guardian answer? <span class="pc-optional">(Optional)</span></label>
                    @if ($scannerReady)
                        <div class="pc-read">
                            <button type="button" class="btn btn-secondary" id="pcReadAnswer" disabled>Read the answer from the photo</button>
                            <span class="pc-read-state" id="pcReadState" aria-live="polite">Attach a photo of the signed form first.</span>
                        </div>
                        {{-- What the reader made of the photo, laid out as View
                             Details lays out a filed form: the photo beside the
                             reading, so the adviser checks one against the
                             other before anything is filed. --}}
                        <div class="pc-review" id="pcReview" hidden>
                            <div class="pc-view-grid">
                                <section class="pc-view-form">
                                    <div class="pc-view-section-head">
                                        <h4>Uploaded form &middot; SY {{ $schoolYear }}</h4>
                                        <button type="button" class="btn btn-secondary pc-view-enlarge" id="pcReviewEnlarge">Enlarge</button>
                                    </div>
                                    <figure class="pc-view-photo">
                                        <img id="pcReviewPhoto" alt="Photo of the signed Sulat-Pahibalo">
                                    </figure>
                                </section>
                                <section class="pc-view-read">
                                    <h4>Read from the uploaded photo</h4>
                                    <div class="pc-view-flash" id="pcReviewFlash" role="status"></div>
                                    <div id="pcCopy"></div>
                                </section>
                            </div>
                        </div>
                    @endif
                    <div class="pc-input">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                        <select id="pc_consent_type" name="consent_type">
                            <option value="">Not recorded yet</option>
                            <option value="full" @selected(old('consent_type') === 'full')>Full consent &mdash; agreed to every service</option>
                            <option value="partial" @selected(old('consent_type') === 'partial')>Partial consent &mdash; agreed, with exceptions</option>
                            <option value="refused" @selected(old('consent_type') === 'refused')>Declined &mdash; did not agree</option>
                        </select>
                    </div>
                    {{-- One span, so the hint's flex layout keeps the bold words
                         inside the sentence instead of splitting it into columns. --}}
                    <p class="pc-hint"><span>
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

                {{-- The services ticked in the letter's list, so View Details
                     can read the form back as it was signed. They are not
                     asked for on screen: the reader reads them off the photo
                     and the script posts them here as hidden fields, checked
                     by the adviser in the reading beside the photo. An upload
                     with none reads as the program it is filed for, as
                     uploads always did. The confirmation step rewrites them
                     with the adviser's own marks on an agreed form. --}}
                <div id="pcServices" hidden></div>
                <div id="pcExcepted" hidden></div>
                <input type="hidden" name="services_confirmed" id="pcServicesConfirmed" value="0">

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
{{-- The confirmation Upload Consent opens: who the form is for, the parent's
     answer, and every health service it approves or does not. It sits above
     the upload dialog, and Back returns to it untouched. --}}
<div class="confirm-overlay pc-modal pcc-overlay" id="pcConfirmModal" aria-hidden="true">
    <div class="confirm-modal pcc-modal" role="dialog" aria-modal="true" aria-labelledby="pcConfirmTitle">
        <div class="confirm-head">
            <div class="confirm-title" id="pcConfirmTitle">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
                Confirm Upload
                <small id="pcConfirmLearner"></small>
            </div>
            <button type="button" class="confirm-close" data-confirm-back aria-label="Back to the upload form">&times;</button>
        </div>
        <div class="confirm-body">
            <dl class="pcc-summary">
                <div><dt>Parent's answer</dt><dd><span class="pcc-answer" id="pcConfirmAnswer"></span></dd></div>
                <div><dt>Signed form</dt><dd id="pcConfirmFile"></dd></div>
                <div id="pcConfirmNoteRow" hidden><dt id="pcConfirmNoteLabel"></dt><dd id="pcConfirmNote"></dd></div>
            </dl>
            <div class="pcc-services-head">
                <h4>Health Services</h4>
                <span class="pcc-tally" id="pcConfirmTally"></span>
                <button type="button" class="pcc-mark-all" id="pcConfirmAll" hidden>Mark all</button>
            </div>
            <ul class="pcc-list" id="pcConfirmList"></ul>
            <div class="confirm-actions">
                <button type="button" class="btn btn-secondary" data-confirm-back>Back</button>
                <button type="button" class="btn" id="pcConfirmSubmit">Confirm &amp; Upload</button>
            </div>
        </div>
    </div>
</div>
@if ($scannerReady)
    @include('consent-forms._photo-closeups')
    {{-- The photo at full size. Outside the upload form, so nothing in it
         can submit that form. --}}
    <dialog class="pc-photo-dialog" id="pcPhotoDialog" aria-label="Uploaded consent form">
        <div class="pc-photo-dialog-bar">
            <button type="button" class="btn btn-secondary" id="pcPhotoDialogClose">Close</button>
        </div>
        <img id="pcPhotoDialogImg" alt="Photo of the signed Sulat-Pahibalo">
    </dialog>
@endif
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
    const answerSelect = document.getElementById('pc_consent_type');
    const submitButton = uploadForm.querySelector('button[type="submit"]');

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
    const answerLines = { partial: document.getElementById('pcPartialField'), refused: document.getElementById('pcRefusedField') };

    // The exception line belongs to a partial consent and the reason to a
    // refusal; the server keeps only the one the answer calls for.
    const syncAnswerLines = () => {
        Object.entries(answerLines).forEach(([answer, field]) => {
            if (field) field.hidden = answerSelect.value !== answer;
        });
    };

    answerSelect.addEventListener('change', syncAnswerLines);

    // Upload Consent opens the confirmation; only its Confirm files the form.
    let confirmedSubmit = false;
    uploadForm.addEventListener('submit', (event) => {
        if (consentFile && !(consentFile.files && consentFile.files.length > 0)) {
            event.preventDefault();
            consentDrop?.classList.add('invalid');
            consentDrop?.scrollIntoView({ block: 'center', behavior: 'smooth' });
            return;
        }
        if (!confirmedSubmit) {
            event.preventDefault();
            openConfirm();
        }
    });

    // A list of service keys as hidden fields of one name.
    const writeKeys = (box, name, keys) => {
        if (!box) return;
        box.textContent = '';
        keys.forEach((key) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = key;
            box.appendChild(input);
        });
    };

    // The services ticked in the letter's list. The reader fills them with
    // the list the server read off the paper (ConsentPaperCopy::tickedServices),
    // and with what the confirmation should start from for each answer
    // (ConsentPaperCopy::proposal); with none the upload reads as the program
    // it is filed for.
    const servicesBox = document.getElementById('pcServices');
    let readServices = [];
    let proposal = null;
    const setServices = (keys, proposed = null) => {
        readServices = keys.slice();
        proposal = proposed;
        writeKeys(servicesBox, 'services[]', keys);
    };

    // ── Confirming what the form approves ───────────────────────────
    // Every service the letter can carry, each approved or not. On an agreed
    // form the adviser marks them, starting from what the server read off the
    // paper and from nothing when nobody has read it: a row is only ever ticked
    // for a reason. The marks are what is filed: `services` the approved ones,
    // and on a partial consent the rest of the letter's list as
    // `excepted_services`. The server reads them with ConsentStanding, exactly
    // as the learner's Consent tab will show them.
    const confirmModal = document.getElementById('pcConfirmModal');
    const confirmList = document.getElementById('pcConfirmList');
    const confirmTally = document.getElementById('pcConfirmTally');
    const confirmSubmit = document.getElementById('pcConfirmSubmit');
    const confirmAll = document.getElementById('pcConfirmAll');
    const exceptedBox = document.getElementById('pcExcepted');
    const servicesConfirmed = document.getElementById('pcServicesConfirmed');
    const confirmServices = @json($confirmServices);
    const serviceKeys = confirmServices.map((service) => service.key);
    const answerKinds = { full: 'is-full', partial: 'is-partial', refused: 'is-refused' };
    // The adviser's marks, kept while they go back to the form and return;
    // dropped when the answer, the photo or the learner changes.
    let approvedChoice = null;

    const isAgreed = () => answerSelect.value === 'full' || answerSelect.value === 'partial';
    // What the reading proposes for the answer on screen. Nothing without one.
    const proposed = () => proposal?.[answerSelect.value] || { approved: [], check: [] };
    // The services the letter lists: every row the reader found marked or
    // could not settle, or — with no reading — every one, as the printed
    // letter carries them all.
    const letterServices = () => {
        if (!proposal) return serviceKeys.slice();
        const onLetter = [...readServices, ...proposal.full.check];
        return serviceKeys.filter((key) => onLetter.includes(key));
    };

    const learnerName = () => {
        if (studentSelect?.tagName === 'SELECT') {
            return studentSelect.options[studentSelect.selectedIndex]?.text || '';
        }
        return document.getElementById('pc_student')?.value || '';
    };

    const paintRow = (row, state) => {
        row.classList.remove('is-approved', 'is-denied', 'is-pending', 'is-check');
        row.classList.add('is-' + state);
        const label = row.querySelector('.pcc-state');
        if (label) label.textContent = { approved: 'Approved', denied: 'Not approved', pending: 'Pending', check: 'Check the paper' }[state];
    };

    const syncTally = () => {
        const total = serviceKeys.length;
        if (isAgreed()) {
            const approved = serviceKeys.filter((key) => approvedChoice.has(key)).length;
            confirmTally.textContent = approved + ' approved · ' + (total - approved) + ' not approved';
            // An agreed form that approves nothing is not one the server takes.
            confirmSubmit.disabled = approved === 0;
            confirmAll.hidden = false;
            confirmAll.textContent = approved === total ? 'Clear all' : 'Mark all';
        } else {
            confirmTally.textContent = answerSelect.value === 'refused'
                ? '0 approved · ' + total + ' not approved'
                : total + ' pending';
            confirmSubmit.disabled = false;
            confirmAll.hidden = true;
        }
    };

    const buildRow = (service) => {
        const row = document.createElement('li');
        row.className = 'pcc-row';
        const wrap = document.createElement(isAgreed() ? 'label' : 'div');
        wrap.className = 'pcc-line';
        const box = document.createElement('span');
        box.className = 'pcc-box';
        box.setAttribute('aria-hidden', 'true');
        const name = document.createElement('span');
        name.className = 'pcc-name';
        name.lang = 'ceb';
        name.textContent = service.label;
        if (service.group) row.classList.add('is-child');
        const state = document.createElement('span');
        state.className = 'pcc-state';

        row.append(wrap);

        if (isAgreed()) {
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.checked = approvedChoice.has(service.key);
            input.addEventListener('change', () => {
                if (input.checked) approvedChoice.add(service.key);
                else approvedChoice.delete(service.key);
                paintRow(row, input.checked ? 'approved' : 'denied');
                syncTally();
            });
            wrap.append(input, box, name, state);
            // A row the reading could not settle says so until somebody decides.
            paintRow(row, input.checked ? 'approved' : (proposed().check.includes(service.key) ? 'check' : 'denied'));
        } else {
            wrap.append(box, name, state);
            paintRow(row, answerSelect.value === 'refused' ? 'denied' : 'pending');
        }

        return row;
    };

    // A heading the paper indents its sub-items under (Pag Purga, Immunization)
    // heads them here too.
    const renderRows = () => {
        const rows = [];
        let group = null;
        confirmServices.forEach((service) => {
            if (service.group && service.group !== group) {
                const head = document.createElement('li');
                head.className = 'pcc-group';
                head.lang = 'ceb';
                head.textContent = service.group;
                rows.push(head);
            }
            group = service.group || null;
            rows.push(buildRow(service));
        });
        confirmList.replaceChildren(...rows);
        syncTally();
    };

    confirmAll?.addEventListener('click', () => {
        approvedChoice = new Set(approvedChoice.size === serviceKeys.length ? [] : serviceKeys);
        renderRows();
    });

    const openConfirm = () => {
        if (!confirmModal) return;
        if (isAgreed() && approvedChoice === null) {
            approvedChoice = new Set(proposed().approved.filter((key) => serviceKeys.includes(key)));
        }

        document.getElementById('pcConfirmLearner').textContent = learnerName();
        const answer = document.getElementById('pcConfirmAnswer');
        answer.className = 'pcc-answer ' + (answerKinds[answerSelect.value] || 'is-none');
        answer.textContent = answerSelect.options[answerSelect.selectedIndex]?.text || '';
        document.getElementById('pcConfirmFile').textContent = consentFile?.files?.[0]?.name || '';

        // What the parent wrote under their box, as the form carries it.
        const note = { partial: ['Exceptions', 'pc_partial_exception'], refused: ['Reason given', 'pc_refused_reason'] }[answerSelect.value];
        const noteText = note ? (document.getElementById(note[1])?.value || '').trim() : '';
        document.getElementById('pcConfirmNoteRow').hidden = noteText === '';
        document.getElementById('pcConfirmNoteLabel').textContent = note ? note[0] : '';
        document.getElementById('pcConfirmNote').textContent = noteText;

        renderRows();

        confirmModal.classList.add('open');
        confirmModal.setAttribute('aria-hidden', 'false');
        (confirmList.querySelector('input') || confirmSubmit).focus();
    };

    const closeConfirm = () => {
        if (!confirmModal?.classList.contains('open')) return;
        confirmModal.classList.remove('open');
        confirmModal.setAttribute('aria-hidden', 'true');
        submitButton?.focus();
    };

    confirmModal?.addEventListener('click', (event) => {
        if (event.target === confirmModal || event.target.closest('[data-confirm-back]')) closeConfirm();
    });

    confirmSubmit?.addEventListener('click', () => {
        if (isAgreed()) {
            const approved = serviceKeys.filter((key) => approvedChoice.has(key));
            if (approved.length === 0) return;
            writeKeys(servicesBox, 'services[]', approved);
            writeKeys(exceptedBox, 'excepted_services[]', answerSelect.value === 'partial'
                ? letterServices().filter((key) => !approvedChoice.has(key))
                : []);
            servicesConfirmed.value = '1';
        } else {
            writeKeys(servicesBox, 'services[]', readServices);
            writeKeys(exceptedBox, 'excepted_services[]', []);
            servicesConfirmed.value = '0';
        }

        confirmedSubmit = true;
        confirmSubmit.disabled = true;
        if (typeof uploadForm.requestSubmit === 'function') uploadForm.requestSubmit();
        else uploadForm.submit();
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
    const review = document.getElementById('pcReview');
    const reviewPhoto = document.getElementById('pcReviewPhoto');
    const reviewFlash = document.getElementById('pcReviewFlash');
    const reviewEnlarge = document.getElementById('pcReviewEnlarge');
    const copyBox = document.getElementById('pcCopy');
    const photoDialog = document.getElementById('pcPhotoDialog');
    const photoDialogImg = document.getElementById('pcPhotoDialogImg');
    let readVersion = 0;
    let readRequest = null;
    let photoUrl = null;

    // The line over the reading: complete, what to check, or why nothing
    // could be read. Written as text — the checks carry the reader's words.
    const setFlash = (kind, title, items = []) => {
        if (!reviewFlash) return;
        reviewFlash.className = 'pc-view-flash is-' + kind + (items.length ? ' has-list' : '');
        reviewFlash.textContent = title;
        if (items.length) {
            const list = document.createElement('ul');
            list.className = 'pc-review-checks';
            items.forEach((item) => {
                const line = document.createElement('li');
                line.textContent = item;
                list.appendChild(line);
            });
            reviewFlash.appendChild(list);
        }
    };

    // The photo beside the sheet as the reader saw it, drawn by the server
    // in the form's own words. It belongs to one photo and one learner,
    // like the rest, so it is dropped with them.
    const showReview = (file, data) => {
        if (photoDialog?.open) photoDialog.close();
        if (photoUrl) {
            URL.revokeObjectURL(photoUrl);
            photoUrl = null;
        }
        if (!review) return;

        if (!file || !data) {
            review.hidden = true;
            uploadModal.classList.remove('is-reviewing');
            reviewPhoto?.removeAttribute('src');
            photoDialogImg?.removeAttribute('src');
            if (copyBox) copyBox.innerHTML = '';
            return;
        }

        photoUrl = URL.createObjectURL(file);
        if (reviewPhoto) reviewPhoto.src = photoUrl;
        if (reviewEnlarge) reviewEnlarge.hidden = typeof photoDialog?.showModal !== 'function';

        const draft = data.draft || {};
        const summary = data.summary || {};
        const checks = (Array.isArray(summary.warnings) ? summary.warnings : [])
            .filter((item) => typeof item === 'string' && item.trim() !== '');

        if (draft.learner_mismatch) {
            setFlash('err', draft.consent_reason || 'The name on this form is another learner\'s.');
        } else if (draft.unreadable) {
            setFlash('err', 'The photo could not be read. Choose the answer from the paper.');
        } else if (summary.complete) {
            setFlash('ok', '✓ All required fields completed');
        } else if (checks.length) {
            setFlash('warn', 'Check against the signed form:', checks);
        } else {
            setFlash('warn', summary.decision || 'Check the reading against the signed form.');
        }

        if (copyBox) copyBox.innerHTML = data.copy_html || '';
        review.hidden = false;
        uploadModal.classList.add('is-reviewing');
    };

    reviewEnlarge?.addEventListener('click', () => {
        if (!photoUrl || !photoDialog || !photoDialogImg) return;
        photoDialogImg.src = photoUrl;
        photoDialog.showModal();
    });
    document.getElementById('pcPhotoDialogClose')?.addEventListener('click', () => photoDialog?.close());
    // A click on the dim backdrop lands on the dialog itself.
    photoDialog?.addEventListener('click', (event) => {
        if (event.target === photoDialog) photoDialog.close();
    });

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
        readButton.disabled = !readable(file) || chosenLearner() === '';
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

    const fillFromReading = (draft, services, proposed) => {
        setServices(Array.isArray(services) ? services : [], proposed || null);

        Object.entries(writtenFields).forEach(([from, to]) => {
            const input = uploadForm.querySelector('[name="' + to + '"]');
            if (input) input.value = (draft.written && draft.written[from] && draft.written[from].text) || '';
        });
        filledFromScan = true;
    };

    const clearReading = () => {
        if (!filledFromScan) return;
        setServices([]);
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
        showReview(null);
        approvedChoice = null;
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

    // Choosing by hand makes the answer the adviser's own, and the services
    // it approves are marked afresh for it.
    answerSelect.addEventListener('change', () => {
        cancelRead();
        setReaderAnswer(false);
        approvedChoice = null;
        syncReader();
    });

    if (readButton && readState) {
        const choiceToAnswer = { all: 'full', specific: 'partial', deny: 'refused' };

        readButton.addEventListener('click', async () => {
            const file = consentFile?.files && consentFile.files[0];
            const lrn = chosenLearner();
            if (!readable(file) || lrn === '') return;

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
                const ask = async (turn) => {
                    await window.consentPhotoCloseups(file, body, turn);

                    const reply = await fetch(@json(route('consent-forms.scan')), {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                            Accept: 'application/json',
                        },
                        body,
                        signal: readRequest.signal,
                    });

                    return { response: reply, data: await reply.json().catch(() => ({})) };
                };

                // A sheet that lay sideways is turned and read once more.
                let { response, data } = await ask(0);
                if (response.ok && data.draft?.turn) {
                    ({ response, data } = await ask(data.draft.turn));
                }

                // The adviser swapped the photo or the learner, or chose the
                // answer themselves, while it was being read.
                if (version !== readVersion || !consentFile.files || consentFile.files[0] !== file || chosenLearner() !== lrn) return;

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
                    fillFromReading(draft, data.ticked_services, data.service_proposal);
                }
                showReview(file, data);

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
                    readButton.disabled = !readable(consentFile?.files && consentFile.files[0]) || chosenLearner() === '';
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
        setServices([]);
        writeKeys(exceptedBox, 'excepted_services[]', []);
        servicesConfirmed.value = '0';
        approvedChoice = null;
        confirmedSubmit = false;
        showReview(null);
        syncAnswerLines();
        consentDrop?.classList.remove('invalid');
        // Only the student list can be pointed at a learner; a learner
        // fixed by their own profile stays theirs.
        if (studentSelect?.tagName === 'SELECT' && lrn) studentSelect.value = lrn;
        syncReader();
        open();
    };

    document.querySelectorAll('[data-consent-upload]').forEach((button) => {
        button.addEventListener('click', () => openUpload(button.dataset.lrn || ''));
    });

    const closeUpload = () => {
        cancelRead();
        closeConfirm();
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
        // The enlarged photo sits above the dialog and closes itself on
        // Escape; the upload behind it stays open.
        if (photoDialog?.open) return;
        // The confirmation sits above the upload: Escape goes back to the
        // form, and Tab stays inside the confirmation.
        const confirming = confirmModal?.classList.contains('open');
        if (event.key === 'Escape') {
            if (confirming) closeConfirm();
            else closeUpload();
            return;
        }
        if (event.key !== 'Tab') return;
        const focusable = Array.from((confirming ? confirmModal : uploadModal).querySelectorAll('button, a[href], input, select, textarea, [tabindex="0"]'))
            .filter((node) => !node.disabled && node.getClientRects().length > 0);
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault(); last?.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault(); first?.focus();
        }
    });

})();
</script>
