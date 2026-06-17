@extends('nutricor.layout')

@section('title', 'NutriCor At-Risk Learners')
@section('crumb', 'At-Risk Learners')
@section('page_title')
At-Risk <span>Learners</span>
@endsection
@section('page_subtitle', 'Prioritized intervention queue with clear risk levels and practical action points.')

@section('content')
<section class="grid-3" style="margin-bottom:12px;">
    <article class="card stat" style="border-left-color:#dc2626;">
        <div class="label">High Risk</div>
        <div class="num">{{ number_format($riskCounts['high']) }}</div>
        <div class="hint">Urgent case review</div>
    </article>
    <article class="card stat" style="border-left-color:#f59e0b;">
        <div class="label">Medium Risk</div>
        <div class="num">{{ number_format($riskCounts['medium']) }}</div>
        <div class="hint">Needs close monitoring</div>
    </article>
    <article class="card stat" style="border-left-color:#16a34a;">
        <div class="label">Low Risk</div>
        <div class="num">{{ number_format($riskCounts['low']) }}</div>
        <div class="hint">Stable progression</div>
    </article>
</section>

<article class="card">
    <div class="card-head">Intervention Tracking Table</div>
    <div class="card-body table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Risk</th>
                    <th>Student Name</th>
                    <th>Grade</th>
                    <th>BMI</th>
                    <th>Nutritional Status</th>
                    <th>Indicators</th>
                    <th>Recommended Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($riskRows as $row)
                    <tr>
                        <td><span class="pill {{ $row['risk'] === 'High' ? 'bad' : 'warn' }}">{{ $row['risk'] }}</span></td>
                        <td>{{ $row['student_name'] }}</td>
                        <td>{{ $row['section'] ?: '-' }}</td>
                        <td>{{ $row['bmi'] ? number_format((float) $row['bmi'], 2) : '-' }}</td>
                        <td><span class="pill {{ $row['risk'] === 'High' ? 'bad' : 'warn' }}">{{ $row['status'] }}</span></td>
                        <td>{{ $row['indicators'] }}</td>
                        <td>{{ $row['action'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">No high-risk or medium-risk learners found in the submitted records.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</article>
@endsection
