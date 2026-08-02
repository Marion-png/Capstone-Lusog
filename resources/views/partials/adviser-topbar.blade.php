{{--
    Shared Class Adviser topbar. Pass $breadcrumb for the current page label
    (e.g. "Dashboard", "Feeding Status"). Search submits into the My
    Students tab, filtered by name/LRN.
--}}
@php
    $breadcrumb = $breadcrumb ?? 'Dashboard';
    $asbHasAlert = \Illuminate\Support\Facades\Schema::hasTable('health_consent_forms')
        ? \App\Models\HealthConsentForm::where('adviser_unread', true)
            ->when(session('active_institution_id'), fn ($q, $id) => $q->where('institution_id', $id))
            ->exists()
        : false;

    $asbSearchGrade = (string) session('assigned_grade_level', '');
    $asbSearchSection = (string) session('assigned_section', '');
    $asbSearchRoster = collect(session('school_health_card_records', []))
        ->filter(function ($row) use ($asbSearchGrade, $asbSearchSection) {
            if ($asbSearchGrade === '' || $asbSearchSection === '') {
                return true;
            }

            return (string) ($row['grade_level'] ?? '') === $asbSearchGrade
                && strcasecmp(trim((string) ($row['section'] ?? '')), trim($asbSearchSection)) === 0;
        })
        ->unique(fn ($row) => (string) ($row['lrn'] ?? ''))
        ->map(function ($row) {
            $middle = trim((string) ($row['middle_name'] ?? ''));
            $middleInitial = $middle !== '' ? ' '.strtoupper(substr($middle, 0, 1)).'.' : '';
            $name = trim(($row['last_name'] ?? '').', '.($row['first_name'] ?? '').$middleInitial);

            return [
                'lrn' => (string) ($row['lrn'] ?? ''),
                'name' => $name !== ',' ? $name : ((string) ($row['lrn'] ?? '')),
                'section' => trim(($row['grade_level'] ?? '').' - '.($row['section'] ?? ''), ' -'),
                'sex' => (string) ($row['gender'] ?? '-'),
            ];
        })
        ->filter(fn ($row) => $row['lrn'] !== '')
        ->values();
@endphp
<header class="asb-topbar">
    <div class="asb-crumb">
        {{-- The dashboard switches its tabs client-side, so these two carry ids
             for switchAdviserTab() to keep in step with the visible panel. --}}
        <div class="asb-crumb-title" id="asbCrumbTitle">{{ $breadcrumb }}</div>
        <div class="asb-crumb-path">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" style="width:12px;height:12px;"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
            <a href="{{ route('dashboard.class-adviser') }}">Home</a>
            <span>/</span>
            <span id="asbCrumbCurrent">{{ $breadcrumb }}</span>
        </div>
    </div>

    <form method="GET" action="{{ route('dashboard.class-adviser') }}" class="asb-search" id="asbSearchForm">
        <input type="hidden" name="tab" value="saved">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="search" name="q" id="asbSearchInput" placeholder="Search students..." value="{{ request('q') }}" autocomplete="off">
        <div class="asb-search-dropdown" id="asbSearchDropdown"></div>
    </form>

    <div class="asb-topbar-right">
        @include('partials.live-clock')
        <a href="{{ route('dashboard.class-adviser') }}#needs-attention" class="asb-icon-btn" title="Notifications" aria-label="Notifications">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            @if ($asbHasAlert)<span class="asb-icon-dot"></span>@endif
        </a>
        <div class="asb-icon-btn asb-profile" title="{{ session('active_name', 'Class Adviser') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </div>
    </div>
</header>

@include('partials.adviser-toast')

<script>
(() => {
    const roster = @json($asbSearchRoster);
    const input = document.getElementById('asbSearchInput');
    const dropdown = document.getElementById('asbSearchDropdown');
    const searchBox = document.getElementById('asbSearchForm');

    if (!input || !dropdown || !searchBox || !roster.length) {
        return;
    }

    const studentsUrl = (lrn) => `{{ route('dashboard.class-adviser') }}?tab=saved&q=${encodeURIComponent(lrn)}`;

    const render = (query) => {
        const term = query.trim().toLowerCase();
        if (term === '') {
            dropdown.classList.remove('show');
            dropdown.innerHTML = '';
            return;
        }

        const results = roster.filter((s) => (
            s.name.toLowerCase().includes(term) || s.lrn.toLowerCase().includes(term)
        ));

        if (results.length === 0) {
            dropdown.innerHTML = `
                <div class="asb-no-results">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <p>No students found matching "${term}"</p>
                </div>
            `;
            dropdown.classList.add('show');
            return;
        }

        const countLabel = results.length === 1 ? '1 result' : `${results.length} results`;
        const items = results.slice(0, 8).map((s) => {
            const parts = s.name.split(',');
            const last = (parts[0] || '').trim();
            const first = (parts[1] || '').trim();
            const initials = ((first.charAt(0) || last.charAt(0) || '?') + (last.charAt(0) || '')).toUpperCase();
            return `
                <a href="${studentsUrl(s.lrn)}" class="asb-result-item">
                    <div class="asb-result-avatar">${initials}</div>
                    <div class="asb-result-info">
                        <div class="asb-result-name">${s.name}</div>
                        <div class="asb-result-meta">
                            <span>${s.lrn}</span>
                            <span>${s.section}</span>
                            <span>${s.sex}</span>
                        </div>
                    </div>
                </a>
            `;
        }).join('');

        dropdown.innerHTML = `<div class="asb-result-count">${countLabel}</div>${items}`;
        dropdown.classList.add('show');
    };

    input.addEventListener('input', () => render(input.value));

    input.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') {
            return;
        }
        const first = dropdown.querySelector('.asb-result-item');
        if (first) {
            e.preventDefault();
            window.location.href = first.href;
        }
    });

    document.addEventListener('click', (e) => {
        if (!searchBox.contains(e.target)) {
            dropdown.classList.remove('show');
        }
    });
})();
</script>
