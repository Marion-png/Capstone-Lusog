<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Feeding Head - Feeding Program</title>
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
			--teal: #2a9d8f;
			--red: #e34848;
			--g300: #86efac;
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
		.head-row { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
		.page-title { font-family: 'DM Serif Display', serif; font-size: 1.45rem; color: var(--text-1); line-height: 1.1; }
		.page-sub { margin-top: 5px; font-size: .76rem; color: var(--text-3); }

		.actions { display: flex; gap: 10px; }
		.btn {
			border: 1px solid transparent;
			border-radius: 10px;
			padding: 10px 20px;
			font-size: .75rem;
			font-weight: 700;
			cursor: pointer;
			box-shadow: var(--shadow-card);
			text-decoration: none;
			display: inline-flex;
			align-items: center;
			justify-content: center;
		}
		.btn-ghost { background: #fff; border-color: var(--border); color: var(--text-2); }
		.btn-primary { background: #1f6f5f; color: #fff; }

		.stats { margin-top: 16px; display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 12px; }
		.card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; box-shadow: var(--shadow-card); }
		.stat { padding: 12px 14px; }
		.stat .label { font-size: .7rem; color: var(--text-3); }
		.stat .num { margin-top: 7px; font-size: 1.55rem; font-family: 'DM Serif Display', serif; line-height: 1; }
		.stat .hint { margin-top: 6px; font-size: .66rem; color: var(--text-3); }

		.progress-card { margin-top: 14px; padding: 12px 14px; }
		.section-title { font-size: .78rem; color: var(--text-2); font-weight: 700; margin-bottom: 10px; }
		.prog-track {
			width: 100%;
			height: 14px;
			border-radius: 999px;
			background: linear-gradient(90deg, #d8dadd 0%, #cfd4d3 100%);
			overflow: hidden;
			box-shadow: inset 0 0 0 1px rgba(27, 58, 49, .08);
			position: relative;
		}
		.prog-fill {
			width: 56%;
			height: 100%;
			background: linear-gradient(90deg, #37b9a7 0%, #2fa595 100%);
			border-radius: 999px;
		}
		.prog-marker {
			position: absolute;
			top: -3px;
			left: 56%;
			width: 2px;
			height: 20px;
			background: #22544a;
			transform: translateX(-1px);
			border-radius: 1px;
			opacity: .7;
		}
		.prog-labels { margin-top: 8px; display: flex; justify-content: space-between; font-size: .66rem; color: var(--text-3); }
		.prog-day { margin-top: 6px; font-size: .66rem; color: #2a5f54; font-weight: 700; }

		.table-section { margin-top: 18px; border-top: 1px solid #dfe7e4; padding-top: 12px; }
		.table-title { font-size: .88rem; color: #29453d; font-weight: 700; margin-bottom: 10px; }
		.table-card { background: #fff; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; box-shadow: var(--shadow-card); }
		table { width: 100%; border-collapse: collapse; }
		th, td { padding: 11px 10px; border-bottom: 1px solid #edf2f1; font-size: .72rem; }
		th { text-align: left; color: #7a8f8a; background: linear-gradient(180deg, #fbfdfc 0%, #f4f9f7 100%); font-weight: 700; }
		tr:last-child td { border-bottom: none; }

		.student-name { font-weight: 700; color: #2a443d; line-height: 1.2; }
		.student-grade { font-size: .65rem; color: var(--text-3); margin-top: 2px; }
		.bmi-up { color: #22a65f; font-weight: 700; }
		.bmi-down { color: #e34848; font-weight: 700; }
		.trend { font-size: .62rem; font-weight: 700; border-radius: 999px; padding: 3px 8px; display: inline-block; }
		.t-improving { background: #dcfce7; color: #15803d; }
		.t-stable { background: #e5e7eb; color: #475569; }
		.t-regressing { background: #fee2e2; color: #c81e1e; }

		.modal-backdrop {
			position: fixed;
			inset: 0;
			background: rgba(8, 25, 20, .45);
			display: none;
			align-items: center;
			justify-content: center;
			z-index: 250;
			padding: 14px;
		}
		.modal-backdrop.open { display: flex; }
		.modal-panel {
			width: min(440px, 100%);
			max-height: 92vh;
			background: #fff;
			border-radius: 12px;
			box-shadow: 0 20px 60px rgba(5,46,22,.22);
			overflow: hidden;
			display: flex;
			flex-direction: column;
		}
		.modal-head {
			display: flex;
			justify-content: space-between;
			align-items: center;
			padding: 14px 16px;
			border-bottom: 1px solid var(--border);
		}
		.modal-title {
			font-size: 1.15rem;
			font-family: 'DM Serif Display', serif;
			line-height: 1;
			color: var(--text-1);
		}
		.modal-close {
			border: none;
			background: none;
			font-size: 1.5rem;
			line-height: 1;
			color: #667a74;
			cursor: pointer;
		}
		.modal-body {
			padding: 14px 16px 8px;
			overflow-y: auto;
		}
		.weight-item { margin-bottom: 14px; }
		.weight-label {
			font-size: .95rem;
			font-family: 'DM Serif Display', serif;
			color: #243f38;
		}
		.weight-label span {
			font-size: .78rem;
			font-family: 'DM Sans', sans-serif;
			font-weight: 600;
			color: #7b918b;
		}
		.weight-field-wrap {
			margin-top: 7px;
			display: grid;
			grid-template-columns: 1fr auto;
			gap: 8px;
			align-items: center;
		}
		.weight-input {
			width: 100%;
			padding: 10px 12px;
			border: 1px solid #d8e1de;
			border-radius: 10px;
			font-size: .82rem;
			font-family: 'DM Sans', sans-serif;
			color: #27423b;
		}
		.weight-unit { font-size: .78rem; color: #5f736d; }
		.modal-foot {
			display: flex;
			justify-content: flex-end;
			gap: 8px;
			padding: 12px 16px 16px;
			border-top: 1px solid var(--border);
		}

		@media (max-width: 1050px) {
			.stats { grid-template-columns: repeat(2, minmax(0,1fr)); }
			.head-row { flex-direction: column; }
		}
		@media (max-width: 780px) {
			.sidebar { display: none; }
			.main { margin-left: 0; }
			.content { padding: 14px; }
			.stats { grid-template-columns: 1fr; }
			.actions { width: 100%; }
			.btn { flex: 1; }
			.table-card { overflow-x: auto; }
			table { min-width: 760px; }
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
		<a href="{{ route('dashboard.feedingcor-health-records') }}" class="sb-link">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
			Student Health Records
		</a>
		<a href="{{ route('dashboard.feedingcor-program') }}" class="sb-link active">
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
		<div class="topbar-bc"><span>Dashboard</span><span>&gt;</span><span>Feeding Program</span></div>
		<div class="topbar-chip"><div class="dot"></div>Monitoring Active</div>
	</header>

	<div class="content">
		<div class="head-row">
			<div>
				<h1 class="page-title">Feeding Program</h1>
				<p class="page-sub">120-Day Supplementary Feeding Program tracking.</p>
			</div>
			<div class="actions">
				<button type="button" class="btn btn-ghost">Record Attendance</button>
				<button type="button" class="btn btn-primary" id="openWeightsModal">Update Weights</button>
			</div>
		</div>

		<section class="stats">
			<article class="card stat">
				<div class="label">Enrolled Students</div>
				<div class="num">48</div>
			</article>
			<article class="card stat">
				<div class="label">Program Day</div>
				<div class="num">67/120</div>
			</article>
			<article class="card stat">
				<div class="label">Avg. Attendance</div>
				<div class="num">82%</div>
			</article>
			<article class="card stat">
				<div class="label">Improving</div>
				<div class="num">72%</div>
				<div class="hint">33 of 46 students</div>
			</article>
		</section>

		<section class="card progress-card">
			<h2 class="section-title">Program Progress</h2>
			<div class="prog-track"><div class="prog-fill"></div><div class="prog-marker"></div></div>
			<div class="prog-day">Day 67 of 120</div>
			<div class="prog-labels"><span>Baseline (Day 1)</span><span>Endline(Day 120)</span></div>
		</section>

		<section class="table-section">
			<h2 class="table-title">Enrolled Students</h2>
			<div class="table-card">
				<table>
					<thead>
						<tr>
							<th>Student</th>
							<th>Baseline Wt</th>
							<th>Current Wt</th>
							<th>BMI Change</th>
							<th>Attendance</th>
							<th>Trend</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><div class="student-name">Maria Santos</div><div class="student-grade">Grade 3</div></td>
							<td>18 kg</td>
							<td><strong class="current-weight" data-student="Maria Santos">19.5 kg</strong></td>
							<td><span class="bmi-up">12.5 - 13.5</span></td>
							<td>58/67 days</td>
							<td><span class="trend t-improving">improving</span></td>
						</tr>
						<tr>
							<td><div class="student-name">Juan Dela Cruz</div><div class="student-grade">Grade 4</div></td>
							<td>25 kg</td>
							<td><strong class="current-weight" data-student="Juan Dela Cruz">26.8 kg</strong></td>
							<td><span class="bmi-up">14.8 - 15.9</span></td>
							<td>65/67 days</td>
							<td><span class="trend t-improving">improving</span></td>
						</tr>
						<tr>
							<td><div class="student-name">Ana Reyes</div><div class="student-grade">Grade 2</div></td>
							<td>22 kg</td>
							<td><strong class="current-weight" data-student="Ana Reyes">22.3 kg</strong></td>
							<td><span class="bmi-up">15.8 - 16</span></td>
							<td>40/67 days</td>
							<td><span class="trend t-stable">stable</span></td>
						</tr>
						<tr>
							<td><div class="student-name">Pedro Villanueva</div><div class="student-grade">Grade 5</div></td>
							<td>28 kg</td>
							<td><strong class="current-weight" data-student="Pedro Villanueva">27.5 kg</strong></td>
							<td><span class="bmi-down">14.2 - 14</span></td>
							<td>30/67 days</td>
							<td><span class="trend t-regressing">regressing</span></td>
						</tr>
					</tbody>
				</table>
			</div>
		</section>
	</div>
</div>

<div class="modal-backdrop" id="weightsModalBackdrop" aria-hidden="true">
	<div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="weightsModalTitle">
		<div class="modal-head">
			<h2 id="weightsModalTitle" class="modal-title">Update Student Weights</h2>
			<button type="button" class="modal-close" id="closeWeightsModal" aria-label="Close">&times;</button>
		</div>
		<form id="weightsForm">
			<div class="modal-body">
				<div class="weight-item">
					<div class="weight-label">Maria Santos <span>(Grade 3)</span></div>
					<div class="weight-field-wrap">
						<input type="number" class="weight-input" data-student="Maria Santos" step="0.1" min="1" value="19.5" required>
						<span class="weight-unit">kg</span>
					</div>
				</div>
				<div class="weight-item">
					<div class="weight-label">Juan Dela Cruz <span>(Grade 4)</span></div>
					<div class="weight-field-wrap">
						<input type="number" class="weight-input" data-student="Juan Dela Cruz" step="0.1" min="1" value="26.8" required>
						<span class="weight-unit">kg</span>
					</div>
				</div>
				<div class="weight-item">
					<div class="weight-label">Ana Reyes <span>(Grade 2)</span></div>
					<div class="weight-field-wrap">
						<input type="number" class="weight-input" data-student="Ana Reyes" step="0.1" min="1" value="22.3" required>
						<span class="weight-unit">kg</span>
					</div>
				</div>
				<div class="weight-item">
					<div class="weight-label">Pedro Villanueva <span>(Grade 5)</span></div>
					<div class="weight-field-wrap">
						<input type="number" class="weight-input" data-student="Pedro Villanueva" step="0.1" min="1" value="27.5" required>
						<span class="weight-unit">kg</span>
					</div>
				</div>
			</div>
			<div class="modal-foot">
				<button type="button" class="btn btn-ghost" id="cancelWeightsModal">Cancel</button>
				<button type="submit" class="btn btn-primary">Save Weights</button>
			</div>
		</form>
	</div>
</div>

<script>
(() => {
	const openBtn = document.getElementById('openWeightsModal');
	const backdrop = document.getElementById('weightsModalBackdrop');
	const closeBtn = document.getElementById('closeWeightsModal');
	const cancelBtn = document.getElementById('cancelWeightsModal');
	const form = document.getElementById('weightsForm');
	const weightCells = Array.from(document.querySelectorAll('.current-weight'));
	const inputs = Array.from(form ? form.querySelectorAll('.weight-input') : []);

	if (!openBtn || !backdrop || !closeBtn || !cancelBtn || !form) {
		return;
	}

	const tableWeightByStudent = () => {
		const map = new Map();
		weightCells.forEach((cell) => {
			const student = cell.dataset.student;
			const raw = (cell.textContent || '').trim();
			const parsed = parseFloat(raw.replace('kg', '').trim());
			if (student && !Number.isNaN(parsed)) {
				map.set(student, parsed);
			}
		});
		return map;
	};

	const syncInputsFromTable = () => {
		const weights = tableWeightByStudent();
		inputs.forEach((input) => {
			const student = input.dataset.student;
			if (!student || !weights.has(student)) {
				return;
			}
			input.value = Number(weights.get(student)).toFixed(1);
		});
	};

	const closeModal = () => {
		backdrop.classList.remove('open');
		document.body.style.overflow = '';
	};

	openBtn.addEventListener('click', () => {
		syncInputsFromTable();
		backdrop.classList.add('open');
		document.body.style.overflow = 'hidden';
	});

	closeBtn.addEventListener('click', closeModal);
	cancelBtn.addEventListener('click', closeModal);

	backdrop.addEventListener('click', (event) => {
		if (event.target === backdrop) {
			closeModal();
		}
	});

	document.addEventListener('keydown', (event) => {
		if (event.key === 'Escape' && backdrop.classList.contains('open')) {
			closeModal();
		}
	});

	form.addEventListener('submit', (event) => {
		event.preventDefault();

		inputs.forEach((input) => {
			const student = input.dataset.student;
			const nextValue = Number(input.value);
			if (Number.isNaN(nextValue)) {
				return;
			}
			const nextWeight = `${nextValue.toFixed(1)} kg`;
			const targetCell = weightCells.find((cell) => cell.dataset.student === student);
			if (targetCell) {
				targetCell.textContent = nextWeight;
			}
		});

		closeModal();
	});
})();
</script>
</body>
</html>
