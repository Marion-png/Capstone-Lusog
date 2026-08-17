<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>Masterlist - School Head - SIGLA</title>
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
@include('partials.schoolhead-sidebar', ['active' => 'masterlist'])

@php
	use App\Support\SchoolHeadOverview;

	$shYear = str_replace('-', '&ndash;', e($schoolYear));

	// The shared status scale, so a learner's badge here reads exactly as it
	// does on the coordinator's tabs. An unmeasured learner is neutral and says
	// so in words — never a green Normal pill.
	$shStatusBadge = fn (string $status) => match ($status) {
		'Severely Wasted' => 'badge-critical',
		'Wasted' => 'badge-risk',
		'Normal' => 'badge-normal',
		'Overweight', 'Obese' => 'badge-monitor',
		default => 'badge-neutral',
	};
	$shStatusLabel = fn (string $status) => $status !== '' ? $status : SchoolHeadOverview::NOT_MEASURED;

	$shMoveBadge = fn (string $move) => match ($move) {
		'improved' => 'badge-normal',
		'declined' => 'badge-critical',
		'same' => 'badge-neutral',
		'off_scale' => 'badge-monitor',
		default => 'badge-neutral',
	};

	$shPct = fn ($value) => $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 1), '0'), '.') . '%';
@endphp

