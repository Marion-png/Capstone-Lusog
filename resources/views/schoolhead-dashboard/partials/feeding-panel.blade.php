{{--
    The feeding programme at a glance. Every figure is the one the coordinator's
    own tabs report — nothing here re-decides who is a beneficiary, who is at
    risk or what counts as a feeding day. Needs $feeding and $cycle.

    Today's turnout is only a figure once today is a recorded feeding day: an
    unheld day has no turnout, and 0% would claim it did.
--}}
@php
    $shPct = fn ($value) => $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 1), '0'), '.') . '%';
@endphp

<div class="sh-figs cols-3">
    <div class="sh-fig">
        <div class="sh-fig-label">Beneficiaries</div>
        <div class="sh-fig-value tnum">{{ number_format($feeding['beneficiaries']) }}</div>
    </div>
    <div class="sh-fig">
        <div class="sh-fig-label">Severely Wasted</div>
        <div class="sh-fig-value tnum">{{ number_format($feeding['severely_wasted']) }}</div>
    </div>
    <div class="sh-fig">
        <div class="sh-fig-label">Wasted</div>
        <div class="sh-fig-value tnum">{{ number_format($feeding['wasted']) }}</div>
    </div>
    <div class="sh-fig">
        <div class="sh-fig-label">Today&rsquo;s Attendance</div>
        <div class="sh-fig-value tnum">{{ $feeding['today_recorded'] ? $shPct($feeding['today_rate']) : '—' }}</div>
    </div>
    <div class="sh-fig">
        <div class="sh-fig-label">Cumulative Attendance</div>
        <div class="sh-fig-value tnum">{{ $shPct($feeding['cumulative_rate']) }}</div>
    </div>
    <div class="sh-fig">
        <div class="sh-fig-label">At Risk</div>
        <div class="sh-fig-value tnum">{{ number_format($feeding['at_risk']) }}</div>
    </div>
    <div class="sh-fig">
        <div class="sh-fig-label">Pending Enrollment</div>
        <div class="sh-fig-value tnum">{{ number_format($feeding['pending']) }}</div>
    </div>
    <div class="sh-fig">
        <div class="sh-fig-label">Endline Completion</div>
        <div class="sh-fig-value tnum">{{ $shPct($feeding['endline_rate']) }}</div>
    </div>
    <div class="sh-fig">
        <div class="sh-fig-label">Meals Served</div>
        <div class="sh-fig-value tnum">{{ number_format($feeding['meals_served']) }}</div>
    </div>
</div>
