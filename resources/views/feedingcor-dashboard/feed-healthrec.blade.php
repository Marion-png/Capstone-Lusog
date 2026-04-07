<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feeding Head - Student Health Records</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    @php $pageCssPath = resource_path('css/feeding-feed-healthrec.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
</head>
<body>
<aside class="sidebar">
    <div class="sb-logo"><img src="{{ asset('images/lusog-logo.png') }}" alt="LUSOG Logo"></div>
    <nav class="sb-nav">
        <a href="{{ route('dashboard.feedingcor-dashboard') }}" class="sb-link">Dashboard</a>
        <a href="{{ route('dashboard.feedingcor-health-records') }}" class="sb-link active">Student Health Records</a>
        <a href="{{ route('dashboard.feedingcor-program') }}" class="sb-link">Feeding Program</a>
        <a href="{{ route('dashboard.feedingcor-sbfp-forms') }}" class="sb-link">SBFP Forms</a>
    </nav>
    <div class="sb-user">
        <div class="sb-avatar">{{ substr(auth()->user()->name ?? 'FC',0,2) }}</div>
        <div class="sb-user-name">{{ auth()->user()->name ?? 'Feeding Coordinator' }}</div>
    </div>
</aside>

@php
    $total = ($records ?? collect())->count();
    $endlineDone = ($records ?? collect())->filter(fn ($r) => !is_null($r->endline_bmi_value))->count();
    $wastedCount = ($statusCounts['wasted'] ?? 0) + ($statusCounts['severely_wasted'] ?? 0);
@endphp

<div class="main">
    <header class="topbar">
        <div class="topbar-bc"><span>Dashboard</span><span>&gt;</span><span>Student Health Records</span></div>
        <div class="topbar-chip">Auto-computed BMI and status</div>
    </header>

    <div class="content">
        <div class="page-eyebrow">Feeding Program</div>
        <h1 class="page-title">Baseline and Endline <span>Health Records</span></h1>
        <p class="page-sub">Tracks BMI progression and nutritional status change per beneficiary.</p>

        <div class="cards">
            <div class="mini-card"><div class="val">{{ $total }}</div><div class="lbl">Beneficiaries</div></div>
            <div class="mini-card"><div class="val">{{ $endlineDone }}</div><div class="lbl">With Endline Data</div></div>
            <div class="mini-card"><div class="val">{{ $wastedCount }}</div><div class="lbl">Wasted or Severe</div></div>
            <div class="mini-card"><div class="val">{{ $statusCounts['normal'] ?? 0 }}</div><div class="lbl">Normal Status</div></div>
        </div>

        <div class="section-title">Per Beneficiary Comparison</div>
        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Section</th>
                        <th>Baseline BMI</th>
                        <th>Baseline Status</th>
                        <th>Endline BMI</th>
                        <th>Endline Status</th>
                        <th>Status Change</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($records ?? collect()) as $record)
                        @php
                            $baselineBmi = $record->baseline_bmi_value;
                            $endlineBmi = $record->endline_bmi_value;
                            $baselineStatus = $record->baseline_nutritional_status ?: $record->nutritional_status;
                            $endlineStatus = $record->endline_nutritional_status ?: $record->nutritional_status;

                            $deltaLabel = 'Pending endline';
                            $deltaClass = 'delta-none';
                            if (!is_null($baselineBmi) && !is_null($endlineBmi)) {
                                $delta = round((float) $endlineBmi - (float) $baselineBmi, 2);
                                if ($delta > 0) {
                                    $deltaLabel = '+' . number_format($delta, 2) . ' BMI';
                                    $deltaClass = 'delta-up';
                                } elseif ($delta < 0) {
                                    $deltaLabel = number_format($delta, 2) . ' BMI';
                                    $deltaClass = 'delta-down';
                                } else {
                                    $deltaLabel = 'No change';
                                }
                            }

                            $statusClass = function ($status) {
                                $normalized = strtolower((string) $status);
                                if (str_contains($normalized, 'severe')) return 's-severe';
                                if (str_contains($normalized, 'wast') || str_contains($normalized, 'underweight')) return 's-wasted';
                                if (str_contains($normalized, 'over')) return 's-over';
                                return 's-normal';
                            };
                        @endphp
                        <tr>
                            <td>{{ $record->student_name }}</td>
                            <td>{{ $record->section }}</td>
                            <td>{{ !is_null($baselineBmi) ? number_format((float) $baselineBmi, 2) : '-' }}</td>
                            <td><span class="status {{ $statusClass($baselineStatus) }}">{{ $baselineStatus ?: '-' }}</span></td>
                            <td>{{ !is_null($endlineBmi) ? number_format((float) $endlineBmi, 2) : '-' }}</td>
                            <td><span class="status {{ $statusClass($endlineStatus) }}">{{ $endlineStatus ?: '-' }}</span></td>
                            <td><span class="{{ $deltaClass }}">{{ $deltaLabel }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="7">No records available yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="section-title">Consolidated Baseline Report by Section</div>
        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Section</th>
                        <th>Total</th>
                        <th>Severely Wasted</th>
                        <th>Wasted</th>
                        <th>Normal</th>
                        <th>Overweight</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($sectionSummary ?? collect()) as $summary)
                        <tr>
                            <td>{{ $summary['section'] }}</td>
                            <td>{{ $summary['total'] }}</td>
                            <td>{{ $summary['counts']['severely_wasted'] }}</td>
                            <td>{{ $summary['counts']['wasted'] }}</td>
                            <td>{{ $summary['counts']['normal'] }}</td>
                            <td>{{ $summary['counts']['overweight'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No consolidated data yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
