<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#126B3A">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>System Admin Access - SIGLA</title>
    {{-- The product's two faces: DM Serif Display for the title, Inter for
         everything else — the same pair the sign-in page and every
         dashboard use, so this screen reads as the same system. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        /* Every colour reads through a LUSOG token, with the token's own
           value as the fallback. The shared palette is inlined after this
           block and declares them for real. */
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
            --r-card:     var(--lg-r-card, 12px);
            --r-btn:      var(--lg-r-btn, 9px);
            --r-input:    var(--lg-r-input, 8px);
            --shadow:     0 18px 48px rgba(14, 45, 30, .13), 0 2px 6px rgba(14, 45, 30, .05);
        }

        html, body { width: 100%; }

        body {
            font-family: 'Inter', 'DM Sans', system-ui, -apple-system, 'Segoe UI', sans-serif;
            font-size: 15px;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
            background:
                radial-gradient(900px 420px at 8% -10%, rgba(31, 138, 76, .12), transparent 60%),
                radial-gradient(900px 420px at 100% 110%, rgba(14, 87, 48, .10), transparent 60%),
                var(--page);
            color: var(--ink);
            padding: 32px 20px;
            display: grid;
            place-items: center;
        }

        .card {
            width: min(440px, 100%);
            min-width: 0;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--r-card);
            box-shadow: var(--shadow);
            overflow: hidden;
            animation: riseIn .5s cubic-bezier(.2, .7, .3, 1) both;
        }

        /* The lockup's wordmark is white, so it sits on the brand green —
           the same layered panel the sign-in page opens on, in a band. */
        .brand {
            position: relative;
            isolation: isolate;
            overflow: hidden;
            display: grid;
            place-items: center;
            padding: 28px 24px 24px;
            background:
                radial-gradient(120% 95% at 12% 6%, rgba(107, 201, 146, .30), transparent 58%),
                radial-gradient(90% 80% at 92% 96%, rgba(3, 46, 26, .42), transparent 55%),
                linear-gradient(158deg, #17814A 0%, var(--brand-deep) 46%, var(--brand-dark) 100%);
        }
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
        .brand img {
            position: relative;
            z-index: 1;
            width: 150px;
            height: auto;
            display: block;
            filter: drop-shadow(0 8px 18px rgba(5, 26, 16, .28));
        }

        .panel { padding: 28px 36px 30px; }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: .68rem;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--brand);
            margin-bottom: 10px;
        }
        .eyebrow svg { width: 12px; height: 12px; }

        h1 {
            font-family: 'DM Serif Display', Georgia, serif;
            font-size: 1.9rem;
            font-weight: 400;
            line-height: 1.15;
            letter-spacing: -.008em;
            color: var(--ink);
        }
        h1 span { color: var(--brand-deep); font-style: italic; }
        .lede { margin-top: 8px; color: var(--ink-soft); font-size: .88rem; line-height: 1.5; }

        .alert-error {
            background: var(--lg-danger-tint, #FCECEC);
            border: 1px solid rgba(217, 92, 92, .38);
            border-left: 3px solid var(--lg-danger, #D95C5C);
            color: var(--lg-danger-ink, #A32B2B);
            border-radius: var(--r-input);
            padding: 11px 13px;
            font-size: .84rem;
            font-weight: 500;
            line-height: 1.45;
            margin-top: 18px;
        }

        form { margin-top: 22px; }
        .field + .field { margin-top: 14px; }
        label {
            display: block;
            font-size: .7rem;
            font-weight: 600;
            letter-spacing: .09em;
            text-transform: uppercase;
            color: var(--ink-soft);
            margin-bottom: 7px;
        }
        .control {
            width: 100%;
            min-height: 46px;
            border: 1px solid var(--line);
            border-radius: var(--r-input);
            background: var(--card);
            padding: 11px 14px;
            font: inherit;
            font-size: .94rem;
            color: var(--ink);
            outline: none;
            transition: border-color .16s ease, box-shadow .16s ease;
        }
        .control::placeholder { color: #A8B5AE; }
        .control:hover { border-color: var(--line-firm); }
        .control:focus { border-color: var(--brand); box-shadow: var(--focus); }

        .actions { margin-top: 22px; display: flex; gap: 10px; }
        .btn {
            appearance: none;
            font: inherit;
            border: 1px solid transparent;
            border-radius: var(--r-btn);
            padding: 12px 16px;
            font-size: .84rem;
            font-weight: 600;
            line-height: 1.2;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            white-space: nowrap;
            transition: background .16s ease, color .16s ease, border-color .16s ease, box-shadow .16s ease;
        }
        .btn:focus-visible { outline: none; box-shadow: var(--focus); }
        .btn-primary { flex: 1 1 auto; background: var(--brand); color: #fff; }
        .btn-primary:hover { background: var(--brand-deep); }
        .btn-secondary { background: var(--card); border-color: var(--line); color: var(--ink); }
        .btn-secondary:hover { background: var(--mint); border-color: var(--line-firm); }
        .btn svg { width: 15px; height: 15px; flex: 0 0 auto; }

        @keyframes riseIn {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: none; }
        }
        @media (prefers-reduced-motion: reduce) {
            .card { animation: none; }
            .control, .btn { transition: none; }
        }
        @media (max-width: 480px) {
            .panel { padding: 24px 22px 24px; }
            .actions { flex-direction: column; }
        }
    </style>
    {{-- One shared palette for pages not yet on lusog-theme.css. Loaded
         last so it overrides this page's own :root colours. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-palette.css')) !!}</style>
</head>
<body>
    <main class="card">
        <div class="brand">
            <img src="{{ asset('images/lusog-logo.png') }}" alt="LUSOG">
        </div>

        <div class="panel">
        <div class="eyebrow">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Restricted access
        </div>
        <h1>System Admin <span>Access</span></h1>
        <p class="lede">Platform administration for SIGLA.</p>

        @if (session('error'))
            <div class="alert-error" role="alert">{{ session('error') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert-error" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('admin.login.submit') }}" autocomplete="off">
            @csrf
            <div class="field">
                <label for="admin_username">Username</label>
                <input id="admin_username" class="control" name="username" type="text" value="{{ old('username') }}" required autofocus>
            </div>
            <div class="field">
                <label for="admin_password">Password</label>
                <input id="admin_password" class="control" name="password" type="password" required>
            </div>
            <div class="actions">
                <button type="submit" class="btn btn-primary">Sign in as System Admin</button>
                <a href="{{ route('login') }}" class="btn btn-secondary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
                    Back
                </a>
            </div>
        </form>
        </div>
    </main>
</body>
</html>
