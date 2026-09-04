<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>Beneficiary Record - Feeding Coordinator - SIGLA</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
	<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
	<script>document.documentElement.classList.add('js');</script>
	<style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/feeding-beneficiary.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/role-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.feedingcor-sidebar', ['active' => 'records'])

@php
	// The learner's standing: enrolled and turning up, enrolled and under the
	// school's threshold, qualified and still waiting on the coordinator's
	// decision, or none of those. Same four readings the roster prints.
	// A learner taken off the active list is neither an active beneficiary nor
	// somebody who never was one, so the plate says which — and the record
	// keeps every figure it earned while they were being fed.
	$wasRemoved = ($program['removed_at'] ?? null) !== null;

	[$standingLabel, $standingClass] = match (true) {
		$standing['enrolled'] && $standing['at_risk'] => ['At Risk Beneficiary', 'is-risk'],
		$standing['enrolled'] => ['Active Beneficiary', 'is-active'],
		$wasRemoved => ['Removed from Active List', 'is-none'],
		$standing['qualified'] => ['Pending Enrollment', 'is-pending'],
		default => ['Not a Beneficiary', 'is-none'],
	};

	// One place decides what colour a nutritional status carries, so a status
	// reads the same here as in the badge that carried it on the roster.
	$statusTone = function ($status) {
		$normalized = strtolower((string) $status);
		if (str_contains($normalized, 'severe')) return 'is-critical';
		if (str_contains($normalized, 'wast') || str_contains($normalized, 'underweight') || str_contains($normalized, 'stunt')) return 'is-risk';
		if (str_contains($normalized, 'over') || str_contains($normalized, 'obese')) return 'is-monitor';
		return 'is-normal';
	};

	// Metres, as the DepEd sheet records a learner's height.
	$metres = fn (?float $cm) => $cm === null ? null : number_format($cm / 100, 2).' m';

	$thresholdLabel = rtrim(rtrim(number_format((float) $attendance['threshold'], 1), '0'), '.');
	$rateLabel = $attendance['rate'] !== null ? number_format((float) $attendance['rate'], 0).'%' : '—';
	$hasEndline = $endline['status'] !== ''
		|| $endline['height_cm'] !== null
		|| $endline['weight_kg'] !== null
		|| $endline['bmi'] !== null;

	// En dash in the school year: it is a range, not a hyphenated word.
	$yearLabel = str_replace('-', '&ndash;', e($learner['school_year']));

	// Five readings of one session, and only one of them counts against the
	// learner. An excused absence is the school's own buffer, a mark no human
	// has confirmed is evidence of nothing, and a day no sheet covered is a
	// filing gap — none of the three is ever drawn as a miss.
	$sessionMarks = [
		'present' => ['badge-normal', '✓', 'Present'],
		'absent' => ['badge-critical', '✕', 'Absent'],
		'excused' => ['badge-monitor', 'E', 'Excused'],
		'unconfirmed' => ['badge-neutral', '?', 'Unconfirmed'],
		'unmarked' => ['badge-neutral', '–', 'Not marked'],
	];
@endphp

