<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health Records - LUSOG</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        :root{--g900:#14532d;--g700:#15803d;--g300:#86efac;--cream:#f7f8f5;--card:#fff;--border:#e4ece7;--text-1:#0d1f14;--text-2:#3d5c47;--text-3:#7a9e87;--red:#ef4444;--amber:#f59e0b;--sidebar-w:248px;--sidebar-collapsed-w:76px;--topbar-h:64px;--radius-sm:10px;--shadow-card:0 1px 4px rgba(5,46,22,.06),0 4px 16px rgba(5,46,22,.06)}
        html,body{height:100%;font-family:'DM Sans',sans-serif;background:var(--cream);color:var(--text-1);overflow:hidden}

        .sidebar{position:fixed;left:0;top:0;bottom:0;width:var(--sidebar-collapsed-w);background:var(--g900);display:flex;flex-direction:column;z-index:100;overflow:hidden;transition:width .24s ease}
        .sidebar:hover{width:var(--sidebar-w)}
        .sb-logo{padding:14px 10px;position:relative;z-index:2;border-bottom:1px solid rgba(255,255,255,.08);display:flex;justify-content:center;transition:padding .24s ease}
        .sb-logo img{width:48px;max-width:100%;height:auto;display:block;transition:width .24s ease}
        .sidebar:hover .sb-logo{padding:20px 20px 18px}
        .sidebar:hover .sb-logo img{width:176px}
        .sb-nav{flex:1;overflow-y:auto;padding:16px 8px;position:relative;z-index:2;scrollbar-width:none;transition:padding .24s ease}
        .sidebar:hover .sb-nav{padding:16px 12px}
        .sb-nav::-webkit-scrollbar{display:none}
        .sb-section-label{font-size:.6rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:rgba(134,239,172,.5);padding:0 8px;margin:20px 0 8px}
        .sb-section-label:first-child{margin-top:0}
        .sb-link{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:var(--radius-sm);text-decoration:none;color:rgba(255,255,255,.62);font-size:.83rem;font-weight:500;transition:background .15s,color .15s,padding .24s ease;margin-bottom:2px;white-space:nowrap;overflow:hidden}
        .sb-link:hover{background:rgba(255,255,255,.08);color:rgba(255,255,255,.9)}
        .sb-link.active{background:rgba(34,197,94,.18);color:var(--g300)}
        .sb-link svg{width:16px;height:16px;flex-shrink:0}
        .sidebar:not(:hover) .sb-section-label{display:none}
        .sidebar:not(:hover) .sb-link{justify-content:center;font-size:0;padding:10px;gap:0}
        .sb-user{padding:14px 16px;border-top:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:11px;position:relative;z-index:2}
        .sb-avatar{width:34px;height:34px;border-radius:50%;background:#16a34a;display:grid;place-items:center;font-size:.8rem;font-weight:700;color:#fff;flex-shrink:0}
        .sb-user-meta{min-width:0}
        .sb-user-name{font-size:.8rem;font-weight:600;color:#fff;line-height:1.2}
        .sb-user-role{font-size:.68rem;color:var(--g300)}
        .sb-logout{margin-left:auto;background:none;border:none;color:rgba(255,255,255,.35);cursor:pointer;padding:4px;border-radius:6px;transition:color .15s,background .15s;display:grid;place-items:center}
        .sb-logout:hover{color:var(--red);background:rgba(239,68,68,.1)}
        .sb-logout svg{width:15px;height:15px}
        .sidebar:not(:hover) .sb-user{padding:14px 10px}
        .sidebar:not(:hover) .sb-user-meta{display:none}

        .main{margin-left:var(--sidebar-collapsed-w);height:100vh;display:flex;flex-direction:column;overflow:hidden;transition:margin-left .24s ease}
        .sidebar:hover ~ .main{margin-left:var(--sidebar-w)}
        .topbar{height:var(--topbar-h);border-bottom:1px solid var(--border);background:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 24px}
        .topbar-bc{font-size:.78rem;color:var(--text-3);display:flex;gap:6px;align-items:center}
        .topbar-chip{font-size:.72rem;border:1px solid #bbf7d0;color:#15803d;background:#f0fdf4;border-radius:999px;padding:5px 11px}

        .content{overflow:auto;padding:20px}
        .page-eyebrow{font-size:.68rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#15803d;margin-bottom:6px}
        .page-title{font-family:'DM Serif Display',serif;font-size:1.75rem;line-height:1.15}
        .page-title span{font-style:italic;color:#15803d}
        .page-sub{margin-top:5px;font-size:.8rem;color:var(--text-3)}

        .cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:14px}
        .mini-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:12px;box-shadow:var(--shadow-card)}
        .mini-card .val{font-family:'DM Serif Display',serif;font-size:1.4rem}
        .mini-card .lbl{font-size:.72rem;color:var(--text-3)}

        .table-card{margin-top:14px;background:#fff;border:1px solid var(--border);border-radius:10px;overflow:auto;box-shadow:var(--shadow-card)}
        table{width:100%;border-collapse:collapse}
        th,td{padding:10px;border-bottom:1px solid #edf2f1;font-size:.74rem;text-align:left}
        th{color:#7a8f8a;background:#f9fbfa;font-weight:700;letter-spacing:.06em;text-transform:uppercase}
        tr:last-child td{border-bottom:none}
        .status{font-size:.66rem;font-weight:700;border-radius:999px;padding:3px 8px;display:inline-block}
        .s-pending{background:#fef3c7;color:#92400e}
        .s-done{background:#dcfce7;color:#15803d}
        .btn{border:none;background:#15803d;color:#fff;font-weight:700;border-radius:8px;padding:7px 10px;cursor:pointer;text-decoration:none;display:inline-block}
        .btn:hover{background:#166534}

        .record-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:14px}
        .record-card{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow-card);padding:14px;cursor:pointer;transition:transform .15s ease,box-shadow .2s ease,border-color .2s ease;display:flex;flex-direction:column;gap:8px;border-left:5px solid #16a34a}
        .record-card.pending{border-left-color:#f59e0b}
        .record-card.done{border-left-color:#16a34a}
        .record-card:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(5,46,22,.12);border-color:#d5e8de}
        .record-top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
        .record-name{font-size:1.05rem;font-weight:700;color:#173726}
        .record-status{font-size:.8rem;font-weight:700}
        .record-status.pending{color:#b45309}
        .record-status.done{color:#15803d}
        .record-sub{font-size:.76rem;color:#6f8c7a;line-height:1.4}
        .record-chips{display:flex;flex-wrap:wrap;gap:6px}
        .chip{background:#f5f8f6;border:1px solid #e6efea;border-radius:8px;padding:4px 8px;font-size:.76rem;color:#355a47}

        .profile-backdrop{position:fixed;inset:0;background:rgba(6,26,14,.42);display:none;align-items:center;justify-content:center;z-index:1400}
        .profile-backdrop.open{display:flex}
        .profile-modal{width:min(1000px,95vw);max-height:90vh;overflow:auto;background:#fff;border-radius:14px;border:1px solid var(--border);box-shadow:0 24px 50px rgba(6,26,14,.28)}
        .profile-head{background:linear-gradient(90deg,#1f4c3a,#214f40);color:#fff;padding:14px 18px;display:flex;justify-content:space-between;align-items:flex-start}
        .profile-title{font-size:1.5rem;font-weight:700;line-height:1.1}
        .profile-meta{font-size:.82rem;opacity:.9;margin-top:4px}
        .profile-right{text-align:right;font-size:.78rem;opacity:.95}
        .profile-right b{display:block;font-size:1.2rem;margin-top:3px}
        .profile-tabs{display:flex;gap:0;border-bottom:1px solid var(--border);background:#fff}
        .profile-tab{border:none;background:none;padding:12px 18px;font-size:.85rem;font-weight:700;color:#6c7f76;cursor:pointer;border-bottom:2px solid transparent}
        .profile-tab.active{color:#215443;border-bottom-color:#2f7d65}
        .profile-body{padding:16px 18px}
        .profile-panel{display:none}
        .profile-panel.active{display:block}
        .profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        .profile-block{border-top:1px solid #e8efeb;padding-top:10px}
        .profile-block h4{font-size:1rem;color:#2e3d36;margin-bottom:8px}
        .kv{display:grid;grid-template-columns:140px 1fr;gap:8px;font-size:.92rem;margin-bottom:6px}
        .kv .k{color:#7a8f86}
        .kv .v{color:#1d3327;font-weight:600}
        .profile-actions{display:flex;justify-content:flex-end;padding:0 18px 16px;gap:8px}
        .btn-secondary{background:#fff;border:1px solid #c7d8cf;color:#285640}

        @media (max-width:980px){.cards{grid-template-columns:1fr}.record-grid{grid-template-columns:1fr}.profile-grid{grid-template-columns:1fr}}

        @media (max-width:980px){.cards{grid-template-columns:1fr}}
        @media (max-width:780px){:root{--sidebar-w:0px;--sidebar-collapsed-w:0px}.sidebar{display:none}.main{margin-left:0}}
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="sb-logo"><img src="{{ asset('images/lusog-logo.png') }}" alt="LUSOG Logo"></div>
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
        <a href="#" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
            Feeding Program
        </a>
        <a href="#" class="sb-link">
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
        <div class="sb-avatar">{{ substr(auth()->user()->name ?? 'SN', 0, 2) }}</div>
        <div class="sb-user-meta">
            <div class="sb-user-name">{{ auth()->user()->name ?? 'School Nurse' }}</div>
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
        <div class="topbar-bc"><span>Dashboard</span><span>></span><span>Student Health Records</span></div>
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
                    <div class="kv"><div class="k">Next Step:</div><div class="v" id="ptNext">Nurse examination pending.</div></div>
                </div>
            </section>
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
        setText('paStatus', examined ? 'Nurse examination details are available.' : 'Pending nurse review.');
        setText('ptNext', examined ? 'Record completed by nurse.' : 'Nurse examination pending.');

        fillLink.setAttribute('href', route || '#');
        backdrop.classList.add('open');
        backdrop.setAttribute('aria-hidden', 'false');
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
