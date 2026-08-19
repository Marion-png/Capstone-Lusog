{{--
    Incident Report panel for the class adviser's student profile.

    The one tab on this page the adviser writes: they are the person in the
    room when something happens. Everything else here — clinic notes,
    consultations, Sheet 2 — is another desk's record and is read-only.

    Expects $lrn. Rendered inside the profile's tab body.
--}}
<section class="student-profile-section">
    <div class="sp-panel-head">
        <h4>Incident Reports</h4>
        <span class="sp-panel-count" id="vpIncidentsCount">0 reports</span>
    </div>

    <div class="sp-note">
        Record something that happened to this learner in school — an injury, a
        sudden illness, a behavioural incident. Reports stay on the learner's
        profile and are visible to you as their class adviser.
    </div>

    <button type="button" class="btn" id="incidentNewBtn" style="margin:14px 0 4px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        File an Incident Report
    </button>

    {{-- The form is closed until asked for: a profile is opened to read far
         more often than to file, and an open form implies there is something
         to report. --}}
    <form id="incidentForm" class="incident-form" hidden
          data-store="{{ route('student-incidents.store', $lrn) }}"
          data-index="{{ route('student-incidents.index', $lrn) }}">
        @csrf

        <div class="incident-form-grid">
            <div class="field">
                <label for="incidentDate">Date of incident</label>
                {{-- An incident is something that already happened; the server
                     refuses a future date and the picker will not offer one. --}}
                <input type="date" id="incidentDate" name="occurred_at" max="{{ now()->toDateString() }}" required>
            </div>

            <div class="field">
                <label for="incidentCategory">Type</label>
                <select id="incidentCategory" name="category" required>
                    @foreach (\App\Models\StudentIncidentReport::CATEGORIES as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="incidentSeverity">Severity</label>
                <select id="incidentSeverity" name="severity" required>
                    @foreach (\App\Models\StudentIncidentReport::SEVERITIES as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="incidentLocation">Where it happened</label>
                <input type="text" id="incidentLocation" name="location" maxlength="255" placeholder="e.g. Covered court" autocomplete="off">
            </div>

            <div class="field full">
                <label for="incidentDescription">What happened</label>
                <textarea id="incidentDescription" name="description" rows="3" maxlength="2000" required placeholder="Describe the incident as you observed it."></textarea>
            </div>

            <div class="field full">
                <label for="incidentAction">Action taken</label>
                <textarea id="incidentAction" name="action_taken" rows="2" maxlength="2000" placeholder="e.g. Sent to the clinic; guardian called."></textarea>
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

        <div class="incident-form-error" id="incidentError" hidden></div>

        <div class="incident-form-foot">
            <button type="button" class="btn btn-secondary" id="incidentCancel">Cancel</button>
            <button type="submit" class="btn" id="incidentSubmit">Save Report</button>
        </div>
    </form>

    <div class="sp-subhead">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/><path d="M12 7v5l4 2"/></svg>
        Incident History
    </div>
    <div id="vpIncidentsList"></div>
</section>
