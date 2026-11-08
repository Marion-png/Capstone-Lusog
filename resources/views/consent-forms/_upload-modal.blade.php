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

    The flow, in order:
      1. The photo reader pre-fills the reading panel beside the photo.
      2. The adviser holds the reading against the signed form and corrects
         it there: a service the parent consented to that the reader missed
         is switched to Consented, one the parent excluded that the reader
         included is switched to Excluded, and the allergy and illness lines
         are corrected in their own fields.
      3. They tick "I checked this answer against the signed form."
      4. Upload Consent opens a read-only Confirm Upload summary: Back to Edit
         returns to the dialog untouched, Confirm & Upload files it.
      5. The page comes back with "Consent saved successfully."
    The Consented / Excluded marks are what is filed (`services`, and a
    partial consent's `excepted_services`). With no reading — no reader set
    up, a PDF, a photo it could not read — the same marks are made under the
    parent's answer instead, starting from Excluded.

    Expects $schoolYear (the year an upload is filed under — always the
    current one), $scannerReady and $plannedServices, plus either
    $uploadStudents (each with lrn and name, for the student list) or
    $uploadLearner (one lrn and name, fixed).
--}}
@php
    $uploadLearner = $uploadLearner ?? null;
    $uploadStudents = $uploadStudents ?? [];
    // The services the dialog marks and the summary lists, worded as the
    // printed letter words
    // them (in Cebuano) under the paper's own headings. Every learner in the
    // class is in the adviser's grade, so a service one grade alone is given
    // is listed only for that grade.
    $uploadGrade = \App\Support\LearnerPlacement::gradeNumber((string) session('assigned_grade_level', ''));
    $confirmServices = collect(array_keys($plannedServices ?? \App\Models\HealthConsentForm::PLANNED_SERVICES))
        ->filter(fn (string $key): bool => (\App\Support\ConsentStanding::GRADE_ONLY_SERVICES[$key] ?? $uploadGrade) === $uploadGrade)
        ->map(fn (string $key): array => \App\Models\HealthConsentForm::printedService($key))
        ->values()
        ->all();
    // What the services say under a heading ticked on the paper while none
    // of its rows is consented: the one sentence the letter's Send and the reading
    // use (HealthConsentForm::rowsRequiredNotice), naming this grade's rows.
    // The allergy and illness lines the parent writes in, by field, as the
    // dialog and its summary caption them.
    $writeIns = [
        'allergy_food_detail' => 'Food allergy',
        'allergy_medicine_detail' => 'Medicine allergy',
        'prev_immunization_detail' => 'Previous immunization',
        'other_illness_detail' => 'Other illnesses',
    ];
    $headingNotices = [];
    foreach (\App\Models\HealthConsentForm::SERVICES as $heading => $service) {
        $rows = array_values(array_filter(
            array_column($confirmServices, 'key'),
            fn (string $key): bool => array_key_exists($key, $service['children']),
        ));
        if ($rows !== []) {
            $headingNotices[$heading] = \App\Models\HealthConsentForm::rowsRequiredNotice($heading, $rows);
        }
    }
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
            <div class="confirm-body pc-upload-body">
                @if ($scannerReady)
                    {{-- The photo, once it has been read: beside the whole
                         form rather than inside one field of it, so the
                         adviser can hold every answer — the boxes, the lines
                         written in, the answer chosen — against the paper
                         without scrolling the photo away. It is the sheet as
                         the reader saw it, upright. --}}
                    <aside class="pc-photo-pane" id="pcPhotoPane" hidden>
                        <div class="pc-view-section-head">
                            <h4>Uploaded form &middot; SY {{ $schoolYear }}</h4>
                            <div class="pc-photo-tools">
                                <button type="button" class="pc-zoom" id="pcZoomOut" aria-label="Zoom out"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 12h14"/></svg></button>
                                <span class="pc-zoom-level" id="pcZoomLevel" aria-live="polite">100%</span>
                                <button type="button" class="pc-zoom" id="pcZoomIn" aria-label="Zoom in"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg></button>
                                <button type="button" class="btn btn-secondary pc-view-enlarge" id="pcReviewEnlarge">Enlarge</button>
                            </div>
                        </div>
                        <figure class="pc-pane-photo" id="pcPhotoFrame">
                            <img id="pcReviewPhoto" alt="Photo of the signed Sulat-Pahibalo">
                        </figure>
                    </aside>
                @endif
                <div class="pc-upload-main">
                <div class="pc-row2">
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
                        {{-- What the reader made of the photo, beside the photo
                             itself (the pane on the left), so the adviser checks
                             one against the other before anything is filed. The
                             sheet's three sections fold to one line each; a
                             section with something to check opens on its own. --}}
                        <div class="pc-review" id="pcReview" hidden>
                            <h4 class="pc-review-title">Read from the uploaded photo</h4>
                            <div class="pc-view-flash" id="pcReviewFlash" role="status"></div>
                            <div id="pcCopy"></div>
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
                        {{-- Whether the answer in the select is the reader's.
                             The server files a reader's answer only with the
                             adviser's tick beside Upload Consent; choosing an
                             answer by hand makes it the adviser's own. --}}
                        <input type="hidden" name="answer_from_scan" id="pcAnswerFromScan" value="0">
                    @endif
                </div>

                {{-- The services ticked in the letter's list, so View Details
                     can read the form back as it was signed. They are posted
                     as hidden fields: the reader's list as it was read, and on
                     an agreed form the services the adviser marked Consented
                     (with a partial consent's Excluded ones as its
                     exceptions). An upload with none reads as the program it
                     is filed for, as uploads always did. --}}
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

                {{-- Each health service, Consented or Excluded, for an agreed
                     form with no reading beside it. Once a photo has been
                     read, the same marks are made in the reading's Services
                     section instead, pre-filled from what the reader saw. --}}
                <div class="pc-field" id="pcServicesField" hidden>
                    <span class="pc-field-label" id="pcServicesLabel">Health services</span>
                    <div class="pc-svc" id="pcSvcHome" role="group" aria-labelledby="pcServicesLabel"></div>
                </div>

                <fieldset class="pc-field pc-fieldset">
                    <legend>Allergies and illnesses</legend>
                    <div class="pc-writeins">
                        @foreach ($writeIns as $field => $caption)
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

                <div class="confirm-actions pc-actions">
                    @if ($scannerReady)
                        {{-- Shown, and required, while a reading is on screen:
                             the adviser has held the whole reading — answer,
                             services, allergies — against the paper. --}}
                        <label class="pc-confirm" id="pcAnswerConfirm" hidden>
                            <input type="checkbox" name="answer_confirmed" value="1" id="pcAnswerConfirmed">
                            <span>I checked this answer against the signed form.</span>
                        </label>
                    @endif
                    <button type="button" class="btn btn-secondary" id="uploadConsentCancel">Cancel</button>
                    <button type="submit" class="btn">Upload Consent</button>
                </div>
                </div>
            </div>
        </form>
    </div>
</div>
{{-- The read-only summary Upload Consent opens: who the form is for, the
     parent's answer, every health service as marked, and the allergy and
     illness lines. Nothing in it can be changed: Back to Edit returns to the
     dialog untouched, and Confirm & Upload files exactly what it shows. --}}
<div class="confirm-overlay pc-modal pcc-overlay" id="pcConfirmModal" aria-hidden="true">
    <div class="confirm-modal pcc-modal" role="dialog" aria-modal="true" aria-labelledby="pcConfirmTitle">
        <div class="confirm-head">
            <div class="confirm-title" id="pcConfirmTitle">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
                Confirm Upload
                <small id="pcConfirmLearner"></small>
            </div>
            <button type="button" class="confirm-close" data-confirm-back aria-label="Back to Edit">&times;</button>
        </div>
        <div class="confirm-body">
            <dl class="pcc-summary">
                <div><dt>Parent's answer</dt><dd><span class="pcc-answer" id="pcConfirmAnswer"></span></dd></div>
                <div><dt>Signed form</dt><dd id="pcConfirmFile"></dd></div>
                <div id="pcConfirmNoteRow" hidden><dt id="pcConfirmNoteLabel"></dt><dd id="pcConfirmNote"></dd></div>
                <div id="pcConfirmCertRow" hidden><dt>Medical certificate</dt><dd id="pcConfirmCert"></dd></div>
                <div id="pcConfirmNotesRow" hidden><dt>Additional notes</dt><dd id="pcConfirmNotes"></dd></div>
            </dl>
            <div class="pcc-services-head">
                <h4>Health Services</h4>
                <span class="pcc-tally" id="pcConfirmTally"></span>
            </div>
            <ul class="pcc-list" id="pcConfirmList"></ul>
            <div class="pcc-services-head">
                <h4>Allergies and illnesses</h4>
            </div>
            <dl class="pcc-summary pcc-health" id="pcConfirmHealth"></dl>
            <div class="confirm-actions">
                <button type="button" class="btn btn-secondary" data-confirm-back>Back to Edit</button>
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

        // A chosen file shrinks the zone to one line naming it; the whole
        // line still opens the picker to choose another.
        const showFile = (file) => {
            body.textContent = '';
            const name = document.createElement('p');
            name.className = 'pc-drop-file';
            name.textContent = file.name;
            const size = document.createElement('span');
            size.className = 'pc-drop-size';
            size.textContent = file.size >= 1048576
                ? (file.size / 1048576).toFixed(1) + ' MB'
                : Math.max(1, Math.round(file.size / 1024)) + ' KB';
            const change = document.createElement('span');
            change.className = 'pc-drop-change';
            change.textContent = 'Change';
            body.append(name, size, change);
            drop.classList.add('has-file');
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
        return () => {
            body.innerHTML = emptyMarkup;
            drop.classList.remove('has-file');
        };
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

    // Upload Consent opens the read-only summary; only its Confirm & Upload
    // files the form. On an agreed form it first waits on every service the
    // reading left to check, and on at least one Consented — the server
    // refuses an agreed form that consents to nothing.
    let confirmedSubmit = false;
    uploadForm.addEventListener('submit', (event) => {
        if (consentFile && !(consentFile.files && consentFile.files.length > 0)) {
            event.preventDefault();
            consentDrop?.classList.add('invalid');
            consentDrop?.scrollIntoView({ block: 'center', behavior: 'smooth' });
            return;
        }
        if (confirmedSubmit) return;
        event.preventDefault();
        if (isAgreed()) {
            const unsettled = keysIn('check');
            if (unsettled.length > 0) {
                showServiceError('Choose Consented or Excluded for every service marked Check the paper.', unsettled[0]);
                return;
            }
            if (keysIn('consented').length === 0) {
                showServiceError('Mark at least one service as Consented.');
                return;
            }
        }
        openConfirm();
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
    // and with where each service starts for each answer
    // (ConsentPaperCopy::proposal); with none the upload reads as the program
    // it is filed for.
    const servicesBox = document.getElementById('pcServices');
    let readServices = [];
    let proposal = null;
    // The headings (Pag Purga, Immunization) ticked on the paper. A heading
    // names the service and its rows say which, so while none of its rows is
    // consented the services say that one must be.
    let readHeadings = [];
    const headingNotices = @json($headingNotices);
    const setServices = (keys, proposed = null, headings = []) => {
        readServices = keys.slice();
        proposal = proposed;
        readHeadings = headings.slice();
        writeKeys(servicesBox, 'services[]', keys);
    };

    // ── Marking the services ────────────────────────────────────────
    // Every service the letter can carry, each Consented or Excluded. With a
    // reading, each row starts where the reader left it
    // (ConsentPaperCopy::proposal): a service it read as approved starts
    // Consented, a row it could not settle waits on the adviser as "Check the
    // paper", and the rest start Excluded. With no reading every row starts
    // Excluded, so a service is only ever Consented for a reason. The
    // adviser's own marks override the reading and survive a switch between
    // full and partial consent; they go with the photo or the learner.
    //
    // The marks are what is filed: `services` the Consented ones, and on a
    // partial consent the Excluded rows of the letter's list as
    // `excepted_services`. The server reads them with ConsentStanding, exactly
    // as the learner's Consent tab will show them.
    const confirmModal = document.getElementById('pcConfirmModal');
    const confirmList = document.getElementById('pcConfirmList');
    const confirmTally = document.getElementById('pcConfirmTally');
    const confirmSubmit = document.getElementById('pcConfirmSubmit');
    const exceptedBox = document.getElementById('pcExcepted');
    const servicesConfirmed = document.getElementById('pcServicesConfirmed');
    const servicesField = document.getElementById('pcServicesField');
    const servicesHome = document.getElementById('pcSvcHome');
    const confirmServices = @json($confirmServices);
    const serviceKeys = confirmServices.map((service) => service.key);
    const writeIns = @json($writeIns);
    const answerKinds = { full: 'is-full', partial: 'is-partial', refused: 'is-refused' };
    const stateWords = { consented: 'Consented', excluded: 'Excluded', check: 'Check the paper', declined: 'Declined', pending: 'Pending' };
    // The adviser's own marks: service key → 'consented' | 'excluded'.
    const decided = new Map();
    // While a reading is on screen the marks are made in its Services section:
    // the section's body, the tally on its folded line, and the section itself.
    let foldHost = null;
    let foldTally = null;
    let foldSection = null;

    const isAgreed = () => answerSelect.value === 'full' || answerSelect.value === 'partial';
    // What the reading proposes for the answer on screen. Nothing without one.
    const proposed = () => proposal?.[answerSelect.value] || { approved: [], check: [] };
    // Where one service stands for the answer on screen. A refusal declines
    // every service and no answer yet gives none; only an agreed form has
    // services to mark.
    const stateOf = (key) => {
        if (answerSelect.value === 'refused') return 'declined';
        if (!isAgreed()) return 'pending';
        if (decided.has(key)) return decided.get(key);
        const reading = proposed();
        if (reading.approved.includes(key)) return 'consented';
        if (reading.check.includes(key)) return 'check';
        return 'excluded';
    };
    const keysIn = (state) => serviceKeys.filter((key) => stateOf(key) === state);
    // The services the letter lists: every row the reader found marked or
    // could not settle, or — with no reading — every one, as the printed
    // letter carries them all.
    const letterServices = () => {
        if (!proposal) return serviceKeys.slice();
        const onLetter = [...readServices, ...proposal.full.check];
        return serviceKeys.filter((key) => onLetter.includes(key));
    };

    // The counts, as the editor, the folded line and the summary say them.
    const tallies = () => {
        if (answerSelect.value === 'refused') return [['declined', serviceKeys.length, 'Declined']];
        if (!isAgreed()) return [['pending', serviceKeys.length, 'Pending']];
        return [
            ['consented', keysIn('consented').length, 'Consented'],
            ['excluded', keysIn('excluded').length, 'Excluded'],
            ['check', keysIn('check').length, 'To check'],
        ].filter(([state, count]) => count > 0 || state !== 'check');
    };
    const tallyText = () => tallies().map(([, count, word]) => count + ' ' + word).join(' · ');
    // The folded line's chips take the reading's own pill colours.
    const chipKinds = { consented: 'consented', excluded: 'declined', check: 'unclear', declined: 'declined', pending: 'no_form' };

    const learnerName = () => {
        if (studentSelect?.tagName === 'SELECT') {
            return studentSelect.options[studentSelect.selectedIndex]?.text || '';
        }
        return document.getElementById('pc_student')?.value || '';
    };

    const editorHost = () => (foldHost?.isConnected ? foldHost : servicesHome);

    // One row's state, painted: its class, which option is pressed, the
    // amber word on a row nobody has settled, and the pill on a read-only row.
    const paintEditorRow = (row) => {
        const state = stateOf(row.dataset.key);
        row.classList.remove('is-consented', 'is-excluded', 'is-check', 'is-declined', 'is-pending');
        row.classList.add('is-' + state);
        row.querySelectorAll('.pc-svc-opt').forEach((option) => {
            option.setAttribute('aria-pressed', String(option.dataset.choice === state));
        });
        const flag = row.querySelector('.pc-svc-flag');
        if (flag) flag.hidden = state !== 'check';
        const pill = row.querySelector('.pc-svc-state');
        if (pill) pill.textContent = stateWords[state];
    };

    // The tally, Mark all and the heading notes, after any change.
    const syncEditor = () => {
        const host = editorHost();
        const tally = host?.querySelector('.pc-svc-tally');
        if (tally) tally.textContent = tallyText();
        const all = host?.querySelector('.pc-svc-all');
        if (all) {
            all.hidden = !isAgreed();
            all.textContent = keysIn('consented').length === serviceKeys.length ? 'Mark all Excluded' : 'Mark all Consented';
        }
        host?.querySelectorAll('.pc-svc-group-note').forEach((note) => {
            const heading = note.dataset.heading;
            const rows = confirmServices.filter((service) => service.parent === heading).map((service) => service.key);
            note.hidden = !(isAgreed() && readHeadings.includes(heading) && !rows.some((key) => stateOf(key) === 'consented'));
        });
        if (foldTally) {
            foldTally.replaceChildren(...tallies().filter(([, count]) => count > 0).map(([state, count, word]) => {
                const chip = document.createElement('span');
                chip.className = 'pcopy-std is-' + chipKinds[state] + ' pc-fold-chip';
                chip.textContent = count + ' ' + word;
                return chip;
            }));
        }
        foldSection?.classList.toggle('needs-check', keysIn('check').length > 0);
    };

    const hideServiceError = () => {
        const error = editorHost()?.querySelector('.pc-svc-error');
        if (error) {
            error.hidden = true;
            error.textContent = '';
        }
    };

    // Upload Consent waits here: the message above the rows, and the first
    // row it is about brought into view.
    const showServiceError = (message, key = null) => {
        if (foldHost?.isConnected && foldSection) foldSection.open = true;
        const host = editorHost();
        const error = host?.querySelector('.pc-svc-error');
        if (!error) return;
        error.textContent = message;
        error.hidden = false;
        const row = key ? host.querySelector('.pc-svc-row[data-key="' + key + '"]') : null;
        (row || error).scrollIntoView({ block: 'center', behavior: 'smooth' });
        (row?.querySelector('.pc-svc-opt') || host.querySelector('.pc-svc-opt'))?.focus({ preventScroll: true });
    };

    const repaintRows = () => {
        editorHost()?.querySelectorAll('.pc-svc-row').forEach(paintEditorRow);
        hideServiceError();
        syncEditor();
    };

    // A heading the paper indents its sub-items under (Pag Purga,
    // Immunization) heads them here too, worded as the printed letter words
    // it, with the notice that one of its rows must be consented when the
    // paper ticks the heading alone.
    const groupHead = (service, className, withNotice) => {
        const head = document.createElement('li');
        head.className = className;
        const title = document.createElement('span');
        title.lang = 'ceb';
        title.textContent = service.group;
        head.appendChild(title);
        if (withNotice && headingNotices[service.parent]) {
            const note = document.createElement('span');
            note.className = 'pc-svc-group-note';
            note.dataset.heading = service.parent;
            note.setAttribute('role', 'status');
            note.textContent = headingNotices[service.parent];
            note.hidden = true;
            head.appendChild(note);
        }
        return head;
    };

    const buildEditor = () => {
        const head = document.createElement('div');
        head.className = 'pc-svc-head';
        const tally = document.createElement('span');
        tally.className = 'pc-svc-tally';
        const all = document.createElement('button');
        all.type = 'button';
        all.className = 'pc-svc-all';
        all.addEventListener('click', () => {
            const every = keysIn('consented').length === serviceKeys.length;
            serviceKeys.forEach((key) => decided.set(key, every ? 'excluded' : 'consented'));
            repaintRows();
        });
        head.append(tally, all);

        const error = document.createElement('p');
        error.className = 'pc-svc-error';
        error.setAttribute('role', 'alert');
        error.hidden = true;

        const list = document.createElement('ul');
        list.className = 'pc-svc-list';
        let group = null;
        confirmServices.forEach((service) => {
            if (service.group && service.group !== group) {
                list.appendChild(groupHead(service, 'pc-svc-group', true));
            }
            group = service.group || null;

            const row = document.createElement('li');
            row.className = 'pc-svc-row' + (service.group ? ' is-child' : '');
            row.dataset.key = service.key;
            const name = document.createElement('span');
            name.className = 'pc-svc-name';
            const words = document.createElement('span');
            words.lang = 'ceb';
            words.textContent = service.label;
            name.appendChild(words);
            row.appendChild(name);

            if (isAgreed()) {
                const flag = document.createElement('span');
                flag.className = 'pc-svc-flag';
                flag.textContent = stateWords.check;
                flag.hidden = true;
                name.appendChild(flag);

                const options = document.createElement('span');
                options.className = 'pc-svc-seg';
                options.setAttribute('role', 'group');
                options.setAttribute('aria-label', service.label);
                ['consented', 'excluded'].forEach((choice) => {
                    const option = document.createElement('button');
                    option.type = 'button';
                    option.className = 'pc-svc-opt';
                    option.dataset.choice = choice;
                    option.textContent = stateWords[choice];
                    option.addEventListener('click', () => {
                        decided.set(service.key, choice);
                        paintEditorRow(row);
                        hideServiceError();
                        syncEditor();
                    });
                    options.appendChild(option);
                });
                row.appendChild(options);
            } else {
                const pill = document.createElement('span');
                pill.className = 'pc-svc-state';
                row.appendChild(pill);
            }

            paintEditorRow(row);
            list.appendChild(row);
        });

        const fragment = document.createDocumentFragment();
        fragment.append(head, error, list);
        return fragment;
    };

    // The editor, drawn where it belongs: in the reading's Services section
    // while a reading is on screen, and under the parent's answer otherwise —
    // there only for an agreed form, the one answer with services to mark.
    const renderEditor = () => {
        const inFold = Boolean(foldHost?.isConnected);
        if (servicesField) servicesField.hidden = inFold || !isAgreed();
        servicesHome?.replaceChildren();
        editorHost()?.replaceChildren(buildEditor());
        syncEditor();
    };

    // ── The read-only summary ───────────────────────────────────────
    const setSummaryRow = (rowId, valueId, text) => {
        const row = document.getElementById(rowId);
        const value = document.getElementById(valueId);
        if (!row || !value) return;
        row.hidden = text === '';
        value.textContent = text;
    };

    const renderSummaryServices = () => {
        const rows = [];
        let group = null;
        confirmServices.forEach((service) => {
            if (service.group && service.group !== group) {
                rows.push(groupHead(service, 'pcc-group', false));
            }
            group = service.group || null;

            const state = stateOf(service.key);
            const row = document.createElement('li');
            row.className = 'pcc-row is-' + state + (service.group ? ' is-child' : '');
            const line = document.createElement('div');
            line.className = 'pcc-line';
            const box = document.createElement('span');
            box.className = 'pcc-box';
            box.setAttribute('aria-hidden', 'true');
            const name = document.createElement('span');
            name.className = 'pcc-name';
            name.lang = 'ceb';
            name.textContent = service.label;
            const pill = document.createElement('span');
            pill.className = 'pcc-state';
            pill.textContent = stateWords[state];
            line.append(box, name, pill);
            row.appendChild(line);
            rows.push(row);
        });
        confirmList.replaceChildren(...rows);
        confirmTally.textContent = tallyText();
    };

    // The allergy and illness lines as they will be filed; an empty line is
    // filed as nothing written.
    const renderSummaryHealth = () => {
        const box = document.getElementById('pcConfirmHealth');
        if (!box) return;
        box.replaceChildren(...Object.entries(writeIns).map(([field, caption]) => {
            const value = (uploadForm.querySelector('[name="' + field + '"]')?.value || '').trim();
            const row = document.createElement('div');
            const term = document.createElement('dt');
            term.textContent = caption;
            const detail = document.createElement('dd');
            detail.textContent = value || '—';
            if (value === '') detail.classList.add('is-empty');
            row.append(term, detail);
            return row;
        }));
    };

    const openConfirm = () => {
        if (!confirmModal) return;

        document.getElementById('pcConfirmLearner').textContent = learnerName();
        const answer = document.getElementById('pcConfirmAnswer');
        answer.className = 'pcc-answer ' + (answerKinds[answerSelect.value] || 'is-none');
        answer.textContent = answerSelect.options[answerSelect.selectedIndex]?.text || '';
        document.getElementById('pcConfirmFile').textContent = consentFile?.files?.[0]?.name || '';

        // What the parent wrote under their box, as the form carries it.
        const note = { partial: ['Exceptions', 'pc_partial_exception'], refused: ['Reason given', 'pc_refused_reason'] }[answerSelect.value];
        const noteText = note ? (document.getElementById(note[1])?.value || '').trim() : '';
        document.getElementById('pcConfirmNoteLabel').textContent = note ? note[0] : '';
        setSummaryRow('pcConfirmNoteRow', 'pcConfirmNote', noteText);
        setSummaryRow('pcConfirmCertRow', 'pcConfirmCert', document.getElementById('pc_med_cert')?.files?.[0]?.name || '');
        setSummaryRow('pcConfirmNotesRow', 'pcConfirmNotes', (document.getElementById('pc_notes')?.value || '').trim());

        renderSummaryServices();
        renderSummaryHealth();

        confirmSubmit.disabled = false;
        confirmModal.classList.add('open');
        confirmModal.setAttribute('aria-hidden', 'false');
        // The summary opens at its top, the learner and the answer first;
        // focus waits on Confirm & Upload without scrolling down to it.
        const panel = confirmModal.querySelector('.confirm-modal');
        if (panel) panel.scrollTop = 0;
        confirmSubmit.focus({ preventScroll: true });
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
            const consented = keysIn('consented');
            // The summary opens only once both hold; a form changed behind it
            // goes back to be checked again.
            if (consented.length === 0 || keysIn('check').length > 0) {
                closeConfirm();
                return;
            }
            writeKeys(servicesBox, 'services[]', consented);
            writeKeys(exceptedBox, 'excepted_services[]', answerSelect.value === 'partial'
                ? letterServices().filter((key) => stateOf(key) !== 'consented')
                : []);
            servicesConfirmed.value = '1';
        } else {
            writeKeys(servicesBox, 'services[]', readServices);
            writeKeys(exceptedBox, 'excepted_services[]', []);
            servicesConfirmed.value = '0';
        }

        if (!uploadForm.checkValidity()) {
            closeConfirm();
            uploadForm.reportValidity();
            return;
        }

        confirmedSubmit = true;
        confirmSubmit.disabled = true;
        if (typeof uploadForm.requestSubmit === 'function') uploadForm.requestSubmit();
        else uploadForm.submit();
    });

    // ── Reading the answer off the photo ────────────────────────────
    // The consent reader proposes; the adviser records. Its answer lands
    // in the same select an adviser would set by hand, its services and
    // allergy lines pre-fill the reading and the fields, and all of it is
    // filed only once the adviser ticks "I checked this answer against the
    // signed form" — the server refuses a reader's answer otherwise. Only
    // rendered where a reader is configured.
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
    // Each check names its field and says what to do; choosing one opens that
    // part of the reading and brings the same part of the photo into view.
    const setFlash = (kind, title, items = []) => {
        if (!reviewFlash) return;
        reviewFlash.className = 'pc-view-flash is-' + kind + (items.length ? ' has-list' : '');
        reviewFlash.textContent = '';
        const head = document.createElement('p');
        head.className = 'pc-checks-head';
        head.textContent = title;
        reviewFlash.appendChild(head);
        if (items.length) {
            const list = document.createElement('ul');
            list.className = 'pc-review-checks';
            items.forEach((item) => {
                const line = document.createElement('li');
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'pc-check';
                const subject = document.createElement('b');
                subject.textContent = item.subject || '';
                const message = document.createElement('span');
                message.textContent = item.message || '';
                button.append(subject, message);
                button.addEventListener('click', () => goToSection(item.section));
                line.appendChild(button);
                list.appendChild(line);
            });
            reviewFlash.appendChild(list);
        }
    };

    // ── The photo pane ──────────────────────────────────────────────
    const photoPane = document.getElementById('pcPhotoPane');
    const photoFrame = document.getElementById('pcPhotoFrame');
    const zoomLevel = document.getElementById('pcZoomLevel');
    const zoomSteps = [1, 1.5, 2, 3];
    let zoom = 0;

    // Zooming keeps the point at the middle of the frame where it was, so a
    // box being looked at stays in view as it grows.
    const setZoom = (step) => {
        if (!photoFrame || !reviewPhoto) return;
        const next = Math.min(zoomSteps.length - 1, Math.max(0, step));
        const ratio = zoomSteps[next] / zoomSteps[zoom];
        const midX = photoFrame.scrollLeft + photoFrame.clientWidth / 2;
        const midY = photoFrame.scrollTop + photoFrame.clientHeight / 2;
        zoom = next;
        reviewPhoto.style.width = (zoomSteps[zoom] * 100) + '%';
        photoFrame.classList.toggle('is-zoomed', zoom > 0);
        photoFrame.scrollLeft = midX * ratio - photoFrame.clientWidth / 2;
        photoFrame.scrollTop = midY * ratio - photoFrame.clientHeight / 2;
        if (zoomLevel) zoomLevel.textContent = Math.round(zoomSteps[zoom] * 100) + '%';
        const zoomOut = document.getElementById('pcZoomOut');
        const zoomIn = document.getElementById('pcZoomIn');
        if (zoomOut) zoomOut.disabled = zoom === 0;
        if (zoomIn) zoomIn.disabled = zoom === zoomSteps.length - 1;
    };
    document.getElementById('pcZoomIn')?.addEventListener('click', () => setZoom(zoom + 1));
    document.getElementById('pcZoomOut')?.addEventListener('click', () => setZoom(zoom - 1));

    // Where each section of the reading sits on the printed sheet, as a share
    // of its height, so opening one brings the same part of the photo into
    // view. The sheet is one printed form; a photo with a wider margin moves
    // these a little, never off the frame.
    // The fourth is the parent's signature line, which has no section of its own.
    const sheetSpots = [0.36, 0.6, 0.69, 0.74];
    const showSpot = (index) => {
        if (!photoFrame || !reviewPhoto || sheetSpots[index] === undefined) return;
        const top = reviewPhoto.offsetHeight * sheetSpots[index] - photoFrame.clientHeight / 2;
        photoFrame.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
    };

    // A check's part of the sheet: its section of the reading opened and in
    // view, and the photo moved to the same place. A check about the sheet as
    // a whole goes to the top of the photo.
    let folds = [];
    const sectionIndex = { services: 0, consent: 1, allergies: 2, signature: 3 };
    const goToSection = (section) => {
        const index = sectionIndex[section];
        if (index === undefined) {
            photoFrame?.scrollTo({ top: 0, behavior: 'smooth' });
            return;
        }
        const fold = folds[index];
        if (fold) {
            fold.open = true;
            fold.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
        showSpot(index);
    };

    // The sheet as the server drew it, folded: each of its three sections is
    // one line naming what it holds ("3 Consented · 1 To check"), and opens to
    // its rows. The Services section is where the adviser marks each service
    // Consented or Excluded, so it opens on its own and its rows give way to
    // the marks, pre-filled from the reading; the parent's answer opens on
    // its own too, and the allergies whenever something there needs a look.
    const foldCopy = (root) => {
        folds = [];
        foldHost = null;
        foldTally = null;
        foldSection = null;
        root.querySelectorAll('.pcopy-section').forEach((section, index) => {
            const head = section.querySelector('.pcopy-head');
            if (!head) return;

            if (index === 0) {
                const fold = document.createElement('details');
                fold.className = 'pc-fold';
                const summary = document.createElement('summary');
                summary.className = 'pc-fold-sum';
                const title = document.createElement('span');
                title.className = 'pc-fold-title';
                title.append(...head.childNodes);
                const tally = document.createElement('span');
                tally.className = 'pc-fold-tally';
                summary.append(title, tally);

                const body = document.createElement('div');
                body.className = 'pc-fold-body';
                const host = document.createElement('div');
                host.className = 'pc-svc';
                host.setAttribute('role', 'group');
                host.setAttribute('aria-label', 'Health services');
                body.appendChild(host);

                section.replaceChildren(fold);
                fold.append(summary, body);
                fold.open = true;
                summary.addEventListener('click', () => { if (!fold.open) showSpot(index); });
                folds[index] = fold;
                foldHost = host;
                foldTally = tally;
                foldSection = fold;
                return;
            }

            const counts = new Map();
            section.querySelectorAll('.pcopy-std').forEach((pill) => {
                const key = pill.className;
                const entry = counts.get(key) || { label: pill.textContent.trim(), count: 0 };
                entry.count += 1;
                counts.set(key, entry);
            });
            const needsCheck = section.querySelector('.pcopy-std.is-unclear, .pcopy-std.is-check_exceptions') !== null;

            const fold = document.createElement('details');
            fold.className = 'pc-fold';
            const summary = document.createElement('summary');
            summary.className = 'pc-fold-sum';
            const title = document.createElement('span');
            title.className = 'pc-fold-title';
            title.append(...head.childNodes);
            const tally = document.createElement('span');
            tally.className = 'pc-fold-tally';
            counts.forEach(({ label, count }, className) => {
                const chip = document.createElement('span');
                chip.className = className + ' pc-fold-chip';
                // The row's pill says "?"; counted, it needs a word.
                chip.textContent = count + ' ' + (label === '?' ? 'Unclear' : label);
                tally.appendChild(chip);
            });
            summary.append(title, tally);

            const body = document.createElement('div');
            body.className = 'pc-fold-body';
            body.append(...Array.from(section.childNodes).filter((node) => node !== head));
            head.remove();
            fold.append(summary, body);
            fold.open = needsCheck || index === 1;
            if (needsCheck) fold.classList.add('needs-check');
            // Only the adviser's own opening moves the photo; the sections
            // that open by themselves leave it at the top of the sheet.
            summary.addEventListener('click', () => { if (!fold.open) showSpot(index); });
            section.appendChild(fold);
            folds[index] = fold;
        });
    };

    // The photo beside the sheet as the reader saw it, drawn by the server
    // in the form's own words. It belongs to one photo and one learner,
    // like the rest, so it is dropped with them. The upright copy the page
    // sent the reader is shown when there is one, so a sheet the reader had
    // turned reads the right way up here too.
    const showReview = (file, data, sheet = null) => {
        if (photoDialog?.open) photoDialog.close();
        if (photoUrl) {
            URL.revokeObjectURL(photoUrl);
            photoUrl = null;
        }
        if (!review) return;

        if (!file || !data) {
            review.hidden = true;
            if (photoPane) photoPane.hidden = true;
            uploadModal.classList.remove('is-reviewing');
            reviewPhoto?.removeAttribute('src');
            photoDialogImg?.removeAttribute('src');
            if (copyBox) copyBox.innerHTML = '';
            folds = [];
            foldHost = null;
            foldTally = null;
            foldSection = null;
            renderEditor();
            return;
        }

        photoUrl = URL.createObjectURL(sheet instanceof Blob && sheet.size > 0 ? sheet : file);
        if (reviewPhoto) reviewPhoto.src = photoUrl;
        if (reviewEnlarge) reviewEnlarge.hidden = typeof photoDialog?.showModal !== 'function';

        const draft = data.draft || {};
        const summary = data.summary || {};
        const checks = (Array.isArray(summary.checks) ? summary.checks : [])
            .filter((item) => item && typeof item.message === 'string' && item.message.trim() !== '');

        if (draft.learner_mismatch) {
            setFlash('err', draft.consent_reason || 'The name on this form is another learner\'s.');
        } else if (draft.unreadable) {
            setFlash('err', checks[0]?.message || 'The photo could not be read. Choose the answer from the paper.');
        } else if (summary.complete) {
            setFlash('ok', '✓ All required fields completed');
        } else if (checks.length) {
            setFlash('warn', checks.length + (checks.length === 1 ? ' thing' : ' things') + ' to check against the paper', checks);
        } else {
            setFlash('warn', summary.decision || 'Check the reading against the signed form.');
        }

        if (copyBox) {
            copyBox.innerHTML = data.copy_html || '';
            foldCopy(copyBox);
        }
        renderEditor();
        review.hidden = false;
        if (photoPane) photoPane.hidden = false;
        uploadModal.classList.add('is-reviewing');
        zoom = 0;
        setZoom(0);
        if (photoFrame) photoFrame.scrollTop = 0;
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

    // Whether the answer in the select is the reader's.
    const setReaderAnswer = (fromScan) => {
        if (answerFromScan) answerFromScan.value = fromScan ? '1' : '0';
    };

    // "I checked this answer against the signed form", beside Upload
    // Consent: shown, and required, while a reading of this photo is on
    // screen, and unticked whenever a new one arrives or the old one goes.
    const setAttestation = (show) => {
        if (!answerConfirm || !answerConfirmed) return;
        answerConfirm.hidden = !show;
        answerConfirmed.required = show;
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
    const notSpecified = @json(\App\Support\LearnerAllergies::NOT_SPECIFIED);

    const fillFromReading = (draft, services, proposed) => {
        setServices(
            Array.isArray(services) ? services : [],
            proposed || null,
            Object.keys(headingNotices).filter((heading) => draft.services?.[heading] === 'yes'),
        );

        Object.entries(writtenFields).forEach(([from, to]) => {
            const input = uploadForm.querySelector('[name="' + to + '"]');
            const entry = (draft.written && draft.written[from]) || {};
            // A box ticked with nothing written beside it: the parent said
            // yes and named nothing. Left blank, the field would be filed as
            // "no allergy"; it is filed as the clinic's own "Not specified".
            if (input) input.value = entry.text || (entry.box === 'yes' ? notSpecified : '');
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
    // The adviser's marks go with it: they were made against that reading.
    const dropReading = () => {
        cancelRead();
        decided.clear();
        if (answerFromScan?.value === '1') {
            answerSelect.value = '';
            setReaderAnswer(false);
            syncAnswerLines();
        }
        clearReading();
        setAttestation(false);
        showReview(null);
        renderEditor();
        syncReader();
    };

    consentFile?.addEventListener('change', dropReading);
    studentSelect?.addEventListener('change', dropReading);

    // Choosing by hand makes the answer the adviser's own. The services are
    // redrawn for it: the marks the adviser made stand, and every other row
    // starts where the reading puts it for that answer.
    answerSelect.addEventListener('change', () => {
        cancelRead();
        setReaderAnswer(false);
        renderEditor();
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
                showReview(file, data, body.get('sheet'));
                // A reading the adviser can hold against the paper waits on
                // their tick; one that read nothing has nothing to check.
                setAttestation(Boolean(data.copy_html) || answer !== '');

                if (draft.unreadable) {
                    readState.textContent = 'The photo could not be read. Choose the answer from the paper.';
                } else if (answer === '') {
                    readState.textContent = draft.consent_reason || 'The answer could not be made out. Choose it from the paper.';
                } else {
                    const label = answerSelect.options[answerSelect.selectedIndex]?.text || answer;
                    // A missing signature is in the list of checks under the
                    // reading, once; it is not said here a second time.
                    readState.textContent = 'Read as "' + label + '". Check it against the paper before uploading.';
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
        setAttestation(false);
        filledFromScan = false;
        setServices([]);
        decided.clear();
        writeKeys(exceptedBox, 'excepted_services[]', []);
        servicesConfirmed.value = '0';
        confirmedSubmit = false;
        if (confirmSubmit) confirmSubmit.disabled = false;
        showReview(null);
        syncAnswerLines();
        renderEditor();
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
        // The summary sits above the upload: Escape is Back to Edit, and
        // Tab stays inside the summary.
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