<div class="main">
	<header class="topbar">
		<div class="topbar-bc">
			<span>Dashboard</span><span class="bc-sep">&rsaquo;</span>
			<a href="{{ route('dashboard.feedingcor-health-records') }}">Beneficiaries</a><span class="bc-sep">&rsaquo;</span>
			<span>{{ $learner['name'] }}</span>
		</div>
		@include('partials.live-clock')
	</header>

	<div class="content bd-page" id="bd-page" data-record="{{ $learner['id'] }}"
		data-enroll-url="{{ route('feedingcor-program.enrollment.store') }}">

		<a class="bd-back" href="{{ route('dashboard.feedingcor-health-records') }}">&larr; All beneficiaries</a>

		@if (session('error'))
			<div class="flash err">{{ session('error') }}</div>
		@endif
		@if (session('success'))
			<div class="flash ok">{{ session('success') }}</div>
		@endif
		<p class="flash err" id="bdError" hidden></p>

		<div class="bd-header">
			<div class="bd-ident">
				<p class="bd-eyebrow">Beneficiary Record</p>
				<h1 class="bd-name">{{ $learner['name'] }}</h1>
				<p class="bd-meta">
					<strong>{{ $learner['grade'] }}{{ $learner['section'] !== '' ? ' — '.$learner['section'] : '' }}</strong>
					@if ($learner['sex'] !== '')
						<span class="bd-sep">&middot;</span>{{ $learner['sex'] }}
					@endif
				</p>
				<p class="bd-meta">
					S.Y. {!! $yearLabel !!}<span class="bd-sep">&middot;</span>School-Based Feeding Program
				</p>
			</div>

			{{-- The acts a coordinator can take on one learner. Measurements are
			     the class adviser's, so this record reports baseline and endline
			     and never asks for them. --}}
			<div class="bd-actions">
				@if ($standing['qualified'] && ! $standing['enrolled'])
					<button type="button" class="btn btn-primary" id="bdEnroll">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
						{{ $wasRemoved ? 'Return to Active List' : 'Enroll Beneficiary' }}
					</button>
				@endif
				@if ($standing['enrolled'])
					{{-- Leaving the programme is a decision about one learner, so it
					     is offered on one learner's record and nowhere else. It is
					     never offered by the At-Risk tab: an attendance figure is a
					     reason to follow a learner up, never a reason to stop
					     feeding them. --}}
					<button type="button" class="btn btn-secondary" data-remove-open>
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
						Remove from Active List
					</button>
				@endif
				<button type="button" class="btn btn-secondary" id="bdPrint">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
					Print Beneficiary Record
				</button>
			</div>
		</div>

		<div class="bd-standing {{ $standingClass }}">
			<span class="bd-standing-label">Status</span>
			<span class="bd-standing-value">{{ $standingLabel }}</span>
		</div>

		{{-- Three readings of one record. Everything is rendered in this one
		     response, so the rail only chooses what is on screen — no tab can
		     show a figure the others have moved past. --}}
		<div class="bd-tabs" role="tablist">
			<button type="button" class="bd-tab is-active" role="tab" aria-selected="true" aria-controls="bd-overview" data-tab="overview">Overview</button>
			<button type="button" class="bd-tab" role="tab" aria-selected="false" aria-controls="bd-attendance" data-tab="attendance">Attendance</button>
			<button type="button" class="bd-tab" role="tab" aria-selected="false" aria-controls="bd-endline" data-tab="endline">Endline</button>
		</div>

		{{-- ── Overview: why this learner is on the programme, and where they
		     sit in its cycle. ── --}}
		<div class="bd-panel cols-2" id="bd-overview" role="tabpanel" data-panel="overview">
			<section class="card bd-card">
				<p class="bd-card-title">Baseline Nutritional Assessment</p>
				@if ($baseline['recorded_at'])
					<p class="bd-card-sub">Measured {{ $baseline['recorded_at'] }}</p>
				@endif
				<dl class="bd-facts">
					<div class="bd-fact">
						<dt>Height</dt>
						<dd class="{{ $baseline['height_cm'] === null ? 'is-none' : '' }}">{{ $metres($baseline['height_cm']) ?? '—' }}</dd>
					</div>
					<div class="bd-fact">
						<dt>Weight</dt>
						<dd class="{{ $baseline['weight_kg'] === null ? 'is-none' : '' }}">{{ $baseline['weight_kg'] !== null ? number_format($baseline['weight_kg'], 1).' kg' : '—' }}</dd>
					</div>
					<div class="bd-fact">
						<dt>BMI</dt>
						<dd class="{{ $baseline['bmi'] === null ? 'is-none' : '' }}">{{ $baseline['bmi'] !== null ? number_format($baseline['bmi'], 1) : '—' }}</dd>
					</div>
					<div class="bd-fact">
						<dt>BMI Status</dt>
						<dd class="{{ $baseline['status'] !== '' ? $statusTone($baseline['status']) : 'is-none' }}">{{ $baseline['status'] !== '' ? $baseline['status'] : '—' }}</dd>
					</div>
					<div class="bd-fact">
						<dt>HFA Status</dt>
						<dd class="{{ $baseline['height_for_age'] !== '' ? $statusTone($baseline['height_for_age']) : 'is-none' }}">{{ $baseline['height_for_age'] !== '' ? $baseline['height_for_age'] : '—' }}</dd>
					</div>
				</dl>
			</section>

			{{-- Days completed counts the sessions the school actually recorded,
			     never the days elapsed: a day nobody fed is not a feeding day
			     this record can claim. --}}
			<section class="card bd-card">
				<p class="bd-card-title">Program</p>
				<dl class="bd-facts">
					<div class="bd-fact">
						<dt>Enrollment Date</dt>
						<dd class="{{ $program['enrolled_at'] === null ? 'is-none' : '' }}">{{ $program['enrolled_at'] ?? 'Not enrolled' }}</dd>
					</div>
					<div class="bd-fact">
						<dt>Program</dt>
						<dd>SBFP {!! $yearLabel !!}</dd>
					</div>
					<div class="bd-fact">
						<dt>Total Feeding Days</dt>
						<dd>{{ $program['total_days'] }}</dd>
					</div>
					<div class="bd-fact">
						<dt>Days Completed</dt>
						<dd>{{ $program['days_completed'] }}</dd>
					</div>
					@if ($program['enrolled_by'] !== '')
						<div class="bd-fact">
							<dt>Enrolled By</dt>
							<dd>{{ $program['enrolled_by'] }}</dd>
						</div>
					@endif
					@if ($wasRemoved)
						{{-- Kept beside the enrolment rather than replacing it: this
						     learner was fed, and the record has to go on saying so. --}}
						<div class="bd-fact">
							<dt>Removed On</dt>
							<dd>{{ $program['removed_at'] }}</dd>
						</div>
						<div class="bd-fact">
							<dt>Reason</dt>
							<dd>{{ $program['removal_reason'] !== '' ? $program['removal_reason'] : '—' }}</dd>
						</div>
						@if ($program['removed_by'] !== '')
							<div class="bd-fact">
								<dt>Removed By</dt>
								<dd>{{ $program['removed_by'] }}</dd>
							</div>
						@endif
					@endif
				</dl>
			</section>
		</div>

		{{-- ── Attendance: the rate the rule judged, and the sessions it judged.
		     An unconfirmed scanned mark is carried on its own line and votes
		     neither way — it is never an absence. ── --}}
		<div class="bd-panel" id="bd-attendance" role="tabpanel" data-panel="attendance" hidden>
			<section class="card bd-card bd-att-summary">
				<p class="bd-card-title">Attendance</p>

				{{-- The one figure the programme is judged on, over the very
				     sessions the rule divided by. --}}
				<p class="bd-att-headline">
					<span class="bd-rate {{ $standing['at_risk'] ? 'is-risk' : '' }}">{{ $attendance['present'] }} / {{ $attendance['confirmed'] }}</span>
					<span class="bd-att-of">days attended</span>
					<span class="bd-att-pct {{ $standing['at_risk'] ? 'is-risk' : '' }}">{{ $rateLabel }}</span>
				</p>

				@php $barWidth = $attendance['rate'] !== null ? max(0, min(100, (float) $attendance['rate'])) : 0; @endphp
				<div class="bd-bar {{ $standing['at_risk'] ? 'is-risk' : '' }}"
					role="img"
					aria-label="Attendance {{ $rateLabel }}{{ $attendance['rate'] !== null ? ', threshold '.$thresholdLabel.'%' : '' }}">
					<span class="bd-bar-fill" style="width: {{ $barWidth }}%"></span>
					{{-- The threshold sits on the bar, so the gap between where
					     the learner is and where the school expects them to be
					     is the thing the eye reads first. --}}
					<span class="bd-bar-mark" style="left: {{ max(0, min(100, (float) $attendance['threshold'])) }}%"></span>
				</div>
				<p class="bd-note">
					At-risk threshold: <strong>{{ $thresholdLabel }}%</strong>
					@if ($attendance['excused'] > 0)
						&middot; {{ $attendance['excused'] }} excused absence(s), counted neither way
					@endif
					@if ($attendance['unconfirmed'] > 0)
						&middot; {{ $attendance['unconfirmed'] }} unconfirmed mark(s), counted neither way
					@endif
				</p>

				@if ($standing['at_risk'])
					<div class="alert-bar is-critical bd-alert">
						<div class="alert-body">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
							<div>
								<strong>At Risk</strong>
								<span>{{ ucfirst($attendance['rule']) }}</span>
							</div>
						</div>
					</div>
				@endif

				@if ($standing['enrolled'] && ($attendance['needs_removal_review'] ?? false))
					{{-- The school's second, later threshold. It opens a question for
					     the class adviser — is this learner coming back? — and
					     nothing more: no figure on this page removes anybody. --}}
					<div class="alert-bar bd-alert">
						<div class="alert-body">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
							<div>
								<strong>Removal review due</strong>
								<span>{{ $attendance['absence_run'] }} unexcused {{ \Illuminate\Support\Str::plural('absence', $attendance['absence_run']) }} in a row. Confirm with the class adviser before releasing the slot.</span>
							</div>
						</div>
					</div>
				@endif
			</section>

			{{-- Every feeding day the school held, newest month first. A day no
			     sheet covered this learner reads "Not marked" — never an
			     absence — and can be corrected here like any other. --}}
			@forelse ($sessionMonths as $month)
				<section class="card bd-card bd-sessions">
					<p class="bd-card-title">{{ $month['label'] }}</p>
					<div class="table-scroll">
						<table class="bd-session-table">
							<thead>
								<tr>
									<th>Date</th>
									<th>Status</th>
									<th>Recorded By</th>
									<th>Remarks</th>
									<th class="bd-correct-col">Correct</th>
								</tr>
							</thead>
							<tbody>
								@foreach ($month['rows'] as $row)
									<tr>
										<td class="bd-session-date">{{ $row['day_label'] }}</td>
										<td>
											<span class="badge {{ $sessionMarks[$row['status']][0] }} has-glyph">
												<span class="bd-glyph">{{ $sessionMarks[$row['status']][1] }}</span>{{ $sessionMarks[$row['status']][2] }}
											</span>
										</td>
										<td class="bd-remark">{{ $row['recorded_by'] !== '' ? $row['recorded_by'] : '—' }}</td>
										<td class="bd-remark">{{ $row['remarks'] !== '' ? $row['remarks'] : '—' }}</td>
										<td class="bd-correct-col">
											{{-- Three posts to one audited endpoint. The
											     mark a session already reads is not
											     offered again — there is nothing to
											     correct it to. Excusing is here rather
											     than anywhere else because it is a
											     decision about one named learner on one
											     named day, and this is the screen that
											     records it with a reason. --}}
											<form method="POST" action="{{ route('feedingcor-program.beneficiary.attendance.correct', $learner['id']) }}" class="bd-correct">
												@csrf
												<input type="hidden" name="session_date" value="{{ $row['date'] }}">
												<button type="submit" name="mark" value="present" class="bd-correct-btn is-present" @disabled($row['status'] === 'present')>Present</button>
												<button type="submit" name="mark" value="absent" class="bd-correct-btn is-absent" @disabled($row['status'] === 'absent')>Absent</button>
												<button type="submit" name="mark" value="excused" class="bd-correct-btn is-excused" @disabled($row['status'] === 'excused')>Excused</button>
											</form>
										</td>
									</tr>
								@endforeach
							</tbody>
						</table>
					</div>
				</section>
			@empty
				<section class="card bd-card">
					<p class="bd-empty">No feeding session has been recorded for this school year yet.</p>
				</section>
			@endforelse
		</div>

		{{-- ── Endline: the class adviser's closing measurement. Nothing
		     measured is "Not yet recorded" — a real answer, not an empty
		     card. ── --}}
		<div class="bd-panel" id="bd-endline" role="tabpanel" data-panel="endline" hidden>
			<section class="card bd-card">
				<p class="bd-card-title">Endline Nutritional Assessment</p>
				@if ($hasEndline && $endline['recorded_at'])
					<p class="bd-card-sub">Measured {{ $endline['recorded_at'] }}</p>
				@endif
				@if ($hasEndline)
					<dl class="bd-facts">
						<div class="bd-fact">
							<dt>Height</dt>
							<dd class="{{ $endline['height_cm'] === null ? 'is-none' : '' }}">{{ $metres($endline['height_cm']) ?? '—' }}</dd>
						</div>
						<div class="bd-fact">
							<dt>Weight</dt>
							<dd class="{{ $endline['weight_kg'] === null ? 'is-none' : '' }}">{{ $endline['weight_kg'] !== null ? number_format($endline['weight_kg'], 1).' kg' : '—' }}</dd>
						</div>
						<div class="bd-fact">
							<dt>BMI</dt>
							<dd class="{{ $endline['bmi'] === null ? 'is-none' : '' }}">{{ $endline['bmi'] !== null ? number_format($endline['bmi'], 1) : '—' }}</dd>
						</div>
						<div class="bd-fact">
							<dt>BMI Status</dt>
							<dd class="{{ $endline['status'] !== '' ? $statusTone($endline['status']) : 'is-none' }}">{{ $endline['status'] !== '' ? $endline['status'] : '—' }}</dd>
						</div>
						<div class="bd-fact">
							<dt>Baseline BMI Status</dt>
							<dd class="{{ $baseline['status'] !== '' ? $statusTone($baseline['status']) : 'is-none' }}">{{ $baseline['status'] !== '' ? $baseline['status'] : '—' }}</dd>
						</div>
					</dl>
				@else
					<p class="bd-empty">Not yet recorded</p>
				@endif
			</section>
		</div>
	</div>
