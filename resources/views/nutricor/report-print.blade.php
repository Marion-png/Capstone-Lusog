{{--
    One document from the Nutrition Coordinator's Report Center, laid out for
    paper. It opens the browser's print dialog on load, which is how this app
    makes a PDF. Every figure is the controller's reading of the same records the
    Report Center shows, and a value nobody recorded is an em dash, never a zero.
--}}
@php
    $dash = fn ($value) => ($value === null || $value === '') ? '—' : $value;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} | {{ $letterhead['school'] }}</title>
    <link rel="icon" href="{{ asset('images/sigla-mark.png') }}">
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0 auto; max-width: 1040px; padding: 24px; background: #fff; color: #111; font: 13px/1.45 Arial, Helvetica, sans-serif; }
        .head { display: flex; align-items: center; justify-content: center; gap: 18px; text-align: center; }
        .head img { width: 64px; height: 64px; object-fit: contain; }
        .head p { margin: 0; }
        .school { font-weight: 700; font-size: 15px; }
        h1 { margin: 18px 0 2px; font-size: 16px; text-align: center; text-transform: uppercase; }
        .sy { margin: 0 0 16px; text-align: center; }
        h2 { margin: 18px 0 6px; font-size: 13px; text-transform: uppercase; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #111; padding: 4px 6px; text-align: left; vertical-align: top; font-variant-numeric: tabular-nums; }
        th { background: #eee; font-size: 12px; }
        td.num, th.num { text-align: right; }
        tr { break-inside: avoid; }
        thead { display: table-header-group; }
        .totals { margin: 0 0 12px; }
        .empty { padding: 16px; border: 1px solid #111; text-align: center; }
        @media print {
            body { padding: 0; max-width: none; }
            .table-wrap { overflow: visible; }
            th { background: none; }
        }
    </style>
</head>
<body>
    <div class="head">
        @if (! empty($seals['deped']))<img src="{{ asset($seals['deped']) }}" alt="Department of Education">@endif
        <div>
            <p>{{ $letterhead['republic'] }}</p>
            <p>{{ $letterhead['department'] }}</p>
            <p>{{ $letterhead['region'] }}</p>
            <p>{{ \App\Support\SchoolLetterhead::divisionLine($letterhead['division']) }}</p>
            <p class="school">{{ $letterhead['school'] }}</p>
            @if ($letterhead['address'] !== '')<p>{{ $letterhead['address'] }}</p>@endif
        </div>
        @if (! empty($seals['school']))<img src="{{ asset($seals['school']) }}" alt="School seal">@endif
    </div>

    <h1>{{ $title }}</h1>
    <p class="sy">School-Based Feeding Program &middot; S.Y. {{ $schoolYear }}</p>

    @if ($report === 'annex-a')
        @if ($data->isEmpty())
            <p class="empty">No learner is enrolled in the feeding program this school year.</p>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>No.</th><th>Name</th><th>Grade / Section</th><th>Gender</th><th class="num">Height (cm)</th><th class="num">Weight (kg)</th><th class="num">BMI</th><th>BMI-for-Age</th><th>Height-for-Age</th></tr></thead>
                <tbody>
                    @foreach ($data as $i => $row)
                        <tr>
                            <td class="num">{{ $i + 1 }}</td>
                            <td>{{ $row['name'] }}</td>
                            <td>{{ $row['section'] }}</td>
                            <td>{{ $dash($row['sex']) }}</td>
                            <td class="num">{{ $dash($row['height_cm']) }}</td>
                            <td class="num">{{ $dash($row['weight_kg']) }}</td>
                            <td class="num">{{ $dash($row['bmi']) }}</td>
                            <td>{{ $dash($row['bmi_status']) }}</td>
                            <td>{{ $dash($row['hfa_status']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    @elseif ($report === 'annex-b')
        @if ($data->isEmpty())
            <p class="empty">No learner is on file this school year.</p>
        @else
            @foreach (['baseline' => 'Baseline', 'endline' => 'Endline'] as $phase => $heading)
                <h2>{{ $heading }}</h2>
                <div class="table-wrap"><table>
                    <thead><tr><th>Grade Level</th><th class="num">Learners</th>@foreach ($labels as $label)<th class="num">{{ $label }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($data as $row)
                            <tr>
                                <td>{{ $row['grade'] }}</td>
                                <td class="num">{{ number_format($row['total']) }}</td>
                                @foreach ($labels as $key => $label)<td class="num">{{ number_format($row[$phase][$key]) }}</td>@endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            @endforeach
        @endif
    @elseif ($report === 'terminal')
        <p class="totals">Learners on file: {{ number_format($summary['total_population']) }} &middot; Baseline: {{ number_format($summary['baseline_total']) }} &middot; Endline: {{ number_format($summary['endline_total']) }}</p>
        <div class="table-wrap"><table>
            <thead><tr><th>Classification</th><th class="num">Baseline</th><th class="num">Endline</th><th class="num">Change</th><th class="num">Percent Change</th></tr></thead>
            <tbody>
                @foreach ($data as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td class="num">{{ number_format($row['baseline']) }}</td>
                        <td class="num">{{ number_format($row['endline']) }}</td>
                        <td class="num">{{ $row['change'] > 0 ? '+' : '' }}{{ $row['change'] }}</td>
                        <td class="num">{{ is_null($row['percent_change']) ? '—' : (($row['percent_change'] > 0 ? '+' : '').number_format($row['percent_change'], 1).'%') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
        <h2>Learners Weighed at Both Baseline and Endline</h2>
        <div class="table-wrap"><table>
            <thead><tr><th class="num">Learners</th><th class="num">Improved</th><th class="num">No Change</th><th class="num">Regressed</th><th class="num">Improvement Rate</th></tr></thead>
            <tbody>
                <tr>
                    <td class="num">{{ number_format($summary['tracked_total']) }}</td>
                    <td class="num">{{ number_format($summary['improved']) }}</td>
                    <td class="num">{{ number_format($summary['no_change']) }}</td>
                    <td class="num">{{ number_format($summary['regressed']) }}</td>
                    <td class="num">{{ is_null($summary['improvement_rate']) ? '—' : number_format($summary['improvement_rate'], 1).'%' }}</td>
                </tr>
            </tbody>
        </table></div>
    @elseif ($report === 'monthly')
        @if ($data->isEmpty())
            <p class="empty">No weighing or feeding session is on file this school year.</p>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Month</th><th class="num">Baseline Weighings</th><th class="num">Endline Weighings</th><th class="num">Feeding Sessions</th><th class="num">Attendance Rate</th></tr></thead>
                <tbody>
                    @foreach ($data as $row)
                        <tr>
                            <td>{{ $row['month'] }}</td>
                            <td class="num">{{ number_format($row['baseline']) }}</td>
                            <td class="num">{{ number_format($row['endline']) }}</td>
                            <td class="num">{{ number_format($row['sessions']) }}</td>
                            <td class="num">{{ is_null($row['rate']) ? '—' : number_format($row['rate'], 1).'%' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    @elseif ($report === 'analytics')
        <p class="totals">Learners on file: {{ number_format($summary['total_population']) }} &middot; Severely Wasted at baseline: {{ number_format($summary['priority_1']) }} &middot; Wasted at baseline: {{ number_format($summary['priority_2']) }} &middot; Improvement rate: {{ is_null($summary['improvement_rate']) ? '—' : number_format($summary['improvement_rate'], 1).'%' }}</p>
        @if ($data->isEmpty())
            <p class="empty">No learner is on file this school year.</p>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Section</th><th class="num">Learners</th><th class="num">Baseline SW</th><th class="num">Baseline W</th><th class="num">Baseline Normal</th><th class="num">Endline SW</th><th class="num">Endline W</th><th class="num">Endline Normal</th></tr></thead>
                <tbody>
                    @foreach ($data as $row)
                        <tr>
                            <td>{{ $row['section'] }}</td>
                            <td class="num">{{ number_format($row['total']) }}</td>
                            <td class="num">{{ number_format($row['baseline']['severely_wasted']) }}</td>
                            <td class="num">{{ number_format($row['baseline']['wasted']) }}</td>
                            <td class="num">{{ number_format($row['baseline']['normal']) }}</td>
                            <td class="num">{{ number_format($row['endline']['severely_wasted']) }}</td>
                            <td class="num">{{ number_format($row['endline']['wasted']) }}</td>
                            <td class="num">{{ number_format($row['endline']['normal']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    @else
        @if ($data->isEmpty())
            <p class="empty">No learner is severely wasted or wasted on the latest weighing.</p>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>No.</th><th>Name</th><th>Grade / Section</th><th>Risk</th><th class="num">BMI</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                    @foreach ($data as $i => $row)
                        <tr>
                            <td class="num">{{ $i + 1 }}</td>
                            <td>{{ $row['student_name'] }}</td>
                            <td>{{ $row['section'] }}</td>
                            <td>{{ $row['risk'] }}</td>
                            <td class="num">{{ $dash($row['bmi']) }}</td>
                            <td>{{ $row['status'] }}</td>
                            <td>{{ $row['action'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    @endif

    <script>window.addEventListener('load', () => window.print());</script>
</body>
</html>
