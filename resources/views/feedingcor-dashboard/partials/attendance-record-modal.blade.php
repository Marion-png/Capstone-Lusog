{{-- ── Record Attendance ──────────────────────────────────────────────────
     The one place a mark is entered. The Attendance Sheet behind it is a
     record of what this dialog wrote and carries no control of its own, so
     there is exactly one way to record a session and one way to change one
     afterwards (the learner's beneficiary record, where it is audited).

     Rendered only while the session is still open — `canRecord` is false once
     a human has confirmed any mark for the date, on a weekend, outside the
     running cycle, or with nobody enrolled. The endpoint re-checks every one of
     those, because a dialog that is not drawn is not a guarantee.

     Anatomy is the shared one (`.modal-backdrop` / `.modal-panel` / `-head` /
     `-body` / `-foot`), so a dialog looks the same product-wide. --}}
<div class="modal-backdrop" id="recordBackdrop" data-session-date="{{ $selectedDate }}">
	<div class="modal-panel ra-modal" role="dialog" aria-modal="true" aria-labelledby="recordTitle">
		<form method="POST" action="{{ route('feedingcor-program.attendance.record.store') }}" id="recordForm">
			@csrf
			<input type="hidden" name="session_date" value="{{ $selectedDate }}">
			<input type="hidden" name="return_to" value="attendance">

			<header class="modal-head">
				<div>
					<p class="modal-eyebrow">Feeding Day {{ $programDay }} of {{ $programDuration }}</p>
					<h2 class="modal-title" id="recordTitle">Record Attendance</h2>
					<p class="modal-sub">{{ $selectedDateLabel }} &middot; {{ $beneficiaryCount }} enrolled {{ \Illuminate\Support\Str::plural('beneficiary', $beneficiaryCount) }}</p>
				</div>
				<button type="button" class="modal-close" data-record-close aria-label="Close">&times;</button>
			</header>

			<div class="modal-body">
				<div class="ra-modal-bar">
					<div class="lg-search ra-modal-search">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
						<input type="search" id="raModalSearch" placeholder="Search name or section" autocomplete="off" aria-label="Search beneficiaries by name or section">
					</div>
					{{-- Marking everyone present and correcting the few absences is
					     the fast path. It only touches rows the search leaves on
					     screen. --}}
					<div class="ra-modal-bulk">
						<button type="button" class="btn btn-secondary" data-record-all="present">Mark All Present</button>
						<button type="button" class="btn btn-secondary" data-record-all="absent">Mark All Absent</button>
						<button type="button" class="btn btn-ghost" data-record-all="">Clear</button>
					</div>
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
								<tr data-search="{{ strtolower(trim($row['name'].' '.$row['grade'].' '.$row['section'])) }}">
									<td class="ra-idx">{{ $index + 1 }}</td>
									<td class="ra-name"><strong>{{ $row['name'] }}</strong></td>
									<td>{{ $row['grade_number'] !== '' ? $row['grade_number'] : '—' }}</td>
									<td>{{ $row['section'] }}</td>
									<td class="ra-mark-col">
										<div class="fa-toggle" role="group" aria-label="Attendance for {{ $row['name'] }}">
											<label class="fa-opt fa-opt-present">
												<input type="radio" name="marks[{{ $row['id'] }}]" value="present">
												<span>Present</span>
											</label>
											<label class="fa-opt fa-opt-absent">
												<input type="radio" name="marks[{{ $row['id'] }}]" value="absent">
												<span>Absent</span>
											</label>
										</div>
										@if ($row['status'] === 'unconfirmed')
											{{-- A scanned mark nobody has read. Saving here is
											     what decides it. --}}
											<span class="badge badge-monitor">Unconfirmed</span>
										@endif
									</td>
									<td>
										{{-- Only an absence carries a reason, so the field opens
										     when Absent is chosen and clears when it is not. --}}
										<input type="text" class="input fa-remark" maxlength="255"
											name="remarks[{{ $row['id'] }}]" value=""
											aria-label="Reason {{ $row['name'] }} was absent" disabled>
									</td>
								</tr>
							@endforeach
						</tbody>
					</table>
					<p class="table-empty" id="raModalNoMatch" style="display:none;">No learner matches this search.</p>
				</div>
			</div>

			<footer class="modal-foot">
				<div class="fa-tally">
					<span class="badge badge-normal">Present <span data-record-tally="present">0</span></span>
					<span class="badge badge-critical">Absent <span data-record-tally="absent">0</span></span>
					<span class="badge badge-neutral">Unmarked <span data-record-tally="none">{{ count($recordRows) }}</span></span>
				</div>
				<div class="modal-actions">
					<button type="button" class="btn btn-ghost" data-record-close>Cancel</button>
					<button type="submit" class="btn btn-primary" id="recordSave">Save Attendance</button>
				</div>
			</footer>
		</form>
	</div>
</div>
