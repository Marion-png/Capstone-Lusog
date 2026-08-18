<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Create Account Request - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f3f8f4;
            --card: #ffffff;
            --text: #0f2f1b;
            --muted: #5b7b68;
            --line: #dbe9df;
            --green: #1F8A4C;
            --green-dark: #126B3A;
            --danger-bg: #FCECEC;
            --danger-text: #A32B2B;
            --ok-bg: #E7F5EC;
            --ok-text: #14653C;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            font-family: 'DM Sans', sans-serif;
            background:
                radial-gradient(circle at 10% -10%, #BFE3CC 0, transparent 45%),
                radial-gradient(circle at 90% 110%, #C4E4D0 0, transparent 40%),
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
            background: linear-gradient(135deg, #126B3A, #1F8A4C);
            color: #fff;
            padding: 22px;
        }
        .head h1 {
            font-family: 'DM Serif Display', serif;
            font-size: 1.65rem;
            line-height: 1.2;
        }
        .head p {
            margin-top: 6px;
            color: #E7F5EC;
            font-size: 0.9rem;
        }
        .body { padding: 22px; }
        .flash {
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 0.86rem;
            margin-bottom: 12px;
        }
        .flash-ok { background: var(--ok-bg); color: var(--ok-text); border: 1px solid #BFE3CC; }
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
        input, select {
            height: 42px;
            border-radius: 10px;
            border: 1px solid var(--line);
            padding: 0 12px;
            font: inherit;
            color: var(--text);
            background: #fff;
        }
        input:focus, select:focus {
            outline: 2px solid #C4E4D0;
            border-color: #43A866;
        }
        .hint {
            grid-column: 1 / -1;
            font-size: 0.78rem;
            color: var(--muted);
        }
        .actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 16px;
            gap: 8px;
            flex-wrap: wrap;
        }
        .link {
            color: #14653C;
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
    {{-- One shared palette for pages not yet on lusog-theme.css. Loaded
         last so it overrides this page's own :root colours. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-palette.css')) !!}</style>
</head>
<body>
    <section class="card">
        <header class="head">
            <h1>Create Account Request</h1>
            <p>Fill out this form. Your request will be reviewed by the System Admin.</p>
        </header>
        <div class="body">
            @if (session('success'))
                <div class="flash flash-ok">{{ session('success') }}</div>
            @endif
            @if ($errors->any())
                <div class="flash flash-err">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('account.request.submit') }}" autocomplete="off" id="accountRequestForm">
                @csrf
                <div class="grid">
                    <div class="field">
                        <label for="name">Full Name</label>
                        <input id="name" name="name" type="text" value="{{ old('name') }}" required>
                    </div>
                    <div class="field">
                        <label for="username">Username / Employee ID</label>
                        <input id="username" name="username" type="text" value="{{ old('username') }}" required>
                    </div>
                    <div class="field">
                        <label for="password">Password</label>
                        <input id="password" name="password" type="password" minlength="6" required>
                    </div>
                    <div class="field">
                        <label for="password_confirmation">Confirm Password</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" minlength="6" required>
                    </div>
                    <div class="field full">
                        <label for="role">Role</label>
                        <select id="role" name="role" required>
                            <option value="" disabled {{ old('role') ? '' : 'selected' }}>Select role</option>
                            <option value="school_nurse" {{ old('role') === 'school_nurse' ? 'selected' : '' }}>School Nurse</option>
                            <option value="clinic_staff" {{ old('role') === 'clinic_staff' ? 'selected' : '' }}>Clinic Staff</option>
                            <option value="class_adviser" {{ old('role') === 'class_adviser' ? 'selected' : '' }}>Class Adviser</option>
                            <option value="school_head" {{ old('role') === 'school_head' ? 'selected' : '' }}>School Head</option>
                            <option value="feeding_coor" {{ old('role') === 'feeding_coor' ? 'selected' : '' }}>Feeding Coordinator</option>
                            <option value="nutricor" {{ old('role') === 'nutricor' ? 'selected' : '' }}>Nutritional Coordinator</option>
                        </select>
                    </div>
                    <div class="field full" id="schoolField" style="display:none;">
                        <label for="institution_id">School / Institution <span style="color:#dc2626;">*</span></label>
                        <select id="institution_id" name="institution_id" style="height:42px;border-radius:10px;border:1px solid var(--line);padding:0 12px;font:inherit;color:var(--text);background:#fff;">
                            <option value="" disabled {{ old('institution_id') ? '' : 'selected' }}>Select your school…</option>
                            @foreach (($institutions ?? collect()) as $institution)
                                <option value="{{ $institution->id }}" {{ (string) old('institution_id') === (string) $institution->id ? 'selected' : '' }}>
                                    {{ $institution->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('institution_id')
                            <span style="color:#dc2626;font-size:0.8rem;">{{ $message }}</span>
                        @enderror
                    </div>
                    <div class="field" id="gradeField">
                        <label for="assigned_grade_level">Assigned Grade Level</label>
                        <select id="assigned_grade_level" name="assigned_grade_level">
                            <option value="" selected disabled>Select grade level</option>
                            <option {{ old('assigned_grade_level') === 'Kinder/SPED' ? 'selected' : '' }}>Kinder/SPED</option>
                            <option {{ old('assigned_grade_level') === 'Grade 1/SPED' ? 'selected' : '' }}>Grade 1/SPED</option>
                            <option {{ old('assigned_grade_level') === 'Grade 2/SPED' ? 'selected' : '' }}>Grade 2/SPED</option>
                            <option {{ old('assigned_grade_level') === 'Grade 3/SPED' ? 'selected' : '' }}>Grade 3/SPED</option>
                            <option {{ old('assigned_grade_level') === 'Grade 4/SPED' ? 'selected' : '' }}>Grade 4/SPED</option>
                            <option {{ old('assigned_grade_level') === 'Grade 5/SPED' ? 'selected' : '' }}>Grade 5/SPED</option>
                            <option {{ old('assigned_grade_level') === 'Grade 6/SPED' ? 'selected' : '' }}>Grade 6/SPED</option>
                            <option {{ old('assigned_grade_level') === 'Grade 7/SPED' ? 'selected' : '' }}>Grade 7/SPED</option>
                            <option {{ old('assigned_grade_level') === 'Grade 8/SPED' ? 'selected' : '' }}>Grade 8/SPED</option>
                            <option {{ old('assigned_grade_level') === 'Grade 9/SPED' ? 'selected' : '' }}>Grade 9/SPED</option>
                            <option {{ old('assigned_grade_level') === 'Grade 10/SPED' ? 'selected' : '' }}>Grade 10/SPED</option>
                            {{-- Senior High is not offered: the feeding programme
                                 covers Grades 7-10. --}}
                        </select>
                    </div>
                    <div class="field" id="sectionField">
                        <label for="assigned_section">Assigned Section</label>
                        {{-- Two controls, one name: the school's published list
                             where there is one, a typed answer where there is
                             not. A disabled control is not submitted, so only
                             the visible one ever posts. --}}
                        <select id="assigned_section_select" name="assigned_section" disabled style="display:none;">
                            <option value="" selected disabled>Select section</option>
                        </select>
                        <input id="assigned_section" name="assigned_section" type="text" value="{{ old('assigned_section') }}" placeholder="e.g. SPED-A">
                        @error('assigned_section')
                            <span style="color:#dc2626;font-size:0.8rem;">{{ $message }}</span>
                        @enderror
                    </div>
                    <p class="hint" id="classAdviserHint">Grade level and section are required for Class Adviser requests only.</p>
                </div>

                <div class="actions">
                    <a class="link" href="{{ route('login') }}">Back to Login</a>
                    <button type="submit" class="submit">Submit Request</button>
                </div>
            </form>
        </div>
    </section>

    <script>
        const SCOPED_ROLES = ['school_nurse', 'clinic_staff', 'class_adviser', 'school_head', 'feeding_coor', 'nutricor'];

        const requestForm     = document.getElementById('accountRequestForm');
        const roleSelect      = document.getElementById('role');
        const institutionSel  = document.getElementById('institution_id');
        const schoolField     = document.getElementById('schoolField');
        const gradeSelect     = document.getElementById('assigned_grade_level');
        const sectionInput    = document.getElementById('assigned_section');
        const sectionSelect   = document.getElementById('assigned_section_select');
        const gradeField      = document.getElementById('gradeField');
        const sectionField    = document.getElementById('sectionField');
        const classAdviserHint = document.getElementById('classAdviserHint');
        const oldInstitutionId = @json((string) old('institution_id', ''));
        const oldGrade         = @json((string) old('assigned_grade_level', ''));
        const oldSection       = @json((string) old('assigned_section', ''));

        // The generic grade list, kept so a school that has not published its
        // sections still gets a working form.
        const fallbackGradeOptions = gradeSelect.innerHTML;

        // { "Grade 7": ["MATIYAGA", ...], ... } for the chosen school, empty
        // when that school has no published catalogue.
        let sectionCatalog = {};

        function populateSchools(institutions) {
            if (!Array.isArray(institutions) || institutions.length === 0) {
                return;
            }

            institutionSel.innerHTML = '<option value="" disabled>Select your school…</option>';

            institutions.forEach(function (inst) {
                const opt = document.createElement('option');
                opt.value = inst.id;
                opt.textContent = inst.name;
                if (String(inst.id) === oldInstitutionId) opt.selected = true;
                institutionSel.appendChild(opt);
            });

            if (!oldInstitutionId) {
                institutionSel.selectedIndex = 0;
            }
        }

        // Refresh institutions from API when available; keep server-rendered schools if it fails.
        fetch('/api/institutions')
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Unable to load schools');
                }

                return response.json();
            })
            .then(populateSchools)
            .catch(function () {});

        /** Show the school's published sections, or fall back to a typed one. */
        function useCatalog(hasCatalog) {
            sectionSelect.style.display = hasCatalog ? '' : 'none';
            sectionSelect.disabled      = !hasCatalog;
            sectionInput.style.display  = hasCatalog ? 'none' : '';
            sectionInput.disabled       = hasCatalog;
        }

        function renderGrades() {
            const grades = Object.keys(sectionCatalog);

            if (grades.length === 0) {
                gradeSelect.innerHTML = fallbackGradeOptions;
                useCatalog(false);
                return;
            }

            gradeSelect.innerHTML = '<option value="" selected disabled>Select grade level</option>';

            grades.forEach(function (grade) {
                const opt = document.createElement('option');
                opt.value = grade;
                opt.textContent = grade;
                if (grade === oldGrade) opt.selected = true;
                gradeSelect.appendChild(opt);
            });

            useCatalog(true);
            renderSections();
        }

        function renderSections() {
            const names = sectionCatalog[gradeSelect.value] || [];

            sectionSelect.innerHTML = names.length
                ? '<option value="" selected disabled>Select section</option>'
                : '<option value="" selected disabled>Select a grade level first</option>';

            names.forEach(function (name) {
                const opt = document.createElement('option');
                opt.value = name;
                opt.textContent = name;
                if (name === oldSection) opt.selected = true;
                sectionSelect.appendChild(opt);
            });

            sectionSelect.setCustomValidity('');
        }

        /** Load the chosen school's sections; a school without any keeps the typed box. */
        function loadSections() {
            const institutionId = institutionSel.value;

            if (!institutionId) {
                sectionCatalog = {};
                renderGrades();
                return;
            }

            fetch('/api/institutions/' + encodeURIComponent(institutionId) + '/sections')
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Unable to load sections');
                    }

                    return response.json();
                })
                .then(function (payload) {
                    sectionCatalog = (payload && payload.grades) || {};
                    renderGrades();
                })
                .catch(function () {
                    sectionCatalog = {};
                    renderGrades();
                });
        }

        function syncRoleFields() {
            const role = roleSelect.value;
            const requiresSchool = SCOPED_ROLES.includes(role);
            const isClassAdviser = role === 'class_adviser';

            schoolField.style.display   = requiresSchool ? '' : 'none';
            gradeField.style.display    = isClassAdviser ? '' : 'none';
            sectionField.style.display  = isClassAdviser ? '' : 'none';
            classAdviserHint.style.display = isClassAdviser ? '' : 'none';

            institutionSel.required = requiresSchool;
            gradeSelect.required    = isClassAdviser;
            sectionInput.required   = isClassAdviser;
            sectionSelect.required  = isClassAdviser;

            if (!requiresSchool) {
                institutionSel.value = '';
                institutionSel.setCustomValidity('');
                sectionCatalog = {};
                renderGrades();
            }
            if (!isClassAdviser) {
                gradeSelect.selectedIndex = 0;
                sectionInput.value = '';
                sectionSelect.selectedIndex = 0;
                gradeSelect.setCustomValidity('');
                sectionInput.setCustomValidity('');
                sectionSelect.setCustomValidity('');
            }
        }

        roleSelect.addEventListener('change', function () {
            syncRoleFields();
            institutionSel.setCustomValidity('');
        });

        institutionSel.addEventListener('change', function () {
            institutionSel.setCustomValidity('');
            // The sections on offer belong to the school just chosen.
            loadSections();
        });

        // Sections are per grade: changing the grade changes the list.
        gradeSelect.addEventListener('change', renderSections);

        sectionInput.addEventListener('input', function () {
            sectionInput.setCustomValidity('');
        });

        sectionSelect.addEventListener('change', function () {
            sectionSelect.setCustomValidity('');
        });

        requestForm.addEventListener('submit', function (event) {
            const role = roleSelect.value;
            const requiresSchool = SCOPED_ROLES.includes(role);
            const isClassAdviser = role === 'class_adviser';

            if (requiresSchool && !institutionSel.value) {
                institutionSel.setCustomValidity('Please select your school.');
            } else {
                institutionSel.setCustomValidity('');
            }

            if (isClassAdviser) {
                gradeSelect.setCustomValidity(gradeSelect.value ? '' : 'Please select assigned grade level.');

                if (sectionSelect.disabled) {
                    sectionInput.setCustomValidity(sectionInput.value.trim() ? '' : 'Please enter assigned section.');
                } else {
                    sectionSelect.setCustomValidity(sectionSelect.value ? '' : 'Please select your assigned section.');
                }
            }

            if (!requestForm.checkValidity()) {
                event.preventDefault();
                requestForm.reportValidity();
            }
        });

        syncRoleFields();

        // A rejected submission comes back with the school already chosen; load
        // its sections so the adviser's grade and section are restored, not lost.
        if (oldInstitutionId) {
            loadSections();
        }
    </script>
</body>
</html>
