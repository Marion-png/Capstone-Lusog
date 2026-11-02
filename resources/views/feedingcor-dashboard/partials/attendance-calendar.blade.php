{{-- The month at a glance: which days were fed, which are finished, and which
     are still missing marks — the fastest way to spot a gap in a 120-day
     programme.

     A day with no session is simply a day: blank, not an absence. Clicking a
     weekday that has happened opens what happened on it — who was present,
     who was absent and why, who was excused, who nobody marked — in the day
     dialog (partials/attendance-day-modal), with the way to that day's sheet
     at its foot. A day still ahead has nothing to report and opens nothing.

     Saturdays and Sundays are locked: nobody is fed on a weekend, so there is
     no sheet worth opening, and a click there only ever led to a notice. They
     are never links. A mark written on one before the weekend guard existed
     still shows its glyph, so a recorded day is never made invisible — it is
     read as the Monday after, and that is the sheet to open.

     A weekday the school calendar closes (a public holiday, a suspension, a
     break — App\Support\SchoolCalendar) is locked the same way, tagged with
     its kind. Hovering or focusing it names the day — which holiday, which
     suspension — in a tooltip, and the name is its accessible label too. --}}
@php
	$weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
	$stateBadge = [
		'complete' => ['fa-day-complete', '✓'],
		'partial' => ['fa-day-partial', '◐'],
		'none' => ['', ''],
	];
	$closureTag = [
		\App\Support\SchoolCalendar::KIND_HOLIDAY => 'Holiday',
		\App\Support\SchoolCalendar::KIND_SUSPENSION => 'Suspended',
		\App\Support\SchoolCalendar::KIND_BREAK => 'Break',
	];
	$calendarDays = $calendarDetail['days'] ?? null;
@endphp

<section class="card fa-calendar">
	<div class="card-head">
		<div>
			<h2 class="card-title">{{ $calendar['label'] }}</h2>
		</div>
		<div class="fa-legend">
			<span><i class="fa-key fa-day-complete"></i>Complete</span>
			<span><i class="fa-key fa-day-partial"></i>Incomplete</span>
			<span><i class="fa-key"></i>No session</span>
			<span><i class="fa-key fa-key-closed"></i>Holiday / No classes</span>
		</div>
	</div>

	<div class="fa-cal-grid" role="grid" aria-label="Feeding days in {{ $calendar['label'] }}">
		@foreach ($weekdays as $i => $weekday)
			<div class="fa-cal-head {{ $i >= 5 ? 'is-weekend' : '' }}" role="columnheader">{{ $weekday }}</div>
		@endforeach

		@foreach ($calendar['weeks'] as $week)
			@foreach ($week as $day)
				@php
					[$dayClass, $glyph] = $stateBadge[$day['state']];
					// A day the dialog can open: a weekday that has happened and
					// that the school did not close.
					$opens = $calendarDays !== null && isset($calendarDays[$day['date']]);
				@endphp
				@if (! $day['in_month'])
					<div class="fa-cal-day is-outside" role="gridcell" aria-hidden="true"></div>
				@elseif ($day['is_weekend'])
					<div class="fa-cal-day is-weekend is-locked {{ $day['is_today'] ? 'is-today' : '' }}" role="gridcell"
						aria-disabled="true"
						aria-label="{{ \Carbon\Carbon::parse($day['date'])->format('l, F j') }} — no feeding on weekends">
						<span class="fa-cal-num">{{ $day['day'] }}</span>
						@if ($day['state'] !== 'none')
							<span class="fa-cal-mark">{{ $glyph }}</span>
						@endif
					</div>
				@elseif (! empty($day['closure']))
					{{-- Focusable so a keyboard reaches the same tooltip a pointer
					     does; it is still locked, and opens nothing. --}}
					<div class="fa-cal-day is-closed is-locked {{ $day['is_today'] ? 'is-today' : '' }}" role="gridcell"
						tabindex="0"
						aria-disabled="true"
						data-closure-name="{{ $day['closure']['name'] }}"
						aria-label="{{ \Carbon\Carbon::parse($day['date'])->format('l, F j') }} — {{ $day['closure']['name'] }}, no feeding">
						<span class="fa-cal-num">{{ $day['day'] }}</span>
						@if ($day['state'] !== 'none')
							<span class="fa-cal-mark">{{ $glyph }}</span>
						@else
							<span class="fa-cal-tag">{{ $closureTag[$day['closure']['kind']] ?? 'Closed' }}</span>
						@endif
						<span class="fa-cal-tip" role="tooltip" aria-hidden="true">
							<span class="fa-cal-tip-kind">{{ $closureTag[$day['closure']['kind']] ?? 'Closed' }}</span>
							<span class="fa-cal-tip-name">{{ $day['closure']['name'] }}</span>
						</span>
					</div>
				@elseif ($opens)
					<button type="button"
						class="fa-cal-day is-open {{ $dayClass }} {{ $day['is_today'] ? 'is-today' : '' }} {{ $day['is_selected'] ? 'is-selected' : '' }}"
						role="gridcell"
						data-day-open="{{ $day['date'] }}"
						@if ($day['state'] !== 'none') data-sheet-url="{{ $pageUrl(['view' => 'sheet', 'date' => $day['date']]) }}" @endif
						aria-haspopup="dialog"
						aria-label="{{ \Carbon\Carbon::parse($day['date'])->format('l, F j') }} — {{ $day['state'] === 'none' ? 'no attendance recorded' : $day['recorded'].' recorded' }}">
						<span class="fa-cal-num">{{ $day['day'] }}</span>
						@if ($day['state'] !== 'none')
							<span class="fa-cal-mark">{{ $glyph }}</span>
						@endif
					</button>
				@else
					{{-- Still ahead: nothing has happened, so nothing opens. --}}
					<div class="fa-cal-day {{ $day['is_today'] ? 'is-today' : '' }} {{ $day['is_selected'] ? 'is-selected' : '' }}" role="gridcell">
						<span class="fa-cal-num">{{ $day['day'] }}</span>
					</div>
				@endif
			@endforeach
		@endforeach
	</div>

	{{-- The month's detail rides inside the pane, so the live refresh that
	     redraws the grid replaces what the dialog reads in the same swap. --}}
	@if ($calendarDetail ?? null)
		<script type="application/json" data-calendar-detail>@json($calendarDetail)</script>
	@endif
</section>
