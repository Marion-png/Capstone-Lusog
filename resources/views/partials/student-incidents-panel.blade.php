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

    $incidentCanFile = StudentIncidentReport::canFile(session('active_role'));
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
            Chart an incident involving this learner in <b>FDAR</b> — Focus, Data,
            Action, Response. The learner's class adviser can read what you file.
        @else
            Incidents involving this learner, charted by the school nurse in
            <b>FDAR</b> — Focus, Data, Action, Response. Read-only here: filing
            and withdrawing a report is the nurse's.
        @endif
    </div>

    @if ($incidentCanFile)
        <button type="button" class="btn" id="incidentNewBtn" style="margin:14px 0 4px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            File an Incident Report
        </button>

        {{-- The form is closed until asked for: a profile is opened to read far
             more often than to file, and an open form implies there is something
             to report. --}}
        <form id="incidentForm" class="incident-form" hidden
              @if (! empty($lrn))
                  data-store="{{ route('student-incidents.store', $lrn) }}"
                  data-index="{{ route('student-incidents.index', $lrn) }}"
              @endif>
            @csrf

            {{-- The record's own fields: when, how serious, where. They are not
                 part of FDAR and are kept out of its four sections so the chart
                 reads as a chart, but they are what makes the report findable
                 and followable afterwards. --}}
            <div class="incident-form-grid">
                <div class="field">
                    <label for="incidentDate">Date of incident</label>
                    {{-- An incident is something that already happened; the server
                         refuses a future date and the picker will not offer one. --}}
                    <input type="date" id="incidentDate" name="occurred_at" max="{{ now()->toDateString() }}" required>
                </div>

                <div class="field">
                    <label for="incidentSeverity">Severity</label>
                    <select id="incidentSeverity" name="severity" required>
                        @foreach (StudentIncidentReport::SEVERITIES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field full">
                    <label for="incidentLocation">Where it happened</label>
                    <input type="text" id="incidentLocation" name="location" maxlength="255" placeholder="e.g. Covered court" autocomplete="off">
                </div>
            </div>

            {{-- ── FDAR ──────────────────────────────────────────────────────
                 Four sections in the order they are charted, each named by its
                 letter so the form reads as the format a nurse already knows.
                 Focus stays the fixed catalogue: the list is filtered by it,
                 and a report filed under a focus nobody recognises is a report
                 nobody finds. --}}
            <div class="fdar">
                <div class="fdar-row">
                    <span class="fdar-letter" aria-hidden="true">F</span>
                    <div class="fdar-body">
                        <label for="incidentCategory">Focus</label>
                        <p class="fdar-hint">The concern being charted.</p>
                        <select id="incidentCategory" name="category" required>
                            @foreach (StudentIncidentReport::CATEGORIES as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="fdar-row">
                    <span class="fdar-letter" aria-hidden="true">D</span>
                    <div class="fdar-body">
                        <label for="incidentDescription">Data</label>
                        <p class="fdar-hint">What was observed and what the learner reported.</p>
                        <textarea id="incidentDescription" name="description" rows="3" maxlength="2000" required
                                  placeholder="e.g. Abrasion 2cm on the left knee after a fall during PE. Alert, ambulatory, reports pain 3/10."></textarea>
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

            <div class="incident-form-grid">
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

            <div class="incident-form-error" id="incidentError" hidden></div>

            <div class="incident-form-foot">
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

    <div class="sp-subhead">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/><path d="M12 7v5l4 2"/></svg>
        Incident History
    </div>
    <div id="vpIncidentsList"></div>
</section>
