@php
	$today = $todayAttendance ?? ['expected' => 0, 'present' => 0, 'absent' => 0, 'unconfirmed' => 0, 'unrecorded' => 0, 'percent' => 0.0, 'recorded' => false, 'filtered' => false, 'rows' => [], 'date_label' => ''];
	// Present and Absent are the two decisions a recorded session produces.
	// A scanned mark nobody has read, and a learner today's sheet never
	// covered, are neither — they render as a dash, never as an absence.
	$marks = [
		'present' => ['badge-normal', 'Present'],
		'absent' => ['badge-critical', 'Absent'],
	];
@endphp

<div class="att-headline">
	<div class="att-figure">
		<span class="att-count">{{ $today['present'] }} / {{ $today['expected'] }}</span>
		<span class="att-percent {{ $today['recorded'] ? '' : 'is-idle' }}">{{ number_format((float) $today['percent'], 1) }}%</span>
	</div>
	<div class="att-chips">
		<span class="badge badge-normal">Present {{ $today['present'] }}</span>
		<span class="badge badge-critical">Absent {{ $today['absent'] }}</span>
		@if ($today['unconfirmed'] > 0)
			<span class="badge badge-monitor">Unconfirmed {{ $today['unconfirmed'] }}</span>
		@endif
		@if ($today['unrecorded'] > 0)
			<span class="badge badge-neutral">Unmarked {{ $today['unrecorded'] }}</span>
		@endif
	</div>
</div>

<div class="table-scroll att-scroll">
	<table class="att-table">
		<thead>
			<tr>
				<th>Student</th>
				<th>Grade</th>
				<th>Section</th>
				<th>Attendance</th>
				<th>Remarks</th>
			</tr>
		</thead>
		<tbody>
			@forelse ($today['rows'] as $row)
				<tr>
					<td><strong>{{ $row['name'] }}</strong></td>
					<td>{{ $row['grade'] }}</td>
					<td>{{ $row['section'] ?: '—' }}</td>
					<td>
						@isset ($marks[$row['status']])
							<span class="badge {{ $marks[$row['status']][0] }}">{{ $marks[$row['status']][1] }}</span>
						@else
							<span class="att-none">—</span>
						@endisset
					</td>
					<td class="att-remark">{{ $row['remarks'] !== '' ? $row['remarks'] : '—' }}</td>
				</tr>
			@empty
				<tr><td colspan="5" class="table-empty">{{ $today['filtered'] ? 'No learner matches these filters.' : 'No beneficiaries on file for this school year.' }}</td></tr>
			@endforelse
		</tbody>
	</table>
</div>
