{{-- Attendance by beneficiary: the cumulative standing the school's threshold
     is applied to.

     Deliberately carries no baseline, BMI, height-for-age or endline column. A
     learner's health profile is the Beneficiaries tab's responsibility; this
     roll answers who is turning up and who is not, and nothing else — which is
     what lets the two tabs stay honest about which one owns what.

     Four columns, four different facts, and none of them folded into another:
     Present and Absent are what a human confirmed, "Not marked" is the feeding
     days no sheet covered this learner on (a filing gap, never an absence), and
     Rate is Present over the confirmed sessions alone.

     The rate printed beside a learner, the state beside it and the flag it
     carries come from one reading of one set of marks, so a row can never show
     92% next to a warning — or 25% next to At Risk before the school's
     observation window says a rate means anything. --}}
<div class="table-card">
	<div class="table-scroll">
		<table class="fa-table">
			<thead>
				<tr>
					<th>Student</th>
					<th>Grade</th>
					<th>Section</th>
					<th class="num">Present</th>
					<th class="num">Absent</th>
					<th class="num">Not marked</th>
					<th class="num">Rate</th>
					<th>Status</th>
				</tr>
			</thead>
			<tbody>
				@forelse ($beneficiaryRows as $row)
					<tr>
						<td class="fa-name"><strong>{{ $row['name'] }}</strong></td>
						<td>{{ $row['grade_number'] !== '' ? $row['grade_number'] : '—' }}</td>
						<td>{{ $row['section'] }}</td>
						<td class="num tnum">{{ $row['present'] }}</td>
						<td class="num tnum">{{ $row['absent'] }}</td>
						{{-- Feeding days nobody recorded this learner on. Shown
						     because a coordinator reading 1 of 4 needs to know
						     whether the other sixteen days are absences or
						     paperwork — they are paperwork. --}}
						<td class="num tnum">{{ $row['not_marked'] }}</td>
						{{-- A learner no confirmed session has covered has no
						     rate to report — an em dash, never 0%. --}}
						<td class="num tnum">{{ $row['rate'] !== null ? number_format($row['rate'], 1).'%' : '—' }}</td>
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
					<tr><td colspan="8" class="table-empty">
						@switch($filters['status'] ?? '')
							@case('at_risk')
								No beneficiary is below the threshold.
								@break
							@case('early_monitoring')
								Every beneficiary has enough recorded attendance to be classified.
								@break
							@default
								No beneficiaries match these filters.
						@endswitch
					</td></tr>
				@endforelse
			</tbody>
		</table>
	</div>
</div>
