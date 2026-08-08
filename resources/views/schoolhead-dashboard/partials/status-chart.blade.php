{{--
    Nutritional status by grade — a stacked column per grade, healthy below,
    at-risk above.

    Design notes worth keeping:
    - Column heights read against the y-axis, not against the tallest bar, so a
      grade of 12 learners looks like 12 whether or not another grade has 40.
    - The two fills are separated by a 2px gap in the surface colour, never a
      border: green and amber sit close together for a protan reader, so the
      gap plus the direct total on each cap plus the table view below carry the
      reading when hue alone would not.
    - Only the column total is labelled. Per-segment numbers live in the hover
      card and the table, which is why both exist.

    Needs $gradeChart and $chartAxis.
--}}
@php
    $axisMax = $chartAxis['max'] ?? 0;
    $ticks = $chartAxis['ticks'] ?? [];
@endphp

@if (empty($gradeChart))
    <div class="chart-empty">No student data yet. Grade columns appear once class advisers enrol learners.</div>
@else
    <div class="chart-legend">
        <span class="legend-item"><i class="legend-dot legend-healthy"></i>Healthy <b>{{ number_format($chartAxis['healthy'] ?? 0) }}</b></span>
        <span class="legend-item"><i class="legend-dot legend-risk"></i>At risk <b>{{ number_format($chartAxis['risk'] ?? 0) }}</b></span>
    </div>

    <div class="chart-figure">
        <div class="chart-axis" aria-hidden="true">
            @foreach ($ticks as $i => $tick)
                <span class="axis-tick" style="bottom: {{ (count($ticks) - 1 - $i) * (100 / max(count($ticks) - 1, 1)) }}%">{{ $tick }}</span>
            @endforeach
        </div>

        <div class="chart-plot">
            <div class="chart-grid" aria-hidden="true">
                @foreach ($ticks as $i => $tick)
                    <span class="grid-line" style="bottom: {{ (count($ticks) - 1 - $i) * (100 / max(count($ticks) - 1, 1)) }}%"></span>
                @endforeach
            </div>

            <div class="chart-cols">
                @foreach ($gradeChart as $col)
                    @php $stackPct = $col['healthy_pct'] + $col['risk_pct']; @endphp
                    <div class="chart-col" tabindex="0"
                         aria-label="{{ $col['label'] }}: {{ $col['total'] }} learners, {{ $col['healthy'] }} healthy, {{ $col['risk'] }} at risk">
                        <div class="chart-tip" role="presentation" style="bottom: calc(min({{ $stackPct }}%, 58%) + 10px)">
                            <div class="tip-head">{{ $col['label'] }}</div>
                            <div class="tip-row"><i class="legend-dot legend-healthy"></i>Healthy<b>{{ $col['healthy'] }}</b></div>
                            <div class="tip-row"><i class="legend-dot legend-risk"></i>At risk<b>{{ $col['risk'] }}</b></div>
                            <div class="tip-total">{{ $col['total'] }} learners</div>
                        </div>
                        @if ($col['total'] > 0)
                            <span class="col-cap" style="bottom: calc({{ $stackPct }}% + 7px)">{{ $col['total'] }}</span>
                        @endif
                        <div class="col-stack">
                            @if ($col['healthy'] > 0)
                                <div class="col-seg seg-healthy" style="height: {{ $col['healthy_pct'] }}%"></div>
                            @endif
                            @if ($col['risk'] > 0)
                                <div class="col-seg seg-risk" style="height: {{ $col['risk_pct'] }}%"></div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="chart-axis-title" aria-hidden="true">Learners</div>
        <div class="chart-labels">
            @foreach ($gradeChart as $col)
                <span class="col-label">{{ $col['short_label'] }}</span>
            @endforeach
        </div>
    </div>

    {{-- The WCAG-clean twin: every value the chart encodes with colour is also
         readable as text, so nothing is gated behind hover or hue. --}}
    <details class="chart-table">
        <summary>Table view</summary>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Grade</th><th>Healthy</th><th>At risk</th><th>Total</th></tr>
                </thead>
                <tbody>
                    @foreach ($gradeChart as $col)
                        <tr>
                            <td>{{ $col['label'] }}</td>
                            <td>{{ $col['healthy'] }}</td>
                            <td>{{ $col['risk'] }}</td>
                            <td>{{ $col['total'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
@endif
