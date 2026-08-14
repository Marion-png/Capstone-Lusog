<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>System Admin Dashboard - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        :root{--bg:#F6F9F7;--card:#fff;--border:#DCE8E0;--text:#1F2D25;--muted:#6B7C72;--g950:#0A3D22;--g900:#126B3A;--g800:#14653C;--g700:#1F8A4C;--g600:#1F8A4C;--g500:#43A866;--g300:#BFE3CC;--g200:#C4E4D0;--g100:#E7F5EC;--g50:#F2FAF5;--red:#D95C5C;--sidebar-w:248px;--sidebar-collapsed-w:76px;--topbar-h:64px;--shadow:0 1px 4px rgba(5,46,22,.06),0 4px 16px rgba(5,46,22,.06)}
        html,body{height:100%;font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);overflow:hidden}
        .sidebar{position:fixed;left:0;top:0;bottom:0;width:var(--sidebar-collapsed-w);background:var(--g900);display:flex;flex-direction:column;z-index:100;overflow:hidden;transition:width .24s ease}
.sidebar:hover, .sidebar.sb-pin {width:var(--sidebar-w)}
        .sidebar::after{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 120% 40% at 50% 100%, rgba(34,197,94,.18) 0%, transparent 70%),radial-gradient(ellipse 80% 30% at 80% 0%, rgba(74,222,128,.1) 0%, transparent 60%);pointer-events:none}
        .sb-grid{position:absolute;inset:0;background-image:linear-gradient(rgba(134,239,172,.05) 1px, transparent 1px),linear-gradient(90deg, rgba(134,239,172,.05) 1px, transparent 1px);background-size:28px 28px;pointer-events:none}
        .sb-logo{padding:14px 10px;position:relative;z-index:2;border-bottom:1px solid rgba(255,255,255,.08);display:flex;justify-content:center;transition:padding .24s ease}
        .sb-logo-full{width:48px;max-width:100%;height:auto;display:block;transition:width .24s ease}
.sidebar:hover .sb-logo, .sidebar.sb-pin .sb-logo {padding:20px 20px 18px}
.sidebar:hover .sb-logo-full, .sidebar.sb-pin .sb-logo-full {width:176px}
        .sb-nav{flex:1;overflow-y:auto;padding:16px 8px;position:relative;z-index:2;scrollbar-width:none;transition:padding .24s ease}
