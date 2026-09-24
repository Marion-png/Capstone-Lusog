{{--
    "New Consultation" as a dialog, so a consultation can be recorded
    without leaving the page — from the Consultation Log, or from a
    learner's profile without losing the profile behind it.

    Built on the shared .bmodal system (partials/board-modal-assets), the
    same one the announcement and event dialogs use, so every dialog in the
    product opens, blurs and closes the same way.

    Anything marked data-bmodal-open="consultModal" opens it. To open it for
    a specific learner, also set data-consult-name / data-consult-section on
    the trigger, or call window.openConsultationFor(name, section).

    The standalone page (dashboard.consultation-create) is still there and
    still works — this does not replace it, so an existing bookmark or a
    direct link keeps behaving.
--}}
@php
    use App\Models\Condition;

    // The clinic's own catalogue, grouped so the list is scannable rather
    // than 33 items in a row. Queried here rather than passed in, so both
    // pages that include this dialog get it without controller changes.
    $consultConditions = \App\Support\SchemaCache::hasTable('conditions')
        ? Condition::orderBy('category')->orderBy('name')->get()->groupBy('category')
        : collect();

    // Medicine dispensed during the visit. Recording it here draws the
    // stock down in the same transaction as the consultation, so the
    // inventory cannot drift from what the clinic actually handed over.
    //
    // Nurse only, enforced again in ConsultationController: clinic staff log
    // consultations but are deliberately not admitted to the dispensing
    // path, and this must not become a way around that.
    $consultMayDispense = session('active_role') === 'school_nurse';

    // The school's learners, for the name picker: opened cold, the nurse
    // types a name and chooses the learner, and the grade and section come
    // off the record rather than being typed against it. Names are encrypted
    // at rest, so the index is embedded and searched in the browser.
    $consultLearnerIndex = \App\Support\LearnerSearchIndex::fromSession(request());

    $consultMedicines = ($consultMayDispense && \App\Support\SchemaCache::hasTable('medicines'))
        ? \App\Models\Medicine::query()
            ->when(session('active_institution_id'), fn ($q, $id) => $q->where('institution_id', $id))
            ->where('stock_quantity', '>', 0)
            ->orderBy('name')
            ->get()
        : collect();

@endphp

@include('partials.board-modal-assets')

