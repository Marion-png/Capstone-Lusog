<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>Reports - School Head - SIGLA</title>
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

@php
	$shYear = str_replace('-', '&ndash;', e($schoolYear));
	$shPct = fn ($value) => $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 1), '0'), '.') . '%';
@endphp

<div class="main">
	<header class="topbar">
		<div class="topbar-bc"><span>School Head</span><span class="bc-sep">&rsaquo;</span><span>Reports</span></div>
		@include('partials.live-clock')
	</header>

	<div class="content">
		<div class="content-inner">

		@if (session('success'))
			<div class="flash ok">{{ session('success') }}</div>
		@endif
		@if (session('error'))
			<div class="flash err">{{ session('error') }}</div>
		@endif
		@if ($errors->any())
			<div class="flash err">{{ $errors->first() }}</div>
		@endif

		<div class="page-header sh-header">
			<div class="sh-headline">
				<div class="sh-title-row">
					<h1 class="page-title">SBFP <span>Reports</span></h1>
					<span class="sh-year tnum">S.Y. {!! $shYear !!}</span>
				</div>
				<p class="sh-meta">
					<span>{{ $schoolName }}</span>
					<span class="sh-sep">&middot;</span>
					<span>Every figure derived from the learners&rsquo; records at read time</span>
				</p>
			</div>
			<div class="sh-actions">
				<form method="GET" class="sh-yearpick">
					<label class="field-label" for="shYear">School year</label>
					<select class="select" name="school_year" id="shYear">
						@foreach ($schoolYears as $year)
							<option value="{{ $year }}" @selected($schoolYear === $year)>{{ $year }}</option>
						@endforeach
					</select>
					<noscript><button type="submit" class="btn btn-secondary">Apply</button></noscript>
				</form>
				<a class="btn btn-primary" href="{{ route('dashboard.school-head.reports.export', ['report' => 'packet', 'school_year' => $schoolYear]) }}">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
					Division submission packet
				</a>
				<button type="button" class="btn btn-secondary" id="shPrint">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
					Print reports
				</button>
			</div>
		</div>

		<div class="print-masthead" aria-hidden="true">
			<h2>School-Based Feeding Program &mdash; Reports</h2>
			<p>{{ $schoolName }} &middot; S.Y. {{ $schoolYear }}</p>
			<p>Printed {{ $todayLabel }}</p>
		</div>

		{{-- ── The shift, as a chart ────────────────────────────────────
		     Two series on one shared scale: the theme's validated
		     --series-risk / --series-healthy pair, the same one the
		     coordinator's Nutritional Progress panel uses. Colour carries
		     which weighing, the row label carries the status, and every bar
		     is direct-labelled — so identity never rests on hue alone and the
		     numbers below are the same numbers. ── --}}
		<section class="card section">
			<div class="section-head">
				<h2 class="section-title">Nutritional Status, Baseline to Endline</h2>
				<div class="section-meta tnum">
					{{ number_format($shift['baseline_measured']) }} measured at baseline
					@if ($shift['has_endline'])
						&middot; {{ number_format($shift['endline_measured']) }} at endline
					@endif
				</div>
			</div>

			@if ($shift['axis_max'] === 0)
				<p class="sh-empty">No measurement on record yet.</p>
			@else
				@if ($shift['has_endline'])
					<ul class="sh-serieslegend">
						<li><i class="sh-swatch is-baseline"></i>Baseline</li>
						<li><i class="sh-swatch is-endline"></i>Endline</li>
					</ul>
				@endif

				<div class="sh-shift">
					@foreach ($shift['rows'] as $row)
						<div class="sh-shift-row">
							<span class="sh-shift-label">{{ $row['label'] }}</span>
							<div class="sh-shift-bars">
								<div class="sh-shift-line">
									<span class="sh-shift-bar is-baseline" style="width:{{ $row['baseline_pct'] }}%"
									      title="Baseline &middot; {{ $row['label'] }}: {{ $row['baseline'] }}"></span>
									<span class="sh-shift-value tnum">{{ $row['baseline'] }}</span>
								</div>
								@if ($shift['has_endline'])
									<div class="sh-shift-line">
										<span class="sh-shift-bar is-endline" style="width:{{ $row['endline_pct'] }}%"
										      title="Endline &middot; {{ $row['label'] }}: {{ $row['endline'] }}"></span>
										<span class="sh-shift-value tnum">{{ $row['endline'] }}</span>
										@if ($row['change'] !== 0)
											{{-- A real minus sign, not an entity: inside {{ }} an
											     entity would be escaped and print as text. --}}
											<span class="sh-shift-delta {{ $row['change'] < 0 ? 'is-down' : 'is-up' }} tnum">
												{{ $row['change'] > 0 ? '+' : '−' }}{{ abs($row['change']) }}
											</span>
										@endif
									</div>
								@endif
							</div>
						</div>
					@endforeach
				</div>

				<div class="sh-shift-axis" aria-hidden="true">
					@foreach ($shift['ticks'] as $tick)
						<span class="tnum">{{ $tick }}</span>
					@endforeach
				</div>
			@endif
		</section>

		{{-- ── Baseline against endline ─────────────────────────────────
		     Two panels, one scale, the number measured printed beside every
		     share — a category percentage taken over children nobody weighed
		     would be a made-up figure. ── --}}
		<section class="sh-compare">
			@foreach (['baseline', 'endline'] as $phase)
				@php $panel = $comparison[$phase]; @endphp
				<article class="card section sh-panel">
					<div class="section-head">
						<h2 class="section-title">{{ $panel['label'] }}</h2>
						<span class="badge {{ $panel['measured'] > 0 ? 'badge-info' : 'badge-neutral' }}">
							{{ $panel['date'] ?? 'Not yet recorded' }}
						</span>
					</div>

					<div class="table-card">
						<div class="table-scroll">
							<table>
								<thead>
									<tr><th>Category</th><th class="num">Learners</th><th class="num">Share</th></tr>
								</thead>
								<tbody>
									@foreach ($panel['rows'] as $row)
										<tr>
											<td><strong>{{ $row['label'] }}</strong></td>
											<td class="num">{{ number_format($row['count']) }}</td>
											<td class="num">{{ $shPct($row['share']) }}</td>
										</tr>
									@endforeach
									<tr class="sh-total-row">
										<td><strong>Total measured</strong></td>
										<td class="num"><strong>{{ number_format($panel['measured']) }}</strong></td>
										<td class="num">of {{ number_format($panel['total']) }}</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>

					@if ($panel['not_measured'] > 0)
						<p class="sh-panel-note">
							{{ number_format($panel['not_measured']) }}
							{{ \Illuminate\Support\Str::plural('learner', $panel['not_measured']) }} not measured at this weighing &mdash;
							counted separately, never as Normal.
						</p>
					@endif
				</article>
			@endforeach
		</section>

		{{-- ── Outcome ─────────────────────────────────────────────────
		     Two figures, both about the closing weigh-in. "Still Severely
		     Wasted and Wasted" counts the beneficiaries whose *endline* reading
		     is on the wasting scale — never the ones nobody re-measured, who
		     are reported separately, because a child no one has weighed is not
		     evidence either way. ── --}}
		<section class="kpi-grid">
			<article class="card kpi accent-orange">
				<div class="kpi-top">
					<div class="kpi-label">Still Severely Wasted and Wasted</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg></div>
				</div>
				<div class="kpi-value">{{ number_format($outcome['still_wasted']) }}</div>
				<div class="kpi-hint">
					@if ($outcome['measured'] === 0)
						No endline measurement recorded yet
					@else
						of {{ number_format($outcome['measured']) }} measured at endline
						@if ($outcome['not_measured'] > 0)
							&middot; {{ number_format($outcome['not_measured']) }} not yet measured
						@endif
					@endif
				</div>
			</article>
			<article class="card kpi accent-success">
				<div class="kpi-top">
					<div class="kpi-label">Improved</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg></div>
				</div>
				<div class="kpi-value">{{ number_format($outcome['improved']) }}</div>
				<div class="kpi-hint">Climbed the wasting scale, of {{ number_format($outcome['beneficiaries']) }} beneficiaries</div>
			</article>
			<article class="card kpi accent-info">
				<div class="kpi-top">
					<div class="kpi-label">Gain at Normal</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg></div>
				</div>
				<div class="kpi-value">
					@if ($comparison['normal_gain'] === null)
						&mdash;
					@else
						{{ $comparison['normal_gain'] > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($comparison['normal_gain'], 1), '0'), '.') }} pp
					@endif
				</div>
				<div class="kpi-hint">Share at Normal, baseline to endline</div>
			</article>
		</section>

		{{-- ── Reports ─────────────────────────────────────────────────
		     The head opens a report and exports it. There is no decision to
		     record here any more: Approve / Return for correction / Lock are
		     gone, and with them the last write this role had.

		     Export is offered only once the weighing behind the form is
		     finished — a baseline form with learners still unmeasured is not a
		     draft of the school's return, it is a form that would be wrong when
		     it was handed in. The endpoint refuses it too. ── --}}
		<section class="card section">
			<div class="section-head">
				<h2 class="section-title">Reports</h2>
				<div class="section-meta">Exports are .xlsx workbooks &middot; print a view for a PDF</div>
			</div>

			<ul class="sh-reports">
				@foreach ($reports as $report)
					<li class="card sh-report">
						<div class="sh-report-main">
							<div class="sh-report-head">
								<strong>{{ $report['name'] }}</strong>
								@if ($report['complete'])
									<span class="badge badge-normal">Ready to export</span>
								@else
									<span class="badge badge-monitor">Weighing in progress</span>
								@endif
							</div>
							<p class="sh-report-sub">{{ $report['summary'] }}</p>
							<p class="sh-report-detail tnum">{{ $report['detail'] }}</p>
							@unless ($report['complete'])
								<p class="sh-report-detail">{{ $report['blocked_reason'] }}</p>
							@endunless
						</div>

						<div class="sh-report-actions">
							<a class="btn btn-secondary"
							   href="{{ route('dashboard.school-head.reports.view', ['report' => $report['key'], 'school_year' => $schoolYear]) }}">
								View
							</a>
							@if ($report['complete'])
								<a class="btn btn-primary"
								   href="{{ route('dashboard.school-head.reports.export', ['report' => $report['key'], 'school_year' => $schoolYear]) }}">
									Export
								</a>
							@endif
						</div>
					</li>
				@endforeach

				{{-- The masterlist is always current: it lists who is enrolled,
				     and there is no weighing to finish before it can be handed in. --}}
				<li class="card sh-report">
					<div class="sh-report-main">
						<div class="sh-report-head">
							<strong>Masterlist</strong>
							<span class="badge badge-info">Always current</span>
						</div>
						<p class="sh-report-sub">The identified Severely Wasted and Wasted learners qualified for the programme.</p>
						<p class="sh-report-detail">Filtered and searched on the Masterlist tab.</p>
					</div>
					<div class="sh-report-actions">
						<a class="btn btn-secondary" href="{{ route('dashboard.school-head.reports.view', ['report' => 'masterlist', 'school_year' => $schoolYear]) }}">View</a>
						<a class="btn btn-primary" href="{{ route('dashboard.school-head.masterlist.export', ['school_year' => $schoolYear]) }}">Export</a>
					</div>
				</li>
			</ul>
		</section>

		{{-- ── Monthly accomplishment ──────────────────────────────────── --}}
		@if (! empty($monthly))
			<section class="card section">
				<div class="section-head">
					<h2 class="section-title">Monthly Accomplishment</h2>
					<div class="section-meta tnum">
						@if ($turnout['average'] !== null)
							{{ $shPct($turnout['average']) }} average turnout
						@else
							No confirmed mark yet
						@endif
					</div>
				</div>

				{{-- ── Turnout month by month ───────────────────────────
				     A fixed 0–100 axis, because a percentage's scale is not
				     the data's to choose, with the programme's full-turnout
				     line drawn across it: the gap between a column and that
				     line is the reading. A month whose marks are all still
				     unconfirmed draws nothing rather than a zero. ── --}}
				<div class="sh-turnout">
					<div class="sh-turnout-axis" aria-hidden="true">
						@foreach ($turnout['ticks'] as $tick)
							<span class="tnum">{{ $tick }}%</span>
						@endforeach
					</div>
					<div class="sh-turnout-plot">
						<div class="sh-turnout-grid" aria-hidden="true">
							@foreach ($turnout['ticks'] as $tick)
								<span class="sh-turnout-line" style="bottom:{{ $tick }}%"></span>
							@endforeach
							<span class="sh-turnout-target" style="bottom:{{ $turnout['full_turnout'] }}%"
							      data-label="{{ rtrim(rtrim(number_format($turnout['full_turnout'], 1), '0'), '.') }}%"></span>
						</div>
						<div class="sh-turnout-cols">
							@foreach ($turnout['columns'] as $column)
								<div class="sh-turnout-col" tabindex="0"
								     title="{{ $column['full_label'] }} &middot; {{ $column['days_fed'] }} {{ \Illuminate\Support\Str::plural('day', $column['days_fed']) }} fed &middot; {{ number_format($column['meals']) }} meals"
								     aria-label="{{ $column['full_label'] }}: {{ $column['rate'] === null ? 'no confirmed mark' : $shPct($column['rate']).' turnout' }}">
									@if ($column['rate'] !== null)
										<span class="sh-turnout-cap tnum" style="bottom:calc({{ $column['rate'] }}% + 6px)">{{ $shPct($column['rate']) }}</span>
										<span class="sh-turnout-bar {{ $column['rate'] < $turnout['full_turnout'] ? 'is-low' : '' }}"
										      style="height:{{ $column['rate'] }}%"></span>
									@endif
								</div>
							@endforeach
						</div>
					</div>
					<div class="sh-turnout-labels" aria-hidden="true">
						@foreach ($turnout['columns'] as $column)
							<span>{{ $column['label'] }}</span>
						@endforeach
					</div>
				</div>

				@foreach ($monthly as $month)
					<div class="sh-month">
						<div class="sh-month-head">
							<strong>{{ $month['label'] }}</strong>
							<span class="tnum">
								{{ $month['days_fed'] }} {{ \Illuminate\Support\Str::plural('day', $month['days_fed']) }} fed
								&middot; {{ number_format($month['meals_served']) }} meals
								&middot; {{ $shPct($month['turnout']) }} turnout
							</span>
						</div>
						@if (! empty($month['grades']))
							<div class="table-card">
								<div class="table-scroll">
									<table>
										<thead>
											<tr><th>Grade</th><th class="num">Present</th><th class="num">Confirmed marks</th><th class="num">Turnout</th></tr>
										</thead>
										<tbody>
											@foreach ($month['grades'] as $grade)
												<tr>
													<td><strong>{{ $grade['label'] }}</strong></td>
													<td class="num">{{ number_format($grade['present']) }}</td>
													<td class="num">{{ number_format($grade['confirmed']) }}</td>
													<td class="num">{{ $shPct($grade['rate']) }}</td>
												</tr>
											@endforeach
										</tbody>
									</table>
								</div>
							</div>
						@endif
					</div>
				@endforeach
			</section>
		@endif
		</div>
	</div>
</div>

<script>
(() => {
	document.getElementById('shPrint')?.addEventListener('click', () => window.print());

	// The year picker applies itself — an Apply button nobody presses is a
	// filter that silently does nothing.
	document.getElementById('shYear')?.addEventListener('change', (event) => {
		const form = event.target.form;
		form.requestSubmit ? form.requestSubmit() : form.submit();
	});

})();
</script>
@include('partials.role-page-transition')
</body>
</html>
