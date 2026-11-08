{{--
    Incident Report panel, charted in FDAR, shared by the nurse's and the
    adviser's student profiles.

    **The nurse writes; the adviser reads.** FDAR — Focus / Data / Action /
    Response — is clinical documentation: assessing a learner and recording
    what was done and whether it worked is the nurse's assessment to make. The
    adviser sees the same reports on their own profile because an incident
    involving a learner in their class is something they have to know about,
    and the panel simply renders without its write controls for them.

    Who may write is read from the model, never decided here — a view that
    judges for itself eventually draws a form the endpoint then refuses. The
    server says so again on every list (`can_file`), so a page rendered for one
    role cannot be reused by another.

    $lrn is optional. The adviser's profile is a page and knows the learner at
    render time; the nurse's is a dialog filled in by JS, so the script builds
    its URLs from templates instead — see partials/student-incidents-script.
--}}
@php
    use App\Models\StudentIncidentReport;
    use App\Support\ClinicalProofreader;
    use App\Support\IncidentPhysicalExam;

    $incidentCanFile = StudentIncidentReport::canFile(session('active_role'));
    $incidentCanEdit = StudentIncidentReport::canEdit(session('active_role'));
    $incidentCanWithdraw = StudentIncidentReport::canWithdraw(session('active_role'));
    $studentIncidentsCss = resource_path('css/student-incidents.css');
