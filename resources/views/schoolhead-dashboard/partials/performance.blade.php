{{--
    Health programme performance: five shares, each on the same 0–100 track so
    the bars are comparable down the column, each direct-labelled with its
    figure and the counts behind it.

    A percentage's scale is not the data's to choose, so the track is always the
    full hundred — a truncated one would exaggerate every gap.

    Four of the five read "higher is better" and take the healthy series; the
    at-risk rate is the one where a long bar is the bad news, so it takes the
    risk series. Colour is the second signal only: the row label and the figure
    say which is which without it. Needs $performance.
--}}
<ul class="sh-perf">
    @foreach ($performance as $row)
        @php
            $value = $row['value'];
            $shown = $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 1), '0'), '.') . '%';
        @endphp
        <li class="sh-perf-row">
            <div class="sh-perf-label">{{ $row['label'] }}</div>
            <div class="sh-perf-track">
                @if ($value !== null)
                    <span class="sh-perf-fill is-{{ $row['tone'] }}" style="width: {{ min(100, max(0, (float) $value)) }}%"></span>
                @endif
            </div>
            <div class="sh-perf-value tnum">{{ $shown }}</div>
            <div class="sh-perf-detail tnum">{{ $row['detail'] ?? '—' }}</div>
        </li>
    @endforeach
</ul>
