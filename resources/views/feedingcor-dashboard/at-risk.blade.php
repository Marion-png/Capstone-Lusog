<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>At-Risk Students - Feeding Coordinator - SIGLA</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
	<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
	<script>document.documentElement.classList.add('js');</script>
	<style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/feeding-at-risk.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/role-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.feedingcor-sidebar', ['active' => 'at-risk'])

@php
	// En dash in the school year: it is a range, not a hyphenated word.
	$arYear = str_replace('-', '&ndash;', e($schoolYear));
@endphp

<div class="main">
	<header class="topbar">
		<div class="topbar-bc"><span>Dashboard</span><span class="bc-sep">&rsaquo;</span><span>At-Risk Students</span></div>
		@include('partials.live-clock')
	</header>

	<div class="content" id="ar-page"
		data-metrics-url="{{ route('dashboard.feedingcor-at-risk.metrics') }}"
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

		{{-- The same masthead as the Attendance and Beneficiaries tabs: the
		     subject upright, the section italic emerald, the year beside it. --}}
		<div class="page-header sbfp-header">
			<div class="sbfp-headline">
				<div class="sbfp-title-row">
					<h1 class="page-title">SBFP <span>At-Risk Students</span></h1>
					<span class="sbfp-year">S.Y. {!! $arYear !!}</span>
				</div>
				<p class="sbfp-program">Active Program: <strong>School-Based Feeding Program</strong></p>
				<p class="ar-daymeta">
					<span class="ar-day">Feeding Day {{ $programDay }} of {{ $programDuration }}</span>
					<span class="ar-sep">&middot;</span>
					<span>{{ $daysRemaining }} days remaining</span>
					<span class="ar-sep">&middot;</span>
					<span>Today: {{ $todayLabel }}</span>
				</p>
			</div>

			{{-- The coordinator's own acts. Enrolment changes are deliberately
			     absent: being below the attendance threshold is not a reason to
			     drop a learner from the programme, and any such change follows
			     the enrolment workflow on the Beneficiaries tab. --}}
			<div class="sbfp-actions">
				@if ($followUpsAvailable && $rows->isNotEmpty())
					<button type="button" class="btn btn-primary" data-followup-open>
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M9 15h6"/><path d="M12 12v6"/></svg>
						Record Follow-Up
					</button>
				@endif
				<a class="btn btn-secondary" href="{{ route('dashboard.feedingcor-at-risk.export', request()->query()) }}">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
					Export At-Risk List
				</a>
				<button type="button" class="btn btn-secondary" id="arPrint">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
					Print At-Risk List
				</button>
			</div>
		</div>

		{{-- Paper carries no rail and no clock, so the masthead prints in their
		     place and is invisible on screen. --}}
		<div class="print-masthead" aria-hidden="true">
			<h2>At-Risk Feeding Beneficiaries</h2>
			<p>{{ $schoolName }} &middot; S.Y. {{ $schoolYear }} &middot; Feeding Day {{ $programDay }} of {{ $programDuration }}</p>
			<p>At-risk threshold: {{ $cards['threshold_label'] }}% &middot; Printed {{ now()->format('F j, Y') }}</p>
		</div>

		<section class="kpi-grid live-pane" id="ar-cards">
			@include('feedingcor-dashboard.partials.at-risk-cards')
		</section>

		@if ($cards['critical'] > 0)
			<div class="alert-bar is-critical ar-alert">
				<div class="alert-body">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
					<div>
						<strong>{{ $cards['critical'] }} {{ \Illuminate\Support\Str::plural('beneficiary', $cards['critical']) }} in critical standing</strong>
						<span>Flagged by the school&rsquo;s rule: {{ $cards['rule'] }}.</span>
					</div>
				</div>
			</div>
		@endif

		{{-- ── Filters ────────────────────────────────────────────────────
		     Grade and Section are scope: they move the cards and the list
		     together. Risk, attendance, days absent and follow-up status narrow
		     the list alone, so the cards keep reporting the programme rather
		     than the last select touched. Every control applies itself. ── --}}
		<form method="GET" class="card ar-toolbar" id="arToolbar">
			<div class="ar-filter">
				<label class="field-label" for="arGrade">Grade</label>
				<select class="select" name="grade" id="arGrade">
					<option value="">All</option>
					@foreach ($filterOptions['grades'] as $grade)
						<option value="{{ $grade }}" @selected($filters['grade'] === $grade)>{{ $grade }}</option>
					@endforeach
				</select>
			</div>
			<div class="ar-filter">
				<label class="field-label" for="arSection">Section</label>
				<select class="select" name="section" id="arSection">
					<option value="">All</option>
					@foreach ($filterOptions['sections'] as $section)
						<option value="{{ $section }}" @selected($filters['section'] === $section)>{{ $section }}</option>
					@endforeach
				</select>
			</div>
			<div class="ar-filter">
				<label class="field-label" for="arRisk">Risk Level</label>
				<select class="select" name="risk" id="arRisk">
					<option value="">All</option>
					<option value="critical" @selected($filters['risk'] === 'critical')>Critical</option>
					<option value="at_risk" @selected($filters['risk'] === 'at_risk')>At Risk</option>
					<option value="watch" @selected($filters['risk'] === 'watch')>Watch</option>
				</select>
			</div>
			<div class="ar-filter">
				<label class="field-label" for="arAttendance">Attendance</label>
				<select class="select" name="attendance" id="arAttendance">
					<option value="">All</option>
					<option value="below_50" @selected($filters['attendance'] === 'below_50')>Below 50%</option>
					<option value="50_69" @selected($filters['attendance'] === '50_69')>50% &ndash; 69%</option>
					<option value="70_79" @selected($filters['attendance'] === '70_79')>70% &ndash; 79%</option>
					<option value="80_plus" @selected($filters['attendance'] === '80_plus')>80% and above</option>
					<option value="no_sessions" @selected($filters['attendance'] === 'no_sessions')>No confirmed session</option>
				</select>
			</div>
			<div class="ar-filter">
				<label class="field-label" for="arAbsences">Days Absent</label>
				<select class="select" name="absences" id="arAbsences">
					<option value="">All</option>
					<option value="1_2" @selected($filters['absences'] === '1_2')>1 &ndash; 2</option>
					<option value="3_5" @selected($filters['absences'] === '3_5')>3 &ndash; 5</option>
					<option value="6_plus" @selected($filters['absences'] === '6_plus')>6 or more</option>
				</select>
			</div>
			<div class="ar-filter">
				<label class="field-label" for="arFollowUp">Follow-Up</label>
				<select class="select" name="follow_up" id="arFollowUp">
					<option value="">All</option>
					<option value="none" @selected($filters['follow_up'] === 'none')>No follow-up</option>
					@foreach ($statusOptions as $value => $label)
						<option value="{{ $value }}" @selected($filters['follow_up'] === $value)>{{ $label }}</option>
					@endforeach
				</select>
			</div>

			{{-- No-JS fallback: without it the selects would be unreachable
			     controls on a page that never reloads. --}}
			<noscript><button type="submit" class="btn btn-secondary">Apply</button></noscript>
		</form>

		@include('feedingcor-dashboard.partials.at-risk-list')
	</div>
