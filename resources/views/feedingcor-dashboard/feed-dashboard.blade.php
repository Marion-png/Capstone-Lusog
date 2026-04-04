<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Feeding Head - Dashboard</title>
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
			--blue: #2f89d7;
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
			--radius: 16px;
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
		.page-header { margin-bottom: 14px; }
		.page-eyebrow { font-size: .68rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: #15803d; margin-bottom: 6px; }
		.page-title { font-family: 'DM Serif Display', serif; font-size: 1.75rem; color: var(--text-1); line-height: 1.15; }
		.page-title span { font-style: italic; color: #15803d; }
		.page-sub { margin-top: 5px; font-size: .8rem; color: var(--text-3); }
		.page-header-actions { margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap; }

		.btn {
			appearance: none;
			border: 1px solid transparent;
			border-radius: 8px;
			padding: 8px 12px;
			font-size: .74rem;
			font-weight: 600;
			cursor: pointer;
			text-decoration: none;
			display: inline-flex;
			align-items: center;
			justify-content: center;
		}
		.btn-primary { background: var(--g700); color: #fff; }
		.btn-primary:hover { background: var(--g800); }
		.btn-ghost { background: #fff; color: #3d5c47; border-color: #d1d5db; }
		.btn-ghost:hover { background: #f0fdf4; border-color: #86efac; color: #15803d; }

		.stats { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 12px; margin-bottom: 14px; }
		.card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; box-shadow: var(--shadow-card); }
		.stat { padding: 12px 14px; }
		.stat .label { font-size: .68rem; color: var(--text-3); }
		.stat .num { margin-top: 7px; font-family: 'DM Serif Display', serif; font-size: 1.5rem; line-height: 1; }
		.stat .hint { margin-top: 6px; font-size: .66rem; color: var(--text-3); }

		.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
		.section { padding: 14px; }
		.section-title { font-size: .82rem; letter-spacing: .02em; color: var(--text-2); margin-bottom: 10px; font-weight: 700; }

		.chart-card { padding: 14px; }
		.chart-title { font-size: .78rem; color: var(--text-2); font-weight: 700; margin-bottom: 8px; }
		.chart-surface {
			border: 1px solid #e8efed;
			border-radius: 10px;
			background: linear-gradient(180deg, #ffffff 0%, #fbfdfc 100%);
			padding: 8px;
		}
		.chart-svg { width: 100%; height: 185px; display: block; }
		.axis-txt { fill: #8aa09c; font-size: 10px; font-family: 'DM Sans', sans-serif; }
		.grid-line { stroke: #dbe4e2; stroke-dasharray: 3 5; }
		.axis-line { stroke: #97ada8; stroke-width: 1.2; }
		.area-fill { fill: url(#bmiAreaGradient); }
		.line-main { fill: none; stroke: var(--teal); stroke-width: 2.4; }
		.line-dot { fill: var(--teal); }

		.donut-wrap { height: 185px; display: grid; place-items: center; }
		.donut {
			width: 138px;
			height: 138px;
			border-radius: 50%;
			background: conic-gradient(var(--teal) 0 72%, var(--blue) 72% 89%, var(--red) 89% 100%);
			position: relative;
			box-shadow: inset 0 0 0 1px rgba(18, 56, 44, .08), 0 8px 18px rgba(9, 39, 30, .08);
		}
		.donut::after {
			content: '';
			position: absolute;
			inset: 28px;
			background: #fff;
			border-radius: 50%;
		}
		.donut-center {
			position: absolute;
			inset: 0;
			display: grid;
			place-items: center;
			z-index: 2;
			text-align: center;
			font-size: .66rem;
			color: #5e7871;
		}
		.donut-center strong {
			display: block;
			font-size: 1rem;
			line-height: 1;
			color: #27443d;
			margin-bottom: 2px;
		}
		.legend-row { margin-top: 6px; display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
		.legend-item { font-size: .64rem; color: var(--text-3); display: inline-flex; align-items: center; gap: 4px; }
		.legend-dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; }

		.full-chart { margin-top: 12px; }
		.bars-area { height: 178px; display: flex; gap: 16px; align-items: flex-end; border-left: 2px solid #b4c3c0; border-bottom: 2px solid #b4c3c0; padding: 0 12px 0 12px; position: relative; background: linear-gradient(180deg, #fcfefd 0%, #f8fbfa 100%); border-radius: 8px 8px 0 0; }
		.bars-area::before, .bars-area::after {
			content: '';
			position: absolute;
			left: 12px;
			right: 0;
			border-top: 1px dashed #d6dfdd;
		}
		.bars-area::before { top: 46px; }
		.bars-area::after { top: 94px; }
		.bar-col { flex: 1; min-width: 0; height: 100%; display: flex; flex-direction: column; justify-content: flex-end; }
		.bar-stack { border-radius: 8px 8px 0 0; overflow: hidden; display: flex; flex-direction: column-reverse; box-shadow: inset 0 0 0 1px rgba(16, 58, 46, .05); }
		.bar-good { background: var(--teal); }
		.bar-risk { background: var(--red); }
		.bar-label { margin-top: 7px; font-size: .62rem; color: var(--text-3); text-align: center; }
		.bar-value { font-size: .58rem; color: #6f8681; text-align: center; margin-bottom: 4px; font-weight: 600; }

		.records-wrap { margin-top: 12px; }
		.records-head { padding: 14px; }
		.records-title { font-family: 'DM Serif Display', serif; font-size: 1.35rem; color: var(--text-1); }
		.records-sub { margin-top: 4px; font-size: .72rem; color: var(--text-3); }
		.records-note {
			margin-top: 12px;
			background: #bde3ef;
			border: 1px solid #63beda;
			color: #155a73;
			border-radius: 999px;
			padding: 8px 14px;
			font-size: .74rem;
		}
		.records-toolbar {
			margin-top: 14px;
			display: grid;
			grid-template-columns: 1.2fr auto repeat(4, auto);
			gap: 10px;
			align-items: center;
		}
		.records-search {
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
			margin-top: 12px;
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
			.stats { grid-template-columns: repeat(2, minmax(0,1fr)); }
			.grid-2 { grid-template-columns: 1fr; }
			.records-toolbar { grid-template-columns: 1fr 1fr; }
			.filter-btn { width: 100%; }
		}
		@media (max-width: 780px) {
			.sidebar { display: none; }
			.main { margin-left: 0; }
			.content { padding: 14px; }
			.stats { grid-template-columns: 1fr; }
			.records-toolbar { grid-template-columns: 1fr; }
			.table-card { overflow-x: auto; }
			table { min-width: 720px; }
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
		<a href="{{ route('dashboard.feedingcor-dashboard') }}" class="sb-link active">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
			Dashboard
		</a>
		<a href="{{ route('dashboard.feedingcor-health-records') }}" class="sb-link">
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
		<div class="topbar-bc"><span>Dashboard</span><span>&gt;</span><span>Feeding Program</span></div>
		<div class="topbar-chip"><div class="dot"></div>Monitoring Active</div>
	</header>

	<div class="content">
		<div class="page-header" id="dashboard">
			<div class="page-eyebrow">Feeding Program</div>
			<h1 class="page-title">Dashboard <span>Feeding Program</span></h1>
			<p class="page-sub">Monitor participation, outcomes, and weekly attendance at a glance.</p>
		</div>

		<section class="stats">
			<article class="card stat">
				<div class="label">Enrolled Students</div>
				<div class="num">46</div>
				<div class="hint">In feeding program</div>
			</article>
			<article class="card stat">
				<div class="label">Program Day</div>
				<div class="num">67</div>
				<div class="hint">of 120 day cycle</div>
			</article>
			<article class="card stat">
				<div class="label">Improving</div>
				<div class="num">72%</div>
				<div class="hint">33 of 46 students</div>
			</article>
			<article class="card stat">
				<div class="label">Avg Attendance</div>
				<div class="num">91%</div>
				<div class="hint">This week</div>
			</article>
		</section>

		<section class="grid-2" id="feeding-program">
			<article class="card chart-card">
				<h2 class="chart-title">Avg BMI Progress Over Time</h2>
				<div class="chart-surface">
				<svg class="chart-svg" viewBox="0 0 520 250" role="img" aria-label="Average BMI progress line chart">
					<defs>
						<linearGradient id="bmiAreaGradient" x1="0" y1="0" x2="0" y2="1">
							<stop offset="0%" stop-color="#2a9d8f" stop-opacity="0.25"></stop>
							<stop offset="100%" stop-color="#2a9d8f" stop-opacity="0"></stop>
						</linearGradient>
					</defs>
					<line class="axis-line" x1="48" y1="20" x2="48" y2="210"></line>
					<line class="axis-line" x1="48" y1="210" x2="500" y2="210"></line>
					<line class="grid-line" x1="48" y1="52" x2="500" y2="52"></line>
					<line class="grid-line" x1="48" y1="122" x2="500" y2="122"></line>
					<line class="grid-line" x1="48" y1="178" x2="500" y2="178"></line>
					<line class="grid-line" x1="48" y1="20" x2="48" y2="210"></line>
					<line class="grid-line" x1="138" y1="20" x2="138" y2="210"></line>
					<line class="grid-line" x1="228" y1="20" x2="228" y2="210"></line>
					<line class="grid-line" x1="318" y1="20" x2="318" y2="210"></line>
					<line class="grid-line" x1="408" y1="20" x2="408" y2="210"></line>
					<line class="grid-line" x1="500" y1="20" x2="500" y2="210"></line>

					<polygon class="area-fill" points="48,210 48,175 138,150 228,132 318,108 408,82 500,62 500,210"></polygon>
					<polyline class="line-main" points="48,175 138,150 228,132 318,108 408,82 500,62"></polyline>
					<circle class="line-dot" cx="48" cy="175" r="3.5"></circle>
					<circle class="line-dot" cx="138" cy="150" r="3.5"></circle>
					<circle class="line-dot" cx="228" cy="132" r="3.5"></circle>
					<circle class="line-dot" cx="318" cy="108" r="3.5"></circle>
					<circle class="line-dot" cx="408" cy="82" r="3.5"></circle>
					<circle class="line-dot" cx="500" cy="62" r="3.5"></circle>

					<text class="axis-txt" x="28" y="56">19</text>
					<text class="axis-txt" x="28" y="126">16</text>
					<text class="axis-txt" x="28" y="182">14</text>
					<text class="axis-txt" x="40" y="228">Jan</text>
					<text class="axis-txt" x="130" y="228">Feb</text>
					<text class="axis-txt" x="220" y="228">Mar</text>
					<text class="axis-txt" x="310" y="228">Apr</text>
					<text class="axis-txt" x="402" y="228">May</text>
				</svg>
				</div>
			</article>

			<article class="card chart-card">
				<h2 class="chart-title">Student Progress Breakdown</h2>
				<div class="donut-wrap">
					<div class="donut">
						<div class="donut-center"><div><strong>46</strong>Students</div></div>
					</div>
				</div>
				<div class="legend-row">
					<span class="legend-item"><span class="legend-dot" style="background: var(--teal);"></span>Improving (33)</span>
					<span class="legend-item"><span class="legend-dot" style="background: var(--blue);"></span>Stable (8)</span>
					<span class="legend-item"><span class="legend-dot" style="background: var(--red);"></span>Regressing (5)</span>
				</div>
			</article>
		</section>

		<section class="card chart-card full-chart">
			<h2 class="chart-title">Weekly Attendance</h2>
			<div class="bars-area">
				<div class="bar-col"><div class="bar-value">46</div><div class="bar-stack"><div class="bar-good" style="height: 126px;"></div><div class="bar-risk" style="height: 14px;"></div></div><div class="bar-label">Week 1</div></div>
				<div class="bar-col"><div class="bar-value">44</div><div class="bar-stack"><div class="bar-good" style="height: 122px;"></div><div class="bar-risk" style="height: 16px;"></div></div><div class="bar-label">Week 2</div></div>
				<div class="bar-col"><div class="bar-value">45</div><div class="bar-stack"><div class="bar-good" style="height: 128px;"></div><div class="bar-risk" style="height: 12px;"></div></div><div class="bar-label">Week 3</div></div>
				<div class="bar-col"><div class="bar-value">43</div><div class="bar-stack"><div class="bar-good" style="height: 118px;"></div><div class="bar-risk" style="height: 20px;"></div></div><div class="bar-label">Week 4</div></div>
				<div class="bar-col"><div class="bar-value">45</div><div class="bar-stack"><div class="bar-good" style="height: 126px;"></div><div class="bar-risk" style="height: 14px;"></div></div><div class="bar-label">Week 5</div></div>
			</div>
		</section>

	</div>
</div>
</body>
</html>
