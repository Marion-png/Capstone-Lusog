{{-- The programme's five headline figures, one semantic accent each: brand
     green for the headcount, coral for the critical status, amber for the one
     under monitoring, orange for at-risk, teal for the neutral participation
     measure. Every number comes from FeedingBeneficiarySummary — nothing here
     is stored or hand-entered, so a mark recorded at the feeding line moves
     these on the next read. Rendered on first paint and on every live refresh,
     so the two can never drift apart. --}}
@php
	$bs = $beneficiarySummary ?? [];
	$bsTotal = (int) ($bs['beneficiaries'] ?? 0);
	$bsSevere = (int) ($bs['severely_wasted'] ?? 0);
	$bsWasted = (int) ($bs['wasted'] ?? 0);
	$bsRate = $bs['attendance_rate'] ?? null;
	// A share of nobody is not 0%: with no beneficiaries enrolled there is
	// nothing to take a share of, so the hint says that instead.
	$bsShare = fn (int $count): string => $bsTotal > 0
		? round($count / $bsTotal * 100).'% of beneficiaries'
		: 'None enrolled yet';
@endphp
<article class="card kpi accent-brand">
	<div class="kpi-top">
		<div class="kpi-label">Total Beneficiaries</div>
		<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
	</div>
	<div class="kpi-value">{{ $bsTotal }}</div>
	<div class="kpi-hint">Enrolled in the programme</div>
</article>
<article class="card kpi accent-danger">
	<div class="kpi-top">
		<div class="kpi-label">Severely Wasted</div>
		<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12h4l3 8 4-16 3 8h4"/></svg></div>
	</div>
	<div class="kpi-value">{{ $bsSevere }}</div>
	<div class="kpi-hint">{{ $bsShare($bsSevere) }}</div>
</article>
<article class="card kpi accent-amber">
	<div class="kpi-top">
		<div class="kpi-label">Wasted</div>
		<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20V10"/><path d="M18 20V4"/><path d="M6 20v-4"/></svg></div>
	</div>
	<div class="kpi-value">{{ $bsWasted }}</div>
	<div class="kpi-hint">{{ $bsShare($bsWasted) }}</div>
</article>
<article class="card kpi accent-orange">
	<div class="kpi-top">
		<div class="kpi-label">At Risk</div>
		<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg></div>
	</div>
	<div class="kpi-value">{{ (int) ($bs['at_risk'] ?? 0) }}</div>
	<div class="kpi-hint">Below {{ rtrim(rtrim(number_format((float) ($bs['at_risk_threshold'] ?? 80), 1), '0'), '.') }}% attendance</div>
</article>
<article class="card kpi accent-info">
	<div class="kpi-top">
		<div class="kpi-label">Currently Attending</div>
		<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="8" height="4" x="8" y="2" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/></svg></div>
	</div>
	{{-- Null is not 0%: no confirmed session means no turnout to report. --}}
	<div class="kpi-value">{{ ! is_null($bsRate) ? $bsRate.'%' : '—' }}</div>
	<div class="kpi-hint">{{ (int) ($bs['attendance_sessions'] ?? 0) }} confirmed session{{ (int) ($bs['attendance_sessions'] ?? 0) === 1 ? '' : 's' }}</div>
</article>
