{{--
    Learner search for a role's topbar — one control shared by the Class
    Adviser and the School Nurse.

    Both roles grew their own version and drifted: different field shape,
    avatar size, result fields and empty state. This is the single one.

    Parameters:
      $roster      list of ['lrn','name','section','sex'] (sex optional)
      $hrefPattern URL with {lrn} where the learner's LRN belongs
      $placeholder input placeholder, defaults to "Search students..."
      $formAction  optional: wrap in a GET form so Enter submits a search
                   (the adviser does this — several learners can match a
                   partial name, and that case wants a filtered list)
      $formFields  optional: hidden fields for that form, as key => value

    The roster is embedded and filtered in the browser because student
    names are encrypted at rest and no SQL LIKE can see them.
--}}
@php
    $lsRoster = collect($roster ?? [])->values();
    $lsPlaceholder = $placeholder ?? 'Search students...';
    $lsFormAction = $formAction ?? null;
    $lsFormFields = $formFields ?? [];
    $lsHref = $hrefPattern ?? '#';
@endphp

@once
    <style>{!! file_get_contents(resource_path('css/learner-search.css')) !!}</style>
@endonce

@if ($lsFormAction)
    <form method="GET" action="{{ $lsFormAction }}" class="lsearch" id="lsearchBox">
        @foreach ($lsFormFields as $field => $value)
            <input type="hidden" name="{{ $field }}" value="{{ $value }}">
        @endforeach
@else
    <div class="lsearch" id="lsearchBox">
@endif

    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    <input type="search"
           id="lsearchInput"
           @if ($lsFormAction) name="q" @endif
           placeholder="{{ $lsPlaceholder }}"
           value="{{ $lsFormAction ? request('q') : '' }}"
           autocomplete="off"
           role="combobox"
           aria-expanded="false"
           aria-controls="lsearchResults">
    <div class="lsearch-dropdown" id="lsearchResults" role="listbox"></div>

@if ($lsFormAction)
    </form>
@else
    </div>
@endif

<script>
(() => {
    const box = document.getElementById('lsearchBox');
    const input = document.getElementById('lsearchInput');
    const results = document.getElementById('lsearchResults');
    if (!box || !input || !results) return;

    const roster = @json($lsRoster);
    const hrefFor = (lrn) => @json($lsHref).replace('{lrn}', encodeURIComponent(lrn));

    const initialsOf = (name) => {
        const parts = String(name).split(',');
        const last = (parts[0] || '').trim();
        const first = (parts[1] || '').trim();
        return ((first.charAt(0) || last.charAt(0) || '?') + (last.charAt(0) || '')).toUpperCase();
    };

    const close = () => {
        results.classList.remove('show');
        input.setAttribute('aria-expanded', 'false');
    };

    // Built from DOM nodes, never innerHTML: these are names typed by an
    // adviser, and a template string would run any markup inside one.
    const render = (matches, term) => {
        results.textContent = '';

        if (matches.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'lsearch-empty';

            const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            icon.setAttribute('viewBox', '0 0 24 24');
            icon.setAttribute('fill', 'none');
            icon.setAttribute('stroke', 'currentColor');
            icon.setAttribute('stroke-width', '2');
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2');
            const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
            circle.setAttribute('cx', '9');
            circle.setAttribute('cy', '7');
            circle.setAttribute('r', '4');
            icon.append(path, circle);

            const text = document.createElement('span');
            text.textContent = 'No students found matching "' + term + '"';

            empty.append(icon, text);
            results.appendChild(empty);
            return;
        }

        const count = document.createElement('div');
        count.className = 'lsearch-count';
        count.textContent = matches.length === 1 ? '1 result' : matches.length + ' results';
        results.appendChild(count);

        matches.slice(0, 8).forEach((row) => {
            const link = document.createElement('a');
            link.className = 'lsearch-row';
            link.setAttribute('role', 'option');
            link.href = hrefFor(row.lrn);

            const avatar = document.createElement('div');
            avatar.className = 'lsearch-avatar';
            avatar.textContent = initialsOf(row.name);

            const info = document.createElement('div');
            info.className = 'lsearch-info';

            const name = document.createElement('div');
            name.className = 'lsearch-name';
            name.textContent = row.name;

            const meta = document.createElement('div');
            meta.className = 'lsearch-meta';
            [row.lrn, row.section, row.sex].filter(Boolean).forEach((value) => {
                const span = document.createElement('span');
                span.textContent = value;
                meta.appendChild(span);
            });

            info.append(name, meta);
            link.append(avatar, info);
            results.appendChild(link);
        });
    };

    const apply = () => {
        const raw = input.value.trim();
        const term = raw.toLowerCase();

        if (term === '') {
            close();
            results.textContent = '';
            return;
        }

        render(
            roster.filter((row) =>
                String(row.name).toLowerCase().includes(term) ||
                String(row.lrn).toLowerCase().includes(term)
            ),
            raw
        );

        results.classList.add('show');
        input.setAttribute('aria-expanded', 'true');
    };

    input.addEventListener('input', apply);
    input.addEventListener('focus', apply);

    document.addEventListener('click', (event) => {
        if (!box.contains(event.target)) close();
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            close();
            input.blur();
        }
    });
})();
</script>
