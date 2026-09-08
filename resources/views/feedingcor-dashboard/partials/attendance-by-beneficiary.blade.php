{{-- Attendance by beneficiary: the cumulative standing the school's threshold
     is applied to.

     Deliberately carries no baseline, BMI, height-for-age or endline column. A
     learner's health profile is the Beneficiaries tab's responsibility; this
     roll answers who is turning up and who is not, and nothing else — which is
     what lets the two tabs stay honest about which one owns what.

     Five columns, five different facts, and none of them folded into another:
     Present and Absent are what a human confirmed, Excused is the school's own
     buffer (an absence it accepted, which the at-risk rule never counts against
     the learner), "Not marked" is the feeding days no sheet covered this learner
     on (a filing gap, never an absence), and Rate is Present over the
     unexcused confirmed sessions alone.

     The rate printed beside a learner, the state beside it and the flag it
     carries come from one reading of one set of marks, so a row can never show
     92% next to a warning — or 25% next to At Risk before the school's
     observation window says a rate means anything.

     The Session column is the one thing here that is not cumulative: it is this
     learner's mark on the day the toolbar is set to, and it is what the
     attendance filter narrows on. It is printed rather than left implicit so a
     filtered roll says why each row is in it.

     That filter also decides which of the two count columns is drawn: ask for
     absences and you read a table of absences, rather than hunting the right
     column in a table of both. "Not marked", Rate and Status never move — they
     answer the same question whichever way the filter is set. --}}
@php
	$sessionMarks = [
		'present' => ['badge-normal', 'Present'],
		'absent' => ['badge-critical', 'Absent'],
		'excused' => ['badge-monitor', 'Excused'],
		'unconfirmed' => ['badge-neutral', 'Unconfirmed'],
	];

	$mark = $filters['status'] ?? '';
	$query = trim((string) ($filters['q'] ?? ''));

	// The school's own feeding days, newest first, with the label each is
	// printed under. The history dialog walks this list rather than the
	// learner's marks, so a day the school fed but no sheet covered this child
	// reads "Not marked" instead of vanishing — the only way a coordinator can
	// see a gap. Formatted here so the dialog and the roll date a session the
	// same way.
	$sessionIndex = collect($sessionDates)
		->sortDesc()
		->values()
		->map(fn (string $date): array => [
			'd' => $date,
			'l' => \Carbon\Carbon::parse($date)->format('M j, Y'),
		])
		->all();
	$showPresent = $mark === '' || $mark === 'present';
	$showAbsent = $mark === '' || $mark === 'absent';
	$showExcused = $mark === '' || $mark === 'excused';

	// Student, Grade, Section, Session, Not marked, Rate, Status, plus whichever
	// of Present / Absent / Excused the filter left standing.
	$columnCount = 7 + (int) $showPresent + (int) $showAbsent + (int) $showExcused;
