{{-- What happened across the feeding programme, one row per feeding day.

     Newest first, because the session a coordinator opens this view to check is
     almost always the last one. Clicking a date opens that day's sheet.

     Every count here is scoped: grade, section and gender narrow the population
     the row is counted over, so one section's history reads that section's
     turnout and not the school's.

     The attendance filter then does two things at once, and both are the same
     answer to the same question. It narrows the LIST — Present keeps the
     sessions somebody was present at, Absent the ones that carried an absence —
     and it narrows the COLUMNS, so a coordinator who asked for absences reads a
     table of absences rather than hunting the right column in a table of both.
     The other three columns never move: "Not marked" is the filing gap and is
     its own figure, Rate is present over confirmed marks whichever way the
     filter is set, and Recorded says how much of the session exists. --}}
@php
	$scopeParts = array_values(array_filter([
		$filters['grade'] ?? '',
		$filters['section'] ?? '',
		$filters['sex'] ?? '',
	], fn (string $part): bool => $part !== ''));

	$mark = $filters['status'] ?? '';
	$showPresent = $mark === '' || $mark === 'present';
	$showAbsent = $mark === '' || $mark === 'absent';

	$markFilter = match ($mark) {
		'present' => 'sessions with someone present',
		'absent' => 'sessions carrying an absence',
		default => null,
	};

	// Date, Feeding Day, Not marked, Rate, Recorded, plus whichever of
	// Present / Absent the filter left standing.
	$columnCount = 5 + (int) $showPresent + (int) $showAbsent;
@endphp

@if ($scopeParts !== [] || $markFilter !== null)
	<p class="fa-scopenote">
		Counted over
		<strong>{{ $scopeParts !== [] ? implode(' &middot; ', $scopeParts) : 'every beneficiary' }}</strong>
		&mdash; {{ $beneficiaryCount }} of {{ $rollCount }} {{ \Illuminate\Support\Str::plural('beneficiary', $rollCount) }}@if ($markFilter !== null), showing {{ $markFilter }}@endif.
	</p>
@endif

<div class="table-card">
	<div class="table-scroll">
		<table class="fa-table">
			<thead>
				<tr>
					<th>Date</th>
					<th class="num">Feeding Day</th>
					@if ($showPresent)<th class="num">Present</th>@endif
					@if ($showAbsent)<th class="num">Absent</th>@endif
					{{-- Beneficiaries no sheet covered that day. Its own column,
					     never added to the absences: the difference between "did
					     not come" and "nobody wrote it down" is the difference
					     between a follow-up and a filing job. --}}
					<th class="num">Not marked</th>
					<th class="num">Rate</th>
					<th>Recorded</th>
				</tr>
			</thead>
			<tbody>
				@forelse ($history as $session)
					<tr>
						<td>
							<a class="fa-datelink" href="{{ $pageUrl(['view' => 'sheet', 'date' => $session['date']]) }}">
								<strong>{{ $session['label'] }}</strong>
							</a>
						</td>
						<td class="num tnum">{{ $session['day'] }}</td>
						@if ($showPresent)<td class="num tnum">{{ $session['present'] }}</td>@endif
						@if ($showAbsent)<td class="num tnum">{{ $session['absent'] }}</td>@endif
						<td class="num tnum">{{ $session['unmarked'] }}</td>
						{{-- Present over confirmed marks: an unconfirmed scan
						     counts on neither side. --}}
						<td class="num tnum">{{ $session['rate'] !== null ? number_format($session['rate'], 1).'%' : '—' }}</td>
						<td>
							@if ($session['complete'])
								<span class="badge badge-normal">Complete</span>
							@else
								<span class="badge badge-monitor">{{ $session['recorded'] }} of {{ $session['expected'] }}</span>
							@endif
							@if ($session['unconfirmed'] > 0)
								<span class="badge badge-neutral">{{ $session['unconfirmed'] }} unconfirmed</span>
							@endif
						</td>
					</tr>
				@empty
					<tr><td colspan="{{ $columnCount }}" class="table-empty">
						@switch($mark)
							@case('present')
								No feeding session in this selection has anyone present.
								@break
							@case('absent')
								No feeding session in this selection carries an absence.
								@break
							@default
								No feeding session has been recorded yet.
						@endswitch
					</td></tr>
				@endforelse
			</tbody>
		</table>
	</div>
</div>
