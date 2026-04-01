<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clinic Staff Dashboard - LUSOG</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        :root{--bg:#f7f8f5;--card:#fff;--border:#e4ece7;--text:#0d1f14;--muted:#6f8c7a;--g900:#14532d;--g700:#15803d;--g500:#22c55e;--g100:#dcfce7;--red:#ef4444;--amber:#f59e0b;--blue:#3b82f6;--sidebar:248px;--shadow:0 1px 4px rgba(5,46,22,.06),0 4px 16px rgba(5,46,22,.06)}
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
        .crumb{font-size:.82rem;color:var(--muted)}
        .chip{font-size:.75rem;background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;padding:6px 12px;border-radius:999px}
        .content{padding:24px;overflow:auto}
        .title{font-family:'DM Serif Display',serif;font-size:1.7rem}
        .title i{color:#15803d}
        .sub{color:var(--muted);font-size:.85rem;margin-top:4px}
        .stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:18px 0}
        .card{background:var(--card);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow)}
        .stat{padding:14px}
        .stat b{font-family:'DM Serif Display',serif;font-size:1.5rem;display:block}
        .stat span{font-size:.72rem;color:var(--muted)}
        .grid{display:grid;grid-template-columns:1.2fr 1fr;gap:12px}
        .section{padding:14px}
        .section h3{font-size:.82rem;color:#3d5c47;margin-bottom:10px;text-transform:uppercase;letter-spacing:.08em}
        table{width:100%;border-collapse:collapse}
        th,td{padding:10px;border-bottom:1px solid var(--border);font-size:.78rem;text-align:left}
        th{font-size:.67rem;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);background:#f7faf8}
        .pill{display:inline-block;padding:3px 9px;border-radius:999px;font-size:.68rem;font-weight:700}
        .ok{background:var(--g100);color:#166534}.warn{background:#fef3c7;color:#92400e}.bad{background:#fee2e2;color:var(--red)}
        .row{display:flex;justify-content:space-between;align-items:center;margin:8px 0;font-size:.8rem}
        .bar{height:8px;background:#edf5ef;border-radius:999px;overflow:hidden}
        .fill{height:100%}
        @media (max-width:980px){.stats{grid-template-columns:repeat(2,1fr)}.grid{grid-template-columns:1fr}}
        @media (max-width:780px){.sidebar{display:none}.main{margin-left:0}}
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="logo"><div class="avatar">LU</div><div><b>LUSOG</b><span>Clinic Management</span></div></div>
    <nav class="nav">
        <a href="{{ route('dashboard.school-nurse') }}">School Nurse</a>
        <a href="{{ route('dashboard.clinic-staff') }}" class="active">Clinic Staff</a>
        <a href="{{ route('dashboard.student-health-records') }}">Health Records</a>
        <a href="{{ route('dashboard.consultation-log') }}">Consultation Log</a>
        <a href="{{ route('dashboard.school-head') }}">School Head</a>
        <a href="{{ route('dashboard.system-admin') }}">System Admin</a>
    </nav>
    <div class="user"><div class="avatar">{{ substr(auth()->user()->name ?? 'CS',0,2) }}</div><div style="font-size:.78rem">Clinic Staff</div></div>
</aside>
<div class="main">
    <header class="top"><div class="crumb">Dashboard > Clinic Staff</div><div class="chip">Operations Workspace</div></header>
    <div class="content">
        <h1 class="title">Clinic Staff <i>Operations Hub</i></h1>
        <p class="sub">Focused tools for daily encoding, triage updates, inventory issuance, and follow-up tracking.</p>

        <section class="stats">
            <article class="card stat"><b>31</b><span>New walk-ins today</span></article>
            <article class="card stat"><b>18</b><span>Records encoded</span></article>
            <article class="card stat"><b>24</b><span>Medicines dispensed</span></article>
            <article class="card stat"><b>7</b><span>Pending endorsements</span></article>
        </section>

        <section class="grid">
            <article class="card section">
                <h3>Queue and Encoded Cases</h3>
                <table>
                    <thead><tr><th>Time</th><th>Name</th><th>Complaint</th><th>Status</th></tr></thead>
                    <tbody>
                        <tr><td>08:41</td><td>Juan Dela Cruz</td><td>Headache</td><td><span class="pill ok">Encoded</span></td></tr>
                        <tr><td>09:05</td><td>Ana Gonzales</td><td>Fever</td><td><span class="pill warn">For Review</span></td></tr>
                        <tr><td>09:22</td><td>Maria Santos</td><td>Abdominal pain</td><td><span class="pill ok">Encoded</span></td></tr>
                        <tr><td>09:47</td><td>Carlo Mendoza</td><td>Skin allergy</td><td><span class="pill bad">Refer Nurse</span></td></tr>
                    </tbody>
                </table>
            </article>

            <article class="card section">
                <h3>Medicine Issuance Snapshot</h3>
                <div class="row"><span>Paracetamol</span><span>78 tablets left</span></div>
                <div class="bar"><div class="fill" style="width:44%;background:#f59e0b"></div></div>
                <div class="row"><span>Antihistamine</span><span>42 tabs left</span></div>
                <div class="bar"><div class="fill" style="width:22%;background:#ef4444"></div></div>
                <div class="row"><span>Bandages</span><span>120 pcs left</span></div>
                <div class="bar"><div class="fill" style="width:61%;background:#22c55e"></div></div>
                <div class="row"><span>ORS</span><span>35 sachets left</span></div>
                <div class="bar"><div class="fill" style="width:33%;background:#3b82f6"></div></div>
            </article>
        </section>
    </div>
</div>
</body>
</html>