.sidebar:hover .sb-nav, .sidebar.sb-pin .sb-nav {padding:16px 12px}
        .sb-nav::-webkit-scrollbar{display:none}
        .sb-section-label{font-size:.6rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:rgba(134,239,172,.5);padding:0 8px;margin:20px 0 8px; white-space: nowrap; transition: font-size .24s ease, margin .24s ease, opacity .18s ease; }
        .sb-section-label:first-child{margin-top:0}
        .sb-link{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;text-decoration:none;color:rgba(255,255,255,.6);font-size:.83rem;font-weight:500;transition:background .15s,color .15s, padding .24s ease, gap .24s ease, font-size .24s ease;margin-bottom:2px;white-space:nowrap;overflow:hidden}
        .sb-link:hover{background:rgba(255,255,255,.08);color:rgba(255,255,255,.9)}
        .sb-link.active{background:rgba(34,197,94,.18);color:var(--g300)}
        .sb-link svg{width:16px;height:16px;flex-shrink:0}
        .sidebar:not(:hover):not(.sb-pin) .sb-section-label { font-size: 0; margin: 0; opacity: 0; }
        .sidebar:not(:hover):not(.sb-pin) .sb-link { font-size: 0; padding: 10px 22px; gap: 0; }
        .sb-user{padding:14px 16px;border-top:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:11px;position:relative;z-index:2; transition: padding .24s ease, gap .24s ease; }
        .sb-avatar{width:34px;height:34px;border-radius:50%;background:var(--g600);display:grid;place-items:center;font-size:.8rem;font-weight:700;color:#fff;flex-shrink:0}
        .sb-user-meta{min-width:0; max-width: 170px; overflow: hidden; white-space: nowrap; transition: max-width .24s ease, opacity .18s ease; }
        .sb-user-name{font-size:.8rem;font-weight:600;color:#fff;line-height:1.2}
        .sb-user-role{font-size:.68rem;color:var(--g300)}
        .sb-logout{margin-left:auto;background:none;border:none;color:rgba(255,255,255,.35);cursor:pointer;padding:4px;border-radius:6px;transition: color .15s, background .15s, max-width .24s ease, padding .24s ease, opacity .18s ease;display:grid;place-items:center; max-width: 30px; overflow: hidden; }
        .sb-logout:hover{color:var(--red);background:rgba(239,68,68,.1)}
        .sb-logout svg{width:15px;height:15px}
        .sidebar:not(:hover):not(.sb-pin) .sb-user { padding: 14px 13px; gap: 0; }
        .sidebar:not(:hover):not(.sb-pin) .sb-user-meta { max-width: 0; opacity: 0; }
        .sidebar:not(:hover):not(.sb-pin) .sb-logout { max-width: 0; padding: 0; opacity: 0; }
        .main{margin-left:var(--sidebar-collapsed-w);height:100vh;display:flex;flex-direction:column;overflow:hidden;transition:margin-left .24s ease}
.sidebar:hover ~ .main, .sidebar.sb-pin ~ .main {margin-left:var(--sidebar-w)}
        .topbar{height:var(--topbar-h);background:#fff;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 24px}
        .topbar-breadcrumb{font-size:.82rem;color:#6B7C72}
        .topbar-chip{font-size:.75rem;background:#F2FAF5;border:1px solid #C4E4D0;color:#1F8A4C;padding:6px 12px;border-radius:999px}
        .content{padding:24px;overflow:auto}
        .title{font-family:'DM Serif Display',serif;font-size:1.7rem}.title i{color:#1F8A4C}
        .sub{color:var(--muted);font-size:.85rem;margin-top:4px}
        .stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:18px 0}
        .card{background:var(--card);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow)}
        .stat{padding:14px}.stat b{font-family:'DM Serif Display',serif;font-size:1.5rem;display:block}.stat span{font-size:.72rem;color:var(--muted)}
        .grid{display:grid;grid-template-columns:1.1fr 1fr;gap:12px}
        .section{padding:14px}.section h3{font-size:.82rem;color:#3E5348;margin-bottom:10px;text-transform:uppercase;letter-spacing:.08em}
        table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid var(--border);font-size:.78rem;text-align:left}th{font-size:.67rem;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);background:#f7faf8}
        .tag{display:inline-block;padding:3px 8px;border-radius:999px;font-size:.67rem;font-weight:700}.ok{background:var(--g100);color:#14653C}.warn{background:#FDF4E2;color:#8A5A06}.bad{background:#FCECEC;color:var(--red)}
        .row{display:flex;justify-content:space-between;align-items:center;padding:10px;border:1px solid var(--border);border-radius:10px;margin-bottom:8px;font-size:.8rem}
        .btn{font-size:.72rem;background:#fff;border:1px solid var(--border);border-radius:8px;padding:6px 10px;color:#2f4f42}
        .btn-danger{background:#fff;color:#b91c1c;border:1px solid #fecaca}
        .flash{padding:10px 12px;border-radius:10px;font-size:.8rem;margin-top:12px}
        .flash-ok{background:#E7F5EC;color:#14653C;border:1px solid #BFE3CC}
        .flash-err{background:#FCECEC;color:#A32B2B;border:1px solid #fecaca}
        @media (max-width:980px){.stats{grid-template-columns:repeat(2,1fr)}.grid{grid-template-columns:1fr}}
        @media (max-width:780px){:root{--sidebar-w:0px;--sidebar-collapsed-w:0px}.sidebar{display:none}.main{margin-left:0}}
    </style>
    {{-- One shared palette for pages not yet on lusog-theme.css. Loaded
         last so it overrides this page's own :root colours. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-palette.css')) !!}</style>
</head>
<body>
<aside class="sidebar">
    <div class="sb-grid"></div>
    <div class="sb-logo">
        <img src="{{ asset('images/lusog-logo.png') }}" alt="SIGLA Logo" class="sb-logo-full">
    </div>
    <nav class="sb-nav">
        <div class="sb-section-label">Main</div>
        <a href="#create-account" class="sb-link active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Create Account
        </a>
        <a href="{{ route('dashboard.system-admin.audit-logs') }}" class="sb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>
            Audit Trail
        </a>
    </nav>
    <div class="sb-user">
        <div class="sb-avatar">SA</div>
        <div class="sb-user-meta">
            <div class="sb-user-name">System Admin</div>
            <div class="sb-user-role">Platform Control</div>
        </div>
        <form method="POST" action="{{ route('logout') }}" style="margin-left:auto;">
            @csrf
            <button class="sb-logout" type="submit" aria-label="Sign out">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </button>
        </form>
    </div>
</aside>
<div class="main">
    <header class="topbar"><div class="topbar-breadcrumb">Dashboard > System Administrator</div><div class="topbar-chip">Platform Control Center</div>@include('partials.live-clock')</header>
    <div class="content">
        <h1 class="title">System Administrator <i>Control Center</i></h1>
        <p class="sub">User governance, predictive restocking settings, notification policies, and audit visibility.</p>

        @if (session('success'))
            <div class="flash flash-ok">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="flash flash-err">{{ $errors->first() }}</div>
        @endif

        @include('partials.announcements')

        @php
            $accountsCollection = collect($accounts ?? []);
            $pendingRequestsCollection = collect($pendingRequests ?? []);
            $requestHistoryCollection = collect($requestHistory ?? []);
            $classAdviserAccounts = $accountsCollection->where('role', 'class_adviser')->count();
            $classAdviserRequests = $pendingRequestsCollection->where('role', 'class_adviser')->count();
        @endphp

        <section class="stats">
            <article class="card stat"><b>{{ $accountsCollection->count() }}</b><span>Active users</span></article>
            <article class="card stat"><b>{{ $pendingRequestsCollection->count() }}</b><span>Pending account approvals</span></article>
            <article class="card stat"><b>{{ $classAdviserAccounts }}</b><span>Class Adviser accounts</span></article>
            <article class="card stat"><b>{{ $classAdviserRequests }}</b><span>Class Adviser requests</span></article>
        </section>

        <section class="grid">
            <article class="card section" id="create-account">
                <h3 id="user-management">User and Role Management</h3>
                <table>
                    <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Assignment</th><th>Status</th></tr></thead>
                    <tbody>
                        @php
                            $roleLabel = [
                                'school_nurse' => 'School Nurse',
                                'clinic_staff' => 'Clinic Staff',
                                'class_adviser' => 'Class Adviser',
                                'school_head' => 'School Head',
                                'feeding_coor' => 'Feeding Coordinator',
                                'nutricor' => 'Nutritional Coordinator',
                            ];
                        @endphp
                        @forelse($accounts as $account)
                            <tr>
                                <td>{{ $account['name'] ?? '-' }}</td>
                                <td>{{ $account['username'] ?? '-' }}</td>
                                <td>{{ $roleLabel[$account['role'] ?? ''] ?? ($account['role'] ?? '-') }}</td>
                                <td>
                                    @if (($account['role'] ?? '') === 'class_adviser')
                                        {{ $account['school_name'] ?? '-' }}<br>{{ $account['assigned_grade_level'] ?? '-' }} / {{ $account['assigned_section'] ?? '-' }}
                                    @elseif (in_array(($account['role'] ?? ''), ['school_nurse', 'clinic_staff', 'school_head', 'nutricor'], true))
                                        {{ $account['school_name'] ?? '-' }}
                                    @else
                                        -
                                    @endif
                                </td>
                                <td><span class="tag ok">Active</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" style="color:#6B7C72;">No created accounts yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                <h3 id="incoming-requests" style="margin-top:16px">Incoming Account Requests</h3>
                <table>
                    <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Assignment</th><th>Submitted</th><th>Action</th></tr></thead>
                    <tbody>
                        @forelse($pendingRequests as $request)
                            <tr>
                                <td>{{ $request['name'] ?? '-' }}</td>
                                <td>{{ $request['username'] ?? '-' }}</td>
                                <td>{{ $roleLabel[$request['role'] ?? ''] ?? ($request['role'] ?? '-') }}</td>
                                <td>
                                    @if (($request['role'] ?? '') === 'class_adviser')
                                        {{ $request['school_name'] ?? '-' }}<br>{{ $request['assigned_grade_level'] ?? '-' }} / {{ $request['assigned_section'] ?? '-' }}
                                    @elseif (in_array(($request['role'] ?? ''), ['school_nurse', 'clinic_staff', 'school_head', 'nutricor'], true))
                                        {{ $request['school_name'] ?? '-' }}
                                    @else
                                        -
                                    @endif
                                </td>
                                <td>{{ isset($request['created_at']) ? \Illuminate\Support\Carbon::parse($request['created_at'])->format('M d, Y h:i A') : '-' }}</td>
                                <td>
                                    <div style="display:flex;gap:6px;align-items:center;">
                                        <form method="POST" action="{{ route('dashboard.system-admin.requests.approve', $request['id']) }}">
                                            @csrf
                                            <button type="submit" class="btn">Approve</button>
                                        </form>
                                        <form method="POST" action="{{ route('dashboard.system-admin.requests.decline', $request['id']) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-danger">Decline</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" style="color:#6B7C72;">No pending account requests.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </article>

            <article class="card section" id="feeding-policy">
                <h3>Feeding At-Risk Threshold</h3>
                {{-- School-configurable by requirement: a programme running four
                     days a week cannot be judged on the same line as one running
                     five. An empty field means the school follows the app
                     default, so it moves with the programme instead of being
                     pinned to whatever today's number happens to be. --}}
                <table>
                    <thead><tr><th>School</th><th>Source</th><th>Threshold</th></tr></thead>
                    <tbody>
                        @forelse(($institutions ?? collect()) as $school)
                            <tr>
                                <td>{{ $school->name }}</td>
                                <td>
                                    @if ($school->feeding_at_risk_threshold === null)
                                        <span class="tag">Default {{ (int) ($defaultAtRiskThreshold ?? 80) }}%</span>
                                    @else
                                        <span class="tag ok">School-set</span>
                                    @endif
                                </td>
                                <td>
                                    {{-- One form per row, wholly inside this cell: a form
                                         straddling table cells is invalid markup. --}}
                                    <form method="POST" action="{{ route('dashboard.system-admin.institutions.at-risk-threshold', $school->id) }}" style="display:flex;gap:8px;align-items:center;">
                                        @csrf
                                        <input type="number" name="threshold" min="1" max="100" step="1"
                                            value="{{ $school->feeding_at_risk_threshold }}"
                                            placeholder="{{ (int) ($defaultAtRiskThreshold ?? 80) }}"
                                            aria-label="At-risk attendance threshold for {{ $school->name }}"
                                            style="width:84px;">
                                        <span style="color:#6B7C72;">%</span>
                                        <button type="submit" class="btn btn-secondary">Save</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" style="color:#6B7C72;">No schools on file.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </article>

            <article class="card section" id="account-history">
                <h3>Account Request History</h3>
                <table>
                    <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Assignment</th><th>Decision</th><th>Submitted</th><th>Processed</th></tr></thead>
                    <tbody>
                        @forelse($requestHistoryCollection as $history)
                            <tr>
                                <td>{{ $history['name'] ?? '-' }}</td>
                                <td>{{ $history['username'] ?? '-' }}</td>
                                <td>{{ $roleLabel[$history['role'] ?? ''] ?? ($history['role'] ?? '-') }}</td>
                                <td>
                                    @if (($history['role'] ?? '') === 'class_adviser')
                                        {{ $history['school_name'] ?? '-' }}<br>{{ $history['assigned_grade_level'] ?? '-' }} / {{ $history['assigned_section'] ?? '-' }}
                                    @elseif (in_array(($history['role'] ?? ''), ['school_nurse', 'clinic_staff', 'school_head', 'nutricor'], true))
                                        {{ $history['school_name'] ?? '-' }}
                                    @else
                                        -
                                    @endif
                                </td>
                                <td>
                                    @if (($history['status'] ?? '') === 'accepted')
                                        <span class="tag ok">Accepted</span>
                                    @else
                                        <span class="tag bad">Declined</span>
                                    @endif
                                </td>
                                <td>{{ isset($history['submitted_at']) ? \Illuminate\Support\Carbon::parse($history['submitted_at'])->format('M d, Y h:i A') : '-' }}</td>
                                <td>{{ isset($history['decided_at']) ? \Illuminate\Support\Carbon::parse($history['decided_at'])->format('M d, Y h:i A') : '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" style="color:#6B7C72;">No processed account requests yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </article>
        </section>
    </div>
</div>
@include('partials.sidebar-hover-pin')
</body>
</html>
