<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Register School - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f3f8f4;
            --card: #ffffff;
            --text: #0f2f1b;
            --muted: #5b7b68;
            --line: #dbe9df;
            --green: #15803d;
            --green-dark: #14532d;
            --danger-bg: #fee2e2;
            --danger-text: #991b1b;
            --ok-bg: #dcfce7;
            --ok-text: #166534;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            font-family: 'DM Sans', sans-serif;
            background:
                radial-gradient(circle at 10% -10%, #86efac 0, transparent 45%),
                radial-gradient(circle at 90% 110%, #bbf7d0 0, transparent 40%),
                var(--bg);
            color: var(--text);
            display: grid;
            place-items: center;
            padding: 24px;
        }
        .card {
            width: min(760px, 100%);
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: 0 18px 40px rgba(20, 83, 45, 0.12);
            overflow: hidden;
        }
        .head {
            background: linear-gradient(135deg, #14532d, #15803d);
            color: #fff;
            padding: 22px;
        }
        .head h1 {
            font-family: 'DM Serif Display', serif;
            font-size: 1.65rem;
            line-height: 1.2;
        }
        .body { padding: 22px; }
        .flash {
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 0.86rem;
            margin-bottom: 12px;
        }
        .flash-ok { background: var(--ok-bg); color: var(--ok-text); border: 1px solid #86efac; }
        .flash-err { background: var(--danger-bg); color: var(--danger-text); border: 1px solid #fecaca; }
        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .field { display: flex; flex-direction: column; gap: 6px; }
        .field.full { grid-column: 1 / -1; }
        label {
            font-size: 0.7rem;
            color: var(--muted);
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-weight: 700;
        }
        input {
            height: 42px;
            border-radius: 10px;
            border: 1px solid var(--line);
            padding: 0 12px;
            font: inherit;
            color: var(--text);
            background: #fff;
        }
        input:focus {
            outline: 2px solid #bbf7d0;
            border-color: #22c55e;
        }
        .err { color: #dc2626; font-size: 0.8rem; }
        .actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 16px;
            gap: 8px;
            flex-wrap: wrap;
        }
        .link {
            color: #166534;
            text-decoration: underline;
            font-size: 0.86rem;
        }
        .submit {
            background: var(--green);
            color: #fff;
            border: 1px solid var(--green);
            border-radius: 10px;
            padding: 10px 14px;
            cursor: pointer;
            font-weight: 600;
        }
        .submit:hover { background: var(--green-dark); }
        @media (max-width: 720px) {
            .grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <section class="card">
        <header class="head">
            <h1>Register School</h1>
        </header>
        <div class="body">
            @if (session('success'))
                <div class="flash flash-ok">{{ session('success') }}</div>
            @endif
            @if ($errors->any())
                <div class="flash flash-err">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('institution.request.submit') }}" autocomplete="off">
                @csrf
                <div class="grid">
                    <div class="field full">
                        <label for="name">School Name</label>
                        <input id="name" name="name" type="text" value="{{ old('name') }}" required>
                        @error('name')<span class="err">{{ $message }}</span>@enderror
                    </div>
                    <div class="field full">
                        <label for="address">School Address</label>
                        <input id="address" name="address" type="text" value="{{ old('address') }}">
                        @error('address')<span class="err">{{ $message }}</span>@enderror
                    </div>
                    <div class="field">
                        <label for="division">Division</label>
                        <input id="division" name="division" type="text" value="{{ old('division') }}">
                        @error('division')<span class="err">{{ $message }}</span>@enderror
                    </div>
                    <div class="field">
                        <label for="contact_person">Contact Person</label>
                        <input id="contact_person" name="contact_person" type="text" value="{{ old('contact_person') }}" required>
                        @error('contact_person')<span class="err">{{ $message }}</span>@enderror
                    </div>
                    <div class="field">
                        <label for="contact_email">Contact Email</label>
                        <input id="contact_email" name="contact_email" type="email" value="{{ old('contact_email') }}" required>
                        @error('contact_email')<span class="err">{{ $message }}</span>@enderror
                    </div>
                    <div class="field">
                        <label for="contact_number">Contact Number</label>
                        <input id="contact_number" name="contact_number" type="text" value="{{ old('contact_number') }}">
                        @error('contact_number')<span class="err">{{ $message }}</span>@enderror
                    </div>
                </div>
                <div class="actions">
                    <a class="link" href="{{ route('login') }}">Back to login</a>
                    <button class="submit" type="submit">Submit Registration</button>
                </div>
            </form>
        </div>
    </section>
</body>
</html>
