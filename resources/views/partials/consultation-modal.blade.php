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

    // Categories sort alphabetically, which would leave the catch-all
    // stranded between "Oral" and "Reproductive". Move it to the end: a
    // reader scans the real complaints first and falls through to "Others"
    // only when none of them fit.
    $catchAllCategory = $consultConditions->keys()
        ->first(fn ($category) => strcasecmp((string) $category, 'Other') === 0);

    if ($catchAllCategory !== null) {
        $catchAllGroup = $consultConditions->get($catchAllCategory);
        $consultConditions->forget($catchAllCategory);
        $consultConditions->put($catchAllCategory, $catchAllGroup);
    }
@endphp

@include('partials.board-modal-assets')

<div class="bmodal" id="consultModal" role="dialog" aria-modal="true" aria-labelledby="consultModalTitle"
     @if ($errors->consultation->any()) data-bmodal-autoopen @endif>
    <div class="bmodal-panel bmodal-panel-wide">
        <form method="POST" action="{{ route('consultations.store') }}">
            @csrf
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

                <div class="bmodal-field">
                    <label for="cm_student_name">Student name</label>
                    <input id="cm_student_name" type="text" name="student_name"
                           value="{{ old('student_name') }}" placeholder="e.g. Dela Cruz, Juan" required autocomplete="off">
                    @if ($errors->consultation->has('student_name'))
                        <div class="bmodal-error">{{ $errors->consultation->first('student_name') }}</div>
                    @endif
                </div>

                <div class="bmodal-field">
                    <label for="cm_grade_section">Grade and section</label>
                    <input id="cm_grade_section" type="text" name="grade_section"
                           value="{{ old('grade_section') }}" placeholder="e.g. Grade 10 - Rizal" required autocomplete="off">
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
                        <select id="cm_condition_id" name="condition_id" required data-catch-all="{{ $catchAllId }}">
                            <option value="" disabled @selected(! old('condition_id'))>Select a condition...</option>
                            @foreach ($consultConditions as $category => $group)
                                <optgroup label="{{ $category }}">
                                    @foreach ($group as $condition)
                                        <option value="{{ $condition->id }}" @selected((int) old('condition_id') === $condition->id)>{{ $condition->name }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>

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
</style>

<script>
// Open the dialog with a learner already filled in.
(() => {
    const nameField = document.getElementById('cm_student_name');
    const sectionField = document.getElementById('cm_grade_section');

    // Called by the profile's "New Consultation" button.
    window.openConsultationFor = (name, section) => {
        if (nameField) nameField.value = name || '';
        if (sectionField) sectionField.value = section || '';

        const trigger = document.querySelector('[data-bmodal-open="consultModal"]');
        if (trigger) {
            trigger.click();
            return;
        }

        // No trigger on this page — open it directly.
        const modal = document.getElementById('consultModal');
        if (modal) modal.classList.add('open');
    };

    // "Others" asks for the detail; every other condition does not.
    const conditionSelect = document.getElementById('cm_condition_id');
    const otherWrap = document.getElementById('cm_condition_other_wrap');
    const otherInput = document.getElementById('cm_condition_other');

    if (conditionSelect && otherWrap && otherInput) {
        const catchAll = String(conditionSelect.dataset.catchAll || '');

        const syncOther = () => {
            const isOther = catchAll !== '' && conditionSelect.value === catchAll;
            otherWrap.hidden = !isOther;
            // Required only while it is on screen, or the browser would
            // block submission on a hidden field it cannot focus.
            otherInput.required = isOther;
            if (!isOther) otherInput.value = '';
        };

        conditionSelect.addEventListener('change', syncOther);
        syncOther();
    }

    // A trigger may carry the learner on itself.
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-bmodal-open="consultModal"]');
        if (!trigger) return;

        if (trigger.dataset.consultName !== undefined && nameField) {
            nameField.value = trigger.dataset.consultName || '';
        }
        if (trigger.dataset.consultSection !== undefined && sectionField) {
            sectionField.value = trigger.dataset.consultSection || '';
        }
    }, true);
})();
</script>