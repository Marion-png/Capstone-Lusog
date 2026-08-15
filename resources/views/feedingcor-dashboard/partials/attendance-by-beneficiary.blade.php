{{-- Attendance by beneficiary: the cumulative standing the school's threshold
     is applied to.

     Deliberately carries no baseline, BMI, height-for-age or endline column. A
     learner's health profile is the Beneficiaries tab's responsibility; this
     roll answers who is turning up and who is not, and nothing else — which is
     what lets the two tabs stay honest about which one owns what.

     The rate printed beside a learner and the flag beside it come from one
     reading of one set of marks, so a row can never show 92% next to a
     warning. --}}
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
						{{-- A learner no confirmed session has covered has no
						     rate to report — an em dash, never 0%. --}}
						<td class="num tnum">{{ $row['rate'] !== null ? number_format($row['rate'], 1).'%' : '—' }}</td>
						<td>
							@if ($row['at_risk'])
								<span class="badge badge-risk has-glyph"><span class="fa-glyph">⚠</span>At Risk</span>
							@elseif ($row['rate'] === null)
								<span class="badge badge-neutral">No sessions</span>
							@else
								<span class="badge badge-normal">Good</span>
							@endif
						</td>
					</tr>
				@empty
					<tr><td colspan="7" class="table-empty">
						{{ ($filters['status'] ?? '') === 'at_risk' ? 'No beneficiary is below the threshold.' : 'No beneficiaries match these filters.' }}
					</td></tr>
				@endforelse
			</tbody>
		</table>
	</div>
</div>
