{{-- What happened across the feeding programme, one row per feeding day.

     Newest first, because the session a coordinator opens this view to check is
     almost always the last one. Clicking a date opens that day's sheet.

     This view carries no toolbar: every row is the whole enrolled roll's tally
     for that day, and no filter narrows the list or the columns. "Not marked"
     is the filing gap and is its own figure, Rate is present over confirmed
     marks, and Recorded says how much of the session exists. --}}
@php
	// Date, Feeding Day, Present, Absent, Excused, Not marked, Rate, Recorded.
	$columnCount = 8;
@endphp

<div class="table-card">
	<div class="table-scroll">
		<table class="fa-table">
			<thead>
				<tr>
					<th>Date</th>
					<th class="num">Feeding Day</th>
					<th class="num">Present</th>
					<th class="num">Absent</th>
					{{-- Absences the school accepted, counted separately from the
					     ones it did not: only the second kind is evidence the
					     at-risk rule reads. --}}
					<th class="num">Excused</th>
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
						<td class="num tnum">{{ $session['present'] }}</td>
						<td class="num tnum">{{ $session['absent'] }}</td>
						<td class="num tnum">{{ $session['excused'] }}</td>
						<td class="num tnum">{{ $session['unmarked'] }}</td>
						{{-- Present over confirmed marks: an unconfirmed scan
						     counts on neither side. --}}
						<td class="num tnum">{{ $session['rate'] !== null ? number_format($session['rate'], 0).'%' : '—' }}</td>
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
					<tr><td colspan="{{ $columnCount }}" class="table-empty">No feeding session has been recorded yet.</td></tr>
				@endforelse
			</tbody>
		</table>
	</div>
</div>
