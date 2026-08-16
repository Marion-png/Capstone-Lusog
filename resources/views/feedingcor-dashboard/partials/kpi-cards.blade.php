{{-- Four white cards, one semantic accent each: brand green for the headcount,
     teal for the neutral participation measure, orange for at-risk, amber for
     what is still waiting on the coordinator. Rendered on first paint and on
     every live refresh, so the two can never drift apart. --}}
<article class="card kpi accent-brand">
	<div class="kpi-top">
		<div class="kpi-label">Beneficiary</div>
		<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
	</div>
	<div class="kpi-value">{{ $dashboardStats['beneficiaries'] ?? 0 }}</div>
	{{-- The programme is Junior High only, so the card names the grades it
	     covers rather than splitting a roll that has only one half. --}}
	<div class="kpi-hint">{{ $dashboardStats['beneficiaries_grade_range'] ?? \App\Http\Controllers\FeedingCoordinatorController::gradeRangeLabel() }}</div>
</article>
<article class="card kpi accent-info">
	<div class="kpi-top">
		<div class="kpi-label">Attendance</div>
		<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="8" height="4" x="8" y="2" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/></svg></div>
	</div>
	{{-- Null is not 0%: no confirmed session means no turnout to report. --}}
	<div class="kpi-value">{{ !is_null($dashboardStats['attendance_rate'] ?? null) ? $dashboardStats['attendance_rate'].'%' : '—' }}</div>
	<div class="kpi-hint">{{ $dashboardStats['attendance_sessions'] ?? 0 }} confirmed session{{ ($dashboardStats['attendance_sessions'] ?? 0) === 1 ? '' : 's' }}</div>
</article>
<article class="card kpi accent-orange">
	<div class="kpi-top">
		<div class="kpi-label">At-Risk</div>
		<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg></div>
	</div>
	<div class="kpi-value">{{ $dashboardStats['at_risk'] ?? 0 }}</div>
	<div class="kpi-hint">{{ ucfirst($dashboardStats['at_risk_rule'] ?? '') }}</div>
</article>
<article class="card kpi accent-amber">
	<div class="kpi-top">
		<div class="kpi-label">Awaiting Enrollment</div>
		<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg></div>
	</div>
	<div class="kpi-value">{{ $dashboardStats['awaiting_enrollment'] ?? 0 }}</div>
	<div class="kpi-hint">Qualified, no session recorded</div>
</article>
