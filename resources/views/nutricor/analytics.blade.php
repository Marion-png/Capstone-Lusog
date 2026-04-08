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
            <p class="muted">Kinder has the largest share of Priority 1 beneficiaries. Grade 4 to 6 shows moderate wasted cases requiring targeted intervention.</p>
        </div>
    </article>
    <article class="card">
        <div class="card-head">Gender Distribution</div>
        <div class="card-body">
            <p class="muted">Male beneficiaries remain slightly higher than female, with similar recovery patterns across both groups.</p>
        </div>
    </article>
</section>

<section class="grid-3" style="margin-top:12px;">
    <article class="card stat" style="border-left-color:#dc2626;">
        <div class="label">Severely Wasted</div>
        <div class="num">4</div>
        <div class="hint">Immediate intervention needed</div>
    </article>
    <article class="card stat" style="border-left-color:#f59e0b;">
        <div class="label">Wasted</div>
        <div class="num">5</div>
        <div class="hint">Close monitoring required</div>
    </article>
    <article class="card stat" style="border-left-color:#16a34a;">
        <div class="label">Normal/Healthy</div>
        <div class="num">8</div>
        <div class="hint">On-track beneficiaries</div>
    </article>
</section>
@endsection
