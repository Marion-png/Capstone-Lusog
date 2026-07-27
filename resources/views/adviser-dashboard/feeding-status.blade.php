<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Feeding Status - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @php $classAdviserCssPath = resource_path('css/class-adviser.css'); @endphp
    @if (file_exists($classAdviserCssPath))
        <style>{!! file_get_contents($classAdviserCssPath) !!}</style>
    @endif
</head>
<body>
@include('partials.adviser-sidebar', ['active' => 'feeding'])

<div class="asb-main">
    @include('partials.adviser-topbar', ['breadcrumb' => 'Feeding Status'])

    <div class="content">
        <h1 class="title">Feeding <i>&amp; Nutrition Status</i></h1>
        <p class="sub">Read-only summary of your class's nutritional status and feeding program eligibility. Attendance is recorded by the Feeding Coordinator.</p>

        <div class="assigned-class-banner">
            <div>
                <div class="assigned-class-label">Assigned Class</div>
                <div class="assigned-class-value">{{ $gradeSection }}</div>
            </div>
            <div class="assigned-class-note">Showing current school year records for your assigned class only.</div>
        </div>

        <section class="card section" style="margin-top:16px;">
            @if ($students->isEmpty())
                <div style="padding:30px;text-align:center;color:var(--muted);font-size:.85rem;">
                    No students with health records found for your assigned class yet.
                </div>
            @else
                <div style="overflow-x:auto;">
                    <table class="my-students-table" style="width:100%;border-collapse:collapse;">
                        <thead>
                            <tr style="text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);">
                                <th style="padding:10px 12px;border-bottom:1px solid var(--border);">Student</th>
                                <th style="padding:10px 12px;border-bottom:1px solid var(--border);">Nutritional Status</th>
                                <th style="padding:10px 12px;border-bottom:1px solid var(--border);">Weight (kg)</th>
                                <th style="padding:10px 12px;border-bottom:1px solid var(--border);">BMI</th>
                                <th style="padding:10px 12px;border-bottom:1px solid var(--border);">Feeding Attendance</th>
                                <th style="padding:10px 12px;border-bottom:1px solid var(--border);">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($students as $student)
                                @php
                                    $status = strtolower((string) $student->nutritional_status);
                                    $eligible = str_contains($status, 'wast') || str_contains($status, 'underweight');
                                @endphp
                                <tr>
                                    <td style="padding:11px 12px;border-bottom:1px solid var(--border);font-weight:600;">{{ $student->student_name }}</td>
                                    <td style="padding:11px 12px;border-bottom:1px solid var(--border);">{{ $student->nutritional_status ?: '—' }}</td>
                                    <td style="padding:11px 12px;border-bottom:1px solid var(--border);">{{ $student->weight ?? '—' }}</td>
                                    <td style="padding:11px 12px;border-bottom:1px solid var(--border);">{{ $student->bmi_value ?? '—' }}</td>
                                    <td style="padding:11px 12px;border-bottom:1px solid var(--border);">{{ $student->attendance_sessions_count ?? 0 }} session(s)</td>
                                    <td style="padding:11px 12px;border-bottom:1px solid var(--border);">
                                        @if ($student->is_at_risk)
                                            <span style="background:#fee2e2;color:#b91c1c;font-size:.68rem;font-weight:700;padding:3px 9px;border-radius:999px;">At-Risk</span>
                                        @elseif ($eligible)
                                            <span style="background:#fef3c7;color:#92400e;font-size:.68rem;font-weight:700;padding:3px 9px;border-radius:999px;">Feeding Eligible</span>
                                        @else
                                            <span style="background:#dcfce7;color:#166534;font-size:.68rem;font-weight:700;padding:3px 9px;border-radius:999px;">Normal</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</div>
</body>
</html>
