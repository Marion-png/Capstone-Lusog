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
        <div class="num">3</div>
        <div class="hint">Urgent case review</div>
    </article>
    <article class="card stat" style="border-left-color:#f59e0b;">
        <div class="label">Medium Risk</div>
        <div class="num">4</div>
        <div class="hint">Needs close monitoring</div>
    </article>
    <article class="card stat" style="border-left-color:#16a34a;">
        <div class="label">Low Risk</div>
        <div class="num">10</div>
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
                <tr>
                    <td><span class="pill bad">High</span></td>
                    <td>Bacos, Ujan Lungayao</td>
                    <td>Grade 2</td>
                    <td>15.50</td>
                    <td><span class="pill bad">Severely Wasted</span></td>
                    <td>Severe wasting, younger age</td>
                    <td>Immediate referral and weekly nutrition check</td>
                </tr>
                <tr>
                    <td><span class="pill warn">Medium</span></td>
                    <td>Santos, Maria</td>
                    <td>Grade 4</td>
                    <td>17.30</td>
                    <td><span class="pill warn">Wasted</span></td>
                    <td>Low BMI trend</td>
                    <td>Biweekly follow-up and diet coaching</td>
                </tr>
            </tbody>
        </table>
    </div>
</article>
@endsection
