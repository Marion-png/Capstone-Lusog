{{-- What happened across the feeding programme, one row per feeding day.

     Newest first, because the session a coordinator opens this view to check is
     almost always the last one. Clicking a date opens that day's sheet. --}}
<div class="table-card">
	<div class="table-scroll">
		<table class="fa-table">
			<thead>
				<tr>
					<th>Date</th>
					<th class="num">Feeding Day</th>
					<th class="num">Present</th>
					<th class="num">Absent</th>
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
					<tr><td colspan="6" class="table-empty">No feeding session has been recorded yet.</td></tr>
				@endforelse
			</tbody>
		</table>
	</div>
</div>
