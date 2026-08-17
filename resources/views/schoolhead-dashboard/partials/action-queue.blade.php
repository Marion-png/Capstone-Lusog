{{--
    The action queue — the core of the Dashboard.

    Every item is generated from a live condition in the school's own records:
    there is no stored to-do list, and an item disappears the moment the
    condition stops being true. Each carries a severity, what happened, the
    figure behind it and the tab where it is resolved.

    Severity is never colour alone: the rail's tint is paired with a word, so
    the queue survives greyscale and a colour-vision-deficient reader.

    The empty state is a real answer, never a blank box. Needs $queue and $cycle.
--}}
@php
    $shSeverity = [
        'high' => ['label' => 'Needs action', 'badge' => 'badge-critical'],
        'medium' => ['label' => 'Monitor', 'badge' => 'badge-monitor'],
        'info' => ['label' => 'Upcoming', 'badge' => 'badge-info'],
    ];
@endphp

@if (empty($queue))
    <div class="sh-queue-clear">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
        <div>
            <strong>Nothing needs your attention right now.</strong>
            <span>
                @if (! $cycle['started'])
                    The feeding cycle starts on the first recorded feeding day.
                @elseif ($cycle['days_remaining'] > 0)
                    The cycle is on day {{ $cycle['day'] }} of {{ $cycle['duration'] }}, with {{ $cycle['days_remaining'] }} {{ \Illuminate\Support\Str::plural('day', $cycle['days_remaining']) }} to run.
                @else
                    The cycle has run its full {{ $cycle['duration'] }} days.
                @endif
            </span>
        </div>
    </div>
@else
    <ul class="sh-queue">
        @foreach ($queue as $item)
            @php $tone = $shSeverity[$item['severity']] ?? $shSeverity['info']; @endphp
            <li class="sh-queue-item is-{{ $item['severity'] }}">
                <div class="sh-queue-body">
                    <span class="badge {{ $tone['badge'] }}">{{ $tone['label'] }}</span>
                    <div class="sh-queue-text">
                        <strong>{{ $item['title'] }}</strong>
                        <span>{{ $item['detail'] }}</span>
                    </div>
                </div>
                <a class="btn btn-secondary sh-queue-action" href="{{ $item['action']['url'] }}">
                    {{ $item['action']['label'] }}
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                </a>
            </li>
        @endforeach
    </ul>
@endif
