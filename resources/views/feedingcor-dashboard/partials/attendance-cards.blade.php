{{-- The session's four figures, the programme's turnout beside them, and where
     the school's rule currently stands.

     These are attendance figures and nothing else — no BMI, no baseline, no
     nutritional status. Who was fed on this day, and how the programme is doing
     across every day so far. Two different questions, so the cumulative rate is
     labelled as its own thing and carries the number of recorded sessions it
     was taken over: 25% over four sheets is a very different claim from 25%
     over forty.

     A session nobody has recorded has no rate: the card reads an em dash, never
     0%, because no evidence is not a turnout of nothing. --}}
@php
	$t = $tally;
	$rateLabel = $t['rate'] !== null ? number_format($t['rate'], 1).'%' : '—';
	$cumulativeLabel = $cumulative['rate'] !== null ? number_format($cumulative['rate'], 1).'%' : '—';
	$rateAccent = match (true) {
		$t['rate'] === null => 'accent-info',
		$t['rate'] < $atRisk['threshold'] => 'accent-orange',
		default => 'accent-success',
	};
	// The at-risk card stays quiet while the observation window is still
	// holding everyone back — nobody is flagged, so nothing is wrong yet.
	$riskAccent = match (true) {
		$atRisk['count'] > 0 => 'accent-danger',
		$atRisk['observing'] > 0 => 'accent-info',
		default => 'accent-success',
	};
@endphp

<article class="card kpi accent-brand">
	<div class="kpi-top">
		<div class="kpi-label">Beneficiaries</div>
		<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
	</div>
	<div class="kpi-value">{{ $beneficiaryCount }}</div>
	<div class="kpi-hint">Enrolled for feeding</div>
</article>

<article class="card kpi accent-success">
	<div class="kpi-top">
		<div class="kpi-label">Present {{ $isToday ? 'Today' : 'This Session' }}</div>
		<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg></div>
	</div>
	<div class="kpi-value">{{ $t['present'] }}</div>
	<div class="kpi-hint">{{ $selectedDateLabel }}</div>
</article>

<article class="card kpi accent-danger">
	<div class="kpi-top">
		<div class="kpi-label">Absent {{ $isToday ? 'Today' : 'This Session' }}</div>
		<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></div>
	</div>
	<div class="kpi-value">{{ $t['absent'] }}</div>
	<div class="kpi-hint">{{ $t['unmarked'] > 0 ? $t['unmarked'].' not yet recorded' : 'All beneficiaries recorded' }}</div>
</article>

<article class="card kpi {{ $rateAccent }}">
	<div class="kpi-top">
		<div class="kpi-label">Attendance Rate</div>
		<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="m19 9-5 5-4-4-3 3"/></svg></div>
	</div>
	<div class="kpi-value">{{ $rateLabel }}</div>
	{{-- The programme's own turnout, which is a different figure from this
	     session's, named so it can never be read as one — and over how many
	     recorded sessions, which is what makes it readable at all. --}}
	<div class="kpi-hint">Cumulative: {{ $cumulativeLabel }} &middot; {{ $cumulative['sessions'] }} recorded {{ \Illuminate\Support\Str::plural('session', $cumulative['sessions']) }}</div>
</article>

<article class="card kpi {{ $riskAccent }}">
	<div class="kpi-top">
		<div class="kpi-label">At Risk</div>
		<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg></div>
	</div>
	<div class="kpi-value">{{ $atRisk['count'] }}</div>
	{{-- Learners the observation window is still holding back are named here
	     rather than added to the figure above: they are unclassified, not
	     flagged, and the two must never be summed. --}}
	<div class="kpi-hint">
		@if ($atRisk['observing'] > 0)
			{{ $atRisk['observing'] }} in early monitoring
		@else
			Below {{ $atRisk['thresholdLabel'] }}% attendance
		@endif
	</div>
</article>
