<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>Health Overview - School Head - SIGLA</title>
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
@include('partials.schoolhead-sidebar', ['active' => 'health'])

@php
	$shYearLabel = str_replace('-', '&ndash;', e($schoolYear));
	$shPct = fn ($value) => $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 1), '0'), '.') . '%';
@endphp

<div class="main">
	<header class="topbar">
		<div class="topbar-bc"><span>School Head</span><span class="bc-sep">&rsaquo;</span><span>Health Overview</span></div>
		@include('partials.live-clock')
	</header>

	<div class="content">
		<div class="content-inner">

		<div class="page-header sh-header">
			<div class="sh-headline">
				<div class="sh-title-row">
					<h1 class="page-title">Clinic <span>Health Overview</span></h1>
					<span class="sh-year tnum">S.Y. {!! $shYearLabel !!}</span>
				</div>
				<p class="sh-meta">
					<span>{{ $schoolName }}</span>
					<span class="sh-sep">&middot;</span>
					<span class="tnum">{{ number_format($clinic['total']) }} {{ \Illuminate\Support\Str::plural('consultation', $clinic['total']) }}</span>
				</p>
			</div>
			<div class="sh-actions">
				<button type="button" class="btn btn-secondary" id="shPrint">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
					Print health summary
				</button>
			</div>
		</div>

		<div class="print-masthead" aria-hidden="true">
			<h2>Clinic Health Summary</h2>
			<p>{{ $schoolName }} &middot; S.Y. {{ $schoolYear }}</p>
			<p>Printed {{ $todayLabel }}</p>
		</div>

		<section class="kpi-grid">
			<article class="card kpi accent-info">
				<div class="kpi-top">
					<div class="kpi-label">Consultations</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></div>
				</div>
				<div class="kpi-value">{{ number_format($clinic['total']) }}</div>
				<div class="kpi-hint">{{ number_format($clinic['this_month']) }} in {{ $clinic['month_label'] }}</div>
			</article>
			<article class="card kpi accent-brand">
				<div class="kpi-top">
					<div class="kpi-label">Learners Seen</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
				</div>
				<div class="kpi-value">{{ number_format($clinic['learners']) }}</div>
				<div class="kpi-hint">{{ number_format($students) }} on the roll</div>
			</article>
			<article class="card kpi accent-amber">
				<div class="kpi-top">
					<div class="kpi-label">Referrals</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg></div>
				</div>
				<div class="kpi-value">{{ number_format($clinic['referred']) }}</div>
				<div class="kpi-hint">{{ number_format($clinic['referred_this_month']) }} in {{ $clinic['month_label'] }}</div>
			</article>
			<article class="card kpi accent-success">
				<div class="kpi-top">
					<div class="kpi-label">Clinic Notes</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/></svg></div>
				</div>
				<div class="kpi-value">{{ number_format($clinic['notes']) }}</div>
				<div class="kpi-hint">{{ number_format($clinic['today']) }} {{ \Illuminate\Support\Str::plural('consultation', $clinic['today']) }} today</div>
			</article>
		</section>

		<form method="GET" class="card sh-toolbar" id="shToolbar">
			<div class="sh-filter">
				<label class="field-label" for="hoYear">School year</label>
				<select class="select" name="school_year" id="hoYear">
					@foreach ($schoolYears as $year)
						<option value="{{ $year }}" @selected($filters['school_year'] === $year)>{{ $year }}</option>
					@endforeach
				</select>
			</div>
			<div class="sh-filter">
				<label class="field-label" for="hoGrade">Grade</label>
				<select class="select" name="grade" id="hoGrade">
					<option value="">All grades</option>
					@foreach ($filterOptions['grades'] as $grade)
						<option value="{{ $grade }}" @selected($filters['grade'] === $grade)>{{ $grade }}</option>
					@endforeach
				</select>
			</div>
			<div class="sh-filter">
				<label class="field-label" for="hoSection">Section</label>
				<select class="select" name="section" id="hoSection">
					<option value="">All sections</option>
					@foreach ($filterOptions['sections'] as $section)
						<option value="{{ $section }}" @selected($filters['section'] === $section)>{{ $section }}</option>
					@endforeach
				</select>
			</div>
			<noscript><button type="submit" class="btn btn-secondary">Apply</button></noscript>
		</form>

		<section class="card section">
			<div class="section-head">
				<h2 class="section-title">Clinic Activity</h2>
				<div class="section-meta tnum">{{ number_format($clinic['this_week']) }} this week</div>
			</div>

			@if (empty($clinic['trend']['columns']))
				<div class="sh-empty">No consultation logged this school year.</div>
			@else
				<div class="sh-cols">
					<div class="sh-cols-axis">
						@foreach ($clinic['trend']['ticks'] as $tick)
							<span>{{ number_format($tick) }}</span>
						@endforeach
					</div>
					<div class="sh-cols-plot">
						<div class="sh-cols-grid" aria-hidden="true">
							@foreach ([0, 25, 50, 75, 100] as $line)
								<span class="sh-cols-line" style="top: {{ $line }}%"></span>
							@endforeach
						</div>
						<div class="sh-cols-bars">
							@foreach ($clinic['trend']['columns'] as $column)
								<div class="sh-col" title="{{ $column['full_label'] }}: {{ $column['count'] }}">
									<span class="sh-col-cap" style="bottom: calc({{ $column['pct'] }}% + 5px)">{{ number_format($column['count']) }}</span>
									<span class="sh-col-bar" style="height: {{ $column['pct'] }}%"></span>
								</div>
							@endforeach
						</div>
					</div>
					<div class="sh-cols-labels">
						@foreach ($clinic['trend']['columns'] as $column)
							<span>{{ $column['label'] }}</span>
						@endforeach
					</div>
				</div>
			@endif
		</section>

		<section class="grid-2">
			<article class="card section">
				<div class="section-head">
					<h2 class="section-title">Disposition</h2>
				</div>
				@if ($clinic['total'] === 0)
					<div class="sh-empty">No consultation logged this school year.</div>
				@else
					<ul class="sh-meter">
						@foreach ($clinic['dispositions'] as $row)
							<li class="sh-meter-row">
								<span class="sh-meter-label">{{ $row['label'] }}</span>
								<span class="sh-meter-track">
									<span class="sh-meter-fill {{ $row['key'] === 'referred' ? 'is-watch' : '' }}" style="width: {{ $row['share'] ?? 0 }}%"></span>
								</span>
								<span class="sh-meter-value tnum">{{ number_format($row['count']) }}</span>
								<span class="sh-meter-share tnum">{{ $shPct($row['share']) }}</span>
							</li>
						@endforeach
					</ul>
				@endif
			</article>

			<article class="card section">
				<div class="section-head">
					<h2 class="section-title">Consultations by Grade</h2>
				</div>
				@if (empty($clinic['grades']))
					<div class="sh-empty">No consultation logged this school year.</div>
				@else
					<ul class="sh-meter">
						@foreach ($clinic['grades'] as $row)
							<li class="sh-meter-row">
								<span class="sh-meter-label">{{ $row['label'] }}</span>
								<span class="sh-meter-track"><span class="sh-meter-fill" style="width: {{ $row['pct'] }}%"></span></span>
								<span class="sh-meter-value tnum">{{ number_format($row['count']) }}</span>
								<span class="sh-meter-share tnum">{{ $shPct($row['share']) }}</span>
							</li>
						@endforeach
					</ul>
				@endif
			</article>
		</section>

		<section class="grid-2">
			<article class="card section">
				<div class="section-head">
					<h2 class="section-title">Complaint Category</h2>
				</div>
				@if (empty($clinic['categories']))
					<div class="sh-empty">No consultation logged this school year.</div>
				@else
					<ul class="sh-meter">
						@foreach (array_slice($clinic['categories'], 0, 8) as $row)
							<li class="sh-meter-row">
								<span class="sh-meter-label">{{ $row['label'] }}</span>
								<span class="sh-meter-track"><span class="sh-meter-fill" style="width: {{ $row['pct'] }}%"></span></span>
								<span class="sh-meter-value tnum">{{ number_format($row['count']) }}</span>
								<span class="sh-meter-share tnum">{{ $shPct($row['share']) }}</span>
							</li>
						@endforeach
					</ul>
				@endif
			</article>

			<article class="card section">
				<div class="section-head">
					<h2 class="section-title">Most Common Complaints</h2>
				</div>
				@if (empty($clinic['complaints']))
					<div class="sh-empty">No consultation logged this school year.</div>
				@else
					<ul class="sh-meter">
						@foreach (array_slice($clinic['complaints'], 0, 8) as $row)
							<li class="sh-meter-row">
								<span class="sh-meter-label">{{ $row['label'] }}</span>
								<span class="sh-meter-track"><span class="sh-meter-fill" style="width: {{ $row['pct'] }}%"></span></span>
								<span class="sh-meter-value tnum">{{ number_format($row['count']) }}</span>
								<span class="sh-meter-share tnum">{{ $shPct($row['share']) }}</span>
							</li>
						@endforeach
					</ul>
				@endif
			</article>
		</section>

		<section class="card section">
			<div class="section-head">
				<h2 class="section-title">Latest Visits</h2>
			</div>
			@if (empty($clinic['recent']))
				<div class="sh-empty">No consultation logged this school year.</div>
			@else
				<div class="table-card">
					<table class="sh-table">
						<thead>
							<tr>
								<th>Date</th>
								<th>Time</th>
								<th>Grade &amp; section</th>
								<th>Complaint</th>
								<th>Disposition</th>
							</tr>
						</thead>
						<tbody>
							@foreach ($clinic['recent'] as $row)
								<tr>
									<td class="tnum">{{ $row['date'] }}</td>
									<td class="tnum">{{ $row['time'] }}</td>
									<td>{{ $row['grade'] }}</td>
									<td>{{ $row['complaint'] }}</td>
									<td><span class="badge {{ $row['badge'] }}">{{ $row['status'] }}</span></td>
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
