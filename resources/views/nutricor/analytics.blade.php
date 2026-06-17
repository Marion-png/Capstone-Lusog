@extends('nutricor.layout')

@section('title', 'NutriCor Analytics')
@section('crumb', 'Analytics')
@section('page_title')
Nutritional <span>Analytics</span>
@endsection
@section('page_subtitle', 'Readable snapshots for grade-level trends, risk concentration, and program insights.')

@section('content')
<section class="grid-2">
    <article class="card">
        <div class="card-head">Nutritional Status by Grade Group</div>
        <div class="card-body">
            <p class="muted">Section totals are computed from adviser-submitted baseline assessments and kept aligned with the report center.</p>
            <div class="table-wrap" style="margin-top:10px;">
                <table>
                    <thead>
                        <tr>
                            <th>Section</th>
                            <th>Total</th>
                            <th>Severely Wasted</th>
                            <th>Wasted/Underweight</th>
                            <th>Normal</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($sectionSummary as $section)
                            <tr>
                                <td>{{ $section['section'] }}</td>
                                <td>{{ number_format($section['total']) }}</td>
                                <td>{{ number_format($section['baseline']['severely_wasted']) }}</td>
                                <td>{{ number_format($section['baseline']['wasted'] + $section['baseline']['underweight']) }}</td>
                                <td>{{ number_format($section['baseline']['normal']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="muted">No section-level records available.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </article>
    <article class="card">
        <div class="card-head">Gender Distribution</div>
        <div class="card-body">
            <p class="muted">Current adviser submissions do not store sex-disaggregated values in the consolidated health record. This view reflects total population and section-level classifications only.</p>
        </div>
    </article>
</section>

<section class="grid-3" style="margin-top:12px;">
    <article class="card stat" style="border-left-color:#dc2626;">
        <div class="label">Severely Wasted</div>
        <div class="num">{{ number_format($summary['baseline_counts']['severely_wasted']) }}</div>
        <div class="hint">Immediate intervention needed</div>
    </article>
    <article class="card stat" style="border-left-color:#f59e0b;">
        <div class="label">Wasted/Underweight</div>
        <div class="num">{{ number_format($summary['baseline_counts']['wasted'] + $summary['baseline_counts']['underweight']) }}</div>
        <div class="hint">Close monitoring required</div>
    </article>
    <article class="card stat" style="border-left-color:#16a34a;">
        <div class="label">Normal/Healthy</div>
        <div class="num">{{ number_format($summary['baseline_counts']['normal']) }}</div>
        <div class="hint">On-track beneficiaries</div>
    </article>
</section>
@endsection