</div>

{{-- ── Beneficiary details ────────────────────────────────────────────────
     One learner's record, opened from their row. The panels come from that
     row's own <template>, so the dialog and the row are one server render and
     cannot disagree; the head carries the identity and the standing, and the
     footer carries the one act a coordinator can take from here. ── --}}
<div class="modal-backdrop" id="detailBackdrop" role="dialog" aria-modal="true" aria-labelledby="detailTitle" hidden>
	<div class="modal-panel ar-detail-modal">
		<div class="modal-head">
			<div class="ar-detail-ident">
				<p class="ar-modal-eyebrow">At-Risk Beneficiary</p>
				<p class="modal-title" id="detailTitle"></p>
				<p class="ar-modal-meta" id="detailMeta"></p>
			</div>
			<button type="button" class="modal-close" data-detail-close aria-label="Close">&times;</button>
		</div>

		<div class="modal-body ar-detail-body" id="detailBody"></div>

		<div class="modal-foot">
			<span class="badge" id="detailStanding"></span>
			<div class="ar-modal-actions">
				<button type="button" class="btn btn-secondary" data-detail-close>Close</button>
				@if ($followUpsAvailable)
					<button type="button" class="btn btn-primary" id="detailFollowUp">Record Follow-Up</button>
				@endif
			</div>
		</div>
	</div>
</div>

{{-- ── Record Follow-Up ───────────────────────────────────────────────────
     One dialog, one endpoint, whichever row opened it — the same anatomy as
     the enrolment dialog so a dialog looks like a dialog everywhere in the
     product. It records what was done, never a conclusion about the learner,
     and it never changes enrolment. ── --}}