<div class="main">
	<header class="topbar">
		<div class="topbar-bc"><span>School Head</span><span class="bc-sep">&rsaquo;</span><span>Masterlist</span></div>
		@include('partials.live-clock')
	</header>

	<div class="content">

		<div class="page-header sh-header">
			<div class="sh-headline">
				<div class="sh-title-row">
					<h1 class="page-title">Learner <span>Masterlist</span></h1>
					<span class="sh-year tnum">S.Y. {!! $shYear !!}</span>
				</div>
				<p class="sh-meta">
					<span>{{ $schoolName }}</span>
					<span class="sh-sep">&middot;</span>
					<span>Read-only &mdash; measurements are recorded by the class adviser</span>
				</p>
			</div>
			<div class="sh-actions">
				<a class="btn btn-secondary" href="{{ route('dashboard.school-head.masterlist.export', request()->query()) }}">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
					Export masterlist
				</a>
				<button type="button" class="btn btn-secondary" id="shPrint">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
					Print masterlist
				</button>
			</div>
		</div>

		<div class="print-masthead" aria-hidden="true">
			<h2>Learner Masterlist</h2>
			<p>{{ $schoolName }} &middot; S.Y. {{ $schoolYear }}</p>
			<p>Printed {{ $todayLabel }}</p>
		</div>

		{{-- ── Filters ────────────────────────────────────────────────────
		     Two kinds, the same split every other tab uses. School year, grade
		     and section are scope — they move the count and the table together.
		     Sex, baseline, latest, beneficiary standing and attendance standing
		     narrow the list alone. Every one but the school year is applied in
		     PHP, because every one of those columns is encrypted at rest. ── --}}
		<form method="GET" class="card sh-toolbar" id="shToolbar">
			<div class="sh-filter">
				<label class="field-label" for="mlYear">School year</label>
				<select class="select" name="school_year" id="mlYear">
					@foreach ($schoolYears as $year)
						<option value="{{ $year }}" @selected($filters['school_year'] === $year)>{{ $year }}</option>
					@endforeach
				</select>
			</div>
			<div class="sh-filter">
				<label class="field-label" for="mlGrade">Grade</label>
				<select class="select" name="grade" id="mlGrade">
					<option value="">All</option>
					@foreach ($filterOptions['grades'] as $grade)
						<option value="{{ $grade }}" @selected($filters['grade'] === $grade)>{{ $grade }}</option>
					@endforeach
				</select>
			</div>
			<div class="sh-filter">
				<label class="field-label" for="mlSection">Section</label>
				<select class="select" name="section" id="mlSection">
					<option value="">All</option>
					@foreach ($filterOptions['sections'] as $section)
						<option value="{{ $section }}" @selected($filters['section'] === $section)>{{ $section }}</option>
					@endforeach
				</select>
			</div>
			<div class="sh-filter">
				<label class="field-label" for="mlSex">Sex</label>
				<select class="select" name="sex" id="mlSex">
					<option value="">All</option>
					@foreach ($sexOptions as $sex)
						<option value="{{ $sex }}" @selected($filters['sex'] === $sex)>{{ $sex }}</option>
					@endforeach
				</select>
			</div>
			<div class="sh-filter">
				<label class="field-label" for="mlBaseline">Baseline status</label>
				<select class="select" name="baseline" id="mlBaseline">
					<option value="">All</option>
					@foreach ($statusOptions as $status)
						<option value="{{ $status }}" @selected($filters['baseline'] === $status)>
							{{ $status === 'not_measured' ? SchoolHeadOverview::NOT_MEASURED : $status }}
						</option>
					@endforeach
				</select>
			</div>
			<div class="sh-filter">
				<label class="field-label" for="mlLatest">Latest status</label>
				<select class="select" name="latest" id="mlLatest">
					<option value="">All</option>
					@foreach ($statusOptions as $status)
						<option value="{{ $status }}" @selected($filters['latest'] === $status)>
							{{ $status === 'not_measured' ? SchoolHeadOverview::NOT_MEASURED : $status }}
						</option>
					@endforeach
				</select>
			</div>
			<div class="sh-filter">
				<label class="field-label" for="mlStanding">Beneficiary</label>
				<select class="select" name="standing" id="mlStanding">
					<option value="">All</option>
					<option value="beneficiary" @selected($filters['standing'] === 'beneficiary')>Enrolled beneficiary</option>
					<option value="pending" @selected($filters['standing'] === 'pending')>Awaiting enrolment</option>
					<option value="not_eligible" @selected($filters['standing'] === 'not_eligible')>Not a beneficiary</option>
				</select>
			</div>
			<div class="sh-filter">
				<label class="field-label" for="mlAttendance">Attendance</label>
				<select class="select" name="attendance" id="mlAttendance">
					<option value="">All</option>
					<option value="at_risk" @selected($filters['attendance'] === 'at_risk')>At Risk</option>
					<option value="early_monitoring" @selected($filters['attendance'] === 'early_monitoring')>Early monitoring</option>
					<option value="on_track" @selected($filters['attendance'] === 'on_track')>On track</option>
					<option value="no_sessions" @selected($filters['attendance'] === 'no_sessions')>No confirmed session</option>
				</select>
			</div>

			{{-- No-JS fallback: without it the selects would be unreachable
			     controls on a page that never reloads. --}}
			<noscript><button type="submit" class="btn btn-secondary">Apply</button></noscript>
		</form>

		<section class="table-card sh-listcard">
			<div class="sh-listhead">
				<div>
					<h2 class="card-title">Learners</h2>
					<p class="card-sub tnum" id="mlCount">Showing {{ number_format($shown) }} of {{ number_format($total) }} learners</p>
				</div>
				<div class="lg-search sh-search">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
					<input type="search" id="mlSearch" placeholder="Search by name or LRN" aria-label="Search by name or LRN">
				</div>
			</div>

			@if ($rows->isEmpty())
				<p class="table-empty">No learner matches those filters. Clear the search or pick another grade level.</p>
			@else
				<div class="table-scroll">
					<table class="sh-table" id="mlTable">
						<thead>
							<tr>
								<th class="num">#</th>
								<th data-sort="lrn">LRN</th>
								<th data-sort="name">Name</th>
								<th data-sort="section">Grade &amp; Section</th>
								<th data-sort="sex">Sex</th>
								<th class="num" data-sort="age">Age</th>
								<th class="num" data-sort="weight">Weight (kg)</th>
								<th class="num" data-sort="height">Height (cm)</th>
								<th class="num" data-sort="bmi">BMI</th>
								<th data-sort="baseline">Baseline</th>
								<th data-sort="latest">Latest</th>
								<th data-sort="movement">Change</th>
							</tr>
						</thead>
						<tbody>
							@foreach ($rows as $row)
								<tr class="sh-row"
								    data-row="{{ $row['id'] }}"
								    data-search="{{ strtolower($row['name'].' '.$row['lrn']) }}"
								    data-lrn="{{ $row['lrn'] }}"
								    data-name="{{ $row['name'] }}"
								    data-section="{{ $row['section'] }}"
								    data-sex="{{ $row['sex'] }}"
								    data-age="{{ $row['age'] }}"
								    data-weight="{{ $row['weight'] }}"
								    data-height="{{ $row['height'] }}"
								    data-bmi="{{ $row['bmi'] }}"
								    data-baseline="{{ $shStatusLabel($row['baseline']) }}"
								    data-latest="{{ $shStatusLabel($row['latest']) }}"
								    data-movement="{{ SchoolHeadOverview::movementLabel($row['movement']) }}"
								    data-meta="{{ $row['section'] }}{{ $row['sex'] !== '' ? ' · '.$row['sex'] : '' }}"
								    data-standing="{{ $row['standing_label'] }}">
									<td class="num sh-index"></td>
									<td class="tnum">{{ $row['lrn'] !== '' ? $row['lrn'] : '—' }}</td>
									{{-- The whole cell is the target, so a head aims at the
									     column rather than at the glyphs. --}}
									<td class="sh-name is-link">
										<button type="button" class="sh-namebtn" data-detail-open="{{ $row['id'] }}">
											<strong>{{ $row['name'] }}</strong>
										</button>
									</td>
									<td>{{ $row['section'] }}</td>
									<td>{{ $row['sex'] !== '' ? $row['sex'] : '—' }}</td>
									<td class="num">{{ $row['age'] !== '' ? $row['age'] : '—' }}</td>
									<td class="num">{{ $row['weight'] !== '' ? $row['weight'] : '—' }}</td>
									<td class="num">{{ $row['height'] !== '' ? $row['height'] : '—' }}</td>
									<td class="num">{{ $row['bmi'] !== '' ? $row['bmi'] : '—' }}</td>
									<td><span class="badge {{ $shStatusBadge($row['baseline']) }}">{{ $shStatusLabel($row['baseline']) }}</span></td>
									<td><span class="badge {{ $shStatusBadge($row['latest']) }}">{{ $shStatusLabel($row['latest']) }}</span></td>
									<td>
										@if ($row['movement'] === 'unknown')
											<span class="muted">&mdash;</span>
										@else
											<span class="badge {{ $shMoveBadge($row['movement']) }}">{{ SchoolHeadOverview::movementLabel($row['movement']) }}</span>
										@endif
									</td>
								</tr>
							@endforeach
						</tbody>
					</table>
				</div>
				<p class="table-empty" id="mlNoMatch" hidden>No learner matches that search.</p>
			@endif
		</section>

		{{-- ── One learner's record ───────────────────────────────────────
		     Server-rendered per row into a template and cloned into the dialog
		     on open, so the panel and the row are one render and cannot
		     disagree — and the record stays out of the document flow and off
		     the printer. There is no edit control anywhere in it. ── --}}
		@foreach ($rows as $row)
			<template class="sh-detail-source" data-detail-for="{{ $row['id'] }}">
				<div class="sh-detail">
					<section class="sh-panel-box">
						<h3 class="sh-panel-title">Measurement History</h3>
						@if (empty($row['history']))
							<p class="sh-empty">No measurement has been recorded for this learner.</p>
						@else
							<table class="sh-mini">
								<thead>
									<tr><th>Reading</th><th>Date</th><th class="num">Weight</th><th class="num">Height</th><th class="num">BMI</th><th>Status</th></tr>
								</thead>
								<tbody>
									@foreach ($row['history'] as $entry)
										<tr>
											<td><strong>{{ $entry['phase'] }}</strong></td>
											<td class="tnum">{{ $entry['date'] }}</td>
											<td class="num">{{ $entry['weight'] !== '' ? $entry['weight'] : '—' }}</td>
											<td class="num">{{ $entry['height'] !== '' ? $entry['height'] : '—' }}</td>
											<td class="num">{{ $entry['bmi'] !== '' ? $entry['bmi'] : '—' }}</td>
											<td>{{ $entry['status'] }}</td>
										</tr>
									@endforeach
								</tbody>
							</table>
						@endif

						@if ($row['sparkline'])
							<p class="sh-panel-sub">BMI over the readings on file</p>
							<svg class="sh-spark" viewBox="0 0 100 40" preserveAspectRatio="none" role="img"
							     aria-label="BMI from {{ $row['sparkline']['min'] }} to {{ $row['sparkline']['max'] }}">
								<polyline fill="none" stroke="var(--series-healthy)" stroke-width="2" vector-effect="non-scaling-stroke"
								          points="@foreach ($row['sparkline']['points'] as $point){{ $point['x'] }},{{ $point['y'] * 0.4 }} @endforeach"></polyline>
								@foreach ($row['sparkline']['points'] as $point)
									<circle cx="{{ $point['x'] }}" cy="{{ $point['y'] * 0.4 }}" r="1.6" fill="var(--series-healthy)" vector-effect="non-scaling-stroke"></circle>
								@endforeach
							</svg>
							<p class="sh-spark-axis tnum">
								@foreach ($row['sparkline']['points'] as $point)
									<span>{{ $point['label'] }} {{ $point['value'] }}</span>
								@endforeach
							</p>
						@endif
					</section>

					<section class="sh-panel-box">
						<h3 class="sh-panel-title">Feeding Programme</h3>
						<dl class="sh-facts">
							<div class="sh-fact"><dt>Standing</dt><dd>{{ $row['standing_label'] }}</dd></div>
							@if ($row['enrolled_on'])
								<div class="sh-fact"><dt>Enrolled</dt><dd>{{ $row['enrolled_on'] }}</dd></div>
							@endif
							<div class="sh-fact"><dt>Attendance</dt><dd>{{ $shPct($row['rate']) }}</dd></div>
							<div class="sh-fact"><dt>Present</dt><dd>{{ $row['present'] }} of {{ $row['confirmed'] }} confirmed</dd></div>
							<div class="sh-fact"><dt>Absent</dt><dd>{{ $row['absent'] }}</dd></div>
							{{-- Feeding days no sheet covered this learner. Named on
							     its own, never counted as an absence. --}}
							<div class="sh-fact"><dt>Not marked</dt><dd>{{ $row['not_marked'] }}</dd></div>
							<div class="sh-fact"><dt>Standing</dt><dd>{{ $row['attendance_label'] }}</dd></div>
						</dl>
						<p class="sh-panel-note">{{ $rule }}.</p>
					</section>
				</div>
			</template>
		@endforeach
	</div>
