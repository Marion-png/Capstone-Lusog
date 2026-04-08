@extends('nutricor.layout')

@section('title', 'NutriCor Dashboard')
@section('crumb', 'Dashboard')
@section('page_title')
Nutritional Coordinator <span>Dashboard</span>
@endsection
@section('page_subtitle', 'Clear, readable overview of enrollment priorities, risk trends, and program progress.')

@section('content')
<section class="grid-5">
    <article class="card stat">
        <div class="label">Total Enrolled</div>
        <div class="num">17</div>
        <div class="hint">SBFP beneficiaries</div>
    </article>
    <article class="card stat" style="border-left-color:#dc2626;">
        <div class="label">Priority 1</div>
        <div class="num">9</div>
        <div class="hint">Kinder and severely wasted</div>
    </article>
    <article class="card stat" style="border-left-color:#f59e0b;">
        <div class="label">Priority 2</div>
        <div class="num">8</div>
        <div class="hint">Wasted learners</div>
    </article>
    <article class="card stat" style="border-left-color:#b91c1c;">
        <div class="label">At-Risk</div>
        <div class="num">7</div>
        <div class="hint">Needs intervention</div>
    </article>
    <article class="card stat" style="border-left-color:#7c3aed;">
        <div class="label">Feeding Days</div>
        <div class="num">45</div>
        <div class="hint">Days completed</div>
    </article>
</section>

<section class="summary">
    <h3>SBFP Automatic Enrollment</h3>
    <p>Enrollment follows DepEd priority guidelines and keeps high-risk learners visible for quick action.</p>
</section>

<section class="grid-2">
    <article class="card">
        <div class="card-head">Priority 1 Learners</div>
        <div class="card-body table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Grade</th>
                        <th>BMI</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Bacos, Ujan Lungayao</td>
                        <td>Grade 2</td>
                        <td>15.50</td>
                        <td><span class="pill bad">Severely Wasted</span></td>
                    </tr>
                    <tr>
                        <td>Gonzales, Ana</td>
                        <td>Kinder</td>
                        <td>15.20</td>
                        <td><span class="pill bad">Severely Wasted</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </article>

    <article class="card">
        <div class="card-head">Priority 2 Learners</div>
        <div class="card-body table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Grade</th>
                        <th>BMI</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Santos, Maria</td>
                        <td>Grade 4</td>
                        <td>17.30</td>
                        <td><span class="pill warn">Wasted</span></td>
                    </tr>
                    <tr>
                        <td>Reyes, Jose</td>
                        <td>Grade 6</td>
                        <td>16.50</td>
                        <td><span class="pill warn">Wasted</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </article>
</section>
@endsection