<div class="bmodal" id="consultModal" role="dialog" aria-modal="true" aria-labelledby="consultModalTitle"
     @if ($errors->consultation->any()) data-bmodal-autoopen @endif>
    <div class="bmodal-panel bmodal-panel-wide">
        <form method="POST" action="{{ route('consultations.store') }}" enctype="multipart/form-data">
            @csrf
            {{-- Where the save comes back to: the page the dialog was opened
                 on, not always the Consultation Log. A profile sets it to
                 itself with the learner open, so the nurse lands back on the
                 child they were reading. The server accepts only a URL inside
                 this app and otherwise falls back to the log. --}}
            <input type="hidden" name="return_to" id="cm_return_to" value="{{ old('return_to', url()->full()) }}">
            <div class="bmodal-head">
                <div>
                    <div class="bmodal-eyebrow">Consultation</div>
                    <div class="bmodal-title" id="consultModalTitle">New consultation</div>
                    <div class="bmodal-sub">Record a clinic visit and the treatment given.</div>
                </div>
                <button type="button" class="bmodal-close" data-bmodal-close aria-label="Close">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <div class="bmodal-body">
                <div class="bmodal-field bmodal-grid">
                    <div>
                        <label for="cm_consulted_at">Date and time</label>
                        <input id="cm_consulted_at" type="datetime-local" name="consulted_at"
                               value="{{ old('consulted_at', now()->format('Y-m-d\TH:i')) }}" required>
                        @if ($errors->consultation->has('consulted_at'))
                            <div class="bmodal-error">{{ $errors->consultation->first('consulted_at') }}</div>
                        @endif
                    </div>
                    <div>
                        <label for="cm_status">Status</label>
                        <select id="cm_status" name="status" required>
                            <option value="treated" @selected(old('status', 'treated') === 'treated')>Treated</option>
                            <option value="referred" @selected(old('status') === 'referred')>Referred</option>
                        </select>
                        @if ($errors->consultation->has('status'))
                            <div class="bmodal-error">{{ $errors->consultation->first('status') }}</div>
                        @endif
                    </div>
                </div>

                {{-- Locked for the same reason the grade and section below are,
                     and locked with them: the nurse searched for this learner
                     and opened their profile, so the name is the record's, not
                     something to retype. A name that can be edited beside a
                     frozen section is how a visit ends up filed against a
                     mismatched pair — the wrong child on the right class. --}}
                <div class="bmodal-field" id="cm_student_name_field">
                    <label for="cm_student_name">Student name</label>
                    {{-- Opened cold, this is a search over the school's roll:
                         typing lists matching learners, and choosing one fills
                         the grade and section from their record and locks it.
                         A name typed and never chosen is still accepted — a
                         visitor not on the roll is still a visit. --}}
                    <div class="cmcombo" id="cm_student_combo">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input id="cm_student_name" type="text" name="student_name"
                               value="{{ old('student_name') }}" placeholder="Type a learner's name or LRN…" required autocomplete="off"
                               role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="cm_student_results">
                        <div class="cmcombo-list" id="cm_student_results" role="listbox"></div>
                    </div>
                    <div class="bmodal-note">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <span>From the learner's record. Correct it on their profile.</span>
                    </div>
                    @if ($errors->consultation->has('student_name'))
                        <div class="bmodal-error">{{ $errors->consultation->first('student_name') }}</div>
                    @endif
                </div>

                {{-- Opened from a learner's profile, the grade and section are
                     that learner's own — the dialog fills them in and locks
                     them, because a consultation typed against a section the
                     record does not have is a consultation filed under the
                     wrong class. Opened cold from the Consultation Log there
                     is no record to read, so the nurse still types it. --}}
                <div class="bmodal-field" id="cm_grade_section_field">
                    <label for="cm_grade_section">Grade and section</label>
                    <input id="cm_grade_section" type="text" name="grade_section"
                           value="{{ old('grade_section') }}" placeholder="e.g. Grade 10 - Rizal" required autocomplete="off">
                    <div class="bmodal-note">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <span>From the learner's record. Correct it on their profile.</span>
                    </div>
                    @if ($errors->consultation->has('grade_section'))
                        <div class="bmodal-error">{{ $errors->consultation->first('grade_section') }}</div>
                    @endif
                </div>

                <div class="bmodal-field">
                    <label for="cm_condition_id">Condition</label>
                    @if ($consultConditions->isEmpty())
                        {{-- The catalogue has not been seeded. Fall back to
                             free text (the store accepts `condition` as well
                             as `condition_id`) rather than showing an empty
                             dropdown nobody can pick from. --}}
                        <input id="cm_condition_id" type="text" name="condition"
                               value="{{ old('condition') }}" placeholder="e.g. Headache" required autocomplete="off">
                    @else
                        @php
                            // The catalogue's catch-all. Selecting it asks for
                            // the detail, because "Others" on its own tells a
                            // later reader nothing.
                            $catchAllId = optional(
                                $consultConditions->flatten()->first(fn ($c) => strcasecmp($c->name, 'Others') === 0)
                            )->id;
                        @endphp
                        @php
                            // The catalogue, flattened for the browser to search.
                            // It is embedded rather than fetched: it is small,
                            // fixed, and a nurse typing should not wait on a
                            // round trip per keystroke.
                            //
                            // Sorted by name across every category, not grouped
                            // by one. The nurse types the complaint, so what
                            // orders the results is the complaint — grouping
                            // mattered when this was a list to scan, and a
                            // search that returns "Fever" above "Abdominal pain"
                            // because one is Respiratory reads as unordered.
                            $conditionIndex = $consultConditions
                                ->flatMap(fn ($group, $category) => $group->map(fn ($c) => [
                                    'id' => $c->id,
                                    'name' => $c->name,
                                    'category' => $category,
                                ]))
                                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                                ->values();

                            $selectedName = old('condition_id')
                                ? optional($conditionIndex->firstWhere('id', (int) old('condition_id')))['name']
                                : null;
                        @endphp

                        {{-- A search box, not a list. The catalogue runs to
                             dozens of entries across seven categories, and
                             scrolling one to find "Headache" is slower than
                             typing it. Nothing is shown until the nurse types:
                             an open list on focus covers the fields below and
                             is a menu to be read rather than an answer to be
                             found. --}}
                        <div class="cmcombo" id="cm_condition_combo" data-catch-all="{{ $catchAllId }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input type="text"
                                   id="cm_condition_search"
                                   placeholder="Type to search conditions…"
                                   value="{{ $selectedName }}"
                                   autocomplete="off"
                                   role="combobox"
                                   aria-expanded="false"
                                   aria-autocomplete="list"
                                   aria-controls="cm_condition_results"
                                   required>
                            {{-- What actually posts. The visible box is for
                                 finding; this is the answer. --}}
                            <input type="hidden" id="cm_condition_id" name="condition_id" value="{{ old('condition_id') }}">
                            <div class="cmcombo-list" id="cm_condition_results" role="listbox"></div>
                        </div>

                        {{-- Revealed only when "Others" is chosen. --}}
                        <div id="cm_condition_other_wrap" hidden style="margin-top:9px">
                            <input id="cm_condition_other" type="text" name="condition"
                                   value="{{ old('condition') }}" maxlength="255"
                                   placeholder="Describe the condition" autocomplete="off">
                        </div>
                    @endif
                    @if ($errors->consultation->has('condition_id') || $errors->consultation->has('condition'))
                        <div class="bmodal-error">{{ $errors->consultation->first('condition_id') ?: $errors->consultation->first('condition') }}</div>
                    @endif
                </div>

                <div class="bmodal-field">
                    <label for="cm_treatment_given">Treatment given</label>
                    <textarea id="cm_treatment_given" name="treatment_given"
                              placeholder="Medicine given, recommendations, referral note...">{{ old('treatment_given') }}</textarea>
                    @if ($errors->consultation->has('treatment_given'))
                        <div class="bmodal-error">{{ $errors->consultation->first('treatment_given') }}</div>
                    @endif
                </div>

                {{-- A note on the visit, written with it. It replaced the
                     profile's separate Clinic Notes tab: a comment about a
                     visit belongs on that visit, and it shows under the
                     consultation on the profile's Consultation Log tab. --}}
                @if (\App\Support\SchemaCache::hasColumn('consultations', 'notes'))
                    <div class="bmodal-field">
                        <label for="cm_notes">Notes / comments <span class="bmodal-optional">(optional)</span></label>
                        <textarea id="cm_notes" name="notes" maxlength="2000"
                                  placeholder="Clinical observation, follow-up, or anything worth noting about this visit">{{ old('notes') }}</textarea>
                        @if ($errors->consultation->has('notes'))
                            <div class="bmodal-error">{{ $errors->consultation->first('notes') }}</div>
                        @endif
                    </div>
                    @if (\App\Support\SchemaCache::hasColumn('consultations', 'notes_shared_with_adviser'))
                        {{-- A note is clinical text and stops at the clinic, like the
                             complaint and the treatment. This is the one way it
                             reaches the class adviser, and it is the nurse's
                             decision — the same one a shared photograph is. --}}
                        <div class="bmodal-field cm-photo-share">
                            <label class="cm-photo-check">
                                <input type="checkbox" name="notes_shared_with_adviser" value="1" @checked(old('notes_shared_with_adviser'))>
                                <span>Share this note with the learner's class adviser</span>
                            </label>
                            <div class="bmodal-hint">Notes stay in the clinic unless shared.</div>
                        </div>
                    @endif
                @endif

                @if ($consultMayDispense && $consultMedicines->isNotEmpty())
                    {{-- Optional. Choosing one deducts it from stock when the
                         consultation saves — one action, one transaction, so a
                         medicine handed over cannot go unrecorded. --}}
                    <div class="bmodal-grid">
                        <div class="bmodal-field">
                            <label for="cm_medicine_id">Medicine dispensed <span class="bmodal-optional">(optional)</span></label>
                            <select id="cm_medicine_id" name="medicine_id">
                                <option value="">None</option>
                                @foreach ($consultMedicines as $medicine)
                                    <option value="{{ $medicine->id }}" @selected((string) old('medicine_id') === (string) $medicine->id)>
                                        {{ $medicine->name }} ({{ $medicine->stock_quantity }} {{ $medicine->unit }} left)
                                    </option>
                                @endforeach
                            </select>
                            @if ($errors->consultation->has('medicine_id'))
                                <div class="bmodal-error">{{ $errors->consultation->first('medicine_id') }}</div>
                            @endif
                        </div>
                        <div class="bmodal-field">
                            <label for="cm_medicine_quantity">Quantity</label>
                            <input id="cm_medicine_quantity" type="number" name="medicine_quantity" min="1" step="1"
                                   value="{{ old('medicine_quantity') }}" placeholder="e.g. 1" autocomplete="off">
                            @if ($errors->consultation->has('medicine_quantity'))
                                <div class="bmodal-error">{{ $errors->consultation->first('medicine_quantity') }}</div>
                            @endif
                        </div>
                    </div>
                    <div class="bmodal-hint">Deducted from inventory when this consultation is saved.</div>
                @endif

                {{-- Photographs of the injury, attached while the visit is
                     recorded — a cut, a rash, a swelling, seen rather than
                     described. Same limits and same storage as the Photos
                     dialog on the log, so one taken now and one added later
                     are the same record. Not shared with the class adviser
                     unless the nurse ticks it: the adviser sees no other
                     consultation detail, and a photo of a child's injury must
                     not become the back door. --}}
                @if (\App\Support\SchemaCache::hasTable('consultation_photos'))
                    <div class="bmodal-field" id="cm_photos_field">
                        <label for="cm_photos">Photos of the injury <span class="bmodal-optional">(optional)</span></label>
                        <label class="cm-photo-drop" for="cm_photos">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                            <span id="cm_photos_label">Take or choose up to {{ \App\Models\ConsultationPhoto::MAX_PER_UPLOAD }} photos</span>
                            <input id="cm_photos" type="file" name="photos[]" multiple
                                   accept="image/jpeg,image/png,image/webp,image/heic" capture="environment">
                        </label>
                        <div class="cm-photo-previews" id="cm_photos_previews" hidden></div>
                        @if ($errors->consultation->has('photos') || $errors->consultation->has('photos.*'))
                            <div class="bmodal-error">{{ $errors->consultation->first('photos') ?: $errors->consultation->first('photos.*') }}</div>
                        @endif
                    </div>
                    <div class="bmodal-grid" id="cm_photo_meta" hidden>
                        <div class="bmodal-field">
                            <label for="cm_photo_caption">What the photos show <span class="bmodal-optional">(optional)</span></label>
                            <input id="cm_photo_caption" type="text" name="photo_caption" maxlength="500"
                                   value="{{ old('photo_caption') }}" placeholder="e.g. Graze to the left knee, cleaned and dressed" autocomplete="off">
                        </div>
                        <div class="bmodal-field cm-photo-share">
                            <label class="cm-photo-check">
                                <input type="checkbox" name="photo_shared_with_adviser" value="1" @checked(old('photo_shared_with_adviser'))>
                                <span>Share with the learner's class adviser</span>
                            </label>
                            <div class="bmodal-hint">Photos stay in the clinic unless shared.</div>
                        </div>
                    </div>
                @endif
            </div>

            <div class="bmodal-foot">
                <button type="button" class="bmodal-btn bmodal-btn-ghost" data-bmodal-close>Cancel</button>
                <button type="submit" class="bmodal-btn bmodal-btn-primary">Save consultation</button>
            </div>
        </form>
    </div>
</div>

<style>
    /* The consultation form carries more fields than a notice, so its panel
       is wider than the shared default. */
    .bmodal-panel-wide { max-width: 640px; }
    /* Category headings inside the condition list: quieter than the options
       they group, so the eye lands on the conditions themselves. */
    #consultModal optgroup {
        font-size: .68rem;
        font-weight: 700;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: #6B7C72;
    }
    #consultModal optgroup option {
        font-size: .84rem;
        font-weight: 400;
        letter-spacing: 0;
        text-transform: none;
        color: #1d3c31;
    }

    /* Photos of the injury: a drop target that is also the camera button on
       a phone (capture="environment"), then thumbnails of what was chosen so
       the nurse sees the picture before it is filed against a child. */
    #consultModal .cm-photo-drop {
        display: flex; align-items: center; gap: 9px;
        padding: 10px 12px; margin-top: 4px;
        border: 1.5px dashed #DCE8E0; border-radius: 10px;
        background: #F6F9F7; color: #3E5348; font-size: .8rem; cursor: pointer;
    }
    #consultModal .cm-photo-drop:hover { border-color: #BFE3CC; color: #1F8A4C; }
    #consultModal .cm-photo-drop svg { width: 16px; height: 16px; flex: 0 0 auto; }
    #consultModal .cm-photo-drop input { position: absolute; width: 1px; height: 1px; opacity: 0; overflow: hidden; }
    #consultModal .cm-photo-previews {
        display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px;
    }
    #consultModal .cm-photo-previews[hidden] { display: none; }
    #consultModal .cm-photo-thumb {
        width: 64px; height: 64px; border-radius: 8px; object-fit: cover;
        border: 1px solid #DCE8E0; background: #fff;
    }
    #consultModal .cm-photo-check {
        display: flex; align-items: center; gap: 8px;
        font-size: .82rem; color: #1d3c31; margin-top: 22px;
    }
    #consultModal .cm-photo-check input { width: auto; margin: 0; }
    #consultModal #cm_photo_meta[hidden] { display: none; }
