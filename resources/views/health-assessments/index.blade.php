<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Health Assessments (MLHAT) - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @php $cfCss = resource_path('css/consent-form.css'); $haCss = resource_path('css/mlhat.css'); @endphp
    @if (file_exists($cfCss)) <style>{!! file_get_contents($cfCss) !!}</style> @endif
    @if (file_exists($haCss)) <style>{!! file_get_contents($haCss) !!}</style> @endif
</head>
<body>
<header class="cf-topbar">
    <img src="{{ asset('images/lusog-logo.png') }}" alt="SIGLA">
    <div>
        <div class="cf-topbar-title">Health Assessment (MLHAT)</div>
        <div class="cf-topbar-sub">Mandatory Learner's Health Assessment Tool &middot; {{ session('assigned_grade_level') }} / {{ session('assigned_section') }} &middot; SY {{ $schoolYear }}</div>
    </div>
    <a href="{{ route('dashboard.class-adviser') }}" class="cf-back">&larr; Back to Dashboard</a>
</header>

<div class="cf-wrap">
    <h1 class="cf-page-title">Mandatory Learner's <i>Health Assessment Tool</i></h1>
    <p class="cf-page-sub">Complete the two-sheet MLHAT for each learner, in cooperation with their parents. Submitted assessments become visible to you and the Clinical Teacher.</p>

    @if (session('success')) <div class="cf-flash cf-flash-ok">{{ session('success') }}</div> @endif
    @if (session('health_assessment_success')) <div class="cf-flash cf-flash-ok">{{ session('health_assessment_success') }}</div> @endif
    @if (session('error')) <div class="cf-flash cf-flash-err">{{ session('error') }}</div> @endif

    <div class="cf-card">
        <div class="cf-card-head"><h2>My Learners</h2></div>
        @if ($students->isEmpty())
            <div class="cf-empty">
                No learners found for your assigned class yet.<br>
                Encode learners first through the <a href="{{ route('dashboard.class-adviser') }}">School Health Card Form</a>.
            </div>
        @else
            <table class="cf-table">
                <thead>
                    <tr>
                        <th>Learner</th>
                        <th>LRN</th>
                        <th>Status</th>
                        <th>Assessed / Submitted</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($students as $student)
                        @php
                            $record = $records->get($student['lrn']);
                            $assessment = $record ? $assessments->get($record->id) : null;
                        @endphp
                        <tr>
                            <td><b>{{ $student['name'] }}</b></td>
                            <td>{{ $student['lrn'] }}</td>
                            <td>
                                @if ($assessment)
                                    <span class="cf-badge" style="background:#dcfce7;color:#166534;">Submitted</span>
                                @else
                                    <span class="cf-badge" style="background:#f8fafc;color:#94a3b8;">Not started</span>
                                @endif
                            </td>
                            <td>
                                @if ($assessment)
                                    {{ optional($assessment->date_of_assessment)->format('M j, Y') ?? '—' }}
                                    <span style="color:var(--muted); font-size:.74rem;">by {{ $assessment->assessed_by ?: $assessment->submitted_by_name }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td style="text-align:right; white-space:nowrap;">
                                @if ($assessment)
                                    <a href="{{ route('health-assessments.show', $assessment) }}" class="cf-btn cf-btn-ghost">View</a>
                                    <a href="{{ route('health-assessments.form', $student['lrn']) }}" class="cf-btn cf-btn-outline">Edit / Resubmit</a>
                                @else
                                    <a href="{{ route('health-assessments.form', $student['lrn']) }}" class="cf-btn cf-btn-primary">Start Assessment</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
</body>
</html>