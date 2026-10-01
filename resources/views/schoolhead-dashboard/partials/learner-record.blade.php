{{-- Presentation of the existing row data; the controller owns all measurements,
     classifications and attendance calculations. Kept separate from the nurse view. --}}
@php
    $attendanceBadge = match ($row['attendance']) {
        'at_risk' => 'badge-risk',
        'early_monitoring' => 'badge-monitor',
        'on_track' => 'badge-normal',
        default => 'badge-neutral',
    };
@endphp
<div class="sh-detail">
    <section class="sh-panel-box sh-measurements">
        <div class="sh-record-section-head">
            <span class="sh-record-section-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></span>
            <div>
                <h3 class="sh-panel-title">Measurement History</h3>
                <p class="sh-record-caption">Readings recorded by the class adviser</p>
            </div>
        </div>

        @if (empty($row['history']))
            <div class="sh-record-empty">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 2h6v4H9zM9 11h6M9 15h4"/></svg>
                <p>No measurement has been recorded for this learner.</p>
            </div>
        @else
            <div class="table-scroll sh-history-scroll" role="region" aria-label="Measurement history" tabindex="0">
                <table class="sh-mini sh-history-table">
                    <thead>
                        <tr>
                            <th scope="col">Reading / date</th>
                            <th scope="col" class="num">Weight <span class="sh-unit">kg</span></th>
                            <th scope="col" class="num">Height <span class="sh-unit">cm</span></th>
                            <th scope="col" class="num">BMI</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($row['history'] as $entry)
                            <tr>
                                <td class="sh-reading"><strong>{{ $entry['phase'] }}</strong><span class="sh-reading-date tnum">{{ $entry['date'] }}</span></td>
                                <td class="num">{{ $entry['weight'] !== '' ? $entry['weight'] : '—' }}</td>
                                <td class="num">{{ $entry['height'] !== '' ? $entry['height'] : '—' }}</td>
                                <td class="num sh-reading-bmi">{{ $entry['bmi'] !== '' ? $entry['bmi'] : '—' }}</td>
                                <td><span class="badge {{ $shStatusBadge($entry['status']) }}">{{ $entry['status'] }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($row['sparkline'])
            <div class="sh-bmi-trend">
                <p class="sh-panel-sub">BMI over the readings on file</p>
                <div class="sh-bmi-plot">
                    <svg class="sh-spark" viewBox="0 0 100 40" preserveAspectRatio="none" role="img"
                         aria-label="BMI from {{ $row['sparkline']['min'] }} to {{ $row['sparkline']['max'] }}">
                        <polyline fill="none" stroke="var(--lg-info)" stroke-width="2" vector-effect="non-scaling-stroke"
                                  points="@foreach ($row['sparkline']['points'] as $point){{ $point['x'] }},{{ $point['y'] * 0.4 }} @endforeach"></polyline>
                        @foreach ($row['sparkline']['points'] as $point)
                            <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] * 0.4 }}" r="1.1" fill="var(--lg-info)" vector-effect="non-scaling-stroke"></circle>
                        @endforeach
                    </svg>
                </div>
                <p class="sh-spark-axis tnum">
                    @foreach ($row['sparkline']['points'] as $point)
                        <span>{{ $point['label'] }} <strong>{{ $point['value'] }}</strong></span>
                    @endforeach
                </p>
            </div>
        @endif
    </section>

    <section class="sh-panel-box sh-feeding-record">
        <div class="sh-record-section-head">
            <span class="sh-record-section-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 2v7a2 2 0 0 0 2 2h4a2 2 0 0 0 2-2V2M7 2v20M21 15V2a5 5 0 0 0-5 5v6a2 2 0 0 0 2 2h3v7"/></svg></span>
            <div>
                <h3 class="sh-panel-title">Feeding Programme</h3>
                <p class="sh-record-caption">Enrolment and attendance</p>
            </div>
        </div>

        <div class="sh-enrolment">
            <span class="sh-record-label">Beneficiary standing</span>
            <strong>{{ $row['standing_label'] }}</strong>
            @if ($row['enrolled_on'])
                <span class="sh-record-caption tnum">Enrolled {{ $row['enrolled_on'] }}</span>
            @endif
        </div>

        <div class="sh-attendance-summary">
            <div>
                <span class="sh-record-label">Attendance</span>
                <p class="sh-record-caption tnum">{{ $row['present'] }} of {{ $row['confirmed'] }} confirmed</p>
            </div>
            <strong class="sh-attendance-rate tnum">{{ $shPct($row['rate']) }}</strong>
        </div>

        <dl class="sh-attendance-counts">
            <div><dt>Present</dt><dd>{{ $row['present'] }}</dd></div>
            <div><dt>Absent</dt><dd>{{ $row['absent'] }}</dd></div>
            <div><dt>Not marked</dt><dd>{{ $row['not_marked'] }}</dd></div>
        </dl>

        <div class="sh-attendance-standing">
            <span class="sh-record-label">Attendance standing</span>
            <span class="badge {{ $attendanceBadge }}">{{ $row['attendance_label'] }}</span>
        </div>
        <p class="sh-record-rule"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg><span>{{ $rule }}.</span></p>
    </section>
</div>
