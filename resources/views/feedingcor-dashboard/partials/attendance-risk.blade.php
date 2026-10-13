@php
	$risk = $attendanceRisk ?? ['threshold_label' => '', 'notice_label' => '', 'rule' => '', 'mode' => '', 'count' => 0, 'awaiting' => 0, 'notices' => 0, 'rows' => []];
@endphp

{{-- The school's own two figures, printed rather than assumed — two schools
     read different lines here. "Flagged" counts confirmed learners only. --}}
<div class="risk-headline">
	<span class="risk-threshold">Notice at <strong>{{ $risk['notice_label'] }}</strong> &middot; Flag at <strong>{{ $risk['threshold_label'] }}</strong></span>
	<span class="risk-count">{{ $risk['count'] }} flagged</span>
	@if ($risk['awaiting'] > 0)
		<span class="risk-count">{{ $risk['awaiting'] }} awaiting confirmation</span>
	@endif
	@if ($risk['notices'] > 0)
		<span class="risk-observing">{{ $risk['notices'] }} {{ \Illuminate\Support\Str::plural('notice', $risk['notices']) }}</span>
	@endif
</div>

<div class="table-scroll risk-scroll">
	<table class="risk-table">
		<thead>
			<tr>
				<th>Student</th>
				<th>Grade</th>
				<th>Section</th>
				<th>In a Row</th>
				<th>Attendance</th>
				<th>Status</th>
				<th>Action</th>
			</tr>
		</thead>
		<tbody>
			@forelse ($risk['rows'] as $row)
				<tr>
					<td><strong>{{ $row['name'] }}</strong></td>
					<td>{{ $row['grade'] }}</td>
					<td>{{ $row['section'] ?: '—' }}</td>
					<td class="tnum">{{ $row['run'] }}</td>
					{{-- The rate and the fraction come from the same confirmed
					     sessions the rule judged, so they can never disagree. --}}
					<td class="tnum">{{ !is_null($row['rate']) ? number_format((float) $row['rate'], 0).'%' : '—' }} ({{ $row['present'] }}/{{ $row['sessions'] }})</td>
					<td><span class="badge {{ $row['state_badge'] }}">{{ $row['state_label'] }}</span></td>
					<td>
						<a class="risk-view" href="{{ route('feedingcor-program.attendance.learner', $row['id']) }}">View</a>
					</td>
				</tr>
			@empty
				<tr><td colspan="7" class="table-empty">No attendance notice or follow-up flag.</td></tr>
			@endforelse
		</tbody>
	</table>
</div>
