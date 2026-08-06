<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Health Records - Feeding Coordinator - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    <script>document.documentElement.classList.add('js');</script>
    @php $pageCssPath = resource_path('css/feeding-healthrec.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
    <style>{!! file_get_contents(resource_path('css/feedingcor-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.feedingcor-sidebar', ['active' => 'records'])

@php
    $total = ($records ?? collect())->count();
    $endlineDone = ($records ?? collect())->filter(fn ($r) => !is_null($r->endline_bmi_value))->count();
    $wastedCount = ($statusCounts['wasted'] ?? 0) + ($statusCounts['severely_wasted'] ?? 0);
@endphp

<div class="main">
    <header class="topbar">
        <div class="topbar-bc"><span>Dashboard</span><span>&rsaquo;</span><span>Student Health Records</span></div>
        @include('partials.live-clock')
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

        @php
            $activeFilters = ($filters['grade_level'] ?? '') !== '' || ($filters['section'] ?? '') !== '';
        @endphp
        <form method="GET" class="filter-bar" id="recordFilters">
            <div class="filter-field">
                <label for="filterGrade">Grade Level</label>
                <select name="grade_level" id="filterGrade">
                    <option value="">All grade levels</option>
                    @foreach (($gradeLevels ?? collect()) as $grade)
                        <option value="{{ $grade }}" @selected(($filters['grade_level'] ?? '') === $grade)>{{ $grade }}</option>
                    @endforeach
                </select>
            </div>
            <div class="filter-field">
                <label for="filterSection">Section</label>
                <select name="section" id="filterSection">
                    <option value="">All sections</option>
                    @foreach (($sections ?? collect()) as $section)
                        <option value="{{ $section }}" @selected(($filters['section'] ?? '') === $section)>{{ $section }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="filter-apply">Apply</button>
            @if ($activeFilters)
                <a class="filter-clear" href="{{ url()->current() }}">Clear</a>
            @endif
            <span class="filter-count">
                Showing {{ $total }} of {{ $totalBeforeFilters ?? $total }} {{ ($totalBeforeFilters ?? $total) === 1 ? 'beneficiary' : 'beneficiaries' }}
            </span>
        </form>

        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Grade Level</th>
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
                            <td>{{ $record->grade_level }}</td>
                            <td>{{ $record->section_name }}</td>
                            <td>{{ !is_null($baselineBmi) ? number_format((float) $baselineBmi, 2) : '-' }}</td>
                            <td><span class="status {{ $statusClass($baselineStatus) }}">{{ $baselineStatus ?: '-' }}</span></td>
                            <td>{{ !is_null($endlineBmi) ? number_format((float) $endlineBmi, 2) : '-' }}</td>
                            <td><span class="status {{ $statusClass($endlineStatus) }}">{{ $endlineStatus ?: '-' }}</span></td>
                            <td><span class="{{ $deltaClass }}">{{ $deltaLabel }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="8">{{ $activeFilters ? 'No beneficiaries match this grade level and section.' : 'No records available yet.' }}</td></tr>
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
@include('partials.feedingcor-page-transition')
<script>
(() => {
    const form = document.getElementById('recordFilters');
    if (!form) return;
    const grade = document.getElementById('filterGrade');

    form.querySelectorAll('select').forEach((select) => {
        select.addEventListener('change', () => {
            // Sections belong to a grade, so a new grade invalidates the section
            // already chosen. The server rebuilds the list for the new grade.
            if (select === grade) form.querySelector('#filterSection').value = '';
            form.submit();
        });
    });
})();
</script>
</body>
</html>
