<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>School Head Reports - SIGLA</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
	<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
	<script>document.documentElement.classList.add('js');</script>
	{{-- Shared LUSOG design system, then this page's own styles, then the
	     role sidebar panel — the same three-layer order every role uses. --}}
	<style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/schoolhead.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/role-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.schoolhead-sidebar', ['active' => 'reports'])

<div class="main">
	<header class="topbar">
		<div class="topbar-bc"><span>Dashboard</span><span class="bc-sep">&rsaquo;</span><span>Reports</span></div>
	    @include('partials.live-clock')
	</header>

	<div class="content">
		<div class="content-inner">
		<div class="page-header">
		<h1 class="page-title">Executive <span>Reports Overview</span></h1>
		<p class="page-sub">View-only report summaries for school-level health programs and compliance monitoring.</p>
		<span class="badge badge-info" style="margin-top:12px;">Read-only monitoring</span>
		</div>

		{{-- Green for what is done, amber for what is waiting, coral for what
		     is late: the accent tells the head where to look first. --}}
		<section class="kpi-grid">
			<article class="card kpi accent-brand">
				<div class="kpi-top">
					<div class="kpi-label">Submission Rate</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg></div>
				</div>
				<div class="kpi-value">{{ $reportStats['submission_rate'] ?? '0%' }}</div>
				<div class="kpi-hint">Of expected submissions</div>
			</article>
			<article class="card kpi accent-amber">
				<div class="kpi-top">
					<div class="kpi-label">Open Findings</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></div>
				</div>
				<div class="kpi-value">{{ $reportStats['open_findings'] ?? 0 }}</div>
				<div class="kpi-hint">Awaiting resolution</div>
			</article>
			<article class="card kpi accent-success">
				<div class="kpi-top">
					<div class="kpi-label">Completed Reports</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="8" height="4" x="8" y="2" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/></svg></div>
				</div>
				<div class="kpi-value">{{ $reportStats['completed_reports'] ?? 0 }}</div>
				<div class="kpi-hint">Signed off this period</div>
			</article>
			<article class="card kpi accent-danger">
				<div class="kpi-top">
					<div class="kpi-label">Overdue Reports</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
				</div>
				<div class="kpi-value">{{ $reportStats['overdue_reports'] ?? 0 }}</div>
				<div class="kpi-hint">Past their due date</div>
			</article>
		</section>

		<section class="card section">
			<div class="section-head">
				<h2 class="section-title">Recent Report Submissions</h2>
				<div class="section-meta">Monitoring log for visibility only</div>
			</div>
			<div class="table-card">
			<div class="table-scroll">
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
						<td><strong>Nutritional Status Summary</strong></td>
						<td>School Nurse</td>
						<td>Q1 2026</td>
						<td><span class="badge badge-normal">Submitted</span></td>
					</tr>
					<tr>
						<td><strong>Feeding Program Progress</strong></td>
						<td>Clinic Staff</td>
						<td>March 2026</td>
						<td><span class="badge badge-info">Reviewed</span></td>
					</tr>
					<tr>
						<td><strong>Deworming Completion Report</strong></td>
						<td>School Nurse</td>
						<td>Q1 2026</td>
						<td><span class="badge badge-monitor">Pending Sign-off</span></td>
					</tr>
				</tbody>
			</table>
			</div>
			</div>
		</section>
		</div>
	</div>
</div>
@include('partials.role-page-transition')
</body>
</html>
