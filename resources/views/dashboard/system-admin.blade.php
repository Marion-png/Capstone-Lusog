<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Admin Dashboard - LUSOG</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        :root{--bg:#f7f8f5;--card:#fff;--border:#e4ece7;--text:#0d1f14;--muted:#6f8c7a;--g950:#052e16;--g900:#14532d;--g800:#166534;--g700:#15803d;--g600:#16a34a;--g500:#22c55e;--g300:#86efac;--g200:#bbf7d0;--g100:#dcfce7;--g50:#f0fdf4;--red:#ef4444;--sidebar-w:248px;--sidebar-collapsed-w:76px;--topbar-h:64px;--shadow:0 1px 4px rgba(5,46,22,.06),0 4px 16px rgba(5,46,22,.06)}
        html,body{height:100%;font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);overflow:hidden}
        .sidebar{position:fixed;left:0;top:0;bottom:0;width:var(--sidebar-collapsed-w);background:var(--g900);display:flex;flex-direction:column;z-index:100;overflow:hidden;transition:width .24s ease}
        .sidebar:hover{width:var(--sidebar-w)}
        .sidebar::after{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 120% 40% at 50% 100%, rgba(34,197,94,.18) 0%, transparent 70%),radial-gradient(ellipse 80% 30% at 80% 0%, rgba(74,222,128,.1) 0%, transparent 60%);pointer-events:none}
        .sb-grid{position:absolute;inset:0;background-image:linear-gradient(rgba(134,239,172,.05) 1px, transparent 1px),linear-gradient(90deg, rgba(134,239,172,.05) 1px, transparent 1px);background-size:28px 28px}
        .sb-logo{padding:14px 10px;position:relative;z-index:2;border-bottom:1px solid rgba(255,255,255,.08);display:flex;justify-content:center;transition:padding .24s ease}
        .sb-logo-full{width:48px;max-width:100%;height:auto;display:block;transition:width .24s ease}
        .sidebar:hover .sb-logo{padding:20px 20px 18px}
        .sidebar:hover .sb-logo-full{width:176px}
        .sb-nav{flex:1;overflow-y:auto;padding:16px 8px;position:relative;z-index:2;scrollbar-width:none;transition:padding .24s ease}
        .sidebar:hover .sb-nav{padding:16px 12px}
        .sb-nav::-webkit-scrollbar{display:none}
        .sb-section-label{font-size:.6rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:rgba(134,239,172,.5);padding:0 8px;margin:20px 0 8px}
        .sb-section-label:first-child{margin-top:0}
        .sb-link{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;text-decoration:none;color:rgba(255,255,255,.6);font-size:.83rem;font-weight:500;transition:background .15s,color .15s,padding .24s ease;margin-bottom:2px;white-space:nowrap;overflow:hidden}
        .sb-link:hover{background:rgba(255,255,255,.08);color:rgba(255,255,255,.9)}
        .sb-link.active{background:rgba(34,197,94,.18);color:var(--g300)}
        .sb-link svg{width:16px;height:16px;flex-shrink:0}
        .sidebar:not(:hover) .sb-section-label{display:none}
        .sidebar:not(:hover) .sb-link{justify-content:center;font-size:0;padding:10px;gap:0}
        .sb-user{padding:14px 16px;border-top:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:11px;position:relative;z-index:2}
        .sb-avatar{width:34px;height:34px;border-radius:50%;background:var(--g600);display:grid;place-items:center;font-size:.8rem;font-weight:700;color:#fff;flex-shrink:0}
        .sb-user-meta{min-width:0}
        .sb-user-name{font-size:.8rem;font-weight:600;color:#fff;line-height:1.2}
        .sb-user-role{font-size:.68rem;color:var(--g300)}
        .sb-logout{margin-left:auto;background:none;border:none;color:rgba(255,255,255,.35);cursor:pointer;padding:4px;border-radius:6px;transition:color .15s,background .15s;display:grid;place-items:center}
        .sb-logout:hover{color:var(--red);background:rgba(239,68,68,.1)}
        .sb-logout svg{width:15px;height:15px}
        .sidebar:not(:hover) .sb-user{padding:14px 10px}
        .sidebar:not(:hover) .sb-user-meta{display:none}
        .sidebar:not(:hover) .sb-logout{display:none}
        .main{margin-left:var(--sidebar-collapsed-w);height:100vh;display:flex;flex-direction:column;overflow:hidden;transition:margin-left .24s ease}
        .sidebar:hover ~ .main{margin-left:var(--sidebar-w)}
        .topbar{height:var(--topbar-h);background:#fff;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 24px}
        .topbar-breadcrumb{font-size:.82rem;color:#6f8c7a}
        .topbar-chip{font-size:.75rem;background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;padding:6px 12px;border-radius:999px}
        .content{padding:24px;overflow:auto}
        .title{font-family:'DM Serif Display',serif;font-size:1.7rem}.title i{color:#15803d}
        .sub{color:var(--muted);font-size:.85rem;margin-top:4px}
        .stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:18px 0}
        .card{background:var(--card);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow)}
        .stat{padding:14px}.stat b{font-family:'DM Serif Display',serif;font-size:1.5rem;display:block}.stat span{font-size:.72rem;color:var(--muted)}
        .grid{display:grid;grid-template-columns:1.1fr 1fr;gap:12px}
        .section{padding:14px}.section h3{font-size:.82rem;color:#3d5c47;margin-bottom:10px;text-transform:uppercase;letter-spacing:.08em}
        table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid var(--border);font-size:.78rem;text-align:left}th{font-size:.67rem;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);background:#f7faf8}
        .tag{display:inline-block;padding:3px 8px;border-radius:999px;font-size:.67rem;font-weight:700}.ok{background:var(--g100);color:#166534}.warn{background:#fef3c7;color:#92400e}.bad{background:#fee2e2;color:var(--red)}
        .row{display:flex;justify-content:space-between;align-items:center;padding:10px;border:1px solid var(--border);border-radius:10px;margin-bottom:8px;font-size:.8rem}
        .btn{font-size:.72rem;background:#fff;border:1px solid var(--border);border-radius:8px;padding:6px 10px;color:#2f4f42}
        .btn-danger{background:#fff;color:#b91c1c;border:1px solid #fecaca}
        .flash{padding:10px 12px;border-radius:10px;font-size:.8rem;margin-top:12px}
        .flash-ok{background:#dcfce7;color:#166534;border:1px solid #86efac}
        .flash-err{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
        @media (max-width:980px){.stats{grid-template-columns:repeat(2,1fr)}.grid{grid-template-columns:1fr}}
        @media (max-width:780px){:root{--sidebar-w:0px;--sidebar-collapsed-w:0px}.sidebar{display:none}.main{margin-left:0}}
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="sb-grid"></div>
    <div class="sb-logo">
        <img src="{{ asset('images/lusog-logo.png') }}" alt="LUSOG Logo" class="sb-logo-full">
    </div>
    <nav class="sb-nav">
        <div class="sb-section-label">Main</div>
        <a href="#create-account" class="sb-link active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Create Account
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
    <header class="topbar"><div class="topbar-breadcrumb">Dashboard > System Administrator</div><div class="topbar-chip">Platform Control Center</div></header>
    <div class="content">
        <h1 class="title">System Administrator <i>Control Center</i></h1>
        <p class="sub">User governance, predictive restocking settings, notification policies, and audit visibility.</p>

        @if (session('success'))
            <div class="flash flash-ok">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="flash flash-err">{{ $errors->first() }}</div>
        @endif

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
                            <tr><td colspan="5" style="color:#6f8c7a;">No created accounts yet.</td></tr>
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
                            <tr><td colspan="6" style="color:#6f8c7a;">No pending account requests.</td></tr>
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
                            <tr><td colspan="7" style="color:#6f8c7a;">No processed account requests yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </article>
        </section>
    </div>
</div>
</body>
</html>