</div>

@if ($standing['enrolled'])
	{{-- Removing a beneficiary asks for a reason, because the reason is the
	     thing that will still matter a year from now — "transferred to Davao
	     City NHS" is what makes the vacated slot explicable. Same dialog
	     anatomy as the enrolment and follow-up dialogs. --}}
	<div class="modal-backdrop" id="removeBackdrop">
		<div class="modal-panel bd-remove-modal" role="dialog" aria-modal="true" aria-labelledby="removeTitle">
			<form method="POST" action="{{ route('feedingcor-program.enrollment.remove', $learner['id']) }}">
				@csrf
				<header class="modal-head">
					<div>
						<p class="modal-eyebrow">Beneficiary Record</p>
						<h2 class="modal-title" id="removeTitle">Remove from Active List</h2>
						<p class="modal-sub">{{ $learner['name'] }} &middot; {{ $learner['grade'] }}{{ $learner['section'] !== '' ? ' — '.$learner['section'] : '' }}</p>
					</div>
					<button type="button" class="modal-close" data-remove-close aria-label="Close">&times;</button>
				</header>
				<div class="modal-body">
					<label class="field-label" for="removeReason">Reason</label>
					<input type="text" class="input" id="removeReason" name="reason" maxlength="255" required
						placeholder="Transferred to another school" autocomplete="off">
				</div>
				<footer class="modal-foot">
					<div class="modal-actions">
						<button type="button" class="btn btn-ghost" data-remove-close>Cancel</button>
						<button type="submit" class="btn btn-primary">Remove Beneficiary</button>
					</div>
				</footer>
			</form>
		</div>
	</div>
