@extends('nutricor.layout')

@section('title', 'NutriCor Beneficiaries')
@section('crumb', 'Beneficiaries')
@section('page_title')
Master List <span>Beneficiaries</span>
@endsection
@section('page_subtitle', 'Clear and searchable listing of SBFP beneficiaries and their nutritional classification.')

@section('content')
<input type="text" class="search" placeholder="Search student name...">

<article class="card">
    <div class="card-head">Beneficiary List</div>
    <div class="card-body table-wrap">
        <table>
            <thead>
                <tr>
                    <th>No.</th>
                    <th>Name</th>
                    <th>Sex</th>
                    <th>Grade</th>
                    <th>Weight</th>
                    <th>Height</th>
                    <th>BMI</th>
                    <th>Nutritional Status</th>
                    <th>Priority</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>1</td>
                    <td>Abella, Zyrain Kurt D.</td>
                    <td>M</td>
                    <td>Kinder</td>
                    <td>34</td>
                    <td>127</td>
                    <td>21.08</td>
                    <td><span class="pill ok">Normal</span></td>
                    <td><span class="pill bad">Priority 1</span></td>
                </tr>
                <tr>
                    <td>2</td>
                    <td>Bacos, Ujan Lungayao</td>
                    <td>M</td>
                    <td>Grade 2</td>
                    <td>32</td>
                    <td>127</td>
                    <td>15.50</td>
                    <td><span class="pill bad">Severely Wasted</span></td>
                    <td><span class="pill bad">Priority 1</span></td>
                </tr>
                <tr>
                    <td>3</td>
                    <td>Santos, Maria</td>
                    <td>F</td>
                    <td>Grade 4</td>
                    <td>38</td>
                    <td>148</td>
                    <td>17.30</td>
                    <td><span class="pill warn">Wasted</span></td>
                    <td><span class="pill warn">Priority 2</span></td>
                </tr>
            </tbody>
        </table>
    </div>
</article>
@endsection
