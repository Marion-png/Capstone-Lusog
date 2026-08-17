{{--
    Health services consent: how complete the school is, and which sections are
    furthest behind. Standings only — a form's contents belong to the adviser
    and the nurse who act on them. Needs $consent.

    The sections listed are the worst-completing ones: the row a head has to
    chase is the row at the top.
--}}
@php
    $shPct = fn ($value) => $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 1), '0'), '.') . '%';
    $shSections = array_slice($consent['sections'], 0, 5);
@endphp

<div class="sh-figs">
    <div class="sh-fig">
        <div class="sh-fig-label">Students Requiring Consent</div>
        <div class="sh-fig-value tnum">{{ number_format($consent['required']) }}</div>
    </div>
    <div class="sh-fig">
        <div class="sh-fig-label">Valid Consent</div>
        <div class="sh-fig-value tnum">{{ number_format($consent['valid']) }}</div>
    </div>
    <div class="sh-fig">
        <div class="sh-fig-label">Missing Consent</div>
        <div class="sh-fig-value tnum">{{ number_format($consent['missing']) }}</div>
    </div>
    <div class="sh-fig">
        <div class="sh-fig-label">Completion Rate</div>
        <div class="sh-fig-value tnum">{{ $shPct($consent['rate']) }}</div>
    </div>
</div>

@if (empty($shSections))
    <div class="sh-empty">No learner on the roll for this school year.</div>
@else
    <ul class="sh-meter">
        @foreach ($shSections as $row)
            <li class="sh-meter-row">
                <span class="sh-meter-label">{{ $row['grade'] }} &middot; {{ $row['section'] }}</span>
                <span class="sh-meter-track">
                    <span class="sh-meter-fill is-healthy" style="width: {{ $row['rate'] ?? 0 }}%"></span>
                </span>
                <span class="sh-meter-value tnum">{{ $row['valid'] }}/{{ $row['required'] }}</span>
                <span class="sh-meter-share tnum">{{ $shPct($row['rate']) }}</span>
            </li>
        @endforeach
    </ul>
@endif

<a class="btn btn-secondary sh-panel-action" href="{{ route('dashboard.school-head.consent', ['state' => 'none']) }}">
    View students without valid consent
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
</a>
