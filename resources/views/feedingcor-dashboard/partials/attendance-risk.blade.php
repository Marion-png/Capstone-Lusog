@php
	$risk = $attendanceRisk ?? ['threshold' => 80, 'rule' => '', 'mode' => 'attendance_rate', 'count' => 0, 'rows' => []];
	// The threshold is the school's own (System Admin sets it per school), so
	// it is printed rather than assumed — two schools read different lines here.
	$thresholdLabel = rtrim(rtrim(number_format((float) $risk['threshold'], 1), '0'), '.');
@endphp

<div class="risk-headline">
	<span class="risk-threshold">At-risk threshold: <strong>{{ $thresholdLabel }}%</strong></span>
	<span class="risk-count">{{ $risk['count'] }} flagged</span>
</div>

<div class="table-scroll risk-scroll">
	<table class="risk-table">
		<thead>
			<tr>
				<th>Student</th>
				<th>Grade</th>
				<th>Section</th>
				<th>Attendance</th>
				<th>Days Present</th>
				<th>Action</th>
			</tr>
		</thead>
		<tbody>
			@forelse ($risk['rows'] as $row)
				<tr>
					<td><strong>{{ $row['name'] }}</strong></td>
					<td>{{ $row['grade'] }}</td>
					<td>{{ $row['section'] ?: '—' }}</td>
					{{-- The rate and the fraction come from the same confirmed
					     sessions the rule judged, so they can never disagree. --}}
					<td class="risk-rate">{{ !is_null($row['rate']) ? number_format((float) $row['rate'], 0).'%' : '—' }}</td>
					<td class="tnum">{{ $row['present'] }}/{{ $row['sessions'] }}</td>
					<td>
						<a class="risk-view" href="{{ route('feedingcor-program.attendance.learner', $row['id']) }}">View</a>
					</td>
				</tr>
			@empty
				<tr><td colspan="6" class="table-empty">No beneficiary is below the threshold.</td></tr>
			@endforelse
		</tbody>
	</table>
</div>
