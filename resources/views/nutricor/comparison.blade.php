@extends('nutricor.layout')

@section('title', 'NutriCor Baseline and Endline')
@section('crumb', 'Baseline/Endline')
@section('page_title')
Baseline and Endline <span>Comparison</span>
@endsection
@section('page_subtitle', 'Readable before-and-after summary that highlights improvement, regression, and no-change cases.')

@section('content')
<div class="summary">
    <h3>Total Student Population Snapshot</h3>
    <p>Baseline submitted: {{ number_format($summary['baseline_total']) }} | Endline submitted: {{ number_format($summary['endline_total']) }} | Matched baseline/endline: {{ number_format($summary['tracked_total']) }}</p>
</div>

<section class="grid-4" style="margin-bottom:12px;">
    <article class="card stat" style="border-left-color:#16a34a;">
        <div class="label">Students Improved</div>
        <div class="num">{{ number_format($summary['improved']) }}</div>
        <div class="hint">Moved to better status</div>
    </article>
    <article class="card stat" style="border-left-color:#dc2626;">
        <div class="label">Students Regressed</div>
        <div class="num">{{ number_format($summary['regressed']) }}</div>
        <div class="hint">Needs review</div>
    </article>
    <article class="card stat" style="border-left-color:#64748b;">
        <div class="label">No Change</div>
        <div class="num">{{ number_format($summary['no_change']) }}</div>
        <div class="hint">Maintain support</div>
    </article>
    <article class="card stat" style="border-left-color:#15803d;">
        <div class="label">Improvement Rate</div>
        <div class="num">{{ number_format($summary['improvement_rate'], 1) }}%</div>
        <div class="hint">Across all tracked learners</div>
    </article>
</section>

<article class="card">
    <div class="card-head">Comparison Summary Table</div>
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
                        <td><span class="pill {{ $row['change'] <= 0 && $row['key'] !== 'normal' ? 'ok' : ($row['change'] > 0 && $row['key'] !== 'normal' ? 'warn' : 'ok') }}">{{ $row['change'] > 0 ? '+' : '' }}{{ $row['change'] }}</span></td>
                        <td>
                            <span class="pill {{ $row['change'] <= 0 && $row['key'] !== 'normal' ? 'ok' : ($row['change'] > 0 && $row['key'] !== 'normal' ? 'warn' : 'ok') }}">
                                {{ is_null($row['percent_change']) ? '-' : (($row['percent_change'] > 0 ? '+' : '') . number_format($row['percent_change'], 1) . '%') }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No baseline or endline records available.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</article>
@endsection
