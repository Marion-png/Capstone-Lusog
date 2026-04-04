<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Feeding Head - Student Health Records</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
	<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
	<style>
		*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
		:root {
			--g900: #14532d;
			--g800: #166534;
			--g700: #15803d;
			--g600: #16a34a;
			--g300: #86efac;
			--teal: #2a9d8f;
			--blue: #2f89d7;
			--red: #e34848;
			--cream: #f7f8f5;
			--card: #ffffff;
			--border: #e4ece7;
			--text-1: #0d1f14;
			--text-2: #3d5c47;
			--text-3: #7a9e87;
			--sidebar-w: 248px;
			--topbar-h: 64px;
			--radius-sm: 10px;
			--shadow-card: 0 1px 4px rgba(5,46,22,.06), 0 4px 16px rgba(5,46,22,.06);
		}

		html, body { height: 100%; font-family: 'DM Sans', sans-serif; background: var(--cream); color: var(--text-1); overflow: hidden; }

		.sidebar {
			position: fixed; left: 0; top: 0; bottom: 0;
			width: var(--sidebar-w); background: var(--g900);
			display: flex; flex-direction: column; z-index: 100; overflow: hidden;
		}
		.sidebar::after {
			content: ''; position: absolute; inset: 0;
			background: radial-gradient(ellipse 120% 40% at 50% 100%, rgba(34,197,94,.18) 0%, transparent 70%),
						radial-gradient(ellipse 80% 30% at 80% 0%, rgba(74,222,128,.1) 0%, transparent 60%);
			pointer-events: none;
		}
		.sb-grid {
			position: absolute; inset: 0;
			background-image: linear-gradient(rgba(134,239,172,.05) 1px, transparent 1px),
							  linear-gradient(90deg, rgba(134,239,172,.05) 1px, transparent 1px);
			background-size: 28px 28px;
		}
		.sb-logo { padding: 20px 20px 18px; position: relative; z-index: 2; border-bottom: 1px solid rgba(255,255,255,.08); display: flex; justify-content: center; }
		.sb-logo-full { width: 176px; max-width: 100%; height: auto; display: block; }
		.sb-nav { flex: 1; overflow-y: auto; padding: 16px 12px; position: relative; z-index: 2; }
		.sb-section-label { font-size: .6rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: rgba(134,239,172,.5); padding: 0 8px; margin: 8px 0; }
		.sb-link { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: var(--radius-sm); text-decoration: none; color: rgba(255,255,255,.62); font-size: .83rem; font-weight: 500; transition: background .15s, color .15s; margin-bottom: 2px; }
		.sb-link:hover { background: rgba(255,255,255,.08); color: rgba(255,255,255,.9); }
		.sb-link.active { background: rgba(34,197,94,.18); color: var(--g300); }
		.sb-link svg { width: 16px; height: 16px; flex-shrink: 0; }
		.sb-user { padding: 14px 16px; border-top: 1px solid rgba(255,255,255,.08); display: flex; align-items: center; gap: 11px; position: relative; z-index: 2; }
		.sb-avatar { width: 34px; height: 34px; border-radius: 50%; background: var(--g600); display: grid; place-items: center; font-size: .8rem; font-weight: 700; color: white; flex-shrink: 0; }
		.sb-user-name { font-size: .8rem; font-weight: 600; color: white; line-height: 1.2; }
		.sb-user-role { font-size: .68rem; color: var(--g300); }

		.main { margin-left: var(--sidebar-w); height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
		.topbar { height: var(--topbar-h); border-bottom: 1px solid var(--border); background: #fff; display: flex; align-items: center; justify-content: space-between; padding: 0 22px; }
		.topbar-bc { font-size: .76rem; color: var(--text-3); display: flex; gap: 6px; align-items: center; }
		.topbar-chip { font-size: .72rem; border: 1px solid #bbf7d0; color: #15803d; background: #f0fdf4; border-radius: 999px; padding: 5px 11px; display: flex; align-items: center; gap: 7px; }
		.topbar-chip .dot { width: 6px; height: 6px; border-radius: 50%; background: #22c55e; }

		.content { overflow: auto; padding: 18px; }
		.page-eyebrow { font-size: .68rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: #15803d; margin-bottom: 6px; }
		.page-title { font-family: 'DM Serif Display', serif; font-size: 1.75rem; color: var(--text-1); line-height: 1.15; }
		.page-title span { font-style: italic; color: #15803d; }
		.page-sub { margin-top: 5px; font-size: .8rem; color: var(--text-3); }

		.notice {
			margin-top: 14px;
			background: #bde3ef;
			border: 1px solid #63beda;
			color: #155a73;
			border-radius: 999px;
			padding: 8px 14px;
			font-size: .74rem;
		}

		.analytics-card {
			margin-top: 12px;
			background: #fff;
			border: 1px solid var(--border);
			border-radius: 10px;
			box-shadow: var(--shadow-card);
			padding: 12px 14px;
		}
		.analytics-title {
			font-size: .76rem;
			font-weight: 700;
			color: var(--text-2);
			margin-bottom: 8px;
		}
		.status-bar {
			height: 14px;
			border-radius: 999px;
			overflow: hidden;
			background: #f1f5f4;
			display: flex;
			box-shadow: inset 0 0 0 1px #e3ece9;
		}
		.seg-severe { background: #dc2626; width: 12.5%; }
		.seg-wasted { background: #ef4444; width: 25%; }
		.seg-normal { background: #22a65f; width: 50%; }
		.seg-over { background: #64748b; width: 12.5%; }
		.analytics-legend {
			margin-top: 8px;
			display: flex;
			gap: 10px;
			flex-wrap: wrap;
		}
		.legend-item {
			font-size: .64rem;
			color: var(--text-3);
			display: inline-flex;
			align-items: center;
			gap: 4px;
		}
		.legend-dot {
			width: 7px;
			height: 7px;
			border-radius: 50%;
			display: inline-block;
		}

		.toolbar {
			margin-top: 16px;
			display: grid;
			grid-template-columns: 1.2fr auto repeat(4, auto);
			gap: 10px;
			align-items: center;
		}
		.search {
			width: 100%;
			border: 1px solid var(--border);
			border-radius: 12px;
			background: #fff;
			padding: 10px 12px;
			font-size: .76rem;
			color: var(--text-2);
			box-shadow: var(--shadow-card);
		}
		.filter-btn {
			border: 1px solid var(--border);
			background: #fff;
			color: #5f736d;
			border-radius: 10px;
			padding: 10px 14px;
			font-size: .72rem;
			box-shadow: var(--shadow-card);
			font-weight: 600;
		}
		.filter-btn.active {
			background: #1f6f5f;
			color: #fff;
			border-color: #1f6f5f;
		}

		.table-card {
			margin-top: 14px;
			background: #fff;
			border: 1px solid var(--border);
			border-radius: 10px;
			overflow: hidden;
			box-shadow: var(--shadow-card);
		}
		table { width: 100%; border-collapse: collapse; }
		th, td { padding: 10px 10px; border-bottom: 1px solid #edf2f1; font-size: .72rem; }
		th { text-align: left; color: #7a8f8a; background: #f9fbfa; font-weight: 700; }
		tr:last-child td { border-bottom: none; }
		td strong { color: #2d433d; }

		.warn { color: #f59e0b; font-size: .75rem; margin-right: 6px; }
		.bmi { font-weight: 700; color: #2f4b44; }
		.status {
			font-size: .62rem;
			font-weight: 700;
			border-radius: 999px;
			padding: 3px 8px;
			display: inline-block;
		}
		.s-severe { background: #fee2e2; color: #c81e1e; }
		.s-wasted { background: #fee2e2; color: #d62f2f; }
		.s-normal { background: #dcfce7; color: #15803d; }
		.s-over { background: #e5e7eb; color: #475569; }

		@media (max-width: 1050px) {
			.toolbar { grid-template-columns: 1fr 1fr; }
			.filter-btn { width: 100%; }
		}
		@media (max-width: 780px) {
			.sidebar { display: none; }
			.main { margin-left: 0; }
			.content { padding: 14px; }
			.table-card { overflow-x: auto; }
			table { min-width: 720px; }
			.toolbar { grid-template-columns: 1fr; }
		}
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
		<a href="{{ route('dashboard.feedingcor-dashboard') }}" class="sb-link">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
			Dashboard
		</a>
		<a href="{{ route('dashboard.feedingcor-health-records') }}" class="sb-link active">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
			Student Health Records
		</a>
		<a href="{{ route('dashboard.feedingcor-program') }}" class="sb-link">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
			Feeding Program
		</a>
	</nav>
	<div class="sb-user">
		<div class="sb-avatar">{{ substr(auth()->user()->name ?? 'FC', 0, 2) }}</div>
		<div>
			<div class="sb-user-name">{{ auth()->user()->name ?? 'Feeding Coordinator' }}</div>
			<div class="sb-user-role">Feeding Program Coordinator - DCNHS</div>
		</div>
	</div>
</aside>

<div class="main">
	<header class="topbar">
		<div class="topbar-bc"><span>Dashboard</span><span>&gt;</span><span>Student Health Records</span></div>
		<div class="topbar-chip"><div class="dot"></div>View Only</div>
	</header>

	<div class="content" id="student-health-records">
		<div class="page-eyebrow">Feeding Program</div>
		<h1 class="page-title">Student <span>Health Records</span></h1>
		<p class="page-sub">8 students · 3 flagged as wasted</p>

		<div class="notice">
			View Only - You can view flagged students for feeding enrollment.
		</div>

		<div class="analytics-card" aria-label="Health status distribution">
			<div class="analytics-title">Status Distribution</div>
			<div class="status-bar">
				<span class="seg-severe"></span>
				<span class="seg-wasted"></span>
				<span class="seg-normal"></span>
				<span class="seg-over"></span>
			</div>
			<div class="analytics-legend">
				<span class="legend-item"><span class="legend-dot" style="background:#dc2626"></span>Severely Wasted (1)</span>
				<span class="legend-item"><span class="legend-dot" style="background:#ef4444"></span>Wasted (2)</span>
				<span class="legend-item"><span class="legend-dot" style="background:#22a65f"></span>Normal (4)</span>
				<span class="legend-item"><span class="legend-dot" style="background:#64748b"></span>Overweight (1)</span>
			</div>
		</div>

		<div class="toolbar">
			<input class="search" type="text" value="" placeholder="Search student name..." aria-label="Search student name">
			<button class="filter-btn active" type="button">All</button>
			<button class="filter-btn" type="button">Severely Wasted</button>
			<button class="filter-btn" type="button">Wasted</button>
			<button class="filter-btn" type="button">Normal</button>
			<button class="filter-btn" type="button">Overweight</button>
		</div>

		<div class="table-card">
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
				<tbody>
					<tr>
						<td><span class="warn">&#9888;</span><strong>Maria Santos</strong></td>
						<td>Grade 3 - Sampaguita</td>
						<td>9</td>
						<td>120</td>
						<td>18</td>
						<td class="bmi">12.5</td>
						<td><span class="status s-severe">Severely Wasted</span></td>
					</tr>
					<tr>
						<td><span class="warn">&#9888;</span><strong>Juan Dela Cruz</strong></td>
						<td>Grade 4 - Narra</td>
						<td>10</td>
						<td>130</td>
						<td>25</td>
						<td class="bmi">14.8</td>
						<td><span class="status s-wasted">Wasted</span></td>
					</tr>
					<tr>
						<td><span class="warn">&#9888;</span><strong>Ana Reyes</strong></td>
						<td>Grade 2 - Rosal</td>
						<td>8</td>
						<td>118</td>
						<td>22</td>
						<td class="bmi">15.8</td>
						<td><span class="status s-wasted">Wasted</span></td>
					</tr>
					<tr>
						<td><strong>Carlos Garcia</strong></td>
						<td>Grade 5 - Mahogany</td>
						<td>11</td>
						<td>140</td>
						<td>35</td>
						<td class="bmi">17.9</td>
						<td><span class="status s-normal">Normal</span></td>
					</tr>
					<tr>
						<td><strong>Sofia Lim</strong></td>
						<td>Grade 1 - Dahlia</td>
						<td>7</td>
						<td>110</td>
						<td>20</td>
						<td class="bmi">16.5</td>
						<td><span class="status s-normal">Normal</span></td>
					</tr>
					<tr>
						<td><strong>Miguel Torres</strong></td>
						<td>Grade 6 - Acacia</td>
						<td>12</td>
						<td>148</td>
						<td>42</td>
						<td class="bmi">19.2</td>
						<td><span class="status s-normal">Normal</span></td>
					</tr>
					<tr>
						<td><strong>Isabella Cruz</strong></td>
						<td>Grade 3 - Sampaguita</td>
						<td>9</td>
						<td>122</td>
						<td>30</td>
						<td class="bmi">20.2</td>
						<td><span class="status s-normal">Normal</span></td>
					</tr>
					<tr>
						<td><strong>Diego Mendoza</strong></td>
						<td>Grade 4 - Narra</td>
						<td>10</td>
						<td>132</td>
						<td>40</td>
						<td class="bmi">23</td>
						<td><span class="status s-over">Overweight</span></td>
					</tr>
				</tbody>
			</table>
		</div>

		<div id="dashboard"></div>
		<div id="feeding-program"></div>
	</div>
</div>
<script>
(() => {
	const searchInput = document.querySelector('.search');
	const filterButtons = Array.from(document.querySelectorAll('.filter-btn'));
	const rows = Array.from(document.querySelectorAll('tbody tr'));

	if (!searchInput || filterButtons.length === 0 || rows.length === 0) {
		return;
	}

	let activeStatus = 'all';

	const normalize = (value) => value.toLowerCase().trim();

	const applyFilters = () => {
		const query = normalize(searchInput.value);

		rows.forEach((row) => {
			const name = normalize(row.querySelector('td strong')?.textContent || '');
			const status = normalize(row.querySelector('.status')?.textContent || '');

			const matchesName = name.includes(query);
			const matchesStatus = activeStatus === 'all' || status === activeStatus;

			row.style.display = matchesName && matchesStatus ? '' : 'none';
		});
	};

	searchInput.addEventListener('input', applyFilters);

	filterButtons.forEach((button) => {
		button.addEventListener('click', () => {
			filterButtons.forEach((btn) => btn.classList.remove('active'));
			button.classList.add('active');
			activeStatus = normalize(button.textContent);
			applyFilters();
		});
	});
})();
</script>
</body>
</html>
