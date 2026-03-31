<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LUSOG School Nurse Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg: #f4f7f6;
            --card: #ffffff;
            --ink: #1f2f2b;
            --sub: #6b7f79;
            --line: #dde5e2;
            --teal-900: #0f6257;
            --teal-800: #187a69;
            --teal-600: #2f9a81;
            --teal-100: #e8f4f0;
            --blue: #3b8de3;
            --green: #2ca17d;
            --amber: #ea9e2f;
            --red: #d74b4b;
            --shadow: 0 10px 22px rgba(26, 49, 40, 0.12);
            --radius: 12px;
        }

        html, body { width: 100%; min-height: 100%; }

        body {
            font-family: 'Nunito', sans-serif;
            color: var(--ink);
            background: var(--bg);
        }

        .layout {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 92px 1fr;
        }

        .sidebar {
            background: linear-gradient(180deg, var(--teal-900), #13675c 70%, #0f5c52);
            border-right: 1px solid rgba(255, 255, 255, 0.15);
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 18px 12px;
            gap: 20px;
        }

        .logo-mini {
            width: 54px;
            height: 54px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.24);
            display: grid;
            place-items: center;
            overflow: hidden;
        }

        .logo-mini img {
            width: 44px;
            height: 44px;
            object-fit: contain;
        }

        .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.55);
        }

        .content {
            padding: 20px 18px 30px;
            max-width: 1200px;
        }

        .header h1 {
            font-size: 2rem;
            font-weight: 800;
        }

        .header p {
            color: var(--sub);
            margin-top: 4px;
            font-size: 0.9rem;
        }

        .quick-nav {
            margin-top: 12px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .quick-btn {
            text-decoration: none;
            font-size: 0.78rem;
            font-weight: 800;
            color: #2e5a53;
            background: #ffffff;
            border: 1px solid #cfded9;
            border-radius: 9px;
            padding: 8px 11px;
            box-shadow: 0 4px 10px rgba(24, 48, 39, 0.08);
        }

        .quick-btn.primary {
            color: #ffffff;
            background: linear-gradient(180deg, #2f8f74 0%, #27725d 100%);
            border-color: #27725d;
        }

        .stats {
            margin-top: 16px;
            display: grid;
            grid-template-columns: repeat(4, minmax(140px, 1fr));
            gap: 12px;
        }

        .stat {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 12px 14px;
        }

        .stat .k { color: #7b8d88; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; }
        .stat .v { font-size: 2rem; font-weight: 800; margin-top: 5px; line-height: 1; }
        .stat .s { color: #92a29d; font-size: 0.72rem; margin-top: 5px; }

        .grid-2 {
            margin-top: 12px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 12px;
        }

        .card-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .card-title { font-weight: 800; font-size: 0.95rem; }
        .tiny { font-size: 0.72rem; color: #7f8f8b; }

        .search {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 8px;
            min-height: 34px;
            padding: 8px 10px;
            font: inherit;
            margin-bottom: 8px;
        }

        .student-list { display: grid; gap: 7px; max-height: 166px; overflow: auto; padding-right: 2px; }

        .row {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 8px;
            display: grid;
            grid-template-columns: 1fr auto;
            align-items: center;
            gap: 10px;
        }

        .name { font-size: 0.86rem; font-weight: 700; }
        .meta { font-size: 0.72rem; color: #879692; }

        .pill {
            padding: 3px 9px;
            border-radius: 999px;
            font-size: 0.68rem;
            font-weight: 700;
            color: #fff;
        }

        .pill.normal { background: var(--green); }
        .pill.monitor { background: var(--amber); }
        .pill.severe { background: var(--red); }

        .hbars { display: grid; gap: 8px; }
        .hbar { display: grid; grid-template-columns: 120px 1fr; align-items: center; gap: 8px; }
        .hbar span { font-size: 0.72rem; color: #748682; }

        .track {
            width: 100%;
            background: #ebf0ee;
            height: 11px;
            border-radius: 999px;
            overflow: hidden;
        }

        .fill { height: 100%; background: var(--blue); border-radius: 999px; }

        .line-svg {
            width: 100%;
            height: 180px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background:
                linear-gradient(#f7faf9 1px, transparent 1px),
                linear-gradient(90deg, #f7faf9 1px, transparent 1px);
            background-size: 36px 36px;
        }

        .med-list { display: grid; gap: 9px; }

        .med {
            display: grid;
            grid-template-columns: 115px 1fr 74px;
            align-items: center;
            gap: 8px;
        }

        .med-name { font-size: 0.75rem; font-weight: 700; }
        .med-val { font-size: 0.7rem; color: #80908c; text-align: right; }

        .stacked {
            margin-top: 12px;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 12px;
        }

        .stack-wrap {
            height: 170px;
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 10px;
            align-items: end;
            margin-top: 10px;
            border-bottom: 1px solid var(--line);
            padding: 10px 8px;
        }

        .stack {
            border-radius: 6px 6px 0 0;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: end;
            height: 100%;
            background: #f3f7f5;
        }

        .seg-r { background: var(--red); }
        .seg-a { background: var(--amber); }
        .seg-g { background: #2f9a81; }

        .months {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 10px;
            margin-top: 7px;
            font-size: 0.68rem;
            color: #80908b;
            text-align: center;
        }

        .kpis {
            margin-top: 12px;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
        }

        .kpi {
            border-radius: 10px;
            padding: 10px;
            text-align: center;
            font-size: 0.74rem;
        }

        .kpi b { font-size: 1.05rem; display: block; margin-bottom: 3px; }

        .k1 { background: #e8f4ef; color: #2f7b60; }
        .k2 { background: #e8eff7; color: #2f6f9f; }
        .k3 { background: #faf3e4; color: #b67a1e; }
        .k4 { background: #f9e9e9; color: #b14242; }

        .bottom {
            margin-top: 12px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .mini-list { display: grid; gap: 8px; }

        .item {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 8px;
            display: grid;
            grid-template-columns: 24px 1fr auto;
            gap: 8px;
            align-items: center;
        }

        .bullet {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            font-size: 0.75rem;
            font-weight: 800;
        }

        .b-m { background: #e9f3ef; color: #2f7b60; }
        .b-a { background: #fff2de; color: #b67a1e; }
        .b-r { background: #fde9e9; color: #b14242; }

        .item-name { font-size: 0.8rem; font-weight: 700; }
        .item-sub { font-size: 0.7rem; color: #869793; }
        .item-link { font-size: 0.7rem; color: #2b8368; font-weight: 700; }

        @media (max-width: 1100px) {
            .stats { grid-template-columns: repeat(2, 1fr); }
            .grid-2, .bottom { grid-template-columns: 1fr; }
            .kpis { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 760px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .content { padding: 14px; }
            .stats { grid-template-columns: 1fr; }
            .kpis { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="logo-mini">
            <img src="{{ asset('images/lusog-logo.png') }}" alt="LUSOG" onerror="this.style.opacity='0';">
        </div>
        <div class="dot"></div>
        <div class="dot"></div>
    </aside>

    <main class="content">
        <header class="header">
            <h1>Dashboard</h1>
            <p>Welcome, School Nurse. School health overview and monitoring.</p>
            <div class="quick-nav">
                <a class="quick-btn" href="{{ route('dashboard.student-health-records') }}">Student Health Records</a>
                <a class="quick-btn primary" href="{{ route('dashboard.consultation-log') }}">Consultation Log</a>
            </div>
        </header>

        <section class="stats">
            <article class="stat"><p class="k">Total Students</p><p class="v">389</p><p class="s">Enrollment SY</p></article>
            <article class="stat"><p class="k">Nutr. Status</p><p class="v">46</p><p class="s">Negative findings</p></article>
            <article class="stat"><p class="k">Medicine Stock</p><p class="v">24</p><p class="s">Below threshold</p></article>
            <article class="stat"><p class="k">Consultations Today</p><p class="v">7</p><p class="s">+2 from yesterday</p></article>
        </section>

        <section class="grid-2">
            <article class="card">
                <div class="card-head">
                    <h2 class="card-title">Quick Student Lookup</h2>
                </div>
                <input class="search" placeholder="Search by name, LRN, or section..." readonly>
                <div class="student-list">
                    <div class="row"><div><p class="name">Maria Santos</p><p class="meta">Grade 10 - Sampaguita</p></div><span class="pill normal">Normal</span></div>
                    <div class="row"><div><p class="name">Carlos Garcia</p><p class="meta">Grade 8 - Orchid</p></div><span class="pill severe">Severely Wasted</span></div>
                    <div class="row"><div><p class="name">Sofia Lim</p><p class="meta">Grade 9 - Bonifacio</p></div><span class="pill normal">Normal</span></div>
                    <div class="row"><div><p class="name">Juan Dela Cruz</p><p class="meta">Grade 7 - Mabini</p></div><span class="pill monitor">Monitored</span></div>
                </div>
            </article>

            <article class="card">
                <div class="card-head">
                    <h2 class="card-title">Top Consultation Cases</h2>
                    <p class="tiny">March 2026</p>
                </div>
                <div class="hbars">
                    <div class="hbar"><span>Headache</span><div class="track"><div class="fill" style="width: 94%"></div></div></div>
                    <div class="hbar"><span>Stomach Ache</span><div class="track"><div class="fill" style="width: 78%"></div></div></div>
                    <div class="hbar"><span>Fever/Cold</span><div class="track"><div class="fill" style="width: 66%"></div></div></div>
                    <div class="hbar"><span>Cough</span><div class="track"><div class="fill" style="width: 54%"></div></div></div>
                    <div class="hbar"><span>Wound/Injury</span><div class="track"><div class="fill" style="width: 42%"></div></div></div>
                    <div class="hbar"><span>Allergy</span><div class="track"><div class="fill" style="width: 31%"></div></div></div>
                </div>
            </article>
        </section>

        <section class="grid-2">
            <article class="card">
                <div class="card-head">
                    <h2 class="card-title">Consultation Trend</h2>
                </div>
                <svg class="line-svg" viewBox="0 0 620 240" preserveAspectRatio="none" aria-label="Consultation line chart">
                    <polyline points="40,170 130,120 220,190 310,90 400,135 490,105" fill="none" stroke="#3b8de3" stroke-width="4" />
                    <circle cx="40" cy="170" r="5" fill="#3b8de3" />
                    <circle cx="130" cy="120" r="5" fill="#3b8de3" />
                    <circle cx="220" cy="190" r="5" fill="#3b8de3" />
                    <circle cx="310" cy="90" r="5" fill="#3b8de3" />
                    <circle cx="400" cy="135" r="5" fill="#3b8de3" />
                    <circle cx="490" cy="105" r="5" fill="#3b8de3" />
                </svg>
            </article>

            <article class="card">
                <div class="card-head">
                    <h2 class="card-title">Medicine Inventory Status</h2>
                </div>
                <div class="med-list">
                    <div class="med"><p class="med-name">Paracetamol 500mg</p><div class="track"><div class="fill" style="width: 16%; background:#d74b4b"></div></div><p class="med-val">15% left</p></div>
                    <div class="med"><p class="med-name">Amoxicillin</p><div class="track"><div class="fill" style="width: 22%; background:#ea9e2f"></div></div><p class="med-val">22% left</p></div>
                    <div class="med"><p class="med-name">Antihistamine</p><div class="track"><div class="fill" style="width: 35%; background:#ea9e2f"></div></div><p class="med-val">35% left</p></div>
                    <div class="med"><p class="med-name">Mefenamic Acid</p><div class="track"><div class="fill" style="width: 30%; background:#ea9e2f"></div></div><p class="med-val">30% left</p></div>
                    <div class="med"><p class="med-name">Vitamin C</p><div class="track"><div class="fill" style="width: 67%; background:#2ca17d"></div></div><p class="med-val">67% left</p></div>
                    <div class="med"><p class="med-name">Bandages</p><div class="track"><div class="fill" style="width: 29%; background:#ea9e2f"></div></div><p class="med-val">29% left</p></div>
                </div>
            </article>
        </section>

        <section class="stacked">
            <div class="card-head">
                <h2 class="card-title">Feeding Program Progress</h2>
            </div>

            <div class="stack-wrap">
                <div class="stack"><div class="seg-g" style="height:34%"></div><div class="seg-a" style="height:24%"></div><div class="seg-r" style="height:20%"></div></div>
                <div class="stack"><div class="seg-g" style="height:30%"></div><div class="seg-a" style="height:30%"></div><div class="seg-r" style="height:16%"></div></div>
                <div class="stack"><div class="seg-g" style="height:44%"></div><div class="seg-a" style="height:20%"></div><div class="seg-r" style="height:12%"></div></div>
                <div class="stack"><div class="seg-g" style="height:50%"></div><div class="seg-a" style="height:18%"></div><div class="seg-r" style="height:10%"></div></div>
                <div class="stack"><div class="seg-g" style="height:56%"></div><div class="seg-a" style="height:12%"></div><div class="seg-r" style="height:8%"></div></div>
                <div class="stack"><div class="seg-g" style="height:62%"></div><div class="seg-a" style="height:10%"></div><div class="seg-r" style="height:6%"></div></div>
            </div>

            <div class="months"><span>Baseline</span><span>Month 1</span><span>Month 2</span><span>Month 3</span><span>Month 4</span><span>Endline</span></div>

            <div class="kpis">
                <article class="kpi k1"><b>72%</b>Students improved</article>
                <article class="kpi k2"><b>3.2 kg</b>Average weight gain</article>
                <article class="kpi k3"><b>28%</b>Moved from wasted to normal</article>
                <article class="kpi k4"><b>14</b>Still need follow-up</article>
            </div>
        </section>

        <section class="bottom">
            <article class="card">
                <div class="card-head">
                    <h2 class="card-title">Recent Consultations</h2>
                    <p class="tiny">View all consultations -></p>
                </div>
                <div class="mini-list">
                    <div class="item"><div class="bullet b-m">M</div><div><p class="item-name">Maria Santos</p><p class="item-sub">Grade 10 - Mar 17</p></div><p class="item-sub">Headache follow-up</p></div>
                    <div class="item"><div class="bullet b-m">C</div><div><p class="item-name">Carlos Garcia</p><p class="item-sub">Grade 8 - Mar 18</p></div><p class="item-sub">Stomach ache</p></div>
                    <div class="item"><div class="bullet b-m">S</div><div><p class="item-name">Sofia Lim</p><p class="item-sub">Grade 9 - Mar 18</p></div><p class="item-sub">Minor wound</p></div>
                    <div class="item"><div class="bullet b-m">J</div><div><p class="item-name">Juan Dela Cruz</p><p class="item-sub">Grade 7 - Mar 20</p></div><p class="item-sub">Allergic reaction</p></div>
                </div>
            </article>

            <article class="card">
                <div class="card-head"><h2 class="card-title">Action Items</h2></div>
                <div class="mini-list">
                    <div class="item"><div class="bullet b-r">!</div><div><p class="item-name">Low Stock Alert</p><p class="item-sub">Paracetamol, Amoxicillin, Bandages</p></div><span class="item-link">Order</span></div>
                    <div class="item"><div class="bullet b-a">!</div><div><p class="item-name">Expiring Medicines</p><p class="item-sub">2 items will expire in 42 days</p></div><span class="item-link">Review</span></div>
                    <div class="item"><div class="bullet b-m">i</div><div><p class="item-name">Feeding Program Update</p><p class="item-sub">17% of students improved this quarter</p></div><span class="item-link">View report</span></div>
                </div>
            </article>
        </section>
    </main>
</div>
</body>
</html>
