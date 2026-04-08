@extends('nutricor.layout')

@section('title', 'NutriCor Baseline and Endline')
@section('crumb', 'Baseline/Endline')
@section('page_title')
Baseline and Endline <span>Comparison</span>
@endsection
@section('page_subtitle', 'Readable before-and-after summary that highlights improvement, regression, and no-change cases.')

@section('content')
<div class="summary">
    <h3>School Year Progress Snapshot</h3>
    <p>Baseline date: 06/16/2025 | Endline date: 02/09/2026</p>
</div>

<section class="grid-4" style="margin-bottom:12px;">
    <article class="card stat" style="border-left-color:#16a34a;">
        <div class="label">Students Improved</div>
        <div class="num">12</div>
        <div class="hint">Moved to better status</div>
    </article>
    <article class="card stat" style="border-left-color:#dc2626;">
        <div class="label">Students Regressed</div>
        <div class="num">4</div>
        <div class="hint">Needs review</div>
    </article>
    <article class="card stat" style="border-left-color:#64748b;">
        <div class="label">No Change</div>
        <div class="num">18</div>
        <div class="hint">Maintain support</div>
    </article>
    <article class="card stat" style="border-left-color:#15803d;">
        <div class="label">Improvement Rate</div>
        <div class="num">35.3%</div>
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
                <tr>
                    <td>Severely Wasted</td>
                    <td>6</td>
                    <td>4</td>
                    <td><span class="pill ok">-2</span></td>
                    <td><span class="pill ok">-33.3%</span></td>
                </tr>
                <tr>
                    <td>Wasted</td>
                    <td>8</td>
                    <td>5</td>
                    <td><span class="pill ok">-3</span></td>
                    <td><span class="pill ok">-37.5%</span></td>
                </tr>
                <tr>
                    <td>Normal</td>
                    <td>18</td>
                    <td>24</td>
                    <td><span class="pill ok">+6</span></td>
                    <td><span class="pill ok">+33.3%</span></td>
                </tr>
            </tbody>
        </table>
    </div>
</article>
@endsection
