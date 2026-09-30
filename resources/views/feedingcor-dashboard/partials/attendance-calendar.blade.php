{{-- The month at a glance: which days were fed, which are finished, and which
     are still missing marks — the fastest way to spot a gap in a 120-day
     programme.

     A day with no session is simply a day: blank, not an absence. Clicking a
     weekday opens its sheet.

     Saturdays and Sundays are locked: nobody is fed on a weekend, so there is
     no sheet worth opening, and a click there only ever led to a notice. They
     are never links. A mark written on one before the weekend guard existed
     still shows its glyph, so a recorded day is never made invisible — it is
     read as the Monday after, and that is the sheet to open. --}}
@php
	$weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
	$stateBadge = [
		'complete' => ['fa-day-complete', '✓'],
		'partial' => ['fa-day-partial', '◐'],
		'none' => ['', ''],
	];
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
		</div>
	</div>

	<div class="fa-cal-grid" role="grid" aria-label="Feeding days in {{ $calendar['label'] }}">
		@foreach ($weekdays as $i => $weekday)
			<div class="fa-cal-head {{ $i >= 5 ? 'is-weekend' : '' }}" role="columnheader">{{ $weekday }}</div>
		@endforeach

		@foreach ($calendar['weeks'] as $week)
			@foreach ($week as $day)
				@php [$dayClass, $glyph] = $stateBadge[$day['state']]; @endphp
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
				@elseif ($day['state'] === 'none')
					{{-- Nothing was recorded, so there is no sheet to open. --}}
					<div class="fa-cal-day {{ $day['is_today'] ? 'is-today' : '' }} {{ $day['is_selected'] ? 'is-selected' : '' }}" role="gridcell">
						<span class="fa-cal-num">{{ $day['day'] }}</span>
					</div>
				@else
					<a class="fa-cal-day {{ $dayClass }} {{ $day['is_today'] ? 'is-today' : '' }} {{ $day['is_selected'] ? 'is-selected' : '' }}"
						role="gridcell"
						href="{{ $pageUrl(['view' => 'sheet', 'date' => $day['date']]) }}"
						aria-label="{{ \Carbon\Carbon::parse($day['date'])->format('F j') }} — {{ $day['recorded'] }} recorded">
						<span class="fa-cal-num">{{ $day['day'] }}</span>
						<span class="fa-cal-mark">{{ $glyph }}</span>
					</a>
				@endif
			@endforeach
		@endforeach
	</div>
</section>
