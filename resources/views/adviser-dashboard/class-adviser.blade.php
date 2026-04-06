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

        @media (max-width:980px){.stats{grid-template-columns:1fr}.grid{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}}
        @media (max-width:780px){.sidebar{display:none}.main{margin-left:0}}
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="sb-logo"><img src="{{ asset('images/lusog-logo.png') }}" alt="LUSOG Logo"></div>
    <nav class="sb-nav">
        <a href="#" class="sb-link active">
            <svg class="sb-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
            <span class="sb-link-label">Baseline and Endline Encoding</span>
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
        <p class="sub">Encode baseline and endline anthropometric data. BMI and nutritional status are computed automatically.</p>

        @if (session('success'))
            <div class="flash flash-ok">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="flash flash-err">Incomplete fields detected. Please complete all required entries before submitting.</div>
        @endif

        <section class="stats">
            <article class="card stat"><b>{{ $stats['encoded_today'] ?? 0 }}</b><span>Students encoded today</span></article>
            <article class="card stat"><b>{{ $stats['avg_bmi'] ?? '0.0' }}</b><span>Average BMI from records</span></article>
            <article class="card stat"><b>{{ $stats['flagged'] ?? 0 }}</b><span>Wasted or severely wasted</span></article>
        </section>

        <section class="grid">
            <article class="card section">
                <h3>Baseline Encoding (Day 1)</h3>
                <form method="POST" action="{{ route('class-adviser.health-records.baseline.store') }}" autocomplete="off">
                    @csrf
                    <div class="form-grid">
                        <div class="field full"><label for="student_name">Student Name</label><input id="student_name" name="student_name" type="text" required value="{{ old('student_name') }}"></div>
                        <div class="field"><label for="student_id">Student ID</label><input id="student_id" name="student_id" type="text" required value="{{ old('student_id') }}"></div>
                        <div class="field"><label for="section">Grade and Section</label><input id="section" name="section" type="text" required value="{{ old('section') }}"></div>
                        <div class="field"><label for="age">Age</label><input id="age" name="age" type="number" min="2" max="25" required value="{{ old('age') }}"></div>
                        <div class="field"><label for="height_cm">Height (cm)</label><input id="height_cm" name="height_cm" type="number" step="0.01" min="50" max="250" required value="{{ old('height_cm') }}"></div>
                        <div class="field"><label for="weight_kg">Weight (kg)</label><input id="weight_kg" name="weight_kg" type="number" step="0.01" min="5" max="300" required value="{{ old('weight_kg') }}"></div>
                        <div class="field"><label for="recorded_at">Date Measured</label><input id="recorded_at" name="recorded_at" type="date" value="{{ old('recorded_at', now()->toDateString()) }}"></div>
                        <div class="field full"><button type="submit" class="btn">Save Baseline and Compute BMI</button></div>
                    </div>
                </form>
            </article>

            <article class="card section">
                <h3>Endline Encoding (After 120 Days)</h3>
                <form method="POST" action="#" id="endlineForm" autocomplete="off">
                    @csrf
                    <div class="form-grid">
                        <div class="field full">
                            <label for="record_id">Beneficiary</label>
                            <select id="record_id" name="record_id" required>
                                <option value="">Select beneficiary</option>
                                @foreach (($records ?? collect()) as $record)
                                    <option value="{{ $record->id }}" data-route="{{ route('class-adviser.health-records.endline.store', $record) }}" {{ old('record_id') == $record->id ? 'selected' : '' }}>
                                        {{ $record->student_name }} - {{ $record->section }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field"><label for="endline_age">Age</label><input id="endline_age" name="age" type="number" min="2" max="25" required value="{{ old('age') }}"></div>
                        <div class="field"><label for="endline_height_cm">Height (cm)</label><input id="endline_height_cm" name="height_cm" type="number" step="0.01" min="50" max="250" required value="{{ old('height_cm') }}"></div>
                        <div class="field"><label for="endline_weight_kg">Weight (kg)</label><input id="endline_weight_kg" name="weight_kg" type="number" step="0.01" min="5" max="300" required value="{{ old('weight_kg') }}"></div>
                        <div class="field"><label for="endline_recorded_at">Date Measured</label><input id="endline_recorded_at" name="recorded_at" type="date" value="{{ old('recorded_at', now()->toDateString()) }}"></div>
                        <div class="field full"><button type="submit" class="btn">Save Endline and Compare</button></div>
                    </div>
                </form>
                <p class="sub" style="margin-top:10px;">System compares baseline versus endline BMI and updates nutritional status automatically.</p>
            </article>
        </section>

        <section class="card section" style="margin-top:12px;">
            <h3>Saved Records</h3>
            <table>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Section</th>
                        <th>Baseline BMI</th>
                        <th>Baseline Status</th>
                        <th>Endline BMI</th>
                        <th>Endline Status</th>
                        <th>BMI Change</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($records ?? collect()) as $record)
                        @php
                            $baselineBmi = $record->baseline_bmi_value;
                            $endlineBmi = $record->endline_bmi_value;
                            $change = null;
                            if ($baselineBmi !== null && $endlineBmi !== null) {
                                $change = round((float) $endlineBmi - (float) $baselineBmi, 2);
                            }

                            $statusClass = 'ok';
                            $statusText = strtolower((string) ($record->nutritional_status ?? 'normal'));
                            if (str_contains($statusText, 'severe')) {
                                $statusClass = 'risk';
                            } elseif (str_contains($statusText, 'wast')) {
                                $statusClass = 'warn';
                            } elseif (str_contains($statusText, 'over')) {
                                $statusClass = 'over';
                            }
                        @endphp
                        <tr>
                            <td>{{ $record->student_name }}</td>
                            <td>{{ $record->section }}</td>
                            <td>{{ $baselineBmi !== null ? number_format((float) $baselineBmi, 2) : '-' }}</td>
                            <td><span class="badge {{ $statusClass }}">{{ $record->baseline_nutritional_status ?? '-' }}</span></td>
                            <td>{{ $endlineBmi !== null ? number_format((float) $endlineBmi, 2) : '-' }}</td>
                            <td><span class="badge {{ $statusClass }}">{{ $record->endline_nutritional_status ?? '-' }}</span></td>
                            <td>
                                @if ($change === null)
                                    <span class="muted">Pending endline</span>
                                @elseif ($change > 0)
                                    +{{ number_format($change, 2) }}
                                @else
                                    {{ number_format($change, 2) }}
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="muted">No encoded records yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>
</div>

<script>
(() => {
    const endlineForm = document.getElementById('endlineForm');
    const recordSelect = document.getElementById('record_id');

    if (!endlineForm || !recordSelect) {
        return;
    }

    endlineForm.addEventListener('submit', (event) => {
        const selectedOption = recordSelect.options[recordSelect.selectedIndex];
        const action = selectedOption ? selectedOption.getAttribute('data-route') : '';

        if (!action) {
            event.preventDefault();
            return;
        }

        endlineForm.setAttribute('action', action);
    });
})();
</script>
</body>
</html>