</div>

<div class="modal-backdrop" id="detailBackdrop" role="dialog" aria-modal="true" aria-labelledby="detailTitle" hidden>
	<div class="modal-panel sh-detail-modal">
		<div class="modal-head">
			<div class="sh-detail-ident">
				<p class="sh-modal-eyebrow">Learner record</p>
				<p class="modal-title" id="detailTitle"></p>
				<p class="sh-modal-meta" id="detailMeta"></p>
			</div>
			<button type="button" class="modal-close" data-detail-close aria-label="Close">&times;</button>
		</div>

		<div class="modal-body sh-detail-body" id="detailBody"></div>

		<div class="modal-foot">
			<span class="sh-modal-note">Read-only &mdash; measurements are recorded by the class adviser.</span>
			<div class="sh-modal-actions">
				<button type="button" class="btn btn-secondary" data-detail-close>Close</button>
			</div>
		</div>
	</div>
</div>

<script>
(() => {
	document.getElementById('shPrint')?.addEventListener('click', () => window.print());

	// Every control in the toolbar applies itself — an Apply button nobody
	// presses is a filter that silently does nothing.
	document.querySelectorAll('#shToolbar select').forEach((control) => {
		control.addEventListener('change', () => {
			control.form.requestSubmit ? control.form.requestSubmit() : control.form.submit();
		});
	});

	const table = document.getElementById('mlTable');
	if (!table) return;

	const body = table.querySelector('tbody');
	const rows = Array.from(body.querySelectorAll('tr.sh-row'));
	const search = document.getElementById('mlSearch');
	const noMatch = document.getElementById('mlNoMatch');
	const count = document.getElementById('mlCount');
	const total = rows.length;

	// The # column is renumbered after every search and sort, so it always
	// reads 1..n for the rows actually on screen.
	const renumber = () => {
		let shown = 0;
		rows.forEach((row) => {
			if (row.hidden) return;
			shown++;
			row.querySelector('.sh-index').textContent = shown;
		});
		if (noMatch) noMatch.hidden = !(shown === 0 && total > 0);
		if (count) count.textContent = 'Showing ' + shown + ' of ' + total + ' learners';
		return shown;
	};

	search?.addEventListener('input', () => {
		const term = search.value.trim().toLowerCase();
		rows.forEach((row) => { row.hidden = term !== '' && !row.dataset.search.includes(term); });
		renumber();
	});

	// Client-side sort. Numeric columns compare as numbers so "9" sorts below
	// "10"; a blank sorts last either way, because an unmeasured learner is not
	// the smallest measurement.
	const numeric = new Set(['age', 'weight', 'height', 'bmi']);
	let sortKey = null;
	let ascending = true;

	table.querySelectorAll('th[data-sort]').forEach((head) => {
		head.classList.add('is-sortable');
		head.setAttribute('tabindex', '0');
		head.setAttribute('role', 'button');

		const apply = () => {
			const key = head.dataset.sort;
			ascending = sortKey === key ? !ascending : true;
			sortKey = key;

			table.querySelectorAll('th[data-sort]').forEach((other) => {
				other.classList.remove('is-asc', 'is-desc');
				other.removeAttribute('aria-sort');
			});
			head.classList.add(ascending ? 'is-asc' : 'is-desc');
			head.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');

			const sorted = rows.slice().sort((a, b) => {
				const left = a.dataset[key] ?? '';
				const right = b.dataset[key] ?? '';
				if (left === '' && right === '') return 0;
				if (left === '') return 1;
				if (right === '') return -1;

				const compared = numeric.has(key)
					? parseFloat(left) - parseFloat(right)
					: left.localeCompare(right, undefined, { numeric: true, sensitivity: 'base' });

				return ascending ? compared : -compared;
			});

			sorted.forEach((row) => body.appendChild(row));
			renumber();
		};

		head.addEventListener('click', apply);
		head.addEventListener('keydown', (event) => {
			if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); apply(); }
		});
	});

	// ── The learner's record ───────────────────────────────────────────
	const backdrop = document.getElementById('detailBackdrop');
	const detailBody = document.getElementById('detailBody');
	const detailTitle = document.getElementById('detailTitle');
	const detailMeta = document.getElementById('detailMeta');
	let opener = null;

	const close = () => {
		backdrop.classList.remove('open');
		backdrop.hidden = true;
		if (opener && document.contains(opener)) { opener.focus(); opener = null; }
	};

	document.addEventListener('click', (event) => {
		const trigger = event.target.closest('[data-detail-open]');
		if (trigger) {
			event.preventDefault();
			const id = trigger.dataset.detailOpen;
			const row = rows.find((candidate) => candidate.dataset.row === String(id));
			const source = document.querySelector('template.sh-detail-source[data-detail-for="' + id + '"]');
			if (!row || !source) return;

			opener = trigger;
			detailTitle.textContent = row.dataset.name ?? '';
			detailMeta.textContent = [row.dataset.meta, 'LRN ' + (row.dataset.lrn || '—'), row.dataset.standing]
				.filter(Boolean).join(' · ');
			detailBody.replaceChildren(source.content.cloneNode(true));
			detailBody.scrollTop = 0;
			backdrop.hidden = false;
			backdrop.classList.add('open');
			backdrop.querySelector('.modal-close')?.focus();
			return;
		}

		if (event.target.closest('[data-detail-close]') || event.target === backdrop) close();
	});

	document.addEventListener('keydown', (event) => {
		if (event.key === 'Escape' && !backdrop.hidden) close();
	});

	renumber();
})();
</script>
@include('partials.role-page-transition')
</body>
</html>