</style>

<script>
// Photos of the injury: name what was chosen, show it, and reveal the caption
// and sharing controls only once there is a photo for them to describe.
(() => {
    const input = document.getElementById('cm_photos');
    const label = document.getElementById('cm_photos_label');
    const previews = document.getElementById('cm_photos_previews');
    const meta = document.getElementById('cm_photo_meta');
    if (!input) return;

    const max = {{ (int) \App\Models\ConsultationPhoto::MAX_PER_UPLOAD }};
    const idle = label ? label.textContent : '';

    input.addEventListener('change', () => {
        const files = Array.from(input.files || []);
        if (previews) {
            previews.querySelectorAll('img').forEach((img) => URL.revokeObjectURL(img.src));
            previews.textContent = '';
        }

        if (files.length > max) {
            input.value = '';
            if (label) label.textContent = 'Choose at most ' + max + ' photos.';
            if (previews) previews.hidden = true;
            if (meta) meta.hidden = true;
            return;
        }

        if (label) {
            label.textContent = files.length === 0
                ? idle
                : files.length + (files.length === 1 ? ' photo chosen' : ' photos chosen');
        }

        if (previews) {
            files.forEach((file) => {
                if (!file.type.startsWith('image/')) return;
                const img = document.createElement('img');
                img.className = 'cm-photo-thumb';
                img.alt = '';
                img.src = URL.createObjectURL(file);
                previews.appendChild(img);
            });
            previews.hidden = files.length === 0;
        }
        if (meta) meta.hidden = files.length === 0;
    });
})();

