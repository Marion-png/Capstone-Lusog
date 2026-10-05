{{--
    Account settings — the body, shared by every role.

    Account details, password changes and teacher class reassignment. Each
    form acts only on the signed-in account; roles and schools stay read-only.

    The shell around this is the role's own (dashboard/settings for the six
    roles on the LUSOG rail, nutricor/settings for that role's layout), because
    a page has to arrive in the rail its reader navigates by. The body is one
    copy so the two shells cannot drift.

    Expects: $profile (App\Support\AccountSettings::profileFor) and
    $passwordBlockedReason, $assignmentSchoolYear and $assignmentCatalog.
--}}
@php
    use App\Support\AccountPassword;

    $stRow = function (string $label, ?string $value) {
        return ['label' => $label, 'value' => trim((string) $value)];
    };

    // What this account is. A value the app does not hold prints as an em
    // dash — never a plausible-looking guess at somebody's school.
    $stRows = [
        $stRow('Full name', $profile['name']),
        $stRow('Username', $profile['username']),
        $stRow('Role', $profile['role_label']),
        $stRow('School', $profile['school_name']),
    ];

    if ($profile['shows_assignment']) {
        // The other half of a class adviser's scope: it decides which
        // learners they can open at all, so it is printed where they can
        // check it against what they were told.
        $stRows[] = $stRow('Grade level', $profile['assigned_grade_level']);
        $stRows[] = $stRow('Section', $profile['assigned_section']);
    }
@endphp

@if (session('success'))
    <div class="flash ok">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="flash err">{{ session('error') }}</div>
@endif

<div class="set-grid">
    <section class="card set-panel">
        <div class="card-head">
            <div>
                <div class="card-title">Your account</div>
                <div class="card-sub">Your current account details and school.</div>
            </div>
        </div>

        <dl class="set-list">
            @foreach ($stRows as $row)
                <div class="set-item">
                    <dt>{{ $row['label'] }}</dt>
                    <dd>{{ $row['value'] !== '' ? $row['value'] : '—' }}</dd>
                </div>
            @endforeach
        </dl>

        <p class="set-note muted">
            Contact the System Admin to correct your name, role or school.
            @if ($profile['shows_assignment'])
                Use Teaching assignment below when your grade or section changes.
            @endif
        </p>
    </section>

    <section class="card set-panel">
        <div class="card-head">
            <div>
                <div class="card-title">{{ $profile['has_password'] ? 'Change your password' : 'Set your password' }}</div>
                <div class="card-sub">
                    @if ($profile['has_password'])
                        You will keep using the same username.
                    @else
                        This account has no password on file yet.
                    @endif
                </div>
            </div>
        </div>

        @if ($passwordBlockedReason !== '')
            {{-- The panel explains itself rather than disappearing: a control
                 that vanishes leaves somebody with no way to tell "not for
                 you" from "broken". --}}
            <div class="flash info set-blocked">{{ $passwordBlockedReason }}</div>
        @else
            @if (! $profile['has_password'])
                {{-- Not a warning for its own sake: until a password is set,
                     the sign-in check matches this username against anything
                     typed, and this form is the only way to close that. --}}
                <div class="flash err set-nopass">
                    Until you set one, this account will accept <strong>any</strong> password at sign-in.
                </div>
            @endif

            <form method="POST" action="{{ route('settings.password') }}" class="set-form">
                @csrf

                @if ($profile['has_password'])
                    <div class="set-field">
                        <label class="field-label" for="current_password">Current password</label>
                        <input class="input" id="current_password" name="current_password" type="password"
                               autocomplete="current-password" required>
                        @error('current_password') <div class="err">{{ $message }}</div> @enderror
                    </div>
                @endif

                <div class="set-field">
                    <label class="field-label" for="password">New password</label>
                    <input class="input" id="password" name="password" type="password"
                           autocomplete="new-password" minlength="{{ AccountPassword::MIN_LENGTH }}"
                           maxlength="{{ AccountPassword::MAX_LENGTH }}" required>
                    <div class="set-hint muted">{{ AccountPassword::requirementHint() }}</div>
                    @error('password') <div class="err">{{ $message }}</div> @enderror
                </div>

                <div class="set-field">
                    <label class="field-label" for="password_confirmation">Confirm new password</label>
                    <input class="input" id="password_confirmation" name="password_confirmation" type="password"
                           autocomplete="new-password" required>
                </div>

                <div class="set-actions">
                    <button type="submit" class="btn btn-primary">
                        {{ $profile['has_password'] ? 'Change password' : 'Set password' }}
                    </button>
                </div>
            </form>
        @endif
    </section>

    @if ($profile['shows_assignment'])
        @include('partials.settings-assignment')
    @endif
</div>
