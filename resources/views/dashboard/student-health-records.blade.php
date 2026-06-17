<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Health Records - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
        @php $pageCssPath = resource_path('css/school-nurse-student-health-records.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
</head>
<body>
<aside class="sidebar">
    <div class="sb-logo"><img src="{{ asset('images/lusog-logo.png') }}" alt="SIGLA Logo"></div>
    <nav class="sb-nav">
        <div class="sb-section-label">Main</div>
        <a href="{{ route('dashboard.school-nurse') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Dashboard
        </a>
        <a href="{{ route('dashboard.student-health-records') }}" class="sb-link active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Health Records
            <span class="badge" style="margin-left:auto;background:var(--red);color:#fff;font-size:.62rem;font-weight:700;padding:2px 6px;border-radius:999px;">3</span>
        </a>
        <a href="{{ route('dashboard.consultation-log') }}" class="sb-link">
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

@php
    $records = session('school_health_card_records', []);
    $pendingCount = collect($records)->filter(fn($row) => empty($row['examination']))->count();
    $doneCount = collect($records)->filter(fn($row) => !empty($row['examination']))->count();
@endphp

<div class="main">
    <header class="topbar">
        <div class="topbar-bc"><span>Dashboard</span><span>&rsaquo;</span><span>Student Health Records</span></div>
        <div class="topbar-chip">Consultation records are now in Consultation Log</div>
    </header>

    <div class="content">
        <div class="page-eyebrow">School Health Card Workflow</div>
        <h1 class="page-title">Class Adviser <span>Submitted Forms</span></h1>
        <p class="page-sub">This page displays submitted adviser forms. Consultation records are handled in Consultation Log.</p>

        <div class="cards">
            <div class="mini-card"><div class="val">{{ count($records) }}</div><div class="lbl">Total Submissions</div></div>
            <div class="mini-card"><div class="val">{{ $pendingCount }}</div><div class="lbl">Pending Nurse Examination</div></div>
            <div class="mini-card"><div class="val">{{ $doneCount }}</div><div class="lbl">Examined by Nurse</div></div>
        </div>

        <div class="record-grid">
            @forelse ($records as $index => $record)
                @php
                    $middle = trim((string) ($record['middle_name'] ?? ''));
                    $middleInitial = $middle !== '' ? (' ' . strtoupper(substr($middle, 0, 1)) . '.') : '';
                    $fullName = trim(($record['last_name'] ?? '') . ', ' . ($record['first_name'] ?? '') . $middleInitial);
                    $examined = !empty($record['examination']);
                    $statusLabel = $examined ? 'Examined' : 'Pending';
                @endphp
                <article class="record-card {{ $examined ? 'done' : 'pending' }} js-record-card" data-index="{{ $index }}" data-route="{{ route('nurse.examine', $index) }}" data-record='@json($record)'>
                    <div class="record-top">
                        <div class="record-name">{{ $fullName }}</div>
                        <div class="record-status {{ $examined ? 'done' : 'pending' }}">{{ $statusLabel }}</div>
                    </div>
                    <div class="record-sub">LRN: {{ $record['lrn'] ?? '-' }}</div>
                    <div class="record-sub">{{ $record['grade_level'] ?? '-' }}</div>
                    <div class="record-chips">
                        <span class="chip">Ht: {{ $record['height_cm'] ?? '-' }} cm</span>
                        <span class="chip">Wt: {{ $record['weight_kg'] ?? '-' }} kg</span>
                    </div>
                </article>
            @empty
                <div class="table-card" style="padding:14px;color:var(--text-3);">No adviser submissions yet.</div>
            @endforelse
        </div>
    </div>
</div>

<div class="profile-backdrop" id="profileBackdrop" aria-hidden="true">
    <div class="profile-modal" role="dialog" aria-modal="true" aria-label="Student Health Record Preview">
        <div class="profile-head">
            <div>
                <div class="profile-title" id="pName">-</div>
                <div class="profile-meta" id="pLrn">LRN: -</div>
            </div>
            <div class="profile-right">Grade &amp; Section<b id="pGrade">-</b></div>
        </div>
        <div class="profile-tabs">
            <button type="button" class="profile-tab active" data-panel="p-demographics">Demographics</button>
            <button type="button" class="profile-tab" data-panel="p-shd">SHD Form 2</button>
            <button type="button" class="profile-tab" data-panel="p-growth">Growth &amp; Nutrition</button>
            <button type="button" class="profile-tab" data-panel="p-alerts">Medical Alerts</button>
            <button type="button" class="profile-tab" data-panel="p-timeline">Health Timeline</button>
            @if(session('active_role') === 'clinic_staff')
            <button type="button" class="profile-tab" data-panel="p-conditions">Health Conditions</button>
            @endif
        </div>
        <div class="profile-body">
            <section id="p-demographics" class="profile-panel active">
                <div class="profile-grid">
                    <div class="profile-block">
                        <h4>Personal Information</h4>
                        <div class="kv"><div class="k">Full Name:</div><div class="v" id="pdName">-</div></div>
                        <div class="kv"><div class="k">LRN:</div><div class="v" id="pdLrn">-</div></div>
                        <div class="kv"><div class="k">Date of Birth:</div><div class="v" id="pdDob">-</div></div>
                        <div class="kv"><div class="k">Birthplace:</div><div class="v" id="pdBirthplace">-</div></div>
                        <div class="kv"><div class="k">Address:</div><div class="v" id="pdAddress">-</div></div>
                    </div>
                    <div class="profile-block">
                        <h4>Parent/Guardian Information</h4>
                        <div class="kv"><div class="k">Parent/Guardian:</div><div class="v" id="pdGuardian">-</div></div>
                        <div class="kv"><div class="k">Contact Number:</div><div class="v" id="pdContact">-</div></div>
                        <div class="kv"><div class="k">School ID:</div><div class="v" id="pdSchoolId">-</div></div>
                        <div class="kv"><div class="k">Region/Division:</div><div class="v" id="pdRegionDivision">-</div></div>
                    </div>
                </div>
            </section>
            <section id="p-shd" class="profile-panel">
                <div class="profile-block">
                    <h4>SHD Form 2 Snapshot</h4>
                    <div class="kv"><div class="k">Grade Level:</div><div class="v" id="psGrade">-</div></div>
                    <div class="kv"><div class="k">Status:</div><div class="v" id="psStatus">-</div></div>
                </div>
            </section>
            <section id="p-growth" class="profile-panel">
                <div class="profile-block">
                    <h4>Growth &amp; Nutrition</h4>
                    <div class="kv"><div class="k">Height:</div><div class="v" id="pgHeight">-</div></div>
                    <div class="kv"><div class="k">Weight:</div><div class="v" id="pgWeight">-</div></div>
                    <div class="growth-chart-wrap">
                        <div class="growth-chart-title">Over Time: Height (Line) and Weight (Bar)</div>
                        <svg id="pgTrendChart" class="growth-chart" viewBox="0 0 520 180" preserveAspectRatio="none" aria-label="Growth and nutrition line chart">
                            <line x1="48" y1="20" x2="48" y2="150" class="growth-grid-line" />
                            <line x1="48" y1="150" x2="500" y2="150" class="growth-grid-line" />
                            <line x1="48" y1="52" x2="500" y2="52" class="growth-grid-line" />
                            <line x1="48" y1="84" x2="500" y2="84" class="growth-grid-line" />
                            <line x1="48" y1="116" x2="500" y2="116" class="growth-grid-line" />

                            <polyline id="pgHeightLine" class="growth-line-height" points="" />
                            <rect id="pgWeightBarStart" class="growth-bar-weight" x="113" y="130" width="34" height="20" rx="6" />
                            <rect id="pgWeightBarEnd" class="growth-bar-weight" x="373" y="124" width="34" height="26" rx="6" />

                            <circle id="pgHeightStart" class="growth-dot-height" r="4" cx="130" cy="120" />
                            <circle id="pgHeightEnd" class="growth-dot-height" r="4" cx="390" cy="96" />

                            <text x="118" y="168" class="growth-axis-label">Baseline</text>
                            <text x="380" y="168" class="growth-axis-label">Current</text>

                            <text id="pgHeightStartLabel" x="130" y="114" class="growth-value-label">-</text>
                            <text id="pgHeightEndLabel" x="390" y="90" class="growth-value-label">-</text>
                            <text id="pgWeightStartLabel" x="130" y="126" class="growth-value-label">-</text>
                            <text id="pgWeightEndLabel" x="390" y="120" class="growth-value-label">-</text>
                        </svg>
                        <div class="growth-legend">
                            <span><i class="legend-dot height"></i>Height (cm)</span>
                            <span><i class="legend-dot weight"></i>Weight (kg)</span>
                        </div>
                    </div>
                </div>
            </section>
            <section id="p-alerts" class="profile-panel">
                <div class="profile-block">
                    <h4>Medical Alerts</h4>
                    <div class="kv"><div class="k">Current Note:</div><div class="v" id="paStatus">Pending nurse review.</div></div>
                </div>
            </section>
            <section id="p-timeline" class="profile-panel">
                <div class="profile-block">
                    <h4>Health Timeline</h4>
                    <div class="kv"><div class="k">Submission:</div><div class="v">Class Adviser submitted this form.</div></div>
                    <div class="kv"><div class="k">Examination:</div><div class="v" id="ptNext">Nurse examination pending.</div></div>
                    <div id="ptConditionsWrap" style="margin-top:14px;border-top:1px solid #e4ece7;padding-top:12px;">
                        <div style="font-size:.74rem;font-weight:700;color:#1d3c31;text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px;">Medical Conditions &amp; Certificates</div>
                        <div id="ptConditionsList">
                            <div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">Loading&hellip;</div></div>
                        </div>
                    </div>
                </div>
            </section>
            @if(session('active_role') === 'clinic_staff')
            <section id="p-conditions" class="profile-panel">
                <div class="profile-block">
                    <h4>Health Conditions</h4>
                    <div id="shcConditionsList">
                        <div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">Loading&hellip;</div></div>
                    </div>
                </div>
            </section>
            @endif
        </div>
        <div class="profile-actions">
            <button type="button" class="btn btn-secondary" id="profileClose">Close</button>
            <a href="#" class="btn" id="profileFillLink">Fill Medical Record</a>
        </div>
    </div>
</div>

<script>
(() => {
    const cards = Array.from(document.querySelectorAll('.js-record-card'));
    const backdrop = document.getElementById('profileBackdrop');
    const closeBtn = document.getElementById('profileClose');
    const fillLink = document.getElementById('profileFillLink');

    if (!cards.length || !backdrop || !closeBtn || !fillLink) {
        return;
    }

    const setText = (id, value) => {
        const node = document.getElementById(id);
        if (node) {
            node.textContent = value && String(value).trim() !== '' ? String(value) : '-';
        }
    };

    const drawGrowthTrend = (record) => {
        const toNum = (value) => {
            const parsed = Number(value);
            return Number.isFinite(parsed) ? parsed : null;
        };

        const baselineHeight = toNum(record?.baseline_snapshot?.height_cm ?? record?.height_cm);
        const currentHeight = toNum(record?.examination?.height_cm ?? record?.endline_snapshot?.height_cm ?? record?.height_cm);
        const baselineWeight = toNum(record?.baseline_snapshot?.weight_kg ?? record?.weight_kg);
        const currentWeight = toNum(record?.examination?.weight_kg ?? record?.endline_snapshot?.weight_kg ?? record?.weight_kg);

        const yForMetric = (value, minVal, maxVal) => {
            if (value === null) {
                return 150;
            }
            const span = Math.max(1, maxVal - minVal);
            const ratio = (value - minVal) / span;
            return 150 - (ratio * 100);
        };

        const bx = 130;
        const cx = 390;
        const barWidth = 34;

        const heightValues = [baselineHeight, currentHeight].filter((value) => value !== null);
        const weightValues = [baselineWeight, currentWeight].filter((value) => value !== null);

        const minHeight = heightValues.length ? Math.min(...heightValues) * 0.95 : 0;
        const maxHeight = heightValues.length ? Math.max(...heightValues) * 1.05 : 1;
        const minWeight = 0;
        const maxWeight = weightValues.length ? Math.max(...weightValues) * 1.15 : 1;

        const byH = yForMetric(baselineHeight, minHeight, maxHeight);
        const cyH = yForMetric(currentHeight, minHeight, maxHeight);
        const byW = yForMetric(baselineWeight, minWeight, maxWeight);
        const cyW = yForMetric(currentWeight, minWeight, maxWeight);

        const setAttr = (id, attr, value) => {
            const node = document.getElementById(id);
            if (node) {
                node.setAttribute(attr, String(value));
            }
        };

        setAttr('pgHeightLine', 'points', `${bx},${byH} ${cx},${cyH}`);

        setAttr('pgHeightStart', 'cx', bx); setAttr('pgHeightStart', 'cy', byH);
        setAttr('pgHeightEnd', 'cx', cx); setAttr('pgHeightEnd', 'cy', cyH);
        setAttr('pgWeightBarStart', 'x', bx - (barWidth / 2));
        setAttr('pgWeightBarStart', 'y', byW);
        setAttr('pgWeightBarStart', 'width', barWidth);
        setAttr('pgWeightBarStart', 'height', Math.max(2, 150 - byW));
        setAttr('pgWeightBarEnd', 'x', cx - (barWidth / 2));
        setAttr('pgWeightBarEnd', 'y', cyW);
        setAttr('pgWeightBarEnd', 'width', barWidth);
        setAttr('pgWeightBarEnd', 'height', Math.max(2, 150 - cyW));

        const setLabel = (id, x, y, text) => {
            const node = document.getElementById(id);
            if (!node) {
                return;
            }
            node.setAttribute('x', String(x));
            node.setAttribute('y', String(y));
            node.textContent = text;
        };

        setLabel('pgHeightStartLabel', bx, byH - 8, baselineHeight !== null ? `${baselineHeight.toFixed(1)}` : '-');
        setLabel('pgHeightEndLabel', cx, cyH - 8, currentHeight !== null ? `${currentHeight.toFixed(1)}` : '-');
        setLabel('pgWeightStartLabel', bx, byW - 8, baselineWeight !== null ? `${baselineWeight.toFixed(1)}` : '-');
        setLabel('pgWeightEndLabel', cx, cyW - 8, currentWeight !== null ? `${currentWeight.toFixed(1)}` : '-');
    };

    const openProfile = (record, route) => {
        const fullName = [record.last_name, ',', record.first_name, record.middle_name ? (' ' + String(record.middle_name).charAt(0).toUpperCase() + '.') : '']
            .join(' ')
            .replace(' ,', ',')
            .replace(/\s+/g, ' ')
            .trim();
        const dob = [record.birth_year, record.birth_month, record.birth_day].filter(Boolean).join('-');
        const examined = record.examination && Object.keys(record.examination).length > 0;

        setText('pName', fullName || '-');
        setText('pLrn', 'LRN: ' + (record.lrn || '-'));
        setText('pGrade', record.grade_level || '-');

        setText('pdName', fullName || '-');
        setText('pdLrn', record.lrn || '-');
        setText('pdDob', dob || '-');
        setText('pdBirthplace', record.birthplace || '-');
        setText('pdAddress', record.address || '-');
        setText('pdGuardian', record.parent_guardian || '-');
        setText('pdContact', record.telephone_no || '-');
        setText('pdSchoolId', record.school_id || '-');
        setText('pdRegionDivision', [record.region, record.division].filter(Boolean).join(' / ') || '-');

        setText('psGrade', record.grade_level || '-');
        setText('psStatus', examined ? 'Examined by Nurse' : 'Pending');

        setText('pgHeight', (record.height_cm || '-') + ' cm');
        setText('pgWeight', (record.weight_kg || '-') + ' kg');
        drawGrowthTrend(record);
        setText('paStatus', examined ? 'Nurse examination details are available.' : 'Pending nurse review.');
        setText('ptNext', examined ? 'Record completed by nurse.' : 'Nurse examination pending.');

        fillLink.setAttribute('href', route || '#');

        @if(session('active_role') === 'clinic_staff')
        loadConditionsForClinicStaff(record.lrn || '');
        @endif

        loadConditionsForTimeline(record.lrn || '');

        backdrop.classList.add('open');
        backdrop.setAttribute('aria-hidden', 'false');
    };

    @if(session('active_role') === 'clinic_staff')
    const loadConditionsForClinicStaff = async (lrn) => {
        const listEl = document.getElementById('shcConditionsList');
        if (!listEl) return;

        if (!lrn) {
            listEl.innerHTML = '<div style="font-size:.78rem;color:#7a9e87;">No LRN available for this record.</div>';
            return;
        }

        listEl.innerHTML = '<div style="font-size:.78rem;color:#7a9e87;">Loading&hellip;</div>';

        try {
            const resp = await fetch('/api/student-conditions?lrn=' + encodeURIComponent(lrn), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!resp.ok) {
                listEl.innerHTML = '<div style="font-size:.78rem;color:#7a9e87;">No conditions recorded.</div>';
                return;
            }

            const data = await resp.json();
            const conditions = data.conditions || [];

            if (!conditions.length) {
                listEl.innerHTML = '<div style="font-size:.78rem;color:#7a9e87;">No health conditions on file for this student.</div>';
                return;
            }

            listEl.innerHTML = conditions.map(c => {
                const badge = c.is_verified
                    ? '<span style="font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:999px;background:#dcfce7;color:#15803d;margin-left:6px;">Verified / Diagnosed</span>'
                    : '<span style="font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:999px;background:#fef3c7;color:#92400e;margin-left:6px;">Self-reported</span>';

                const certRows = (c.certificates || []).map(cert => {
                    const dl = cert.download_url
                        ? `<a href="${cert.download_url}" target="_blank" style="font-size:.72rem;font-weight:700;color:#15803d;text-decoration:none;border:1px solid #86efac;background:#f0fdf4;border-radius:6px;padding:3px 8px;margin-left:8px;">View</a>`
                        : '';
                    const doctor = cert.doctor_clinic ? ` &mdash; ${cert.doctor_clinic}` : '';
                    const date = cert.diagnosis_date ? ` (${cert.diagnosis_date})` : '';
                    return `<div style="display:flex;align-items:center;gap:4px;padding:4px 0 4px 12px;font-size:.76rem;color:#3d5c47;">
                        <span style="color:#7a9e87;">&#8226;</span>
                        <span>${cert.original_name}${doctor}${date}</span>
                        <span style="font-size:.7rem;color:#7a9e87;">&mdash; uploaded by ${cert.uploaded_by} on ${cert.uploaded_at}</span>
                        ${dl}
                    </div>`;
                }).join('');

                return `<div style="border-bottom:1px solid #edf5ef;padding:8px 0;">
                    <div style="display:flex;align-items:center;gap:4px;">
                        <span style="font-size:.88rem;font-weight:700;color:#1d3c31;">${c.condition_name}</span>
                        ${badge}
                    </div>
                    ${certRows || '<div style="font-size:.72rem;color:#7a9e87;padding:4px 0 0 12px;">No certificates on file.</div>'}
                </div>`;
            }).join('');
        } catch (_err) {
            listEl.innerHTML = '<div style="font-size:.78rem;color:#7a9e87;">Could not load conditions.</div>';
        }
    };
    @endif

    const loadConditionsForTimeline = async (lrn) => {
        const listEl = document.getElementById('ptConditionsList');
        if (!listEl) return;

        if (!lrn) {
            listEl.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">No LRN available.</div></div>';
            return;
        }

        listEl.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">Loading&hellip;</div></div>';

        try {
            const resp = await fetch('/api/student-conditions?lrn=' + encodeURIComponent(lrn), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!resp.ok) {
                listEl.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">No conditions on file.</div></div>';
                return;
            }

            const data = await resp.json();
            const conditions = data.conditions || [];

            if (!conditions.length) {
                listEl.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">No medical conditions recorded for this student.</div></div>';
                return;
            }

            listEl.innerHTML = conditions.map(c => {
                const badge = c.is_verified
                    ? '<span style="font-size:.67rem;font-weight:700;padding:2px 7px;border-radius:999px;background:#dcfce7;color:#15803d;margin-left:6px;">Verified / Diagnosed</span>'
                    : '<span style="font-size:.67rem;font-weight:700;padding:2px 7px;border-radius:999px;background:#fef3c7;color:#92400e;margin-left:6px;">Self-reported</span>';
                const certDetails = (c.certificates || []).map(cert => {
                    const doctor = cert.doctor_clinic ? ` — ${cert.doctor_clinic}` : '';
                    const date = cert.diagnosis_date ? ` (${cert.diagnosis_date})` : '';
                    const dl = cert.download_url
                        ? `<a href="${cert.download_url}" target="_blank" style="display:inline-flex;align-items:center;gap:4px;font-size:.72rem;font-weight:700;color:#15803d;text-decoration:none;border:1px solid #86efac;background:#f0fdf4;border-radius:6px;padding:3px 9px;margin-left:6px;white-space:nowrap;">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            View
                           </a>`
                        : '';
                    return `<div style="display:flex;align-items:center;flex-wrap:wrap;gap:4px;padding:4px 0 4px 12px;font-size:.76rem;color:#3d5c47;">
                        <span style="color:#7a9e87;">•</span>
                        <span>${cert.original_name}${doctor}${date}</span>
                        <span style="color:#7a9e87;">— uploaded by ${cert.uploaded_by} on ${cert.uploaded_at}</span>
                        ${dl}
                    </div>`;
                }).join('');
                return `<div style="border-bottom:1px solid #edf5ef;padding:7px 0;">
                    <div style="display:flex;align-items:center;gap:4px;">
                        <span style="font-size:.88rem;font-weight:700;color:#1d3c31;">${c.condition_name}</span>${badge}
                    </div>
                    ${certDetails || '<div style="font-size:.72rem;color:#7a9e87;padding:3px 0 0 12px;">No certificates on file.</div>'}
                </div>`;
            }).join('');
        } catch (_err) {
            listEl.innerHTML = '<div class="kv"><div class="k">Status:</div><div class="v" style="color:#7a9e87;">Could not load conditions.</div></div>';
        }
    };

    const closeProfile = () => {
        backdrop.classList.remove('open');
        backdrop.setAttribute('aria-hidden', 'true');
    };

    cards.forEach((card) => {
        card.addEventListener('click', () => {
            let record = {};
            try {
                record = JSON.parse(card.getAttribute('data-record') || '{}');
            } catch (_e) {
                record = {};
            }
            openProfile(record, card.getAttribute('data-route') || '#');
        });
    });

    closeBtn.addEventListener('click', closeProfile);
    backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) {
            closeProfile();
        }
    });

    const tabs = Array.from(document.querySelectorAll('.profile-tab'));
    const panels = Array.from(document.querySelectorAll('.profile-panel'));
    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            const target = tab.getAttribute('data-panel');
            tabs.forEach((t) => t.classList.remove('active'));
            panels.forEach((p) => p.classList.remove('active'));
            tab.classList.add('active');
            const panel = document.getElementById(target || '');
            if (panel) {
                panel.classList.add('active');
            }
        });
    });
})();
</script>
</body>
</html>
