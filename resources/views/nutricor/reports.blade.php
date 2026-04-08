@extends('nutricor.layout')

@section('title', 'NutriCor Reports')
@section('crumb', 'Reports')
@section('page_title')
Report <span>Center</span>
@endsection
@section('page_subtitle', 'Generate clean SBFP outputs with consistent report labels and quick access actions.')

@section('content')
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
