@extends('nutricor.layout')

@section('title', 'NutriCor Beneficiaries')
@section('crumb', 'Beneficiaries')
@section('page_title')
Master List <span>Beneficiaries</span>
@endsection
@section('page_subtitle', 'Clear and searchable listing of SBFP beneficiaries and their nutritional classification.')

@section('content')
@php
    $statusClass = function ($status) {
        $status = strtolower((string) $status);
        if (str_contains($status, 'severe')) return 'bad';
        if (str_contains($status, 'wast') || str_contains($status, 'underweight') || str_contains($status, 'over')) return 'warn';
        return 'ok';
    };
    $priority = function ($status) {
        $status = strtolower((string) $status);
        if (str_contains($status, 'severe')) return ['Priority 1', 'bad'];
        if (str_contains($status, 'wast') || str_contains($status, 'underweight')) return ['Priority 2', 'warn'];
        return ['General', 'ok'];
    };
@endphp
<input type="text" class="search" placeholder="Search student name...">

<article class="card">
    <div class="card-head">Beneficiary List</div>
    <div class="card-body table-wrap">
        <table>
            <thead>
                <tr>
                    <th>No.</th>
                    <th>Name</th>
                    <th>School</th>
                    <th>Section</th>
                    <th>Weight</th>
                    <th>Height</th>
                    <th>BMI</th>
                    <th>Nutritional Status</th>
                    <th>Priority</th>
                </tr>
            </thead>
            <tbody>
                @forelse($records as $record)
                    @php
                        $status = $record->baseline_nutritional_status ?: $record->nutritional_status;
                        [$priorityLabel, $priorityClass] = $priority($status);
                    @endphp
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $record->student_name }}</td>
                        <td>{{ $record->school_name ?: '-' }}</td>
                        <td>{{ $record->section ?: '-' }}</td>
                        <td>{{ $record->baseline_weight_kg ? number_format((float) $record->baseline_weight_kg, 1) : '-' }}</td>
                        <td>{{ $record->baseline_height_cm ? number_format((float) $record->baseline_height_cm, 1) : '-' }}</td>
                        <td>{{ $record->baseline_bmi_value ? number_format((float) $record->baseline_bmi_value, 2) : '-' }}</td>
                        <td><span class="pill {{ $statusClass($status) }}">{{ $status ?: '-' }}</span></td>
                        <td><span class="pill {{ $priorityClass }}">{{ $priorityLabel }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="muted">No adviser-submitted records available.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</article>
@endsection
