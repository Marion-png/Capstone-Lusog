{{-- Consultations month by month across the school year. The line lives in
     an SVG stretched over the plot; dots, end labels and the hover bands are
     HTML at the same percentages, so circles stay round at any width. --}}
<div class="nv-head">
    <div>
        <h2 class="nv-title">Consultation Trend</h2>
        <p class="nv-meta">Source: Consultation Module (dates grouped by month)</p>
    </div>
    <div class="nv-tools">
        <a class="nv-tool" href="{{ route('dashboard.consultation-log') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7"/><path d="M7 7h10v10"/></svg>
            <span>Consultation Log</span>
        </a>
        @include('dashboard.visualization.table-toggle')
    </div>
</div>
<div class="nv-body">
    @if ($trend['total'] === 0)
        <div class="nv-empty">No consultation logged in S.Y. {{ $schoolYear }}.</div>
    @else
        @php
            $lastIndex = count($trend['points']) - 1;
            $trendAria = collect($trend['points'])
                ->map(fn ($p) => $p['full_label'].': '.$p['treated'].' treated, '.$p['referred'].' referred')
                ->implode('; ');
        @endphp
        <div class="nv-chart">
            <div class="nv-series-list">
                <button type="button" class="nv-series" data-series-toggle="treated" aria-pressed="true">
                    <i class="nv-key-line tone-treated"></i>Treated <b class="tnum">{{ number_format($trend['treated']) }}</b>
                </button>
                <button type="button" class="nv-series" data-series-toggle="referred" aria-pressed="true">
                    <i class="nv-key-line tone-referred"></i>Referred <b class="tnum">{{ number_format($trend['referred']) }}</b>
                </button>
                @if ($trend['busiest'])
                    <span class="nv-peak">Busiest month <b>{{ $trend['busiest']['full_label'] }}</b> &middot; <span class="tnum">{{ number_format($trend['busiest']['total']) }}</span></span>
                @endif
            </div>

            <div class="nv-line">
                <div class="nv-axis tnum" aria-hidden="true">
                    @foreach ($trend['ticks'] as $tick)
                        <span>{{ number_format($tick) }}</span>
                    @endforeach
                </div>
                <div class="nv-plot" role="img" aria-label="Consultations per month: {{ $trendAria }}">
                    @foreach ([0, 25, 50, 75, 100] as $line)
                        <span class="nv-gridline {{ $line === 100 ? 'is-base' : '' }}" style="top: {{ $line }}%"></span>
                    @endforeach
                    <svg viewBox="0 0 1000 300" preserveAspectRatio="none" aria-hidden="true">
                        <polyline class="nv-path is-referred" points="{{ $trend['lines']['referred'] }}"></polyline>
                        <polyline class="nv-path is-treated" points="{{ $trend['lines']['treated'] }}"></polyline>
                    </svg>
                    <div class="nv-bands">
                        @foreach ($trend['points'] as $index => $point)
                            <div class="nv-band"
                                data-tip-title="{{ $point['full_label'] }}"
                                data-tip="{{ number_format($point['total']) }} {{ \Illuminate\Support\Str::plural('consultation', $point['total']) }} &middot; {{ number_format($point['treated']) }} treated &middot; {{ number_format($point['referred']) }} referred">
                                <span class="nv-dot is-referred" style="bottom: {{ $point['y_referred'] }}%"></span>
                                <span class="nv-dot is-treated" style="bottom: {{ $point['y_treated'] }}%"></span>
                                @if ($index === $lastIndex)
                                    @foreach ($trend['end_labels'] as $label)
                                        <span class="nv-end tnum is-{{ $label['series'] }} is-{{ $label['place'] }}" style="bottom: {{ $label['y'] }}%">{{ number_format($label['value']) }}</span>
                                    @endforeach
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="nv-xlabels" aria-hidden="true">
                    @foreach ($trend['points'] as $point)
                        <span>{{ $point['label'] }}</span>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="nv-table table-scroll">
            <table>
                <thead>
                    <tr><th>Month</th><th class="num">Consultations</th><th class="num">Treated</th><th class="num">Referred</th></tr>
                </thead>
                <tbody>
                    @foreach ($trend['points'] as $point)
                        <tr>
                            <td>{{ $point['full_label'] }}</td>
                            <td class="num">{{ number_format($point['total']) }}</td>
                            <td class="num">{{ number_format($point['treated']) }}</td>
                            <td class="num">{{ number_format($point['referred']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td>S.Y. {{ $schoolYear }}</td>
                        <td class="num">{{ number_format($trend['total']) }}</td>
                        <td class="num">{{ number_format($trend['treated']) }}</td>
                        <td class="num">{{ number_format($trend['referred']) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
