<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <link rel="shortcut icon" href="{{ asset('images/lusog-logo.png') }}">
    <title>Class Adviser Dashboard - LUSOG</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        :root{--bg:#f7f8f5;--card:#fff;--border:#e4ece7;--text:#0d1f14;--muted:#6f8c7a;--g900:#14532d;--g700:#15803d;--g300:#86efac;--g100:#dcfce7;--red:#ef4444;--amber:#f59e0b;--blue:#3b82f6;--sidebar:248px;--sidebar-collapsed:76px;--radius-sm:10px;--shadow:0 1px 4px rgba(5,46,22,.06),0 4px 16px rgba(5,46,22,.06)}
        html,body{height:100%;font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);overflow:hidden}
        .sidebar{position:fixed;left:0;top:0;bottom:0;width:var(--sidebar-collapsed);background:var(--g900);display:flex;flex-direction:column;overflow:hidden;transition:width .26s ease;box-shadow:inset -1px 0 0 rgba(255,255,255,.04)}
        .sidebar:hover{width:var(--sidebar)}
        .sb-logo{padding:16px 12px;border-bottom:1px solid rgba(255,255,255,.1);display:flex;justify-content:center;transition:padding .26s ease}
        .sb-logo img{width:48px;max-width:100%;height:auto;display:block;transition:width .26s ease}
        .sidebar:hover .sb-logo{padding:20px}
        .sidebar:hover .sb-logo img{width:170px}
        .sb-nav{padding:16px 8px;flex:1;transition:padding .26s ease}
        .sidebar:hover .sb-nav{padding:16px 12px}
        .sb-link{display:flex;align-items:center;gap:10px;color:rgba(255,255,255,.7);text-decoration:none;font-size:.83rem;font-weight:600;padding:10px;border-radius:var(--radius-sm);transition:background .2s ease,color .2s ease,padding .26s ease}
        .sidebar:hover .sb-link{padding:10px 12px}
        .sb-link-icon{width:16px;height:16px;flex-shrink:0}
        .sidebar:not(:hover) .sb-link{justify-content:center;gap:0;padding:10px}
        .sb-link-label{white-space:nowrap;opacity:0;max-width:0;overflow:hidden;transform:translateX(-6px);transition:opacity .16s ease,max-width .26s ease,transform .26s ease}
        .sidebar:hover .sb-link-label{opacity:1;max-width:220px;transform:translateX(0)}
        .sb-link.active{background:rgba(34,197,94,.18);color:var(--g300)}
        .sb-link:hover{background:rgba(255,255,255,.08);color:#fff}
        .sb-user{padding:12px 10px;border-top:1px solid rgba(255,255,255,.1);display:flex;align-items:center;gap:10px;color:#fff;transition:padding .26s ease}
        .sb-user form{margin-left:auto;display:flex;align-items:center}
        .sidebar:hover .sb-user{padding:14px 16px}
        .sb-avatar{width:34px;height:34px;border-radius:50%;background:#16a34a;display:grid;place-items:center;font-weight:700;font-size:.76rem;letter-spacing:.08em;line-height:1;padding-left:1px}
        .sb-user-name{white-space:nowrap;opacity:0;max-width:0;overflow:hidden;transform:translateX(-6px);transition:opacity .16s ease,max-width .26s ease,transform .26s ease}
        .sidebar:hover .sb-user-name{opacity:1;max-width:170px;transform:translateX(0)}
        .sb-logout{background:none;border:none;color:rgba(255,255,255,.62);cursor:pointer;border-radius:8px;width:30px;height:30px;display:grid;place-items:center;transition:background .2s ease,color .2s ease}
        .sb-logout svg{width:16px;height:16px}
        .sb-logout:hover{background:rgba(239,68,68,.14);color:#fecaca}

        .main{margin-left:var(--sidebar-collapsed);height:100vh;display:flex;flex-direction:column;transition:margin-left .26s ease}
        .sidebar:hover ~ .main{margin-left:var(--sidebar)}
        .top{height:64px;background:#fff;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 24px}
        .crumb{font-size:.82rem;color:var(--muted)}
        .content{padding:24px;overflow:auto}
        .title{font-family:'DM Serif Display',serif;font-size:1.7rem}
        .title i{color:#15803d}
        .sub{color:var(--muted);font-size:.85rem;margin-top:4px}

        .flash{margin:14px 0;padding:10px 12px;border-radius:10px;font-size:.82rem}
        .flash-ok{background:#dcfce7;color:#166534;border:1px solid #86efac}
        .flash-err{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
        .toast-success{position:fixed;top:20px;right:20px;z-index:1200;background:#166534;color:#fff;border-radius:10px;padding:12px 14px;box-shadow:0 10px 28px rgba(22,101,52,.35);font-size:.82rem;font-weight:600;min-width:260px;max-width:340px;display:flex;align-items:flex-start;gap:8px;animation:toastIn .22s ease}
        .toast-close{margin-left:auto;background:none;border:none;color:#d1fae5;cursor:pointer;font-weight:700;line-height:1;padding:0}
        @keyframes toastIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
        .section-panel{display:none}
        .section-panel.active{display:block}

        .stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:16px 0}
        .card{background:var(--card);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);transition:transform .16s ease,box-shadow .2s ease,border-color .2s ease}
        .card:hover{transform:translateY(-1px);box-shadow:0 2px 8px rgba(5,46,22,.08),0 10px 24px rgba(5,46,22,.07);border-color:#d9e8e0}
        .stat{padding:14px}
        .stat b{font-family:'DM Serif Display',serif;font-size:1.5rem;display:block}
        .stat span{font-size:.72rem;color:var(--muted)}
        .assigned-class-banner{margin-top:10px;background:linear-gradient(135deg,#14532d,#1d6a43);color:#eafff3;border-radius:12px;padding:12px 14px;display:flex;justify-content:space-between;align-items:center;gap:10px;border:1px solid rgba(134,239,172,.25);box-shadow:0 8px 20px rgba(20,83,45,.18)}
        .assigned-class-label{font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:#c8f6dc;font-weight:700}
        .assigned-class-value{font-size:1rem;font-weight:800;letter-spacing:.01em}
        .assigned-class-note{font-size:.75rem;color:#d7f7e6}

        .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .section{padding:14px}
        .section h3{font-size:.82rem;color:#3d5c47;margin-bottom:10px;text-transform:uppercase;letter-spacing:.08em}
        .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
        .full{grid-column:1 / -1}
        .field{display:flex;flex-direction:column;gap:6px}
        .field label{font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:700}
        .field input,.field select{height:38px;border:1px solid var(--border);border-radius:8px;padding:0 10px;font:inherit;background:#fff}
        .field input:focus,.field select:focus{outline:none;border-color:#7fbeaa;box-shadow:0 0 0 3px rgba(63,147,114,.14)}
        .btn{border:none;background:#15803d;color:#fff;font-weight:700;border-radius:8px;padding:9px 12px;cursor:pointer;transition:background .18s ease,transform .14s ease,box-shadow .18s ease}
        .btn:hover{background:#166534}
        .btn:active{transform:translateY(1px)}

        table{width:100%;border-collapse:collapse}
        th,td{padding:10px;border-bottom:1px solid var(--border);font-size:.78rem;text-align:left}
        th{font-size:.67rem;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);background:#f7faf8}
        tbody tr:hover td{background:#f7fbf8}
        .badge{display:inline-block;padding:3px 9px;border-radius:999px;font-size:.68rem;font-weight:700}
        .ok{background:#dcfce7;color:#166534}
        .warn{background:#fef3c7;color:#92400e}
        .risk{background:#fee2e2;color:#991b1b}
        .over{background:#dbeafe;color:#1e40af}
        .muted{color:var(--muted)}
        .profile-open-btn{border:1px solid #15803d;background:#fff;color:#166534;font-weight:700;border-radius:8px;padding:6px 10px;cursor:pointer;font-size:.72rem}
        .profile-open-btn:hover{background:#f0fdf4}
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
        .growth-wrap{display:grid;grid-template-columns:1fr 1fr;gap:14px}
        .growth-card{border:1px solid var(--border);border-radius:10px;padding:10px;background:#f8fcfa}
        .growth-card h5{font-size:.78rem;color:#2b4c3d;margin-bottom:8px;text-transform:uppercase;letter-spacing:.06em}
        .growth-metrics{display:grid;grid-template-columns:1fr 1fr;gap:8px}
        .growth-metric{border:1px solid #deebe4;border-radius:8px;padding:8px;background:#fff}
        .growth-metric .lbl{font-size:.66rem;color:#6b8679;text-transform:uppercase;letter-spacing:.06em}
        .growth-metric .val{font-size:.86rem;color:#143021;font-weight:700;margin-top:2px}
        .growth-chart{width:100%;height:170px}
        .growth-chart-axis{stroke:#d8e5de;stroke-width:1}
        .growth-chart-line{fill:none;stroke:#15803d;stroke-width:3}
        .growth-chart-dot{fill:#15803d}
        .growth-chart-dot.end{fill:#3b82f6}
        .growth-point-label{font-size:.72rem;fill:#3d5c47;font-weight:700}
        .attendance-bars{display:flex;align-items:flex-end;gap:8px;height:120px;padding-top:4px}
        .attendance-col{flex:1;min-width:28px;text-align:center}
        .attendance-bar{width:100%;border-radius:7px 7px 0 0;background:linear-gradient(180deg,#3f946b,#25684b);min-height:6px}
        .attendance-month{font-size:.62rem;color:#6f8c7a;margin-top:4px;white-space:nowrap}
        .attendance-val{font-size:.62rem;color:#315444;font-weight:700}
        .add-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:10px}
        .add-title{font-size:1.15rem;font-weight:800;color:#1d3c31}
        .add-sub{font-size:.8rem;color:#6f8c7a;margin-top:4px}
        .add-date{font-size:.76rem;color:#6f8c7a;padding:7px 10px;border:1px solid var(--border);border-radius:8px;background:#fff}
        .class-box{background:#eef9f3;border:1px solid #c8e8d8;border-left:4px solid #15803d;border-radius:10px;padding:10px 12px;margin-bottom:12px}
        .class-box-row{display:flex;align-items:center;gap:8px;font-size:.82rem;color:#2d5c49}
        .class-box-value{font-weight:800;color:#15613f}
        .class-box-note{font-size:.72rem;color:#638477;margin-top:3px}
        .student-section{border-bottom:1px solid var(--border);padding-bottom:12px;margin-bottom:12px}
        .student-section h4{font-size:.86rem;color:#284b3e;margin-bottom:10px;text-transform:uppercase;letter-spacing:.06em}
        .student-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
        .student-grid .full{grid-column:1 / -1}
        .field textarea{border:1px solid var(--border);border-radius:8px;padding:9px 10px;font:inherit;font-size:.84rem;resize:vertical;min-height:70px}
        .calc-box{background:#f4f8f6;border:1px solid #dbe9e1;border-radius:10px;padding:10px}
        .calc-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:8px}
        .calc-item{text-align:center;background:#fff;border:1px solid #e2ece7;border-radius:8px;padding:8px}
        .calc-item .label{font-size:.66rem;color:#6f8c7a}
        .calc-item .value{font-size:1rem;font-weight:800;color:#1a573f;margin-top:3px;line-height:1.1}
        .note-box{background:#fff8db;border:1px solid #f6e6a7;border-radius:10px;padding:10px 12px;font-size:.78rem;color:#7a6320;line-height:1.4}
        .confirm-overlay{position:fixed;inset:0;background:rgba(0,0,0,.48);display:none;align-items:center;justify-content:center;z-index:1500;padding:16px}
        .confirm-overlay.open{display:flex}
        .confirm-modal{width:min(760px,96vw);max-height:90vh;overflow:auto;background:#fff;border-radius:14px;border:1px solid var(--border);box-shadow:0 24px 50px rgba(6,26,14,.28)}
        .confirm-head{position:sticky;top:0;z-index:1;background:linear-gradient(90deg,#14532d,#1d6a43);color:#fff;padding:12px 14px;border-radius:14px 14px 0 0;display:flex;justify-content:space-between;align-items:center}
        .confirm-title{font-size:1rem;font-weight:800}
        .confirm-close{background:none;border:none;color:#d9fbe8;font-size:1.05rem;cursor:pointer}
        .confirm-body{padding:14px}
        .confirm-info{background:#eff6ff;border-left:4px solid #3b82f6;border-radius:8px;padding:10px 12px;font-size:.8rem;color:#1e3a8a;margin-bottom:10px}
        .summary-card{background:#f8faf9;border:1px solid #e4ece7;border-radius:10px;padding:10px 12px;margin-bottom:8px}
        .summary-card h5{font-size:.8rem;color:#1f5a43;margin-bottom:6px}
        .summary-grid{display:grid;grid-template-columns:160px 1fr;gap:4px 8px;font-size:.8rem}
        .summary-k{color:#6f8c7a}
        .summary-v{color:#1c3429;font-weight:600}
        .confirm-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:10px;padding-top:10px;border-top:1px solid var(--border)}

        @media (max-width:980px){.stats{grid-template-columns:1fr}.grid{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}.profile-grid{grid-template-columns:1fr}.student-grid{grid-template-columns:1fr}.calc-grid{grid-template-columns:1fr 1fr}.add-head{flex-direction:column;align-items:flex-start}}
        @media (max-width:780px){.sidebar{display:none}.main{margin-left:0}}
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="sb-logo"><img src="{{ asset('images/lusog-logo.png') }}" alt="LUSOG Logo"></div>
    <nav class="sb-nav">
        <a href="#" class="sb-link active js-proto-nav" data-target="prototype-form-panel">
            <svg class="sb-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
            <span class="sb-link-label">School Health Card Form</span>
        </a>
        <a href="#" class="sb-link js-proto-nav" data-target="prototype-saved-panel">
            <svg class="sb-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <line x1="8" y1="6" x2="21" y2="6"/>
                <line x1="8" y1="12" x2="21" y2="12"/>
                <line x1="8" y1="18" x2="21" y2="18"/>
                <line x1="3" y1="6" x2="3.01" y2="6"/>
                <line x1="3" y1="12" x2="3.01" y2="12"/>
                <line x1="3" y1="18" x2="3.01" y2="18"/>
            </svg>
            <span class="sb-link-label">Saved Submissions</span>
        </a>
    </nav>
    <div class="sb-user">
        <div class="sb-avatar">{{ substr(auth()->user()->name ?? 'CA',0,2) }}</div>
        <div class="sb-user-name">{{ auth()->user()->name ?? 'Class Adviser' }}</div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="sb-logout" title="Sign out" aria-label="Sign out">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <path d="M16 17l5-5-5-5"/>
                    <path d="M21 12H9"/>
                </svg>
            </button>
        </form>
    </div>
</aside>

<div class="main">
    <header class="top"><div class="crumb">Dashboard > Class Adviser</div></header>
    <div class="content">
        @php
            $assignedGradeLevel = session('assigned_grade_level');
            $assignedSection = session('assigned_section');
            $assignedClassLabel = ($assignedGradeLevel && $assignedSection)
                ? ($assignedGradeLevel . ' / ' . $assignedSection)
                : 'Not Assigned';
        @endphp
        <h1 class="title">Class Adviser <i>Encoding Workspace</i></h1>
        <p class="sub">School Health Card prototype workflow for adviser submission and nurse follow-up.</p>
        <div class="assigned-class-banner">
            <div>
                <div class="assigned-class-label">Assigned Class</div>
                <div class="assigned-class-value">{{ $assignedClassLabel }}</div>
            </div>
            <div class="assigned-class-note">Only learners from this grade and section are shown and can be encoded.</div>
        </div>

        @if (session('success'))
            <div class="toast-success" id="successToast" role="status" aria-live="polite">
                <span>{{ session('success') }}</span>
                <button type="button" class="toast-close" id="toastClose" aria-label="Close">x</button>
            </div>
        @endif
        @if ($errors->any())
            <div class="flash flash-err">Incomplete fields detected. Please complete all required entries before submitting.</div>
        @endif

        @php
            $allPrototypeRecords = session('school_health_card_records', []);
            $prototypeRecords = collect($allPrototypeRecords)->filter(function ($row) use ($assignedGradeLevel, $assignedSection) {
                if (!$assignedGradeLevel || !$assignedSection) {
                    return true;
                }

                return (string) ($row['grade_level'] ?? '') === (string) $assignedGradeLevel
                    && (string) ($row['section'] ?? '') === (string) $assignedSection;
            });
        @endphp

        <section id="prototype-saved-panel" class="card section section-panel" style="margin-top:12px;">
            <h3>Saved School Health Card Submissions</h3>
            <table>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>LRN</th>
                        <th>Grade Level</th>
                        <th>Section</th>
                        <th>Height</th>
                        <th>Weight</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($prototypeRecords as $index => $prototypeRecord)
                        @php
                            $middle = trim((string) ($prototypeRecord['middle_name'] ?? ''));
                            $middleInitial = $middle !== '' ? (' ' . strtoupper(substr($middle, 0, 1)) . '.') : '';
                            $fullName = trim(($prototypeRecord['last_name'] ?? '') . ', ' . ($prototypeRecord['first_name'] ?? '') . $middleInitial);
                            $isExamined = !empty($prototypeRecord['examination']);
                        @endphp
                        <tr>
                            <td>{{ $fullName }}</td>
                            <td>{{ $prototypeRecord['lrn'] ?? '-' }}</td>
                            <td>{{ $prototypeRecord['grade_level'] ?? '-' }}</td>
                            <td>{{ $prototypeRecord['section'] ?? '-' }}</td>
                            <td>{{ $prototypeRecord['height_cm'] ?? '-' }}</td>
                            <td>{{ $prototypeRecord['weight_kg'] ?? '-' }}</td>
                            <td>
                                @if ($isExamined)
                                    <span class="badge ok">Examined by Nurse</span>
                                @else
                                    <span class="badge warn">Pending Nurse Examination</span>
                                @endif
                            </td>
                            <td>
                                <button type="button" class="profile-open-btn js-profile-open" data-route="{{ route('nurse.examine', $index) }}" data-record='@json($prototypeRecord)'>Student Profile</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="muted">No School Health Card prototype submissions yet for your assigned class.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section id="prototype-form-panel" class="card section section-panel active" style="margin-top:12px;">
            <div class="add-head">
                <div>
                    <h3 class="add-title">Add New Student</h3>
                    <p class="add-sub">Enter basic information, weight, and height. BMI and nutritional status will be auto-calculated.</p>
                </div>
                <div class="add-date" id="currentDate">-</div>
            </div>

            <div class="class-box">
                <div class="class-box-row"><span>Your assigned class:</span><span class="class-box-value" id="assignedClassDisplay">{{ $assignedClassLabel }}</span></div>
                <div class="class-box-note">Students will be automatically added to this grade and section.</div>
            </div>

            <form id="studentForm" method="POST" action="{{ route('adviser.store') }}" autocomplete="off">
                @csrf
                <input id="proto_birth_month" name="birth_month" type="hidden" value="{{ old('birth_month') }}">
                <input id="proto_birth_day" name="birth_day" type="hidden" value="{{ old('birth_day') }}">
                <input id="proto_birth_year" name="birth_year" type="hidden" value="{{ old('birth_year') }}">
                <input id="proto_height_cm" name="height_cm" type="hidden" value="{{ old('height_cm') }}">
                <input type="hidden" name="grade_level" value="{{ $assignedGradeLevel ?? '' }}">
                <input type="hidden" name="section" value="{{ $assignedSection ?? '' }}">

                <div class="student-section">
                    <h4>Student Information</h4>
                    <div class="student-grid">
                        <div class="field"><label for="proto_last_name">Last Name</label><input id="proto_last_name" name="last_name" type="text" placeholder="e.g., Dela Cruz" value="{{ old('last_name') }}" required></div>
                        <div class="field"><label for="proto_first_name">First Name</label><input id="proto_first_name" name="first_name" type="text" placeholder="e.g., Maria" value="{{ old('first_name') }}" required></div>
                        <div class="field"><label for="proto_middle_name">Middle Name</label><input id="proto_middle_name" name="middle_name" type="text" placeholder="e.g., Santos" value="{{ old('middle_name') }}"></div>
                        <div class="field"><label for="proto_lrn">LRN</label><input id="proto_lrn" name="lrn" type="text" placeholder="12-digit Learner Reference Number" value="{{ old('lrn') }}" inputmode="numeric" required></div>
                        <div class="field"><label for="birthDate">Date of Birth</label><input id="birthDate" type="date" value="{{ old('birth_year') && old('birth_month') && old('birth_day') ? old('birth_year') . '-' . str_pad(old('birth_month'), 2, '0', STR_PAD_LEFT) . '-' . str_pad(old('birth_day'), 2, '0', STR_PAD_LEFT) : '' }}" required></div>
                        <div class="field"><label for="proto_birthplace">Birthplace</label><input id="proto_birthplace" name="birthplace" type="text" placeholder="City/Municipality of birth" value="{{ old('birthplace') }}" required></div>
                        <div class="field full"><label for="gender">Gender</label><select id="gender"><option value="">Select Gender</option><option>Male</option><option>Female</option></select></div>
                    </div>
                </div>

                <div class="student-section">
                    <h4>Parent/Guardian Information</h4>
                    <div class="student-grid">
                        <div class="field full"><label for="proto_parent_guardian">Parent/Guardian Name</label><input id="proto_parent_guardian" name="parent_guardian" type="text" placeholder="Full name of parent or guardian" value="{{ old('parent_guardian') }}" required></div>
                        <div class="field"><label for="proto_telephone_no">Contact Number</label><input id="proto_telephone_no" name="telephone_no" type="text" placeholder="e.g., 09171234567" value="{{ old('telephone_no') }}" inputmode="tel" required></div>
                        <div class="field full"><label for="proto_address">Address</label><textarea id="proto_address" name="address" rows="2" required>{{ old('address') }}</textarea></div>
                    </div>
                </div>

                <div class="student-section">
                    <h4>Health Data (Baseline)</h4>
                    <div class="student-grid" style="margin-bottom:10px;">
                        <div class="field"><label for="weight">Weight (kg)</label><input id="weight" name="weight_kg" type="number" step="0.1" min="0" max="200" placeholder="e.g., 34" value="{{ old('weight_kg') }}" required></div>
                        <div class="field"><label for="height">Height (m)</label><input id="height" type="number" step="0.01" min="0.50" max="2.50" placeholder="e.g., 1.27" value="{{ old('height_cm') ? number_format(old('height_cm') / 100, 2, '.', '') : '' }}" required></div>
                    </div>

                    <div class="calc-box">
                        <div style="font-size:.78rem;color:#48685a;font-weight:700;">Auto-Calculated Results</div>
                        <div class="calc-grid">
                            <div class="calc-item"><div class="label">(Height)^2</div><div class="value" id="heightSquared">-</div></div>
                            <div class="calc-item"><div class="label">BMI (kg/m^2)</div><div class="value" id="bmiDisplay">-</div></div>
                            <div class="calc-item"><div class="label">Nutritional Status</div><div class="value" id="nutriStatusDisplay">-</div></div>
                            <div class="calc-item"><div class="label">Height-for-Age</div><div class="value" id="hfaDisplay">-</div></div>
                        </div>
                    </div>
                </div>

                <div class="student-grid" style="display:none;">
                    <input id="proto_school_id" name="school_id" type="hidden" value="{{ old('school_id', 'DCNHS-001') }}">
                    <input id="proto_region" name="region" type="hidden" value="{{ old('region', 'NCR') }}">
                    <input id="proto_division" name="division" type="hidden" value="{{ old('division', 'Quezon City') }}">
                </div>

                <div class="note-box">After submission, the school nurse will complete the remaining SHD form fields. You can view complete updates once nurse review is done.</div>

                <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:10px;">
                    <button type="button" class="btn btn-secondary" id="cancelAddStudent">Cancel</button>
                    <button type="button" class="btn" id="reviewSubmitBtn">Review &amp; Submit</button>
                </div>
            </form>
        </section>
    </div>
</div>

<div id="confirmationModal" class="confirm-overlay" aria-hidden="true">
    <div class="confirm-modal" role="dialog" aria-modal="true" aria-label="Confirm student information">
        <div class="confirm-head">
            <div class="confirm-title">Confirm Student Information</div>
            <button type="button" class="confirm-close" id="confirmCloseBtn">x</button>
        </div>
        <div class="confirm-body">
            <div class="confirm-info">Please review the information before submitting. The school nurse will complete the remaining health record.</div>
            <div id="summaryContainer"></div>
            <div class="confirm-actions">
                <button type="button" class="btn btn-secondary" id="confirmEditBtn">Edit</button>
                <button type="button" class="btn" id="confirmSubmitBtn">Confirm &amp; Submit</button>
            </div>
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
                    <div class="kv"><div class="k">BMI:</div><div class="v" id="psBmi">-</div></div>
                    <div class="kv"><div class="k">BMI for Age:</div><div class="v" id="psBmiForAge">-</div></div>
                    <div class="kv"><div class="k">Height for Age:</div><div class="v" id="psHeightForAge">-</div></div>
                </div>
            </section>
            <section id="p-growth" class="profile-panel">
                <div class="profile-block">
                    <h4>Growth &amp; Nutrition</h4>
                    <div class="growth-wrap">
                        <div class="growth-card">
                            <h5>BMI Trend: Baseline to Endline</h5>
                            <svg class="growth-chart" viewBox="0 0 380 170" preserveAspectRatio="none" aria-label="Baseline to endline BMI chart">
                                <line class="growth-chart-axis" x1="40" y1="140" x2="350" y2="140"></line>
                                <line class="growth-chart-axis" x1="40" y1="20" x2="40" y2="140"></line>
                                <polyline id="pgTrendLine" class="growth-chart-line" points="80,120 300,100"></polyline>
                                <circle id="pgBaseDot" class="growth-chart-dot" cx="80" cy="120" r="5"></circle>
                                <circle id="pgEndDot" class="growth-chart-dot end" cx="300" cy="100" r="5"></circle>
                                <text id="pgBasePointText" class="growth-point-label" x="54" y="112">Baseline</text>
                                <text id="pgEndPointText" class="growth-point-label" x="276" y="92">Endline</text>
                            </svg>
                            <div class="growth-metrics">
                                <div class="growth-metric">
                                    <div class="lbl">Baseline BMI</div>
                                    <div class="val" id="pgBaseBmi">-</div>
                                </div>
                                <div class="growth-metric">
                                    <div class="lbl">Endline BMI</div>
                                    <div class="val" id="pgEndBmi">-</div>
                                </div>
                            </div>
                        </div>
                        <div class="growth-card">
                            <h5>Monthly Attendance</h5>
                            <div class="attendance-bars" id="pgAttendanceBars" aria-label="Monthly attendance chart"></div>
                            <div class="kv" style="margin-top:8px"><div class="k">Latest Attendance Month:</div><div class="v" id="pgAttendanceLatest">-</div></div>
                            <div class="kv"><div class="k">Total Recorded Sessions:</div><div class="v" id="pgAttendanceTotal">-</div></div>
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
    const navLinks = Array.from(document.querySelectorAll('.js-proto-nav'));
    const tabPanels = Array.from(document.querySelectorAll('.section-panel'));

    if (!navLinks.length || !tabPanels.length) {
        return;
    }

    navLinks.forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            const targetId = link.getAttribute('data-target');

            navLinks.forEach((navLink) => {
                navLink.classList.remove('active');
            });

            tabPanels.forEach((panel) => {
                panel.classList.remove('active');
            });

            link.classList.add('active');

            const targetPanel = document.getElementById(targetId);
            if (targetPanel) {
                targetPanel.classList.add('active');
            }
        });
    });
})();

(() => {
    const toast = document.getElementById('successToast');
    if (!toast) {
        return;
    }

    const closeBtn = document.getElementById('toastClose');
    const dismiss = () => {
        toast.style.display = 'none';
    };

    if (closeBtn) {
        closeBtn.addEventListener('click', dismiss);
    }

    window.setTimeout(dismiss, 3200);
})();

(() => {
    const birthDate = document.getElementById('birthDate');
    const birthMonth = document.getElementById('proto_birth_month');
    const birthDay = document.getElementById('proto_birth_day');
    const birthYear = document.getElementById('proto_birth_year');

    if (!birthDate || !birthMonth || !birthDay || !birthYear) {
        return;
    }

    const syncBirthParts = () => {
        if (!birthDate.value) {
            birthMonth.value = '';
            birthDay.value = '';
            birthYear.value = '';
            return;
        }

        const parts = birthDate.value.split('-');
        birthYear.value = parts[0] || '';
        birthMonth.value = parts[1] || '';
        birthDay.value = parts[2] || '';
    };

    birthDate.addEventListener('change', syncBirthParts);
    birthDate.addEventListener('input', syncBirthParts);
    syncBirthParts();
})();

(() => {
    const heightInput = document.getElementById('height');
    const weightInput = document.getElementById('weight');
    const birthDate = document.getElementById('birthDate');
    const heightCmHidden = document.getElementById('proto_height_cm');
    const heightSquaredOut = document.getElementById('heightSquared');
    const bmiOut = document.getElementById('bmiDisplay');
    const bmiAgeOut = document.getElementById('nutriStatusDisplay');
    const hfaOut = document.getElementById('hfaDisplay');

    if (!heightInput || !weightInput || !birthDate || !heightCmHidden || !heightSquaredOut || !bmiOut || !bmiAgeOut || !hfaOut) {
        return;
    }

    const toNum = (value) => {
        const num = Number(value);
        return Number.isFinite(num) ? num : null;
    };

    const getAge = () => {
        if (!birthDate.value) {
            return null;
        }

        const date = new Date(birthDate.value);
        if (Number.isNaN(date.getTime())) {
            return null;
        }

        const today = new Date();
        let age = today.getFullYear() - date.getFullYear();
        const monthDiff = today.getMonth() - date.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < date.getDate())) {
            age -= 1;
        }

        return age >= 0 ? age : null;
    };

    const classifyBmiForAge = (bmi, age) => {
        if (bmi === null || age === null) {
            return 'Not enough data';
        }

        if (bmi < 16.0) return 'Severely Wasted';
        if (bmi < 17.0) return 'Wasted';
        if (bmi < 18.5) return 'Underweight';
        if (bmi < 25.0) return 'Normal';
        if (bmi < 30.0) return 'Overweight';
        return 'Obese';
    };

    const classifyHeightForAge = (heightM, age) => {
        if (heightM === null || age === null) {
            return 'Not enough data';
        }

        if (heightM < 1.20) return 'Severely Stunted';
        if (heightM < 1.30) return 'Stunted';
        if (heightM > 1.70) return 'Tall';
        return 'Normal';
    };

    const recalc = () => {
        const heightM = toNum(heightInput.value);
        const weightKg = toNum(weightInput.value);
        const age = getAge();

        if (!heightM || !weightKg || heightM <= 0 || weightKg <= 0 || heightM > 2.5) {
            heightCmHidden.value = '';
            heightSquaredOut.textContent = '-';
            bmiOut.textContent = '-';
            bmiAgeOut.textContent = 'Not enough data';
            hfaOut.textContent = classifyHeightForAge(heightM, age);
            return;
        }

        const heightCm = heightM * 100;
        heightCmHidden.value = heightCm.toFixed(2);

        const heightSquared = heightM * heightM;
        const bmi = weightKg / heightSquared;

        heightSquaredOut.textContent = heightSquared.toFixed(4);
        bmiOut.textContent = bmi.toFixed(2);
        bmiAgeOut.textContent = classifyBmiForAge(bmi, age);
        hfaOut.textContent = classifyHeightForAge(heightM, age);
    };

    heightInput.addEventListener('input', recalc);
    weightInput.addEventListener('input', recalc);
    birthDate.addEventListener('input', recalc);
    birthDate.addEventListener('change', recalc);
    recalc();
})();

(() => {
    const node = document.getElementById('currentDate');
    if (!node) {
        return;
    }

    node.textContent = new Date().toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
})();

(() => {
    const form = document.getElementById('studentForm');
    const reviewBtn = document.getElementById('reviewSubmitBtn');
    const cancelBtn = document.getElementById('cancelAddStudent');
    const modal = document.getElementById('confirmationModal');
    const closeBtn = document.getElementById('confirmCloseBtn');
    const editBtn = document.getElementById('confirmEditBtn');
    const submitBtn = document.getElementById('confirmSubmitBtn');
    const summary = document.getElementById('summaryContainer');

    if (!form || !reviewBtn || !modal || !closeBtn || !editBtn || !submitBtn || !summary) {
        return;
    }

    const byId = (id) => document.getElementById(id);

    const openModal = () => {
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
    };

    const closeModal = () => {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
    };

    const buildSummary = () => {
        const fullName = `${byId('proto_last_name')?.value || ''}, ${byId('proto_first_name')?.value || ''} ${byId('proto_middle_name')?.value || ''}`.trim();
        const assignedClass = byId('assignedClassDisplay')?.textContent || '-';

        const blocks = [
            {
                title: 'Student Information',
                rows: [
                    ['Full Name', fullName || '-'],
                    ['LRN', byId('proto_lrn')?.value || '-'],
                    ['Date of Birth', byId('birthDate')?.value || '-'],
                    ['Gender', byId('gender')?.value || '-'],
                    ['Grade & Section', assignedClass],
                ],
            },
            {
                title: 'Parent/Guardian Information',
                rows: [
                    ['Parent/Guardian', byId('proto_parent_guardian')?.value || 'Not provided'],
                    ['Contact Number', byId('proto_telephone_no')?.value || 'Not provided'],
                    ['Address', byId('proto_address')?.value || 'Not provided'],
                ],
            },
            {
                title: 'Health Data (Baseline)',
                rows: [
                    ['Weight', `${byId('weight')?.value || '-'} kg`],
                    ['Height', `${byId('height')?.value || '-'} m`],
                    ['(Height)^2', byId('heightSquared')?.textContent || '-'],
                    ['BMI', `${byId('bmiDisplay')?.textContent || '-'} kg/m^2`],
                    ['Nutritional Status', byId('nutriStatusDisplay')?.textContent || '-'],
                    ['Height-for-Age', byId('hfaDisplay')?.textContent || '-'],
                ],
            },
        ];

        summary.innerHTML = '';
        blocks.forEach((block) => {
            const card = document.createElement('div');
            card.className = 'summary-card';
            const rowsHtml = block.rows
                .map(([k, v]) => `<div class="summary-k">${k}:</div><div class="summary-v">${v}</div>`)
                .join('');
            card.innerHTML = `<h5>${block.title}</h5><div class="summary-grid">${rowsHtml}</div>`;
            summary.appendChild(card);
        });
    };

    reviewBtn.addEventListener('click', () => {
        if (!form.reportValidity()) {
            return;
        }

        buildSummary();
        openModal();
    });

    cancelBtn?.addEventListener('click', () => {
        form.reset();
        byId('heightSquared').textContent = '-';
        byId('bmiDisplay').textContent = '-';
        byId('nutriStatusDisplay').textContent = '-';
        byId('hfaDisplay').textContent = '-';
    });

    closeBtn.addEventListener('click', closeModal);
    editBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    submitBtn.addEventListener('click', () => {
        closeModal();
        form.requestSubmit();
    });
})();

(() => {
    const openButtons = Array.from(document.querySelectorAll('.js-profile-open'));
    const backdrop = document.getElementById('profileBackdrop');
    const closeBtn = document.getElementById('profileClose');
    const fillLink = document.getElementById('profileFillLink');

    if (!openButtons.length || !backdrop || !closeBtn || !fillLink) {
        return;
    }

    const setText = (id, value) => {
        const node = document.getElementById(id);
        if (node) {
            node.textContent = value && String(value).trim() !== '' ? String(value) : '-';
        }
    };

    const toNumber = (value) => {
        const num = Number(value);
        return Number.isFinite(num) ? num : null;
    };

    const computeBmi = (heightCm, weightKg) => {
        const height = toNumber(heightCm);
        const weight = toNumber(weightKg);
        if (!height || !weight || height <= 0 || weight <= 0) {
            return null;
        }

        const meters = height / 100;
        return weight / (meters * meters);
    };

    const renderTrendGraph = (baselineBmi, endlineBmi) => {
        const line = document.getElementById('pgTrendLine');
        const baseDot = document.getElementById('pgBaseDot');
        const endDot = document.getElementById('pgEndDot');
        const baseText = document.getElementById('pgBasePointText');
        const endText = document.getElementById('pgEndPointText');

        if (!line || !baseDot || !endDot || !baseText || !endText) {
            return;
        }

        const safeBase = baselineBmi ?? 0;
        const safeEnd = endlineBmi ?? safeBase;
        const maxBmi = Math.max(30, safeBase + 2, safeEnd + 2);
        const toY = (value) => {
            const ratio = Math.max(0, Math.min(1, value / maxBmi));
            return 140 - (ratio * 110);
        };

        const baseY = toY(safeBase);
        const endY = toY(safeEnd);

        line.setAttribute('points', `80,${baseY} 300,${endY}`);
        baseDot.setAttribute('cy', String(baseY));
        endDot.setAttribute('cy', String(endY));
        baseText.setAttribute('y', String(baseY - 10));
        endText.setAttribute('y', String(endY - 10));
        baseText.textContent = `Baseline ${baselineBmi ? baselineBmi.toFixed(1) : '-'}`;
        endText.textContent = `Endline ${endlineBmi ? endlineBmi.toFixed(1) : '-'}`;
    };

    const renderAttendanceBars = (attendanceByMonth) => {
        const barsWrap = document.getElementById('pgAttendanceBars');
        const latestNode = document.getElementById('pgAttendanceLatest');
        const totalNode = document.getElementById('pgAttendanceTotal');

        if (!barsWrap || !latestNode || !totalNode) {
            return;
        }

        const entries = Object.entries(attendanceByMonth || {})
            .filter(([, count]) => Number(count) >= 0)
            .sort(([a], [b]) => a.localeCompare(b));

        barsWrap.innerHTML = '';

        const chartEntries = entries.length ? entries.slice(-6) : [[new Date().toISOString().slice(0, 7), 0]];
        const maxCount = Math.max(1, ...chartEntries.map(([, count]) => Number(count) || 0));
        const total = chartEntries.reduce((sum, [, count]) => sum + (Number(count) || 0), 0);

        chartEntries.forEach(([month, count]) => {
            const value = Number(count) || 0;
            const monthLabel = month.slice(2);
            const height = Math.max(6, Math.round((value / maxCount) * 90));

            const col = document.createElement('div');
            col.className = 'attendance-col';
            col.innerHTML = `<div class="attendance-bar" style="height:${height}px"></div><div class="attendance-month">${monthLabel}</div><div class="attendance-val">${value}</div>`;
            barsWrap.appendChild(col);
        });

        const latest = chartEntries[chartEntries.length - 1];
        setText('pgAttendanceLatest', `${latest[0]} (${latest[1]} session${Number(latest[1]) === 1 ? '' : 's'})`);
        setText('pgAttendanceTotal', `${total}`);
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
        setText('pGrade', [record.grade_level, record.section].filter(Boolean).join(' / ') || '-');

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
        setText('psBmi', record.bmi_value || '-');
        setText('psBmiForAge', record.nutritional_status_bmi_for_age || '-');
        setText('psHeightForAge', record.nutritional_status_height_for_age || '-');

        const baselineSnapshot = record.baseline_snapshot || {};
        const endlineSnapshot = record.endline_snapshot || {};
        const examData = record.examination || {};

        const baselineHeight = baselineSnapshot.height_cm ?? record.height_cm;
        const baselineWeight = baselineSnapshot.weight_kg ?? record.weight_kg;
        const endlineHeight = endlineSnapshot.height_cm ?? examData.height_cm ?? record.height_cm;
        const endlineWeight = endlineSnapshot.weight_kg ?? examData.weight_kg ?? record.weight_kg;

        const baselineBmi = computeBmi(baselineHeight, baselineWeight);
        const endlineBmi = computeBmi(endlineHeight, endlineWeight);

        setText('pgBaseBmi', baselineBmi ? baselineBmi.toFixed(1) : '-');
        setText('pgEndBmi', endlineBmi ? endlineBmi.toFixed(1) : '-');
        renderTrendGraph(baselineBmi, endlineBmi);
        renderAttendanceBars(record.attendance_by_month || {});

        setText('paStatus', examined ? 'Nurse examination details are available.' : 'Pending nurse review.');
        setText('ptNext', examined ? 'Record completed by nurse.' : 'Nurse examination pending.');

        fillLink.setAttribute('href', route || '#');
        backdrop.classList.add('open');
        backdrop.setAttribute('aria-hidden', 'false');
    };

    openButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            let record = {};
            try {
                record = JSON.parse(btn.getAttribute('data-record') || '{}');
            } catch (_err) {
                record = {};
            }
            openProfile(record, btn.getAttribute('data-route') || '#');
        });
    });

    const closeProfile = () => {
        backdrop.classList.remove('open');
        backdrop.setAttribute('aria-hidden', 'true');
    };

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
