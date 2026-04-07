<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>System Admin Access - LUSOG</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Nunito', sans-serif;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background:
                radial-gradient(900px 400px at 10% -10%, rgba(47, 126, 98, 0.15), transparent 60%),
                radial-gradient(900px 400px at 100% 110%, rgba(20, 83, 45, 0.12), transparent 60%),
                #eef1ef;
            color: #18312a;
            padding: 20px;
        }
        .card {
            width: min(460px, 100%);
            background: #fff;
            border: 1px solid #dbe6e0;
            border-radius: 14px;
            box-shadow: 0 20px 45px rgba(14, 44, 33, 0.16);
            padding: 24px 22px;
        }
        h1 { font-size: 1.45rem; font-weight: 800; color: #1d3c33; }
        p { margin-top: 6px; color: #68817a; font-size: 0.85rem; }
        .alert {
            margin-top: 12px;
            background: #fff1f1;
            border: 1px solid #f5cdcd;
            color: #952d2d;
            border-radius: 8px;
            padding: 9px 10px;
            font-size: 0.8rem;
        }
        .field { margin-top: 12px; }
        label {
            display: block;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #5e7a70;
            font-weight: 700;
            margin-bottom: 5px;
        }
        input {
            width: 100%;
            height: 42px;
            border: 1px solid #d4dfda;
            border-radius: 8px;
            padding: 0 12px;
            font: inherit;
            color: #18312a;
        }
        input:focus {
            outline: none;
            border-color: #75b79f;
            box-shadow: 0 0 0 3px rgba(63, 147, 114, 0.15);
        }
        .actions { margin-top: 16px; display: flex; gap: 8px; }
        .btn {
            border: none;
            border-radius: 8px;
            padding: 10px 12px;
            font-weight: 800;
            cursor: pointer;
            font-size: 0.82rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-primary { background: linear-gradient(180deg, #3a8f70, #2f7b60); color: #fff; flex: 1; }
        .btn-secondary { background: #fff; color: #2f5b4d; border: 1px solid #c8d9d1; }
        .hint { margin-top: 10px; font-size: 0.72rem; color: #88a099; }
    </style>
</head>
<body>
    <div class="card">
        <h1>System Admin Access</h1>
        <p>Restricted entry for platform administration.</p>

        @if (session('error'))
            <div class="alert">{{ session('error') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('admin.login.submit') }}" autocomplete="off">
            @csrf
            <div class="field">
                <label for="admin_username">Username</label>
                <input id="admin_username" name="username" type="text" value="{{ old('username') }}" required>
            </div>
            <div class="field">
                <label for="admin_password">Password</label>
                <input id="admin_password" name="password" type="password" required>
            </div>
            <div class="actions">
                <button type="submit" class="btn btn-primary">Sign in as System Admin</button>
                <a href="{{ route('login') }}" class="btn btn-secondary">Back</a>
            </div>
        </form>
        <div class="hint">Tip: set SYSTEM_ADMIN_USERNAME and SYSTEM_ADMIN_PASSWORD in your environment.</div>
    </div>
</body>
</html>
