<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>School Head Reports - LUSOG</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
	<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
	<style>
		*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
		:root {
			--g900: #14532d;
			--g300: #86efac;
			--sidebar-w: 248px;
			--topbar-h: 64px;
			--cream: #f7f8f5;
			--card: #ffffff;
			--border: #e4ece7;
			--text-1: #0d1f14;
			--text-2: #3d5c47;
			--text-3: #7a9e87;
			--shadow-card: 0 1px 4px rgba(5,46,22,.06), 0 4px 16px rgba(5,46,22,.06);
			--radius-sm: 10px;
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
		.sb-avatar { width: 34px; height: 34px; border-radius: 50%; background: #16a34a; display: grid; place-items: center; font-size: .8rem; font-weight: 700; color: white; }
		.sb-user-name { font-size: .8rem; font-weight: 600; color: white; }
		.sb-user-role { font-size: .68rem; color: var(--g300); }

		.main { margin-left: var(--sidebar-w); height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
		.topbar { height: var(--topbar-h); border-bottom: 1px solid var(--border); background: #fff; display: flex; align-items: center; justify-content: space-between; padding: 0 22px; }
		.topbar-bc { font-size: .76rem; color: var(--text-3); display: flex; gap: 6px; align-items: center; }

		.content { overflow: auto; padding: 18px; }
		.page-eyebrow { font-size: .68rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: #15803d; margin-bottom: 6px; }
		.page-title { font-family: 'DM Serif Display', serif; font-size: 1.75rem; color: var(--text-1); line-height: 1.15; }
		.page-title span { font-style: italic; color: #15803d; }
		.page-sub { margin-top: 5px; font-size: .8rem; color: var(--text-3); margin-bottom: 14px; }

		.stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; }
		.card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; box-shadow: var(--shadow-card); }
		.stat { padding: 12px 14px; }
		.stat .label { font-size: .68rem; color: var(--text-3); }
		.stat .num { margin-top: 7px; font-family: 'DM Serif Display', serif; font-size: 1.5rem; line-height: 1; }

		.section { padding: 14px; }
		.section-title { font-size: .82rem; letter-spacing: .02em; color: var(--text-2); margin-bottom: 10px; font-weight: 700; }
		table { width: 100%; border-collapse: collapse; }
		th, td { font-size: .74rem; text-align: left; padding: 10px 8px; border-bottom: 1px solid var(--border); }
		th { color: var(--text-3); font-weight: 600; }

		@media (max-width: 1050px) { .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
		@media (max-width: 780px) {
			.sidebar { display: none; }
			.main { margin-left: 0; }
			.stats { grid-template-columns: 1fr; }
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
		<a href="{{ route('dashboard.school-head') }}" class="sb-link">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
			Dashboard
		</a>
		<div class="sb-section-label">Reports</div>
		<a href="{{ route('dashboard.school-head.reports') }}" class="sb-link active">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
			Reports
		</a>
	</nav>
	<div class="sb-user">
		<div class="sb-avatar">{{ substr(auth()->user()->name ?? 'SH', 0, 2) }}</div>
		<div>
			<div class="sb-user-name">{{ auth()->user()->name ?? 'School Head' }}</div>
			<div class="sb-user-role">School Head - DCNHS</div>
		</div>
	</div>
</aside>

<div class="main">
	<header class="topbar">
		<div class="topbar-bc"><span>Dashboard</span><span>&gt;</span><span>Reports</span></div>
	</header>

	<div class="content">
		<div class="page-eyebrow">School Head Reports</div>
		<h1 class="page-title">Executive <span>Reports Overview</span></h1>
		<p class="page-sub">Summarized reporting status for school-level health programs.</p>

		<section class="stats">
			<article class="card stat">
				<div class="label">Submission Rate</div>
				<div class="num">{{ $reportStats['submission_rate'] ?? '0%' }}</div>
			</article>
			<article class="card stat">
				<div class="label">Open Findings</div>
				<div class="num">{{ $reportStats['open_findings'] ?? 0 }}</div>
			</article>
			<article class="card stat">
				<div class="label">Completed Reports</div>
				<div class="num">{{ $reportStats['completed_reports'] ?? 0 }}</div>
			</article>
			<article class="card stat">
				<div class="label">Overdue Reports</div>
				<div class="num">{{ $reportStats['overdue_reports'] ?? 0 }}</div>
			</article>
		</section>

		<section class="card section">
			<h2 class="section-title">Recent Report Submissions</h2>
			<table>
				<thead>
					<tr>
						<th>Report Name</th>
						<th>Owner</th>
						<th>Period</th>
						<th>Status</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>Nutritional Status Summary</td>
						<td>School Nurse</td>
						<td>Q1 2026</td>
						<td>Submitted</td>
					</tr>
					<tr>
						<td>Feeding Program Progress</td>
						<td>Clinic Staff</td>
						<td>March 2026</td>
						<td>Reviewed</td>
					</tr>
					<tr>
						<td>Deworming Completion Report</td>
						<td>School Nurse</td>
						<td>Q1 2026</td>
						<td>Pending Sign-off</td>
					</tr>
				</tbody>
			</table>
		</section>
	</div>
</div>
</body>
</html>
