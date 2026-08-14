<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>Learner Attendance - Feeding Coordinator - SIGLA</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
	<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
	<script>document.documentElement.classList.add('js');</script>
	<style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/feeding-dashboard.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/role-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.feedingcor-sidebar', ['active' => 'dashboard'])

@php
	$marks = [
		'present' => ['badge-normal', 'Present'],
		'absent' => ['badge-critical', 'Absent'],
		'unconfirmed' => ['badge-monitor', 'Unconfirmed'],
	];
	$thresholdLabel = rtrim(rtrim(number_format((float) $summary['threshold'], 1), '0'), '.');
@endphp

<div class="main">
	<header class="topbar">
		<div class="topbar-bc"><span>Dashboard</span><span class="bc-sep">&rsaquo;</span><span>Attendance At Risk</span><span class="bc-sep">&rsaquo;</span><span>{{ $learner['name'] }}</span></div>
		@include('partials.live-clock')
	</header>

	<div class="content">
		<div class="page-header la-header">
			<div>
				<h1 class="page-title">{{ $learner['name'] }}</h1>
				<p class="page-sub">{{ $learner['grade'] }}{{ $learner['section'] ? ' · '.$learner['section'] : '' }} &middot; {{ $learner['nutritional_status'] ?: 'Status not set' }}</p>
			</div>
			<a class="btn btn-secondary" href="{{ route('dashboard.feedingcor-dashboard') }}">Back to dashboard</a>
		</div>

		@if (session('error'))
			<div class="flash err">{{ session('error') }}</div>
		@endif

		<section class="kpi-grid cols-3">
			<article class="card kpi {{ $summary['at_risk'] ? 'accent-orange' : 'accent-brand' }}">
				<div class="kpi-top"><div class="kpi-label">Attendance</div></div>
				<div class="kpi-value">{{ !is_null($summary['rate']) ? number_format((float) $summary['rate'], 0).'%' : '—' }}</div>
				<div class="kpi-hint">{{ $summary['at_risk'] ? 'Below '.$thresholdLabel.'% threshold' : 'At or above '.$thresholdLabel.'%' }}</div>
			</article>
			<article class="card kpi accent-info">
				<div class="kpi-top"><div class="kpi-label">Days Present</div></div>
				<div class="kpi-value">{{ $summary['present'] }}/{{ $summary['confirmed'] }}</div>
				<div class="kpi-hint">Confirmed sessions</div>
			</article>
			<article class="card kpi accent-amber">
				<div class="kpi-top"><div class="kpi-label">Unconfirmed</div></div>
				<div class="kpi-value">{{ $summary['unconfirmed'] }}</div>
				<div class="kpi-hint">Excluded from the rate</div>
			</article>
		</section>

		<section class="card risk-card">
			<div class="card-head">
				<div>
					<h2 class="card-title">Session History</h2>
					<p class="card-sub">Most recent first.</p>
				</div>
				@if ($summary['unconfirmed'] > 0)
					<a class="btn btn-secondary" href="{{ route('feedingcor-program.attendance.review') }}">Review queue</a>
				@endif
			</div>
			<div class="table-scroll risk-scroll">
				<table class="risk-table">
					<thead>
						<tr>
							<th>Date</th>
							<th>Attendance</th>
							<th>Remarks</th>
						</tr>
					</thead>
					<tbody>
						@forelse ($sessions as $session)
							<tr>
								<td><strong>{{ $session['date'] }}</strong></td>
								<td><span class="badge {{ $marks[$session['status']][0] }}">{{ $marks[$session['status']][1] }}</span></td>
								<td class="att-remark">{{ $session['remarks'] !== '' ? $session['remarks'] : '—' }}</td>
							</tr>
						@empty
							<tr><td colspan="3" class="table-empty">No session has covered this learner yet.</td></tr>
						@endforelse
					</tbody>
				</table>
			</div>
		</section>
	</div>
</div>
@include('partials.role-page-transition')
</body>
</html>
