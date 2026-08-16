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
    <style>{!! file_get_contents(resource_path('css/feeding-enroll-modal.css')) !!}</style>
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
					<button type="button" class="btn btn-secondary" id="enrollBeneficiaryBtn" data-enroll-open>
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
			{{-- Sex is scope like grade and section, not a narrowing filter: it
			     changes who the figures describe. It reads the encrypted
			     student_details.gender through FeedingBeneficiarySummary, so
			     every coordinator tab agrees on the answer. --}}
			<div class="fc-filter">
				<label class="field-label" for="filterSex">Sex</label>
				<select class="select" name="sex" id="filterSex">
					<option value="">All</option>
					@foreach ($filterOptions['sexes'] as $sexOption)
						<option value="{{ $sexOption }}" @selected($filters['sex'] === $sexOption)>{{ $sexOption }}</option>
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
				@if ($filters['grade'] !== '' || $filters['section'] !== '' || $filters['sex'] !== '' || $filters['status'] !== '' || $filters['attendance'] !== '' || $filters['school_year'] !== \App\Models\StudentHealthRecord::currentSchoolYear())
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

@include('partials.feeding-enroll-modal')
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
