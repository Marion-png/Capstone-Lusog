<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Consultation Log - LUSOG</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
        @php $pageCssPath = resource_path('css/school-nurse-consultation-log.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
</head>
<body>
<aside class="sidebar">
    <div class="sb-grid"></div>
    <div class="sb-logo">
        <img src="{{ asset('images/lusog-logo.png') }}" alt="LUSOG Logo" class="sb-logo-full">
    </div>
    <nav class="sb-nav">
        <div class="sb-section-label">Main</div>
        <a href="{{ route('dashboard.school-nurse') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Dashboard
        </a>
        <a href="{{ route('dashboard.student-health-records') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Health Records
            <span class="badge">3</span>
        </a>
        <a href="{{ route('dashboard.consultation-log') }}" class="sb-link active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4"/><path d="M21 12c0 4.97-4.03 9-9 9S3 16.97 3 12 7.03 3 12 3s9 4.03 9 9z"/></svg>
            Consultation Log
        </a>
        <div class="sb-section-label">Health Programs</div>
        <a href="{{ route('dashboard.school-nurse.feeding-program') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
            Feeding Program
        </a>
        <a href="{{ route('dashboard.school-nurse.deworming') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 3-7-3"/><path d="M3 9v6l7 3 7-3V9"/><polyline points="3 9 12 6 21 9"/></svg>
            Deworming Program
        </a>
        <div class="sb-section-label">Inventory</div>
        <a href="{{ route('dashboard.medicine-inventory') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="2" width="18" height="20" rx="2"/><path d="M9 2v4h6V2"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
            Medicine Inventory
        </a>
        <a href="#" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Dispensing Log
        </a>
        <div class="sb-section-label">Reports</div>
        <a href="{{ route('dashboard.data-visualization') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            Data Visualization
        </a>
        <a href="#" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Generate Reports
        </a>
    </nav>
    <div class="sb-user">
        <div class="sb-avatar">{{ substr(session('active_name', 'School Nurse'), 0, 2) }}</div>
        <div class="sb-user-meta">
            <div class="sb-user-name">{{ session('active_name', 'School Nurse') }}</div>
            <div class="sb-user-role">School Nurse - DCNHS</div>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="sb-logout" title="Sign out">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </button>
        </form>
    </div>
</aside>

<div class="main">
    <header class="topbar">
        <div class="topbar-breadcrumb">
            <a href="{{ route('dashboard.school-nurse') }}" class="bc-home">Dashboard</a>
            <span class="bc-sep">></span>
            <span class="bc-current">Consultation Log</span>
        </div>
        <div class="topbar-chip"><div class="dot"></div>DCNHS - SY 2025-2026</div>
    </header>

    <div class="content">
        @if (session('success'))
            <div class="flash-success">{{ session('success') }}</div>
        @endif
        @php
            $conditionColumns = [
                'inflamed eye/stye', 'eye irritation', 'conjunctivitis', 'ear problem',
                'nose bleeding', 'sinusistis/acute rhinitis', 'sore throat', 'tonsilitis',
                'inflamed gum', 'toothache', 'couch', 'fever', 'cold', 'headache',
                'hyperacidity', 'dysmenorrhea', 'diarrhea/lbm', 'abdominal pain',
                'nausea/vomitting', 'fainting', 'dizziness', 'lacerated wound',
                'punctured wound', 'incised wound', 'abrasion', 'contusion', 'ulcer (skin)',
                'burn', 'thea flava', 'ringworm', 'boil', 'skin allergy', 'others'
            ];

            $conditionLabels = [
                'inflamed eye/stye' => 'Inflamed Eye/Stye',
                'eye irritation' => 'Eye Irritation',
                'conjunctivitis' => 'Conjunctivitis',
                'ear problem' => 'Ear Problem',
                'nose bleeding' => 'Nose Bleeding',
                'sinusistis/acute rhinitis' => 'Sinusistis/Acute Rhinitis',
                'sore throat' => 'Sore Throat',
                'tonsilitis' => 'Tonsilitis',
                'inflamed gum' => 'Inflamed Gum',
                'toothache' => 'Toothache',
                'couch' => 'COuch',
                'fever' => 'Fever',
                'cold' => 'Cold',
                'headache' => 'Headache',
                'hyperacidity' => 'Hyperacidity',
                'dysmenorrhea' => 'Dysmenorrhea',
                'diarrhea/lbm' => 'Diarrhea/LBM',
                'abdominal pain' => 'Abdominal Pain',
                'nausea/vomitting' => 'Nausea/ Vomitting',
                'fainting' => 'Fainting',
                'dizziness' => 'Dizziness',
                'lacerated wound' => 'Lacerated Wound',
                'punctured wound' => 'Punctured Wound',
                'incised wound' => 'Incised Wound',
                'abrasion' => 'Abrasion',
                'contusion' => 'Contusion',
                'ulcer (skin)' => 'Ulcer (Skin)',
                'burn' => 'Burn',
                'thea flava' => 'Thea Flava',
                'ringworm' => 'Ringworm',
                'boil' => 'Boil',
                'skin allergy' => 'Skin Allergy',
                'others' => 'Others',
            ];

            $matchesCondition = static function (string $needle, string $value): bool {
                $normalizedNeedle = strtolower(trim($needle));
                $normalizedValue = strtolower(trim($value));

                if ($normalizedNeedle === '' || $normalizedValue === '') {
                    return false;
                }

                if (str_contains($normalizedValue, $normalizedNeedle)) {
                    return true;
                }

                if ($normalizedNeedle === 'others') {
                    $known = [
                        'inflamed eye', 'stye', 'eye irritation', 'conjunctivitis', 'ear problem',
                        'nose bleeding', 'sinusistis', 'acute rhinitis', 'sore throat', 'tonsilitis',
                        'inflamed gum', 'toothache', 'couch', 'fever', 'cold', 'headache',
                        'hyperacidity', 'dysmenorrhea', 'diarrhea', 'lbm', 'abdominal pain',
                        'nausea', 'vomitting', 'fainting', 'dizziness', 'lacerated wound',
                        'punctured wound', 'incised wound', 'abrasion', 'contusion', 'ulcer',
                        'burn', 'thea flava', 'ringworm', 'boil', 'skin allergy'
                    ];

                    foreach ($known as $item) {
                        if (str_contains($normalizedValue, $item)) {
                            return false;
                        }
                    }

                    return true;
                }

                return false;
            };
        @endphp

        <div class="page-header">
            <div>
                <div class="page-eyebrow">School Clinic Form</div>
                <h1 class="page-title">Record Of Daily <span>Treatment In School Clinic</span></h1>
                <p class="page-sub">Template-based consultation log view for School Nurse.</p>
            </div>
            <div class="page-header-actions">
                <a href="{{ route('consultations.create') }}" class="btn btn-primary">New Consultation</a>
            </div>
        </div>

        <section class="template-sheet">
            <div class="template-sheet-head">
                <div>
                    <div class="org">Davao City National High School</div>
                    <div class="title">Record of Daily Treatment in School Clinic</div>
                    <div class="subtitle">(Male)</div>
                </div>
                <div class="meta">Month: {{ now()->format('F') }} {{ now()->year }}</div>
            </div>

            <div class="template-table-wrap">
                <table class="template-table">
                    <thead>
                        <tr>
                            <th rowspan="2" class="w-date">Date</th>
                            <th rowspan="2" class="w-name">Name</th>
                            <th rowspan="2" class="w-grade">Grade</th>
                            <th rowspan="2" class="w-section">Section</th>
                            <th colspan="2" class="w-time">Time</th>
                            <th colspan="{{ count($conditionColumns) }}">Condition Requiring Treatment</th>
                            <th rowspan="2" class="w-treat">Treatment Given (Meds &amp; Mgnt)</th>
                            <th rowspan="2" class="w-remarks">Remarks (Vital Signs, Etc.)</th>
                            <th rowspan="2" class="w-sign">Signature of NoD</th>
                        </tr>
                        <tr>
                            <th class="w-time-col">In</th>
                            <th class="w-time-col">Out</th>
                            @foreach ($conditionColumns as $column)
                                <th class="v-col"><span>{{ $conditionLabels[$column] ?? ucfirst($column) }}</span></th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($consultations as $consultation)
                            @php
                                $date = optional($consultation->consulted_at);
                                $gradeSection = trim((string) $consultation->grade_section);
                                $grade = $gradeSection;
                                $section = '-';

                                if (preg_match('/^(.*?)\s*[\/-]\s*(.*?)$/', $gradeSection, $matches) === 1) {
                                    $grade = trim((string) ($matches[1] ?? $gradeSection));
                                    $section = trim((string) ($matches[2] ?? '-'));
                                }

                                $conditionValue = (string) ($consultation->condition ?? '');
                            @endphp
                            <tr>
                                <td>{{ $date?->format('m/d/Y') ?? '-' }}</td>
                                <td>{{ $consultation->student_name }}</td>
                                <td>{{ $grade !== '' ? $grade : '-' }}</td>
                                <td>{{ $section }}</td>
                                <td>{{ $date?->format('H:i') ?? '-' }}</td>
                                <td>{{ $date?->copy()->addMinutes(20)->format('H:i') ?? '-' }}</td>
                                @foreach ($conditionColumns as $column)
                                    <td class="mark">{{ $matchesCondition($column, $conditionValue) ? '/' : '' }}</td>
                                @endforeach
                                <td>{{ $consultation->treatment_given ?: '-' }}</td>
                                <td>{{ $consultation->status === 'referred' ? 'Referred' : 'Treated' }}</td>
                                <td>-</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ 10 + count($conditionColumns) }}" class="empty-cell">No consultation entries yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
</body>
</html>
