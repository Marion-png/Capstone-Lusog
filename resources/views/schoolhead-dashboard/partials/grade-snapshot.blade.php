{{--
    One row per grade level: how much of it is undernourished at the latest
    weighing, and which way that has moved since the baseline.

    The bar is a share of the grade, so grades of different sizes are
    comparable; the count beside it is the number the bar is a share of, because
    a percentage on its own hides a grade of four learners. A grade nobody has
    measured shows an em dash rather than an empty bar reading as zero.

    Needs $gradeSnapshot.
--}}
@if (empty($gradeSnapshot))
    <p class="sh-empty">No learners on the roll yet. Grade rows appear once class advisers enrol them.</p>
@else
    <div class="table-card">
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Grade</th>
                        <th>Undernourished share</th>
                        <th class="num">Learners</th>
                        <th class="num">Since baseline</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($gradeSnapshot as $row)
                        <tr>
                            <td><strong>{{ $row['label'] }}</strong></td>
                            <td class="sh-bar-cell">
                                @if ($row['bar'] === null)
                                    <span class="muted">Not measured</span>
                                @else
                                    <span class="sh-bar" role="img" aria-label="{{ $row['bar'] }}% undernourished">
                                        <span class="sh-bar-fill" style="width:{{ min(100, $row['bar']) }}%"></span>
                                    </span>
                                    <span class="sh-bar-value tnum">{{ rtrim(rtrim(number_format($row['bar'], 1), '0'), '.') }}%</span>
                                @endif
                            </td>
                            <td class="num">{{ $row['undernourished'] }} / {{ $row['total'] }}</td>
                            <td class="num">
                                @if ($row['change'] < 0)
                                    <span class="sh-delta is-down">&minus;{{ abs($row['change']) }}</span>
                                @elseif ($row['change'] > 0)
                                    <span class="sh-delta is-up">+{{ $row['change'] }}</span>
                                @else
                                    <span class="muted">&mdash;</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