@if ($followUpsAvailable)
	<div class="modal-backdrop" id="followUpBackdrop" role="dialog" aria-modal="true" aria-labelledby="followUpTitle" hidden>
		<div class="modal-panel ar-modal">
			<form method="POST" action="{{ route('feedingcor-at-risk.follow-up.store') }}" id="followUpForm">
				@csrf
				<div class="modal-head">
					<div>
						<p class="ar-modal-eyebrow">At-Risk Beneficiary</p>
						<p class="modal-title" id="followUpTitle">Record Follow-Up</p>
					</div>
					<button type="button" class="modal-close" data-followup-close aria-label="Close">&times;</button>
				</div>

				<div class="modal-body ar-modal-body">
					<div class="ar-field">
						<label class="field-label" for="followUpRecord">Student</label>
						<select class="select" name="record_id" id="followUpRecord" required>
							@foreach ($rows as $row)
								<option value="{{ $row['id'] }}">{{ $row['name'] }} — {{ $row['grade'] }} {{ $row['section'] }}</option>
							@endforeach
						</select>
					</div>

					<div class="ar-field-row">
						<div class="ar-field">
							<label class="field-label" for="followUpDate">Follow-Up Date</label>
							<input type="date" class="input" name="followed_up_on" id="followUpDate"
								value="{{ old('followed_up_on', $today) }}" max="{{ $today }}" required>
						</div>
						<div class="ar-field">
							<label class="field-label" for="followUpStatus">Status</label>
							<select class="select" name="status" id="followUpStatus" required>
								@foreach ($statusOptions as $value => $label)
									<option value="{{ $value }}" @selected(old('status', \App\Models\FeedingFollowUp::STATUS_FOLLOW_UP_REQUIRED) === $value)>{{ $label }}</option>
								@endforeach
							</select>
						</div>
					</div>

					<div class="ar-field">
						<label class="field-label" for="followUpPerson">Person Contacted</label>
						<input type="text" class="input" name="person_contacted" id="followUpPerson"
							maxlength="255" value="{{ old('person_contacted') }}">
					</div>

					<div class="ar-field">
						<label class="field-label" for="followUpAction">Action Taken</label>
						<input type="text" class="input" name="action_taken" id="followUpAction"
							maxlength="500" value="{{ old('action_taken') }}">
					</div>

					<div class="ar-field">
						<label class="field-label" for="followUpReason">Reason / Context</label>
						<textarea class="input ar-textarea" name="reason" id="followUpReason" rows="2" maxlength="500">{{ old('reason') }}</textarea>
					</div>

					<div class="ar-field">
						<label class="field-label" for="followUpRemarks">Remarks</label>
						<textarea class="input ar-textarea" name="remarks" id="followUpRemarks" rows="2" maxlength="500">{{ old('remarks') }}</textarea>
					</div>
				</div>

				<div class="modal-foot">
					<span class="ar-modal-note">Recorded as {{ session('active_name', 'Feeding Coordinator') }}</span>
					<div class="ar-modal-actions">
						<button type="button" class="btn btn-secondary" data-followup-close>Cancel</button>
						<button type="submit" class="btn btn-primary">Save Follow-Up</button>
					</div>
				</div>
			</form>
		</div>
	</div>
@endif

