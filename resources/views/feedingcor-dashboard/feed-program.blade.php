<!DOCTYPE html>
{{--
	Nutritional Health Status — Feeding Programme.

	The School Nurse's (and the Clinic Teacher's) read-only window onto the
	School-Based Feeding Program, the second view inside Nutritional Health
	Status (partials/nurse-nutrition-tabs). The Feeding Coordinator's own tab of
	the old name was retired; this page reads the programme the way their tabs
	do — FeedingBeneficiarySummary for who is a beneficiary and the headline
	cards, FeedingProgramCycle for the day, FeedingAttendanceFlag for who is
	flagged, FeedingNutritionProgress for baseline against endline — so the two
	desks report one programme.

	It is live. The page polls the school's stamp (workspace.pulse — a hash, no
	personal data) and, when another desk writes, re-renders its panels from
	FeedingProgramController::programMetrics, which returns the same partials
	the first paint used. Nothing on it writes.
--}}
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>Nutritional Health Status — Feeding Programme - SIGLA</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="icon" type="image/png" href="{{ asset('images/sigla-mark.png') }}">
	<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
	<script>document.documentElement.classList.add('js');</script>
	<style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/feeding-program.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/nurse-sidebar.css')) !!}</style>
</head>
<body>
@php
	$fpCycleDay = (int) ($cycle['day'] ?? 0);
	$fpCycleDuration = (int) ($cycle['duration'] ?? \App\Support\FeedingProgramCycle::DURATION_DAYS);
