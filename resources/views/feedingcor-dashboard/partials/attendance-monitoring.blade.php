@php
	$today = $todayAttendance ?? ['expected' => 0, 'present' => 0, 'absent' => 0, 'excused' => 0, 'unconfirmed' => 0, 'unrecorded' => 0, 'percent' => 0.0, 'recorded' => false, 'filtered' => false, 'rows' => [], 'date_label' => ''];
	// Present and Absent are the two decisions a recorded session produces.
	// A scanned mark nobody has read, and a learner today's sheet never
	// covered, are neither — they render as a dash, never as an absence.
	$marks = [
		'present' => ['badge-normal', 'Present'],
		'absent' => ['badge-critical', 'Absent'],
		'excused' => ['badge-monitor', 'Excused'],
	];
@endphp

@php
	// Today's roll, split five ways. Present and Absent are always named —
	// they are the two answers a recorded session gives — and the other three
	// only when someone is in them. Unmarked is a learner no sheet covered,
	// never an absence, so it is drawn as a hatched gap rather than a colour.
	$expected = max(0, (int) $today['expected']);
	$split = [
		['key' => 'present', 'label' => 'Present', 'count' => (int) $today['present'], 'always' => true],
		['key' => 'absent', 'label' => 'Absent', 'count' => (int) $today['absent'], 'always' => true],
		['key' => 'excused', 'label' => 'Excused', 'count' => (int) $today['excused'], 'always' => false],
		['key' => 'unconfirmed', 'label' => 'Unconfirmed', 'count' => (int) $today['unconfirmed'], 'always' => false],
		['key' => 'unmarked', 'label' => 'Unmarked', 'count' => (int) $today['unrecorded'], 'always' => false],
	];
	$shown = array_values(array_filter($split, fn (array $part): bool => $part['always'] || $part['count'] > 0));
	$meterLabel = implode(', ', array_map(fn (array $part): string => $part['label'].' '.$part['count'], $shown)).' of '.$expected;
@endphp

{{-- The fraction is the fact (82 of 87 present); the percentage restates it
     for scale and stays grey until the session is recorded, because 0 of N
     before anybody has marked the roll is not a turnout anyone measured. --}}
<div class="att-summary">
	<div class="att-headline">
		<div class="att-figure">
			<span class="att-count"><strong>{{ number_format((int) $today['present']) }}</strong> <span class="att-of">/ {{ number_format($expected) }}</span></span>
			<span class="att-caption">present today</span>
		</div>
		<span class="att-rate {{ $today['recorded'] ? '' : 'is-idle' }}">
			<strong>{{ number_format((float) $today['percent'], 0) }}%</strong>
			{{ $today['recorded'] ? 'turnout' : 'not recorded' }}
		</span>
	</div>

	<div class="att-meter" role="img" aria-label="{{ $meterLabel }}">
		@if ($expected > 0)
			@foreach ($split as $part)
				@if ($part['count'] > 0)
					<span class="att-seg is-{{ $part['key'] }}" style="width: {{ round($part['count'] / $expected * 100, 2) }}%"></span>
				@endif
			@endforeach
		@endif
	</div>

	<ul class="att-legend">
		@foreach ($shown as $part)
			<li class="{{ $part['count'] === 0 ? 'is-zero' : '' }}">
				<i class="att-key is-{{ $part['key'] }}" aria-hidden="true"></i>
				{{ $part['label'] }} <strong>{{ number_format($part['count']) }}</strong>
			</li>
		@endforeach
	</ul>
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
