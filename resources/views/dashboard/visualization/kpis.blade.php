{{-- Headline figures. The two nutritional tiles carry every weighing, so the
     Weighing control moves them with the charts below. --}}
@php
    $pct = fn ($value) => \App\Support\ClinicVisualization::percent($value);
@endphp
<article class="card kpi accent-brand">
    <div class="kpi-top">
        <div class="kpi-label">Learners Measured</div>
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
    </div>
    @foreach ($kpis['phases'] as $phase => $reading)
        <div class="nv-kpi-phase" data-phase-view="{{ $phase }}">
            <div class="kpi-value">{{ number_format($reading['measured']) }}</div>
            <div class="kpi-hint">of {{ number_format($reading['total']) }} on the roll &middot; {{ $phases[$phase] }}</div>
        </div>
    @endforeach
</article>

<article class="card kpi accent-orange">
    <div class="kpi-top">
        <div class="kpi-label">Undernourished</div>
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
    </div>
    @foreach ($kpis['phases'] as $phase => $reading)
        <div class="nv-kpi-phase" data-phase-view="{{ $phase }}">
            <div class="kpi-value">{{ $pct($reading['undernourished_share']) }}</div>
            <div class="kpi-hint">
                {{ number_format($reading['undernourished']) }} {{ \Illuminate\Support\Str::plural('learner', $reading['undernourished']) }}
                @if ($reading['change'] !== null)
                    &middot;
                    @if ($reading['change'] === 0)
                        no change since baseline
                    @else
                        <span class="nv-delta {{ $reading['change'] < 0 ? 'is-good' : 'is-bad' }}">{{ $reading['change'] < 0 ? '▼' : '▲' }} {{ number_format(abs($reading['change'])) }}</span> since baseline
                    @endif
                @endif
            </div>
        </div>
    @endforeach
</article>

<article class="card kpi accent-info">
    <div class="kpi-top">
        <div class="kpi-label">Consultations</div>
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></div>
    </div>
    <div class="kpi-value">{{ number_format($kpis['consultations']) }}</div>
    <div class="kpi-hint">
        @if ($kpis['is_current_period'])
            {{ number_format($kpis['consultations_month']) }} in {{ $kpis['month_label'] }} &middot;
        @endif
        {{ number_format($kpis['referred']) }} referred
    </div>
</article>

<article class="card kpi {{ $kpis['stock_alerts'] > 0 ? 'accent-danger' : 'accent-success' }}">
    <div class="kpi-top">
        <div class="kpi-label">Stock Alerts</div>
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="m8.5 8.5 7 7"/></svg></div>
    </div>
    <div class="kpi-value">{{ number_format($kpis['stock_alerts']) }}</div>
    <div class="kpi-hint">
        @if ($kpis['stock_tracked'] === 0)
            No medicine tracked
        @else
            {{ number_format($kpis['stock_out']) }} out of stock &middot; {{ number_format($kpis['stock_monitor']) }} to monitor
        @endif
    </div>
</article>
