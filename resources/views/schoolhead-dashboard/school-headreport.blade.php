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
		     Rehabilitation and improvement are counted separately and never
		     merged: a learner who climbed from Severely Wasted to Wasted has
		     improved but is not rehabilitated. ── --}}
		<section class="kpi-grid">
			<article class="card kpi accent-success">
				<div class="kpi-top">
					<div class="kpi-label">Rehabilitated</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg></div>
				</div>
				<div class="kpi-value">{{ number_format($outcome['rehabilitated']) }}</div>
				<div class="kpi-hint">Normal at endline, of {{ number_format($outcome['beneficiaries']) }} beneficiaries</div>
			</article>
			<article class="card kpi accent-brand">
				<div class="kpi-top">
					<div class="kpi-label">Rehabilitation Rate</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="m19 9-5 5-4-4-3 3"/></svg></div>
				</div>
				<div class="kpi-value">{{ $shPct($outcome['rate']) }}</div>
				<div class="kpi-hint">
					@if ($outcome['beneficiaries'] === 0)
						No beneficiaries enrolled &mdash; the rate is undefined
					@elseif ($outcome['measured'] === 0)
						No endline measurement recorded yet &mdash; the rate is undefined
					@elseif ($target !== null)
						Target {{ $shPct($target) }} &middot; {{ number_format($outcome['measured']) }} measured
					@else
						{{ number_format($outcome['measured']) }} of {{ number_format($outcome['beneficiaries']) }} measured at endline
					@endif
				</div>
			</article>
			<article class="card kpi accent-orange">
				<div class="kpi-top">
					<div class="kpi-label">Still Undernourished</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg></div>
				</div>
				<div class="kpi-value">{{ number_format($outcome['still_undernourished']) }}</div>
				<div class="kpi-hint">{{ number_format($outcome['improved']) }} improved without reaching Normal or better</div>
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

		{{-- ── Reports and decisions ───────────────────────────────────
		     The one place this role writes. Approving stamps the head's name
		     and the time; returning requires a remark; locking ends the line
		     and is refused server-side thereafter. ── --}}
		<section class="card section">
			<div class="section-head">
				<h2 class="section-title">Reports</h2>
				<div class="section-meta">Exports are .xlsx workbooks &middot; print for a PDF</div>
			</div>

			@if (! $reviewsAvailable)
				<div class="alert-bar is-info">
					<div class="alert-body">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
						<div>
							<strong>Report decisions are unavailable</strong>
							<span>The report_reviews table has not been migrated yet. Exports still work.</span>
						</div>
					</div>
				</div>
			@endif

			<ul class="sh-reports">
				@foreach ($reports as $report)
					<li class="card sh-report">
						<div class="sh-report-main">
							<div class="sh-report-head">
								<strong>{{ $report['name'] }}</strong>
								<span class="badge {{ $report['badge'] }}">{{ $report['status_label'] }}</span>
							</div>
							<p class="sh-report-sub">{{ $report['summary'] }}</p>
							<p class="sh-report-detail tnum">{{ $report['detail'] }}</p>
							@if ($report['reviewed_at'])
								<p class="sh-report-stamp">
									{{ $report['status_label'] }} by {{ $report['reviewed_by'] ?: 'the School Head' }}
									on {{ $report['reviewed_at'] }}.
									@if ($report['remarks'] !== '')
										<span class="sh-report-remark">&ldquo;{{ $report['remarks'] }}&rdquo;</span>
									@endif
								</p>
							@endif
						</div>

						<div class="sh-report-actions">
							<a class="btn btn-secondary"
							   href="{{ route('dashboard.school-head.reports.export', ['report' => $report['key'], 'school_year' => $schoolYear]) }}">
								Export
							</a>
							@if ($reviewsAvailable && ! $report['locked'])
								<button type="button" class="btn btn-primary"
								        data-review-open data-report="{{ $report['key'] }}" data-decision="approve"
								        data-name="{{ $report['name'] }}">Approve</button>
								<button type="button" class="btn btn-secondary"
								        data-review-open data-report="{{ $report['key'] }}" data-decision="return"
								        data-name="{{ $report['name'] }}">Return for correction</button>
								<button type="button" class="btn btn-secondary"
								        data-review-open data-report="{{ $report['key'] }}" data-decision="lock"
								        data-name="{{ $report['name'] }}">Lock</button>
							@elseif ($report['locked'])
								<span class="sh-report-locked">Locked &mdash; no further changes</span>
							@endif
						</div>
					</li>
				@endforeach

				{{-- The masterlist is always current, so it has no decision to
				     record — only a link to where it is read and exported. --}}
				<li class="card sh-report">
					<div class="sh-report-main">
						<div class="sh-report-head">
							<strong>Masterlist</strong>
							<span class="badge badge-info">Always current</span>
						</div>
						<p class="sh-report-sub">The full learner list with measurements and attendance standing.</p>
						<p class="sh-report-detail">Read and filtered on the Masterlist tab.</p>
					</div>
					<div class="sh-report-actions">
						<a class="btn btn-secondary" href="{{ route('dashboard.school-head.masterlist', ['school_year' => $schoolYear]) }}">Open masterlist</a>
						<a class="btn btn-secondary" href="{{ route('dashboard.school-head.masterlist.export', ['school_year' => $schoolYear]) }}">Export</a>
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

