<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Health Records</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg: #f3f6f5;
            --card: #ffffff;
            --ink: #223632;
            --muted: #748884;
            --line: #dbe5e1;
            --teal-900: #0f6156;
            --teal-700: #1b7b69;
            --teal-100: #ddf1ea;
            --red: #d94d4d;
            --green: #2ca17c;
            --amber: #e39e33;
            --shadow: 0 10px 24px rgba(20, 47, 38, 0.12);
        }
        body { font-family: 'Nunito', sans-serif; background: var(--bg); color: var(--ink); }
        .layout { min-height: 100vh; display: grid; grid-template-columns: 92px 1fr; }
        .sidebar { background: linear-gradient(180deg, var(--teal-900), #125f55 70%, #0e5a50); }
        .content { padding: 18px; }
        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 12px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        .card-inner { padding: 14px; }
        .topbar { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 10px; }
        .title { font-size: 2rem; font-weight: 800; }
        .subtitle { font-size: 0.78rem; color: var(--muted); margin-top: 2px; }
        .btn {
            border: 1px solid #c6d7d1;
            background: #fff;
            color: #325651;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 700;
            padding: 8px 12px;
            border-radius: 8px;
        }
        .btn.primary { background: #2c8f74; border-color: #2c8f74; color: #fff; }
        .notice {
            background: linear-gradient(90deg, #a8def3, #8bd2f0);
            color: #1f4d63;
            border: 1px solid #6bb9d9;
            border-radius: 10px;
            font-size: 0.84rem;
            font-weight: 700;
            padding: 10px 12px;
            margin-top: 8px;
        }
        .tools { margin-top: 12px; display: grid; grid-template-columns: 1fr auto auto auto auto auto; gap: 8px; align-items: center; }
        .search {
            border: 1px solid var(--line);
            border-radius: 8px;
            min-height: 36px;
            padding: 8px 10px;
            font: inherit;
        }
        .chip {
            border: 1px solid var(--line);
            background: #fff;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
            color: #5f736f;
            padding: 8px 14px;
            text-align: center;
            cursor: pointer;
        }
        .chip.active { background: #186a5b; color: #fff; border-color: #186a5b; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 0.78rem; }
        th, td { border-bottom: 1px solid #e7eeeb; padding: 9px 8px; text-align: left; }
        th { font-size: 0.68rem; color: #79908b; text-transform: uppercase; letter-spacing: 0.04em; }
        .flag { color: #d8a324; font-size: 0.8rem; margin-right: 6px; }
        .status {
            border-radius: 999px;
            color: #fff;
            font-size: 0.64rem;
            font-weight: 800;
            padding: 3px 8px;
            display: inline-block;
        }
        .s-red { background: var(--red); }
        .s-green { background: var(--green); }
        .s-amber { background: var(--amber); }
        .s-gray { background: #8f9f9a; }
        .actions { margin-top: 12px; display: flex; gap: 8px; }
        @media (max-width: 980px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .tools { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar"></aside>
    <main class="content">
        <div class="card">
            <div class="card-inner">
                <div class="topbar">
                    <div>
                        <h1 class="title">Student Health Records</h1>
                        <p class="subtitle">8 Students | 3 Flags to review</p>
                    </div>
                    <div class="actions">
                        <a href="{{ route('dashboard.school-nurse') }}" class="btn">Back to Dashboard</a>
                        <a href="{{ route('dashboard.consultation-log') }}" class="btn primary">Consultation Log</a>
                    </div>
                </div>

                <div class="notice">View Only - Only Class Advisers can input health data.</div>

                <div class="tools">
                    <input class="search" id="studentSearch" placeholder="Search student name, grade, section, or status...">
                    <button class="chip active" type="button" data-filter="all">All</button>
                    <button class="chip" type="button" data-filter="severely wasted">Severely Wasted</button>
                    <button class="chip" type="button" data-filter="wasted">Wasted</button>
                    <button class="chip" type="button" data-filter="normal">Normal</button>
                    <button class="chip" type="button" data-filter="overweight">Overweight</button>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Grade &amp; Section</th>
                            <th>Age</th>
                            <th>Height (cm)</th>
                            <th>Weight (kg)</th>
                            <th>BMI</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="recordsBody">
                        <tr data-status="severely wasted"><td><span class="flag">⚠</span>Maria Santos</td><td>Grade 3 - Sampaguita</td><td>9</td><td>120</td><td>18</td><td><b>12.5</b></td><td><span class="status s-red">Severely Wasted</span></td></tr>
                        <tr data-status="wasted"><td><span class="flag">⚠</span>Juan Dela Cruz</td><td>Grade 4 - Narra</td><td>10</td><td>130</td><td>25</td><td><b>14.8</b></td><td><span class="status s-red">Wasted</span></td></tr>
                        <tr data-status="wasted"><td><span class="flag">⚠</span>Ana Reyes</td><td>Grade 2 - Rosal</td><td>8</td><td>118</td><td>22</td><td><b>15.8</b></td><td><span class="status s-red">Wasted</span></td></tr>
                        <tr data-status="normal"><td>Carlos Garcia</td><td>Grade 5 - Mahogany</td><td>11</td><td>140</td><td>35</td><td><b>17.9</b></td><td><span class="status s-green">Normal</span></td></tr>
                        <tr data-status="normal"><td>Sofia Lim</td><td>Grade 1 - Dahlia</td><td>7</td><td>110</td><td>20</td><td><b>16.5</b></td><td><span class="status s-green">Normal</span></td></tr>
                        <tr data-status="normal"><td>Miguel Torres</td><td>Grade 6 - Acacia</td><td>12</td><td>148</td><td>42</td><td><b>19.2</b></td><td><span class="status s-green">Normal</span></td></tr>
                        <tr data-status="normal"><td>Isabella Cruz</td><td>Grade 3 - Sampaguita</td><td>9</td><td>122</td><td>30</td><td><b>20.2</b></td><td><span class="status s-green">Normal</span></td></tr>
                        <tr data-status="overweight"><td>Diego Mendoza</td><td>Grade 4 - Narra</td><td>10</td><td>132</td><td>40</td><td><b>23</b></td><td><span class="status s-gray">Overweight</span></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
<script>
    const chips = document.querySelectorAll('.chip[data-filter]');
    const searchInput = document.getElementById('studentSearch');
    const rows = document.querySelectorAll('#recordsBody tr[data-status]');

    function applyFilters() {
        const activeChip = document.querySelector('.chip.active[data-filter]');
        const statusFilter = activeChip ? activeChip.dataset.filter : 'all';
        const query = searchInput.value.trim().toLowerCase();

        rows.forEach((row) => {
            const rowStatus = row.dataset.status;
            const rowText = row.textContent.toLowerCase();
            const matchesStatus = statusFilter === 'all' || rowStatus === statusFilter;
            const matchesSearch = query === '' || rowText.includes(query);

            row.style.display = matchesStatus && matchesSearch ? '' : 'none';
        });
    }

    chips.forEach((chip) => {
        chip.addEventListener('click', () => {
            chips.forEach((item) => item.classList.remove('active'));
            chip.classList.add('active');
            applyFilters();
        });
    });

    searchInput.addEventListener('input', applyFilters);
</script>
</body>
</html>
