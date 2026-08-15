{{-- The month at a glance: which days were fed, which are finished, and which
     are still missing marks — the fastest way to spot a gap in a 120-day
     programme.

     A day with no session is simply a day: blank, not an absence. Weekends are
     drawn like any other day so a session recorded on one is never invisible.
     Clicking a day opens its sheet. --}}
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
		@foreach ($weekdays as $weekday)
			<div class="fa-cal-head" role="columnheader">{{ $weekday }}</div>
		@endforeach

		@foreach ($calendar['weeks'] as $week)
			@foreach ($week as $day)
				@php [$dayClass, $glyph] = $stateBadge[$day['state']]; @endphp
				@if (! $day['in_month'])
					<div class="fa-cal-day is-outside" role="gridcell" aria-hidden="true"></div>
				@elseif ($day['state'] === 'none')
					{{-- Nothing was recorded, so there is no sheet to open. --}}
					<div class="fa-cal-day {{ $day['is_weekend'] ? 'is-weekend' : '' }} {{ $day['is_today'] ? 'is-today' : '' }} {{ $day['is_selected'] ? 'is-selected' : '' }}" role="gridcell">
						<span class="fa-cal-num">{{ $day['day'] }}</span>
					</div>
				@else
					<a class="fa-cal-day {{ $dayClass }} {{ $day['is_weekend'] ? 'is-weekend' : '' }} {{ $day['is_today'] ? 'is-today' : '' }} {{ $day['is_selected'] ? 'is-selected' : '' }}"
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
