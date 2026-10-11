{{-- Each grade's measured learners split by status, 100% wide, with the
     undernourished share (Severely Wasted + Wasted) at the end of the row. --}}
@php
    $pct = fn ($value) => \App\Support\ClinicVisualization::percent($value);
    $scale = \App\Support\ClinicVisualization::TONES;
@endphp
<div class="nv-head">
    <div>
        <h2 class="nv-title">Nutritional Status by Grade</h2>
        <p class="nv-meta">Source: Student Profile Module (grade level and BMI-for-age)</p>
    </div>
    <div class="nv-tools">
        @include('dashboard.visualization.table-toggle')
    </div>
</div>
<div class="nv-body">
    @foreach ($nutrition as $phase => $reading)
        <div data-phase-view="{{ $phase }}">
            @if (empty($reading['grades']))
                <div class="nv-empty">No learner on the roll for S.Y. {{ $schoolYear }}.</div>
            @else
                <div class="nv-chart">
                    <ul class="nv-key" aria-hidden="true">
                        @foreach ($scale as $status => $tone)
                            <li><i class="nv-swatch tone-{{ $tone }}"></i>{{ $status }}</li>
                        @endforeach
                        @if (collect($reading['grades'])->contains(fn ($g) => $g['measured'] === 0))
                            <li><i class="nv-swatch is-unmeasured"></i>Not measured</li>
                        @endif
                    </ul>
                    <div class="nv-stack-head" aria-hidden="true"><span>Grade</span><span></span><span>Undernourished</span></div>
                    <ul class="nv-stack-list">
                        @foreach ($reading['grades'] as $grade)
                            @php
                                $gradeAria = collect($grade['segments'])
                                    ->map(fn ($s) => $s['status'].' '.$s['count'])
                                    ->implode(', ');
                            @endphp
                            <li class="nv-stack-row">
                                <span class="nv-stack-label">{{ $grade['label'] }}</span>
                                @if ($grade['measured'] > 0)
                                    <span class="nv-stack" role="img" aria-label="{{ $grade['label'] }}: {{ $gradeAria }}{{ $grade['not_measured'] > 0 ? ', not measured '.$grade['not_measured'] : '' }}">
                                        @foreach ($grade['segments'] as $segment)
                                            <span class="nv-stack-seg tone-{{ $segment['tone'] }}"
                                                style="flex-grow: {{ $segment['count'] }}"
                                                data-tip-title="{{ $grade['label'] }} &middot; {{ $segment['status'] }}"
                                                data-tip="{{ number_format($segment['count']) }} {{ \Illuminate\Support\Str::plural('learner', $segment['count']) }} &middot; {{ $pct($segment['share']) }} of measured"></span>
                                        @endforeach
                                    </span>
                                @else
                                    <span class="nv-stack is-empty" role="img" aria-label="{{ $grade['label'] }}: not measured"
                                        data-tip-title="{{ $grade['label'] }}"
                                        data-tip="{{ number_format($grade['total']) }} {{ \Illuminate\Support\Str::plural('learner', $grade['total']) }}, none measured"></span>
                                @endif
                                <span class="nv-stack-figure">
                                    <b class="tnum">{{ $pct($grade['undernourished_share']) }}</b>
                                    <small class="tnum">of {{ number_format($grade['measured']) }} measured</small>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="nv-table table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Grade</th>
                                @foreach ($scale as $status => $tone)
                                    <th class="num">{{ $status }}</th>
                                @endforeach
                                <th class="num">Not measured</th>
                                <th class="num">Undernourished</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($reading['grades'] as $grade)
                                <tr>
                                    <td>{{ $grade['label'] }}</td>
                                    @foreach ($scale as $status => $tone)
                                        <td class="num">{{ number_format($grade['counts'][$status]) }}</td>
                                    @endforeach
                                    <td class="num">{{ number_format($grade['not_measured']) }}</td>
                                    <td class="num">{{ $pct($grade['undernourished_share']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endforeach
</div>
