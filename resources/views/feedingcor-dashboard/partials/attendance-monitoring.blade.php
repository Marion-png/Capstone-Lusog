@php
	$today = $todayAttendance ?? ['expected' => 0, 'present' => 0, 'absent' => 0, 'unconfirmed' => 0, 'unrecorded' => 0, 'percent' => null, 'recorded' => false, 'rows' => [], 'date_label' => ''];
	// Four states, never two. An unread scanned mark and a learner today's
	// sheet never covered are each their own thing — neither is an absence.
	$statusBadges = [
		'present' => ['badge-normal', 'Present'],
		'absent' => ['badge-critical', 'Absent'],
		'unconfirmed' => ['badge-monitor', 'Unconfirmed'],
		'unrecorded' => ['badge-neutral', 'Not recorded'],
	];
@endphp

<div class="att-headline">
	@if ($today['recorded'])
		<div class="att-figure">
			<span class="att-count">{{ $today['present'] }} / {{ $today['expected'] }}</span>
			<span class="att-percent">{{ number_format((float) $today['percent'], 1) }}%</span>
		</div>
	@else
		<div class="att-figure att-figure-idle">
			<span class="att-count">Not recorded</span>
		</div>
	@endif
	<div class="att-chips">
		<span class="badge badge-normal">Present {{ $today['present'] }}</span>
		<span class="badge badge-critical">Absent {{ $today['absent'] }}</span>
		@if ($today['unconfirmed'] > 0)
			<span class="badge badge-monitor">Unconfirmed {{ $today['unconfirmed'] }}</span>
		@endif
		@if ($today['unrecorded'] > 0)
			<span class="badge badge-neutral">No mark {{ $today['unrecorded'] }}</span>
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
				<th>Status</th>
			</tr>
		</thead>
		<tbody>
			@forelse ($today['rows'] as $row)
				@php [$badge, $label] = $statusBadges[$row['status']]; @endphp
				<tr>
					<td><strong>{{ $row['name'] }}</strong></td>
					<td>{{ $row['grade'] }}</td>
					<td>{{ $row['section'] ?: '—' }}</td>
					<td><span class="badge {{ $badge }}">{{ $label }}</span></td>
				</tr>
			@empty
				<tr><td colspan="4" class="table-empty">No beneficiaries on file for this school year.</td></tr>
			@endforelse
		</tbody>
	</table>
</div>
