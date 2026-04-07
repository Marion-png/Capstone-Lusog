<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>New Consultation - LUSOG</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
        @php $pageCssPath = resource_path('css/school-nurse-consultation-create.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
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