@endphp
{{-- Whichever clinic rail the reader belongs to: the nurse's, or the Clinic
     Teacher's own. One module, two views, so the nutrition entry stays lit. --}}
@include('partials.clinic-rail', ['active' => 'nutritional-status'])

<div class="main">
	<header class="topbar">
		<div class="topbar-bc"><span>{{ $roleLabel }}</span><span class="bc-sep">&rsaquo;</span><span>Nutritional Health Status</span></div>

		@include('partials.nurse-learner-search')
		@include('partials.live-clock')
	</header>

	<div class="content" id="fp-page"
		data-pulse-url="{{ route('workspace.pulse') }}"
		data-metrics-url="{{ route($metricsRouteName) }}">
		@include('partials.nurse-nutrition-tabs', ['active' => 'programme'])

		<div class="head-row page-header">
			<div>
				<h1 class="page-title">Nutritional Health Status <span>Feeding Programme</span></h1>
				<p class="page-sub">School-Based Feeding Program &middot; S.Y. {{ $schoolYear }}</p>
			</div>
			<div class="head-actions">
				<form method="GET" action="{{ route($programRouteName) }}" class="fp-grade-form">
					<label for="gradeFilterSelect" class="fp-grade-label">Grade Level</label>
					<select id="gradeFilterSelect" name="grade" class="select fp-grade-select" onchange="this.form.submit()">
						<option value="all" @selected($selectedGrade === 'all')>All Grade Levels</option>
						@foreach ($gradeOptions as $gradeOption)
							<option value="{{ $gradeOption }}" @selected($selectedGrade === $gradeOption)>{{ $gradeOption }}</option>
						@endforeach
					</select>
				</form>
				<button type="button" class="btn btn-secondary" id="printProgramBtn">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
					Print Program Report
				</button>
			</div>
		</div>

		{{-- Paper has no sidebar to say whose programme this is, and no clock
		     to date it. This masthead prints in their place and is invisible on
		     screen. --}}
		<div class="print-masthead" aria-hidden="true">
			<h2>School-Based Feeding Program</h2>
			<p>
				{{ $schoolName }}
				&middot; S.Y. {{ $schoolYear }}
				&middot; Day {{ $fpCycleDay }} of {{ $fpCycleDuration }}
				@if ($selectedGrade !== 'all') &middot; {{ $selectedGrade }} @endif
			</p>
			<p>Printed {{ now(config('app.school_timezone'))->format('F j, Y') }}{{ ($programStats['sessions_held'] ?? 0) > 0 ? ' · '.$programStats['sessions_held'].' feeding sessions recorded' : '' }}</p>
		</div>

		<div class="live-pane" id="fp-alert">
			@include('feedingcor-dashboard.partials.program-alert')
		</div>

		<section class="card fp-cycle live-pane" id="fp-cycle" aria-label="Feeding cycle">
			@include('feedingcor-dashboard.partials.program-cycle')
		</section>

		<section class="kpi-grid{{ $showFlags ? ' cols-5' : '' }} fp-cards live-pane" id="fp-cards" aria-label="Programme figures">
			@include('feedingcor-dashboard.partials.program-cards')
		</section>

		<div class="fp-split">
			<section class="card fp-panel live-pane" id="fp-session">
				@include('feedingcor-dashboard.partials.program-session')
			</section>
			<section class="card fp-panel live-pane" id="fp-nutrition">
				@include('feedingcor-dashboard.partials.program-nutrition')
			</section>
		</div>

		{{-- Who is flagged is shown to the School Nurse alone; the Clinic
		     Teacher's page carries no list and no section for it. --}}
		@if ($showFlags)
			<section class="card fp-table-section" id="atRiskSection">
				<div class="fp-table-head">
					<div>
						<h2 class="card-title">At-Risk Beneficiaries</h2>
						<p class="card-sub">{{ ucfirst($programStats['at_risk_rule'] ?? '') }}</p>
					</div>
					<div class="fp-filters">
						<div class="lg-search fp-search">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
							<input type="search" id="riskSearch" placeholder="Search" autocomplete="off" aria-label="Search the at-risk list by name or section">
						</div>
						<select class="select fp-filter" id="riskGenderFilter" aria-label="Filter the at-risk list by gender">
							<option value="all">Gender</option>
							@foreach (\App\Support\FeedingBeneficiarySummary::SEX_OPTIONS as $sexOption)
								<option value="{{ strtolower($sexOption) }}">{{ $sexOption }}</option>
							@endforeach
						</select>
					</div>
				</div>
				<div class="table-card fp-table-card">
					<div class="table-scroll">
						<table id="riskTable">
							<thead>
								<tr>
									<th>Student</th>
									<th>Attendance</th>
									<th>Flagged On</th>
									<th>Nutritional Status</th>
								</tr>
							</thead>
							<tbody class="live-pane" id="fp-risk">
								@include('feedingcor-dashboard.partials.program-risk-rows')
							</tbody>
						</table>
					</div>
				</div>
			</section>
		@endif

		<section class="card fp-table-section" id="attendanceRosterSection">
			<div class="fp-table-head">
				<div>
					<h2 class="card-title">Feeding Program Beneficiaries</h2>
					<p class="card-sub"><span class="tnum" id="rosterCount">{{ ($attendanceRoster ?? collect())->count() }}</span> {{ \Illuminate\Support\Str::plural('beneficiary', ($attendanceRoster ?? collect())->count()) }}{{ $selectedGrade !== 'all' ? ' in '.$selectedGrade : '' }}</p>
				</div>
				<div class="fp-filters">
					<div class="lg-search fp-search">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
						<input type="search" id="rosterSearch" placeholder="Search" autocomplete="off" aria-label="Search beneficiaries by name or section">
					</div>
					<select class="select fp-filter" id="rosterStatusFilter" aria-label="Filter beneficiaries by baseline status">
						<option value="all">Baseline Status</option>
						<option value="severely wasted">Severely Wasted</option>
						<option value="wasted">Wasted</option>
					</select>
					<select class="select fp-filter" id="rosterGenderFilter" aria-label="Filter beneficiaries by gender">
						<option value="all">Gender</option>
						@foreach (\App\Support\FeedingBeneficiarySummary::SEX_OPTIONS as $sexOption)
							<option value="{{ strtolower($sexOption) }}">{{ $sexOption }}</option>
						@endforeach
					</select>
				</div>
			</div>
			<div class="table-card fp-table-card">
				<div class="table-scroll">
					<table id="rosterTable" class="fp-roster">
						<thead>
							<tr>
								<th>Student</th>
								<th>Baseline Status</th>
								<th class="num">Baseline</th>
								<th class="num">Current</th>
								<th class="num">Weight Change</th>
								<th class="num">Present</th>
								<th class="num">Absent</th>
								<th class="num">Attendance</th>
								<th>Weight Status</th>
							</tr>
						</thead>
						<tbody class="live-pane" id="fp-roster">
							@include('feedingcor-dashboard.partials.program-roster-rows')
						</tbody>
					</table>
				</div>
			</div>
		</section>

		<p class="fp-updated" aria-live="polite">Updated <span id="fpUpdated">{{ $generatedAt }}</span></p>
	</div>
</div>

<script>
(() => {
	const page = document.getElementById('fp-page');
	if (!page) return;

	document.getElementById('printProgramBtn')?.addEventListener('click', () => window.print());

	// ── The two lists' own filters ─────────────────────────────────────
	// Applied in the browser over the rows on the page (names are encrypted,
	// and the server already scoped the roll to the grade on screen). Read
	// again after every live refresh, so a redraw keeps what was chosen.
	const applyList = (tbodyId, prefix, controls, noMatchId) => {
		const tbody = document.getElementById(tbodyId);
		if (!tbody) return;
		const term = (controls.search?.value || '').trim().toLowerCase();
		const gender = controls.gender?.value || 'all';
		const status = controls.status?.value || 'all';
		const rows = Array.from(tbody.querySelectorAll(`tr[data-${prefix}-search]`));
		let shown = 0;
		rows.forEach((row) => {
			const visible = (term === '' || row.getAttribute(`data-${prefix}-search`).includes(term))
				&& (gender === 'all' || row.getAttribute(`data-${prefix}-gender`) === gender)
				&& (status === 'all' || row.getAttribute(`data-${prefix}-status`) === status);
			row.style.display = visible ? '' : 'none';
			if (visible) shown++;
		});
		const noMatch = document.getElementById(noMatchId);
		if (noMatch) noMatch.style.display = rows.length > 0 && shown === 0 ? '' : 'none';
	};

	const riskControls = {
		search: document.getElementById('riskSearch'),
		gender: document.getElementById('riskGenderFilter'),
	};
	const rosterControls = {
		search: document.getElementById('rosterSearch'),
		gender: document.getElementById('rosterGenderFilter'),
		status: document.getElementById('rosterStatusFilter'),
	};
	const applyRisk = () => applyList('fp-risk', 'risk', riskControls, 'riskNoMatchRow');
	const applyRoster = () => applyList('fp-roster', 'roster', rosterControls, 'rosterNoMatchRow');

	Object.values(riskControls).forEach((control) => control?.addEventListener(control.tagName === 'INPUT' ? 'input' : 'change', applyRisk));
	Object.values(rosterControls).forEach((control) => control?.addEventListener(control.tagName === 'INPUT' ? 'input' : 'change', applyRoster));

	// ── Live refresh ───────────────────────────────────────────────────
	// The stamp carries no personal data; the panels are re-read only when it
	// moves, and the page's own query string travels with the request so a
	// grade-filtered page redraws as the same grade.
	const pulseUrl = page.dataset.pulseUrl;
	const metricsUrl = page.dataset.metricsUrl;
	const panes = {
		alert: document.getElementById('fp-alert'),
		cycle: document.getElementById('fp-cycle'),
		cards: document.getElementById('fp-cards'),
		session: document.getElementById('fp-session'),
		nutrition: document.getElementById('fp-nutrition'),
		risk: document.getElementById('fp-risk'),
		roster: document.getElementById('fp-roster'),
	};
	const updated = document.getElementById('fpUpdated');
	const rosterCount = document.getElementById('rosterCount');
	let stamp = null;
	let busy = false;

	const refresh = async () => {
		Object.values(panes).forEach((node) => node?.classList.add('is-refreshing'));
		try {
			const response = await fetch(metricsUrl + window.location.search, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
			if (!response.ok) return;
			const payload = await response.json();
			Object.entries(panes).forEach(([key, node]) => {
				if (node && payload.html && typeof payload.html[key] === 'string') node.innerHTML = payload.html[key];
			});
			if (updated && payload.generatedAt) updated.textContent = payload.generatedAt;
			if (rosterCount && panes.roster) rosterCount.textContent = String(panes.roster.querySelectorAll('tr[data-roster-search]').length);
			applyRisk();
			applyRoster();
		} catch (error) {
			/* A missed refresh is caught by the next stamp. */
		} finally {
			Object.values(panes).forEach((node) => node?.classList.remove('is-refreshing'));
		}
	};

	const poll = async () => {
		if (busy || document.hidden || !pulseUrl || !metricsUrl) return;
		busy = true;
		try {
			const response = await fetch(pulseUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
			if (!response.ok) return;
			const payload = await response.json();
			if (stamp === null) {
				stamp = payload.stamp;
				return;
			}
			if (payload.stamp !== stamp) {
				stamp = payload.stamp;
				await refresh();
			}
		} catch (error) {
			/* Offline for a tick; the next one catches up. */
		} finally {
			busy = false;
		}
	};

	poll();
	window.setInterval(poll, 20000);
	document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
})();
</script>
@include('partials.chart-tooltip')
@include('partials.nurse-page-transition')
@include('partials.sidebar-hover-pin')
</body>
</html>
