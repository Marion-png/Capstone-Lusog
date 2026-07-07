<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Health Services Consent Forms - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @php $cfCss = resource_path('css/consent-form.css'); @endphp
    @if (file_exists($cfCss)) <style>{!! file_get_contents($cfCss) !!}</style> @endif
</head>
<body>
@php use App\Models\HealthConsentForm; @endphp

<header class="cf-topbar">
    <img src="{{ asset('images/lusog-logo.png') }}" alt="SIGLA">
    <div>
        <div class="cf-topbar-title">Health Services Consent Form</div>
        <div class="cf-topbar-sub">Sulat-Pahibalo &middot; {{ session('assigned_grade_level') }} / {{ session('assigned_section') }} &middot; SY {{ $schoolYear }}</div>
    </div>
    <a href="{{ route('dashboard.class-adviser') }}" class="cf-back">&larr; Back to Dashboard</a>
</header>

<div class="cf-wrap">
    <h1 class="cf-page-title">Health Services Consent <i>Sulat-Pahibalo</i></h1>
    <p class="cf-page-sub">Prepare the official consent form for each learner, send it to the parent/guardian for e-signature, then review and release it to the School Nurse.</p>

    @if (session('success')) <div class="cf-flash cf-flash-ok">{{ session('success') }}</div> @endif
    @if (session('error')) <div class="cf-flash cf-flash-err">{{ session('error') }}</div> @endif

    @if ($unreadCount > 0)
        <div class="cf-flash cf-flash-ok">
            <b>{{ $unreadCount }}</b> consent {{ Str::plural('form', $unreadCount) }} signed by parents since your last visit.
        </div>
    @endif

    <div class="cf-legend">
        @foreach (HealthConsentForm::statusBadges() as $badge)
            <span class="cf-badge" style="background: {{ $badge['bg'] }}; color: {{ $badge['fg'] }};">{{ $badge['label'] }}</span>
        @endforeach
    </div>

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
                        <th>Parent / Guardian</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($students as $student)
                        @php
                            $form = $forms->get($student['lrn']);
                            $badge = $form ? $form->statusBadge() : null;
                        @endphp
                        <tr>
                            <td><b>{{ $student['name'] }}</b></td>
                            <td>{{ $student['lrn'] }}</td>
                            <td>{{ $student['parent_guardian'] }}</td>
                            <td>
                                @if ($badge)
                                    <span class="cf-badge" style="background: {{ $badge['bg'] }}; color: {{ $badge['fg'] }};">{{ $badge['label'] }}</span>
                                    @if ($form->adviser_unread) <span class="cf-badge" style="background:#fee2e2;color:#991b1b;">New</span> @endif
                                @else
                                    <span class="cf-badge" style="background:#f8fafc;color:#94a3b8;">Not started</span>
                                @endif
                            </td>
                            <td style="text-align:right;">
                                <form method="POST" action="{{ route('consent-forms.open') }}" style="display:inline;">
                                    @csrf
                                    <input type="hidden" name="lrn" value="{{ $student['lrn'] }}">
                                    <button type="submit" class="cf-btn {{ $form ? 'cf-btn-outline' : 'cf-btn-primary' }}">
                                        {{ $form ? 'Open Form' : 'Create Form' }}
                                    </button>
                                </form>
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
