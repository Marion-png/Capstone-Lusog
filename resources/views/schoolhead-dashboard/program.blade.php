<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>Feeding Program - School Head - SIGLA</title>
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
@include('partials.schoolhead-sidebar', ['active' => 'program'])

@php
	// En dash in the school year: it is a range, not a hyphenated word.
	$shYear = str_replace('-', '&ndash;', e($schoolYear));
	$shPct = fn ($value) => $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 1), '0'), '.') . '%';
@endphp

<div class="main">
	<header class="topbar">
		<div class="topbar-bc"><span>School Head</span><span class="bc-sep">&rsaquo;</span><span>Feeding Program</span></div>
		@include('partials.live-clock')
	</header>

	<div class="content">
		<div class="content-inner">

		<div class="page-header sh-header">
			<div class="sh-headline">
				<div class="sh-title-row">
					<h1 class="page-title">SBFP <span>Feeding Program</span></h1>
					<span class="sh-year tnum">S.Y. {!! $shYear !!}</span>
				</div>
				<p class="sh-meta">
					<span>{{ $schoolName }}</span>
					<span class="sh-sep">&middot;</span>
					<span class="tnum">Feeding day {{ $stats['day'] }} of {{ $stats['duration'] }}</span>
					<span class="sh-sep">&middot;</span>
					<span class="tnum">{{ $stats['days_completed'] }} {{ \Illuminate\Support\Str::plural('day', $stats['days_completed']) }} recorded</span>
				</p>
				<p class="sh-note">Monitoring only &mdash; feeding marks are recorded by the Feeding Coordinator.</p>
			</div>
			<div class="sh-actions">
				<button type="button" class="btn btn-secondary" id="shPrint">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
					Print program report
				</button>
			</div>
		</div>

		{{-- Paper carries no rail and no clock, so the masthead prints in their
		     place and is invisible on screen. --}}
		<div class="print-masthead" aria-hidden="true">
			<h2>School-Based Feeding Program</h2>
			<p>{{ $schoolName }} &middot; S.Y. {{ $schoolYear }} &middot; Feeding day {{ $stats['day'] }} of {{ $stats['duration'] }}</p>
			<p>Printed {{ $todayLabel }}</p>
		</div>

		{{-- ── Cycle statistics ────────────────────────────────────────── --}}
		<section class="kpi-grid">
			<article class="card kpi accent-brand">
				<div class="kpi-top">
					<div class="kpi-label">Beneficiaries Enrolled</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
				</div>
				<div class="kpi-value">{{ number_format($stats['beneficiaries']) }}</div>
				<div class="kpi-hint">Grades {{ implode(', ', \App\Support\FeedingBeneficiarySummary::GRADE_LEVELS) }}</div>
			</article>
			<article class="card kpi accent-success">
				<div class="kpi-top">
					<div class="kpi-label">Meals Served</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/></svg></div>
				</div>
				<div class="kpi-value">{{ number_format($stats['meals_served']) }}</div>
				<div class="kpi-hint">Of {{ number_format($stats['meals_planned']) }} planned to date &middot; {{ $shPct($stats['meals_percent']) }}</div>
			</article>
			<article class="card kpi accent-info">
				<div class="kpi-top">
					<div class="kpi-label">Average Turnout</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="m9 16 2 2 4-4"/></svg></div>
				</div>
				<div class="kpi-value">{{ $shPct($stats['turnout']) }}</div>
				<div class="kpi-hint">Across {{ $stats['days_completed'] }} recorded feeding {{ \Illuminate\Support\Str::plural('day', $stats['days_completed']) }}</div>
			</article>
			<article class="card kpi accent-orange">
				<div class="kpi-top">
					<div class="kpi-label">At Risk</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg></div>
				</div>
				<div class="kpi-value">{{ number_format($atRisk) }}</div>
				<div class="kpi-hint">
					{{ $rule }}@if ($observing > 0) &middot; {{ $observing }} under observation @endif
				</div>
			</article>
		</section>

		{{-- ── Feeding day grid ────────────────────────────────────────
		     120 cells, one per planned feeding day. Colour never travels
		     alone: every cell carries its day number and a title naming its
		     state and its turnout, and the legend spells the states out. ── --}}
		<section class="card section sh-gridcard">
			<div class="section-head">
				<h2 class="section-title">Feeding Days</h2>
				<div class="section-meta tnum">{{ $stats['days_completed'] }} of {{ $stats['duration'] }} recorded</div>
			</div>

			<ul class="sh-legend">
				@foreach ($legend as $key)
					<li class="sh-legend-item"><i class="sh-cell-key is-{{ $key['state'] }}"></i>{{ $key['label'] }}</li>
				@endforeach
			</ul>

			<div class="sh-grid" role="list" aria-label="Feeding days 1 to {{ $stats['duration'] }}">
				@foreach ($grid as $cell)
					<span class="sh-cell is-{{ $cell['state'] }}" role="listitem" title="{{ $cell['title'] }}">{{ $cell['day'] }}</span>
				@endforeach
			</div>
		</section>

		{{-- ── Nutritional status by grade level ───────────────────────
		     A diverging bar: undernutrition extends left of the centre line,
		     normal-and-above extends right, every bar on one shared scale.
		     The toggle moves the chart and its callout together. ── --}}
		<section class="card section">
			<div class="section-head">
				<h2 class="section-title">Nutritional Status by Grade Level</h2>
				<div class="sh-seg" role="group" aria-label="Weighing">
					<a class="sh-seg-btn {{ $phase === 'baseline' ? 'is-active' : '' }}"
					   href="{{ route('dashboard.school-head.program', ['phase' => 'baseline']) }}"
					   @if ($phase === 'baseline') aria-current="true" @endif>Baseline</a>
					<a class="sh-seg-btn {{ $phase === 'latest' ? 'is-active' : '' }}"
					   href="{{ route('dashboard.school-head.program', ['phase' => 'latest']) }}"
					   @if ($phase === 'latest') aria-current="true" @endif>Latest</a>
				</div>
			</div>

			@if (empty($chart['bars']))
				<p class="sh-empty">No learners on the roll yet. Grade bars appear once class advisers enrol them.</p>
			@else
				<p class="sh-callout">
					@if ($callout['worst_grade'])
						<strong>{{ $callout['worst_grade'] }}</strong> has the most severely wasted learners ({{ $callout['worst_count'] }}) at the {{ $callout['phase_label'] }}.
					@else
						No learner is severely wasted at the {{ $callout['phase_label'] }}.
					@endif
					School-wide, <strong>{{ number_format($callout['undernourished']) }}</strong> of
					{{ number_format($callout['measured']) }} measured {{ \Illuminate\Support\Str::plural('learner', $callout['measured']) }}
					{{ $callout['undernourished'] === 1 ? 'is' : 'are' }} undernourished.
					@if ($callout['not_measured'] > 0)
						{{ number_format($callout['not_measured']) }} {{ \Illuminate\Support\Str::plural('learner', $callout['not_measured']) }} not yet measured.
					@endif
				</p>

				<ul class="sh-divlegend">
					<li><i class="sh-swatch tone-sw"></i>Severely Wasted</li>
					<li><i class="sh-swatch tone-w"></i>Wasted</li>
					<li><i class="sh-swatch tone-n"></i>Normal</li>
					<li><i class="sh-swatch tone-ow"></i>Overweight</li>
					<li><i class="sh-swatch tone-ob"></i>Obese</li>
				</ul>

				<div class="sh-diverging">
					@foreach ($chart['bars'] as $bar)
						<div class="sh-div-row">
							<span class="sh-div-label">{{ $bar['label'] }}</span>
							<span class="sh-div-figure is-left tnum">{{ $bar['undernourished'] ?: '' }}</span>
							<div class="sh-div-track">
								<div class="sh-div-half is-left">
									@foreach ($bar['left'] as $seg)
										<span class="sh-div-seg tone-{{ $seg['tone'] }}"
										      style="width:{{ $seg['width'] }}%"
										      title="{{ $seg['label'] }}: {{ $seg['count'] }} ({{ rtrim(rtrim(number_format($seg['share'], 1), '0'), '.') }}% of {{ $bar['label'] }} measured)"></span>
									@endforeach
								</div>
								<span class="sh-div-axis" aria-hidden="true"></span>
								<div class="sh-div-half is-right">
									@foreach ($bar['right'] as $seg)
										<span class="sh-div-seg tone-{{ $seg['tone'] }}"
										      style="width:{{ $seg['width'] }}%"
										      title="{{ $seg['label'] }}: {{ $seg['count'] }} ({{ rtrim(rtrim(number_format($seg['share'], 1), '0'), '.') }}% of {{ $bar['label'] }} measured)"></span>
									@endforeach
								</div>
							</div>
							<span class="sh-div-figure is-right tnum">{{ $bar['total'] - $bar['undernourished'] - $bar['not_measured'] ?: '' }}</span>
						</div>
					@endforeach
				</div>

				{{-- The WCAG-clean twin: every value the chart encodes with
				     colour is also readable as text, so nothing is gated
				     behind hover or hue. --}}
				<details class="sh-table-view">
					<summary>Table view</summary>
					<div class="table-card">
						<div class="table-scroll">
							<table>
								<thead>
									<tr>
										<th>Grade</th>
										@foreach (\App\Support\SchoolHeadOverview::NUTRITION_SCALE as $status)
											<th class="num">{{ $status }}</th>
										@endforeach
										<th class="num">Not measured</th>
										<th class="num">Total</th>
									</tr>
								</thead>
								<tbody>
									@foreach ($chart['bars'] as $bar)
										<tr>
											@php $counts = collect($bar['left'])->merge($bar['right'])->keyBy('label'); @endphp
											<td><strong>{{ $bar['label'] }}</strong></td>
											@foreach (\App\Support\SchoolHeadOverview::NUTRITION_SCALE as $status)
												<td class="num">{{ $counts->get($status)['count'] ?? 0 }}</td>
											@endforeach
											<td class="num">{{ $bar['not_measured'] }}</td>
											<td class="num">{{ $bar['total'] }}</td>
										</tr>
									@endforeach
									<tr class="sh-total-row">
										<td><strong>All grades</strong></td>
										@foreach (\App\Support\SchoolHeadOverview::NUTRITION_SCALE as $status)
											<td class="num"><strong>{{ $chart['totals'][$status] }}</strong></td>
										@endforeach
										<td class="num"><strong>{{ $chart['totals'][\App\Support\SchoolHeadOverview::NOT_MEASURED] }}</strong></td>
										<td class="num"><strong>{{ array_sum($chart['totals']) }}</strong></td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</details>
			@endif
		</section>

		</div>
	</div>
</div>
<script>
	document.getElementById('shPrint')?.addEventListener('click', () => window.print());

	// The chart's table view is its twin on paper, and a closed <details>
	// prints closed — so it is opened before the print, not merely styled open.
	window.addEventListener('beforeprint', () => {
		document.querySelectorAll('details.sh-table-view').forEach((view) => { view.open = true; });
	});
</script>
@include('partials.schoolhead-live')
@include('partials.role-page-transition')
</body>
</html>
