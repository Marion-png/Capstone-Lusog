{{--
    The School Head's four headline numbers. Rendered on first paint and
    re-rendered into #sh-stats by the live refresh, so there is one copy of the
    markup. Needs $stats.

    Semantic accents, not decoration: the roll is brand green, the children the
    programme exists to treat wear the at-risk orange, the outcome wears the
    healthy green and the turnout the neutral-information teal.

    Every figure that could be a division by zero prints an em dash instead —
    a rehabilitation rate with no beneficiaries is undefined, not 0%.
--}}
@php
    $shChange = (int) ($stats['undernourished_change'] ?? 0);
    $shRate = $stats['rehabilitation_rate'] ?? null;
    $shTurnout = $stats['turnout'] ?? null;
    $shDash = fn ($value) => $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 1), '0'), '.') . '%';
@endphp
<article class="card kpi accent-brand">
    <div class="kpi-top">
        <div class="kpi-label">Learners enrolled</div>
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
    </div>
    <div class="kpi-value">{{ number_format($stats['total_students'] ?? 0) }}</div>
    <div class="kpi-hint">Across {{ number_format($stats['sections'] ?? 0) }} {{ \Illuminate\Support\Str::plural('section', $stats['sections'] ?? 0) }}</div>
</article>

<article class="card kpi accent-orange">
    <div class="kpi-top">
        <div class="kpi-label">Still undernourished</div>
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg></div>
    </div>
    <div class="kpi-value">{{ number_format($stats['undernourished'] ?? 0) }}</div>
    <div class="kpi-hint">
        @if ($shChange < 0)
            {{ abs($shChange) }} fewer than at baseline
        @elseif ($shChange > 0)
            {{ $shChange }} more than at baseline
        @else
            Unchanged since baseline ({{ number_format($stats['undernourished_baseline'] ?? 0) }})
        @endif
    </div>
</article>

<article class="card kpi accent-success">
    <div class="kpi-top">
        <div class="kpi-label">Rehabilitation rate</div>
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="m19 9-5 5-4-4-3 3"/></svg></div>
    </div>
    <div class="kpi-value">{{ $shDash($shRate) }}</div>
    <div class="kpi-hint">
        @if (($stats['beneficiaries'] ?? 0) === 0)
            No beneficiaries enrolled yet
        @elseif (($stats['endline_measured'] ?? 0) === 0)
            No endline measurement recorded yet
        @else
            {{ number_format($stats['rehabilitated'] ?? 0) }} of {{ number_format($stats['beneficiaries']) }} at Normal &middot;
            {{ number_format($stats['endline_measured']) }} measured
        @endif
    </div>
</article>

<article class="card kpi accent-info">
    <div class="kpi-top">
        <div class="kpi-label">Average turnout</div>
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="m9 16 2 2 4-4"/></svg></div>
    </div>
    <div class="kpi-value">{{ $shDash($shTurnout) }}</div>
    <div class="kpi-hint">
        @if (($stats['turnout_days'] ?? 0) === 0)
            No feeding day recorded yet
        @else
            Across {{ number_format($stats['turnout_days']) }} recorded feeding {{ \Illuminate\Support\Str::plural('day', $stats['turnout_days']) }}
        @endif
    </div>
</article>
