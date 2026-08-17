{{--
    Clinic activity, aggregated: how much, how it ended, and what it was for.
    The clinical narrative belongs to the nurse — this panel carries no learner,
    no treatment and no note. Needs $clinic.

    Dispositions are the two the clinic log itself records. A share with no
    consultation behind it is an em dash, never 0%.
--}}
@php
    $shPct = fn ($value) => $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 1), '0'), '.') . '%';
@endphp

<div class="sh-figs">
    <div class="sh-fig">
        <div class="sh-fig-label">This Month</div>
        <div class="sh-fig-value tnum">{{ number_format($clinic['this_month']) }}</div>
    </div>
    <div class="sh-fig">
        <div class="sh-fig-label">This Week</div>
        <div class="sh-fig-value tnum">{{ number_format($clinic['this_week']) }}</div>
    </div>
    <div class="sh-fig">
        <div class="sh-fig-label">Learners Seen</div>
        <div class="sh-fig-value tnum">{{ number_format($clinic['learners']) }}</div>
    </div>
    <div class="sh-fig">
        <div class="sh-fig-label">Clinic Notes</div>
        <div class="sh-fig-value tnum">{{ number_format($clinic['notes']) }}</div>
    </div>
</div>

@if ($clinic['total'] === 0)
    <div class="sh-empty">No consultation logged this school year.</div>
@else
    <div class="sh-panel-sub">Disposition</div>
    <ul class="sh-meter">
        @foreach ($clinic['dispositions'] as $row)
            <li class="sh-meter-row">
                <span class="sh-meter-label">{{ $row['label'] }}</span>
                <span class="sh-meter-track">
                    <span class="sh-meter-fill {{ $row['key'] === 'referred' ? 'is-watch' : '' }}"
                          style="width: {{ $row['share'] ?? 0 }}%"></span>
                </span>
                <span class="sh-meter-value tnum">{{ number_format($row['count']) }}</span>
                <span class="sh-meter-share tnum">{{ $shPct($row['share']) }}</span>
            </li>
        @endforeach
    </ul>

    @if (! empty($clinic['categories']))
        <div class="sh-panel-sub">Complaint Category</div>
        <ul class="sh-meter">
            @foreach (array_slice($clinic['categories'], 0, 5) as $row)
                <li class="sh-meter-row">
                    <span class="sh-meter-label">{{ $row['label'] }}</span>
                    <span class="sh-meter-track"><span class="sh-meter-fill" style="width: {{ $row['pct'] }}%"></span></span>
                    <span class="sh-meter-value tnum">{{ number_format($row['count']) }}</span>
                    <span class="sh-meter-share tnum">{{ $shPct($row['share']) }}</span>
                </li>
            @endforeach
        </ul>
    @endif
@endif
