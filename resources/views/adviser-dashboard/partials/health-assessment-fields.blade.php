{{--
    Shared Health Assessment (MLHAT) Sheet 1 & 2 field markup — included both
    from the combined Enroll Student form (fresh enrollment) and from the
    standalone Sheet 2 form (an already-enrolled student opened from their
    profile). Field names must match HealthAssessment::validationRules().
--}}

{{-- ── SHEET 1 ────────────────────────────────────────────── --}}
<div class="upload-subsection-title" style="margin-bottom:12px;">Sheet 1 &mdash; Learner Information, History &amp; Vital Signs</div>

{{-- A. Assessment Info --}}
<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px;">
    <div>
        <label class="ha-label">Date of Assessment</label>
        <input type="date" name="date_of_assessment" class="ha-input">
    </div>
    <div>
        <label class="ha-label">Assessed by (Name/Title)</label>
        <input type="text" name="assessed_by" class="ha-input" placeholder="e.g. Juan dela Cruz, RN">
    </div>
</div>

{{-- B. Medical History --}}
<div class="ha-section">
    <div class="ha-section-head">B. Medical History <span style="font-weight:400;font-size:.72rem;">(Check all that apply)</span></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 16px;">
        <label class="ha-check-label"><input type="checkbox" name="med_asthma" value="1" class="ha-check"> Asthma</label>
        <label class="ha-check-label"><input type="checkbox" name="med_allergies" value="1" class="ha-check" id="haAllergyCheck"> Allergies:</label>
        <label class="ha-check-label"><input type="checkbox" name="med_diabetes" value="1" class="ha-check"> Diabetes</label>
        <label class="ha-check-label"><input type="checkbox" name="med_heart_condition" value="1" class="ha-check"> Heart Condition</label>
        <label class="ha-check-label"><input type="checkbox" name="med_seizure_disorder" value="1" class="ha-check"> Seizure Disorder</label>
        <label class="ha-check-label"><input type="checkbox" name="med_tuberculosis" value="1" class="ha-check"> Tuberculosis</label>
        <label class="ha-check-label"><input type="checkbox" name="med_frequent_infections" value="1" class="ha-check"> Frequent Infections</label>
        <label class="ha-check-label"><input type="checkbox" name="med_hospitalization_surgery" value="1" class="ha-check" id="haHospCheck"> Hospitalization/Surgery:</label>
    </div>
    <div style="margin-top:8px;display:grid;grid-template-columns:1fr 1fr;gap:8px;">
        <input type="text" name="med_allergies_detail" id="haAllergyDetail" class="ha-input" placeholder="Specify allergies" style="display:none;">
        <input type="text" name="med_hospitalization_detail" id="haHospDetail" class="ha-input" placeholder="Specify details" style="display:none;">
    </div>
    <div style="margin-top:8px;display:grid;grid-template-columns:1fr 1fr;gap:8px;">
        <div>
            <label class="ha-label">Current Medications</label>
            <input type="text" name="med_current_medications" class="ha-input" placeholder="List current medications">
        </div>
        <div>
            <label class="ha-label">Other Conditions</label>
            <input type="text" name="med_other_conditions" class="ha-input" placeholder="Specify other conditions">
        </div>
    </div>
</div>

{{-- C. Family History --}}
<div class="ha-section">
    <div class="ha-section-head">C. Family History</div>
    <div style="display:flex;flex-wrap:wrap;gap:6px 14px;">
        <label class="ha-check-label"><input type="checkbox" name="fam_hypertension" value="1" class="ha-check"> Hypertension</label>
        <label class="ha-check-label"><input type="checkbox" name="fam_diabetes" value="1" class="ha-check"> Diabetes</label>
        <label class="ha-check-label"><input type="checkbox" name="fam_heart_disease" value="1" class="ha-check"> Heart Disease</label>
        <label class="ha-check-label"><input type="checkbox" name="fam_cancer" value="1" class="ha-check"> Cancer</label>
        <label class="ha-check-label"><input type="checkbox" name="fam_mental_health" value="1" class="ha-check"> Mental Health Conditions</label>
    </div>
    <div style="margin-top:8px;">
        <label class="ha-label">Genetic/Hereditary Disorders</label>
        <input type="text" name="fam_genetic_hereditary" class="ha-input" placeholder="Specify if any">
    </div>