@endphp
{{-- The panel carries its own sheet, like the documents one: the two profiles
     that render it load different stylesheets, and the rules used to live in
     class-adviser.css where the nurse's page could not reach them. --}}
@if (file_exists($studentIncidentsCss))
    <style>{!! file_get_contents($studentIncidentsCss) !!}</style>
@endif
<section class="student-profile-section">
    <div class="sp-panel-head">
        <h4>Incident Reports</h4>
        <span class="sp-panel-count" id="vpIncidentsCount">0 reports</span>
    </div>

    <div class="sp-note">
        @if ($incidentCanFile)
            Chart an incident involving this learner in <b>F-DAR</b> — Date/Time,
            Focus, then the Progress Notes as Data, Action, Response. The
            learner's class adviser can read what you file.
            @unless ($incidentCanWithdraw)
                Withdrawing a report is the school nurse's.
            @endunless
        @else
            Incidents involving this learner, charted by the clinic in
            <b>F-DAR</b> — Date/Time, Focus, and the Progress Notes as Data,
            Action, Response. Read-only here: filing a report is the school
            nurse's or the clinic teacher's, and withdrawing one the nurse's.
        @endif
    </div>

    @if ($incidentCanFile)
        <div class="incident-actions" id="incidentActions">
            <button type="button" class="btn" id="incidentNewBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                File an Incident Report
            </button>
            {{-- The school's Incident Report form with nothing charted on it,
                 for an incident written up by hand first. --}}
            <button type="button" class="btn btn-secondary" id="incidentBlankBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9V3h12v6"/><rect x="6" y="14" width="12" height="7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/></svg>
                Print Blank Form
            </button>
        </div>

        {{-- The form is closed until asked for: a profile is opened to read far
             more often than to file, and an open form implies there is something
             to report. --}}
        <form id="incidentForm" class="incident-form" hidden
              @if (! empty($lrn))
                  data-store="{{ route('student-incidents.store', $lrn) }}"
                  data-index="{{ route('student-incidents.index', $lrn) }}"
              @endif>
            @csrf

            @php $incidentChartColumns = StudentIncidentReport::supportsChartColumns(); @endphp

            {{-- Filing and editing are one form; only an edit carries a title. --}}
            <h5 class="incident-form-title" id="incidentFormTitle" hidden>Edit Incident Report</h5>

            {{-- ── F-DAR ─────────────────────────────────────────────────────
                 The form is laid out as the sheet it files: Date/Time, then
                 the Focus, then the Progress Notes in D / A / R order, each
                 named by its letter so it reads as the format a nurse already
                 knows. The record's own fields — severity, where, witnesses —
                 come after, because they are not part of the chart. --}}
            <div class="fdar">
                {{-- Column one of the sheet: the date AND the time. An incident
                     is something that already happened; the server refuses a
                     future date or a later time today, and the picker will not
                     offer a future date. --}}
                <div class="fdar-row">
                    <span class="fdar-letter fdar-letter-plain" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    </span>
                    <div class="fdar-body">
                        <label for="incidentDate">Date and time</label>
                        <p class="fdar-hint">When it happened.</p>
                        <div class="fdar-when">
                            <input type="date" id="incidentDate" name="occurred_at" max="{{ now((string) config('app.school_timezone'))->toDateString() }}" required aria-label="Date of incident">
                            @if ($incidentChartColumns)
                                <input type="time" id="incidentTime" name="occurred_time" aria-label="Time of incident">
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Focus: what the note is about — a sign or symptom, a
                     behaviour, a treatment event; not a medical diagnosis. The
                     kind of incident stays the fixed catalogue, because the
                     list is filtered by it and a report filed under a kind
                     nobody recognises is a report nobody finds; the statement
                     is the nurse's own words and is what the sheet prints. --}}
                <div class="fdar-row">
                    <span class="fdar-letter" aria-hidden="true">F</span>
                    <div class="fdar-body">
                        <label for="incidentCategory">Focus</label>
                        <p class="fdar-hint">The concern being charted — a sign or symptom, a behaviour or a treatment event, not a medical diagnosis.</p>
                        <div class="fdar-focus">
                            <select id="incidentCategory" name="category" required aria-label="Kind of incident">
                                @foreach (StudentIncidentReport::CATEGORIES as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @if ($incidentChartColumns)
                                <input type="text" id="incidentFocus" name="focus" maxlength="255" autocomplete="off"
                                       placeholder="e.g. Abrasion, left knee" aria-label="Focus statement">
                            @endif
                        </div>
                    </div>
                </div>

                <div class="fdar-subhead">Progress Notes</div>

                <div class="fdar-row">
                    <span class="fdar-letter" aria-hidden="true">D</span>
                    <div class="fdar-body">
                        <label for="incidentDescription">Data</label>
                        <p class="fdar-hint">What was observed and what the learner reported.</p>
                        <textarea id="incidentDescription" name="description" rows="3" maxlength="2000" required
                                  placeholder="e.g. Fell during PE and hit the left knee on the floor. Reports pain on the knee."></textarea>

                        {{-- The objective half of the Data: the physical
                             examination taken at the time, one field per
                             reading so every report charts them the same way
                             and the printed form has a place for each
                             (App\Support\IncidentPhysicalExam). Every field is
                             optional. Drawn only where the column exists. --}}
                        @if (StudentIncidentReport::supportsPhysicalExam())
                            <fieldset class="fdar-exam" id="incidentExam">
                                <legend>Physical Examination</legend>

                                <div class="fdar-vitals">
                                    @foreach (IncidentPhysicalExam::VITALS as $examKey => $vital)
                                        @php
                                            $range = match ($examKey) {
                                                'temperature_c' => ['25', '45', '0.1', '36.5'],
                                                'pulse_bpm' => ['20', '250', '1', '80'],
                                                'respiratory_rate' => ['4', '80', '1', '18'],
                                                default => ['50', '100', '1', '98'],
                                            };
                                        @endphp
                                        <label class="fdar-vital" for="incidentExam_{{ $examKey }}">
                                            <span class="fdar-vital-label">{{ $vital['label'] }}</span>
                                            <span class="fdar-vital-field">
                                                @if ($examKey === 'blood_pressure')
                                                    <input type="text" id="incidentExam_{{ $examKey }}" name="exam[{{ $examKey }}]" maxlength="7" inputmode="numeric" autocomplete="off" placeholder="110/70">
                                                @else
                                                    <input type="number" id="incidentExam_{{ $examKey }}" name="exam[{{ $examKey }}]" inputmode="decimal"
                                                           min="{{ $range[0] }}" max="{{ $range[1] }}" step="{{ $range[2] }}" placeholder="{{ $range[3] }}">
                                                @endif
                                                <em>{{ $vital['unit'] }}</em>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>

                                <div class="fdar-exam-grid">
                                    <div class="fdar-exam-field">
                                        <label for="incidentExam_consciousness">Level of consciousness</label>
                                        <select id="incidentExam_consciousness" name="exam[consciousness]">
                                            <option value="">—</option>
                                            @foreach (IncidentPhysicalExam::CONSCIOUSNESS as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="fdar-exam-field">
                                        <label for="incidentExam_pain_score">Pain score</label>
                                        <select id="incidentExam_pain_score" name="exam[pain_score]">
                                            <option value="">—</option>
                                            @for ($pain = 0; $pain <= IncidentPhysicalExam::PAIN_MAX; $pain++)
                                                <option value="{{ $pain }}">{{ $pain }} / {{ IncidentPhysicalExam::PAIN_MAX }}{{ $pain === 0 ? ' · No pain' : ($pain === IncidentPhysicalExam::PAIN_MAX ? ' · Worst pain' : '') }}</option>
                                            @endfor
                                        </select>
                                    </div>
                                    <div class="fdar-exam-field is-full">
                                        <label for="incidentExam_body_site">Body part affected</label>
                                        <input type="text" id="incidentExam_body_site" name="exam[body_site]" maxlength="{{ IncidentPhysicalExam::BODY_SITE_MAX }}" list="incidentBodySites" autocomplete="off" placeholder="e.g. Left knee">
                                        <datalist id="incidentBodySites">
                                            @foreach (IncidentPhysicalExam::BODY_SITES as $site)
                                                <option value="{{ $site }}"></option>
                                            @endforeach
                                        </datalist>
                                    </div>
                                    <div class="fdar-exam-field is-full">
                                        <label for="incidentExam_findings">Findings</label>
                                        <textarea id="incidentExam_findings" name="exam[findings]" rows="2" maxlength="{{ IncidentPhysicalExam::FINDINGS_MAX }}"
                                                  placeholder="e.g. 2 cm abrasion, minimal bleeding, no swelling, full range of motion."></textarea>
                                    </div>
                                </div>
                            </fieldset>
                        @endif
                    </div>
                </div>

                <div class="fdar-row">
                    <span class="fdar-letter" aria-hidden="true">A</span>
                    <div class="fdar-body">
                        <label for="incidentAction">Action</label>
                        <p class="fdar-hint">What was done about it.</p>
                        <textarea id="incidentAction" name="action_taken" rows="2" maxlength="2000"
                                  placeholder="e.g. Wound cleaned with sterile saline and dressed. Rested at the clinic for 15 minutes."></textarea>
                    </div>
                </div>

                {{-- Drawn only where the column exists: an un-migrated machine
                     charts F, D and A rather than offering a field the save
                     would fail on. --}}
                @if (StudentIncidentReport::supportsResponse())
                    <div class="fdar-row">
                        <span class="fdar-letter" aria-hidden="true">R</span>
                        <div class="fdar-body">
                            <label for="incidentResponse">Response</label>
                            <p class="fdar-hint">How the learner responded. Leave this until there is an outcome to record.</p>
                            <textarea id="incidentResponse" name="response" rows="2" maxlength="2000"
                                      placeholder="e.g. Pain settled, no further bleeding. Returned to class at 10:20."></textarea>
                        </div>
                    </div>
                @endif
            </div>

            {{-- The record's own fields: how serious, where, who else was
                 there. Not part of F-DAR, so kept out of the chart above, but
                 they are what makes the report findable and followable. --}}
            <div class="incident-form-grid">
                <div class="field">
                    <label for="incidentSeverity">Severity</label>
                    <select id="incidentSeverity" name="severity" required>
                        @foreach (StudentIncidentReport::SEVERITIES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="incidentLocation">Where it happened</label>
                    <input type="text" id="incidentLocation" name="location" maxlength="255" placeholder="e.g. Covered court" autocomplete="off">
                </div>

                <div class="field full">
                    <label for="incidentWitnesses">Witnesses</label>
                    <input type="text" id="incidentWitnesses" name="witnesses" maxlength="500" placeholder="Names of anyone else present" autocomplete="off">
                </div>

                <div class="field full">
                    <label class="incident-check">
                        <input type="checkbox" id="incidentNotified" name="guardian_notified" value="1">
                        <span>Parent or guardian has been informed</span>
                    </label>
                </div>
            </div>

            {{-- The AI check's suggestions, each beside what was written, to
                 accept or dismiss (App\Support\ClinicalProofreader). Nothing
                 here saves: an accepted suggestion only changes the field. --}}
            <div class="incident-proof" id="incidentProof" hidden aria-live="polite"></div>

            <div class="incident-form-error" id="incidentError" hidden></div>

            <div class="incident-form-foot">
                {{-- Drawn only where a Gemini key is set; the endpoint refuses
                     regardless. --}}
                @if (ClinicalProofreader::isConfigured())
                    <button type="button" class="btn btn-secondary incident-ai" id="incidentProofBtn" title="AI correction of grammar and terms">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/></svg>
                        <span class="incident-ai-label">AI Check: Grammar &amp; Terms</span>
                    </button>
                @endif
                <button type="button" class="btn btn-secondary" id="incidentCancel">Cancel</button>
                <button type="submit" class="btn" id="incidentSubmit">Save Report</button>
            </div>
        </form>
    @else
        {{-- No form for a reader, and no hidden one either: a control that is
             not drawn cannot be re-enabled from the console into a request the
             endpoint would refuse anyway. The index route still has to reach
             the script, so it travels on the list instead. --}}
        <div id="incidentReadOnly" class="incident-readonly"
             @if (! empty($lrn)) data-index="{{ route('student-incidents.index', $lrn) }}" @endif></div>
    @endif

    {{-- Put away while the form is open — filing or editing — so the report
         being written is never read as one already in the history; back as
         soon as the form is cancelled or saved. --}}
    <div class="incident-history" id="incidentHistory">
        <div class="sp-subhead">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/><path d="M12 7v5l4 2"/></svg>
            Incident History
        </div>
        <div id="vpIncidentsList" data-can-edit="{{ $incidentCanEdit ? '1' : '0' }}" data-can-withdraw="{{ $incidentCanWithdraw ? '1' : '0' }}"></div>
    </div>
</section>
