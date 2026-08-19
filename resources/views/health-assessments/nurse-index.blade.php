<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Health Assessments - School Nurse - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @php $cfCss = resource_path('css/consent-form.css'); $haCss = resource_path('css/mlhat.css'); @endphp
    @if (file_exists($cfCss)) <style>{!! file_get_contents($cfCss) !!}</style> @endif
    @if (file_exists($haCss)) <style>{!! file_get_contents($haCss) !!}</style> @endif
    {{-- One shared palette for pages not yet on lusog-theme.css. Loaded
         last so it overrides this page's own :root colours. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-palette.css')) !!}</style>
</head>
<body>
<header class="cf-topbar">
    <img src="{{ asset('images/lusog-logo.png') }}" alt="SIGLA">
    <div>
        <div class="cf-topbar-title">Health Assessments (MLHAT)</div>
        <div class="cf-topbar-sub">School Nurse &middot; read-only access</div>
    </div>
    @include('partials.nurse-learner-search')
    <a href="{{ route('dashboard.school-nurse') }}" class="cf-back">&larr; Back to Dashboard</a>
</header>

<div class="cf-wrap">
    <h1 class="cf-page-title">Submitted Health Assessments</h1>
    <p class="cf-page-sub">Mandatory Learner's Health Assessment Tool forms submitted by class advisers for SY {{ \App\Models\HealthAssessment::currentSchoolYear() }}.</p>

    @if (session('error')) <div class="cf-flash cf-flash-err">{{ session('error') }}</div> @endif

    <div class="cf-card">
        <div class="cf-card-head"><h2>Assessments</h2></div>
        @if ($assessments->isEmpty())
            <div class="cf-empty">No health assessments submitted yet.</div>
        @else
            <table class="cf-table">
                <thead>
                    <tr>
                        <th>Learner</th>
                        <th>Grade / Section</th>
                        <th>Date of Assessment</th>
                        <th>Assessed by</th>
                        <th>Submitted</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($assessments as $assessment)
                        <tr>
                            <td><b>{{ $assessment->studentHealthRecord?->student_name ?? 'Unknown' }}</b><br><span style="color:var(--muted); font-size:.74rem;">LRN {{ $assessment->studentHealthRecord?->student_id }}</span></td>
                            <td>{{ $assessment->studentHealthRecord?->section }}</td>
                            <td>{{ optional($assessment->date_of_assessment)->format('M j, Y') ?? '—' }}</td>
                            <td>{{ $assessment->assessed_by ?: $assessment->submitted_by_name }}</td>
                            <td>{{ $assessment->created_at?->format('M j, Y g:i A') }}</td>
                            <td style="text-align:right;">
                                <a href="{{ route('health-assessments.show', $assessment) }}" class="cf-btn cf-btn-outline">View</a>
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