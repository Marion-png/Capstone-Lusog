{{-- ── Record Attendance ──────────────────────────────────────────────────
     The one place a mark is entered. The Attendance Sheet behind it is a
     record of what this dialog wrote and carries no control of its own, so
     there is exactly one way to record a session and one way to change one
     afterwards (the learner's beneficiary record, where it is audited).

     **A closed day opens the dialog as a record, not as a form.** `$recordCanSave`
     is false once a human has confirmed any mark for the date, on a weekend,
     outside the running cycle, or with nobody enrolled: the form element, the
     radios, the remark fields, the bulk marks and the Save button are then not
     rendered at all — a disabled control is still one a browser can re-enable
     and post — and the session is reported with the marks it came to and the
     reason it is closed. `storeRecordedAttendance` re-checks every one of those
     conditions, because a dialog that is not drawn is not a guarantee.

     A control that opens a dialog either way is deliberate: a button that
     vanishes once the day is recorded leaves a coordinator with nothing to
     press and nothing to read, and no way to tell "already done" from "broken".

     **It carries its own behaviour.** The script below travels with the markup,
     so including this partial is the whole of adding the dialog to a page — the
     Dashboard and the Attendance tab both open the same one control-for-control
     rather than keeping two copies of the wiring that could drift apart. Any
     control marked `[data-record-open]` opens it, wherever it sits.

     Anatomy is the shared one (`.modal-backdrop` / `.modal-panel` / `-head` /
     `-body` / `-foot`), so a dialog looks the same product-wide. --}}
@php
	// Open by default: the Attendance tab renders this only on a day it can
	// record, so it passes nothing. The Dashboard offers the control every day
	// and says which kind of day it is.
	$raCanSave = $recordCanSave ?? true;
	$raBlocked = trim((string) ($recordBlockedReason ?? ''));

	// The marks on file, for a session being read rather than written.
	$raMarks = [
		'present' => ['badge-normal', 'Present'],
		'absent' => ['badge-critical', 'Absent'],
		'excused' => ['badge-monitor', 'Excused'],
		'unconfirmed' => ['badge-monitor', 'Unconfirmed'],
	];
	$raTally = ['present' => 0, 'absent' => 0, 'excused' => 0, 'unconfirmed' => 0, 'unmarked' => 0];
	foreach ($recordRows as $raRow) {
		$raKey = array_key_exists($raRow['status'], $raTally) ? $raRow['status'] : 'unmarked';
		$raTally[$raKey]++;
	}
@endphp
<div class="modal-backdrop" id="recordBackdrop" data-session-date="{{ $selectedDate }}">
	<div class="modal-panel ra-modal{{ $raCanSave ? '' : ' is-readonly' }}" role="dialog" aria-modal="true" aria-labelledby="recordTitle">
		{{-- On a closed day this is a plain container, not a form: there is
		     no element on screen that could post at all, and an inert form is
		     one an extension or a stray Enter could still submit. --}}
		@if ($raCanSave)
		<form method="POST" action="{{ route('feedingcor-program.attendance.record.store') }}" id="recordForm">
			@csrf
			<input type="hidden" name="session_date" value="{{ $selectedDate }}">
			{{-- Where the save lands: the screen the coordinator opened the
			     dialog from. Anything but "attendance" returns to the
			     Dashboard, which is what the Dashboard passes. --}}
			<input type="hidden" name="return_to" value="{{ $recordReturnTo ?? 'attendance' }}">
		@else
		<div class="ra-modal-shell">
		@endif

			<header class="modal-head">
				<div>
					<p class="modal-eyebrow">Feeding Day {{ $programDay }} of {{ $programDuration }}</p>
					<h2 class="modal-title" id="recordTitle">{{ $raCanSave ? 'Record Attendance' : 'Attendance' }}</h2>
					<p class="modal-sub">{{ $selectedDateLabel }} &middot; {{ $beneficiaryCount }} enrolled {{ \Illuminate\Support\Str::plural('beneficiary', $beneficiaryCount) }}</p>
				</div>
				<button type="button" class="modal-close" data-record-close aria-label="Close">&times;</button>
			</header>

			@if (! $raCanSave && $raBlocked !== '')
				<div class="alert-bar is-info ra-modal-notice">
					<div class="alert-body">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
						<div><strong>{{ $raBlocked }}</strong></div>
					</div>
				</div>
			@endif

			<div class="modal-body">
				<div class="ra-modal-bar">
					<div class="lg-search ra-modal-search">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
						<input type="search" id="raModalSearch" placeholder="Search name or section" autocomplete="off" aria-label="Search beneficiaries by name or section">
					</div>
					{{-- Marking everyone present and correcting the few absences is
					     the fast path. It only touches rows the search leaves on
					     screen. --}}
					{{-- No bulk "excused": an excuse is a reason given for one
					     named learner, and a button that excuses a whole screen
					     of them at once would record a decision nobody made. --}}
					@if ($raCanSave)
						<div class="ra-modal-bulk">
							<button type="button" class="btn btn-secondary" data-record-all="present">Mark All Present</button>
							<button type="button" class="btn btn-secondary" data-record-all="absent">Mark All Absent</button>
							<button type="button" class="btn btn-ghost" data-record-all="">Clear</button>
						</div>
					@endif
				</div>

				<div class="table-scroll ra-modal-scroll">
					<table class="ra-modal-table" id="raModalTable">
						<thead>
							<tr>
								<th class="ra-idx">#</th>
								<th>Student</th>
								<th>Grade</th>
								<th>Section</th>
								<th class="ra-mark-col">Attendance</th>
								<th>Remarks</th>
							</tr>
						</thead>
						<tbody>
							@foreach ($recordRows as $index => $row)
								{{-- Name, grade and section are carried on the row so the
								     confirmation dialog reads them back from the form
								     rather than from a second query. --}}
								<tr data-search="{{ strtolower(trim($row['name'].' '.$row['grade'].' '.$row['section'])) }}"
									data-name="{{ $row['name'] }}"
									data-grade="{{ $row['grade_number'] !== '' ? $row['grade_number'] : '—' }}"
									data-section="{{ $row['section'] }}">
									<td class="ra-idx">{{ $index + 1 }}</td>
									<td class="ra-name"><strong>{{ $row['name'] }}</strong></td>
									<td>{{ $row['grade_number'] !== '' ? $row['grade_number'] : '—' }}</td>
									<td>{{ $row['section'] }}</td>
									<td class="ra-mark-col">
										@if ($raCanSave)
											{{-- Three answers, because an absence the school
											     accepted is a different fact from one it did
											     not: only the unexcused kind counts toward the
											     at-risk flag. --}}
											<div class="fa-toggle" role="group" aria-label="Attendance for {{ $row['name'] }}">
												<label class="fa-opt fa-opt-present">
													<input type="radio" name="marks[{{ $row['id'] }}]" value="present">
													<span>Present</span>
												</label>
												<label class="fa-opt fa-opt-absent">
													<input type="radio" name="marks[{{ $row['id'] }}]" value="absent">
													<span>Absent</span>
												</label>
												<label class="fa-opt fa-opt-excused">
													<input type="radio" name="marks[{{ $row['id'] }}]" value="excused">
													<span>Excused</span>
												</label>
											</div>
											@if ($row['status'] === 'unconfirmed')
												{{-- A scanned mark nobody has read. Saving here is
												     what decides it. --}}
												<span class="badge badge-monitor">Unconfirmed</span>
											@endif
										@elseif (isset($raMarks[$row['status']]))
											<span class="badge {{ $raMarks[$row['status']][0] }}">{{ $raMarks[$row['status']][1] }}</span>
										@else
											{{-- Nobody wrote this learner down. Never an absence. --}}
											<span class="ra-unmarked">Not marked</span>
										@endif
									</td>
									<td>
										@if ($raCanSave)
											{{-- Only an absence carries a reason, so the field opens
											     for either kind and clears when the learner is
											     marked present. --}}
											<input type="text" class="input fa-remark" maxlength="255"
												name="remarks[{{ $row['id'] }}]" value=""
												aria-label="Reason {{ $row['name'] }} was absent" disabled>
										@else
											<span class="ra-remark-read">{{ ($row['remarks'] ?? '') !== '' ? $row['remarks'] : '—' }}</span>
										@endif
									</td>
								</tr>
							@endforeach
						</tbody>
					</table>
					<p class="table-empty" id="raModalNoMatch" style="display:none;">No learner matches this search.</p>
				</div>
			</div>

			<footer class="modal-foot">
				@if ($raCanSave)
					<div class="fa-tally">
						<span class="badge badge-normal">Present <span data-record-tally="present">0</span></span>
						<span class="badge badge-critical">Absent <span data-record-tally="absent">0</span></span>
						<span class="badge badge-monitor">Excused <span data-record-tally="excused">0</span></span>
						<span class="badge badge-neutral">Unmarked <span data-record-tally="none">{{ count($recordRows) }}</span></span>
					</div>
					<div class="modal-actions">
						<button type="button" class="btn btn-ghost" data-record-close>Cancel</button>
						<button type="submit" class="btn btn-primary" id="recordSave">Save Attendance</button>
					</div>
				@else
					{{-- What the session came to, not what is about to be saved.
					     No tally attributes, so the dialog's script leaves these
					     figures alone. --}}
					<div class="fa-tally">
						<span class="badge badge-normal">Present {{ $raTally['present'] }}</span>
						<span class="badge badge-critical">Absent {{ $raTally['absent'] }}</span>
						@if ($raTally['excused'] > 0)
							<span class="badge badge-monitor">Excused {{ $raTally['excused'] }}</span>
						@endif
						@if ($raTally['unconfirmed'] > 0)
							<span class="badge badge-monitor">Unconfirmed {{ $raTally['unconfirmed'] }}</span>
						@endif
						@if ($raTally['unmarked'] > 0)
							<span class="badge badge-neutral">Not marked {{ $raTally['unmarked'] }}</span>
						@endif
					</div>
					<div class="modal-actions">
						<button type="button" class="btn btn-primary" data-record-close>Close</button>
					</div>
				@endif
			</footer>
		@if ($raCanSave)
		</form>
		@else
		</div>
		@endif
	</div>
</div>
<script>
(() => {
	// ── Record Attendance dialog: the one place a mark is entered. ──
	const recordBackdrop = document.getElementById('recordBackdrop');
	if (recordBackdrop) {
		const rows = Array.from(recordBackdrop.querySelectorAll('tbody tr[data-search]'));
		const tallies = {
			present: recordBackdrop.querySelector('[data-record-tally="present"]'),
			absent: recordBackdrop.querySelector('[data-record-tally="absent"]'),
			excused: recordBackdrop.querySelector('[data-record-tally="excused"]'),
			none: recordBackdrop.querySelector('[data-record-tally="none"]'),
		};

		const setOpen = (open) => {
			recordBackdrop.classList.toggle('open', open);
			if (open) recordBackdrop.querySelector('#raModalSearch')?.focus();
		};

		// Any control marked data-record-open opens it, so a page can offer the
		// action without repeating the wiring.
		document.addEventListener('click', (event) => {
			const opener = event.target.closest('[data-record-open]');
			if (opener) {
				event.preventDefault();
				setOpen(true);
				return;
			}
			if (event.target.closest('[data-record-close]') || event.target === recordBackdrop) {
				setOpen(false);
			}
		});

		// The confirmation dialog stacks above this one, so while it is open
		// Escape belongs to it: one press must not dismiss both and lose the
		// marks underneath.
		document.addEventListener('keydown', (event) => {
			if (event.key !== 'Escape' || !recordBackdrop.classList.contains('open')) return;
			if (document.getElementById('confirmBackdrop')?.classList.contains('open')) return;
			setOpen(false);
		});

		// A remark belongs to an absence. Marking a learner present clears and
		// closes theirs, so a stale reason can never survive the correction.
		const syncRemark = (row) => {
			const remark = row.querySelector('.fa-remark');
			if (!remark) return;
			const checked = row.querySelector('input[type="radio"]:checked');
			// Either kind of absence carries a reason — on an excused one it is
			// the reason the school accepted, which is the whole point of
			// recording it.
			const isAway = checked !== null && checked.value !== 'present';
			remark.disabled = !isAway;
			if (!isAway) remark.value = '';
		};

		const retally = () => {
			const counts = { present: 0, absent: 0, excused: 0 };
			rows.forEach((row) => {
				const checked = row.querySelector('input[type="radio"]:checked');
				if (!checked) return;
				if (checked.value in counts) counts[checked.value]++;
			});
			Object.keys(counts).forEach((key) => {
				if (tallies[key]) tallies[key].textContent = String(counts[key]);
			});
			if (tallies.none) {
				tallies.none.textContent = String(rows.length - counts.present - counts.absent - counts.excused);
			}
		};

		recordBackdrop.addEventListener('change', (event) => {
			if (!event.target.matches('input[type="radio"]')) return;
			syncRemark(event.target.closest('tr'));
			retally();
		});

		// Bulk marks only touch rows the search leaves on screen.
		recordBackdrop.querySelectorAll('[data-record-all]').forEach((button) => {
			button.addEventListener('click', () => {
				const value = button.dataset.recordAll;
				rows.forEach((row) => {
					if (row.style.display === 'none') return;
					row.querySelectorAll('input[type="radio"]').forEach((input) => {
						input.checked = value !== '' && input.value === value;
					});
					syncRemark(row);
				});
				retally();
			});
		});

		const modalSearch = document.getElementById('raModalSearch');
		const modalNoMatch = document.getElementById('raModalNoMatch');
		modalSearch?.addEventListener('input', () => {
			const term = modalSearch.value.trim().toLowerCase();
			let shown = 0;
			rows.forEach((row) => {
				const hit = term === '' || row.dataset.search.includes(term);
				row.style.display = hit ? '' : 'none';
				if (hit) shown++;
			});
			if (modalNoMatch) modalNoMatch.style.display = shown === 0 && rows.length > 0 ? '' : 'none';
		});

		retally();

		// An older link that asked for the dialog by URL. The Dashboard opens
		// its own now, so nothing in the app produces this any more — it is
		// kept so a bookmark still lands where it meant to.
		if (new URLSearchParams(window.location.search).get('record') === '1') setOpen(true);
	}
})();
</script>
