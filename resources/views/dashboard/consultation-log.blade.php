<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultation Log</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg: #f4f7f6;
            --card: #ffffff;
            --ink: #213531;
            --muted: #718682;
            --line: #dde6e2;
            --teal-900: #0f6156;
            --green: #2f8f74;
            --amber: #e3a13a;
            --shadow: 0 10px 24px rgba(20, 47, 38, 0.11);
        }
        body { font-family: 'Nunito', sans-serif; background: var(--bg); color: var(--ink); }
        .layout { min-height: 100vh; display: grid; grid-template-columns: 92px 1fr; }
        .sidebar { background: linear-gradient(180deg, var(--teal-900), #125f55 70%, #0e5a50); }
        .content { padding: 14px; }
        .panel { background: var(--card); border: 1px solid var(--line); border-radius: 12px; box-shadow: var(--shadow); overflow: hidden; }
        .inner { padding: 10px; }
        .head { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 8px; }
        .title { font-size: 1.1rem; font-weight: 800; }
        .sub { color: var(--muted); font-size: 0.72rem; }
        .head-right { display: flex; gap: 8px; align-items: center; color: #8b9b97; font-size: 0.72rem; }
        .btn {
            text-decoration: none;
            border-radius: 8px;
            border: 1px solid #c7d8d2;
            color: #305652;
            background: #fff;
            padding: 7px 10px;
            font-size: 0.74rem;
            font-weight: 800;
        }
        .btn.primary { border-color: #266f59; background: #266f59; color: #fff; }
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 8px; }
        .stat { border: 1px solid var(--line); border-radius: 8px; padding: 8px; background: #fff; }
        .stat .k { font-size: 0.64rem; color: #7f928d; text-transform: uppercase; font-weight: 700; }
        .stat .v { font-size: 1.5rem; font-weight: 800; margin-top: 2px; }
        .stat .s { font-size: 0.68rem; color: #8ea09b; }
        .filters { display: grid; grid-template-columns: 1.7fr 1fr 1fr 1fr; gap: 8px; margin-bottom: 8px; }
        .control { min-height: 34px; border: 1px solid var(--line); border-radius: 7px; padding: 8px 10px; font: inherit; color: #60736f; background: #fff; }
        .reset { width: 160px; }
        .charts { display: grid; grid-template-columns: 1.2fr 1fr; gap: 8px; margin-bottom: 8px; }
        .card { border: 1px solid var(--line); border-radius: 8px; padding: 8px; }
        .card h3 { font-size: 0.78rem; color: #3f5953; margin-bottom: 6px; }
        .bars { height: 170px; display: grid; grid-template-columns: repeat(6, 1fr); gap: 8px; align-items: end; }
        .bar { background: #2f5f2f; border-radius: 6px 6px 0 0; }
        .line { height: 170px; border: 1px solid #e5ece9; border-radius: 8px; background: linear-gradient(#f7faf9 1px, transparent 1px), linear-gradient(90deg, #f7faf9 1px, transparent 1px); background-size: 30px 30px; }
        .table-wrap { border: 1px solid var(--line); border-radius: 8px; overflow: hidden; }
        table { width: 100%; border-collapse: collapse; font-size: 0.72rem; }
        th, td { border-bottom: 1px solid #e6eeeb; padding: 8px 7px; text-align: left; }
        th { background: #fbfdfc; font-size: 0.64rem; text-transform: uppercase; color: #82958f; letter-spacing: 0.04em; }
        .badge { border-radius: 999px; padding: 3px 8px; font-weight: 800; font-size: 0.62rem; color: #fff; }
        .b-green { background: #2ca17d; }
        .b-amber { background: var(--amber); }
        .ops { color: #61827a; font-weight: 700; }
        .foot { display: flex; justify-content: space-between; padding: 8px 2px 0; color: #83948f; font-size: 0.68rem; }
        @media (max-width: 1000px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .stats { grid-template-columns: repeat(2, 1fr); }
            .filters { grid-template-columns: 1fr; }
            .charts { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar"></aside>
    <main class="content">
        <div class="panel">
            <div class="inner">
                <div class="head">
                    <div>
                        <h1 class="title">Consultation Log</h1>
                        <p class="sub">Manage and track all student consultations</p>
                    </div>
                    <div class="head-right">
                        <span>March 30, 2026</span>
                        <a class="btn" href="{{ route('dashboard.school-nurse') }}">Dashboard</a>
                        <a class="btn" href="{{ route('dashboard.student-health-records') }}">Health Records</a>
                        <a class="btn primary" href="#">+ New Consultation</a>
                    </div>
                </div>

                <section class="stats">
                    <article class="stat"><p class="k">Total Consultations</p><p class="v">1,247</p><p class="s">This SY</p></article>
                    <article class="stat"><p class="k">This Month</p><p class="v">215</p><p class="s">March 2026</p></article>
                    <article class="stat"><p class="k">This Week</p><p class="v">48</p><p class="s">Mar 24-30, 2026</p></article>
                    <article class="stat"><p class="k">Referral Cases</p><p class="v" style="color:#cd7f2b">23</p><p class="s">Needs follow-up</p></article>
                </section>

                <section class="filters">
                    <input class="control" placeholder="Search by student name, LRN, or grade..." readonly>
                    <select class="control"><option>All Dates</option></select>
                    <select class="control"><option>All Conditions</option></select>
                    <select class="control"><option>All Grades</option></select>
                </section>

                <button class="btn reset">Reset</button>

                <section class="charts" style="margin-top:8px;">
                    <article class="card">
                        <h3>Most Common Conditions (This Month)</h3>
                        <div class="bars">
                            <div class="bar" style="height:94%"></div>
                            <div class="bar" style="height:86%"></div>
                            <div class="bar" style="height:68%"></div>
                            <div class="bar" style="height:54%"></div>
                            <div class="bar" style="height:48%"></div>
                            <div class="bar" style="height:36%"></div>
                        </div>
                    </article>
                    <article class="card">
                        <h3>Daily Consultation Trend (This Week)</h3>
                        <svg class="line" viewBox="0 0 500 220" preserveAspectRatio="none">
                            <polyline points="10,90 90,130 170,80 250,120 330,50 410,180 490,210" fill="none" stroke="#daa33f" stroke-width="4" />
                        </svg>
                    </article>
                </section>

                <section class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Date &amp; Time</th>
                                <th>Student Name</th>
                                <th>Grade &amp; Section</th>
                                <th>Condition</th>
                                <th>Treatment Given</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>2026-03-29 09:15</td><td>Dela Cruz, Juan</td><td>Grade 10 - Rizal</td><td>Fever, Cough</td><td>Paracetamol 500mg, advised rest</td><td><span class="badge b-green">Treated</span></td><td class="ops">View · Edit</td></tr>
                            <tr><td>2026-03-28 08:30</td><td>Santos, Maria</td><td>Grade 8 - Mabini</td><td>Headache</td><td>Mefenamic Acid 250mg</td><td><span class="badge b-green">Treated</span></td><td class="ops">View · Edit</td></tr>
                            <tr><td>2026-03-28 09:00</td><td>Rizal, Jose</td><td>Grade 12 - Dahlia</td><td>Abrasion</td><td>Cleaned wound, applied bandage</td><td><span class="badge b-green">Treated</span></td><td class="ops">View · Edit</td></tr>
                            <tr><td>2026-03-27 14:00</td><td>Gonzales, Ana</td><td>Grade 7 - Aquino</td><td>Abdominal Pain</td><td>Antacid, advised to eat properly</td><td><span class="badge b-green">Treated</span></td><td class="ops">View · Edit</td></tr>
                            <tr><td>2026-03-27 10:45</td><td>Mendoza, Carlo</td><td>Grade 9 - Bonifacio</td><td>Skin Allergy</td><td>Antihistamine, Calamine lotion</td><td><span class="badge b-amber">Referred</span></td><td class="ops">View · Edit</td></tr>
                        </tbody>
                    </table>
                </section>

                <div class="foot">
                    <span>Showing 1-5 of 5 records</span>
                    <span>Page 1 of 1</span>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>
