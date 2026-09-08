<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>Attendance - Feeding Coordinator - SIGLA</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
	<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
	<script>document.documentElement.classList.add('js');</script>
	<style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/feeding-attendance.css')) !!}</style>
	{{-- The Record Attendance dialog is one dialog with one sheet, shared with
	     the Dashboard, which opens the same one. --}}
	<style>{!! file_get_contents(resource_path('css/feeding-record-modal.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/feeding-attendance-confirm.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/role-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.feedingcor-sidebar', ['active' => 'attendance'])

@php
	// En dash in the school year: it is a range, not a hyphenated word.
	$faYear = str_replace('-', '&ndash;', e($schoolYear));
	$cycleShare = $programDuration > 0 ? (int) round(($programDay / $programDuration) * 100) : 0;

	$views = [
		'sheet' => 'Attendance Sheet',
		'history' => 'Attendance History',
		'beneficiary' => 'By Beneficiary',
		'calendar' => 'Calendar',
	];

	// A view keeps every filter already chosen and changes only the view, so
	// switching never silently drops the grade the coordinator was reading.
	$viewUrl = fn (string $key): string => $pageUrl(['view' => $key]);
@endphp

<div class="main">
	<header class="topbar">
		<div class="topbar-bc"><span>Dashboard</span><span class="bc-sep">&rsaquo;</span><span>Attendance</span></div>
		@include('partials.live-clock')
	</header>

	<div class="content" id="fa-page"
		data-metrics-url="{{ route('dashboard.feedingcor-attendance.metrics') }}"
		data-pulse-url="{{ route('dashboard.feedingcor.metrics.pulse') }}">

		@if (session('success'))
			<div class="flash ok">{{ session('success') }}</div>
		@endif
		@if (session('error'))
			<div class="flash err">{{ session('error') }}</div>
		@endif
		@if ($errors->any())
			<div class="flash err">{{ $errors->first() }}</div>
		@endif

		{{-- Same masthead as the Beneficiaries tab: the subject upright, the
		     section italic emerald, the year beside it. --}}
		<div class="page-header sbfp-header">
			<div class="sbfp-headline">
				<div class="sbfp-title-row">
					<h1 class="page-title">SBFP <span>Feeding Attendance</span></h1>
					<span class="sbfp-year">S.Y. {!! $faYear !!}</span>
				</div>
				<p class="sbfp-program">Active Program: <strong>School-Based Feeding Program</strong></p>
				<p class="fa-daymeta">
					<span class="fa-day">Feeding Day {{ $programDay }} of {{ $programDuration }}</span>
					<span class="fa-sep">&middot;</span>
					<span>Today: {{ $todayLabel }}</span>
				</p>
			</div>

			<div class="sbfp-actions">
				{{-- Recording is offered on the Attendance Sheet, beside the
				     session it belongs to, and nowhere else on this page. A
				     second button up here sat next to Export and Print — two
				     actions that read the session — and read as a third way of
				     doing the same kind of thing, while being the only one that
				     writes. The Dashboard opens the same dialog for today.

				     Attendance History is a view on the rail below, so the
				     header does not carry a second way in either. --}}
				<a class="btn btn-secondary" href="{{ route('dashboard.feedingcor-attendance.export', request()->query()) }}">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
					Export Attendance Sheet
				</a>
				<button type="button" class="btn btn-secondary" id="faPrint">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
					Print Attendance Sheet
				</button>
			</div>
		</div>

		{{-- Paper carries no rail and no clock, so the masthead prints in their
		     place and is invisible on screen. --}}
		<div class="print-masthead" aria-hidden="true">
			<h2>SBFP Feeding Attendance</h2>
			<p>{{ $schoolName }} &middot; S.Y. {{ $schoolYear }} &middot; Feeding Day {{ $programDay }} of {{ $programDuration }}</p>
			<p>Session: {{ $selectedDateLabel }} &middot; Printed {{ now()->format('F j, Y') }}</p>
		</div>

		<section class="kpi-grid cols-5 live-pane" id="fa-cards">
			@include('feedingcor-dashboard.partials.attendance-cards')
		</section>

		<div class="live-pane" id="fa-notices">
			@include('feedingcor-dashboard.partials.attendance-notices')
		</div>

		<nav class="seg-tabs" aria-label="Attendance views">
			@foreach ($views as $key => $label)
				<a class="seg-tab {{ $view === $key ? 'is-active' : '' }}" href="{{ $viewUrl($key) }}"
					@if ($view === $key) aria-current="page" @endif>
					<span class="seg-tab-label">{{ $label }}</span>
				</a>
			@endforeach
		</nav>

		{{-- ── One toolbar: the session being read, and what narrows it.
		     The date and the filters were two full-width cards for six
		     controls; they are one row now, and every control applies itself
		     on change — an Apply button nobody presses is a filter that
		     silently does nothing. ── --}}
		<form method="GET" class="card fa-toolbar" id="faToolbar">
			<input type="hidden" name="view" value="{{ $view }}">

			@if ($view === 'sheet')
				{{-- Previous and Next step to the neighbouring recorded session
				     where there is one, and the picker refuses a date outside
				     the running programme — a mistyped year would open a
				     feeding day the programme never had. --}}
				<div class="fa-dategroup">
					<a class="fa-step {{ $previousDate === null ? 'is-disabled' : '' }}"
						@if ($previousDate !== null) href="{{ $pageUrl(['view' => $view, 'date' => $previousDate]) }}" @endif
						@if ($previousDate === null) aria-disabled="true" @endif
						aria-label="Previous feeding day">&lsaquo;</a>

					<input type="date" class="input fa-dateinput" id="faDate" name="date"
						value="{{ $selectedDate }}"
						aria-label="Feeding date"
						@if ($window['start'] !== null) min="{{ $window['start'] }}" @endif
						max="{{ $window['end'] }}">

					<a class="fa-step {{ $nextDate === null ? 'is-disabled' : '' }}"
						@if ($nextDate !== null) href="{{ $pageUrl(['view' => $view, 'date' => $nextDate]) }}" @endif
						@if ($nextDate === null) aria-disabled="true" @endif
						aria-label="Next feeding day">&rsaquo;</a>
				</div>
			@else
				{{-- The other views carry the session forward without showing
				     it, so returning to the sheet lands on the same day. --}}
				<input type="hidden" name="date" value="{{ $selectedDate }}">
			@endif

			{{-- By Beneficiary is a roll, not a session: a coordinator opens it
			     to find one learner among ninety, so the control that leads it
			     is a search rather than a date picker. The day it reads is
			     still named on the Session column's own heading, and it is
			     chosen where a session is chosen — on the sheet.

			     It is a server-side search because names are encrypted at rest
			     and because this pane is re-rendered by the live refresh: a
			     filter held only in the browser would be wiped every time a
			     mark landed elsewhere in the school. --}}
			@if ($view === 'beneficiary')
				<div class="fa-filter fa-queryfilter">
					<label class="field-label" for="faQuery">Search</label>
					<div class="lg-search">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
						<input type="search" class="input" id="faQuery" name="q"
							value="{{ $filters['q'] }}" maxlength="60"
							placeholder="Name, grade or section" autocomplete="off"
							aria-label="Search beneficiaries by name, grade or section">
					</div>
				</div>
			@else
				<input type="hidden" name="q" value="{{ $filters['q'] }}">
			@endif

			{{-- Grade and section scope every view; attendance status narrows
			     the list being read. All three read encrypted or derived
			     values, so all three are applied in PHP, never in SQL. --}}
			<div class="fa-filter">
				<label class="field-label" for="faGrade">Grade</label>
				<select class="select" name="grade" id="faGrade">
					<option value="">All</option>
					@foreach ($filterOptions['grades'] as $grade)
						<option value="{{ $grade }}" @selected($filters['grade'] === $grade)>{{ $grade }}</option>
					@endforeach
				</select>
			</div>
			<div class="fa-filter">
				<label class="field-label" for="faSection">Section</label>
				<select class="select" name="section" id="faSection">
					<option value="">All</option>
					@foreach ($filterOptions['sections'] as $section)
						<option value="{{ $section }}" @selected($filters['section'] === $section)>{{ $section }}</option>
					@endforeach
				</select>
			</div>
			<div class="fa-filter">
				<label class="field-label" for="faSex">Gender</label>
				<select class="select" name="sex" id="faSex">
					<option value="">All</option>
					@foreach (\App\Support\FeedingBeneficiarySummary::SEX_OPTIONS as $sexOption)
						<option value="{{ $sexOption }}" @selected($filters['sex'] === $sexOption)>{{ $sexOption }}</option>
					@endforeach
				</select>
			</div>
			{{-- Three answers, because a confirmed mark has three: everyone,
			     the ones who came, the ones who did not. A learner nobody
			     wrote down and a scanned mark nobody has read are printed on
			     their rows but are not answers to "who came today". --}}
			<div class="fa-filter">
				<label class="field-label" for="faStatus">Attendance</label>
				<select class="select" name="status" id="faStatus">
					<option value="">All</option>
					<option value="present" @selected($filters['status'] === 'present')>Present</option>
					<option value="absent" @selected($filters['status'] === 'absent')>Absent</option>
					<option value="excused" @selected($filters['status'] === 'excused')>Excused</option>
				</select>
			</div>

			@if ($view === 'beneficiary')
				{{-- A verdict across the programme is a different question from a
				     mark on one day, so it is a different control — and it only
				     appears on the roll it applies to. The at-risk notice above
				     lands here with it already set. --}}
				<div class="fa-filter">
					<label class="field-label" for="faStanding">Standing</label>
					<select class="select" name="standing" id="faStanding">
						<option value="">All</option>
						<option value="at_risk" @selected($filters['standing'] === 'at_risk')>At risk</option>
						<option value="early_monitoring" @selected($filters['standing'] === 'early_monitoring')>Early monitoring</option>
					</select>
				</div>
			@else
				<input type="hidden" name="standing" value="{{ $filters['standing'] }}">
			@endif

			{{-- No-JS fallback: without it the selects would be unreachable
			     controls on a page that never reloads. --}}
			<noscript><button type="submit" class="btn btn-secondary">Apply</button></noscript>
		</form>

		@if ($view === 'sheet')
			{{-- ── The sheet is a record, not a form. ──
			     It reports the marks a coordinator recorded and offers no control
			     over them: no radio, no remark field, no bulk mark, no save bar.
			     Recording happens once, in the dialog, and a mistake or a learner
			     the session missed is put right on that learner's own beneficiary
			     record where the change is attributed and audited. --}}
			<div id="faSheet">
				<div class="card fa-bulkbar">
					<div class="lg-search fa-search">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
						<input type="search" id="faSearch" placeholder="Search name or section" autocomplete="off" aria-label="Search beneficiaries by name or section">
					</div>
					<div class="fa-sheet-standing">
						@if ($sessionLocked)
							<span class="badge badge-normal has-glyph"><span class="fa-glyph">✓</span>Recorded</span>
						@elseif (! $isFeedingDay)
							<span class="badge badge-neutral">No session &mdash; weekend</span>
						@else
							<span class="badge badge-neutral">Not yet recorded</span>
						@endif
						@if ($canRecord)
							<button type="button" class="btn btn-primary" data-record-open>Record Attendance</button>
						@endif
					</div>
				</div>

				@if ($sessionLocked)
					<div class="alert-bar is-info fa-lockbar">
						<div class="alert-body">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
							<div>
								<strong>Attendance for {{ $selectedDateLabel }} has been recorded</strong>
								<span>This session is read-only. Correct a mark on the learner&rsquo;s beneficiary record.</span>
							</div>
						</div>
					</div>
				@elseif (! $isFeedingDay)
					<div class="alert-bar is-info fa-lockbar">
						<div class="alert-body">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>
							<div>
								<strong>{{ $selectedDateLabel }} is a {{ \Carbon\Carbon::parse($selectedDate)->format('l') }}</strong>
								<span>There are no feeding sessions on Saturdays or Sundays.</span>
							</div>
						</div>
					</div>
				@endif

				<div class="table-card">
					<div class="table-scroll">
						<table class="fa-table fa-sheet-table" id="faTable">
							<thead>
								<tr>
									<th class="fa-idx">#</th>
									<th>Student</th>
									<th>Grade</th>
									<th>Section</th>
									<th class="fa-mark-col">Status</th>
									<th>Remarks</th>
								</tr>
							</thead>
							<tbody>
								@forelse ($rows as $index => $row)
									<tr data-search="{{ strtolower(trim($row['name'].' '.$row['grade'].' '.$row['section'])) }}">
										<td class="fa-idx">{{ $index + 1 }}</td>
										<td class="fa-name"><strong>{{ $row['name'] }}</strong></td>
										<td>{{ $row['grade_number'] !== '' ? $row['grade_number'] : '—' }}</td>
										<td>{{ $row['section'] }}</td>
										<td class="fa-mark-col">
											@if ($row['status'] === 'present' || $row['status'] === 'absent')
												<span class="badge {{ $row['status'] === 'present' ? 'badge-normal' : 'badge-critical' }} has-glyph">
													<span class="fa-glyph">{{ $row['status'] === 'present' ? '✓' : '✕' }}</span>{{ ucfirst($row['status']) }}
												</span>
											@elseif ($row['status'] === 'excused')
												{{-- Away, with a reason the school accepted. Still an
												     absence on the sheet, and never counted toward the
												     at-risk flag. --}}
												<span class="badge badge-monitor has-glyph"><span class="fa-glyph">E</span>Excused</span>
											@elseif ($row['status'] === 'unconfirmed')
												{{-- A scanned mark no human has read. Neither present nor
												     absent until somebody decides it, so it is neither
												     here. --}}
												<span class="badge badge-monitor">Unconfirmed</span>
											@else
												{{-- Never an absence: nobody wrote this learner down. --}}
												<span class="fa-unmarked">Not recorded</span>
											@endif
										</td>
										<td><span class="fa-remark-read">{{ $row['remarks'] !== '' ? $row['remarks'] : '—' }}</span></td>
									</tr>
								@empty
									<tr><td colspan="6" class="table-empty">
										{{ $beneficiaryCount === 0 ? 'No beneficiaries are enrolled for this school year.' : 'No beneficiary matches these filters.' }}
									</td></tr>
								@endforelse
								<tr id="faNoMatch" style="display:none;"><td colspan="6" class="table-empty">No learner matches this search.</td></tr>
							</tbody>
						</table>
					</div>
				</div>

				@if ($rows->isNotEmpty())
					{{-- What this session came to, not what is about to be saved. --}}
					<div class="card fa-savebar is-readonly">
						<div class="fa-tally">
							<span class="fa-total">{{ $beneficiaryCount }} {{ \Illuminate\Support\Str::plural('beneficiary', $beneficiaryCount) }}</span>
							<span class="badge badge-normal">Present <span data-tally="present">{{ $tally['present'] }}</span></span>
							<span class="badge badge-critical">Absent <span data-tally="absent">{{ $tally['absent'] }}</span></span>
							<span class="badge badge-monitor">Excused <span data-tally="excused">{{ $tally['excused'] }}</span></span>
							<span class="badge badge-neutral">Not recorded <span data-tally="none">{{ $tally['unmarked'] }}</span></span>
						</div>
					</div>
				@endif
			</div>
		@elseif ($view === 'history')
			<div class="live-pane" id="fa-history">
				@include('feedingcor-dashboard.partials.attendance-history')
			</div>
		@elseif ($view === 'beneficiary')
			<div class="live-pane" id="fa-beneficiary">
				@include('feedingcor-dashboard.partials.attendance-by-beneficiary')
			</div>
		@else
			<div class="live-pane" id="fa-calendar">
				@include('feedingcor-dashboard.partials.attendance-calendar')
			</div>
		@endif
	</div>
</div>

{{-- The dialog belongs to the sheet: it lists the whole roll unfiltered, and
     the other views are readings of their own. --}}
@if ($view === 'sheet' && $canRecord)
	@include('feedingcor-dashboard.partials.attendance-record-modal')
	{{-- A recorded session cannot be reopened, so the marks are read back and
	     confirmed before they are posted. --}}
	@include('feedingcor-dashboard.partials.attendance-confirm-modal', [
		'confirmSessionLabel' => $selectedDateLabel,
	])
@endif

{{-- The roll's own dialog: one learner's sessions, read from the row that
     opened it. It belongs to By Beneficiary and is drawn nowhere else. --}}
@if ($view === 'beneficiary')
	@include('feedingcor-dashboard.partials.attendance-learner-modal')
@endif

<script>
(() => {
	const page = document.getElementById('fa-page');
	if (!page) return;

	document.getElementById('faPrint')?.addEventListener('click', () => window.print());

	// A notice's action jumps to the sheet for the session it is talking about.
	page.addEventListener('click', (event) => {
		const opener = event.target.closest('[data-open-sheet]');
		if (!opener) return;
		const url = new URL(window.location.href);
		url.searchParams.set('view', 'sheet');
		window.location.href = url.toString();
	});

	// Every control in the toolbar applies itself. The date picker's own
	// min/max already refuse a date outside the running programme; the server
	// re-checks on save, since a bound in the markup is a convenience, never a
	// guarantee.
	document.querySelectorAll('#faToolbar select, #faToolbar input[type="date"]').forEach((control) => {
		control.addEventListener('change', () => {
			if (control.type === 'date' && !control.value) return;
			control.form.requestSubmit ? control.form.requestSubmit() : control.form.submit();
		});
	});

	// The roll's search applies itself too, a beat after the typing stops:
	// the term is answered by the server (names are encrypted, so only it can
	// match them), and asking on every keystroke would ask ten questions to
	// get one answer. The caret comes back where it was, so the reload does
	// not interrupt a coordinator mid-word.
	const query = document.getElementById('faQuery');
	if (query) {
		let timer = null;
		query.addEventListener('input', () => {
			window.clearTimeout(timer);
			timer = window.setTimeout(() => {
				query.form.requestSubmit ? query.form.requestSubmit() : query.form.submit();
			}, 350);
		});
		query.addEventListener('keydown', (event) => {
			if (event.key !== 'Enter') return;
			event.preventDefault();
			window.clearTimeout(timer);
			query.form.requestSubmit ? query.form.requestSubmit() : query.form.submit();
		});
		if (query.value !== '') {
			query.focus();
			query.setSelectionRange(query.value.length, query.value.length);
		}
	}

	// ── The sheet: a record, so the only thing to do to it is search it. ──
	const sheet = document.getElementById('faSheet');
	if (sheet) {
		const rows = Array.from(sheet.querySelectorAll('tbody tr[data-search]'));
		const search = document.getElementById('faSearch');
		const noMatch = document.getElementById('faNoMatch');

		const renumber = () => {
			let n = 0;
			rows.forEach((row) => {
				const cell = row.querySelector('.fa-idx');
				if (!cell) return;
				cell.textContent = row.style.display === 'none' ? '' : String(++n);
			});
		};

		search?.addEventListener('input', () => {
			const term = search.value.trim().toLowerCase();
			let shown = 0;
			rows.forEach((row) => {
				const hit = term === '' || row.dataset.search.includes(term);
				row.style.display = hit ? '' : 'none';
				if (hit) shown++;
			});
			if (noMatch) noMatch.style.display = shown === 0 && rows.length > 0 ? '' : 'none';
			renumber();
		});
	}

	// ── Live refresh ───────────────────────────────────────────────────
	// The page polls a stamp (no personal data) and re-reads the panels only
	// when it moves, so a mark recorded at the feeding line by someone else
	// lands here without a reload.
	//
	// The sheet is never replaced: it holds marks nobody has saved yet, and
	// overwriting a coordinator's work in progress would be worse than being
	// a few seconds stale.
	const metricsUrl = page.dataset.metricsUrl;
	const pulseUrl = page.dataset.pulseUrl;
	if (!metricsUrl || !pulseUrl) return;

	const panes = {
		cards: document.getElementById('fa-cards'),
		notices: document.getElementById('fa-notices'),
		history: document.getElementById('fa-history'),
		beneficiary: document.getElementById('fa-beneficiary'),
		calendar: document.getElementById('fa-calendar'),
	};

	const query = window.location.search;
	let stamp = null;
	let busy = false;

	const refresh = async () => {
		try {
			const response = await fetch(metricsUrl + query, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
			if (!response.ok) return;
			const payload = await response.json();
			Object.entries(panes).forEach(([key, node]) => {
				if (node && payload.html?.[key] !== undefined) node.innerHTML = payload.html[key];
			});
		} catch (e) { /* a missed refresh is not an error worth shouting about */ }
	};

	const poll = async () => {
		if (busy || document.hidden) return;
		busy = true;
		try {
			const response = await fetch(pulseUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
			if (!response.ok) return;
			const payload = await response.json();
			if (stamp === null) { stamp = payload.stamp; return; }
			if (payload.stamp !== stamp) {
				stamp = payload.stamp;
				await refresh();
			}
		} catch (e) { /* offline for a tick; the next one will catch up */ } finally {
			busy = false;
		}
	};

	poll();
	window.setInterval(poll, 20000);
	document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
})();
</script>
@include('partials.role-page-transition')
</body>
</html>
