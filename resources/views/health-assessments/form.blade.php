<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>MLHAT - {{ $record->student_name }} - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @php $cfCss = resource_path('css/consent-form.css'); $haCss = resource_path('css/mlhat.css'); @endphp
    @if (file_exists($cfCss)) <style>{!! file_get_contents($cfCss) !!}</style> @endif
    @if (file_exists($haCss)) <style>{!! file_get_contents($haCss) !!}</style> @endif
    {{-- One shared palette for pages not yet on lusog-theme.css. Loaded
         last so it overrides this page's own :root colours. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-palette.css')) !!}</style>
</head>
<body>
@php
    use App\Models\HealthAssessment;
    $a = $assessment;
    $bs = old('body_systems', $a?->body_systems ?? []);
    $teeth = old('teeth_condition', $a?->teeth_condition ?? []);
    $birth = collect([$student['birth_month'], $student['birth_day'], $student['birth_year']])->filter()->implode('/');
@endphp

<header class="cf-topbar">
    <img src="{{ asset('images/lusog-logo.png') }}" alt="SIGLA">
    <div>
        <div class="cf-topbar-title">MLHAT &mdash; {{ $record->student_name }}</div>
        <div class="cf-topbar-sub">Sheet 1 &amp; 2 &middot; SY {{ $schoolYear }} {{ $a ? '· editing resubmits the assessment' : '' }}</div>
    </div>
    <a href="{{ route('health-assessments.index') }}" class="cf-back">&larr; All Assessments</a>
</header>

