<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="{{ asset('images/sigla-mark.png') }}">
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
    <img src="{{ asset('images/sigla-mark.png') }}" alt="SIGLA">
    <div>
        <div class="cf-topbar-title">Health Assessments (MLHAT)</div>
        <div class="cf-topbar-sub">School Nurse &middot; assessments are read-only; the health record is yours to record</div>
    </div>
    @include('partials.nurse-learner-search')
    <a href="{{ route('dashboard.school-nurse') }}" class="cf-back">&larr; Back to Dashboard</a>
</header>

<div class="cf-wrap">
    <h1 class="cf-page-title">Submitted Health Assessments</h1>
    @php
        $selectedYear = $selectedYear ?? \App\Models\HealthAssessment::currentSchoolYear();
        $schoolYears = $schoolYears ?? [$selectedYear];
        $isCurrentYear = $isCurrentYear ?? true;
    @endphp
    <p class="cf-page-sub">Mandatory Learner's Health Assessment Tool forms submitted by class advisers for SY {{ $selectedYear }}. The assessment itself belongs to the adviser who submitted it; the health record beside it is yours to fill in or correct.</p>

    @if (session('error')) <div class="cf-flash cf-flash-err">{{ session('error') }}</div> @endif

    {{-- An earlier year is that year's assessments and that year's health
         record, read only: Fill Health Record writes the current year. --}}
    <form method="GET" class="cf-filter" action="{{ route('health-assessments.nurse-index') }}">
        <label for="assessmentYear">School year</label>
        <select name="school_year" id="assessmentYear" onchange="this.form.submit()">
            @foreach ($schoolYears as $yearOption)
                <option value="{{ $yearOption }}" @selected($yearOption === $selectedYear)>S.Y. {{ $yearOption }}{{ $yearOption === \App\Support\SchoolYear::current() ? ' (current)' : '' }}</option>
            @endforeach
        </select>
    </form>

    <div class="cf-card">
        <div class="cf-card-head"><h2>Assessments</h2></div>
        @if ($assessments->isEmpty())
            <div class="cf-empty">{{ $isCurrentYear ? 'No health assessments submitted yet.' : 'No health assessments submitted in S.Y. '.$selectedYear.'.' }}</div>
        @else
            <table class="cf-table">
                <thead>
                    <tr>
                        <th>Learner</th>
                        <th>Grade / Section</th>
                        <th>Date of Assessment</th>
                        <th>Assessed by</th>
                        <th>Submitted</th>
                        <th>Health Record</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($assessments as $assessment)
                        @php
                            // A learner whose assessment is in but who is not on
                            // the session roster has no form to open — the link
                            // is keyed by that row, so there is nothing to point
                            // at and the standing says so rather than offering a
                            // control that would 404.
                            $rowLrn = trim((string) ($assessment->studentHealthRecord?->student_id ?? ''));
                            $examineIndex = $examineIndexByLrn[$rowLrn] ?? null;
                            $examined = ! empty($examinedByLrn[$rowLrn]);
                        @endphp
                        <tr>
                            <td><b>{{ $assessment->studentHealthRecord?->student_name ?? 'Unknown' }}</b><br><span style="color:var(--muted); font-size:.74rem;">LRN {{ $assessment->studentHealthRecord?->student_id }}</span></td>
                            <td>{{ $assessment->studentHealthRecord?->section }}</td>
                            <td>{{ optional($assessment->date_of_assessment)->format('M j, Y') ?? '—' }}</td>
                            <td>{{ $assessment->assessed_by ?: $assessment->submitted_by_name }}</td>
                            <td>{{ \App\Support\SchoolClock::local($assessment->created_at)?->format('M j, Y g:i A') }}</td>
                            <td>
                                @if (! $isCurrentYear)
                                    {{-- That year's own row says whether it was recorded. --}}
                                    @if (! empty($assessment->studentHealthRecord?->examination))
                                        <span style="color:var(--g700);font-weight:600;">Recorded</span>
                                    @else
                                        <span style="color:var(--muted);">Not recorded</span>
                                    @endif
                                @elseif ($examineIndex === null)
                                    <span style="color:var(--muted);">Not on this year's roster</span>
                                @elseif ($examined)
                                    <span style="color:var(--g700);font-weight:600;">Recorded</span>
                                @else
                                    <span style="color:var(--muted);">Not yet recorded</span>
                                @endif
                            </td>
                            <td style="text-align:right;white-space:nowrap;">
                                <a href="{{ route('health-assessments.show', $assessment) }}" class="cf-btn cf-btn-outline">View</a>
                                @if ($examineIndex !== null)
                                    {{-- One form, one audited write path: the same
                                         Fill Medical Record the Health Records table
                                         opens, carrying this page back so Cancel and
                                         Save both land here. --}}
                                    <a href="{{ route('nurse.examine', ['index' => $examineIndex, 'return_to' => route('health-assessments.nurse-index')]) }}"
                                       class="cf-btn cf-btn-primary">{{ $examined ? 'Edit Health Record' : 'Fill Health Record' }}</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@include('partials.workspace-live')
</body>
</html>