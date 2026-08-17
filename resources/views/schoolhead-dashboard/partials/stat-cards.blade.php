{{--
    The executive summary: the eight school-level figures the head is
    accountable for, across every programme rather than one of them. Rendered on
    first paint and re-rendered into #sh-stats by the live refresh, so there is
    one copy of the markup. Needs $stats.

    Semantic accents, not decoration: the roll and the beneficiary count are
    brand green, clinic traffic and consent the neutral-information teal,
    referrals the monitoring amber, the children the rule has flagged the
    at-risk orange, and stock the clinic cannot dispense the critical coral.

    Every figure that could be a division by zero prints an em dash instead — a
    consent rate over an empty roster is undefined, not 0%.
--}}
@php
    $shDash = fn ($value) => $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 1), '0'), '.') . '%';
    $shBeneficiaries = (int) ($stats['beneficiaries'] ?? 0);
    $shAtRisk = (int) ($stats['at_risk'] ?? 0);
    $shAtRiskShare = $shBeneficiaries > 0 ? round(($shAtRisk / $shBeneficiaries) * 100, 1) : null;
@endphp
<article class="card kpi accent-brand">
    <div class="kpi-top">
        <div class="kpi-label">Learners Enrolled</div>
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
    </div>
    <div class="kpi-value">{{ number_format($stats['total_students'] ?? 0) }}</div>
    <div class="kpi-hint">Across {{ number_format($stats['sections'] ?? 0) }} {{ \Illuminate\Support\Str::plural('section', $stats['sections'] ?? 0) }}</div>
</article>

<article class="card kpi accent-info">
    <div class="kpi-top">
        <div class="kpi-label">Clinic Consultations</div>
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></div>
    </div>
    <div class="kpi-value">{{ number_format($stats['consultations'] ?? 0) }}</div>
    <div class="kpi-hint">{{ number_format($stats['consultations_this_month'] ?? 0) }} in {{ $stats['clinic_month_label'] ?? now()->format('F') }}</div>
</article>

<article class="card kpi accent-amber">
    <div class="kpi-top">
        <div class="kpi-label">Clinic Referrals</div>
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg></div>
    </div>
    <div class="kpi-value">{{ number_format($stats['referrals'] ?? 0) }}</div>
    <div class="kpi-hint">{{ number_format($stats['referrals_this_month'] ?? 0) }} in {{ $stats['clinic_month_label'] ?? now()->format('F') }}</div>
</article>

<article class="card kpi accent-brand">
    <div class="kpi-top">
        <div class="kpi-label">SBFP Beneficiaries</div>
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/></svg></div>
    </div>
    <div class="kpi-value">{{ number_format($shBeneficiaries) }}</div>
    <div class="kpi-hint">{{ number_format($stats['undernourished'] ?? 0) }} undernourished on the roll</div>
</article>

<article class="card kpi accent-success">
    <div class="kpi-top">
        <div class="kpi-label">SBFP Attendance</div>
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="m9 16 2 2 4-4"/></svg></div>
    </div>
    <div class="kpi-value">{{ $shDash($stats['turnout'] ?? null) }}</div>
    <div class="kpi-hint">
        @if (($stats['turnout_days'] ?? 0) === 0)
            No feeding day recorded yet
        @else
            Across {{ number_format($stats['turnout_days']) }} recorded feeding {{ \Illuminate\Support\Str::plural('day', $stats['turnout_days']) }}
        @endif
    </div>
</article>

<article class="card kpi accent-orange">
    <div class="kpi-top">
        <div class="kpi-label">At-Risk Beneficiaries</div>
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg></div>
    </div>
    <div class="kpi-value">{{ number_format($shAtRisk) }}</div>
    <div class="kpi-hint">
        @if ($shBeneficiaries === 0)
            No beneficiaries enrolled yet
        @else
            {{ $shDash($shAtRiskShare) }} of {{ number_format($shBeneficiaries) }} beneficiaries
        @endif
    </div>
</article>

<article class="card kpi accent-info">
    <div class="kpi-top">
        <div class="kpi-label">Consent Completion</div>
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="m9 15 2 2 4-4"/></svg></div>
    </div>
    <div class="kpi-value">{{ $shDash($stats['consent_rate'] ?? null) }}</div>
    <div class="kpi-hint">
        @if (($stats['total_students'] ?? 0) === 0)
            No learners on the roll
        @else
            {{ number_format($stats['consent_valid'] ?? 0) }} valid &middot; {{ number_format($stats['consent_missing'] ?? 0) }} missing
        @endif
    </div>
</article>

<article class="card kpi {{ ($stats['medicines_out'] ?? 0) > 0 ? 'accent-danger' : 'accent-amber' }}">
    <div class="kpi-top">
        <div class="kpi-label">Medicines Needing Stock</div>
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="m8.5 8.5 7 7"/></svg></div>
    </div>
    <div class="kpi-value">{{ number_format($stats['medicines_low'] ?? 0) }}</div>
    <div class="kpi-hint">
        @if (($stats['medicines_tracked'] ?? 0) === 0)
            No medicine tracked yet
        @else
            {{ number_format($stats['medicines_out'] ?? 0) }} out of stock &middot; {{ number_format($stats['medicines_tracked']) }} tracked
        @endif
    </div>
</article>
