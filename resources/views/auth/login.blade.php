<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#126B3A">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}?v=2">
    <link rel="shortcut icon" href="{{ asset('images/lusog-logo.png') }}?v=2">
    <title>SIGLA : School-Based Information Governance for Life Care Administration</title>
    {{-- The product's two faces: DM Serif Display for the title, Inter for
         everything else. This page used Nunito, so the first screen a user
         met spoke in a voice no other screen in the system uses. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        /* Every colour here reads through a LUSOG token, with the token's own
           value as the fallback. The shared palette is inlined after this block
           and declares them for real; the fallbacks only keep the page on
           palette if it ever fails to load. */
        :root {
            --ink:        var(--lg-ink, #1F2D25);
            --ink-soft:   var(--lg-ink-soft, #6B7C72);
            --line:       var(--lg-border, #DCE8E0);
            --line-firm:  var(--lg-border-strong, #C7DCCE);
            --card:       var(--lg-card, #FFFFFF);
            --page:       var(--lg-page, #F6F9F7);
            --brand:      var(--lg-emerald, #1F8A4C);
            --brand-deep: var(--lg-emerald-deep, #126B3A);
            --brand-dark: var(--lg-emerald-dark, #0E5730);
            --mint:       var(--lg-mint, #E7F5EC);
            --focus:      var(--lg-focus, 0 0 0 3px rgba(31, 138, 76, .28));

            --r-card:  var(--lg-r-card, 12px);
            --r-btn:   var(--lg-r-btn, 9px);
            --r-input: var(--lg-r-input, 8px);

            --shadow: 0 18px 48px rgba(14, 45, 30, .13), 0 2px 6px rgba(14, 45, 30, .05);
        }

        html, body {
            width: 100%;
        }

        /* 100vh, not 100%: a percentage min-height resolves against html's
           auto height and collapses to the content, which left the card
           sitting against the top of the window instead of centred in it. */
        body {
            font-family: 'Inter', 'DM Sans', system-ui, -apple-system, 'Segoe UI', sans-serif;
            font-size: 15px;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
            background: var(--page);
            color: var(--ink);
            padding: 32px 28px;
            display: grid;
            place-items: center;
        }

        /* min-width: 0 on the shell and on both columns is load-bearing, not
           tidiness. A grid item's default min-width is auto, which floors it at
           its own min-content width — so on a narrow phone the card refused to
           shrink past its longest line and pushed the form's right edge off
           the screen, taking the password toggle with it. */
        .shell {
            width: min(1080px, 100%);
            max-width: 100%;
            min-width: 0;
            min-height: 620px;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--r-card);
            overflow: hidden;
            box-shadow: var(--shadow);
            display: grid;
            grid-template-columns: 1.05fr 1fr;
        }

        .brand, .panel { min-width: 0; }

        /* ── Brand panel ──────────────────────────────────────────────────
           Static by design. It used to carry four blurred orbs on infinite
           keyframes: a perpetual composite on a screen whose only job is a
           two-field form, and a visual register that reads as a marketing
           page rather than a health record system. The depth is now built
           from layered gradients and a ring motif, which cost one paint and
           hold still while somebody types a password. */
        .brand {
            position: relative;
            isolation: isolate;
            overflow: hidden;
            padding: 56px 48px;
            display: grid;
            place-items: center;
            background:
                radial-gradient(120% 95% at 12% 6%, rgba(107, 201, 146, .30), transparent 58%),
                radial-gradient(90% 80% at 92% 96%, rgba(3, 46, 26, .42), transparent 55%),
                linear-gradient(158deg, #17814A 0%, var(--brand-deep) 46%, var(--brand-dark) 100%);
        }

        /* A faint dot grid: institutional texture that survives a projector and
           a cheap monitor, where a soft blur just turns into a grey smear. */
        .brand::before {
            content: "";
            position: absolute;
            inset: 0;
            background-image: radial-gradient(rgba(255, 255, 255, .16) 1px, transparent 1px);
            background-size: 22px 22px;
            opacity: .5;
            -webkit-mask-image: radial-gradient(80% 70% at 50% 40%, #000 30%, transparent 78%);
            mask-image: radial-gradient(80% 70% at 50% 40%, #000 30%, transparent 78%);
            pointer-events: none;
        }

        .rings {
            position: absolute;
            inset: 0;
            pointer-events: none;
            overflow: hidden;
        }

        .rings span {
            position: absolute;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, .13);
        }

        .rings span:nth-child(1) { width: 520px; height: 520px; top: -190px; right: -200px; }
        .rings span:nth-child(2) { width: 340px; height: 340px; top: -100px; right: -110px; border-color: rgba(255, 255, 255, .2); }
        .rings span:nth-child(3) { width: 620px; height: 620px; bottom: -300px; left: -240px; }
        .rings span:nth-child(4) { width: 400px; height: 400px; bottom: -190px; left: -140px; border-color: rgba(255, 255, 255, .09); }

        .brand-wrap {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 400px;
            text-align: center;
            animation: riseIn .55s cubic-bezier(.2, .7, .3, 1) both;
        }

        .brand-logo {
            width: 100%;
            max-width: 290px;
            height: auto;
            display: block;
            margin: 0 auto;
            filter: drop-shadow(0 10px 22px rgba(5, 26, 16, .28));
        }

        .brand-fallback {
            display: none;
            font-family: 'DM Serif Display', Georgia, serif;
            color: #F2FBF6;
            letter-spacing: .02em;
            font-size: 3rem;
            line-height: 1.05;
        }

        /* A hairline instead of a gap: it reads as a considered lockup rather
           than two things that happen to sit near each other. */
        .brand-rule {
            width: 52px;
            height: 1px;
            margin: 22px auto 18px;
            background: rgba(255, 255, 255, .32);
        }

        .brand-tag {
            color: rgba(236, 251, 243, .9);
            font-size: .875rem;
            font-weight: 400;
            line-height: 1.6;
            letter-spacing: .012em;
        }

        /* ── Form panel ── */
        .panel {
            padding: 52px 52px 40px;
            background: var(--card);
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .top { margin-bottom: 26px; }

        /* The product sets page titles in DM Serif Display. Using it here is
           what makes this screen and the dashboard behind it one product. */
        .top h1 {
            font-family: 'DM Serif Display', Georgia, serif;
            font-size: 2.35rem;
            font-weight: 400;
            line-height: 1.12;
            letter-spacing: -.008em;
            color: var(--ink);
            margin-bottom: 9px;
        }

        .top p {
            color: var(--ink-soft);
            font-size: .9rem;
            line-height: 1.55;
        }

        .alert-error {
            background: var(--lg-danger-tint, #FCECEC);
            border: 1px solid rgba(217, 92, 92, .38);
            border-left: 3px solid var(--lg-danger, #D95C5C);
            color: var(--lg-danger-ink, #A32B2B);
            border-radius: var(--r-input);
            padding: 11px 13px;
            font-size: .85rem;
            font-weight: 500;
            line-height: 1.45;
            margin-bottom: 14px;
        }

        .field { margin-bottom: 16px; }

        .field label {
            font-size: .7rem;
            color: var(--ink-soft);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .09em;
            display: block;
            margin-bottom: 7px;
        }

        .control {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: var(--r-input);
            background: var(--card);
            min-height: 48px;
            padding: 12px 14px;
            font: inherit;
            font-size: .94rem;
            color: var(--ink);
            outline: none;
            transition: border-color .16s ease, box-shadow .16s ease, background .16s ease;
        }

        .control::placeholder { color: #A8B5AE; }

        .control:hover { border-color: var(--line-firm); }

        .control:focus {
            border-color: var(--brand);
            box-shadow: var(--focus);
        }

        .control.is-error {
            border-color: var(--lg-danger, #D95C5C);
            background: var(--lg-danger-tint, #FCECEC);
        }

        select.control {
            appearance: none;
            cursor: pointer;
            padding-right: 40px;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8' fill='none'%3E%3Cpath d='M1 1.5 6 6.5l5-5' stroke='%236B7C72' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
        }

        .pw-wrap { position: relative; }

        .pw-wrap .control { padding-right: 72px; }

        /* Fixed width so SHOW and HIDE occupy the same box. The label used to
           change width under the cursor as it toggled. */
        .toggle-pw {
            position: absolute;
            right: 7px;
            top: 50%;
            transform: translateY(-50%);
            min-width: 54px;
            border: none;
            border-radius: 6px;
            background: transparent;
            color: var(--ink-soft);
            cursor: pointer;
            font: inherit;
            font-size: .66rem;
            font-weight: 700;
            letter-spacing: .08em;
            padding: 7px 8px;
            transition: background .16s ease, color .16s ease;
        }

        .toggle-pw:hover {
            background: var(--mint);
            color: var(--brand-deep);
        }

        .remember {
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 9px;
            color: var(--ink-soft);
            font-size: .855rem;
            cursor: pointer;
            user-select: none;
        }

        .remember input {
            width: 16px;
            height: 16px;
            accent-color: var(--brand);
            cursor: pointer;
        }

        .submit {
            margin-top: 22px;
            width: 100%;
            border: none;
            border-radius: var(--r-btn);
            min-height: 48px;
            background: var(--brand-deep);
            color: #fff;
            font: inherit;
            font-size: .95rem;
            font-weight: 600;
            letter-spacing: .01em;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            box-shadow: 0 1px 2px rgba(14, 45, 30, .16);
            transition: background .16s ease, box-shadow .16s ease, transform .08s ease;
        }

        .submit svg { transition: transform .18s cubic-bezier(.2, .7, .3, 1); }

        .submit:hover {
            background: var(--brand-dark);
            box-shadow: 0 4px 14px rgba(14, 45, 30, .2);
        }

        .submit:hover svg { transform: translateX(3px); }

        .submit:active { transform: translateY(1px); }

        .submit.loading {
            opacity: .75;
            cursor: wait;
        }

        .submit.loading svg { transform: none; }

        /* One visible ring for every keyboard target, which the page carried
           only on the inputs before. */
        .toggle-pw:focus-visible,
        .submit:focus-visible,
        .link:focus-visible,
        .demo-toggle:focus-visible,
        .demo-copy-btn:focus-visible,
        .remember input:focus-visible {
            outline: 2px solid var(--brand);
            outline-offset: 2px;
        }

        .foot {
            margin-top: 26px;
            padding-top: 18px;
            border-top: 1px solid var(--line);
            text-align: center;
            font-size: .78rem;
            color: var(--ink-soft);
            line-height: 1.7;
        }

        /* Nothing in this product underlines a word. The link is carried by
           colour, weight and a tinted hover. */
        .link {
            color: var(--brand-deep);
            font-weight: 600;
            text-decoration: none;
            border-radius: 5px;
            padding: 2px 5px;
            transition: background .16s ease, color .16s ease;
        }

        .link:hover {
            background: var(--mint);
            color: var(--brand-dark);
        }

        .version {
            display: block;
            margin-top: 8px;
            font-size: .68rem;
            color: #9AA8A1;
            font-variant-numeric: var(--lg-figures, tabular-nums lining-nums slashed-zero);
        }

        /* ── Demo panel ── */
        .demo-panel {
            border: 1px solid var(--line);
            border-radius: var(--r-input);
            background: var(--lg-rail, #EEF4F0);
            margin-bottom: 18px;
            overflow: hidden;
        }

        .demo-toggle {
            width: 100%;
            background: none;
            border: none;
            padding: 11px 13px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            font: inherit;
            font-size: .76rem;
            font-weight: 600;
            color: var(--brand-deep);
            text-align: left;
        }

        .demo-toggle:hover { background: var(--mint); }

        .demo-toggle-icon {
            font-size: .62rem;
            transition: transform .2s ease;
        }

        .demo-toggle.open .demo-toggle-icon { transform: rotate(180deg); }

        .demo-body {
            display: none;
            padding: 0 13px 12px;
        }

        .demo-body.open { display: block; }

        .demo-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .74rem;
        }

        .demo-table th {
            text-align: left;
            color: var(--ink-soft);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .07em;
            font-size: .64rem;
            padding: 5px 6px 6px 0;
            border-bottom: 1px solid var(--line-firm);
        }

        .demo-table td {
            padding: 6px 6px 6px 0;
            color: var(--ink);
            border-bottom: 1px solid var(--line);
            vertical-align: middle;
        }

        .demo-table tr:last-child td { border-bottom: none; }

        .demo-table code {
            font-family: var(--lg-mono, ui-monospace, 'SF Mono', Menlo, Consolas, monospace);
            font-size: .72rem;
            color: var(--ink);
        }

        .demo-role-badge {
            display: inline-block;
            background: var(--mint);
            color: var(--lg-success-ink, #14653C);
            border-radius: var(--lg-r-pill, 999px);
            padding: 3px 9px;
            font-size: .65rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .demo-copy-btn {
            background: var(--card);
            border: 1px solid var(--line-firm);
            border-radius: 6px;
            color: var(--brand-deep);
            cursor: pointer;
            font: inherit;
            font-size: .64rem;
            font-weight: 600;
            padding: 4px 9px;
            white-space: nowrap;
            text-decoration: none;
            display: inline-block;
            transition: background .16s ease, border-color .16s ease;
        }

        .demo-copy-btn:hover {
            background: var(--mint);
            border-color: var(--brand);
        }

        .demo-note {
            font-size: .68rem;
            color: var(--ink-soft);
            margin-top: 9px;
            line-height: 1.5;
        }

        @keyframes riseIn {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: .001ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: .001ms !important;
            }
        }

        @media (max-width: 940px) {
            body {
                padding: 0;
                background: var(--card);
                place-items: stretch;
            }

            .shell {
                width: 100%;
                min-height: 100vh;
                border: none;
                border-radius: 0;
                box-shadow: none;
                grid-template-columns: 1fr;
                align-content: start;
            }

            .brand { padding: 40px 28px 36px; }

            .brand-logo { max-width: 200px; }

            .brand-rule { margin: 16px auto 13px; }

            .brand-tag { font-size: .82rem; }

            .panel { padding: 34px 24px 30px; }

            .top h1 { font-size: 1.95rem; }
        }

        @media (max-width: 420px) {
            .panel { padding: 28px 18px 24px; }
            .top h1 { font-size: 1.75rem; }
        }
    </style>
    {{-- One shared palette for pages not yet on lusog-theme.css. Loaded
         last so it overrides this page's own :root colours. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-palette.css')) !!}</style>
</head>
<body>
<main class="shell">
    <section class="brand">
        <div class="rings" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
        <div class="brand-wrap">
            <img
                src="{{ asset('images/lusog-logo.png') }}"
                alt="SIGLA Logo"
                class="brand-logo"
                onerror="this.style.display='none';document.getElementById('logoFallback').style.display='block';"
            >
            <div class="brand-fallback" id="logoFallback">SIGLA</div>
            <div class="brand-rule" aria-hidden="true"></div>
            {{-- What the acronym stands for. Sits under the mark so the first
                 thing a new user reads is what the system actually is. --}}
            <p class="brand-tag">School-Based Information Governance<br>for Life Care Administration</p>
        </div>
    </section>

    <section class="panel">
        <div class="top">
            <h1>Welcome back</h1>
            <p>Enter your credentials to access your assigned modules.</p>
        </div>


        @if (!empty($demoAccounts))
        <div class="demo-panel">
            <button type="button" class="demo-toggle" id="demoToggle" onclick="toggleDemo()">
                <span>&#128274; Demo Accounts (for testing)</span>
                <span class="demo-toggle-icon" id="demoIcon">&#9660;</span>
            </button>
            <div class="demo-body" id="demoBody">
                <table class="demo-table">
                    <thead>
                        <tr>
                            <th>Role</th>
                            <th>Username</th>
                            <th>Password</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($demoAccounts as $demo)
                        <tr>
                            <td><span class="demo-role-badge">{{ $demo['label'] }}</span></td>
                            <td><code>{{ $demo['username'] }}</code></td>
                            <td><code>{{ $demo['password'] }}</code></td>
                            <td>
                                <button type="button" class="demo-copy-btn"
                                    onclick="fillLogin('{{ $demo['username'] }}', '{{ $demo['password'] }}')">
                                    Use
                                </button>
                            </td>
                        </tr>
                        @endforeach
                        <tr>
                            <td><span class="demo-role-badge">System Admin</span></td>
                            <td><code>systemadmin</code></td>
                            <td><code>admin123</code></td>
                            <td>
                                <a href="{{ route('admin.login') }}" class="demo-copy-btn">
                                    Go
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="demo-note">
                    Click <strong>Use</strong> to auto-fill the login form, or copy credentials manually. System Admin uses a separate login page.
                </p>
            </div>
        </div>
        @endif

        @if ($errors->any())
            <div class="alert-error" role="alert">{{ $errors->first() }}</div>
        @endif

        @if (session('error'))
            <div class="alert-error" role="alert">{{ session('error') }}</div>
        @endif

        <form method="POST" action="{{ route('login') }}" id="loginForm">
            @csrf

            <div class="field">
                <label for="email">Username / Employee ID</label>
                <input
                    type="text"
                    id="email"
                    name="email"
                    value="{{ old('email') }}"
                    placeholder="schoolnurse.maria"
                    autocomplete="username"
                    required
                    class="control {{ $errors->has('email') ? 'is-error' : '' }}"
                >
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="pw-wrap">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter password"
                        autocomplete="current-password"
                        required
                        class="control"
                    >
                    <button type="button" class="toggle-pw" onclick="togglePassword()" id="togglePw" aria-label="Show or hide password">SHOW</button>
                </div>
            </div>

            @if (session('school_choices'))
                <div class="field">
                    <label for="institution_id">School</label>
                    <select id="institution_id" name="institution_id" required class="control">
                        <option value="">Select your school</option>
                        @foreach (session('school_choices') as $institutionId => $schoolName)
                            <option value="{{ $institutionId }}" {{ (string) old('institution_id') === (string) $institutionId ? 'selected' : '' }}>
                                {{ $schoolName }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif

            <label class="remember">
                <input type="checkbox" id="remember" name="remember" {{ old('remember') ? 'checked' : '' }}>
                Keep me signed in for 30 days
            </label>

            <button type="submit" class="submit" id="submitBtn">
                Sign in
                <svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M2.5 8h10M9 4.5 12.5 8 9 11.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>

            <p class="foot">
                Forgot your password? Contact your system administrator.<br>
                <a href="{{ route('account.request') }}" class="link">Create Account</a>
                <span class="version">SIGLA v1.0 &middot; School-Based Information Governance for Life Care Administration</span>
            </p>
        </form>
    </section>
</main>

<script>
    function togglePassword() {
        const input = document.getElementById('password');
        const btn = document.getElementById('togglePw');
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.textContent = show ? 'HIDE' : 'SHOW';
    }

    function toggleDemo() {
        const body = document.getElementById('demoBody');
        const toggle = document.getElementById('demoToggle');
        const icon = document.getElementById('demoIcon');
        const open = body.classList.toggle('open');
        toggle.classList.toggle('open', open);
    }

    function fillLogin(username, password) {
        document.getElementById('email').value = username;
        document.getElementById('password').value = password;
        if (document.getElementById('password').type === 'text') {
            // keep visible
        } else {
            document.getElementById('password').type = 'text';
            document.getElementById('togglePw').textContent = 'HIDE';
        }
    }

    document.getElementById('loginForm').addEventListener('submit', function () {
        document.getElementById('submitBtn').classList.add('loading');
    });
</script>
</body>
</html>
