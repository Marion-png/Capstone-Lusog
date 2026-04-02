<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>New Consultation - LUSOG</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: #f7f8f5; color: #0d1f14; }
        .wrap { max-width: 860px; margin: 36px auto; padding: 0 18px; }
        .card { background: #fff; border: 1px solid #e4ece7; border-radius: 14px; box-shadow: 0 1px 4px rgba(5,46,22,.06), 0 4px 16px rgba(5,46,22,.06); }
        .head { padding: 18px 20px; border-bottom: 1px solid #e4ece7; display: flex; justify-content: space-between; align-items: center; }
        .title { font-family: 'DM Serif Display', serif; font-size: 1.5rem; }
        .sub { font-size: .84rem; color: #7a9e87; margin-top: 4px; }
        .body { padding: 20px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .field { display: flex; flex-direction: column; gap: 6px; }
        .field.full { grid-column: 1 / -1; }
        label { font-size: .78rem; font-weight: 700; color: #3d5c47; }
        input, select, textarea { border: 1px solid #e4ece7; border-radius: 10px; padding: 10px 12px; font: inherit; font-size: .86rem; }
        textarea { min-height: 110px; resize: vertical; }
        .err { margin-top: 4px; font-size: .74rem; color: #b91c1c; }
        .actions { margin-top: 18px; display: flex; gap: 10px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; padding: 10px 16px; text-decoration: none; border: 1px solid transparent; font-weight: 600; font-size: .84rem; cursor: pointer; }
        .btn-primary { background: #166534; color: #fff; }
        .btn-ghost { background: #fff; color: #3d5c47; border-color: #e4ece7; }
        @media (max-width: 700px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="head">
            <div>
                <div class="title">New Consultation</div>
                <div class="sub">Record a student clinic visit and treatment details.</div>
            </div>
            <a href="{{ route('dashboard.consultation-log') }}" class="btn btn-ghost">Back to Log</a>
        </div>
        <div class="body">
            <form method="POST" action="{{ route('consultations.store') }}">
                @csrf
                <div class="grid">
                    <div class="field">
                        <label for="consulted_at">Date and Time</label>
                        <input id="consulted_at" type="datetime-local" name="consulted_at" value="{{ old('consulted_at', now()->format('Y-m-d\\TH:i')) }}" required>
                        @error('consulted_at') <div class="err">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="student_name">Student Name</label>
                        <input id="student_name" type="text" name="student_name" value="{{ old('student_name') }}" placeholder="e.g. Dela Cruz, Juan" required>
                        @error('student_name') <div class="err">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="grade_section">Grade and Section</label>
                        <input id="grade_section" type="text" name="grade_section" value="{{ old('grade_section') }}" placeholder="e.g. Grade 10 - Rizal" required>
                        @error('grade_section') <div class="err">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="condition">Condition</label>
                        <input id="condition" type="text" name="condition" value="{{ old('condition') }}" placeholder="e.g. Fever, Cough" required>
                        @error('condition') <div class="err">{{ $message }}</div> @enderror
                    </div>
                    <div class="field full">
                        <label for="treatment_given">Treatment Given</label>
                        <textarea id="treatment_given" name="treatment_given" placeholder="Medicine given, recommendations, referral note...">{{ old('treatment_given') }}</textarea>
                        @error('treatment_given') <div class="err">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="status">Status</label>
                        <select id="status" name="status" required>
                            <option value="treated" @selected(old('status') === 'treated')>Treated</option>
                            <option value="referred" @selected(old('status') === 'referred')>Referred</option>
                        </select>
                        @error('status') <div class="err">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="actions">
                    <button type="submit" class="btn btn-primary">Save Consultation</button>
                    <a href="{{ route('dashboard.consultation-log') }}" class="btn btn-ghost">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
