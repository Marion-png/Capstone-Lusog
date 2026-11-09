{{--
    Health Services Offered — the School Nurse's setting, one switch per
    service, per school year (App\Support\HealthServicesOffered).

    A service switched off reads Not offered on every consent form for that
    school year, so consent is not asked for it and it is never read as
    refused. The rules that hold in every school are not switches: they are
    printed beside the service they govern (ConsentStanding::appliesTo).

    Expects $healthServices (SettingsController::healthServicesPanel).
--}}
@php
    $hs = $healthServices;
    $hsRules = [
        'immunization_grade7' => 'Grade 7 only',
        'iron' => 'Female learners only',
    ];
@endphp
<section class="card set-panel set-services" id="health-services" aria-labelledby="healthServicesTitle">
    <div class="card-head">
        <div>
            <div class="card-title" id="healthServicesTitle">Health Services Offered</div>
            <div class="card-sub">The health services the school gives in a school year. A service switched off reads <strong>Not offered</strong> on every consent form, and consent is not asked for it.</div>
        </div>
    </div>

    {{-- Choosing a year reloads the page on that year's switches. --}}
    <form method="GET" action="{{ route('settings') }}#health-services" class="set-services-year">
        <label class="field-label" for="servicesYear">School year</label>
        <select class="input" id="servicesYear" name="services_year" onchange="this.form.submit()">
            @foreach ($hs['years'] as $year)
                <option value="{{ $year }}" @selected($year === $hs['school_year'])>{{ $year }}{{ $year === $hs['current_year'] ? ' (current)' : '' }}</option>
            @endforeach
        </select>
        <noscript><button type="submit" class="btn btn-secondary">Show</button></noscript>
    </form>

    @if (! $hs['supported'])
        <div class="flash info set-blocked">The database has not been updated for this setting yet. Every service is offered until it is.</div>
    @else
        @if ($hs['saved_for'] === null)
            <p class="set-note muted">Not saved for S.Y. {{ $hs['school_year'] }} yet — every service is offered.</p>
        @elseif ($hs['saved_for'] !== $hs['school_year'])
            <p class="set-note muted">Not saved for S.Y. {{ $hs['school_year'] }} yet — showing the setting carried over from S.Y. {{ $hs['saved_for'] }}.</p>
        @endif

        <form method="POST" action="{{ route('settings.health-services') }}" class="set-form">
            @csrf
            <input type="hidden" name="school_year" value="{{ $hs['school_year'] }}">

            @if ($errors->healthServices->any())
                <div class="flash err" role="alert">{{ $errors->healthServices->first() }}</div>
            @endif

            <ul class="set-switches">
                @foreach (\App\Models\HealthConsentForm::PLANNED_SERVICES as $key => $label)
                    <li>
                        <label class="set-switch">
                            <input type="checkbox" name="offered[]" value="{{ $key }}" role="switch" @checked(in_array($key, $hs['offered'], true))>
                            <span class="set-switch-track" aria-hidden="true"></span>
                            <span class="set-switch-label">
                                {{ $label }}
                                @isset($hsRules[$key])
                                    <span class="set-switch-rule">{{ $hsRules[$key] }}</span>
                                @endisset
                            </span>
                        </label>
                    </li>
                @endforeach
            </ul>

            <div class="set-actions">
                <button type="submit" class="btn btn-primary">Save for S.Y. {{ $hs['school_year'] }}</button>
            </div>
        </form>
    @endif
</section>
