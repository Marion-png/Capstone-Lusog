<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>New Consultation - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
        @php $pageCssPath = resource_path('css/school-nurse-consultation-create.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
    {{-- One shared palette for pages not yet on lusog-theme.css. Loaded
         last so it overrides this page's own :root colours. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-palette.css')) !!}</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="head">
            <div>
                <div class="title">New Consultation</div>
                <div class="sub">Record a student clinic visit and treatment details.</div>
            </div>
            <a href="{{ $backTo ?? route('dashboard.consultation-log') }}" class="btn btn-ghost">Back</a>
        </div>
        <div class="body">
            <form method="POST" action="{{ route('consultations.store') }}">
                @csrf
                <input type="hidden" name="return_to" value="{{ old('return_to', $backTo ?? route('dashboard.consultation-log')) }}">
                <div class="grid">
                    <div class="field">
                        <label for="consulted_at">Date and Time</label>
                        <input id="consulted_at" type="datetime-local" name="consulted_at" value="{{ old('consulted_at', now()->format('Y-m-d\\TH:i')) }}" required>
                        @error('consulted_at') <div class="err">{{ $message }}</div> @enderror
                    </div>
                    {{-- A search over the school's roll: typing lists matching
                         learners, choosing one fills the grade and section from
                         their record and locks it, and typing over the name
                         afterwards clears the section again. A name typed and
                         never chosen is still accepted — a visitor not on the
                         roll is still a visit. --}}
                    <div class="field">
                        <label for="student_name">Student Name</label>
                        <div class="lsearch" id="student_search">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input id="student_name" type="text" name="student_name" value="{{ old('student_name', $prefillName ?? '') }}" placeholder="Type a learner's name or LRN…" required autocomplete="off" @readonly(trim((string) ($prefillName ?? '')) !== '')
                                   role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="student_results">
                            <div class="lsearch-list" id="student_results" role="listbox"></div>
                        </div>
                        @error('student_name') <div class="err">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="grade_section">Grade and Section</label>
                        <input id="grade_section" type="text" name="grade_section" value="{{ old('grade_section', $prefillSection ?? '') }}" placeholder="e.g. Grade 10 - Rizal" required autocomplete="off" @readonly(trim((string) old('grade_section', $prefillSection ?? '')) !== '')>
                        <div class="hint" id="grade_section_hint" @if (trim((string) old('grade_section', $prefillSection ?? '')) === '') hidden @endif>From the learner's record.</div>
                        @error('grade_section') <div class="err">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <x-condition-search
                            name="condition_id"
                            label="Condition"
                            placeholder="Search or type a condition..."
                        />
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
                    {{-- The clinic note goes with the visit — the same field the
                         profile's dialog carries — and reads back under this
                         consultation on the profile's Consultation Log tab. --}}
                    @if (\App\Support\SchemaCache::hasColumn('consultations', 'notes'))
                        <div class="field full">
                            <label for="notes">Clinic Notes <span class="optional">(optional)</span></label>
                            <textarea id="notes" name="notes" maxlength="2000" placeholder="Clinical observation, follow-up, or anything worth noting about this visit">{{ old('notes') }}</textarea>
                            @error('notes') <div class="err">{{ $message }}</div> @enderror
                        </div>
                    @endif
                </div>
                <div class="actions">
                    <button type="submit" class="btn btn-primary">Save Consultation</button>
                    <a href="{{ $backTo ?? route('dashboard.consultation-log') }}" class="btn btn-ghost">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
(() => {
    // Type to find. The list stays closed until something is typed, rows are
    // built from DOM nodes because the names come out of the database, and
    // choosing a learner fills the grade and section off their record.
    const wrap = document.getElementById('student_search');
    const input = document.getElementById('student_name');
    const list = document.getElementById('student_results');
    const section = document.getElementById('grade_section');
    const hint = document.getElementById('grade_section_hint');
    const learners = @json($learnerIndex ?? []);
    if (!wrap || !input || !list || !section) return;

    let picked = input.value.trim() !== '' && section.readOnly ? input.value.trim() : '';

    const close = () => { list.classList.remove('show'); input.setAttribute('aria-expanded', 'false'); };
    const lockSection = (locked) => {
        section.readOnly = locked;
        section.setAttribute('aria-readonly', locked ? 'true' : 'false');
        if (hint) hint.hidden = !locked;
    };

    const choose = (learner) => {
        picked = learner.name;
        input.value = learner.name;
        section.value = learner.section || '';
        lockSection(String(learner.section || '').trim() !== '');
        close();
        if (!section.readOnly) section.focus();
    };

    const row = (learner) => {
        const el = document.createElement('button');
        el.type = 'button';
        el.className = 'lsearch-row';
        el.setAttribute('role', 'option');
        el.appendChild(Object.assign(document.createElement('span'), { className: 'lsearch-name', textContent: learner.name }));
        el.appendChild(Object.assign(document.createElement('span'), { className: 'lsearch-sec', textContent: learner.section || 'No section on file' }));
        el.addEventListener('click', () => choose(learner));
        return el;
    };

    const apply = () => {
        // Opened for a learner already (?lrn=): the name is the record's, not a search.
        if (input.readOnly) { close(); return; }

        const raw = input.value.trim();
        const term = raw.toLowerCase();

        // Typed over a pick: the section was that learner's, not this name's.
        if (picked !== '' && raw !== picked) {
            picked = '';
            section.value = '';
            lockSection(false);
        }

        if (term === '' || learners.length === 0) { list.textContent = ''; close(); return; }

        const startsWith = (v) => String(v || '').toLowerCase().startsWith(term);
        const wordStartsWith = (v) => String(v || '').toLowerCase().split(/[^a-z0-9]+/).some((w) => w.startsWith(term));
        const tiers = [[], [], []];
        learners.forEach((l) => {
            if (startsWith(l.name) || startsWith(l.lrn)) tiers[0].push(l);
            else if (wordStartsWith(l.name)) tiers[1].push(l);
            else if (String(l.name).toLowerCase().includes(term)) tiers[2].push(l);
        });
        const matches = tiers[0].concat(tiers[1], tiers[2]);

        list.textContent = '';
        if (matches.length === 0) {
            list.appendChild(Object.assign(document.createElement('div'), {
                className: 'lsearch-empty',
                textContent: 'No learner on the roll matches "' + raw + '". The name will be recorded as typed.',
            }));
        }
        matches.slice(0, 8).forEach((l) => list.appendChild(row(l)));
        list.classList.add('show');
        input.setAttribute('aria-expanded', 'true');
    };

    input.addEventListener('input', apply);
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') { close(); return; }
        if (event.key === 'Enter' && list.classList.contains('show')) {
            const rows = list.querySelectorAll('.lsearch-row');
            if (rows.length === 1) { event.preventDefault(); rows[0].click(); }
        }
    });
    document.addEventListener('click', (event) => { if (!wrap.contains(event.target)) close(); });
})();
</script>
</body>
</html>
