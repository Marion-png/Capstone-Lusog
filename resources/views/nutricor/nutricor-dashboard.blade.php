@extends('nutricor.layout')

@section('title', 'NutriCor Dashboard')
@section('crumb', 'Dashboard')
@section('page_title')
Nutritional Coordinator <span>Dashboard</span>
@endsection
@section('page_subtitle', 'Clear, readable overview of enrollment priorities, risk trends, and program progress.')

@section('content')
@php
    $statusClass = function ($status) {
        $status = strtolower((string) $status);
        if (str_contains($status, 'severe')) return 'bad';
        if (str_contains($status, 'wast') || str_contains($status, 'underweight') || str_contains($status, 'over')) return 'warn';
        return 'ok';
    };
@endphp
<section class="grid-5">
    <article class="card stat">
        <div class="label">Total Students</div>
        <div class="num">{{ number_format($summary['total_population']) }}</div>
        <div class="hint">Consolidated population</div>
    </article>
    <article class="card stat" style="border-left-color:#dc2626;">
        <div class="label">Priority 1</div>
        <div class="num">{{ number_format($summary['priority_1']) }}</div>
        <div class="hint">Severely wasted learners</div>
    </article>
    <article class="card stat" style="border-left-color:#f59e0b;">
        <div class="label">Priority 2</div>
        <div class="num">{{ number_format($summary['priority_2']) }}</div>
        <div class="hint">Wasted or underweight</div>
    </article>
    <article class="card stat" style="border-left-color:#b91c1c;">
        <div class="label">At-Risk</div>
        <div class="num">{{ number_format($summary['at_risk']) }}</div>
        <div class="hint">Needs intervention</div>
    </article>
    <article class="card stat" style="border-left-color:#7c3aed;">
        <div class="label">With Endline</div>
        <div class="num">{{ number_format($summary['endline_total']) }}</div>
        <div class="hint">Completed final assessment</div>
    </article>
</section>

<section class="summary">
    <h3>Division Submission Snapshot</h3>
    <p>Baseline records: {{ number_format($summary['baseline_total']) }} | Endline records: {{ number_format($summary['endline_total']) }} | Improvement rate: {{ number_format($summary['improvement_rate'], 1) }}%</p>
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
                    @forelse($priorityOne as $record)
                        <tr>
                            <td>{{ $record->student_name }}</td>
                            <td>{{ $record->section ?: '-' }}</td>
                            <td>{{ $record->baseline_bmi_value ? number_format((float) $record->baseline_bmi_value, 2) : '-' }}</td>
                            <td><span class="pill {{ $statusClass($record->baseline_nutritional_status) }}">{{ $record->baseline_nutritional_status ?: '-' }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">No Priority 1 learners submitted.</td></tr>
                    @endforelse
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
                    @forelse($priorityTwo as $record)
                        <tr>
                            <td>{{ $record->student_name }}</td>
                            <td>{{ $record->section ?: '-' }}</td>
                            <td>{{ $record->baseline_bmi_value ? number_format((float) $record->baseline_bmi_value, 2) : '-' }}</td>
                            <td><span class="pill {{ $statusClass($record->baseline_nutritional_status) }}">{{ $record->baseline_nutritional_status ?: '-' }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">No Priority 2 learners submitted.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>
</section>
@endsection
