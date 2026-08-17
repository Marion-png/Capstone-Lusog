{{--
    The consolidated baseline-to-endline picture over the whole roster.

    A learner nobody weighed is carried in its own row and never folded into
    Normal — "5,585 Normal" must not include children no one has looked at — and
    the endline column stays empty until somebody has actually taken a closing
    reading, because a column of zeros would read as "every learner left this
    category". Needs $nutrition.
--}}
@php
    $shPct = fn ($value) => $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 1), '0'), '.') . '%';
@endphp

<div class="table-card">
    <table class="sh-table">
        <thead>
            <tr>
                <th>Category</th>
                <th class="num">Baseline</th>
                <th class="num">Share</th>
                <th class="num">Endline</th>
                <th class="num">Share</th>
                <th class="num">Change</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($nutrition['rows'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="num tnum">{{ number_format($row['baseline']) }}</td>
                    <td class="num tnum">{{ $shPct($row['baseline_share']) }}</td>
                    <td class="num tnum">{{ $nutrition['has_endline'] ? number_format($row['endline']) : '—' }}</td>
                    <td class="num tnum">{{ $nutrition['has_endline'] ? $shPct($row['endline_share']) : '—' }}</td>
                    <td class="num tnum">
                        @if (! $nutrition['has_endline'] || $row['change'] === null)
                            —
                        @else
                            <span class="sh-delta {{ $row['change'] < 0 ? 'is-down' : ($row['change'] > 0 ? 'is-up' : '') }}">
                                {{ $row['change'] > 0 ? '+' : '' }}{{ number_format($row['change']) }}
                            </span>
                        @endif
                    </td>
                </tr>
            @endforeach
            <tr class="sh-total-row">
                <td>{{ \App\Support\SchoolHeadOverview::NOT_MEASURED }}</td>
                <td class="num tnum">{{ number_format($nutrition['not_measured']) }}</td>
                <td class="num">—</td>
                <td class="num tnum">{{ number_format($nutrition['total'] - $nutrition['endline_measured']) }}</td>
                <td class="num">—</td>
                <td class="num">—</td>
            </tr>
        </tbody>
    </table>
</div>

<div class="sh-figs sh-figs-tight">
    <div class="sh-fig">
        <div class="sh-fig-label">Measured at Baseline</div>
        <div class="sh-fig-value tnum">{{ number_format($nutrition['baseline_measured']) }}</div>
        <div class="sh-fig-hint">of {{ number_format($nutrition['total']) }} on the roll</div>
    </div>
    <div class="sh-fig">
        <div class="sh-fig-label">Measured at Endline</div>
        <div class="sh-fig-value tnum">{{ number_format($nutrition['endline_measured']) }}</div>
        <div class="sh-fig-hint">of {{ number_format($nutrition['total']) }} on the roll</div>
    </div>
    <div class="sh-fig">
        <div class="sh-fig-label">Nutritional Improvement</div>
        <div class="sh-fig-value tnum">{{ $shPct($nutrition['improved_rate']) }}</div>
        <div class="sh-fig-hint">{{ number_format($nutrition['improved']) }} of {{ number_format($nutrition['beneficiaries']) }} beneficiaries</div>
    </div>
    <div class="sh-fig">
        <div class="sh-fig-label">Rehabilitation Rate</div>
        <div class="sh-fig-value tnum">{{ $shPct($nutrition['rehabilitation_rate']) }}</div>
        <div class="sh-fig-hint">
            @if ($nutrition['beneficiaries'] === 0)
                No beneficiaries enrolled yet
            @elseif ($nutrition['endline_measured'] === 0)
                No endline measurement recorded yet
            @else
                {{ number_format($nutrition['rehabilitated']) }} of {{ number_format($nutrition['beneficiaries']) }} at Normal
            @endif
        </div>
    </div>
</div>
