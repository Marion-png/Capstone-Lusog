{{-- The four figures the tab opens on.

     "At-Risk Students" counts FLAGGED learners and nothing else: the threshold
     reached AND the coordinator's confirmation on record. Learners awaiting
     that confirmation, and those under an attendance notice, are named on the
     hint line rather than added to the count — neither has been flagged, and
     folding them in would make this tab report a bigger programme problem than
     the Dashboard and the Beneficiaries tab, which count the same reading.

     The threshold card prints the school's configured values, never a
     constant: a System Admin moving them moves this card. --}}
@php
	$c = $cards;
	$rateLabel = $c['at_risk_rate'] !== null ? number_format($c['at_risk_rate'], 0).'%' : '—';
	$riskAccent = match (true) {
		$c['at_risk'] > 0 => 'accent-orange',
		$c['awaiting'] > 0 || $c['notices'] > 0 => 'accent-amber',
		default => 'accent-success',
	};
@endphp

<article class="card kpi {{ $riskAccent }}">
	<div class="kpi-top">
		<div class="kpi-label">At-Risk Students</div>
		<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg></div>
	</div>
	<div class="kpi-value">{{ $c['at_risk'] }}</div>
	<div class="kpi-hint">{{ $c['awaiting'] }} awaiting confirmation &middot; {{ $c['notices'] }} {{ \Illuminate\Support\Str::plural('notice', $c['notices']) }}</div>
</article>

<article class="card kpi accent-brand">
	<div class="kpi-top">
		<div class="kpi-label">Total Beneficiaries</div>
		<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
	</div>
	<div class="kpi-value">{{ $c['beneficiaries'] }}</div>
	<div class="kpi-hint">Enrolled for feeding</div>
</article>

<article class="card kpi {{ $c['at_risk'] > 0 ? 'accent-amber' : 'accent-success' }}">
	<div class="kpi-top">
		<div class="kpi-label">At-Risk Rate</div>
		<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="m19 9-5 5-4-4-3 3"/></svg></div>
	</div>
	<div class="kpi-value">{{ $rateLabel }}</div>
	<div class="kpi-hint">{{ $c['at_risk'] }} of {{ $c['beneficiaries'] }} beneficiaries</div>
</article>

<article class="card kpi accent-info">
	<div class="kpi-top">
		<div class="kpi-label">Attendance Threshold</div>
		<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 22h16"/><path d="M12 2v10"/><path d="m8 6 4-4 4 4"/><path d="M4 12h16v4a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4Z"/></svg></div>
	</div>
	<div class="kpi-value">{{ $c['threshold_label'] }}</div>
	<div class="kpi-hint">Notice at {{ $c['notice_label'] }}</div>
</article>
