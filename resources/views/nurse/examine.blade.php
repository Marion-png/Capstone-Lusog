<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Nurse Examination - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --g950: #052e16; --g900: #14532d; --g800: #166534;
            --g700: #15803d; --g600: #16a34a; --g500: #22c55e;
            --g300: #86efac; --g200: #bbf7d0; --g100: #dcfce7; --g50: #f0fdf4;
            --bg: #f7f8f5; --card: #ffffff; --border: #e4ece7;
            --text-1: #0d1f14; --text-2: #3d5c47; --text-3: #7a9e87;
            --red: #ef4444; --amber: #f59e0b;
            --shadow: 0 1px 4px rgba(5,46,22,.06), 0 4px 16px rgba(5,46,22,.06);
            --radius: 14px; --radius-sm: 10px;
        }
        html, body { min-height: 100%; font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text-1); }
        body { padding: 32px 28px 40px; }

        .page-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 24px; padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }
        .page-header h1 { font-family: 'DM Serif Display', serif; font-size: 1.7rem; color: var(--text-1); line-height: 1.15; }
        .page-header h1 span { font-style: italic; color: var(--g700); }
        .page-eyebrow { font-size: .68rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: var(--g600); margin-bottom: 6px; }

        .btn {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 16px; border-radius: var(--radius-sm);
            font-family: 'DM Sans', sans-serif; font-size: .82rem; font-weight: 600;
            cursor: pointer; border: none; transition: all .18s; text-decoration: none;
        }
        .btn-primary { background: var(--g700); color: #fff; box-shadow: 0 3px 14px rgba(22,101,52,.25); }
        .btn-primary:hover { background: var(--g800); transform: translateY(-1px); }
        .btn-ghost { background: #fff; color: var(--text-2); border: 1.5px solid var(--border); }
        .btn-ghost:hover { border-color: var(--g300); color: var(--g700); background: var(--g50); }

        .card {
            background: var(--card); border: 1px solid var(--border);
            border-radius: var(--radius); box-shadow: var(--shadow);
            margin-bottom: 16px; overflow: hidden;
        }
        .card-header {
            padding: 14px 20px; border-bottom: 1px solid var(--border);
            background: var(--bg); display: flex; align-items: center; gap: 10px;
        }
        .card-header-icon {
            width: 32px; height: 32px; border-radius: 8px;
            background: var(--g100); color: var(--g700);
            display: grid; place-items: center; flex-shrink: 0;
        }
        .card-header-icon svg { width: 16px; height: 16px; }
        .card-title { font-size: .82rem; font-weight: 700; color: var(--text-2); }
        .card-sub { font-size: .72rem; color: var(--text-3); margin-top: 1px; }
        .card-body { padding: 22px 24px; }

        .form-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
        .form-grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; }
        .form-grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
        .full { grid-column: 1 / -1; }

        .field { display: flex; flex-direction: column; gap: 8px; }
        .field label {
            font-size: .7rem; font-weight: 700; color: var(--text-3);
            text-transform: uppercase; letter-spacing: .06em;
        }
        .field input, .field select {
            height: 40px; border: 1.5px solid var(--border); border-radius: var(--radius-sm);
            padding: 0 12px; font: inherit; font-size: .84rem; color: var(--text-1);
            background: #fff; outline: none; transition: border-color .15s, box-shadow .15s;
        }
        .field input:focus, .field select:focus {
            border-color: var(--g300); box-shadow: 0 0 0 3px rgba(134,239,172,.25);
        }
        .field input[readonly] { background: var(--bg); color: var(--text-2); cursor: default; }
        .field select { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%237a9e87' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 36px; }

        .readonly-badge { display: inline-flex; align-items: center; gap: 4px; font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-3); background: var(--bg); border: 1px solid var(--border); border-radius: 999px; padding: 2px 8px; margin-left: auto; }

        .section-divider { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; color: var(--g600); margin: 20px 0 12px; padding-bottom: 6px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 8px; }
        .section-divider::before { content: ''; width: 3px; height: 14px; background: var(--g500); border-radius: 2px; }

        .form-actions { display: flex; align-items: center; gap: 10px; margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--border); }

        .student-hero {
            background: linear-gradient(135deg, var(--g900) 0%, #1f5c3e 100%);
            padding: 16px 20px; display: flex; align-items: center; justify-content: space-between;
        }
        .student-hero-name { font-family: 'DM Serif Display', serif; font-size: 1.35rem; color: #fff; line-height: 1.2; }
        .student-hero-lrn { font-size: .8rem; color: var(--g300); margin-top: 3px; }
        .student-hero-right { text-align: right; }
        .student-hero-grade { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--g300); }
        .student-hero-level { font-size: 1rem; font-weight: 700; color: #fff; margin-top: 2px; }

        .info-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

        @media (max-width: 900px) { .form-grid, .form-grid-4 { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 600px) { .form-grid, .form-grid-2, .form-grid-4 { grid-template-columns: 1fr; } body { padding: 16px; } }
    </style>
</head>
<body>
@php
    $exam = $record['examination'] ?? [];
    $middle = trim((string) ($record['middle_name'] ?? ''));
    $middleInitial = $middle !== '' ? (' ' . strtoupper(substr($middle, 0, 1)) . '.') : '';
    $studentName = trim(($record['last_name'] ?? '') . ', ' . ($record['first_name'] ?? '') . $middleInitial);
@endphp

<div class="page-header">
    <div>
        <div class="page-eyebrow">School Nurse &rsaquo; Health Records</div>
        <h1>Medical <span>Examination Form</span></h1>
    </div>
    <a href="{{ route('dashboard.student-health-records') }}" class="btn btn-ghost">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><path d="M15 18l-6-6 6-6"/></svg>
        Back to Records
    </a>
</div>

{{-- Student Basic Info (Read-only) --}}
<div class="card">
    <div class="student-hero">
        <div>
            <div class="student-hero-name">{{ $studentName }}</div>
            <div class="student-hero-lrn">LRN: {{ $record['lrn'] ?? '-' }}</div>
        </div>
        <div class="student-hero-right">
            <div class="student-hero-grade">Grade Level</div>
            <div class="student-hero-level">{{ $record['grade_level'] ?? '-' }}</div>
        </div>
    </div>
    <div class="card-body">
        <div class="info-row">
            <div class="field">
                <label>Parent / Guardian</label>
                <input type="text" value="{{ $record['parent_guardian'] ?? '' }}" readonly>
            </div>
            <div class="field">
                <label>Address</label>
                <input type="text" value="{{ $record['address'] ?? '' }}" readonly>
            </div>
        </div>
    </div>
</div>

{{-- Medical Findings Form --}}
<div class="card">
    <div class="card-header">
        <div class="card-header-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4"/><path d="M21 12c0 4.97-4.03 9-9 9S3 16.97 3 12 7.03 3 12 3s9 4.03 9 9z"/></svg>
        </div>
        <div>
            <div class="card-title">Medical Findings</div>
            <div class="card-sub">Complete the examination fields below and save to finalize the record.</div>
        </div>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('nurse.examine.save', $index) }}">
            @csrf

            <div class="section-divider">Vital Signs &amp; Physical Measurements</div>
            <div class="form-grid">
                <div class="field">
                    <label>Date of Examination</label>
                    <input type="date" name="date_of_examination" value="{{ $exam['date_of_examination'] ?? '' }}">
                </div>
                <div class="field">
                    <label>Temperature / Blood Pressure</label>
                    <input type="text" name="temperature_bp" value="{{ $exam['temperature_bp'] ?? '' }}" placeholder="e.g. 36.5°C / 120/80">
                </div>
                <div class="field">
                    <label>Heart Rate</label>
                    <input type="text" name="heart_rate" value="{{ $exam['heart_rate'] ?? '' }}" placeholder="e.g. 80 bpm">
                </div>
                <div class="field">
                    <label>Pulse Rate</label>
                    <input type="text" name="pulse_rate" value="{{ $exam['pulse_rate'] ?? '' }}" placeholder="e.g. 78 bpm">
                </div>
                <div class="field">
                    <label>Respiratory Rate</label>
                    <input type="text" name="respiratory_rate" value="{{ $exam['respiratory_rate'] ?? '' }}" placeholder="e.g. 18/min">
                </div>
            </div>

            <div class="section-divider" style="margin-top:24px;">Anthropometric Data <span class="readonly-badge">Auto-filled from Adviser</span></div>
            <div class="form-grid-4">
                <div class="field">
                    <label>Height (cm)</label>
                    <input type="text" id="examHeightCm" name="height_cm" value="{{ $exam['height_cm'] ?? ($record['height_cm'] ?? '') }}" readonly>
                </div>
                <div class="field">
                    <label>Weight (kg)</label>
                    <input type="text" id="examWeightKg" name="weight_kg" value="{{ $exam['weight_kg'] ?? ($record['weight_kg'] ?? '') }}" readonly>
                </div>
                <div class="field">
                    <label>Nutritional Status (BMI/Wt-for-Age)</label>
                    <input type="text" id="examNutritionalStatusBmi" name="nutritional_status_bmi" value="{{ $exam['nutritional_status_bmi'] ?? ($record['nutritional_status_bmi_for_age'] ?? '') }}" readonly>
                </div>
                <div class="field">
                    <label>Nutritional Status (Height-for-Age)</label>
                    <input type="text" id="examNutritionalStatusHeightAge" name="nutritional_status_height_age" value="{{ $exam['nutritional_status_height_age'] ?? ($record['nutritional_status_height_for_age'] ?? '') }}" readonly>
                </div>
            </div>

            <div class="section-divider" style="margin-top:24px;">Screening &amp; Physical Examination</div>
            <div class="form-grid">
                <div class="field">
                    <label>Vision Screening</label>
                    <input type="text" name="vision_screening" value="{{ $exam['vision_screening'] ?? '' }}" placeholder="e.g. 20/20 both eyes">
                </div>
                <div class="field">
                    <label>Auditory Screening</label>
                    <input type="text" name="auditory_screening" value="{{ $exam['auditory_screening'] ?? '' }}" placeholder="e.g. Normal">
                </div>
                <div class="field">
                    <label>Skin / Scalp</label>
                    <input type="text" name="skin_scalp" value="{{ $exam['skin_scalp'] ?? '' }}" placeholder="e.g. No lesions">
                </div>
                <div class="field">
                    <label>Eyes / Ears / Nose</label>
                    <input type="text" name="eyes_ears_nose" value="{{ $exam['eyes_ears_nose'] ?? '' }}" placeholder="e.g. Normal">
                </div>
                <div class="field">
                    <label>Mouth / Throat / Neck</label>
                    <input type="text" name="mouth_throat_neck" value="{{ $exam['mouth_throat_neck'] ?? '' }}" placeholder="e.g. No abnormalities">
                </div>
                <div class="field">
                    <label>Lungs / Heart</label>
                    <input type="text" name="lungs_heart" value="{{ $exam['lungs_heart'] ?? '' }}" placeholder="e.g. Clear">
                </div>
                <div class="field">
                    <label>Abdomen</label>
                    <input type="text" name="abdomen" value="{{ $exam['abdomen'] ?? '' }}" placeholder="e.g. Soft, non-tender">
                </div>
                <div class="field">
                    <label>Deformities</label>
                    <input type="text" name="deformities" value="{{ $exam['deformities'] ?? '' }}" placeholder="e.g. None">
                </div>
            </div>

            <div class="section-divider" style="margin-top:24px;">Supplementation &amp; Programs</div>

            @error('deworming')
                <div style="background:#fee2e2;border:1px solid #fca5a5;color:#b91c1c;border-radius:8px;padding:10px 14px;font-size:.8rem;font-weight:600;margin-bottom:12px;">
                    {{ $message }}
                </div>
            @enderror

            @if($consentForm === null)
                <div style="background:#fef3c7;border:1px solid #fcd34d;color:#92400e;border-radius:8px;padding:10px 14px;font-size:.78rem;font-weight:600;margin-bottom:12px;">
                    No signed parental consent on file for this student for SY {{ $consentSchoolYear }}.
                    Deworming cannot be marked as given until the Class Adviser records a consent form.
                </div>
            @elseif($consentForm->consent_type === 'refused')
                <div style="background:#f3f4f6;border:1px solid #d1d5db;color:#374151;border-radius:8px;padding:10px 14px;font-size:.78rem;font-weight:600;margin-bottom:12px;">
                    <span>Consent refused for SY {{ $consentSchoolYear }}@if($consentForm->refused_reason) &mdash; Reason: {{ $consentForm->refused_reason }}@endif.</span>
                    Deworming cannot be recorded.
                    @if($consentForm->file_path !== null)
                        <a href="{{ route('parental-consent.download', $consentForm->id) }}" target="_blank" rel="noopener noreferrer"
                           style="display:inline-flex;align-items:center;gap:4px;color:#374151;font-size:.74rem;font-weight:700;text-decoration:underline;margin-left:8px;">
                            View signed form
                        </a>
                    @endif
                </div>
            @elseif($consentForm->consent_type === 'partial')
                <div style="background:#fef3c7;border:1px solid #fcd34d;color:#92400e;border-radius:8px;padding:10px 14px;font-size:.78rem;font-weight:600;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                    <span>Partial consent on file for SY {{ $consentSchoolYear }}@if($consentForm->partial_exception) &mdash; Except: {{ $consentForm->partial_exception }}@endif. Verify that deworming is included before recording.</span>
                    @if($consentForm->file_path !== null)
                        <a href="{{ route('parental-consent.download', $consentForm->id) }}" target="_blank" rel="noopener noreferrer"
                           style="display:inline-flex;align-items:center;gap:5px;background:#92400e;color:#fff;border-radius:6px;padding:5px 11px;font-size:.74rem;font-weight:700;text-decoration:none;flex-shrink:0;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                            View Consent Form
                        </a>
                    @endif
                </div>
            @else
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;background:#dcfce7;border:1px solid #86efac;color:#15803d;border-radius:8px;padding:10px 14px;font-size:.78rem;font-weight:600;margin-bottom:12px;">
                    <span>Full parental consent on file for SY {{ $consentSchoolYear }}. Deworming may be recorded.</span>
                    @if($consentForm->file_path !== null)
                        <a href="{{ route('parental-consent.download', $consentForm->id) }}"
                           target="_blank"
                           rel="noopener noreferrer"
                           style="display:inline-flex;align-items:center;gap:5px;background:#15803d;color:#fff;border-radius:6px;padding:5px 11px;font-size:.74rem;font-weight:700;text-decoration:none;flex-shrink:0;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                            View Consent Form
                        </a>
                    @endif
                </div>
            @endif

            <div class="form-grid-4">
                <div class="field">
                    <label>Iron Supplementation</label>
                    <select name="iron_supplementation">
                        <option value="">— Select —</option>
                        <option value="V" @selected(($exam['iron_supplementation'] ?? '') === 'V')>V — Given</option>
                        <option value="X" @selected(($exam['iron_supplementation'] ?? '') === 'X')>X — Not Given</option>
                    </select>
                </div>
                <div class="field">
                    <label>Deworming</label>
                    <select name="deworming">
                        <option value="">— Select —</option>
                        <option value="V" @selected(($exam['deworming'] ?? '') === 'V')>V — Given</option>
                        <option value="X" @selected(($exam['deworming'] ?? '') === 'X')>X — Not Given</option>
                    </select>
                </div>
                <div class="field">
                    <label>SBFP Beneficiary</label>
                    <select name="sbfp_beneficiary">
                        <option value="">— Select —</option>
                        <option value="V" @selected(($exam['sbfp_beneficiary'] ?? '') === 'V')>V — Yes</option>
                        <option value="X" @selected(($exam['sbfp_beneficiary'] ?? '') === 'X')>X — No</option>
                    </select>
                </div>
                <div class="field">
                    <label>4Ps Beneficiary</label>
                    <select name="four_ps_beneficiary">
                        <option value="">— Select —</option>
                        <option value="V" @selected(($exam['four_ps_beneficiary'] ?? '') === 'V')>V — Yes</option>
                        <option value="X" @selected(($exam['four_ps_beneficiary'] ?? '') === 'X')>X — No</option>
                    </select>
                </div>
                <div class="field">
                    <label>Menarche (V — Started)</label>
                    <select name="menarche">
                        <option value="">— Select —</option>
                        <option value="V" @selected(($exam['menarche'] ?? '') === 'V')>V — Yes</option>
                        <option value="X" @selected(($exam['menarche'] ?? '') === 'X')>X — No</option>
                    </select>
                </div>
                <div class="field">
                    <label>Immunization (specify)</label>
                    <input type="text" name="immunization" value="{{ $exam['immunization'] ?? '' }}" placeholder="e.g. BCG, MMR">
                </div>
                <div class="field">
                    <label>Others (specify)</label>
                    <input type="text" name="others" value="{{ $exam['others'] ?? '' }}" placeholder="Any additional notes">
                </div>
                <div class="field">
                    <label>Examined By</label>
                    <input type="text" name="examined_by" value="{{ $exam['examined_by'] ?? '' }}" placeholder="Full name of examiner">
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Save Examination
                </button>
                <a href="{{ route('dashboard.student-health-records') }}" class="btn btn-ghost">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
(() => {
    const heightInput = document.getElementById('examHeightCm');
    const weightInput = document.getElementById('examWeightKg');
    const bmiStatusInput = document.getElementById('examNutritionalStatusBmi');
    const hfaStatusInput = document.getElementById('examNutritionalStatusHeightAge');

    if (!heightInput || !weightInput || !bmiStatusInput || !hfaStatusInput) {
        return;
    }

    const existingBmiStatus = bmiStatusInput.value.trim();
    const existingHfaStatus = hfaStatusInput.value.trim();

    const birthYear = Number(@json($record['birth_year'] ?? null));
    const birthMonth = Number(@json($record['birth_month'] ?? null));
    const birthDay = Number(@json($record['birth_day'] ?? null));

    const resolveAge = () => {
        if (!Number.isFinite(birthYear) || !Number.isFinite(birthMonth) || !Number.isFinite(birthDay)) {
            return null;
        }
        const dob = new Date(birthYear, birthMonth - 1, birthDay);
        if (Number.isNaN(dob.getTime())) return null;
        const today = new Date();
        let age = today.getFullYear() - dob.getFullYear();
        const monthDiff = today.getMonth() - dob.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) age -= 1;
        return age >= 0 ? age : null;
    };

    const classifyBmiForAge = (bmi, age) => {
        if (!Number.isFinite(bmi) || age === null) return 'Not enough data';
        if (bmi < 16.0) return 'Severely Wasted';
        if (bmi < 17.0) return 'Wasted';
        if (bmi < 18.5) return 'Underweight';
        if (bmi >= 25.0) return 'Overweight';
        return 'Normal';
    };

    const classifyHeightForAge = (heightCm, age) => {
        if (!Number.isFinite(heightCm) || age === null || heightCm <= 0) return 'Not enough data';
        const heightM = heightCm / 100;
        if (heightM < 1.20) return 'Severely Stunted';
        if (heightM < 1.30) return 'Stunted';
        if (heightM > 1.70) return 'Tall';
        return 'Normal Height-for-Age';
    };

    const updateStatuses = (force = false) => {
        const heightCm = Number(heightInput.value);
        const weightKg = Number(weightInput.value);
        const age = resolveAge();
        if (!Number.isFinite(heightCm) || !Number.isFinite(weightKg) || heightCm <= 0 || weightKg <= 0) return;
        const heightM = heightCm / 100;
        const bmi = weightKg / (heightM * heightM);
        if (force || bmiStatusInput.value.trim() === '' || bmiStatusInput.value.trim() === existingBmiStatus) {
            bmiStatusInput.value = classifyBmiForAge(bmi, age);
        }
        if (force || hfaStatusInput.value.trim() === '' || hfaStatusInput.value.trim() === existingHfaStatus) {
            hfaStatusInput.value = classifyHeightForAge(heightCm, age);
        }
    };

    heightInput.addEventListener('input', () => updateStatuses(true));
    weightInput.addEventListener('input', () => updateStatuses(true));
    updateStatuses(false);
})();
</script>
</body>
</html>
