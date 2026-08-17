{{--
    Where the 120-day cycle stands.

    Two figures, never merged: the calendar day the cycle is on, and the feeding
    days actually recorded. A school on day 40 that has recorded 12 sheets has
    fed twelve times, not forty — so the bar carries both, the recorded run as a
    solid fill inside the elapsed one.

    Needs $cycle.
--}}
@if (! $cycle['started'])
    <div class="sh-cycle is-idle">
        <div class="sh-cycle-head">
            <span class="sh-cycle-label">Feeding cycle</span>
            <span class="sh-cycle-meta">Not started</span>
        </div>
        <div class="sh-cycle-track" role="img" aria-label="Feeding cycle not started"><span class="sh-cycle-fill" style="width:0%"></span></div>
        <p class="sh-cycle-foot">Day 1 is the first recorded feeding session. None has been recorded yet.</p>
    </div>
@else
    <div class="sh-cycle">
        <div class="sh-cycle-head">
            <span class="sh-cycle-label">Feeding cycle</span>
            <span class="sh-cycle-meta tnum">Day {{ $cycle['day'] }} of {{ $cycle['duration'] }} &middot; {{ $cycle['percent'] }}%</span>
        </div>
        <div class="sh-cycle-track"
             role="img"
             aria-label="Feeding day {{ $cycle['day'] }} of {{ $cycle['duration'] }}, {{ $cycle['days_completed'] }} feeding days recorded">
            <span class="sh-cycle-fill" style="width:{{ min(100, $cycle['percent']) }}%"></span>
            <span class="sh-cycle-fed" style="width:{{ min(100, $cycle['completed_percent']) }}%"></span>
        </div>
        <p class="sh-cycle-foot tnum">
            <span class="sh-cycle-key is-fed"></span>{{ $cycle['days_completed'] }} feeding {{ \Illuminate\Support\Str::plural('day', $cycle['days_completed']) }} recorded
            <span class="sh-cycle-sep">&middot;</span>
            <span class="sh-cycle-key is-elapsed"></span>{{ $cycle['days_remaining'] }} {{ \Illuminate\Support\Str::plural('day', $cycle['days_remaining']) }} remaining
        </p>
    </div>
@endif
