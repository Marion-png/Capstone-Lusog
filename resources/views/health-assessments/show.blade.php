<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>MLHAT - {{ $record?->student_name }} - SIGLA</title>
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
    $isAdviser = session('active_role') === 'class_adviser';
    $backRoute = $isAdviser ? route('health-assessments.index') : route('health-assessments.nurse-index');
    $bs = $a->body_systems ?? [];

    $medChecked = collect(HealthAssessment::MEDICAL_HISTORY_FLAGS)->filter(fn ($label, $field) => $a->$field);
    $famChecked = collect(HealthAssessment::FAMILY_HISTORY_FLAGS)->filter(fn ($label, $field) => $a->$field);
@endphp

<header class="cf-topbar">
    <img src="{{ asset('images/lusog-logo.png') }}" alt="SIGLA">
    <div>
        <div class="cf-topbar-title">MLHAT &mdash; {{ $record?->student_name ?? 'Unknown learner' }}</div>
        <div class="cf-topbar-sub">Read-only &middot; SY {{ $a->school_year }}</div>
    </div>
    <a href="{{ $backRoute }}" class="cf-back">&larr; All Assessments</a>
</header>

<div class="cf-wrap">
    <div class="cf-card">
        <div class="cf-card-head">
            <h2>Assessment</h2>
            <span class="cf-badge" style="background:#E7F5EC;color:#14653C;">Submitted</span>
        </div>
        <div class="cf-card-body">
            <dl class="ha-kv">
                <dt>Name of Learner</dt><dd>{{ $record?->student_name ?? '—' }}</dd>
                <dt>Learner ID / Grade</dt><dd>{{ $record?->student_id }} &middot; {{ $record?->section }}</dd>
                <dt>Date of Assessment</dt><dd>{{ optional($a->date_of_assessment)->format('F j, Y') ?? '—' }}</dd>
                <dt>Assessed by</dt><dd>{{ $a->assessed_by ?: '—' }}</dd>
                <dt>Submitted by</dt><dd>{{ $a->submitted_by_name }} &middot; {{ $a->created_at?->format('M j, Y g:i A') }}</dd>
            </dl>
            <div class="cf-actions no-print" style="margin-top:12px;">
                <button type="button" class="cf-btn cf-btn-ghost" onclick="window.print()">Print / Export PDF</button>
                @if ($isAdviser && $record)
                    <a href="{{ route('health-assessments.form', $record->student_id) }}" class="cf-btn cf-btn-outline">Edit / Resubmit</a>
                @endif
            </div>
        </div>
    </div>

    <div class="cf-card">
        <div class="cf-card-head"><h2>B. Medical History</h2></div>
        <div class="cf-card-body">
            @forelse ($medChecked as $field => $label)
                <span class="ha-chip">{{ $label }}</span>
            @empty
                <span class="ha-chip ha-chip-muted">None reported</span>
            @endforelse
            <dl class="ha-kv" style="margin-top:12px;">
                @if ($a->med_allergies_detail) <dt>Allergies (detail)</dt><dd>{{ $a->med_allergies_detail }}</dd> @endif
                @if ($a->med_hospitalization_detail) <dt>Hospitalization/Surgery</dt><dd>{{ $a->med_hospitalization_detail }}</dd> @endif
                @if ($a->med_current_medications) <dt>Current Medications</dt><dd>{{ $a->med_current_medications }}</dd> @endif
                @if ($a->med_other_conditions) <dt>Other Conditions</dt><dd>{{ $a->med_other_conditions }}</dd> @endif
            </dl>
        </div>
    </div>

    <div class="cf-card">
        <div class="cf-card-head"><h2>C. Family History</h2></div>
        <div class="cf-card-body">
            @forelse ($famChecked as $field => $label)
                <span class="ha-chip">{{ $label }}</span>
            @empty
                <span class="ha-chip ha-chip-muted">None reported</span>
            @endforelse
            @if ($a->fam_genetic_hereditary)
                <dl class="ha-kv" style="margin-top:12px;"><dt>Genetic/Hereditary Disorders</dt><dd>{{ $a->fam_genetic_hereditary }}</dd></dl>
            @endif
        </div>
    </div>

    <div class="cf-card">
        <div class="cf-card-head"><h2>D. General Appearance &middot; E. Vital Signs</h2></div>
        <div class="cf-card-body">
            <dl class="ha-kv">
                <dt>Level of Consciousness</dt><dd>{{ $a->appearance_consciousness ?: '—' }}{{ $a->appearance_consciousness_other ? ' — ' . $a->appearance_consciousness_other : '' }}</dd>
                <dt>Posture / Gait</dt><dd>{{ $a->appearance_posture_gait ?: '—' }}{{ $a->appearance_posture_detail ? ' — ' . $a->appearance_posture_detail : '' }}</dd>
                <dt>Hygiene / Grooming</dt><dd>{{ $a->appearance_hygiene ?: '—' }}</dd>
                <dt>Height / Weight / BMI</dt><dd>{{ $a->vital_height_cm ?? '—' }} cm &middot; {{ $a->vital_weight_kg ?? '—' }} kg &middot; BMI {{ $a->vital_bmi ?? '—' }}</dd>
                <dt>Temp / Pulse / BP</dt><dd>{{ $a->vital_temperature_c ?? '—' }} &deg;C &middot; {{ $a->vital_pulse_rate ?? '—' }} bpm &middot; {{ $a->vital_blood_pressure ?: '—' }}</dd>
            </dl>
        </div>
    </div>

    <div class="cf-card">
        <div class="cf-card-head"><h2>F. Evaluation of Body Systems</h2></div>
        <div class="cf-card-body" style="overflow-x:auto;">
            <table class="ha-systems-table">
                <thead><tr><th style="width:22%;">Body System</th><th>Findings</th><th style="width:30%;">Notes / Details</th></tr></thead>
                <tbody>
                    @foreach (HealthAssessment::BODY_SYSTEMS as $key => $sys)
                        @php
                            $findings = $bs[$key]['findings'] ?? [];
                            $notes = $bs[$key]['notes'] ?? '';
                        @endphp
                        <tr>
                            <td class="ha-sys-name">{{ $sys['label'] }}</td>
                            <td>
                                @forelse ($findings as $f)
                                    <span class="ha-chip">{{ $f }}</span>
                                @empty
                                    <span class="ha-chip ha-chip-muted">Not assessed</span>
                                @endforelse
                            </td>
                            <td>{{ $notes ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="cf-card">
        <div class="cf-card-head"><h2>G. Vision and Hearing &middot; H. Oral Health &middot; I. Immunization</h2></div>
        <div class="cf-card-body">
            <dl class="ha-kv">
                <dt>Vision</dt><dd>Right: {{ $a->vision_right_eye ?: '—' }} &middot; Left: {{ $a->vision_left_eye ?: '—' }} &middot; {{ $a->vision_result ?: '—' }}</dd>
                <dt>Hearing</dt><dd>{{ $a->hearing_result ?: '—' }}</dd>
                <dt>Teeth Condition</dt>
                <dd>
                    @forelse ($a->teeth_condition ?? [] as $t)
                        <span class="ha-chip">{{ $t }}</span>
                    @empty
                        —
                    @endforelse
                </dd>
                <dt>Last Dental Visit</dt><dd>{{ $a->last_dental_visit ?: '—' }}</dd>
                <dt>Dental Referral</dt><dd>{{ $a->dental_referral ? 'Referral to Dentist Recommended' : 'No' }}</dd>
                <dt>Immunization Status</dt><dd>{{ $a->immunization_status ?: '—' }}</dd>
                <dt>Missing/Needed Vaccines</dt><dd>{{ $a->missing_needed_vaccines ?: '—' }}</dd>
                <dt>Date Record Reviewed</dt><dd>{{ optional($a->immunization_date_reviewed)->format('F j, Y') ?? '—' }}</dd>
            </dl>
        </div>
    </div>

    <div class="cf-card">
        <div class="cf-card-head"><h2>J. Assessment Summary and Recommendations</h2></div>
        <div class="cf-card-body">
            <dl class="ha-kv">
                <dt>Summary of Findings</dt><dd>{{ $a->summary_of_findings ?: '—' }}</dd>
                <dt>Recommendations / Referrals</dt><dd>{{ $a->recommendations ?: '—' }}</dd>
                <dt>Examiner Signature</dt><dd>{{ $a->examiner_signature ?: '—' }}</dd>
                <dt>Date</dt><dd>{{ optional($a->date_of_assessment)->format('F j, Y') ?? '—' }}</dd>
            </dl>
        </div>
    </div>
</div>
</body>
</html>