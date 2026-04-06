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
        .preview-details{display:inline-block}
        .preview-summary{cursor:pointer;color:#166534;font-weight:700;font-size:.72rem;list-style:none}
        .preview-summary::-webkit-details-marker{display:none}
        .preview-card{margin-top:8px;padding:10px;border:1px solid var(--border);border-radius:8px;background:#f7fbf8;min-width:320px}
        .preview-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px 10px}
        .preview-item{font-size:.72rem;color:#375948}
        .preview-item b{color:#1f3d2b}
        .preview-open-btn{border:1px solid #15803d;background:#fff;color:#166534;font-weight:700;border-radius:8px;padding:6px 10px;cursor:pointer;font-size:.72rem}
        .preview-open-btn:hover{background:#f0fdf4}
        .preview-modal-backdrop{position:fixed;inset:0;background:rgba(13,31,20,.48);display:none;align-items:center;justify-content:center;z-index:1300}
        .preview-modal-backdrop.open{display:flex}
        .preview-modal-card{width:min(920px,94vw);max-height:88vh;overflow:auto;background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 20px 40px rgba(13,31,20,.25);padding:16px}
        .preview-modal-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}
        .preview-modal-title{font-family:'DM Serif Display',serif;font-size:1.2rem;color:#14532d}
        .preview-modal-close{border:none;background:#fee2e2;color:#991b1b;border-radius:8px;width:30px;height:30px;cursor:pointer;font-weight:700}
        .preview-card-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
        .preview-card-item{border:1px solid var(--border);border-radius:9px;padding:8px 10px;background:#fafdfb}
        .preview-card-label{font-size:.64rem;letter-spacing:.08em;text-transform:uppercase;color:#6f8c7a;font-weight:700}
        .preview-card-value{font-size:.8rem;color:#143021;margin-top:2px}

        @media (max-width:980px){.stats{grid-template-columns:1fr}.grid{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}}
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
        <h1 class="title">Class Adviser <i>Encoding Workspace</i></h1>
        <p class="sub">School Health Card prototype workflow for adviser submission and nurse follow-up.</p>

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
            $prototypeRecords = session('school_health_card_records', []);
        @endphp

        <section id="prototype-saved-panel" class="card section section-panel" style="margin-top:12px;">
            <h3>Saved School Health Card Submissions</h3>
            <table>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>LRN</th>
                        <th>Grade Level</th>
                        <th>Height</th>
                        <th>Weight</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($prototypeRecords as $prototypeRecord)
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
                                @php
                                    $formPayload = [
                                        'Student Name' => $fullName,
                                        'Last Name' => $prototypeRecord['last_name'] ?? '-',
                                        'First Name' => $prototypeRecord['first_name'] ?? '-',
                                        'Middle Name' => $prototypeRecord['middle_name'] ?? '-',
                                        'LRN' => $prototypeRecord['lrn'] ?? '-',
                                        'Birth Month' => $prototypeRecord['birth_month'] ?? '-',
                                        'Birth Day' => $prototypeRecord['birth_day'] ?? '-',
                                        'Birth Year' => $prototypeRecord['birth_year'] ?? '-',
                                        'Birthplace' => $prototypeRecord['birthplace'] ?? '-',
                                        'Parent/Guardian' => $prototypeRecord['parent_guardian'] ?? '-',
                                        'Address' => $prototypeRecord['address'] ?? '-',
                                        'School ID' => $prototypeRecord['school_id'] ?? '-',
                                        'Region' => $prototypeRecord['region'] ?? '-',
                                        'Division' => $prototypeRecord['division'] ?? '-',
                                        'Telephone No.' => $prototypeRecord['telephone_no'] ?? '-',
                                        'Height (cm)' => $prototypeRecord['height_cm'] ?? '-',
                                        'Weight (kg)' => $prototypeRecord['weight_kg'] ?? '-',
                                        'Grade Level' => $prototypeRecord['grade_level'] ?? '-',
                                    ];
                                @endphp
                                <button type="button" class="preview-open-btn js-preview-open" data-form='@json($formPayload)'>View Form</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="muted">No School Health Card prototype submissions yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section id="prototype-form-panel" class="card section section-panel active" style="margin-top:12px;">
            <h3>School Health Card Prototype (Session-Based)</h3>
            <p class="sub" style="margin-top:0;margin-bottom:10px;">This prototype sends adviser entries to the School Nurse workflow using Laravel Session only.</p>
            <form method="POST" action="{{ route('adviser.store') }}" autocomplete="off">
                @csrf
                <div class="form-grid">
                    <div class="field"><label for="proto_last_name">Last Name</label><input id="proto_last_name" name="last_name" type="text"></div>
                    <div class="field"><label for="proto_first_name">First Name</label><input id="proto_first_name" name="first_name" type="text"></div>
                    <div class="field"><label for="proto_middle_name">Middle Name</label><input id="proto_middle_name" name="middle_name" type="text"></div>

                    <div class="field"><label for="proto_lrn">LRN</label><input id="proto_lrn" name="lrn" type="text"></div>
                    <div class="field"><label for="proto_birth_month">Birth Month</label><input id="proto_birth_month" name="birth_month" type="text" placeholder="MM"></div>
                    <div class="field"><label for="proto_birth_day">Birth Day</label><input id="proto_birth_day" name="birth_day" type="text" placeholder="DD"></div>
                    <div class="field"><label for="proto_birth_year">Birth Year</label><input id="proto_birth_year" name="birth_year" type="text" placeholder="YYYY"></div>

                    <div class="field full"><label for="proto_birthplace">Birthplace</label><input id="proto_birthplace" name="birthplace" type="text"></div>
                    <div class="field full"><label for="proto_parent_guardian">Parent/Guardian</label><input id="proto_parent_guardian" name="parent_guardian" type="text"></div>
                    <div class="field full"><label for="proto_address">Address</label><input id="proto_address" name="address" type="text"></div>

                    <div class="field"><label for="proto_school_id">School ID</label><input id="proto_school_id" name="school_id" type="text"></div>
                    <div class="field"><label for="proto_region">Region</label><input id="proto_region" name="region" type="text"></div>
                    <div class="field"><label for="proto_division">Division</label><input id="proto_division" name="division" type="text"></div>
                    <div class="field"><label for="proto_telephone_no">Telephone No.</label><input id="proto_telephone_no" name="telephone_no" type="text"></div>

                    <div class="field"><label for="proto_height_cm">Height (cm)</label><input id="proto_height_cm" name="height_cm" type="text"></div>
                    <div class="field"><label for="proto_weight_kg">Weight (kg)</label><input id="proto_weight_kg" name="weight_kg" type="text"></div>
                    <div class="field full">
                        <label for="proto_grade_level">Grade Level</label>
                        <select id="proto_grade_level" name="grade_level">
                            <option value="">Select Grade Level</option>
                            <option>Kinder/SPED</option>
                            <option>Grade 1/SPED</option>
                            <option>Grade 2/SPED</option>
                            <option>Grade 3/SPED</option>
                            <option>Grade 4/SPED</option>
                            <option>Grade 5/SPED</option>
                            <option>Grade 6/SPED</option>
                            <option>Grade 7/SPED</option>
                            <option>Grade 8/SPED</option>
                            <option>Grade 9/SPED</option>
                            <option>Grade 10/SPED</option>
                            <option>Grade 11/SPED</option>
                            <option>Grade 12/SPED</option>
                        </select>
                    </div>

                    <div class="field full" style="display:flex;flex-direction:row;justify-content:flex-end;align-items:center;">
                        <button type="submit" class="btn">Submit</button>
                    </div>
                </div>
            </form>
        </section>
    </div>
</div>
<div class="preview-modal-backdrop" id="previewModalBackdrop" aria-hidden="true">
    <div class="preview-modal-card" role="dialog" aria-modal="true" aria-label="Submitted Form Preview">
        <div class="preview-modal-head">
            <div class="preview-modal-title">Submitted School Health Card</div>
            <button type="button" class="preview-modal-close" id="previewModalClose" aria-label="Close">x</button>
        </div>
        <div class="preview-card-grid" id="previewCardGrid"></div>
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
    const openButtons = Array.from(document.querySelectorAll('.js-preview-open'));
    const modal = document.getElementById('previewModalBackdrop');
    const closeBtn = document.getElementById('previewModalClose');
    const grid = document.getElementById('previewCardGrid');

    if (!openButtons.length || !modal || !closeBtn || !grid) {
        return;
    }

    const closeModal = () => {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
    };

    openButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            let data = {};
            try {
                data = JSON.parse(btn.getAttribute('data-form') || '{}');
            } catch (_err) {
                data = {};
            }

            grid.innerHTML = '';
            Object.entries(data).forEach(([label, value]) => {
                const item = document.createElement('div');
                item.className = 'preview-card-item';
                item.innerHTML = '<div class="preview-card-label"></div><div class="preview-card-value"></div>';
                item.querySelector('.preview-card-label').textContent = label;
                item.querySelector('.preview-card-value').textContent = String(value ?? '-');
                grid.appendChild(item);
            });

            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
        });
    });

    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });
})();
</script>
</body>
</html>