<script>
(() => {
	const page = document.getElementById('ar-page');
	if (!page) return;

	document.getElementById('arPrint')?.addEventListener('click', () => window.print());

	// Every control in the toolbar applies itself — an Apply button nobody
	// presses is a filter that silently does nothing.
	document.querySelectorAll('#arToolbar select').forEach((control) => {
		control.addEventListener('change', () => {
			control.form.requestSubmit ? control.form.requestSubmit() : control.form.submit();
		});
	});

	// ── Dialogs ────────────────────────────────────────────────────────
	// Two of them — the learner's record, and recording a follow-up — opened
	// and closed the same way, and never stacked: opening one closes the other,
	// so there is only ever a single window to act in.
	const dialogs = {};
	let opener = null;

	const openDialog = (backdrop) => {
		if (!backdrop) return;
		Object.values(dialogs).forEach((other) => { if (other !== backdrop) closeDialog(other, false); });
		backdrop.hidden = false;
		backdrop.classList.add('open');
	};

	const closeDialog = (backdrop, restoreFocus = true) => {
		if (!backdrop || backdrop.hidden) return;
		backdrop.classList.remove('open');
		backdrop.hidden = true;
		// Focus goes back to the control that opened the dialog, so a keyboard
		// user is returned to their place in the list.
		if (restoreFocus && opener && document.contains(opener)) {
			opener.focus();
			opener = null;
		}
	};

	const openDialogs = () => Object.values(dialogs).filter((backdrop) => backdrop && !backdrop.hidden);

	// ── The list ───────────────────────────────────────────────────────
	// The name cell opens its learner's record in the details dialog. The
	// panels are that row's own <template>, rendered with it, so the dialog can
	// never show a figure the row has moved past.
	const table = document.getElementById('arTable');
	const rows = table ? Array.from(table.querySelectorAll('tbody tr.ar-row')) : [];

	dialogs.detail = document.getElementById('detailBackdrop');
	const detailBody = document.getElementById('detailBody');
	const detailTitle = document.getElementById('detailTitle');
	const detailMeta = document.getElementById('detailMeta');
	const detailStanding = document.getElementById('detailStanding');
	const detailFollowUp = document.getElementById('detailFollowUp');
	let detailRecord = null;

	const openDetail = (recordId) => {
		const row = rows.find((candidate) => candidate.dataset.row === String(recordId));
		const source = document.querySelector('template.ar-detail-source[data-detail-for="' + recordId + '"]');
		if (!row || !source || !detailBody) return;

		detailRecord = recordId;
		if (detailTitle) detailTitle.textContent = row.dataset.name ?? '';
		if (detailMeta) {
			detailMeta.textContent = [row.dataset.meta, 'Attendance ' + (row.dataset.rate ?? '—')]
				.filter(Boolean)
				.join(' · ');
		}
		if (detailStanding) {
			detailStanding.className = 'badge ' + (row.dataset.standingBadge ?? 'badge-neutral');
			detailStanding.textContent = row.dataset.standing ?? '';
		}

		detailBody.replaceChildren(source.content.cloneNode(true));
		detailBody.scrollTop = 0;
		openDialog(dialogs.detail);
		document.querySelector('#detailBackdrop .modal-close')?.focus();
	};

	const search = document.getElementById('arSearch');
	const noMatch = document.getElementById('arNoMatch');

	search?.addEventListener('input', () => {
		const term = search.value.trim().toLowerCase();
		let shown = 0;
		rows.forEach((row) => {
			const hit = term === '' || row.dataset.search.includes(term);
			row.hidden = !hit;
			if (hit) shown++;
		});
		if (noMatch) noMatch.hidden = !(shown === 0 && rows.length > 0);
	});

	// ── Record Follow-Up ───────────────────────────────────────────────
	dialogs.followUp = document.getElementById('followUpBackdrop');
	const learner = document.getElementById('followUpRecord');

	const openFollowUp = (recordId) => {
		if (!dialogs.followUp) return;
		if (recordId && learner) {
			const option = Array.from(learner.options).find((o) => o.value === String(recordId));
			if (option) learner.value = option.value;
		}
		openDialog(dialogs.followUp);
		document.getElementById('followUpDate')?.focus();
	};

	// Recording a follow-up from inside a learner's record carries that learner
	// with it, so the dialog never opens on somebody else.
	detailFollowUp?.addEventListener('click', () => openFollowUp(detailRecord));

	document.addEventListener('click', (event) => {
		const detailOpener = event.target.closest('[data-detail-open]');
		if (detailOpener) {
			event.preventDefault();
			opener = detailOpener;
			openDetail(detailOpener.dataset.detailOpen);
			return;
		}

		const followUpOpener = event.target.closest('[data-followup-open]');
		if (followUpOpener) {
			event.preventDefault();
			opener = followUpOpener;
			openFollowUp(followUpOpener.dataset.record);
			return;
		}

		if (event.target.closest('[data-detail-close]') || event.target === dialogs.detail) {
			closeDialog(dialogs.detail);
		}
		if (event.target.closest('[data-followup-close]') || event.target === dialogs.followUp) {
			closeDialog(dialogs.followUp);
		}
	});

	document.addEventListener('keydown', (event) => {
		if (event.key !== 'Escape') return;
		openDialogs().forEach((backdrop) => closeDialog(backdrop));
	});

	// ── Live refresh ───────────────────────────────────────────────────
	// The page polls the coordinator's own stamp (no personal data) and re-reads
	// the cards only when it moves, so a mark recorded at the feeding line lands
	// here without a reload.
	//
	// The list is never replaced: it holds the rows a coordinator has opened to
	// read and the place they had reached in it.
	const metricsUrl = page.dataset.metricsUrl;
	const pulseUrl = page.dataset.pulseUrl;
	const cards = document.getElementById('ar-cards');
	if (!metricsUrl || !pulseUrl || !cards) return;

	const query = window.location.search;
	let stamp = null;
	let busy = false;

	const refresh = async () => {
		try {
			const response = await fetch(metricsUrl + query, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
			if (!response.ok) return;
			const payload = await response.json();
			if (payload.html?.cards !== undefined) cards.innerHTML = payload.html.cards;
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
