{{-- The latest feeding session, and the last few beside it.

     The headline is that day's turnout: present over confirmed marks, the
     way Attendance History counts a day — an excused absence and an unread
     scan are in neither half, and a learner no sheet covered is "not
     marked", never absent.

     Under it, the last few sessions as rows on a FIXED 0–100% track (a
     percentage's scale is not the data's to choose), in the validated
     single-series teal. Every row prints its rate and count as text, a day
     whose marks are all unconfirmed draws no bar rather than a zero, and the
     table view repeats every number, so nothing rides on colour or hover. --}}
@php
	$fpLatest = $latestSession ?? null;
	$fpRecent = $recentSessions ?? [];
@endphp
<div class="card-head">
	<div>
		<h2 class="card-title">Latest Feeding Session</h2>
		<p class="card-sub">{{ $fpLatest['long_label'] ?? 'No session recorded yet' }}</p>
	</div>
</div>

@if ($fpLatest === null)
	<div class="empty-panel fp-empty">No feeding session has been recorded yet.</div>
@else
	<div class="fp-session-head">
		<div class="fp-session-rate">
			<span class="fp-session-value tnum">{{ $fpLatest['rate'] === null ? '—' : $fpLatest['rate'].'%' }}</span>
			<span class="fp-session-caption">present &middot; <span class="tnum">{{ $fpLatest['present'] }}</span> of <span class="tnum">{{ $fpLatest['present'] + $fpLatest['absent'] }}</span> confirmed</span>
		</div>
		<ul class="fp-session-chips">
			<li class="fp-chip is-present"><span class="tnum">{{ $fpLatest['present'] }}</span> Present</li>
			<li class="fp-chip is-absent"><span class="tnum">{{ $fpLatest['absent'] }}</span> Absent</li>
			<li class="fp-chip is-excused"><span class="tnum">{{ $fpLatest['excused'] }}</span> Excused</li>
			<li class="fp-chip is-unmarked"><span class="tnum">{{ $fpLatest['not_marked'] }}</span> {{ \App\Support\FeedingAttendanceMark::NOT_RECORDED }}</li>
			@if ($fpLatest['unconfirmed'] > 0)
				<li class="fp-chip is-pending"><span class="tnum">{{ $fpLatest['unconfirmed'] }}</span> Awaiting review</li>
			@endif
		</ul>
	</div>

	<div class="fp-turnout">
		<div class="fp-chart-head">
			<span class="fp-chart-title">Turnout &middot; last {{ count($fpRecent) }} {{ \Illuminate\Support\Str::plural('session', count($fpRecent)) }}</span>
			<span class="fp-chart-key">present of confirmed</span>
		</div>
		{{-- One row per session, newest first: date, a bar on a 0–100% track,
		     the rate, and the count it was taken over. Rows cannot collide the
		     way labels over narrow columns did, so every figure stays legible
		     at any width. --}}
		<ol class="fp-turnout-list" aria-label="Turnout by feeding session">
			@foreach (array_reverse($fpRecent) as $day)
				@php $confirmed = $day['present'] + $day['absent']; @endphp
				<li class="fp-turnout-row">
					<span class="fp-turnout-date tnum">{{ $day['label'] }}</span>
					<span class="fp-track"
						data-tip-title="{{ $day['long_label'] }}"
						data-tip="{{ $day['rate'] === null ? 'No confirmed marks' : $day['rate'].'% present · '.$day['present'].' of '.$confirmed.' confirmed' }}"
						aria-label="{{ $day['long_label'] }}: {{ $day['rate'] === null ? 'no confirmed marks' : $day['rate'].'% present' }}">
						@if ($day['rate'] !== null)
							<span class="fp-track-fill is-count" style="width: {{ max(0, min(100, $day['rate'])) }}%"></span>
						@endif
					</span>
					<span class="fp-turnout-value tnum">{{ $day['rate'] === null ? '—' : $day['rate'].'%' }}</span>
					<span class="fp-turnout-count tnum">{{ $day['present'] }}/{{ $confirmed }}</span>
				</li>
			@endforeach
		</ol>

		<details class="fp-table-view">
			<summary>Table view</summary>
			<div class="table-scroll">
				<table>
					<thead>
						<tr>
							<th>Date</th>
							<th class="num">Present</th>
							<th class="num">Absent</th>
							<th class="num">Excused</th>
							<th class="num">{{ \App\Support\FeedingAttendanceMark::NOT_RECORDED }}</th>
							<th class="num">Turnout</th>
						</tr>
					</thead>
					<tbody>
						@foreach (array_reverse($fpRecent) as $day)
							<tr>
								<td class="tnum">{{ $day['long_label'] }}</td>
								<td class="num">{{ $day['present'] }}</td>
								<td class="num">{{ $day['absent'] }}</td>
								<td class="num">{{ $day['excused'] }}</td>
								<td class="num">{{ $day['not_marked'] }}</td>
								<td class="num">{{ $day['rate'] === null ? '—' : $day['rate'].'%' }}</td>
							</tr>
						@endforeach
					</tbody>
				</table>
			</div>
		</details>
	</div>
@endif
