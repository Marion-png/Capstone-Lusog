{{-- Where the cycle stands. A feeding cycle is one measure with a known
     ceiling — the school's own length (FeedingProgramCycle::durationDays(),
     never a compiled 120) — so it is a bar, not a chart. Day 1 is the first
     recorded session and every day after is a school day: a weekend or a
     holiday is not a feeding day, so the end date is counted the way the
     coordinator's header counts it. --}}
@php
	$fpCycle = $cycle ?? [];
	$fpDay = (int) ($fpCycle['day'] ?? 0);
	$fpDuration = max(1, (int) ($fpCycle['duration'] ?? \App\Support\FeedingProgramCycle::DURATION_DAYS));
	$fpPercent = max(0, min(100, (float) ($fpCycle['percent'] ?? 0)));
	$fpStarted = (bool) ($fpCycle['started'] ?? false);
	$fpDate = fn (?string $iso): string => $iso ? \Carbon\Carbon::parse($iso)->format('M j, Y') : '—';
@endphp
<div class="fp-cycle-main">
	<span class="fp-eyebrow">School-Based Feeding Program &middot; S.Y. {{ $schoolYear }}</span>
	<div class="fp-cycle-day">
		@if ($fpStarted)
			<strong class="tnum">Day {{ $fpDay }}</strong>
			<span>of {{ $fpDuration }} feeding days</span>
		@else
			<strong>Not started</strong>
			<span>{{ $fpDuration }} feeding days in the cycle</span>
		@endif
		@if ($fpCycle['complete'] ?? false)
			<span class="badge badge-normal">Cycle complete</span>
		@endif
	</div>
	<div class="prog-track" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $fpDuration }}" aria-valuenow="{{ $fpDay }}" aria-label="Feeding day {{ $fpDay }} of {{ $fpDuration }}">
		<div class="prog-fill" style="width: {{ $fpPercent }}%;"></div>
	</div>
	<div class="prog-labels">
		<span>Day 1</span>
		<span class="prog-percent tnum">{{ number_format($fpPercent, 0) }}% complete</span>
		<span>Day {{ $fpDuration }}</span>
	</div>
</div>
<dl class="fp-cycle-facts">
	<div>
		<dt>Started</dt>
		<dd class="tnum">{{ $fpDate($fpCycle['start_date'] ?? null) }}</dd>
	</div>
	<div>
		<dt>Expected end</dt>
		<dd class="tnum">{{ $fpDate($fpCycle['end_date'] ?? null) }}</dd>
	</div>
	<div>
		<dt>Feeding days left</dt>
		<dd class="tnum">{{ $fpStarted ? (int) ($fpCycle['days_remaining'] ?? 0) : $fpDuration }}</dd>
	</div>
	<div>
		<dt>Sessions recorded</dt>
		<dd class="tnum">{{ (int) ($programStats['sessions_held'] ?? 0) }}</dd>
	</div>
</dl>