<div class="cf-wrap">
    @if (session('health_assessment_success')) <div class="cf-flash cf-flash-ok">{{ session('health_assessment_success') }}</div> @endif
    @if ($errors->any()) <div class="cf-flash cf-flash-err">{{ $errors->first() }}</div> @endif

    <form method="POST" action="{{ route('health-assessment.store') }}">
        @csrf
        <input type="hidden" name="lrn" value="{{ $student['lrn'] }}">

        {{-- ── A. Learner Information (auto-filled) ─────────────────── --}}
        <div class="cf-card">
            <div class="cf-card-head"><h2>Sheet 1 &middot; A. Learner Information</h2><span class="cf-badge" style="background:#f1f5f9;color:#475569;">Auto-filled</span></div>
            <div class="cf-card-body">
                <dl class="ha-kv">
                    <dt>Name of Learner</dt><dd>{{ $record->student_name }}</dd>
                    <dt>Learner ID / Grade</dt><dd>{{ $student['lrn'] }} &middot; {{ $student['grade_level'] }} / {{ $student['section'] }}</dd>
                    <dt>Date of Birth / Age</dt><dd>{{ $birth ?: '—' }} @if($student['age']) &middot; {{ $student['age'] }} years old @endif</dd>
                    <dt>Sex</dt><dd>{{ $student['gender'] ?: '—' }}</dd>
                    <dt>School</dt><dd>{{ session('assigned_school_name') ?? session('active_school_name') ?? '—' }}</dd>
                </dl>
                <div class="ha-grid" style="margin-top:14px;">
                    <div>
                        <label class="ha-label">Date of Assessment</label>
                        <input type="date" name="date_of_assessment" class="ha-input" value="{{ old('date_of_assessment', $a?->date_of_assessment?->format('Y-m-d') ?? now()->format('Y-m-d')) }}">
                    </div>
                    <div>
                        <label class="ha-label">Assessed by (Name/Title)</label>
                        <input type="text" name="assessed_by" class="ha-input" placeholder="e.g. Juan dela Cruz, RN" value="{{ old('assessed_by', $a?->assessed_by ?? session('active_name')) }}">
                    </div>
                </div>
            </div>
        </div>

        {{-- ── B. Medical History ───────────────────────────────────── --}}
        <div class="cf-card">
            <div class="cf-card-head"><h2>B. Medical History</h2><span style="font-size:.72rem;color:var(--muted);">Check all that apply — coordinate with the parent/guardian</span></div>
            <div class="cf-card-body">
                <div class="ha-grid">
                    <label class="ha-check-label"><input type="checkbox" name="med_asthma" value="1" @checked(old('med_asthma', $a?->med_asthma))> Asthma</label>
                    <label class="ha-check-label"><input type="checkbox" name="med_allergies" value="1" @checked(old('med_allergies', $a?->med_allergies))> Allergies:
                        <input type="text" name="med_allergies_detail" class="ha-inline-detail" value="{{ old('med_allergies_detail', $a?->med_allergies_detail) }}">
                    </label>
                    <label class="ha-check-label"><input type="checkbox" name="med_diabetes" value="1" @checked(old('med_diabetes', $a?->med_diabetes))> Diabetes</label>
                    <label class="ha-check-label"><input type="checkbox" name="med_heart_condition" value="1" @checked(old('med_heart_condition', $a?->med_heart_condition))> Heart Condition</label>
                    <label class="ha-check-label"><input type="checkbox" name="med_seizure_disorder" value="1" @checked(old('med_seizure_disorder', $a?->med_seizure_disorder))> Seizure Disorder</label>
                    <label class="ha-check-label"><input type="checkbox" name="med_tuberculosis" value="1" @checked(old('med_tuberculosis', $a?->med_tuberculosis))> Tuberculosis</label>
                    <label class="ha-check-label"><input type="checkbox" name="med_frequent_infections" value="1" @checked(old('med_frequent_infections', $a?->med_frequent_infections))> Frequent Infections</label>
                    <label class="ha-check-label"><input type="checkbox" name="med_hospitalization_surgery" value="1" @checked(old('med_hospitalization_surgery', $a?->med_hospitalization_surgery))> Hospitalization/Surgery:
                        <input type="text" name="med_hospitalization_detail" class="ha-inline-detail" value="{{ old('med_hospitalization_detail', $a?->med_hospitalization_detail) }}">
                    </label>
                </div>
                <div class="ha-grid" style="margin-top:10px;">
                    <div>
                        <label class="ha-label">Current Medications</label>
                        <input type="text" name="med_current_medications" class="ha-input" value="{{ old('med_current_medications', $a?->med_current_medications) }}">
                    </div>
                    <div>
                        <label class="ha-label">Other Conditions</label>
                        <input type="text" name="med_other_conditions" class="ha-input" value="{{ old('med_other_conditions', $a?->med_other_conditions) }}">
                    </div>
                </div>
            </div>
        </div>

        {{-- ── C. Family History ────────────────────────────────────── --}}
        <div class="cf-card">
            <div class="cf-card-head"><h2>C. Family History</h2></div>
            <div class="cf-card-body">
                <div class="ha-grid-3">
                    @foreach (HealthAssessment::FAMILY_HISTORY_FLAGS as $field => $label)
                        <label class="ha-check-label"><input type="checkbox" name="{{ $field }}" value="1" @checked(old($field, $a?->$field))> {{ $label }}</label>
                    @endforeach
                </div>
                <div style="margin-top:10px;">
                    <label class="ha-label">Genetic/Hereditary Disorders</label>
                    <input type="text" name="fam_genetic_hereditary" class="ha-input" value="{{ old('fam_genetic_hereditary', $a?->fam_genetic_hereditary) }}">
                </div>
            </div>
        </div>

        {{-- ── D. General Appearance ────────────────────────────────── --}}
        <div class="cf-card">
            <div class="cf-card-head"><h2>D. General Appearance</h2></div>
            <div class="cf-card-body">
                <div class="ha-grid-3">
                    <div>
                        <label class="ha-label">Level of Consciousness</label>
                        @foreach (['Alert', 'Drowsy', 'Other'] as $opt)
                            <label class="ha-check-label"><input type="radio" name="appearance_consciousness" value="{{ $opt }}" @checked(old('appearance_consciousness', $a?->appearance_consciousness) === $opt)> {{ $opt }}</label>
                        @endforeach
                        <input type="text" name="appearance_consciousness_other" class="ha-input" placeholder="If other, specify" value="{{ old('appearance_consciousness_other', $a?->appearance_consciousness_other) }}">
                    </div>
                    <div>
                        <label class="ha-label">Posture / Gait</label>
                        @foreach (['Normal', 'Abnormal'] as $opt)
                            <label class="ha-check-label"><input type="radio" name="appearance_posture_gait" value="{{ $opt }}" @checked(old('appearance_posture_gait', $a?->appearance_posture_gait) === $opt)> {{ $opt }}</label>
                        @endforeach
                        <input type="text" name="appearance_posture_detail" class="ha-input" placeholder="If abnormal, specify" value="{{ old('appearance_posture_detail', $a?->appearance_posture_detail) }}">
                    </div>
                    <div>
                        <label class="ha-label">Hygiene / Grooming</label>
                        @foreach (['Adequate', 'Needs Attention'] as $opt)
                            <label class="ha-check-label"><input type="radio" name="appearance_hygiene" value="{{ $opt }}" @checked(old('appearance_hygiene', $a?->appearance_hygiene) === $opt)> {{ $opt }}</label>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- ── E. Vital Signs ───────────────────────────────────────── --}}
        <div class="cf-card">
            <div class="cf-card-head"><h2>E. Vital Signs</h2></div>
            <div class="cf-card-body">
                <div class="ha-grid-6">
                    <div><label class="ha-label">Height (cm)</label><input type="number" step="0.1" name="vital_height_cm" class="ha-input" value="{{ old('vital_height_cm', $a?->vital_height_cm) }}"></div>
                    <div><label class="ha-label">Weight (kg)</label><input type="number" step="0.1" name="vital_weight_kg" class="ha-input" value="{{ old('vital_weight_kg', $a?->vital_weight_kg) }}"></div>
                    <div><label class="ha-label">BMI</label><input type="number" step="0.1" name="vital_bmi" class="ha-input" value="{{ old('vital_bmi', $a?->vital_bmi) }}"></div>
                    <div><label class="ha-label">Temp (&deg;C)</label><input type="number" step="0.1" name="vital_temperature_c" class="ha-input" value="{{ old('vital_temperature_c', $a?->vital_temperature_c) }}"></div>
                    <div><label class="ha-label">Pulse (bpm)</label><input type="number" name="vital_pulse_rate" class="ha-input" value="{{ old('vital_pulse_rate', $a?->vital_pulse_rate) }}"></div>
                    <div><label class="ha-label">BP (mmHg)</label><input type="text" name="vital_blood_pressure" class="ha-input" placeholder="120/80" value="{{ old('vital_blood_pressure', $a?->vital_blood_pressure) }}"></div>
                </div>
            </div>
        </div>

        {{-- ── F. Evaluation of Body Systems ────────────────────────── --}}
        <div class="cf-card">
            <div class="cf-card-head"><h2>Sheet 2 &middot; F. Evaluation of Body Systems</h2></div>
            <div class="cf-card-body" style="overflow-x:auto;">
                <table class="ha-systems-table">
                    <thead><tr><th style="width:20%;">Body System</th><th>Findings (check applicable)</th><th style="width:28%;">Notes / Details</th></tr></thead>
                    <tbody>
                        @foreach (HealthAssessment::BODY_SYSTEMS as $key => $sys)
                            <tr>
                                <td class="ha-sys-name">{{ $sys['label'] }}</td>
                                <td>
                                    <div class="ha-findings">
                                        @foreach ($sys['findings'] as $finding)
                                            <label class="ha-check-label" style="white-space:nowrap;">
                                                <input type="checkbox" name="body_systems[{{ $key }}][findings][]" value="{{ $finding }}"
                                                    @checked(in_array($finding, $bs[$key]['findings'] ?? [], true))>
                                                {{ $finding }}
                                            </label>
                                        @endforeach
                                    </div>
                                </td>
                                <td><input type="text" name="body_systems[{{ $key }}][notes]" class="ha-input" style="padding:5px 8px;font-size:.76rem;" value="{{ $bs[$key]['notes'] ?? '' }}"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ── G. Vision and Hearing Screening ──────────────────────── --}}
        <div class="cf-card">
            <div class="cf-card-head"><h2>G. Vision and Hearing Screening</h2></div>
            <div class="cf-card-body">
                <div class="ha-grid">
                    <div>
                        <label class="ha-label">Vision</label>
                        <div class="ha-grid" style="margin-bottom:8px;">
                            <input type="text" name="vision_right_eye" class="ha-input" placeholder="Right Eye (e.g. 20/20)" value="{{ old('vision_right_eye', $a?->vision_right_eye) }}">
                            <input type="text" name="vision_left_eye" class="ha-input" placeholder="Left Eye" value="{{ old('vision_left_eye', $a?->vision_left_eye) }}">
                        </div>
                        @foreach (['Pass', 'Refer'] as $opt)
                            <label class="ha-check-label"><input type="radio" name="vision_result" value="{{ $opt }}" @checked(old('vision_result', $a?->vision_result) === $opt)> {{ $opt }}</label>
                        @endforeach
                    </div>
                    <div>
                        <label class="ha-label">Hearing</label>
                        @foreach (['Passed Both', 'Failed Right', 'Failed Left', 'Refer'] as $opt)
                            <label class="ha-check-label"><input type="radio" name="hearing_result" value="{{ $opt }}" @checked(old('hearing_result', $a?->hearing_result) === $opt)> {{ $opt }}</label>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- ── H. Oral Health Examination ───────────────────────────── --}}
        <div class="cf-card">
            <div class="cf-card-head"><h2>H. Oral Health Examination</h2></div>
            <div class="cf-card-body">
                <label class="ha-label">Teeth Condition</label>
                <div class="ha-grid-3" style="margin-bottom:12px;">
                    @foreach (HealthAssessment::TEETH_CONDITIONS as $opt)
                        <label class="ha-check-label"><input type="checkbox" name="teeth_condition[]" value="{{ $opt }}" @checked(in_array($opt, $teeth, true))> {{ $opt }}</label>
                    @endforeach
                </div>
                <div class="ha-grid">
                    <div>
                        <label class="ha-label">Last Dental Visit</label>
                        <input type="text" name="last_dental_visit" class="ha-input" value="{{ old('last_dental_visit', $a?->last_dental_visit) }}">
                    </div>
                    <div style="align-self:end;">
                        <label class="ha-check-label"><input type="checkbox" name="dental_referral" value="1" @checked(old('dental_referral', $a?->dental_referral))> Referral to Dentist Recommended</label>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── I. Immunization Status ───────────────────────────────── --}}
        <div class="cf-card">
            <div class="cf-card-head"><h2>I. Immunization Status</h2></div>
            <div class="cf-card-body">
                <div class="ha-grid-3">
                    <div>
                        <label class="ha-label">Status</label>
                        @foreach (['Complete', 'Incomplete', 'Not Available'] as $opt)
                            <label class="ha-check-label"><input type="radio" name="immunization_status" value="{{ $opt }}" @checked(old('immunization_status', $a?->immunization_status) === $opt)> {{ $opt }}</label>
                        @endforeach
                    </div>
                    <div>
                        <label class="ha-label">Missing/Needed Vaccines</label>
                        <input type="text" name="missing_needed_vaccines" class="ha-input" value="{{ old('missing_needed_vaccines', $a?->missing_needed_vaccines) }}">
                    </div>
                    <div>
                        <label class="ha-label">Date Record Reviewed</label>
                        <input type="date" name="immunization_date_reviewed" class="ha-input" value="{{ old('immunization_date_reviewed', $a?->immunization_date_reviewed?->format('Y-m-d')) }}">
                    </div>
                </div>
            </div>
        </div>

        {{-- ── J. Assessment Summary and Recommendations ────────────── --}}
        <div class="cf-card">
            <div class="cf-card-head"><h2>J. Assessment Summary and Recommendations</h2></div>
            <div class="cf-card-body">
                <div class="ha-grid">
                    <div>
                        <label class="ha-label">Summary of Findings</label>
                        <textarea name="summary_of_findings" class="ha-textarea">{{ old('summary_of_findings', $a?->summary_of_findings) }}</textarea>
                    </div>
                    <div>
                        <label class="ha-label">Recommendations / Referrals</label>
                        <textarea name="recommendations" class="ha-textarea">{{ old('recommendations', $a?->recommendations) }}</textarea>
                    </div>
                </div>
                <div class="ha-grid" style="margin-top:12px;">
                    <div>
                        <label class="ha-label">Examiner Signature (name)</label>
                        <input type="text" name="examiner_signature" class="ha-input" value="{{ old('examiner_signature', $a?->examiner_signature ?? session('active_name')) }}">
                    </div>
                    <div style="align-self:end; text-align:right;">
                        <button type="submit" class="cf-btn cf-btn-primary">{{ $a ? 'Resubmit Assessment' : 'Submit Assessment' }}</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
</body>
</html>