// Open the dialog with a learner already filled in.
(() => {
    const nameField = document.getElementById('cm_student_name');
    const nameWrap = document.getElementById('cm_student_name_field');
    const sectionField = document.getElementById('cm_grade_section');
    const sectionWrap = document.getElementById('cm_grade_section_field');

    // Read-only, never disabled: a disabled input posts nothing, so the field
    // would arrive empty and fail its own required rule.
    const setLock = (field, wrap, locked) => {
        if (!field) return;
        field.readOnly = locked;
        field.setAttribute('aria-readonly', locked ? 'true' : 'false');
        if (wrap) wrap.classList.toggle('is-locked', locked);
    };

    // The learner's identity is one decision, so the two fields lock together
    // — an editable name beside a frozen section files the wrong child on the
    // right class. Each is still locked only on what the record actually gave:
    // a learner with no section on file is still a learner the nurse must be
    // able to log.
    const lockLearner = (name, section) => {
        setLock(nameField, nameWrap, String(name || '').trim() !== '');
        setLock(sectionField, sectionWrap, String(section || '').trim() !== '');
    };

    // Called by the profile's "New Consultation" button.
    window.openConsultationFor = (name, section) => {
        if (nameField) nameField.value = name || '';
        if (sectionField) sectionField.value = section || '';

        lockLearner(name, section);

        // The Consultation Log's own trigger carries no learner, and this
        // borrows it to open the dialog. Flag the open so the delegated
        // handler below does not read that as "opened cold" and unlock what
        // was just filled in.
        window.__consultPrefilled = true;

        const trigger = document.querySelector('[data-bmodal-open="consultModal"]');
        if (trigger) {
            trigger.click();
            return;
        }

        window.__consultPrefilled = false;

        // No trigger on this page — open it directly.
        const modal = document.getElementById('consultModal');
        if (modal) modal.classList.add('open');
    };

    // "Others" asks for the detail; every other condition does not.
    // ── Condition search ─────────────────────────────────────────────
    //
    // Type to find, rather than scroll to find. The list stays closed until
    // the nurse types something: opening it on focus would cover the fields
    // below with a menu to be read, when what they want is one answer.
    //
    // The visible box finds; the hidden input answers. Editing the text after
    // a pick clears the pick, because a name on screen that no longer matches
    // the id underneath it is the one state this must never submit in.
    const combo = document.getElementById('cm_condition_combo');
    const otherWrap = document.getElementById('cm_condition_other_wrap');
    const otherInput = document.getElementById('cm_condition_other');

    if (combo && otherWrap && otherInput) {
        const search = document.getElementById('cm_condition_search');
        const hidden = document.getElementById('cm_condition_id');
        const list = document.getElementById('cm_condition_results');
        const catchAll = String(combo.dataset.catchAll || '');
        const conditions = @json($conditionIndex ?? []);

        const close = () => {
            list.classList.remove('show');
            search.setAttribute('aria-expanded', 'false');
        };

        // Revealed only when "Others" is chosen — "Others" alone tells a
        // later reader nothing.
        const syncOther = () => {
            const isOther = catchAll !== '' && hidden.value === catchAll;
            otherWrap.hidden = !isOther;
            otherInput.required = isOther;
            if (!isOther) otherInput.value = '';
        };

        const choose = (condition) => {
            hidden.value = condition.id;
            search.value = condition.name;
            search.setCustomValidity('');
            close();
            syncOther();
            if (otherInput.required) otherInput.focus();
        };

        // The catalogue entry "Others" is never listed among the matches —
        // it is pinned to the bottom instead, so it is in the same place
        // every time rather than moving up and down the list as the nurse
        // types. Excluding it here is what stops it appearing twice.
        const otherCondition = conditions.find((c) => String(c.id) === catchAll) || null;

        // Built from DOM nodes, never innerHTML: these names come out of the
        // database and a template string would run any markup inside one.
        const conditionRow = (condition, label, hint) => {
            const row = document.createElement('button');
            row.type = 'button';
            row.className = 'cmcombo-row';
            row.setAttribute('role', 'option');

            row.appendChild(Object.assign(document.createElement('span'), {
                className: 'cmcombo-name',
                textContent: label,
            }));
            row.appendChild(Object.assign(document.createElement('span'), {
                className: 'cmcombo-cat',
                textContent: hint,
            }));

            row.addEventListener('click', () => choose(condition));
            return row;
        };

        const render = (matches, term) => {
            list.textContent = '';

            if (matches.length === 0) {
                list.appendChild(Object.assign(document.createElement('div'), {
                    className: 'cmcombo-empty',
                    textContent: 'No condition matches "' + term + '".',
                }));
            }

            matches.slice(0, 8).forEach((condition) => {
                list.appendChild(conditionRow(condition, condition.name, condition.category));
            });

            // Always last, whether the search found anything or not. A
            // condition the catalogue does not carry is a real answer, and
            // the nurse should not have to discover that by first failing
            // to find one.
            if (otherCondition) {
                const other = conditionRow(
                    otherCondition,
                    'Others',
                    'Type the condition yourself'
                );
                other.classList.add('cmcombo-row-other');
                list.appendChild(other);
            }
        };

        const apply = () => {
            const raw = search.value.trim();
            const term = raw.toLowerCase();

            // A pick the nurse has typed over is no longer the answer.
            if (hidden.value !== '') {
                const picked = conditions.find((c) => String(c.id) === hidden.value);
                if (!picked || picked.name.toLowerCase() !== term) {
                    hidden.value = '';
                    syncOther();
                }
            }

            if (term === '') {
                list.textContent = '';
                close();
                return;
            }

            // Matched from the start of a word, not anywhere inside one.
            // A substring match on a single letter returned almost the whole
            // catalogue — "a" brought back Headache and Toothache — so the
            // first keystroke told the nurse nothing.
            //
            // Ranked rather than narrowed, because a nurse types both ways:
            // "a" should bring the A conditions, and "pain" should still find
            // "Abdominal pain".
            //   1. the name starts with what was typed
            //   2. a later word in the name starts with it
            //   3. the category starts with it
            // Each tier is already alphabetical, so the order within one holds.
            const startsWith = (value) => String(value).toLowerCase().startsWith(term);
            const wordStartsWith = (value) =>
                String(value).toLowerCase().split(/[^a-z0-9]+/).some((word) => word.startsWith(term));

            const tiers = [[], [], []];

            conditions.forEach((c) => {
                // Pinned to the bottom by render(), so never a match here.
                if (String(c.id) === catchAll) return;

                if (startsWith(c.name)) tiers[0].push(c);
                else if (wordStartsWith(c.name)) tiers[1].push(c);
                else if (startsWith(c.category)) tiers[2].push(c);
            });

            render(tiers[0].concat(tiers[1], tiers[2]), raw);

            list.classList.add('show');
            search.setAttribute('aria-expanded', 'true');
        };

        search.addEventListener('input', () => {
            search.setCustomValidity('');
            apply();
        });

        // Deliberately no focus handler: the list opens on typing alone.
        search.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') { close(); return; }

            // Enter takes the only match, so a nurse who typed the whole
            // name never has to reach for the mouse.
            if (event.key === 'Enter' && hidden.value === '') {
                // Real matches only — the pinned "Others" is always present,
                // and Enter must not quietly file a typo under it.
                const rows = list.querySelectorAll('.cmcombo-row:not(.cmcombo-row-other)');
                if (rows.length === 1) { event.preventDefault(); rows[0].click(); }
            }
        });

        document.addEventListener('click', (event) => {
            if (!combo.contains(event.target)) close();
        });

        // A typed name nobody picked is not a condition. The browser cannot
        // see that on its own — the visible box is full and the hidden one
        // is empty — so say why rather than letting the server reject it.
        combo.closest('form')?.addEventListener('submit', (event) => {
            if (hidden.value === '') {
                event.preventDefault();
                search.setCustomValidity(
                    search.value.trim() === ''
                        ? 'Search for the condition and choose it from the list.'
                        : 'Choose a condition from the list, or record it under "Others".'
                );
                search.reportValidity();
                apply();
            }
        });

        syncOther();
    }

    // ── Learner search ───────────────────────────────────────────────
    //
    // Same shape as the condition search below the fold: type to find, the
    // list stays closed until something is typed, rows are built from DOM
    // nodes because the names come out of the database. Choosing a learner
    // fills the grade and section from their record and locks that field;
    // typing over the name afterwards unlocks and clears it, because a
    // section that no longer belongs to the name beside it is the one state
    // this must never submit in.
    const studentCombo = document.getElementById('cm_student_combo');
    const studentList = document.getElementById('cm_student_results');
    const learners = @json($consultLearnerIndex ?? []);

    if (studentCombo && studentList && nameField) {
        let pickedName = '';

        const closeStudents = () => {
            studentList.classList.remove('show');
            nameField.setAttribute('aria-expanded', 'false');
        };

        const chooseLearner = (learner) => {
            pickedName = learner.name;
            nameField.value = learner.name;
            if (sectionField) sectionField.value = learner.section || '';
            setLock(sectionField, sectionWrap, String(learner.section || '').trim() !== '');
            closeStudents();
            if (sectionField && !sectionField.readOnly) sectionField.focus();
        };

        const learnerRow = (learner) => {
            const row = document.createElement('button');
            row.type = 'button';
            row.className = 'cmcombo-row';
            row.setAttribute('role', 'option');
            row.appendChild(Object.assign(document.createElement('span'), { className: 'cmcombo-name', textContent: learner.name }));
            row.appendChild(Object.assign(document.createElement('span'), { className: 'cmcombo-cat', textContent: learner.section || 'No section on file' }));
            row.addEventListener('click', () => chooseLearner(learner));
            return row;
        };

        const applyStudents = () => {
            // Filled in from a profile: the name is the record's, not a search.
            if (nameField.readOnly) { closeStudents(); return; }

            const raw = nameField.value.trim();
            const term = raw.toLowerCase();

            // Typed over a pick: the section was that learner's, not this name's.
            if (pickedName !== '' && raw !== pickedName) {
                pickedName = '';
                if (sectionField) sectionField.value = '';
                setLock(sectionField, sectionWrap, false);
            }

            if (term === '' || learners.length === 0) { studentList.textContent = ''; closeStudents(); return; }

            const startsWith = (value) => String(value || '').toLowerCase().startsWith(term);
            const wordStartsWith = (value) =>
                String(value || '').toLowerCase().split(/[^a-z0-9]+/).some((word) => word.startsWith(term));

            const tiers = [[], [], []];
            learners.forEach((learner) => {
                if (startsWith(learner.name) || startsWith(learner.lrn)) tiers[0].push(learner);
                else if (wordStartsWith(learner.name)) tiers[1].push(learner);
                else if (String(learner.name).toLowerCase().includes(term)) tiers[2].push(learner);
            });
            const matches = tiers[0].concat(tiers[1], tiers[2]);

            studentList.textContent = '';
            if (matches.length === 0) {
                studentList.appendChild(Object.assign(document.createElement('div'), {
                    className: 'cmcombo-empty',
                    textContent: 'No learner on the roll matches "' + raw + '". The name will be recorded as typed.',
                }));
            }
            matches.slice(0, 8).forEach((learner) => studentList.appendChild(learnerRow(learner)));

            studentList.classList.add('show');
            nameField.setAttribute('aria-expanded', 'true');
        };

        nameField.addEventListener('input', applyStudents);
        nameField.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') { closeStudents(); return; }
            if (event.key === 'Enter' && studentList.classList.contains('show')) {
                const rows = studentList.querySelectorAll('.cmcombo-row');
                if (rows.length === 1) { event.preventDefault(); rows[0].click(); }
            }
        });
        document.addEventListener('click', (event) => {
            if (!studentCombo.contains(event.target)) closeStudents();
        });
    }

    // A trigger may carry the learner on itself.
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-bmodal-open="consultModal"]');
        if (!trigger) return;

        const carriesLearner = trigger.dataset.consultName !== undefined
            || trigger.dataset.consultSection !== undefined;

        if (carriesLearner) {
            const name = trigger.dataset.consultName ?? '';
            const section = trigger.dataset.consultSection ?? '';

            if (trigger.dataset.consultName !== undefined && nameField) nameField.value = name;
            if (trigger.dataset.consultSection !== undefined && sectionField) sectionField.value = section;

            lockLearner(name, section);
        } else if (!window.__consultPrefilled) {
            // Opened cold — the Consultation Log's own button. One dialog
            // serves both, so a lock left over from a previous open has to
            // be cleared or the nurse cannot type a learner in at all.
            lockLearner('', '');
        }

        window.__consultPrefilled = false;
    }, true);
})();
</script>