</div>

{{-- D. General Appearance --}}
<div class="ha-section">
    <div class="ha-section-head">D. General Appearance</div>
    <div style="display:grid;gap:8px;">
        <div>
            <label class="ha-label">Level of Consciousness</label>
            <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:4px;">
                <label class="ha-check-label"><input type="radio" name="appearance_consciousness" value="Alert" class="ha-check"> Alert</label>
                <label class="ha-check-label"><input type="radio" name="appearance_consciousness" value="Drowsy" class="ha-check"> Drowsy</label>
                <label class="ha-check-label"><input type="radio" name="appearance_consciousness" value="Other" class="ha-check" id="haConsciousOtherRadio"> Other:</label>
                <input type="text" name="appearance_consciousness_other" id="haConsciousOtherText" class="ha-input" placeholder="Specify" style="display:none;width:140px;padding:4px 8px;">
            </div>
        </div>
        <div>
            <label class="ha-label">Posture/Gait</label>
            <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:4px;">
                <label class="ha-check-label"><input type="radio" name="appearance_posture_gait" value="Normal" class="ha-check"> Normal</label>
                <label class="ha-check-label"><input type="radio" name="appearance_posture_gait" value="Abnormal" class="ha-check" id="haPostureAbnormal"> Abnormal:</label>
                <input type="text" name="appearance_posture_detail" id="haPostureDetail" class="ha-input" placeholder="Describe" style="display:none;width:140px;padding:4px 8px;">
            </div>
        </div>
        <div>
            <label class="ha-label">Hygiene/Grooming</label>
            <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:4px;">
                <label class="ha-check-label"><input type="radio" name="appearance_hygiene" value="Adequate" class="ha-check"> Adequate</label>
                <label class="ha-check-label"><input type="radio" name="appearance_hygiene" value="Needs Attention" class="ha-check"> Needs Attention</label>
            </div>
        </div>
    </div>
</div>

{{-- E. Vital Signs --}}
<div class="ha-section">
    <div class="ha-section-head">E. Vital Signs</div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;">
        <div>
            <label class="ha-label">Height (cm)</label>
            <input type="number" name="vital_height_cm" id="haVitalHeight" class="ha-input" step="0.1" min="0" max="300" placeholder="e.g. 142.5">
        </div>
        <div>
            <label class="ha-label">Weight (kg)</label>
            <input type="number" name="vital_weight_kg" id="haVitalWeight" class="ha-input" step="0.01" min="0" max="300" placeholder="e.g. 38.5">
        </div>
        <div>
            <label class="ha-label">BMI <span style="font-weight:400;">(auto)</span></label>
            <input type="number" name="vital_bmi" id="haVitalBmi" class="ha-input" step="0.01" placeholder="Auto-calculated" readonly style="background:#f7faf8;">
        </div>
        <div>
            <label class="ha-label">Temperature (&deg;C)</label>
            <input type="number" name="vital_temperature_c" class="ha-input" step="0.1" min="30" max="45" placeholder="e.g. 36.5">
        </div>
        <div>
            <label class="ha-label">Pulse Rate (bpm)</label>
            <input type="number" name="vital_pulse_rate" class="ha-input" min="0" max="300" placeholder="e.g. 72">
        </div>
        <div>
            <label class="ha-label">Blood Pressure (mmHg)</label>
            <input type="text" name="vital_blood_pressure" class="ha-input" placeholder="e.g. 110/70">
        </div>
    </div>
</div>

{{-- ── SHEET 2 ────────────────────────────────────────────── --}}
<div class="upload-subsection-title" style="margin-top:18px;margin-bottom:12px;">Sheet 2 &mdash; Systems Review, Screenings &amp; Recommendations</div>