{{-- ── Record a decision ───────────────────────────────────────────────────
     One dialog and one endpoint whichever card opened it, using the same
     backdrop / panel / head / body / foot anatomy as every other dialog in the
     product. ── --}}
@if ($reviewsAvailable)
	<div class="modal-backdrop" id="reviewBackdrop" role="dialog" aria-modal="true" aria-labelledby="reviewTitle" hidden>
		<div class="modal-panel sh-modal">
			<form method="POST" id="reviewForm" action="{{ route('dashboard.school-head.reports.review') }}">
				@csrf
				<input type="hidden" name="school_year" value="{{ $schoolYear }}">
				<input type="hidden" name="report" id="reviewReportKey" value="">
				<input type="hidden" name="decision" id="reviewDecision" value="approve">

				<div class="modal-head">
					<div>
						<p class="sh-modal-eyebrow">Report decision</p>
						<p class="modal-title" id="reviewTitle">Approve report</p>
						<p class="sh-modal-meta" id="reviewReport"></p>
					</div>
					<button type="button" class="modal-close" data-review-close aria-label="Close">&times;</button>
				</div>

				<div class="modal-body sh-modal-body">
					<p class="sh-modal-note" id="reviewExplain"></p>
					<div class="sh-field">
						<label class="field-label" for="reviewRemarks">Remarks</label>
						<textarea class="input sh-textarea" name="remarks" id="reviewRemarks" rows="3" maxlength="500"></textarea>
					</div>
				</div>

				<div class="modal-foot">
					<span class="sh-modal-note">Recorded as {{ $headName }}</span>
					<div class="sh-modal-actions">
						<button type="button" class="btn btn-secondary" data-review-close>Cancel</button>
						<button type="submit" class="btn btn-primary" id="reviewSubmit">Approve</button>
					</div>
				</div>
			</form>
		</div>
	</div>
@endif

<script>
(() => {
	document.getElementById('shPrint')?.addEventListener('click', () => window.print());

	// The year picker applies itself — an Apply button nobody presses is a
	// filter that silently does nothing.
	document.getElementById('shYear')?.addEventListener('change', (event) => {
		const form = event.target.form;
		form.requestSubmit ? form.requestSubmit() : form.submit();
	});

	const backdrop = document.getElementById('reviewBackdrop');
	if (!backdrop) return;

	const reportKey = document.getElementById('reviewReportKey');
	const decision = document.getElementById('reviewDecision');
	const title = document.getElementById('reviewTitle');
	const report = document.getElementById('reviewReport');
	const explain = document.getElementById('reviewExplain');
	const remarks = document.getElementById('reviewRemarks');
	const submit = document.getElementById('reviewSubmit');

	const wording = {
		approve: {
			title: 'Approve report',
			submit: 'Approve',
			explain: 'Your name and the time are stamped on the report and written to the audit trail.',
			required: false,
		},
		return: {
			title: 'Return for correction',
			submit: 'Return for correction',
			explain: 'Say what has to be corrected — a returned report without a remark tells nobody anything.',
			required: true,
		},
		lock: {
			title: 'Lock report',
			submit: 'Lock',
			explain: 'Locking ends the line: the report can no longer be approved, returned or locked again.',
			required: false,
		},
	};

	let opener = null;

	const close = () => {
		backdrop.classList.remove('open');
		backdrop.hidden = true;
		if (opener && document.contains(opener)) { opener.focus(); opener = null; }
	};

	document.addEventListener('click', (event) => {
		const trigger = event.target.closest('[data-review-open]');
		if (trigger) {
			event.preventDefault();
			opener = trigger;
			const kind = wording[trigger.dataset.decision] ?? wording.approve;

			// The report key travels as a field, not a path segment — a monthly
			// key holds a colon, and one endpoint is easier to audit than many.
			reportKey.value = trigger.dataset.report;
			decision.value = trigger.dataset.decision;
			title.textContent = kind.title;
			submit.textContent = kind.submit;
			explain.textContent = kind.explain;
			report.textContent = trigger.dataset.name;
			remarks.value = '';
			remarks.required = kind.required;

			backdrop.hidden = false;
			backdrop.classList.add('open');
			remarks.focus();
			return;
		}

		if (event.target.closest('[data-review-close]') || event.target === backdrop) {
			close();
		}
	});

	document.addEventListener('keydown', (event) => {
		if (event.key === 'Escape' && !backdrop.hidden) close();
	});
})();
</script>
@include('partials.role-page-transition')
</body>
</html>
