@extends('nutricor.layout')

@section('title', 'NutriCor Reports')
@section('crumb', 'Reports')
@section('page_title')
Report <span>Center</span>
@endsection
@section('page_subtitle', 'Generate clean SBFP outputs with consistent report labels and quick access actions.')

@section('content')
<section class="summary">
    <h3>Division Office Consolidated Totals</h3>
    <p>Total population: {{ number_format($summary['total_population']) }} | Baseline: {{ number_format($summary['baseline_total']) }} | Endline: {{ number_format($summary['endline_total']) }} | At-risk: {{ number_format($summary['at_risk']) }}</p>
</section>

<article class="card" style="margin-bottom:12px;">
    <div class="card-head">Baseline and Endline Classification Totals</div>
    <div class="card-body table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Classification</th>
                    <th>Baseline</th>
                    <th>Endline</th>
                    <th>Change</th>
                    <th>Percent Change</th>
                </tr>
            </thead>
            <tbody>
                @forelse($reportRows as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td>{{ number_format($row['baseline']) }}</td>
                        <td>{{ number_format($row['endline']) }}</td>
                        <td>{{ $row['change'] > 0 ? '+' : '' }}{{ $row['change'] }}</td>
                        <td>{{ is_null($row['percent_change']) ? '-' : (($row['percent_change'] > 0 ? '+' : '') . number_format($row['percent_change'], 1) . '%') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No adviser-submitted nutritional assessment records available.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</article>

<article class="card" style="margin-bottom:12px;">
    <div class="card-head">Section Summary</div>
    <div class="card-body table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Section</th>
                    <th>Total</th>
                    <th>Baseline SW</th>
                    <th>Baseline W/UW</th>
                    <th>Baseline Normal</th>
                    <th>Endline SW</th>
                    <th>Endline W/UW</th>
                    <th>Endline Normal</th>
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
                        <td>{{ number_format($section['endline']['severely_wasted']) }}</td>
                        <td>{{ number_format($section['endline']['wasted'] + $section['endline']['underweight']) }}</td>
                        <td>{{ number_format($section['endline']['normal']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">No section summary available.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</article>

<section class="report-grid">
    <article class="card card-body">
        <h3 style="font-size:.92rem; margin-bottom:6px;">Annex A</h3>
        <p class="muted" style="margin-bottom:10px;">Master list of beneficiaries with baseline health details.</p>
        <button class="btn"><i class="fas fa-download"></i>Generate PDF</button>
    </article>
    <article class="card card-body">
        <h3 style="font-size:.92rem; margin-bottom:6px;">Annex B</h3>
        <p class="muted" style="margin-bottom:10px;">Summary by grade level and nutritional status.</p>
        <button class="btn"><i class="fas fa-download"></i>Generate PDF</button>
    </article>
    <article class="card card-body">
        <h3 style="font-size:.92rem; margin-bottom:6px;">Terminal Report</h3>
        <p class="muted" style="margin-bottom:10px;">Program completion report with accomplishments.</p>
        <button class="btn"><i class="fas fa-download"></i>Generate PDF</button>
    </article>
    <article class="card card-body">
        <h3 style="font-size:.92rem; margin-bottom:6px;">Monthly Report</h3>
        <p class="muted" style="margin-bottom:10px;">Monthly implementation and progress status.</p>
        <button class="btn"><i class="fas fa-download"></i>Generate PDF</button>
    </article>
    <article class="card card-body">
        <h3 style="font-size:.92rem; margin-bottom:6px;">Analytics Report</h3>
        <p class="muted" style="margin-bottom:10px;">Data-driven nutritional insights and highlights.</p>
        <button class="btn"><i class="fas fa-download"></i>Generate PDF</button>
    </article>
    <article class="card card-body">
        <h3 style="font-size:.92rem; margin-bottom:6px;">At-Risk Report</h3>
        <p class="muted" style="margin-bottom:10px;">Intervention tracker for medium and high-risk learners.</p>
        <button class="btn"><i class="fas fa-download"></i>Generate PDF</button>
    </article>
</section>
@endsection
