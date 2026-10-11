{{-- Nutritional status, part to whole. The ring is the learners actually
     measured; nobody weighed is counted beside it, never folded into Normal. --}}
@php
    $pct = fn ($value) => \App\Support\ClinicVisualization::percent($value);
@endphp
<div class="nv-head">
    <div>
        <h2 class="nv-title">Nutritional Status</h2>
        <p class="nv-meta">Source: Student Profile Module (BMI-for-age from height and weight)</p>
    </div>
    <div class="nv-tools">
        @include('dashboard.visualization.table-toggle')
    </div>
</div>
<div class="nv-body">
    @foreach ($nutrition as $phase => $reading)
        <div data-phase-view="{{ $phase }}">
            @if ($reading['total'] === 0)
                <div class="nv-empty">No learner on the roll for S.Y. {{ $schoolYear }}.</div>
            @elseif ($reading['measured'] === 0)
                <div class="nv-empty">No {{ strtolower($reading['label']) }} weighing recorded yet.</div>
            @else
                @php
                    $ariaParts = collect($reading['rows'])
                        ->filter(fn ($row) => $row['count'] > 0)
                        ->map(fn ($row) => $row['status'].' '.$row['count'].' ('.$pct($row['share']).')')
                        ->implode(', ');
                @endphp
                <div class="nv-chart nv-donut-wrap" data-donut>
                    <div class="nv-donut" role="img" aria-label="{{ $reading['label'] }} weighing, {{ $reading['measured'] }} learners measured: {{ $ariaParts }}">
                        <svg viewBox="0 0 200 200" aria-hidden="true">
                            <circle class="nv-ring-track" cx="100" cy="100" r="78"/>
                            @foreach ($reading['ring'] as $slice)
                                <circle class="nv-slice tone-{{ $slice['tone'] }}"
                                    cx="100" cy="100" r="78" pathLength="1000"
                                    stroke-dasharray="{{ $slice['dash'] }} {{ $slice['rest'] }}"
                                    stroke-dashoffset="{{ $slice['offset'] }}"
                                    transform="rotate(-90 100 100)"
                                    data-status="{{ $slice['status'] }}"
                                    data-count="{{ number_format($slice['count']) }}"
                                    data-share="{{ $pct($slice['share']) }}"></circle>
                            @endforeach
                        </svg>
                        <div class="nv-donut-center">
                            <span class="nv-donut-value" data-center-value data-default="{{ number_format($reading['measured']) }}">{{ number_format($reading['measured']) }}</span>
                            <span class="nv-donut-label" data-center-label data-default="learners measured">learners measured</span>
                        </div>
                    </div>
                    <ul class="nv-legend">
                        @foreach ($reading['rows'] as $row)
                            <li class="nv-legend-row {{ $row['count'] === 0 ? 'is-zero' : '' }}"
                                @if ($row['count'] > 0)
                                    data-status="{{ $row['status'] }}"
                                    data-count="{{ number_format($row['count']) }}"
                                    data-share="{{ $pct($row['share']) }}"
                                    data-legend
                                @endif>
                                <i class="nv-swatch tone-{{ $row['tone'] }}"></i>
                                <span>{{ $row['status'] }}</span>
                                <span class="nv-legend-count tnum">{{ number_format($row['count']) }}</span>
                                <span class="nv-legend-share tnum">{{ $pct($row['share']) }}</span>
                            </li>
                        @endforeach
                        @if ($reading['not_measured'] > 0)
                            <li class="nv-legend-row is-unmeasured">
                                <i class="nv-swatch is-unmeasured"></i>
                                <span>Not measured</span>
                                <span class="nv-legend-count tnum">{{ number_format($reading['not_measured']) }}</span>
                                <span class="nv-legend-share tnum">&nbsp;</span>
                            </li>
                        @endif
                    </ul>
                </div>

                <div class="nv-table table-scroll">
                    <table>
                        <thead>
                            <tr><th>Status</th><th class="num">Learners</th><th class="num">Share of measured</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($reading['rows'] as $row)
                                <tr>
                                    <td>{{ $row['status'] }}</td>
                                    <td class="num">{{ number_format($row['count']) }}</td>
                                    <td class="num">{{ $pct($row['share']) }}</td>
                                </tr>
                            @endforeach
                            <tr>
                                <td>Not measured</td>
                                <td class="num">{{ number_format($reading['not_measured']) }}</td>
                                <td class="num">&mdash;</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr><td>On the roll</td><td class="num">{{ number_format($reading['total']) }}</td><td class="num">{{ number_format($reading['measured']) }} measured</td></tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>
    @endforeach
</div>
