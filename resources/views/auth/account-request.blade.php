<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#126B3A">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Create Account Request - SIGLA</title>
    {{-- The same two faces the sign-in page and every dashboard use: DM Serif
         Display for the title, Inter for everything else. This page was on
         DM Sans, which is a third voice for the same product. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        /* Every colour reads through a LUSOG token, with the token's own value
           as the fallback. The shared palette is inlined after this block and
           declares them for real. */
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
        }

        html, body { width: 100%; }

        body {
            min-height: 100vh;
            font-family: 'Inter', 'DM Sans', system-ui, -apple-system, 'Segoe UI', sans-serif;
            font-size: 15px;
            -webkit-font-smoothing: antialiased;
            background: var(--page);
            color: var(--ink);
            display: grid;
            place-items: center;
            padding: 32px 24px;
        }

        /* min-width: 0 keeps the card shrinkable on a narrow phone: a grid
           item's default min-width is auto, which floors it at its own
           min-content width. */
        .card {
            width: min(780px, 100%);
            max-width: 100%;
            min-width: 0;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--r-card);
            box-shadow: 0 18px 48px rgba(14, 45, 30, .13), 0 2px 6px rgba(14, 45, 30, .05);
            overflow: hidden;
        }

        /* The same emerald, dot texture and ring motif the sign-in panel uses,
           so the two screens read as one product rather than two designs. */
        .head {
            position: relative;
            isolation: isolate;
            overflow: hidden;
            padding: 30px 34px;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 18px;
            background:
                radial-gradient(120% 180% at 8% 0%, rgba(107, 201, 146, .30), transparent 60%),
                linear-gradient(120deg, #17814A 0%, var(--brand-deep) 52%, var(--brand-dark) 100%);
        }

        .head::before {
            content: "";
            position: absolute;
            inset: 0;
            background-image: radial-gradient(rgba(255, 255, 255, .16) 1px, transparent 1px);
            background-size: 22px 22px;
            opacity: .45;
            -webkit-mask-image: radial-gradient(70% 120% at 30% 40%, #000 20%, transparent 80%);
            mask-image: radial-gradient(70% 120% at 30% 40%, #000 20%, transparent 80%);
            pointer-events: none;
        }

        .head-ring {
            position: absolute;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, .14);
            top: -130px;
            right: -60px;
            pointer-events: none;
        }

        .head-ring.is-inner {
            width: 190px;
            height: 190px;
            top: -75px;
            right: -5px;
            border-color: rgba(255, 255, 255, .2);
        }

        /* The mark is a stacked lockup (shield over wordmark), so it is sized by
           height and left to find its own width. Boxed into a square it shrank
           until the wordmark under the shield was unreadable. */
        .head-logo {
            position: relative;
            z-index: 1;
            height: 66px;
            width: auto;
            max-width: 130px;
            object-fit: contain;
            flex: none;
            filter: drop-shadow(0 6px 14px rgba(5, 26, 16, .3));
        }

        .head-text { position: relative; z-index: 1; min-width: 0; }

        .head h1 {
            font-family: 'DM Serif Display', Georgia, serif;
            font-size: 1.85rem;
            font-weight: 400;
            line-height: 1.15;
            letter-spacing: -.006em;
        }

        .head p {
            margin-top: 5px;
            color: rgba(236, 251, 243, .88);
            font-size: .875rem;
            line-height: 1.5;
        }

        .body { padding: 30px 34px 34px; }

        .flash {
            border-radius: var(--r-input);
            padding: 11px 13px;
            font-size: .85rem;
            font-weight: 500;
            line-height: 1.45;
            margin-bottom: 18px;
        }

        .flash-ok {
            background: var(--mint);
            color: var(--lg-success-ink, #14653C);
            border: 1px solid rgba(31, 138, 76, .3);
            border-left: 3px solid var(--brand);
        }

        .flash-err {
            background: var(--lg-danger-tint, #FCECEC);
            color: var(--lg-danger-ink, #A32B2B);
            border: 1px solid rgba(217, 92, 92, .38);
            border-left: 3px solid var(--lg-danger, #D95C5C);
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px 18px;
        }

        /* The script toggles these with style.display = '' / 'none', so the
           rule here has to be the natural display it reverts to. Never give
           .field or .hint display:none in CSS — a hidden field would never
           come back. */
        .field { display: flex; flex-direction: column; gap: 7px; min-width: 0; }

        .field.full { grid-column: 1 / -1; }

        label {
            font-size: .7rem;
            color: var(--ink-soft);
            letter-spacing: .09em;
            text-transform: uppercase;
            font-weight: 600;
        }

        .req { color: var(--lg-danger, #D95C5C); }

        input, select {
            width: 100%;
            min-height: 46px;
            border-radius: var(--r-input);
            border: 1px solid var(--line);
            padding: 11px 13px;
            font: inherit;
            font-size: .94rem;
            color: var(--ink);
            background: var(--card);
            outline: none;
            transition: border-color .16s ease, box-shadow .16s ease;
        }

        input::placeholder { color: #A8B5AE; }

        input:hover, select:hover { border-color: var(--line-firm); }

        input:focus, select:focus {
            border-color: var(--brand);
            box-shadow: var(--focus);
        }

        select {
            appearance: none;
            cursor: pointer;
            padding-right: 40px;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8' fill='none'%3E%3Cpath d='M1 1.5 6 6.5l5-5' stroke='%236B7C72' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
        }

        .field-error {
            color: var(--lg-danger-ink, #A32B2B);
            font-size: .78rem;
            font-weight: 500;
        }

        .hint {
            grid-column: 1 / -1;
            font-size: .78rem;
            color: var(--ink-soft);
            line-height: 1.5;
            padding: 10px 12px;
            background: var(--lg-rail, #EEF4F0);
            border-radius: var(--r-input);
        }

        .actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 26px;
            padding-top: 20px;
            border-top: 1px solid var(--line);
            gap: 12px;
            flex-wrap: wrap;
        }

        /* Nothing in this product underlines a word. Colour, weight and a
           tinted hover carry the link instead. */
        .link {
            color: var(--brand-deep);
            text-decoration: none;
            font-size: .86rem;
            font-weight: 600;
            border-radius: 6px;
            padding: 8px 10px;
            margin-left: -10px;
            transition: background .16s ease, color .16s ease;
        }

        .link:hover { background: var(--mint); color: var(--brand-dark); }

        .submit {
            background: var(--brand-deep);
            color: #fff;
            border: none;
            border-radius: var(--r-btn);
            min-height: 46px;
            padding: 12px 22px;
            cursor: pointer;
            font: inherit;
            font-size: .93rem;
            font-weight: 600;
            box-shadow: 0 1px 2px rgba(14, 45, 30, .16);
            transition: background .16s ease, box-shadow .16s ease, transform .08s ease;
        }

        .submit:hover {
            background: var(--brand-dark);
            box-shadow: 0 4px 14px rgba(14, 45, 30, .2);
        }

        .submit:active { transform: translateY(1px); }

        .link:focus-visible,
        .submit:focus-visible {
            outline: 2px solid var(--brand);
            outline-offset: 2px;
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: .001ms !important;
                transition-duration: .001ms !important;
            }
        }

        @media (max-width: 720px) {
            body { padding: 0; place-items: stretch; }

            .card {
                width: 100%;
                min-height: 100vh;
                border: none;
                border-radius: 0;
                box-shadow: none;
            }

            .head { padding: 24px 22px; gap: 14px; }

            .head-logo { width: 42px; height: 42px; }

            .head h1 { font-size: 1.5rem; }

            .body { padding: 24px 22px 28px; }

            .grid { grid-template-columns: 1fr; }

            .actions { flex-direction: column-reverse; align-items: stretch; }

            .actions .submit { width: 100%; }

            .actions .link { text-align: center; margin-left: 0; }
        }
    </style>
    {{-- One shared palette for pages not yet on lusog-theme.css. Loaded
         last so it overrides this page's own :root colours. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-palette.css')) !!}</style>
</head>
<body>
    <section class="card">
        <header class="head">
            <span class="head-ring" aria-hidden="true"></span>
            <span class="head-ring is-inner" aria-hidden="true"></span>
            <img src="{{ asset('images/lusog-logo.png') }}" alt="" class="head-logo" aria-hidden="true">
            <div class="head-text">
                <h1>Create Account Request</h1>
                <p>Fill out this form. Your request will be reviewed by the System Admin.</p>
            </div>
        </header>
        <div class="body">
            @if (session('success'))
                <div class="flash flash-ok" role="status">{{ session('success') }}</div>
            @endif
            @if ($errors->any())
                <div class="flash flash-err" role="alert">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('account.request.submit') }}" autocomplete="off" id="accountRequestForm">
                @csrf
                <div class="grid">
                    <div class="field">
                        <label for="name">Full Name</label>
                        <input id="name" name="name" type="text" value="{{ old('name') }}" minlength="2" maxlength="100" required>
                    </div>
                    <div class="field">
                        <label for="username">Username / Employee ID</label>
                        <input id="username" name="username" type="text" value="{{ old('username') }}" minlength="4" maxlength="32" required>
                    </div>
                    <div class="field">
                        <label for="password">Password</label>
                        <input id="password" name="password" type="password" minlength="8" maxlength="72" required>
                    </div>
                    <div class="field">
                        <label for="password_confirmation">Confirm Password</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" minlength="8" maxlength="72" required>
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
                        <label for="institution_id">School / Institution <span class="req">*</span></label>
                        @php($schools = ($institutions ?? collect()))
                        <select id="institution_id" name="institution_id">
                            @if ($schools->count() !== 1)
                                <option value="" disabled {{ old('institution_id') ? '' : 'selected' }}>Select your school…</option>
                            @endif
                            @foreach ($schools as $institution)
                                {{-- One school is offered, so it is the selection rather than
                                     something to choose; the server checks it regardless. --}}
                                <option value="{{ $institution->id }}" {{ $schools->count() === 1 || (string) old('institution_id') === (string) $institution->id ? 'selected' : '' }}>
                                    {{ $institution->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('institution_id')
                            <span class="field-error">{{ $message }}</span>
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
                            <span class="field-error">{{ $message }}</span>
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

            // Registration is limited to one school. When that is all the API
            // returns, it is the selection — offering a "Select your school…"
            // placeholder above a single option would leave the form invalid
            // until the user picked the only thing there was to pick.
            const single = institutions.length === 1;

            institutionSel.innerHTML = single
                ? ''
                : '<option value="" disabled>Select your school…</option>';

            institutions.forEach(function (inst) {
                const opt = document.createElement('option');
                opt.value = inst.id;
                opt.textContent = inst.name;
                if (single || String(inst.id) === oldInstitutionId) opt.selected = true;
                institutionSel.appendChild(opt);
            });

            if (!single && !oldInstitutionId) {
                institutionSel.selectedIndex = 0;
            }

            // A programmatic selection fires no change event, so the grade and
            // section cascade would never load for a Class Adviser.
            institutionSel.dispatchEvent(new Event('change'));
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
            } else if (!institutionSel.value && institutionSel.options.length === 1) {
                // Registration is limited to one school, and the branch above
                // cleared it while the role was unscoped. It is the only answer
                // available, so restore it rather than making the user pick it.
                institutionSel.selectedIndex = 0;
                loadSections();
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

        // Either a rejected submission coming back with the school already
        // chosen, or the single permitted school rendered pre-selected. Both
        // put a school on screen whose sections have not been fetched yet, and
        // neither fires a change event on its own.
        if (institutionSel.value) {
            loadSections();
        }
    </script>
</body>
</html>
