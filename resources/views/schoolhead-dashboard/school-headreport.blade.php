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
	<script>document.documentElement.classList.add('js');</script>
	<style>
		*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
		:root {
			--g900: #14532d;
			--g300: #86efac;
			--sidebar-w: 252px;
			--topbar-h: 68px;
			--cream: #f5f8f4;
			--card: #ffffff;
			--border: #deebe2;
			--text-1: #0d1f14;
			--text-2: #365540;
			--text-3: #6d8f79;
			--red: #dc2626;
			--shadow-card: 0 1px 3px rgba(5,46,22,.05), 0 10px 22px rgba(5,46,22,.06);
			--radius-sm: 10px;
		}

		html, body { height: 100%; font-family: 'DM Sans', sans-serif; background: radial-gradient(circle at 5% -10%, #e7f7ec 0%, var(--cream) 50%); color: var(--text-1); overflow: hidden; }

		.sidebar {
			position: fixed; left: 0; top: 0; bottom: 0;
			width: var(--sidebar-w); background: var(--g900);
			display: flex; flex-direction: column; z-index: 100; overflow: hidden;
			box-shadow: 8px 0 28px rgba(6, 46, 26, .18);
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
		.sb-logo { padding: 21px 20px 18px; position: relative; z-index: 2; border-bottom: 1px solid rgba(255,255,255,.09); display: flex; justify-content: center; }
		.sb-logo-full { width: 176px; max-width: 100%; height: auto; display: block; }
		.sb-nav { flex: 1; overflow-y: auto; padding: 16px 12px; position: relative; z-index: 2; }
		.sb-section-label { font-size: .6rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: rgba(134,239,172,.58); padding: 0 8px; margin: 9px 0; }
		.sb-link { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: var(--radius-sm); text-decoration: none; color: rgba(255,255,255,.66); font-size: .83rem; font-weight: 500; transition: background .15s, color .15s, transform .15s; margin-bottom: 2px; }
		.sb-link:hover { background: rgba(255,255,255,.1); color: rgba(255,255,255,.94); transform: translateX(2px); }
		.sb-link.active { background: rgba(34,197,94,.2); color: var(--g300); box-shadow: inset 0 0 0 1px rgba(134,239,172,.22); }
		.sb-link svg { width: 16px; height: 16px; flex-shrink: 0; }
		.sb-user { padding: 14px 16px; border-top: 1px solid rgba(255,255,255,.09); display: flex; align-items: center; gap: 11px; position: relative; z-index: 2; }
		.sb-avatar { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(145deg, #22c55e, #15803d); display: grid; place-items: center; font-size: .8rem; font-weight: 700; color: white; }
		.sb-user-name { font-size: .8rem; font-weight: 600; color: white; line-height: 1.2; }
		.sb-user-role { font-size: .68rem; color: var(--g300); }
		.sb-logout { margin-left: auto; background: none; border: none; color: rgba(255,255,255,.4); cursor: pointer; padding: 4px; border-radius: 6px; transition: color .15s, background .15s; display: grid; place-items: center; }
		.sb-logout:hover { color: #fecaca; background: rgba(239,68,68,.14); }
		.sb-logout svg { width: 15px; height: 15px; }

		.main { margin-left: var(--sidebar-w); height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
		html.js .main { opacity: 0; transform: translateY(10px); transition: opacity .26s ease, transform .3s ease; }
		html.js .main.page-ready { opacity: 1; transform: translateY(0); }
		html.js .main.page-exit { opacity: 0; transform: translateY(10px); }
		.topbar { height: var(--topbar-h); border-bottom: 1px solid var(--border); background: rgba(255,255,255,.82); backdrop-filter: blur(6px); display: flex; align-items: center; justify-content: space-between; padding: 0 24px; }
		.topbar-bc { font-size: .76rem; color: var(--text-3); display: flex; gap: 6px; align-items: center; }
		.topbar-chip { font-size: .72rem; border: 1px solid #bbf7d0; color: #166534; background: #f0fdf4; border-radius: 999px; padding: 5px 11px; display: flex; align-items: center; gap: 7px; font-weight: 600; }
		.topbar-chip .dot { width: 6px; height: 6px; border-radius: 50%; background: #22c55e; }

		.content { overflow: auto; padding: 20px; }
		.content-inner { max-width: 1240px; margin: 0 auto; }
		.page-header {
			margin-bottom: 16px;
			background: linear-gradient(130deg, #ffffff 0%, #f7fcf8 62%);
			border: 1px solid var(--border);
			box-shadow: var(--shadow-card);
			border-radius: 16px;
			padding: 18px;
		}
		.page-eyebrow { font-size: .68rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: #15803d; margin-bottom: 6px; }
		.page-title { font-family: 'DM Serif Display', serif; font-size: clamp(1.45rem, 2.3vw, 1.9rem); color: var(--text-1); line-height: 1.15; }
		.page-title span { font-style: italic; color: #15803d; }
		.page-sub { margin-top: 6px; font-size: .82rem; color: var(--text-3); max-width: 70ch; }

		.stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
		.card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; box-shadow: var(--shadow-card); }
		.stat { padding: 14px 15px; position: relative; overflow: hidden; }
		.stat::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: #16a34a; }
		.stat .label { font-size: .68rem; color: var(--text-3); font-weight: 600; letter-spacing: .01em; }
		.stat .num { margin-top: 8px; font-family: 'DM Serif Display', serif; font-size: 1.58rem; line-height: 1; color: #0f2c1c; }

		.section { padding: 14px; }
		.section-head { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; margin-bottom: 10px; }
		.section-title { font-size: .84rem; letter-spacing: .02em; color: var(--text-2); font-weight: 700; }
		.section-meta { font-size: .7rem; color: var(--text-3); }
		.table-wrap { overflow-x: auto; border-radius: 10px; border: 1px solid var(--border); }
		table { width: 100%; border-collapse: collapse; }
		th, td { font-size: .74rem; text-align: left; padding: 11px 9px; border-bottom: 1px solid var(--border); white-space: nowrap; }
		tbody tr:hover { background: #f8fbf8; }
		th { color: var(--text-3); font-weight: 700; font-size: .7rem; text-transform: uppercase; letter-spacing: .04em; background: #f9fdf9; }
		.status-pill { border-radius: 999px; padding: 3px 8px; font-size: .64rem; font-weight: 700; display: inline-block; }
		.status-submitted { background: #dcfce7; color: #166534; }
		.status-reviewed { background: #dbeafe; color: #1d4ed8; }
		.status-pending { background: #fef3c7; color: #92400e; }

		@media (max-width: 1050px) { .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
		@media (max-width: 780px) {
			.sidebar { display: none; }
			.main { margin-left: 0; }
			.topbar { padding: 0 14px; }
			.content { padding: 14px; }
			.page-header { padding: 14px; }
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
		<form method="POST" action="{{ route('logout') }}">
			@csrf
			<button type="submit" class="sb-logout" title="Sign out">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
			</button>
		</form>
	</div>
</aside>

<div class="main">
	<header class="topbar">
		<div class="topbar-bc"><span>Dashboard</span><span>&gt;</span><span>Reports</span></div>
		<div class="topbar-chip"><div class="dot"></div>Read-Only Monitoring</div>
	</header>

	<div class="content">
		<div class="content-inner">
		<div class="page-header">
		<div class="page-eyebrow">School Head Reports</div>
		<h1 class="page-title">Executive <span>Reports Overview</span></h1>
		<p class="page-sub">View-only report summaries for school-level health programs and compliance monitoring.</p>
		</div>

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
			<div class="section-head">
				<h2 class="section-title">Recent Report Submissions</h2>
				<div class="section-meta">Monitoring log for visibility only</div>
			</div>
			<div class="table-wrap">
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
						<td><span class="status-pill status-submitted">Submitted</span></td>
					</tr>
					<tr>
						<td>Feeding Program Progress</td>
						<td>Clinic Staff</td>
						<td>March 2026</td>
						<td><span class="status-pill status-reviewed">Reviewed</span></td>
					</tr>
					<tr>
						<td>Deworming Completion Report</td>
						<td>School Nurse</td>
						<td>Q1 2026</td>
						<td><span class="status-pill status-pending">Pending Sign-off</span></td>
					</tr>
				</tbody>
			</table>
			</div>
		</section>
		</div>
	</div>
</div>
<script>
	(() => {
		const main = document.querySelector('.main');
		if (!main) {
			return;
		}

		requestAnimationFrame(() => {
			main.classList.add('page-ready');
		});

		window.addEventListener('pageshow', () => {
			main.classList.add('page-ready');
		});

		document.querySelectorAll('.sb-link[href]').forEach((link) => {
			link.addEventListener('click', (event) => {
				const href = link.getAttribute('href');
				if (!href || link.classList.contains('active')) {
					return;
				}
				if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
					return;
				}

				event.preventDefault();
				main.classList.remove('page-ready');
				main.classList.add('page-exit');
				window.setTimeout(() => {
					window.location.href = href;
				}, 220);
			});
		});
	})();
</script>
</body>
</html>
