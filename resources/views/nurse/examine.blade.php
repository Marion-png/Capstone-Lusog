<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Systems Review, Screenings, and Recommendations - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --g950: #0A3D22; --g900: #126B3A; --g800: #14653C;
            --g700: #1F8A4C; --g600: #1F8A4C; --g500: #43A866;
            --g300: #BFE3CC; --g200: #C4E4D0; --g100: #E7F5EC; --g50: #F2FAF5;
            --bg: #F6F9F7; --card: #ffffff; --border: #DCE8E0;
            --text-1: #1F2D25; --text-2: #3E5348; --text-3: #6B7C72;
            --red: #D95C5C; --amber: #F2B84B;
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

        /* Sheet 2, section F: the body systems as the clinic's sheet rules
           them — one row per system, a finding and a note on each. */
        .sheet2-table { width: 100%; border-collapse: collapse; font-size: .84rem; }
        .sheet2-table th, .sheet2-table td { border: 1px solid var(--border); padding: 6px 8px; text-align: left; vertical-align: middle; }
        .sheet2-table thead th { background: var(--g50); font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-3); }
        .sheet2-table tbody th { font-weight: 600; color: var(--text-1); width: 22%; white-space: nowrap; }
        .sheet2-table td:nth-child(2) { width: 22%; }
        .sheet2-table select, .sheet2-table input {
            width: 100%; height: 36px; border: 1.5px solid var(--border); border-radius: 8px;
            padding: 0 10px; font: inherit; font-size: .82rem; color: var(--text-1); background: #fff; outline: none;
        }
        .sheet2-table select { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%237a9e87' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 10px center; padding-right: 30px; }
        .sheet2-table select:focus, .sheet2-table input:focus { border-color: var(--g300); box-shadow: 0 0 0 3px rgba(134,239,172,.25); }
        .field textarea {
            border: 1.5px solid var(--border); border-radius: var(--radius-sm); padding: 9px 12px;
            font: inherit; font-size: .84rem; color: var(--text-1); background: #fff; outline: none; resize: vertical;
        }
        .field textarea:focus { border-color: var(--g300); box-shadow: 0 0 0 3px rgba(134,239,172,.25); }
        .sheet2-note { margin-top: 12px; padding: 9px 12px; border-radius: 8px; background: var(--g50); border: 1px solid var(--g200); font-size: .78rem; color: var(--text-2); }
        .sheet2-signature { display: flex; align-items: baseline; gap: 10px; margin-top: 14px; font-size: .84rem; color: var(--text-2); }
        .sheet2-signature b { color: var(--text-1); border-bottom: 1px solid var(--text-3); padding: 0 12px 2px; }
        @media (max-width: 700px) { .sheet2-table tbody th { white-space: normal; } }

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
    {{-- One shared palette for pages not yet on lusog-theme.css. Loaded
         last so it overrides this page's own :root colours. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-palette.css')) !!}</style>
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
        <h1>Systems Review, <span>Screenings, and Recommendations</span></h1>
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
            <div class="card-title">Systems Review, Screenings, and Recommendations</div>
            <div class="card-sub">Complete the examination fields below and save to finalize the record.</div>
        </div>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('nurse.examine.save', $index) }}">
            @csrf

            {{-- Vital signs are recorded on the learner's profile (the Vital
                 Signs panel), and height, weight and nutritional status are the
                 class adviser's measurements — this form no longer repeats
                 either. The date is kept: it is the record's own, and the
                 monthly examination count is keyed on it. --}}
            @php
                // Sheet 2, as filled — or, until the nurse has filled it, as
                // derived from the adviser's checklist and the older fields, so
                // the form opens on the record's answers (App\Support\Sheet2Review).
                $sheet2 = \App\Support\Sheet2Review::read($exam, is_array($record['systems_review'] ?? null) ? $record['systems_review'] : []);
                $s2 = fn (string $section, string $key): string => (string) ($sheet2[$section][$key] ?? '');
            @endphp

            <div class="form-grid">
                <div class="field">
                    <label>Date of Examination</label>
                    <input type="date" name="date_of_examination" value="{{ $exam['date_of_examination'] ?? '' }}" max="{{ now()->toDateString() }}">
                </div>
            </div>

            @if (($sheet2['source'] ?? 'none') === 'adviser')
                <div class="sheet2-note">Filled in from the class adviser's Sheet 2 and the record on file. Review each item and change what the examination found.</div>
            @endif

            {{-- ── F. Evaluation of body systems ── --}}
            <div class="section-divider" style="margin-top:24px;">F. Evaluation of Body Systems</div>
            <table class="sheet2-table">
                <thead>
                    <tr>
                        <th>Body System</th>
                        <th>Findings (Check / Status)</th>
                        <th>Notes / Details</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (\App\Support\Sheet2Review::SYSTEMS as $key => $label)
                        @php $row = $sheet2['systems'][$key] ?? ['finding' => '', 'notes' => '']; @endphp
                        <tr>
                            <th scope="row">{{ $label }}</th>
                            <td>
                                <select name="systems[{{ $key }}][finding]" aria-label="{{ $label }} finding">
                                    <option value="">— Select —</option>
                                    @foreach (\App\Support\Sheet2Review::FINDINGS as $finding)
                                        <option value="{{ $finding }}" @selected($row['finding'] === $finding)>{{ $finding }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <input type="text" name="systems[{{ $key }}][notes]" value="{{ $row['notes'] }}" maxlength="500" placeholder="Details, if any" aria-label="{{ $label }} notes">
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            {{-- ── G. Vision and hearing screening ── --}}
            <div class="section-divider" style="margin-top:24px;">G. Vision and Hearing Screening</div>
            <div class="form-grid-4">
                <div class="field">
                    <label>Vision — Right Eye</label>
                    <input type="text" name="vision_right" value="{{ $s2('vision', 'right') }}" placeholder="e.g. 20/20">
                </div>
                <div class="field">
                    <label>Vision — Left Eye</label>
                    <input type="text" name="vision_left" value="{{ $s2('vision', 'left') }}" placeholder="e.g. 20/20">
                </div>
                <div class="field">
                    <label>Vision — Result</label>
                    <input type="text" name="vision_result" value="{{ $s2('vision', 'result') }}" placeholder="e.g. Pass">
                </div>
                <div class="field">
                    <label>Hearing — Result</label>
                    <input type="text" name="hearing_result" value="{{ $s2('hearing', 'result') }}" placeholder="e.g. Passed Both">
                </div>
            </div>

            {{-- ── H. Oral health examination ── --}}
            <div class="section-divider" style="margin-top:24px;">H. Oral Health Examination</div>
            <div class="form-grid">
                <div class="field">
                    <label>Teeth Condition</label>
                    <select name="teeth_condition">
                        <option value="">— Select —</option>
                        @foreach (\App\Support\Sheet2Review::TEETH as $teeth)
                            <option value="{{ $teeth }}" @selected($s2('oral', 'teeth') === $teeth)>{{ $teeth }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label>Last Dental Visit</label>
                    <input type="text" name="last_dental_visit" value="{{ $s2('oral', 'last_visit') }}" placeholder="e.g. 2026-03 or N/A">
                </div>
                <div class="field">
                    <label>Referral</label>
                    <input type="text" name="dental_referral" value="{{ $s2('oral', 'referral') }}" placeholder="e.g. No referral required">
                </div>
            </div>

            {{-- ── I. Immunization status ── --}}
            <div class="section-divider" style="margin-top:24px;">I. Immunization Status</div>
            <div class="form-grid">
                <div class="field">
                    <label>Status</label>
                    <select name="immunization_status">
                        <option value="">— Select —</option>
                        @foreach (\App\Support\Sheet2Review::IMMUNIZATION as $status)
                            <option value="{{ $status }}" @selected($s2('immunization', 'status') === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label>Missing / Needed Vaccines</label>
                    <input type="text" name="missing_vaccines" value="{{ $s2('immunization', 'missing') }}" placeholder="e.g. None">
                </div>
                <div class="field">
                    <label>Date Record Reviewed</label>
                    <input type="date" name="immunization_reviewed_at" value="{{ $s2('immunization', 'reviewed_at') }}" max="{{ now()->toDateString() }}">
                </div>
            </div>

            {{-- ── J. Assessment summary and recommendations ── --}}
            <div class="section-divider" style="margin-top:24px;">J. Assessment Summary and Recommendations</div>
            <div class="form-grid-2">
                <div class="field">
                    <label>Summary of Findings</label>
                    <textarea name="summary_findings" rows="3" maxlength="2000" placeholder="What the examination found">{{ $s2('summary', 'findings') }}</textarea>
                </div>
                <div class="field">
                    <label>Recommendations / Referrals</label>
                    <textarea name="recommendations" rows="3" maxlength="2000" placeholder="What should follow">{{ $s2('summary', 'recommendations') }}</textarea>
                </div>
            </div>
            {{-- The examiner and the date are the app's: whoever is signed in,
                 on the date of examination above. --}}
            <div class="sheet2-signature">
                <span>Examiner Signature / Name:</span>
                <b>{{ session('active_name', 'School Nurse') }}</b>
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
</body>
</html>
