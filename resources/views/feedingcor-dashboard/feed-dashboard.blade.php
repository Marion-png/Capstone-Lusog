<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>Feeding Coordinator Dashboard - SIGLA</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
	<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
	<script>document.documentElement.classList.add('js');</script>
	<style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
	@php $pageCssPath = resource_path('css/feeding-dashboard.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
    <style>{!! file_get_contents(resource_path('css/role-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.feedingcor-sidebar', ['active' => 'dashboard'])

<div class="main">
	<header class="topbar">
		<div class="topbar-bc"><span>Dashboard</span><span class="bc-sep">&rsaquo;</span><span>Feeding Program</span></div>
	    @include('partials.live-clock')
	</header>

	<div class="content" id="fc-dashboard"
		data-stamp="{{ $stamp }}"
		data-metrics-url="{{ route('dashboard.feedingcor.metrics') }}"
		data-pulse-url="{{ route('dashboard.feedingcor.metrics.pulse') }}">
		@php
			$cycle = $programCycle ?? ['school_year' => '', 'day' => 0, 'duration' => 120, 'days_remaining' => 120, 'percent' => 0, 'started' => false, 'start_date' => null];
			$cycleDuration = (int) $cycle['duration'];
			$cycleDay = (int) $cycle['day'];
			// En dash in the school year: it is a range, not a hyphenated word.
			$cycleYear = str_replace('-', '&ndash;', e((string) $cycle['school_year']));
		@endphp
		<div class="page-header sbfp-header" id="dashboard">
			{{-- Same two voices as every other tab's title: the subject upright,
			     the section italic emerald. --}}
			<div class="sbfp-title-row">
				<h1 class="page-title">School-Based Feeding Program <span>Dashboard</span></h1>
				<span class="sbfp-year">S.Y. {!! $cycleYear !!}</span>
			</div>

			<p class="sbfp-day {{ $cycle['started'] ? '' : 'is-idle' }}">
				<span class="sbfp-dot" aria-hidden="true"></span>
				@if ($cycle['started'])
					Feeding day <strong data-cycle-day>{{ $cycleDay }}</strong> of {{ $cycleDuration }}
					<span class="sbfp-sep">&middot;</span>
					<span data-cycle-remaining>{{ $cycle['days_remaining'] }} days remaining</span>
				@else
					No feeding session recorded yet
					<span class="sbfp-sep">&middot;</span>
					{{ $cycleDuration }}-day cycle
				@endif
			</p>

			{{-- Server-rendered fill; the script below re-reads the calendar so a
			     page left open overnight still shows today's day. The figure
			     leads at full size — the bar behind it only gives the number a
			     shape, and a 10px track reads as a measure rather than a hairline.
			     The action shares this line, so it sits level with the bar. --}}
			<div class="sbfp-progress-row">
				<span class="sbfp-progress-pct" data-cycle-percent>{{ number_format((float) $cycle['percent'], 1) }}%</span>
				<div class="sbfp-progress" id="sbfpProgress" role="progressbar"
					aria-valuemin="0" aria-valuemax="{{ $cycleDuration }}" aria-valuenow="{{ $cycleDay }}"
					aria-valuetext="Feeding day {{ $cycleDay }} of {{ $cycleDuration }}"
					data-start="{{ $cycle['start_date'] }}" data-duration="{{ $cycleDuration }}">
					<span class="sbfp-progress-fill" style="width: {{ $cycle['percent'] }}%"></span>
				</div>
				<span class="sbfp-progress-end">Day {{ $cycleDuration }}</span>
				<div class="sbfp-actions">
					<button type="button" class="btn btn-secondary" id="enrollBeneficiaryBtn"
						data-candidates-url="{{ route('feedingcor-program.enrollment.candidates') }}"
						data-enroll-url="{{ route('feedingcor-program.enrollment.store') }}">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
						Enroll beneficiary
					</button>
				</div>
			</div>
		</div>

		@if (session('success'))
			<div class="flash ok">{{ session('success') }}</div>
		@endif
		@if (session('error'))
			<div class="flash err">{{ session('error') }}</div>
		@endif

		@include('partials.announcements')

		<section class="kpi-grid live-pane" id="fc-cards">
			@include('feedingcor-dashboard.partials.kpi-cards')
		</section>

		{{-- One coordinated set: school year, grade and section scope the whole
		     page — the cards above these controls as well as the panels below —
		     while nutritional and attendance status narrow the attendance roll.
		     The section list is rebuilt server-side for the chosen grade, so
		     Grade 8's sections are never selectable under Grade 7. --}}
		<form method="GET" class="card fc-filters" id="fcFilters">
			<div class="fc-filter">
				<label class="field-label" for="filterSchoolYear">School Year</label>
				<select class="select" name="school_year" id="filterSchoolYear">
					@foreach ($filterOptions['school_years'] as $year)
						<option value="{{ $year }}" @selected($filters['school_year'] === $year)>{{ $year }}</option>
					@endforeach
				</select>
			</div>
			<div class="fc-filter">
				<label class="field-label" for="filterGrade">Grade</label>
				<select class="select" name="grade" id="filterGrade">
					<option value="">All grades</option>
					@foreach ($filterOptions['grades'] as $grade)
						<option value="{{ $grade }}" @selected($filters['grade'] === $grade)>{{ $grade }}</option>
					@endforeach
				</select>
			</div>
			<div class="fc-filter">
				<label class="field-label" for="filterSection">Section</label>
				<select class="select" name="section" id="filterSection">
					<option value="">All sections</option>
					@foreach ($filterOptions['sections'] as $section)
						<option value="{{ $section }}" @selected($filters['section'] === $section)>{{ $section }}</option>
					@endforeach
				</select>
			</div>
			<div class="fc-filter">
				<label class="field-label" for="filterStatus">Nutritional Status</label>
				<select class="select" name="status" id="filterStatus">
					<option value="">All statuses</option>
					@foreach ($filterOptions['statuses'] as $status)
						<option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $status }}</option>
					@endforeach
				</select>
			</div>
			<div class="fc-filter">
				<label class="field-label" for="filterAttendance">Attendance Status</label>
				<select class="select" name="attendance" id="filterAttendance">
					<option value="">All</option>
					@foreach ($filterOptions['attendance'] as $option)
						<option value="{{ $option['value'] }}" @selected($filters['attendance'] === $option['value'])>{{ $option['label'] }}</option>
					@endforeach
				</select>
			</div>
			<div class="fc-filter-actions">
				{{-- No Apply button: choosing a filter applies it. This submit
				     exists only for a browser with JS off, where a change event
				     cannot submit the form on the reader's behalf. --}}
				<noscript><button type="submit" class="btn btn-primary">Apply</button></noscript>
				{{-- A school year other than the current one counts as a filter
				     too, or there would be no way back from it. --}}
				@if ($filters['grade'] !== '' || $filters['section'] !== '' || $filters['status'] !== '' || $filters['attendance'] !== '' || $filters['school_year'] !== \App\Models\StudentHealthRecord::currentSchoolYear())
					<a class="btn btn-ghost" href="{{ route('dashboard.feedingcor-dashboard') }}">Clear</a>
				@endif
			</div>
		</form>

		<section class="dash-stack" id="feeding-program">
			{{-- Today's session is the coordinator's working surface, so it takes
			     the wide column; the status roll sits beside it as reference. --}}
			<article class="card att-card">
				<div class="card-head">
					<div>
						<h2 class="card-title">Attendance Monitoring</h2>
						<p class="card-sub">Today&rsquo;s feeding attendance &middot; <span id="fc-updated">{{ $generatedAt }}</span></p>
					</div>
					<a class="btn btn-primary" href="{{ route('feedingcor-program.attendance.record') }}">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
						Record Today&rsquo;s Attendance
					</a>
				</div>
				<div class="live-pane" id="fc-attendance">
					@include('feedingcor-dashboard.partials.attendance-monitoring')
				</div>
			</article>

			<article class="card ns-card">
				<div class="card-head">
					<div>
						<h2 class="card-title">Nutritional Status</h2>
						<p class="card-sub">Beneficiaries by baseline status.</p>
					</div>
				</div>
				<div class="live-pane" id="fc-nutrition">
					@include('feedingcor-dashboard.partials.nutrition-status')
				</div>
			</article>
		</section>

		<section class="card risk-card">
			<div class="card-head">
				<div>
					<h2 class="card-title">
						<svg class="card-title-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
						Attendance At Risk
					</h2>
					<p class="card-sub">Beneficiaries below the school&rsquo;s cumulative attendance threshold.</p>
				</div>
				<a class="btn btn-secondary" href="{{ route('dashboard.feedingcor-program') }}#atRiskSection">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/></svg>
					View At-Risk List
				</a>
			</div>
			<div class="live-pane" id="fc-risk">
				@include('feedingcor-dashboard.partials.attendance-risk')
			</div>
		</section>

		<section class="card np-card">
			<div class="card-head">
				<div>
					<h2 class="card-title">Nutritional Progress</h2>
					<p class="card-sub">Improved Nutritional Status &mdash; baseline against endline.</p>
				</div>
			</div>
			<div class="live-pane" id="fc-progress">
				@include('feedingcor-dashboard.partials.nutrition-progress')
			</div>
		</section>

	</div>
</div>

{{-- Enrolment: the coordinator's decision about who the programme feeds.
     The list is fetched when the modal opens and re-read on the dashboard's
     own pulse while it stays open, so a learner an adviser measures during the
     session appears here without a reload. Filters are client-side because the
     list is small and the names are encrypted at rest — the server cannot
     search them in SQL. --}}
<div class="modal-backdrop" id="enrollBackdrop" aria-hidden="true">
	<div class="modal-panel enroll-panel" role="dialog" aria-modal="true" aria-labelledby="enrollTitle">
		<div class="modal-head enroll-head">
			<div>
				<div class="enroll-eyebrow">Enroll beneficiary</div>
				<h2 class="modal-title enroll-title" id="enrollTitle">Qualified learners</h2>
				<p class="enroll-sub" id="enrollSub">Learners measured as wasted or severely wasted. Enrolling one starts their meals at the next feeding day.</p>
			</div>
			<button type="button" class="modal-close enroll-close" id="enrollClose" aria-label="Close">&times;</button>
		</div>

		<div class="enroll-filters">
			<div class="enroll-filter">
				<label class="field-label" for="enrollSearch">Name</label>
				<input type="search" class="input" id="enrollSearch" placeholder="Search a learner" autocomplete="off">
			</div>
			<div class="enroll-filter">
				<label class="field-label" for="enrollGrade">Grade</label>
				<select class="select" id="enrollGrade"><option value="">All</option></select>
			</div>
			<div class="enroll-filter">
				<label class="field-label" for="enrollSection">Section</label>
				<select class="select" id="enrollSection"><option value="">All sections</option></select>
			</div>
			<div class="enroll-filter">
				<label class="field-label" for="enrollStatus">Status</label>
				<select class="select" id="enrollStatus">
					<option value="">All statuses</option>
					<option value="Severely Wasted">Severely wasted</option>
					<option value="Wasted">Wasted</option>
				</select>
			</div>
		</div>

		<div class="enroll-countbar">
			<span id="enrollShowing">Loading&hellip;</span>
			<button type="button" class="enroll-clear" id="enrollClearFilters">Clear filters</button>
		</div>

		<div class="modal-body enroll-body">
			<table class="enroll-table">
				<thead>
					<tr>
						<th class="enroll-check"><input type="checkbox" id="enrollCheckAll" aria-label="Select every learner shown"></th>
						<th>Name</th>
						<th>Grade</th>
						<th>Section</th>
						<th>Status</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody id="enrollRows"></tbody>
			</table>
			<p class="enroll-empty" id="enrollEmpty" hidden>No learner is waiting to be enrolled.</p>
			<p class="flash err enroll-error" id="enrollError" hidden></p>
		</div>

		<div class="modal-foot enroll-foot">
			<div class="enroll-tally">
				<strong id="enrollEnrolledCount">0</strong> currently enrolled
				<span class="enroll-sep">&middot;</span>
				<strong id="enrollWaitingCount">0</strong> waiting
			</div>
			<div class="enroll-foot-actions">
				<button type="button" class="btn btn-ghost" id="enrollCancel">Close</button>
				<button type="button" class="btn btn-primary" id="enrollSelected" disabled>Enroll selected</button>
			</div>
		</div>
	</div>
</div>
<script>
// Feeding-day progress. The bar ships filled from the server; this only keeps
// it honest on a page nobody reloads — at midnight the day advances by itself.
(() => {
	const bar = document.getElementById('sbfpProgress');
	if (!bar || !bar.dataset.start) {
		return;
	}

	const duration = Number(bar.dataset.duration) || 120;
	const startMs = Date.parse(bar.dataset.start + 'T00:00:00');
	if (Number.isNaN(startMs)) {
		return;
	}

	const fill = bar.querySelector('.sbfp-progress-fill');
	const dayEl = document.querySelector('[data-cycle-day]');
	const remainingEl = document.querySelector('[data-cycle-remaining]');
	const percentEl = document.querySelector('[data-cycle-percent]');

	const tick = () => {
		const today = new Date();
		today.setHours(0, 0, 0, 0);
		const elapsed = Math.floor((today.getTime() - startMs) / 86400000) + 1;
		const day = Math.max(0, Math.min(duration, elapsed));
		const remaining = Math.max(0, duration - day);
		const percent = ((day / duration) * 100).toFixed(1);

		if (fill) fill.style.width = percent + '%';
		if (percentEl) percentEl.textContent = percent + '%';
		if (dayEl) dayEl.textContent = String(day);
		if (remainingEl) remainingEl.textContent = remaining + (remaining === 1 ? ' day remaining' : ' days remaining');
		bar.setAttribute('aria-valuenow', String(day));
		bar.setAttribute('aria-valuetext', 'Feeding day ' + day + ' of ' + duration);
	};

	tick();
	window.setInterval(tick, 60000);
	document.addEventListener('visibilitychange', () => {
		if (!document.hidden) tick();
	});
})();

// Live panels. The page asks a cheap pulse (a stamp, no data) on a timer and
// pays for the rebuild only when the stamp moves — so a mark recorded at the
// feeding line shows up here without anyone reloading.
(() => {
	const root = document.getElementById('fc-dashboard');
	if (!root) {
		return;
	}

	const panes = {
		cards: document.getElementById('fc-cards'),
		attendance: document.getElementById('fc-attendance'),
		nutrition: document.getElementById('fc-nutrition'),
		risk: document.getElementById('fc-risk'),
		progress: document.getElementById('fc-progress'),
	};
	const updated = document.getElementById('fc-updated');
	// The refresh carries the page's own filters, so a live update re-renders
	// the view on screen rather than replacing it with an unfiltered one.
	const metricsUrl = root.dataset.metricsUrl + window.location.search;
	const pulseUrl = root.dataset.pulseUrl;
	const PULSE_MS = 20000;

	let inFlight = false;

	const setRefreshing = (on) => {
		Object.values(panes).forEach((pane) => {
			if (pane) pane.classList.toggle('is-refreshing', on);
		});
	};

	const refresh = async () => {
		if (inFlight) {
			return;
		}

		inFlight = true;
		setRefreshing(true);
		try {
			const response = await fetch(metricsUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
			if (!response.ok) {
				return;
			}

			const payload = await response.json();
			if (!payload.html) {
				return;
			}

			// The server renders the same Blade partials the first paint used,
			// so the live view can never drift from a reloaded one.
			Object.keys(panes).forEach((key) => {
				if (panes[key] && typeof payload.html[key] === 'string') {
					panes[key].innerHTML = payload.html[key];
				}
			});
			if (updated && payload.generatedAt) {
				updated.textContent = payload.generatedAt;
			}
		} catch (error) {
			// Offline or a dropped request: keep what is on screen and try again
			// on the next pulse.
		} finally {
			inFlight = false;
			setRefreshing(false);
		}
	};

	const pulse = async () => {
		if (document.hidden || inFlight) {
			return;
		}

		try {
			const response = await fetch(pulseUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
			if (!response.ok) {
				return;
			}

			const payload = await response.json();
			if (payload.stamp && payload.stamp !== root.dataset.stamp) {
				root.dataset.stamp = payload.stamp;
				await refresh();
				// The enrolment modal rides the same pulse rather than polling
				// on its own timer: one question asked of the server, two
				// things kept current.
				document.dispatchEvent(new CustomEvent('fc:records-changed'));
			}
		} catch (error) {
			// Ignored — the next pulse retries.
		}
	};

	// Enrolling a learner changes the cards immediately, without waiting for
	// the next pulse to notice.
	document.addEventListener('fc:refresh-request', () => { refresh(); });

	pulse();
	window.setInterval(pulse, PULSE_MS);
	document.addEventListener('visibilitychange', () => {
		if (!document.hidden) pulse();
	});
})();

// Choosing a filter applies it — there is no Apply button to press.
//
// Changing the grade drops the section with it, because the section list the
// server rebuilds for that grade may no longer contain the one selected. Empty
// filters are stripped from the URL so the address stays readable and "no
// filter" has exactly one representation rather than an empty-string one too.
(() => {
	const form = document.getElementById('fcFilters');
	if (!form) {
		return;
	}

	const section = form.querySelector('[name="section"]');

	const apply = () => {
		const params = new URLSearchParams(new FormData(form));
		Array.from(params.keys()).forEach((key) => {
			if (params.get(key) === '') {
				params.delete(key);
			}
		});

		const query = params.toString();
		window.location.href = form.action.split('?')[0] + (query ? '?' + query : '');
	};

	form.addEventListener('submit', (event) => {
		event.preventDefault();
		apply();
	});

	form.querySelectorAll('select').forEach((select) => {
		select.addEventListener('change', () => {
			if (select.name === 'grade' && section) {
				section.value = '';
			}
			apply();
		});
	});
})();

// Enrolment modal. Qualifying is the adviser's measurement; enrolling is the
// coordinator's decision, and this is where it is made.
(() => {
	const openBtn = document.getElementById('enrollBeneficiaryBtn');
	const backdrop = document.getElementById('enrollBackdrop');
	if (!openBtn || !backdrop) {
		return;
	}

	const el = (id) => document.getElementById(id);
	const rowsBody = el('enrollRows');
	const search = el('enrollSearch');
	const gradeSel = el('enrollGrade');
	const sectionSel = el('enrollSection');
	const statusSel = el('enrollStatus');
	const showing = el('enrollShowing');
	const emptyNote = el('enrollEmpty');
	const errorNote = el('enrollError');
	const checkAll = el('enrollCheckAll');
	const enrolledCount = el('enrollEnrolledCount');
	const waitingCount = el('enrollWaitingCount');
	const enrollSelected = el('enrollSelected');
	const subtitle = el('enrollSub');
	const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

	let candidates = [];
	const selected = new Set();
	let open = false;
	let busy = false;

	const esc = (value) => String(value).replace(/[&<>"']/g, (c) => ({
		'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
	}[c]));

	const visible = () => candidates.filter((row) => {
		const term = (search?.value ?? '').trim().toLowerCase();
		return (term === '' || row.name.toLowerCase().includes(term))
			&& (gradeSel.value === '' || row.grade === gradeSel.value)
			&& (sectionSel.value === '' || row.section === sectionSel.value)
			&& (statusSel.value === '' || row.status === statusSel.value);
	});

	const setError = (message) => {
		if (!errorNote) return;
		errorNote.textContent = message ?? '';
		errorNote.hidden = !message;
	};

	const syncFooter = () => {
		if (enrollSelected) {
			enrollSelected.disabled = busy || selected.size === 0;
			enrollSelected.textContent = selected.size > 0
				? 'Enroll selected (' + selected.size + ')'
				: 'Enroll selected';
		}

		const shown = visible();
		if (checkAll) {
			const allChecked = shown.length > 0 && shown.every((row) => selected.has(row.id));
			checkAll.checked = allChecked;
			checkAll.indeterminate = !allChecked && shown.some((row) => selected.has(row.id));
		}
	};

	const render = () => {
		const shown = visible();

		if (showing) {
			showing.textContent = 'Showing ' + shown.length + ' of ' + candidates.length
				+ ' qualified learner' + (candidates.length === 1 ? '' : 's');
		}
		if (emptyNote) {
			emptyNote.hidden = candidates.length !== 0;
		}

		rowsBody.innerHTML = shown.map((row) => `
			<tr data-id="${row.id}">
				<td class="enroll-check"><input type="checkbox" data-select="${row.id}" ${selected.has(row.id) ? 'checked' : ''} aria-label="Select ${esc(row.name)}"></td>
				<td><strong>${esc(row.name)}</strong></td>
				<td>${esc(row.grade)}</td>
				<td>${esc(row.section || '—')}</td>
				<td><span class="badge ${row.badge}">${esc(row.status_short)}</span></td>
				<td><button type="button" class="btn btn-primary btn-sm" data-enroll="${row.id}">Enroll</button></td>
			</tr>`).join('');

		syncFooter();
	};

	const fillOptions = (select, values, keepValue) => {
		const first = select.querySelector('option');
		select.innerHTML = '';
		select.appendChild(first);
		values.forEach((value) => {
			const option = document.createElement('option');
			option.value = value;
			option.textContent = value;
			select.appendChild(option);
		});
		// A filter the coordinator set survives a live refresh unless the value
		// it named has left the list entirely.
		select.value = values.includes(keepValue) ? keepValue : '';
	};

	const load = async () => {
		try {
			const response = await fetch(openBtn.dataset.candidatesUrl, {
				headers: { Accept: 'application/json' },
				credentials: 'same-origin',
			});
			if (!response.ok) {
				setError('Could not load the qualified learners. Try again in a moment.');
				return;
			}

			const payload = await response.json();
			candidates = Array.isArray(payload.rows) ? payload.rows : [];

			// Drop selections for learners who are no longer waiting — someone
			// else may have enrolled them while this modal sat open.
			const ids = new Set(candidates.map((row) => row.id));
			Array.from(selected).forEach((id) => { if (!ids.has(id)) selected.delete(id); });

			fillOptions(gradeSel, payload.grades ?? [], gradeSel.value);
			fillOptions(sectionSel, payload.sections ?? [], sectionSel.value);

			if (enrolledCount) enrolledCount.textContent = payload.enrolled ?? 0;
			if (waitingCount) waitingCount.textContent = payload.waiting ?? 0;
			if (subtitle && payload.weigh_in) {
				subtitle.textContent = 'Learners measured as wasted or severely wasted at the '
					+ payload.weigh_in + ' weigh-in. Enrolling one starts their meals at the next feeding day.';
			}

			setError(null);
			render();
		} catch (error) {
			setError('Could not load the qualified learners. Try again in a moment.');
		}
	};

	const enroll = async (ids) => {
		if (busy || ids.length === 0) {
			return;
		}

		busy = true;
		syncFooter();
		try {
			const response = await fetch(openBtn.dataset.enrollUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-CSRF-TOKEN': csrf,
				},
				credentials: 'same-origin',
				body: JSON.stringify({ record_ids: ids }),
			});

			if (!response.ok) {
				const payload = await response.json().catch(() => ({}));
				setError(payload.message ?? 'Those learners could not be enrolled.');
				return;
			}

			ids.forEach((id) => selected.delete(id));
			await load();
			// The cards behind the modal count enrolled learners, so they are
			// re-read now rather than at the next pulse.
			document.dispatchEvent(new CustomEvent('fc:refresh-request'));
		} catch (error) {
			setError('Those learners could not be enrolled. Check your connection and try again.');
		} finally {
			busy = false;
			syncFooter();
		}
	};

	const setOpen = (state) => {
		open = state;
		backdrop.classList.toggle('open', state);
		backdrop.setAttribute('aria-hidden', state ? 'false' : 'true');
		document.body.style.overflow = state ? 'hidden' : '';
		if (state) {
			setError(null);
			load().then(() => search?.focus());
		}
	};

	openBtn.addEventListener('click', () => setOpen(true));
	[el('enrollClose'), el('enrollCancel')].forEach((btn) => btn?.addEventListener('click', () => setOpen(false)));
	backdrop.addEventListener('click', (event) => { if (event.target === backdrop) setOpen(false); });
	document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && open) setOpen(false); });

	[search, gradeSel, sectionSel, statusSel].forEach((control) => {
		control?.addEventListener('input', render);
		control?.addEventListener('change', render);
	});

	el('enrollClearFilters')?.addEventListener('click', () => {
		if (search) search.value = '';
		[gradeSel, sectionSel, statusSel].forEach((select) => { if (select) select.value = ''; });
		render();
	});

	checkAll?.addEventListener('change', () => {
		visible().forEach((row) => {
			if (checkAll.checked) selected.add(row.id);
			else selected.delete(row.id);
		});
		render();
	});

	rowsBody.addEventListener('click', (event) => {
		const enrollOne = event.target.closest('[data-enroll]');
		if (enrollOne) {
			enroll([Number(enrollOne.dataset.enroll)]);
		}
	});

	rowsBody.addEventListener('change', (event) => {
		const box = event.target.closest('[data-select]');
		if (!box) return;
		const id = Number(box.dataset.select);
		if (box.checked) selected.add(id);
		else selected.delete(id);
		syncFooter();
	});

	enrollSelected?.addEventListener('click', () => enroll(Array.from(selected)));

	// An adviser measuring a learner mid-session moves the dashboard's stamp;
	// while the modal is open, the waiting list follows it.
	document.addEventListener('fc:records-changed', () => { if (open) load(); });
})();

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

	document.querySelectorAll('.asb-link[href]').forEach((link) => {
		link.addEventListener('click', (event) => {
			const href = link.getAttribute('href');
			if (!href || href === '#' || link.classList.contains('active')) {
				return;
			}
			if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
				return;
			}

			event.preventDefault();
			main.classList.remove('page-ready');
			main.classList.add('page-exit');
			// Matches --asb-page-out in role-sidebar.css.
			window.setTimeout(() => {
				window.location.href = href;
			}, 340);
		});
	});
})();
</script>
</body>
</html>