@endif

<script>
// The rail chooses which reading of the record is on screen; print puts the
// whole record on paper through the sheet's @media print block; and enrolling
// posts to the same endpoint the dialog and the waiting list use — one
// audited, scoped, idempotent path into feeding_enrolled_at.
(() => {
	const tabs = Array.from(document.querySelectorAll('.bd-tab'));
	const panels = Array.from(document.querySelectorAll('.bd-panel'));

	// Correcting a mark posts and comes back, so the rail remembers which
	// reading was open — a coordinator fixing a session should not be returned
	// to the Overview between corrections.
	const memory = 'bd-tab:' + (document.getElementById('bd-page')?.dataset.record ?? '');

	const show = (name, remember = true) => {
		if (!tabs.some((tab) => tab.dataset.tab === name)) return;

		tabs.forEach((tab) => {
			const active = tab.dataset.tab === name;
			tab.classList.toggle('is-active', active);
			tab.setAttribute('aria-selected', active ? 'true' : 'false');
		});
		panels.forEach((panel) => { panel.hidden = panel.dataset.panel !== name; });

		if (remember) {
			try { sessionStorage.setItem(memory, name); } catch (e) { /* private mode: the rail simply forgets */ }
		}
	};

	tabs.forEach((tab) => tab.addEventListener('click', () => show(tab.dataset.tab)));

	try {
		const remembered = sessionStorage.getItem(memory);
		if (remembered) show(remembered, false);
	} catch (e) { /* nothing remembered is not an error */ }

	document.getElementById('bdPrint')?.addEventListener('click', () => window.print());

	// Removal: one dialog, one endpoint, one reason. Plain form POST, so it
	// works with the page's own redirect and flash rather than needing a
	// second success path.
	const removeBackdrop = document.getElementById('removeBackdrop');
	if (removeBackdrop) {
		const setRemoveOpen = (open) => {
			removeBackdrop.classList.toggle('open', open);
			if (open) removeBackdrop.querySelector('#removeReason')?.focus();
		};

		document.addEventListener('click', (event) => {
			if (event.target.closest('[data-remove-open]')) {
				event.preventDefault();
				setRemoveOpen(true);
				return;
			}
			if (event.target.closest('[data-remove-close]') || event.target === removeBackdrop) {
				setRemoveOpen(false);
			}
		});

		document.addEventListener('keydown', (event) => {
			if (event.key === 'Escape' && removeBackdrop.classList.contains('open')) setRemoveOpen(false);
		});
	}

	const page = document.getElementById('bd-page');
	const button = document.getElementById('bdEnroll');
	const error = document.getElementById('bdError');
	if (!page || !button) return;

	const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

	button.addEventListener('click', async () => {
		button.disabled = true;
		error.hidden = true;

		try {
			const response = await fetch(page.dataset.enrollUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'Accept': 'application/json',
					'X-CSRF-TOKEN': csrf,
					'X-Requested-With': 'XMLHttpRequest',
				},
				credentials: 'same-origin',
				body: JSON.stringify({ record_ids: [Number(page.dataset.record)] }),
			});

			const payload = await response.json().catch(() => ({}));
			if (!response.ok) throw new Error(payload.message || 'The learner could not be enrolled.');

			// Every figure on this page is derived, so the enrolled record is
			// re-read rather than patched in place.
			window.location.reload();
		} catch (err) {
			error.textContent = err.message;
			error.hidden = false;
			button.disabled = false;
		}
	});
})();
</script>
@include('partials.role-page-transition')
</body>
</html>