{{-- F. Body Systems --}}
<div class="ha-section">
    <div class="ha-section-head">F. Evaluation of Body Systems</div>
    <div style="overflow-x:auto;">
    <table class="ha-systems-table">
        <thead>
            <tr>
                <th style="width:22%;">Body System</th>
                <th>Findings <span style="font-weight:400;">(Check applicable)</span></th>
                <th style="width:28%;">Notes / Details</th>
            </tr>
        </thead>
        <tbody>
            @php
            $bodySystems = [
                ['key'=>'integumentary',  'label'=>'Integumentary',       'findings'=>['Normal','Lesions/Rashes','Pallor','Other']],
                ['key'=>'heent_head',     'label'=>'HEENT &ndash; Head/Scalp', 'findings'=>['Normal','Abnormal']],
                ['key'=>'heent_eyes',     'label'=>'HEENT &ndash; Eyes',   'findings'=>['Clear','Redness','Discharge']],
                ['key'=>'heent_ears',     'label'=>'HEENT &ndash; Ears',   'findings'=>['Clear','Pain','Discharge']],
                ['key'=>'heent_nose',     'label'=>'HEENT &ndash; Nose',   'findings'=>['Clear','Congested']],
                ['key'=>'heent_throat',   'label'=>'HEENT &ndash; Throat', 'findings'=>['Normal','Inflamed','Tonsillar Issues']],
                ['key'=>'respiratory',    'label'=>'Respiratory',           'findings'=>['Clear Breath Sounds','Cough','Wheezing']],
                ['key'=>'cardiovascular', 'label'=>'Cardiovascular',        'findings'=>['Regular Rhythm','Irregular','Murmur']],
                ['key'=>'gastrointestinal','label'=>'Gastrointestinal',     'findings'=>['Abdomen Soft','Pain','Nausea/Vomiting']],
                ['key'=>'genitourinary',  'label'=>'Genitourinary',         'findings'=>['No Complaints','Pain','Other']],
                ['key'=>'musculoskeletal','label'=>'Musculoskeletal',       'findings'=>['Normal ROM','Deformity','Pain']],
                ['key'=>'neurological',   'label'=>'Neurological',          'findings'=>['Oriented','Reflexes Normal','Abnormal']],
            ];
            @endphp
            @foreach($bodySystems as $sys)
            <tr>
                <td style="font-size:.78rem;font-weight:600;color:#1d3c31;">{!! $sys['label'] !!}</td>
                <td>
                    <div style="display:flex;flex-wrap:wrap;gap:4px 10px;">
                        @foreach($sys['findings'] as $finding)
                        <label style="display:flex;align-items:center;gap:4px;font-size:.76rem;cursor:pointer;white-space:nowrap;">
                            <input type="checkbox" name="body_systems[{{ $sys['key'] }}][findings][]" value="{{ $finding }}" style="accent-color:#15803d;width:13px;height:13px;">
                            {{ $finding }}
                        </label>
                        @endforeach
                    </div>
                </td>
                <td>
                    <input type="text" name="body_systems[{{ $sys['key'] }}][notes]" class="ha-input" style="padding:4px 8px;font-size:.75rem;" placeholder="Notes...">
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>
</div>

{{-- G. Vision and Hearing --}}
<div class="ha-section">
    <div class="ha-section-head">G. Vision and Hearing Screening</div>
    <div style="display:grid;gap:10px;">
        <div>
            <label class="ha-label">Vision</label>
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-top:4px;">
                <span style="font-size:.78rem;color:#334a3f;">Right Eye:</span>
                <input type="text" name="vision_right_eye" class="ha-input" style="width:70px;padding:4px 8px;" placeholder="20/___">
                <span style="font-size:.78rem;color:#334a3f;">Left Eye:</span>
                <input type="text" name="vision_left_eye" class="ha-input" style="width:70px;padding:4px 8px;" placeholder="20/___">
                <label class="ha-check-label"><input type="radio" name="vision_result" value="Pass" class="ha-check"> Pass</label>
                <label class="ha-check-label"><input type="radio" name="vision_result" value="Refer" class="ha-check"> Refer</label>
            </div>
        </div>
        <div>
            <label class="ha-label">Hearing</label>
            <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:4px;">
                <label class="ha-check-label"><input type="radio" name="hearing_result" value="Passed Both" class="ha-check"> Passed Both</label>
                <label class="ha-check-label"><input type="radio" name="hearing_result" value="Failed Right" class="ha-check"> Failed Right</label>
                <label class="ha-check-label"><input type="radio" name="hearing_result" value="Failed Left" class="ha-check"> Failed Left</label>
                <label class="ha-check-label"><input type="radio" name="hearing_result" value="Refer" class="ha-check"> Refer</label>
            </div>
        </div>
    </div>
