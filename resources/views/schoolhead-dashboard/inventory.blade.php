<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>Inventory Overview - School Head - SIGLA</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
	<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
	<script>document.documentElement.classList.add('js');</script>
	<style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/schoolhead.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/role-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.schoolhead-sidebar', ['active' => 'inventory'])

@php
	$shStateLabel = fn (string $state) => \App\Support\SchoolHeadHealthOverview::stockLabel($state);
@endphp

<div class="main">
	<header class="topbar">
		<div class="topbar-bc"><span>School Head</span><span class="bc-sep">&rsaquo;</span><span>Medicine Inventory</span></div>
		@include('partials.live-clock')
	</header>

	<div class="content">
		<div class="content-inner">

		<div class="page-header sh-header">
			<div class="sh-headline">
				<div class="sh-title-row">
					<h1 class="page-title">Medicine <span>Inventory Overview</span></h1>
				</div>
				<p class="sh-meta">
					<span>{{ $schoolName }}</span>
					<span class="sh-sep">&middot;</span>
					<span class="tnum">{{ number_format($inventory['tracked']) }} {{ \Illuminate\Support\Str::plural('medicine', $inventory['tracked']) }} tracked</span>
					<span class="sh-sep">&middot;</span>
					<span class="tnum">{{ number_format($inventory['units']) }} units on hand</span>
				</p>
			</div>
			<div class="sh-actions">
				<a class="btn btn-secondary" href="{{ route('dashboard.school-head.inventory.export', request()->query()) }}">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
					Export
				</a>
				<button type="button" class="btn btn-secondary" id="shPrint">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
					Print
				</button>
			</div>
		</div>

		<div class="print-masthead" aria-hidden="true">
			<h2>Medicine Inventory &mdash; Overview</h2>
			<p>{{ $schoolName }}</p>
			<p>Printed {{ $todayLabel }}</p>
		</div>

		<section class="kpi-grid">
			<article class="card kpi accent-brand">
				<div class="kpi-top">
					<div class="kpi-label">Medicines in Stock</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="m8.5 8.5 7 7"/></svg></div>
				</div>
				<div class="kpi-value">{{ number_format($inventory['good'] + $inventory['monitor']) }}</div>
				<div class="kpi-hint">{{ number_format($inventory['tracked']) }} tracked</div>
			</article>
			<article class="card kpi accent-orange">
				<div class="kpi-top">
					<div class="kpi-label">Low Stock</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2v20"/><path d="m19 15-7 7-7-7"/></svg></div>
				</div>
				<div class="kpi-value">{{ number_format($inventory['low']) }}</div>
				<div class="kpi-hint">{{ number_format($inventory['monitor']) }} approaching the threshold</div>
			</article>
			<article class="card kpi accent-danger">
				<div class="kpi-top">
					<div class="kpi-label">Out of Stock</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/></svg></div>
				</div>
				<div class="kpi-value">{{ number_format($inventory['out']) }}</div>
				<div class="kpi-hint">Cannot be dispensed</div>
			</article>
			<article class="card kpi accent-info">
				<div class="kpi-top">
					<div class="kpi-label">Dispensed This Month</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg></div>
				</div>
				<div class="kpi-value">{{ number_format($inventory['dispensed_this_month']) }}</div>
				<div class="kpi-hint">Units issued</div>
			</article>
		</section>

		<form method="GET" class="card sh-toolbar" id="shToolbar">
			<div class="sh-filter">
				<label class="field-label" for="ivState">Stock status</label>
				<select class="select" name="state" id="ivState">
					<option value="">All</option>
					@foreach ($states as $state)
						<option value="{{ $state }}" @selected($filters['state'] === $state)>{{ $shStateLabel($state) }}</option>
					@endforeach
				</select>
			</div>
			<noscript><button type="submit" class="btn btn-secondary">Apply</button></noscript>
		</form>

		<section class="table-card sh-listcard">
			<div class="sh-listhead">
				<div>
					<h2 class="card-title">Inventory Status</h2>
					<p class="card-sub tnum">{{ number_format($shown) }} of {{ number_format($inventory['tracked']) }} {{ \Illuminate\Support\Str::plural('medicine', $inventory['tracked']) }}</p>
				</div>
			</div>

			@if ($rows->isEmpty())
				<p class="table-empty">No medicine matches that status.</p>
			@else
				<div class="table-scroll">
					<table class="sh-table">
						<thead>
							<tr>
								<th class="num">#</th>
								<th>Medicine</th>
								<th class="num">Stock</th>
								<th>Unit</th>
								<th class="num">Reorder at</th>
								<th>Level</th>
								<th>Status</th>
							</tr>
						</thead>
						<tbody>
							@foreach ($rows as $index => $row)
								<tr>
									<td class="num sh-index tnum">{{ $index + 1 }}</td>
									<td><strong>{{ $row['name'] }}</strong></td>
									<td class="num tnum">{{ number_format($row['stock']) }}</td>
									<td>{{ $row['unit'] }}</td>
									<td class="num tnum">{{ number_format($row['threshold']) }}</td>
									<td>
										<span class="sh-bar-cell">
											<span class="sh-bar">
												<span class="sh-bar-fill" style="width: {{ $row['level'] }}%; background: {{ $row['state'] === 'good' || $row['state'] === 'monitor' ? 'var(--series-healthy)' : 'var(--series-risk)' }};"></span>
											</span>
											<span class="sh-bar-value tnum">{{ rtrim(rtrim(number_format($row['level'], 1), '0'), '.') }}%</span>
										</span>
									</td>
									<td><span class="badge {{ $row['badge'] }}">{{ $row['label'] }}</span></td>
								</tr>
							@endforeach
						</tbody>
					</table>
				</div>
			@endif
		</section>
		</div>
	</div>
</div>
<script>
	(function () {
		document.querySelectorAll('#shToolbar select').forEach(function (control) {
			control.addEventListener('change', function () {
				control.form.requestSubmit ? control.form.requestSubmit() : control.form.submit();
			});
		});

		const print = document.getElementById('shPrint');
		if (print) {
			print.addEventListener('click', function () { window.print(); });
		}
	})();
</script>
@include('partials.schoolhead-live')
@include('partials.role-page-transition')
</body>
</html>
