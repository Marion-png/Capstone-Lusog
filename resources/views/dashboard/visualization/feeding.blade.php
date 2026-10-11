{{-- The feeding programme's beneficiaries at baseline and at endline. Colour
     carries which weighing (the theme's validated pair), the row label carries
     the status, every bar is labelled. The endline series is drawn only once
     somebody has been re-measured. The split below is the shared partial the
     coordinator and the head read, so all three report one outcome. --}}
@php
    $pct = fn ($value) => \App\Support\ClinicVisualization::percent($value);
    $hasEndline = $feeding['has_endline'];
@endphp
<div class="nv-head">
    <div>
        <h2 class="nv-title">Feeding Program Outcome</h2>
        <p class="nv-meta">Source: Feeding Program Module (baseline vs endline nutritional status)</p>
    </div>
    <div class="nv-tools">
        @include('dashboard.visualization.table-toggle')
    </div>
</div>
<div class="nv-body">
    @if ($feeding['beneficiaries'] === 0)
        <div class="nv-empty">No beneficiary enrolled in S.Y. {{ $schoolYear }}.</div>
    @else
        <div class="nv-chart nv-feeding">
            <div>
                <div class="nv-series-list">
                    <span class="nv-series-key"><i class="nv-key-bar is-baseline"></i>Baseline</span>
                    <span class="nv-series-key {{ $hasEndline ? '' : 'is-muted' }}"><i class="nv-key-bar is-endline"></i>Endline{{ $hasEndline ? '' : ' · not yet measured' }}</span>
                </div>
                <ul class="nv-pairs">
                    @foreach ($feeding['rows'] as $row)
                        <li class="nv-pair">
                            <span class="nv-pair-label">{{ $row['status'] }}</span>
                            <span class="nv-pair-bars">
                                <span class="nv-pair-row"
                                    data-tip-title="{{ $row['status'] }} &middot; Baseline"
                                    data-tip="{{ number_format($row['baseline']) }} {{ \Illuminate\Support\Str::plural('beneficiary', $row['baseline']) }}">
                                    <span class="nv-pair-bar is-baseline" style="--p: {{ $row['baseline_pct'] / 100 }}"></span>
                                    <span class="nv-pair-value tnum">{{ number_format($row['baseline']) }}</span>
                                </span>
                                @if ($hasEndline)
                                    <span class="nv-pair-row"
                                        data-tip-title="{{ $row['status'] }} &middot; Endline"
                                        data-tip="{{ number_format($row['endline']) }} {{ \Illuminate\Support\Str::plural('beneficiary', $row['endline']) }} &middot; {{ $row['change'] > 0 ? '+' : '' }}{{ number_format($row['change']) }} since baseline">
                                        <span class="nv-pair-bar is-endline" style="--p: {{ $row['endline_pct'] / 100 }}"></span>
                                        <span class="nv-pair-value tnum">{{ number_format($row['endline']) }}</span>
                                    </span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div>
                <dl class="nv-figures">
                    <div><dt>Beneficiaries</dt><dd>{{ number_format($feeding['beneficiaries']) }}</dd></div>
                    <div><dt>Re-measured</dt><dd>{{ number_format($feeding['endline_measured']) }}</dd></div>
                    <div><dt>Improved</dt><dd>{{ $pct($feeding['outcome']['improved_rate']) }}</dd></div>
                </dl>
                @include('partials.outcome-split', ['split' => $feeding['outcome']['split'], 'splitTitle' => 'Outcome at endline'])
            </div>
        </div>

        <div class="nv-table table-scroll">
            <table>
                <thead>
                    <tr><th>Status</th><th class="num">Baseline</th><th class="num">Endline</th><th class="num">Change</th></tr>
                </thead>
                <tbody>
                    @foreach ($feeding['rows'] as $row)
                        <tr>
                            <td>{{ $row['status'] }}</td>
                            <td class="num">{{ number_format($row['baseline']) }}</td>
                            <td class="num">{{ $hasEndline ? number_format($row['endline']) : '—' }}</td>
                            <td class="num">{{ $hasEndline ? ($row['change'] > 0 ? '+' : '').number_format($row['change']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td>Beneficiaries</td>
                        <td class="num">{{ number_format($feeding['beneficiaries']) }}</td>
                        <td class="num">{{ number_format($feeding['endline_measured']) }} re-measured</td>
                        <td class="num">{{ $pct($feeding['outcome']['improved_rate']) }} improved</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