@endphp
<div class="table-card" data-session-dates="{{ json_encode($sessionIndex) }}">
	<div class="table-scroll">
		<table class="fa-table">
			<thead>
				<tr>
					<th>Student</th>
					<th>Grade</th>
					<th>Section</th>
					{{-- The day this column reads. It is named here rather than
					     in the toolbar, which carries the roll's search instead:
					     a session is chosen on the sheet, where the sheet is. --}}
					<th>Session &middot; {{ \Carbon\Carbon::parse($selectedDate)->format('M j, Y') }}</th>
					@if ($showPresent)<th class="num">Present</th>@endif
					@if ($showAbsent)<th class="num">Absent</th>@endif
					@if ($showExcused)<th class="num">Excused</th>@endif
					<th class="num">Not marked</th>
					<th class="num">Rate</th>
					<th>Status</th>
				</tr>
			</thead>
			<tbody>
				@forelse ($beneficiaryRows as $row)
					<tr>
						{{-- The whole name cell opens this learner's attendance
						     history — every session the school held, the mark on
						     each and the reason recorded with it. The figures the
						     dialog reports are the row's own, carried on the
						     button, so the two are one render and cannot
						     disagree. --}}
						<td class="fa-name is-link">
							<button type="button" class="fa-namebtn" data-learner-open
								data-name="{{ $row['name'] }}"
								data-grade="{{ $row['grade_number'] !== '' ? $row['grade_number'] : '—' }}"
								data-section="{{ $row['section'] }}"
								data-sex="{{ $row['sex'] }}"
								data-present="{{ $row['present'] }}"
								data-absent="{{ $row['absent'] }}"
								data-excused="{{ $row['excused'] }}"
								data-not-marked="{{ $row['not_marked'] }}"
								data-rate="{{ $row['rate'] !== null ? number_format($row['rate'], 0).'%' : '—' }}"
								data-standing="{{ $row['at_risk'] ? 'At Risk' : ($row['status'] === \App\Support\FeedingAtRiskRule::STATUS_EARLY_MONITORING ? 'Early Monitoring' : 'Good') }}"
								data-standing-badge="{{ $row['at_risk'] ? 'badge-risk' : ($row['status'] === \App\Support\FeedingAtRiskRule::STATUS_EARLY_MONITORING ? 'badge-monitor' : 'badge-normal') }}"
								data-record-url="{{ route('feedingcor-program.beneficiary', ['record' => $row['id']]) }}"
								data-marks="{{ json_encode($row['marks']) }}">
								<strong>{{ $row['name'] }}</strong>
							</button>
						</td>
						<td>{{ $row['grade_number'] !== '' ? $row['grade_number'] : '—' }}</td>
						<td>{{ $row['section'] }}</td>
						<td class="fa-session-col">
							@if (isset($sessionMarks[$row['session_status']]))
								<span class="badge {{ $sessionMarks[$row['session_status']][0] }}">{{ $sessionMarks[$row['session_status']][1] }}</span>
								{{-- The reason the school accepted, under the mark it
								     belongs to. An excused absence read without it is
								     indistinguishable from one nobody explained. --}}
								@if (($row['session_remarks'] ?? '') !== '')
									<span class="fa-session-remark">{{ $row['session_remarks'] }}</span>
								@endif
							@else
								{{-- Nobody wrote this learner down for that day. Never an absence. --}}
								<span class="fa-unmarked">Not marked</span>
							@endif
						</td>
						@if ($showPresent)<td class="num tnum">{{ $row['present'] }}</td>@endif
						@if ($showAbsent)<td class="num tnum">{{ $row['absent'] }}</td>@endif
						{{-- The buffer: absences the school accepted. Counted, shown,
						     and never held against the learner by the rule. --}}
						@if ($showExcused)<td class="num tnum">{{ $row['excused'] }}</td>@endif
						{{-- Feeding days nobody recorded this learner on. Shown
						     because a coordinator reading 1 of 4 needs to know
						     whether the other sixteen days are absences or
						     paperwork — they are paperwork. --}}
						<td class="num tnum">{{ $row['not_marked'] }}</td>
						{{-- A learner no confirmed session has covered has no
						     rate to report — an em dash, never 0%. --}}
						<td class="num tnum">{{ $row['rate'] !== null ? number_format($row['rate'], 0).'%' : '—' }}</td>
						<td>
							@if ($row['at_risk'])
								<span class="badge badge-risk has-glyph"><span class="fa-glyph">⚠</span>At Risk</span>
							@elseif ($row['status'] === \App\Support\FeedingAtRiskRule::STATUS_EARLY_MONITORING)
								{{-- Not "Good" and not "At Risk": there is not enough
								     recorded history yet for either to be true. --}}
								<span class="badge badge-monitor">Early Monitoring</span>
								<span class="fa-substatus">{{ $row['sessions_needed'] }} more {{ \Illuminate\Support\Str::plural('session', $row['sessions_needed']) }}</span>
							@else
								<span class="badge badge-normal">Good</span>
							@endif
						</td>
					</tr>
				@empty
					<tr><td colspan="{{ $columnCount }}" class="table-empty">
						@if ($query !== '')
							No beneficiary matches &ldquo;{{ $query }}&rdquo;.
						@else
						@switch(($filters['standing'] ?? '') !== '' ? $filters['standing'] : $mark)
							@case('at_risk')
								No beneficiary is below the threshold.
								@break
							@case('early_monitoring')
								Every beneficiary has enough recorded attendance to be classified.
								@break
							@case('present')
								No beneficiary was marked present on {{ $selectedDateLabel }}.
								@break
							@case('absent')
								No beneficiary was marked absent on {{ $selectedDateLabel }}.
								@break
							@case('excused')
								No beneficiary was excused on {{ $selectedDateLabel }}.
								@break
							@default
								No beneficiaries match these filters.
						@endswitch
						@endif
					</td></tr>
				@endforelse
			</tbody>
		</table>
	</div>
</div>
