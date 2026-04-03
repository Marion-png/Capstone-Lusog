<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}?v=2">
    <link rel="shortcut icon" href="{{ asset('images/lusog-logo.png') }}?v=2">
    <title>Class Adviser Dashboard - LUSOG</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        :root{--bg:#f7f8f5;--card:#fff;--border:#e4ece7;--text:#0d1f14;--muted:#6f8c7a;--g900:#14532d;--g700:#15803d;--g500:#22c55e;--g300:#86efac;--g100:#dcfce7;--g50:#f0fdf4;--amber:#f59e0b;--red:#ef4444;--blue:#3b82f6;--sidebar:248px;--shadow:0 1px 4px rgba(5,46,22,.06),0 4px 16px rgba(5,46,22,.06);--radius-sm:10px}
        html,body{height:100%;font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);overflow:hidden}
        .sidebar{position:fixed;left:0;top:0;bottom:0;width:var(--sidebar);background:var(--g900);display:flex;flex-direction:column;overflow:hidden}
        .sidebar::after{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 120% 40% at 50% 100%,rgba(34,197,94,.18) 0%,transparent 70%),radial-gradient(ellipse 80% 30% at 80% 0%,rgba(74,222,128,.1) 0%,transparent 60%);pointer-events:none}
        .sb-grid{position:absolute;inset:0;background-image:linear-gradient(rgba(134,239,172,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(134,239,172,.05) 1px,transparent 1px);background-size:28px 28px}
        .sb-logo{padding:20px 20px 18px;position:relative;z-index:2;border-bottom:1px solid rgba(255,255,255,.08);display:flex;justify-content:center}
        .sb-logo-full{width:176px;max-width:100%;height:auto;display:block}
        .sb-nav{padding:16px 12px;flex:1;position:relative;z-index:2}
        .sb-section-label{font-size:.6rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:rgba(134,239,172,.5);padding:0 8px;margin:0 0 8px}
        .sb-link{display:flex;align-items:center;gap:10px;color:rgba(255,255,255,.6);text-decoration:none;font-size:.83rem;font-weight:500;padding:10px 12px;border-radius:var(--radius-sm);margin-bottom:2px;transition:background .15s,color .15s}
        .sb-link:hover{background:rgba(255,255,255,.08);color:rgba(255,255,255,.9)}
        .sb-link.active{background:rgba(34,197,94,.18);color:var(--g300)}
        html{scroll-behavior:smooth}
        .sb-user{padding:14px 16px;border-top:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:11px;position:relative;z-index:2}
        .sb-avatar{width:34px;height:34px;border-radius:50%;background:#16a34a;display:grid;place-items:center;font-weight:700;font-size:.8rem;color:#fff;flex-shrink:0}
        .sb-user-name{font-size:.8rem;font-weight:600;color:#fff;line-height:1.2}
        .sb-user-role{font-size:.68rem;color:var(--g300)}
        .sb-logout{margin-left:auto;background:none;border:none;color:rgba(255,255,255,.35);cursor:pointer;padding:4px;border-radius:6px;transition:color .15s,background .15s;display:grid;place-items:center}
        .sb-logout:hover{color:var(--red);background:rgba(239,68,68,.1)}
        .sb-logout svg{width:15px;height:15px}

        .main{margin-left:var(--sidebar);height:100vh;display:flex;flex-direction:column}
        .top{height:64px;background:#fff;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 24px}
        .crumb{font-size:.82rem;color:var(--muted)}
        .chip{font-size:.75rem;background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;padding:6px 12px;border-radius:999px}
        .content{padding:24px;overflow:auto}
        .title{font-family:'DM Serif Display',serif;font-size:1.7rem}
        .title i{color:#15803d}
        .sub{color:var(--muted);font-size:.85rem;margin-top:4px}

        .stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:18px 0}
        .card{background:var(--card);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow)}
        .stat{padding:14px}
        .stat b{font-family:'DM Serif Display',serif;font-size:1.5rem;display:block}
        .stat span{font-size:.72rem;color:var(--muted)}

        .grid{display:grid;grid-template-columns:1.2fr 1fr;gap:12px}
        .section{padding:14px}
        .section h3{font-size:.82rem;color:#3d5c47;margin-bottom:10px;text-transform:uppercase;letter-spacing:.08em}

        .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
        .field{display:flex;flex-direction:column;gap:6px}
        .field label{font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:700}
        .field input{height:38px;border:1px solid var(--border);border-radius:8px;padding:0 10px;font:inherit}
        .field input:focus{outline:none;border-color:#7fbeaa;box-shadow:0 0 0 3px rgba(63,147,114,.14)}
        .full{grid-column:1 / -1}
        .btn{border:none;background:#15803d;color:#fff;font-weight:700;border-radius:8px;padding:9px 12px;cursor:pointer}
        .btn:hover{background:#166534}

        .result-box{margin-top:12px;background:#f8faf9;border:1px solid var(--border);border-radius:10px;padding:10px}
        .result-row{display:flex;justify-content:space-between;gap:8px;font-size:.78rem;padding:5px 0;border-bottom:1px dashed #dce8e2}
        .result-row:last-child{border-bottom:none}
        .badge{display:inline-block;padding:3px 9px;border-radius:999px;font-size:.68rem;font-weight:700}
        .ok{background:var(--g100);color:#166534}
        .warn{background:#fef3c7;color:#92400e}
        .risk{background:#fee2e2;color:#991b1b}

        table{width:100%;border-collapse:collapse}
        th,td{padding:10px;border-bottom:1px solid var(--border);font-size:.78rem;text-align:left}
        th{font-size:.67rem;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);background:#f7faf8}

        .tasks li{list-style:none;font-size:.8rem;color:#244233;padding:8px 0;border-bottom:1px solid var(--border)}
        .tasks li:last-child{border-bottom:none}

        @media (max-width:980px){.stats{grid-template-columns:1fr}.grid{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}}
        @media (max-width:780px){.sidebar{display:none}.main{margin-left:0}}
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="sb-grid"></div>
    <div class="sb-logo">
        <img src="{{ asset('images/lusog-logo.png') }}" alt="LUSOG Logo" class="sb-logo-full">
    </div>
    <nav class="sb-nav">
        <div class="sb-section-label">Class Adviser</div>
        <a href="#feature-input" class="sb-link active">Student Record</a>
    </nav>
    <div class="sb-user">
        <div class="sb-avatar">{{ substr(auth()->user()->name ?? 'CA',0,2) }}</div>
        <div>
            <div class="sb-user-name">{{ auth()->user()->name ?? 'Class Adviser' }}</div>
            <div class="sb-user-role">Class Adviser - DCNHS</div>
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
    <header class="top"><div class="crumb">Dashboard > Class Adviser</div><div class="chip">Early Risk Monitoring</div></header>
    <div class="content">
        <h1 class="title">Class Adviser <i>Monitoring Workspace</i></h1>
        <p class="sub">Capture learner measurements, compute BMI immediately, and auto-flag potentially wasted learners for referral.</p>

        <section class="stats">
            <article class="card stat"><b id="encodedCount">0</b><span>Students encoded today</span></article>
            <article class="card stat"><b id="avgBmi">0.0</b><span>Average BMI from entries</span></article>
            <article class="card stat"><b id="flaggedCount">0</b><span>Auto-flagged wasted students</span></article>
        </section>

        <section class="grid">
            <article class="card section" id="feature-input">
                <h3>Input Student Height, Weight, Age</h3>
                <form id="bmiForm" autocomplete="off">
                    <div class="form-grid">
                        <div class="field full">
                            <label for="studentName">Student Name</label>
                            <input type="text" id="studentName" required placeholder="Example: Juan Dela Cruz">
                        </div>
                        <div class="field">
                            <label for="age">Age</label>
                            <input type="number" id="age" min="5" max="25" required placeholder="13">
                        </div>
                        <div class="field">
                            <label for="grade">Grade and Section</label>
                            <input type="text" id="grade" required placeholder="Grade 7 - Rizal">
                        </div>
                        <div class="field">
                            <label for="heightCm">Height (cm)</label>
                            <input type="number" id="heightCm" min="80" max="220" step="0.1" required placeholder="150.0">
                        </div>
                        <div class="field">
                            <label for="weightKg">Weight (kg)</label>
                            <input type="number" id="weightKg" min="10" max="200" step="0.1" required placeholder="40.0">
                        </div>
                        <div class="field full">
                            <button type="submit" class="btn">Compute BMI and Evaluate</button>
                        </div>
                    </div>
                </form>

                <div class="result-box" id="resultBox" style="display:none;">
                    <div class="result-row"><span>Computed BMI</span><strong id="bmiResult">-</strong></div>
                    <div class="result-row"><span>Nutrition Status</span><strong id="statusResult">-</strong></div>
                    <div class="result-row"><span>System Action</span><strong id="actionResult">-</strong></div>
                </div>
            </article>

            <article class="card section" id="feature-bmi">
                <h3>Class Adviser Responsibilities</h3>
                <ul class="tasks">
                    <li>Input learner height, weight, and age during screening.</li>
                    <li>Track feeding session participation and attendance follow-ups.</li>
                    <li>Manage enrolled wasted students and monitor class-level progress.</li>
                </ul>
            </article>
        </section>

        <section class="card section" style="margin-top:12px;" id="feature-flagged">
            <h3>Computed BMI Student List</h3>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Age</th>
                        <th>Grade and Section</th>
                        <th>BMI</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="flagTableBody">
                    <tr id="emptyRow">
                        <td colspan="6" style="color:var(--muted)">No BMI entries yet. Compute BMI to add records here.</td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</div>

<script>
    const bmiForm = document.getElementById('bmiForm');
    const flagTableBody = document.getElementById('flagTableBody');
    const emptyRow = document.getElementById('emptyRow');
    const resultBox = document.getElementById('resultBox');
    const encodedCount = document.getElementById('encodedCount');
    const flaggedCount = document.getElementById('flaggedCount');
    const avgBmi = document.getElementById('avgBmi');

    let totalEntries = 0;
    let totalBmi = 0;
    let totalFlagged = 0;

    function classifyBmi(bmiValue) {
        if (bmiValue < 16) {
            return { label: 'Severely Wasted', badge: 'risk', action: 'Immediate nurse referral required' };
        }
        if (bmiValue < 18.5) {
            return { label: 'Wasted', badge: 'warn', action: 'Flag for feeding and nurse review' };
        }
        return { label: 'Normal', badge: 'ok', action: 'Continue regular monitoring' };
    }

    bmiForm.addEventListener('submit', function (event) {
        event.preventDefault();

        const name = document.getElementById('studentName').value.trim();
        const age = Number(document.getElementById('age').value);
        const grade = document.getElementById('grade').value.trim();
        const heightCm = Number(document.getElementById('heightCm').value);
        const weightKg = Number(document.getElementById('weightKg').value);

        if (!name || !age || !grade || !heightCm || !weightKg) {
            return;
        }

        const heightM = heightCm / 100;
        const bmi = weightKg / (heightM * heightM);
        const roundedBmi = Number(bmi.toFixed(1));
        const result = classifyBmi(roundedBmi);

        totalEntries += 1;
        totalBmi += roundedBmi;
        encodedCount.textContent = totalEntries;
        avgBmi.textContent = (totalBmi / totalEntries).toFixed(1);

        document.getElementById('bmiResult').textContent = roundedBmi.toFixed(1);
        document.getElementById('statusResult').innerHTML = '<span class="badge ' + result.badge + '">' + result.label + '</span>';
        document.getElementById('actionResult').textContent = result.action;
        resultBox.style.display = 'block';

        if (emptyRow) {
            emptyRow.remove();
        }

        const row = document.createElement('tr');
        row.innerHTML =
            '<td>' + name + '</td>' +
            '<td>' + age + '</td>' +
            '<td>' + grade + '</td>' +
            '<td>' + roundedBmi.toFixed(1) + '</td>' +
            '<td><span class="badge ' + result.badge + '">' + result.label + '</span></td>' +
            '<td>' + result.action + '</td>';

        flagTableBody.prepend(row);

        if (result.label === 'Wasted' || result.label === 'Severely Wasted') {
            totalFlagged += 1;
            flaggedCount.textContent = totalFlagged;
        }

        bmiForm.reset();
        document.getElementById('studentName').focus();
    });
</script>
</body>
</html>
