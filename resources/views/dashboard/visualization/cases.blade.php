{{-- The most common complaints, biggest first; the tail folds into one
     de-emphasised bar so the bars add back up to every visit. --}}
@php
    $pct = fn ($value) => \App\Support\ClinicVisualization::percent($value);
@endphp
<div class="nv-head">
    <div>
        <h2 class="nv-title">Top Consultation Cases</h2>
        <p class="nv-meta">Source: Consultation Module (condition field)</p>
    </div>
    <div class="nv-tools">
        @include('dashboard.visualization.table-toggle')
    </div>
</div>
<div class="nv-body">
    @if (empty($cases['rows']))
        <div class="nv-empty">No consultation logged in S.Y. {{ $schoolYear }}.</div>
    @else
        <div class="nv-chart">
            <ul class="nv-rank">
                @foreach ($cases['rows'] as $row)
                    <li class="nv-rank-row {{ $row['is_other'] ? 'is-other' : '' }}"
                        data-tip-title="{{ $row['label'] }}"
                        data-tip="{{ number_format($row['count']) }} {{ \Illuminate\Support\Str::plural('visit', $row['count']) }} &middot; {{ $pct($row['share']) }} of all consultations{{ $row['is_other'] ? ' · '.$row['kinds'].' complaints' : '' }}">
                        <span class="nv-rank-label">{{ $row['label'] }}</span>
                        <span class="nv-rank-track">
                            <span class="nv-rank-bar" style="--p: {{ $row['pct'] / 100 }}"></span>
                            <span class="nv-rank-value tnum">{{ number_format($row['count']) }}</span>
                        </span>
                        <span class="nv-rank-share tnum">{{ $pct($row['share']) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="nv-table table-scroll">
            <table>
                <thead>
                    <tr><th>Complaint</th><th class="num">Visits</th><th class="num">Share</th></tr>
                </thead>
                <tbody>
                    @foreach ($cases['rows'] as $row)
                        <tr>
                            <td>{{ $row['label'] }}{{ $row['is_other'] ? ' ('.$row['kinds'].')' : '' }}</td>
                            <td class="num">{{ number_format($row['count']) }}</td>
                            <td class="num">{{ $pct($row['share']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr><td>All consultations</td><td class="num">{{ number_format($cases['total']) }}</td><td class="num">100%</td></tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
