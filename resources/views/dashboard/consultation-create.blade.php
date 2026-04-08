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
                        <select id="condition" name="condition" required>
                            <option value="" @selected(old('condition') === null || old('condition') === '')>Select condition</option>
                            <option value="Inflamed eye/stye" @selected(old('condition') === 'Inflamed eye/stye')>Inflamed eye/stye</option>
                            <option value="Eye irritation" @selected(old('condition') === 'Eye irritation')>Eye irritation</option>
                            <option value="Conjunctivitis" @selected(old('condition') === 'Conjunctivitis')>Conjunctivitis</option>
                            <option value="Ear Problem" @selected(old('condition') === 'Ear Problem')>Ear Problem</option>
                            <option value="Nose Bleeding" @selected(old('condition') === 'Nose Bleeding')>Nose Bleeding</option>
                            <option value="Sinusistis/Acute Rhinitis" @selected(old('condition') === 'Sinusistis/Acute Rhinitis')>Sinusistis/Acute Rhinitis</option>
                            <option value="Sore throat" @selected(old('condition') === 'Sore throat')>Sore throat</option>
                            <option value="Tonsilitis" @selected(old('condition') === 'Tonsilitis')>Tonsilitis</option>
                            <option value="Inflamed Gum" @selected(old('condition') === 'Inflamed Gum')>Inflamed Gum</option>
                            <option value="Toothache" @selected(old('condition') === 'Toothache')>Toothache</option>
                            <option value="Cough" @selected(old('condition') === 'Cough')>Cough</option>
                            <option value="Fever" @selected(old('condition') === 'Fever')>Fever</option>
                            <option value="Cold" @selected(old('condition') === 'Cold')>Cold</option>
                            <option value="Headache" @selected(old('condition') === 'Headache')>Headache</option>
                            <option value="Hyperacidity" @selected(old('condition') === 'Hyperacidity')>Hyperacidity</option>
                            <option value="Dysmenorrhea" @selected(old('condition') === 'Dysmenorrhea')>Dysmenorrhea</option>
                            <option value="Diarrhea/LBM" @selected(old('condition') === 'Diarrhea/LBM')>Diarrhea/LBM</option>
                            <option value="Abdominal Pain" @selected(old('condition') === 'Abdominal Pain')>Abdominal Pain</option>
                            <option value="Nausea/ Vomitting" @selected(old('condition') === 'Nausea/ Vomitting')>Nausea/ Vomitting</option>
                            <option value="Fainting" @selected(old('condition') === 'Fainting')>Fainting</option>
                            <option value="Dizziness" @selected(old('condition') === 'Dizziness')>Dizziness</option>
                            <option value="Lacerated Wound" @selected(old('condition') === 'Lacerated Wound')>Lacerated Wound</option>
                            <option value="Punctured Wound" @selected(old('condition') === 'Punctured Wound')>Punctured Wound</option>
                            <option value="Incised Wound" @selected(old('condition') === 'Incised Wound')>Incised Wound</option>
                            <option value="Abrasion" @selected(old('condition') === 'Abrasion')>Abrasion</option>
                            <option value="Contusion" @selected(old('condition') === 'Contusion')>Contusion</option>
                            <option value="Ulcer (Skin)" @selected(old('condition') === 'Ulcer (Skin)')>Ulcer (Skin)</option>
                            <option value="Burn" @selected(old('condition') === 'Burn')>Burn</option>
                            <option value="Thea Flava" @selected(old('condition') === 'Thea Flava')>Thea Flava</option>
                            <option value="Ringworm" @selected(old('condition') === 'Ringworm')>Ringworm</option>
                            <option value="Boil" @selected(old('condition') === 'Boil')>Boil</option>
                            <option value="Skin allergy" @selected(old('condition') === 'Skin allergy')>Skin allergy</option>
                            <option value="Others" @selected(old('condition') === 'Others')>Others</option>
                        </select>
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
