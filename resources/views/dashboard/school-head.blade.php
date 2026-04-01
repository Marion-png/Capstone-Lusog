<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Head Dashboard - LUSOG</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        :root{--bg:#f7f8f5;--card:#fff;--border:#e4ece7;--text:#0d1f14;--muted:#6f8c7a;--g900:#14532d;--g700:#15803d;--g100:#dcfce7;--red:#ef4444;--amber:#f59e0b;--blue:#3b82f6;--sidebar:248px;--shadow:0 1px 4px rgba(5,46,22,.06),0 4px 16px rgba(5,46,22,.06)}
        html,body{height:100%;font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);overflow:hidden}
        .sidebar{position:fixed;inset:0 auto 0 0;width:var(--sidebar);background:var(--g900);padding:20px 12px;display:flex;flex-direction:column}
        .logo{display:flex;align-items:center;gap:10px;padding:4px 8px 16px;border-bottom:1px solid rgba(255,255,255,.1)}
        .logo b{color:#fff;font-family:'DM Serif Display',serif;font-size:1.2rem}
        .logo span{color:#86efac;font-size:.65rem;letter-spacing:.1em;text-transform:uppercase;display:block}
        .nav{padding-top:14px;flex:1}
        .nav a{display:flex;align-items:center;gap:8px;color:rgba(255,255,255,.72);text-decoration:none;font-size:.83rem;padding:10px;border-radius:10px;margin-bottom:4px}
        .nav a.active,.nav a:hover{background:rgba(34,197,94,.18);color:#dcfce7}
        .user{padding:12px 8px;border-top:1px solid rgba(255,255,255,.1);display:flex;align-items:center;gap:8px;color:#fff}
        .avatar{width:32px;height:32px;border-radius:50%;background:#16a34a;display:grid;place-items:center;font-weight:700;font-size:.75rem}
        .main{margin-left:var(--sidebar);height:100vh;display:flex;flex-direction:column}
        .top{height:64px;background:#fff;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 24px}
        .chip{font-size:.75rem;background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;padding:6px 12px;border-radius:999px}
        .content{padding:24px;overflow:auto}
        .title{font-family:'DM Serif Display',serif;font-size:1.7rem}.title i{color:#15803d}
        .sub{color:var(--muted);font-size:.85rem;margin-top:4px}
        .stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:18px 0}
        .card{background:var(--card);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow)}
        .stat{padding:14px}.stat b{font-family:'DM Serif Display',serif;font-size:1.5rem;display:block}.stat span{font-size:.72rem;color:var(--muted)}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .section{padding:14px}.section h3{font-size:.82rem;color:#3d5c47;margin-bottom:10px;text-transform:uppercase;letter-spacing:.08em}
        .kpi{display:flex;justify-content:space-between;padding:10px;border:1px solid var(--border);border-radius:10px;margin-bottom:8px;font-size:.8rem}
        .list li{list-style:none;padding:10px;border-bottom:1px solid var(--border);font-size:.8rem;display:flex;justify-content:space-between}
        .pill{padding:3px 9px;border-radius:999px;font-size:.67rem;font-weight:700}.ok{background:var(--g100);color:#166534}.warn{background:#fef3c7;color:#92400e}.bad{background:#fee2e2;color:var(--red)}
        @media (max-width:980px){.stats{grid-template-columns:repeat(2,1fr)}.grid{grid-template-columns:1fr}}
        @media (max-width:780px){.sidebar{display:none}.main{margin-left:0}}
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="logo"><div class="avatar">LU</div><div><b>LUSOG</b><span>Clinic Management</span></div></div>
    <nav class="nav">
        <a href="{{ route('dashboard.school-nurse') }}">School Nurse</a>
        <a href="{{ route('dashboard.clinic-staff') }}">Clinic Staff</a>
        <a href="{{ route('dashboard.student-health-records') }}">Health Records</a>
        <a href="{{ route('dashboard.consultation-log') }}">Consultation Log</a>
        <a href="{{ route('dashboard.school-head') }}" class="active">School Head</a>
        <a href="{{ route('dashboard.system-admin') }}">System Admin</a>
    </nav>
    <div class="user"><div class="avatar">{{ substr(auth()->user()->name ?? 'SH',0,2) }}</div><div style="font-size:.78rem">School Head</div></div>
</aside>
<div class="main">
    <header class="top"><div style="font-size:.82rem;color:#6f8c7a">Dashboard > School Head</div><div class="chip">Strategic View</div></header>
    <div class="content">
        <h1 class="title">School Head <i>Decision Dashboard</i></h1>
        <p class="sub">School-wide health oversight with program progress, compliance status, and executive indicators.</p>

        <section class="stats">
            <article class="card stat"><b>96.2%</b><span>DepEd compliance score</span></article>
            <article class="card stat"><b>72%</b><span>Feeding program improvement</span></article>
            <article class="card stat"><b>14</b><span>Active at-risk cases</span></article>
            <article class="card stat"><b>5</b><span>Schools reports due</span></article>
        </section>

        <section class="grid">
            <article class="card section">
                <h3>Compliance Reporting</h3>
                <div class="kpi"><span>SF8 Health Profile Completion</span><span class="pill ok">Complete</span></div>
                <div class="kpi"><span>Nutritional Status Summary</span><span class="pill ok">Submitted</span></div>
                <div class="kpi"><span>Quarterly Morbidity Report</span><span class="pill warn">Due in 5 days</span></div>
                <div class="kpi"><span>Referral Tracking Report</span><span class="pill bad">Overdue</span></div>
            </article>

            <article class="card section">
                <h3>Program Monitoring Highlights</h3>
                <ul class="list">
                    <li><span>Feeding Program Cohort A</span><span>+3.2 kg avg</span></li>
                    <li><span>Feeding Program Cohort B</span><span>+2.6 kg avg</span></li>
                    <li><span>Deworming Coverage</span><span>91%</span></li>
                    <li><span>Follow-up Attendance</span><span>84%</span></li>
                    <li><span>At-risk case closure rate</span><span>67%</span></li>
                </ul>
            </article>
        </section>
    </div>
</div>
</body>
</html>