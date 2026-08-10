{{--
    The school head's headline numbers. Rendered on first paint and re-rendered
    into #sh-stats by the live refresh, so there is one copy of the markup.
    Needs $stats.

    Semantic accents, not decoration: the roll is brand green, the programme
    count is the neutral-information teal, and the wasted rate is the one
    number that means "act" — so it wears the at-risk orange.
--}}
<article class="card kpi accent-brand">
    <div class="kpi-top">
        <div class="kpi-label">Total Students</div>
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
    </div>
    <div class="kpi-value">{{ number_format($stats['total_students'] ?? 0) }}</div>
    <div class="kpi-hint">Enrolled this school year</div>
</article>
<article class="card kpi accent-info">
    <div class="kpi-top">
        <div class="kpi-label">Active Programs</div>
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></div>
    </div>
    <div class="kpi-value">{{ $stats['active_programs'] ?? 0 }}</div>
    <div class="kpi-hint">Of 3 school health programs</div>
</article>
<article class="card kpi accent-orange">
    <div class="kpi-top">
        <div class="kpi-label">Wasted Rate</div>
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg></div>
    </div>
    <div class="kpi-value">{{ $stats['wasted_rate'] ?? '0%' }}</div>
    <div class="kpi-hint">{{ number_format($stats['wasted_count'] ?? 0) }} of {{ number_format($stats['total_students'] ?? 0) }} students</div>
</article>
