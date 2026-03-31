<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>LUSOG - School Clinic Management System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --bg: #eef1ef;
            --panel: #27584b;
            --panel-2: #2f6354;
            --ink: #1f2d2a;
            --ink-soft: #61706d;
            --line: #d9e2de;
            --brand: #2f7e62;
            --brand-dark: #25664f;
            --mint: #e6f3ee;
            --card: #ffffff;
            --shadow: 0 28px 60px rgba(12, 39, 30, 0.18);
            --radius-lg: 16px;
            --radius-md: 10px;
            --radius-sm: 8px;
        }

        html, body {
            width: 100%;
            min-height: 100%;
        }

        body {
            font-family: 'Nunito', sans-serif;
            background:
                radial-gradient(1200px 500px at 25% -10%, rgba(47, 126, 98, 0.13), transparent 55%),
                radial-gradient(900px 500px at 90% 110%, rgba(63, 104, 91, 0.11), transparent 50%),
                var(--bg);
            color: var(--ink);
            padding: 28px;
            display: grid;
            place-items: center;
        }

        .shell {
            width: min(1120px, 100%);
            min-height: 660px;
            background: var(--card);
            border: 1px solid #e2e8e5;
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow);
            display: grid;
            grid-template-columns: 1fr 1fr;
        }

        .left {
            background: linear-gradient(165deg, var(--panel) 0%, var(--panel-2) 100%);
            padding: 42px;
            display: grid;
            place-items: center;
            position: relative;
            overflow: hidden;
        }

        .left::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 12% 14%, rgba(98, 188, 144, 0.25), transparent 38%),
                radial-gradient(circle at 90% 92%, rgba(26, 59, 49, 0.3), transparent 35%);
            pointer-events: none;
        }

        .left::after {
            content: "";
            position: absolute;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, 0.08);
            top: -70px;
            right: -80px;
            pointer-events: none;
        }

        .brand-wrap {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 420px;
            text-align: center;
            animation: riseIn .7s ease both;
        }

        .brand-logo {
            width: 100%;
            max-width: 320px;
            height: auto;
            display: block;
            margin: 0 auto 14px;
            filter: drop-shadow(0 16px 26px rgba(9, 28, 22, 0.28));
        }

        .brand-fallback {
            display: none;
            color: #eaf9f3;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            font-weight: 800;
            font-size: 2.2rem;
            line-height: 1.1;
            margin-bottom: 10px;
        }

        .brand-tag {
            color: rgba(235, 255, 246, 0.9);
            font-size: 0.95rem;
            line-height: 1.45;
        }

        .right {
            padding: 44px 50px 34px;
            background: #fcfdfc;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .top {
            margin-bottom: 14px;
        }

        .top h1 {
            font-size: 2rem;
            font-weight: 800;
            line-height: 1.1;
            color: #223532;
            margin-bottom: 8px;
        }

        .top p {
            color: #687774;
            font-size: 0.9rem;
        }

        .info-bar {
            background: var(--mint);
            border: 1px solid #cbe3d8;
            color: #356754;
            padding: 10px 12px;
            border-radius: var(--radius-sm);
            font-size: 0.78rem;
            margin-bottom: 16px;
            font-weight: 600;
        }

        .alert-error {
            background: #fff1f1;
            border: 1px solid #f8cdcd;
            color: #962f2f;
            border-radius: var(--radius-sm);
            padding: 10px 12px;
            font-size: 0.82rem;
            margin-bottom: 12px;
        }

        .field {
            margin-bottom: 12px;
        }

        .field label {
            font-size: 0.74rem;
            color: #6e7b78;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            display: block;
            margin-bottom: 6px;
        }

        .control {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: var(--radius-sm);
            background: #fff;
            min-height: 42px;
            padding: 10px 12px;
            font: inherit;
            color: var(--ink);
            outline: none;
            transition: border-color .18s ease, box-shadow .18s ease;
        }

        .control:focus {
            border-color: #7fbeaa;
            box-shadow: 0 0 0 3px rgba(63, 147, 114, 0.14);
        }

        .control.is-error {
            border-color: #ef9a9a;
        }

        .pw-wrap {
            position: relative;
        }

        .toggle-pw {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #82938f;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 700;
            padding: 2px 4px;
        }

        .role-grid {
            margin-top: 8px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .role-option {
            position: relative;
        }

        .role-option input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .role-card {
            border: 1px solid var(--line);
            border-radius: var(--radius-sm);
            background: #fff;
            padding: 9px 10px;
            min-height: 56px;
            cursor: pointer;
            display: grid;
            align-content: center;
            transition: border-color .18s ease, background .18s ease, box-shadow .18s ease;
        }

        .role-title {
            font-size: 0.82rem;
            color: #243533;
            font-weight: 700;
            line-height: 1.2;
        }

        .role-caption {
            font-size: 0.68rem;
            color: #7b8986;
            margin-top: 3px;
            line-height: 1.2;
        }

        .role-option input:checked + .role-card {
            border-color: #74b69f;
            background: #eef8f3;
            box-shadow: inset 0 0 0 1px rgba(52, 138, 106, 0.2);
        }

        .remember {
            margin-top: 12px;
            display: flex;
            align-items: center;
            gap: 7px;
            color: #667572;
            font-size: 0.82rem;
        }

        .remember input {
            accent-color: var(--brand);
        }

        .submit {
            margin-top: 16px;
            width: 100%;
            border: none;
            border-radius: var(--radius-sm);
            min-height: 42px;
            background: linear-gradient(180deg, #3a8f70 0%, #2f7b60 100%);
            color: #fff;
            font: inherit;
            font-size: 0.94rem;
            font-weight: 800;
            cursor: pointer;
            transition: filter .2s ease, transform .1s ease;
        }

        .submit:hover {
            filter: brightness(0.96);
        }

        .submit:active {
            transform: translateY(1px);
        }

        .submit.loading {
            opacity: 0.82;
            cursor: wait;
        }

        .footer {
            margin-top: 14px;
            text-align: center;
            font-size: 0.68rem;
            color: #93a19e;
            line-height: 1.5;
        }

        @keyframes riseIn {
            from {
                opacity: 0;
                transform: translateY(14px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 940px) {
            body {
                padding: 0;
                background: #fcfdfc;
            }

            .shell {
                width: 100%;
                min-height: 100vh;
                border: none;
                border-radius: 0;
                box-shadow: none;
                grid-template-columns: 1fr;
            }

            .left {
                min-height: 300px;
                padding: 26px;
            }

            .brand-logo {
                max-width: 220px;
            }

            .right {
                padding: 30px 20px 26px;
            }

            .role-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="shell">
    <section class="left">
        <div class="brand-wrap">
            <img
                src="{{ asset('images/lusog-logo.png') }}"
                alt="LUSOG Logo"
                class="brand-logo"
                onerror="this.style.display='none';document.getElementById('logoFallback').style.display='block';"
            >
            <div class="brand-fallback" id="logoFallback">LUSOG</div>
            <p class="brand-tag">
                Learner Utilization and Status of Growth<br>
                School Clinic Management System
            </p>
        </div>
    </section>

    <section class="right">
        <div class="top">
            <h1>Welcome back</h1>
            <p>Enter your credentials to access your assigned modules.</p>
        </div>

        <div class="info-bar">School intranet access only - accessible within campus network</div>

        @if ($errors->any())
            <div class="alert-error">{{ $errors->first() }}</div>
        @endif

        @if (session('error'))
            <div class="alert-error">{{ session('error') }}</div>
        @endif

        <form method="POST" action="{{ route('login') }}" id="loginForm">
            @csrf

            <div class="field">
                <label for="email">Username / Employee ID</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    value="{{ old('email') }}"
                    placeholder="nurse.maria"
                    autocomplete="email"
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
                    <button type="button" class="toggle-pw" onclick="togglePassword()" id="togglePw">SHOW</button>
                </div>
            </div>

            <div class="field">
                <label>Sign in as</label>
                <div class="role-grid">
                    <label class="role-option">
                        <input type="radio" name="role" value="school_nurse" {{ old('role') == 'school_nurse' ? 'checked' : '' }} required>
                        <span class="role-card">
                            <span class="role-title">School nurse</span>
                            <span class="role-caption">Full clinic access</span>
                        </span>
                    </label>

                    <label class="role-option">
                        <input type="radio" name="role" value="clinic_staff" {{ old('role') == 'clinic_staff' ? 'checked' : '' }} required>
                        <span class="role-card">
                            <span class="role-title">Clinic staff</span>
                            <span class="role-caption">Records and inventory</span>
                        </span>
                    </label>

                    <label class="role-option">
                        <input type="radio" name="role" value="school_head" {{ old('role') == 'school_head' ? 'checked' : '' }} required>
                        <span class="role-card">
                            <span class="role-title">School head</span>
                            <span class="role-caption">View only</span>
                        </span>
                    </label>

                    <label class="role-option">
                        <input type="radio" name="role" value="administrator" {{ old('role') == 'administrator' ? 'checked' : '' }} required>
                        <span class="role-card">
                            <span class="role-title">System administrator</span>
                            <span class="role-caption">User and settings management</span>
                        </span>
                    </label>
                </div>
            </div>

            <label class="remember">
                <input type="checkbox" id="remember" name="remember" {{ old('remember') ? 'checked' : '' }}>
                Keep me signed in for 30 days
            </label>

            <button type="submit" class="submit" id="submitBtn">Sign in -></button>

            <p class="footer">
                Forgot your password? Contact your system administrator.<br>
                LUSOG v1.0 | Department of Education - School Health Division
            </p>
        </form>
    </section>
</div>

<script>
    function togglePassword() {
        const input = document.getElementById('password');
        const btn = document.getElementById('togglePw');
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.textContent = show ? 'HIDE' : 'SHOW';
    }

    document.getElementById('loginForm').addEventListener('submit', function () {
        document.getElementById('submitBtn').classList.add('loading');
    });
</script>
</body>
</html>