</div>

{{-- H. Oral Health --}}
<div class="ha-section">
    <div class="ha-section-head">H. Oral Health Examination</div>
    <div>
        <label class="ha-label">Teeth Condition <span style="font-weight:400;">(Check all that apply)</span></label>
        <div style="display:flex;flex-wrap:wrap;gap:6px 14px;margin-top:6px;">
            <label class="ha-check-label"><input type="checkbox" name="teeth_condition[]" value="Good" class="ha-check"> Good</label>
            <label class="ha-check-label"><input type="checkbox" name="teeth_condition[]" value="Fair" class="ha-check"> Fair</label>
            <label class="ha-check-label"><input type="checkbox" name="teeth_condition[]" value="Poor" class="ha-check"> Poor</label>
            <label class="ha-check-label"><input type="checkbox" name="teeth_condition[]" value="Dental Caries" class="ha-check"> Dental Caries</label>
            <label class="ha-check-label"><input type="checkbox" name="teeth_condition[]" value="Gum Inflammation" class="ha-check"> Gum Inflammation</label>
            <label class="ha-check-label"><input type="checkbox" name="teeth_condition[]" value="Missing/Broken Teeth" class="ha-check"> Missing/Broken Teeth</label>
        </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr auto;gap:10px;align-items:end;margin-top:10px;">
        <div>
            <label class="ha-label">Last Dental Visit</label>
            <input type="text" name="last_dental_visit" class="ha-input" placeholder="e.g. January 2026">
        </div>
        <label class="ha-check-label" style="margin-bottom:8px;white-space:nowrap;">
            <input type="checkbox" name="dental_referral" value="1" class="ha-check"> Referral to Dentist Recommended
        </label>
    </div>
</div>

{{-- I. Immunization Status --}}
<div class="ha-section">
    <div class="ha-section-head">I. Immunization Status</div>
    <div style="display:grid;gap:8px;">
        <div>
            <label class="ha-label">Status</label>
            <div style="display:flex;gap:14px;margin-top:4px;">
                <label class="ha-check-label"><input type="radio" name="immunization_status" value="Complete" class="ha-check"> Complete</label>
                <label class="ha-check-label"><input type="radio" name="immunization_status" value="Incomplete" class="ha-check"> Incomplete</label>
                <label class="ha-check-label"><input type="radio" name="immunization_status" value="Not Available" class="ha-check"> Not Available</label>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
            <div>
                <label class="ha-label">Missing/Needed Vaccines</label>
                <input type="text" name="missing_needed_vaccines" class="ha-input" placeholder="e.g. MMR, Hepatitis B">
            </div>
            <div>
                <label class="ha-label">Date Record Reviewed</label>
                <input type="date" name="immunization_date_reviewed" class="ha-input">
            </div>
        </div>
    </div>
</div>

{{-- J. Assessment Summary --}}
<div class="ha-section">
    <div class="ha-section-head">J. Assessment Summary &amp; Recommendations</div>
    <div style="display:grid;gap:8px;">
        <div>
            <label class="ha-label">Summary of Findings</label>
            <textarea name="summary_of_findings" class="ha-input" rows="3" style="resize:vertical;" placeholder="Summarize key findings from the assessment..."></textarea>
        </div>
        <div>
            <label class="ha-label">Recommendations / Referrals</label>
            <textarea name="recommendations" class="ha-input" rows="3" style="resize:vertical;" placeholder="Specify recommendations or referrals..."></textarea>
        </div>
        <div>
            <label class="ha-label">Examiner Signature / Name</label>
            <input type="text" name="examiner_signature" class="ha-input" placeholder="Full name of examiner">
        </div>
    </div>
</div>
