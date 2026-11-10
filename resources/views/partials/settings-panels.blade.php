{{--
    Account settings — the body, shared by every role.

    Account details, password changes and teacher class reassignment. Each
    form acts only on the signed-in account; roles and schools stay read-only.

    The shell around this is the role's own (dashboard/settings for the six
    roles on the LUSOG rail, nutricor/settings for that role's layout), because
    a page has to arrive in the rail its reader navigates by. The body is one
    copy so the two shells cannot drift — and css/settings.css styles all of
    it under .set-page, so it looks the same whichever shell's base styles it
    lands on.

    Expects: $profile (App\Support\AccountSettings::profileFor) and
    $passwordBlockedReason, $assignmentSchoolYear, $calendarSchoolYear,
    $assignmentYearOptions and $assignmentCatalog.
--}}
@php
    use App\Support\AccountPassword;

    $stRow = function (string $label, ?string $value) {
        return ['label' => $label, 'value' => trim((string) $value)];
    };

    $stName = trim((string) $profile['name']);
    $stInitials = collect(preg_split('/\s+/', $stName))
        ->filter()
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->take(2)
        ->implode('');

    // What this account is. A value the app does not hold prints as an em
    // dash — never a plausible-looking guess at somebody's school. The name
    // and role head the card, so the list carries the rest.
    $stRows = [
        $stRow('Username', $profile['username']),
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

<div class="set-page">
    @if (session('success'))
        <div class="flash ok">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="flash err">{{ session('error') }}</div>
    @endif

    <div class="set-layout">
        <aside class="set-side">
            <section class="card set-panel set-profile" aria-labelledby="accountTitle">
                <h2 class="set-visually-hidden" id="accountTitle">Your account</h2>
                <div class="set-profile-top">
                    <div class="set-avatar" aria-hidden="true">{{ $stInitials !== '' ? $stInitials : '?' }}</div>
                    <div class="set-profile-id">
                        <div class="set-profile-name">{{ $stName !== '' ? $stName : '—' }}</div>
                        <span class="set-role">{{ $profile['role_label'] }}</span>
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
        </aside>

        <div class="set-main">
            <section class="card set-panel" aria-labelledby="passwordTitle">
                <div class="set-head">
                    <span class="set-head-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </span>
                    <div class="set-head-text">
                        <h2 class="card-title" id="passwordTitle">{{ $profile['has_password'] ? 'Change your password' : 'Set your password' }}</h2>
                        <p class="card-sub">
                            @if ($profile['has_password'])
                                You will keep using the same username.
                            @else
                                This account has no password on file yet.
                            @endif
                        </p>
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

                        <div class="set-fields">
                            @if ($profile['has_password'])
                                <div class="set-field set-field-wide">
                                    <label class="field-label" for="current_password">Current password</label>
                                    <div class="set-secret">
                                        <input class="input" id="current_password" name="current_password" type="password"
                                               autocomplete="current-password" required>
                                        @include('partials.settings-reveal', ['for' => 'current_password'])
                                    </div>
                                    @error('current_password') <div class="err">{{ $message }}</div> @enderror
                                </div>
                            @endif

                            <div class="set-field">
                                <label class="field-label" for="password">New password</label>
                                <div class="set-secret">
                                    <input class="input" id="password" name="password" type="password"
                                           autocomplete="new-password" minlength="{{ AccountPassword::MIN_LENGTH }}"
                                           maxlength="{{ AccountPassword::MAX_LENGTH }}" required>
                                    @include('partials.settings-reveal', ['for' => 'password'])
                                </div>
                                <div class="set-hint muted">{{ AccountPassword::requirementHint() }}</div>
                                @error('password') <div class="err">{{ $message }}</div> @enderror
                            </div>

                            <div class="set-field">
                                <label class="field-label" for="password_confirmation">Confirm new password</label>
                                <div class="set-secret">
                                    <input class="input" id="password_confirmation" name="password_confirmation" type="password"
                                           autocomplete="new-password" required>
                                    @include('partials.settings-reveal', ['for' => 'password_confirmation'])
                                </div>
                            </div>
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

            @if (! empty($healthServices))
                @include('partials.settings-health-services')
            @endif

            @if (! empty($progressWeighIns))
                @include('partials.settings-progress-weigh-ins')
            @endif
        </div>
    </div>
</div>

<script>
(() => {
    // Show or hide a password while it is typed. Every field goes back to
    // masked before the form is sent, so a browser never saves it as text.
    document.querySelectorAll('.set-reveal').forEach((button) => {
        const input = document.getElementById(button.dataset.for);
        if (!input) return;
        button.addEventListener('click', () => {
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.setAttribute('aria-pressed', show ? 'true' : 'false');
            button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            input.focus();
        });
        if (input.form) {
            input.form.addEventListener('submit', () => { input.type = 'password'; });
        }
    });
})();
</script>
