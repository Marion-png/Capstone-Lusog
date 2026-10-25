<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="{{ asset('images/sigla-mark.png') }}">
    <title>Consent Forms - {{ $roleLabel }} - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @php $cfCss = resource_path('css/consent-form.css'); @endphp
    @if (file_exists($cfCss)) <style>{!! file_get_contents($cfCss) !!}</style> @endif
    {{-- One shared palette for pages not yet on lusog-theme.css. Loaded
         last so it overrides this page's own :root colours. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-palette.css')) !!}</style>
</head>
{{-- .cf-rise plays the bottom-up entrance defined in css/consent-form.css,
     picking up where the nurse rail's exit transition leaves off. --}}
<body class="cf-rise">
@php
    use App\Models\HealthConsentForm;
    use App\Support\ConsentStanding;

    $serviceName = $service !== '' ? $services[$service] : 'All services';
    $gradeName = $grade !== null ? 'Grade '.$grade : 'All grade levels';
    $filtered = $service !== '' || $grade !== null || $status !== '' || ! $isCurrentYear;
@endphp

<header class="cf-topbar">
    <a href="{{ $dashboardUrl }}" class="cf-back cf-back-start">&larr; Back to Dashboard</a>
    <img src="{{ asset('images/sigla-mark.png') }}" alt="SIGLA">
    <div>
        <div class="cf-topbar-title">Health Services Consent Forms</div>
        <div class="cf-topbar-sub">Signed in as {{ $roleLabel }} &middot; view only</div>
    </div>
    @include('partials.nurse-learner-search')
</header>

<div class="cf-wrap">
    @include('consent-forms._simulated-banner')

    <h1 class="cf-page-title">Consent Forms</h1>
    <p class="cf-page-sub">Sulat-Pahibalo records uploaded by class advisers &middot; SY {{ str_replace('-', '–', $schoolYear) }}. These are read-only &mdash; only the class adviser can upload or replace a form.</p>

    @if (session('error')) <div class="cf-flash cf-flash-err">{{ session('error') }}</div> @endif

    {{-- An earlier year is a record, and says so: its forms and scans are kept
         exactly as they were left when the year ended, and a letter still
         "awaiting" there was never answered rather than still on its way. --}}
    @unless ($isCurrentYear)
        <div class="cf-flash cf-flash-info" id="pastYearNotice">
            <b>S.Y. {{ $schoolYear }} has ended.</b> Its consent forms and the signed forms scanned with them are kept
            as they were when the year closed. A consent covers the year it was given in &mdash; it does not authorise a
            service this school year.
            <a href="{{ route('consent-forms.nurse-index') }}">Back to S.Y. {{ $currentSchoolYear }}</a>
        </div>
    @endunless

    {{-- School year, health service and grade are scope: they move the cards
         and the list together. The consent status and the name search narrow
         the list alone. The search runs here in the browser over the rows on
         the page — a name sent in a URL is written into the audit trail. --}}
    <form method="GET" class="cf-filter" id="nurseConsentFilter">
        <label for="consentYear">School year</label>
        <select name="school_year" id="consentYear" onchange="this.form.submit()">
            @foreach ($schoolYears as $year)
                <option value="{{ $year }}" @selected($schoolYear === $year)>S.Y. {{ $year }}{{ $year === $currentSchoolYear ? ' (current)' : '' }}</option>
            @endforeach
        </select>
        <label for="consentService">Health service</label>
        <select name="service" id="consentService" onchange="this.form.submit()">
            <option value="">All services</option>
            @foreach ($services as $key => $label)
                <option value="{{ $key }}" @selected($service === $key)>{{ $label }}</option>
            @endforeach
        </select>
        <label for="consentGrade">Grade level</label>
        <select name="grade" id="consentGrade" onchange="this.form.submit()">
            <option value="">All grade levels</option>
            @foreach ($grades as $number)
                <option value="{{ $number }}" @selected($grade === $number)>Grade {{ $number }}</option>
            @endforeach
        </select>
        <label for="consentStatus">Consent status</label>
        <select name="status" id="consentStatus" onchange="this.form.submit()">
            <option value="">All statuses</option>
            @foreach ($statusFilters as $value => $label)
                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <label for="consentSearch" hidden data-search-part>Search learner</label>
        <input type="search" id="consentSearch" class="cf-search" placeholder="Learner name" autocomplete="off" hidden data-search-part>
        <noscript><button type="submit" class="cf-btn cf-btn-outline">Apply</button></noscript>
        <a href="{{ route('consent-forms.nurse-index') }}" class="cf-filter-clear" id="consentClear" @if (! $filtered) hidden data-search-clear @endif>Clear filters</a>
    </form>
    <p class="cf-context">Showing {{ $serviceName }} &middot; {{ $gradeName }}</p>

    {{-- The figures for the chosen service, grade and year, counted from the
         same rows the list prints. A learner the service does not apply to,
         or who was not enrolled, is in none of them. --}}
    <div class="cf-stats">
        <div class="cf-stat">
            <div class="cf-stat-label">Consented</div>
            <div class="cf-stat-value">{{ $cards['consented'] }}</div>
            <div class="cf-stat-hint">
                of {{ $cards['applicable'] }} {{ $cards['applicable'] === 1 ? 'learner' : 'learners' }} this service applies to
                @if ($service === '' && $cards['with_exceptions'] > 0)
                    &middot; {{ $cards['with_exceptions'] }} with exceptions
                @endif
                @if ($service !== '' && $cards['check_exceptions'] > 0)
                    &middot; {{ $cards['check_exceptions'] }} to check (exceptions written by hand)
                @endif
            </div>
        </div>
        <div class="cf-stat">
            <div class="cf-stat-label">Declined</div>
            <div class="cf-stat-value">{{ $cards['declined'] }}</div>
            <div class="cf-stat-hint">parent declined {{ $service !== '' ? 'this service' : 'the services' }}</div>
        </div>
        <div class="cf-stat">
            <div class="cf-stat-label">No form yet</div>
            <div class="cf-stat-value">{{ $cards['no_form'] }}</div>
            <div class="cf-stat-hint">follow up with the class adviser</div>
        </div>
        <div class="cf-stat is-primary">
            <div class="cf-stat-label">Consent rate</div>
            <div class="cf-stat-value">{{ $cards['rate'] !== null ? $cards['rate'].'%' : '—' }}</div>
            <div class="cf-stat-hint">of applicable learners</div>
        </div>
    </div>

    <div class="cf-card">
        <div class="cf-card-head">
            <h2>Learners <span class="cf-count" id="consentShown">{{ $rows->count() }}</span></h2>
            <span class="cf-muted"><span id="consentShownWord">{{ $rows->count() }}</span> shown</span>
        </div>
        @if ($rows->isEmpty())
            <div class="cf-empty">
                @if ($filtered)
                    No learners match these filters.
                    <a href="{{ route('consent-forms.nurse-index') }}">Clear filters</a>
                @elseif ($isCurrentYear)
                    No learners are on this year's roll yet.
                @else
                    No learners were on the roll in S.Y. {{ $schoolYear }}.
                @endif
            </div>
        @else
            <div class="cf-table-wrap">
            <table class="cf-table" id="consentTable">
                <thead>
                    <tr>
                        <th>Learner</th>
                        <th>Grade</th>
                        <th>Section</th>
                        <th>Class adviser</th>
                        <th>{{ $service !== '' ? 'Status for this service' : 'Status' }}</th>
                        <th>Programs consented</th>
                        <th>Form</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php
                            $answer = $row['answer'];
                            $programNames = $answer !== null ? HealthConsentForm::serviceNames($answer['services']) : [];
                        @endphp
                        <tr data-name="{{ mb_strtolower($row['name']) }}">
                            <td>
                                <b>{{ $row['name'] }}</b>
                                @if ($row['new_enrollee']) <span class="cf-tag">New enrollee</span> @endif
                                <br><span class="cf-lrn">LRN {{ $row['lrn_masked'] }}</span>
                            </td>
                            <td>{{ $row['grade'] }}</td>
                            <td>{{ $row['section'] }}</td>
                            <td>{!! $row['adviser'] !== '' ? e($row['adviser']) : '<span class="cf-muted">—</span>' !!}</td>
                            <td>
                                <span class="cf-badge is-{{ $row['status'] }}">{{ ConsentStanding::SERVICE_LABELS[$row['status']] }}</span>
                                @if ($row['dated_label'] !== '')
                                    <div class="cf-muted cf-dated">{{ $row['dated_label'] }}</div>
                                @endif
                            </td>
                            <td class="cf-programs">
                                @switch($answer['choice'] ?? null)
                                    @case(HealthConsentForm::CONSENT_ALL)
                                        @if ($programNames !== [])
                                            {{ implode(' · ', $programNames) }}
                                        @else
                                            <span class="cf-muted">No program listed on the form</span>
                                        @endif
                                        @break
                                    @case(HealthConsentForm::CONSENT_SPECIFIC)
                                        {{ implode(' · ', $programNames) }}
                                        <div class="cf-except">
                                            @if ($answer['exceptions'] !== '')
                                                Except, as written: &ldquo;{{ $answer['exceptions'] }}&rdquo;
                                            @else
                                                Except: nothing written &mdash; open the form
                                            @endif
                                        </div>
                                        @break
                                    @case(HealthConsentForm::CONSENT_DENY)
                                        None
                                        @break
                                    @default
                                        <span class="cf-muted">No answer yet</span>
                                @endswitch
                            </td>
                            <td style="text-align:right;">
                                <a href="{{ $row['view_url'] }}" class="cf-btn cf-btn-outline">View</a>
                            </td>
                        </tr>
                    @endforeach
                    <tr id="consentNoMatch" hidden>
                        <td colspan="7" class="cf-empty">
                            No learners match these filters.
                            <a href="{{ route('consent-forms.nurse-index') }}">Clear filters</a>
                        </td>
                    </tr>
                </tbody>
            </table>
            </div>
        @endif
    </div>
</div>

<script>
    // The name search: shown only where it can run, and it narrows the rows
    // already on the page — never the cards, and never sent to the server.
    (function () {
        const input = document.getElementById('consentSearch');
        const table = document.getElementById('consentTable');
        const clear = document.getElementById('consentClear');
        if (!input) return;

        document.querySelectorAll('[data-search-part]').forEach((el) => { el.hidden = false; });
        if (!table) { input.disabled = true; return; }

        const rows = Array.from(table.querySelectorAll('tbody tr[data-name]'));
        const noMatch = document.getElementById('consentNoMatch');
        const counts = [document.getElementById('consentShown'), document.getElementById('consentShownWord')];

        // Enter would submit the filters and reload the page, losing the term.
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') event.preventDefault();
        });

        input.addEventListener('input', () => {
            const term = input.value.trim().toLowerCase();
            let shown = 0;

            rows.forEach((row) => {
                const match = term === '' || row.dataset.name.includes(term);
                row.hidden = !match;
                if (match) shown++;
            });

            counts.forEach((el) => { if (el) el.textContent = String(shown); });
            if (noMatch) noMatch.hidden = shown !== 0;
            if (clear && clear.hasAttribute('data-search-clear')) clear.hidden = term === '';
        });
    })();
</script>
@include('partials.workspace-live')
</body>
</html>
