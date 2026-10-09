<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Dashboard - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <script>document.documentElement.classList.add('js');</script>
    {{-- LUSOG order: theme, then this page's sheet, then the nurse rail. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
    @php $pageCssPath = resource_path('css/school-nurse.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
    <style>{!! file_get_contents(resource_path('css/nurse-sidebar.css')) !!}</style>
</head>
<body>
{{-- The School Nurse and the Clinic Teacher read the same clinic, so they
     read the same dashboard — one reading, in whichever rail the reader
     belongs to, never a second copy that could report different figures. --}}
@include('partials.clinic-rail', ['active' => 'dashboard'])

<div class="main">
    @php
        $cdRoleLabel = \App\Support\AccountSettings::roleLabel(session('active_role'));
        $nurseName = session('active_name', $cdRoleLabel);
        $schoolName = session('active_school_name', 'No school assigned');
        $schoolYear = \App\Models\StudentHealthRecord::currentSchoolYear();
        $greetHour = (int) now()->format('G');
        $greeting = $greetHour < 12 ? 'Good morning' : ($greetHour < 18 ? 'Good afternoon' : 'Good evening');
    @endphp

    <header class="topbar">
        <div class="topbar-bc"><span>{{ $cdRoleLabel }}</span><span class="bc-sep">&rsaquo;</span><span>Dashboard</span></div>

        @include('partials.nurse-learner-search')

        <div class="topbar-chip"><span class="dot"></span>{{ $schoolName }} &middot; SY {{ $schoolYear }}</div>
        @include('partials.live-clock')
    </header>

    <div class="content">
        <div class="page-header">
            <div class="card-head" style="margin-bottom:0">
                <div>
                    <div class="page-eyebrow">{{ $greeting }}, {{ $cdRoleLabel }}</div>
                    <h1 class="page-title">Dashboard <span>School Clinic</span></h1>
                    <p class="page-sub">
                        {{ $nurseName }} &middot; {{ $schoolName }} &middot; School Year {{ $schoolYear }}.
                        Today's consultations, learners needing attention, and stock running short.
                    </p>
                </div>
            </div>
        </div>

        <div class="kpi-grid">
            <div class="card kpi accent-brand">
                <div class="kpi-top">
                    <div class="kpi-label">Total Records</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($totalRecords) }}</div>
                <div class="kpi-hint">Learner health cards on file</div>
            </div>

            <div class="card kpi accent-info">
                <div class="kpi-top">
                    <div class="kpi-label">Consultations Today</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 12l2 2 4-4"/><path d="M21 12c0 4.97-4.03 9-9 9S3 16.97 3 12 7.03 3 12 3s9 4.03 9 9z"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($consultationsToday) }}</div>
                <div class="kpi-hint">Logged at the clinic today</div>
            </div>

            <div class="card kpi accent-orange">
                <div class="kpi-top">
                    <div class="kpi-label">At-Risk Learners</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($atRiskCount) }}</div>
                <div class="kpi-hint">Flagged for follow-up</div>
            </div>

            <div class="card kpi accent-amber">
                <div class="kpi-top">
                    <div class="kpi-label">Low Stock Medicines</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="2" width="18" height="20" rx="2"/><path d="M9 2v4h6V2"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($lowStockCount) }}</div>
                <div class="kpi-hint">At or below reorder point</div>
            </div>
        </div>

        <div class="board-row" style="margin-top:20px">
            @include('partials.announcements')
            @include('partials.upcoming-events')
        </div>

        <div class="toolbar">
            <div style="flex:1;min-width:240px">
                <label class="field-label" for="consultSearch">Search</label>
                <div class="lg-search">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" id="consultSearch" placeholder="Search learner, complaint, section..." autocomplete="off">
                </div>
            </div>
            <div>
                <label class="field-label" for="consultDateFilter">Date</label>
                {{-- A calendar, not a Today / Week / Month bucket: the nurse
                     picks the day and reads that day's visits. Cleared, it
                     shows every date. --}}
                <input type="date" class="input" id="consultDateFilter" max="{{ now()->toDateString() }}" aria-label="Filter consultations by date">
            </div>
            {{-- Grade, section and gender are read per visit by
                 ClinicDashboard::consultationFilters(): the first two off the
                 label the row prints, the gender off the learner the name
                 matches on the roster. Personnel stays an option because staff
                 visits are on this table and have no grade. --}}
            @php
                $cfGrades = $consultationFilters['grades'] ?? [];
                $cfSections = $consultationFilters['sections'] ?? [];
                $cfSectionMap = collect($cfSections)->mapWithKeys(fn ($names, $grade) => [strtolower($grade) => $names])->all();
                $cfAllSections = collect($cfSections)->flatten()
                    ->unique(fn ($name) => strtolower($name))
                    ->sort(fn ($a, $b) => strnatcasecmp($a, $b))
                    ->values();
            @endphp
            <div>
                <label class="field-label" for="consultGradeFilter">Grade Level</label>
                <select class="select" id="consultGradeFilter">
                    <option value="all">All grade levels</option>
                    @foreach ($cfGrades as $grade)
                        <option value="{{ strtolower($grade) }}">{{ $grade }}</option>
                    @endforeach
                    <option value="personnel">Personnel</option>
                </select>
            </div>
            <div>
                <label class="field-label" for="consultSectionFilter">Section</label>
                {{-- Rebuilt for the chosen grade in the script below: a section
                     belongs to one grade, and offering the whole list would let
                     the nurse pick a pair that names nobody. --}}
                <select class="select" id="consultSectionFilter"
                        data-sections='@json($cfSectionMap, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)'>
                    <option value="all">All sections</option>
                    @foreach ($cfAllSections as $section)
                        <option value="{{ strtolower($section) }}">{{ $section }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="field-label" for="consultSexFilter">Gender</label>
                <select class="select" id="consultSexFilter">
                    <option value="all">All</option>
                    @foreach (\App\Support\FeedingBeneficiarySummary::SEX_OPTIONS as $sex)
                        <option value="{{ strtolower($sex) }}">{{ $sex }}</option>
                    @endforeach
                </select>
            </div>
            <div class="spacer"></div>
            <div class="toolbar-count" id="consultCount">{{ $recentConsultations->count() }} {{ Str::plural('entry', $recentConsultations->count()) }}</div>
        </div>

        <div class="table-card">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Grade / Section</th>
                            <th>Date</th>
                            <th>Condition</th>
                            <th>Treatment</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="consultTableBody">
                        @forelse($recentConsultations as $c)
                        @php
                            $studentName = trim((string) $c->student_name);
                            $gradeSection = trim((string) $c->grade_section);
                            $condition = trim((string) $c->condition);
                            $treatment = trim((string) $c->treatment_given);

                            $nameParts = array_values(array_filter(explode(' ', $studentName)));
                            $initials = $nameParts === []
                                ? '?'
                                : strtoupper(substr($nameParts[0], 0, 1).substr(end($nameParts), 0, 1));

                            // Decided once, server-side, from the decrypted values
                            // (see ClinicDashboard::consultationFilters). A visit
                            // with no grade is a staff visit: Personnel.
                            $cfRow = $consultationFilters['rows'][$c->id] ?? ['grade' => '', 'section' => '', 'sex' => ''];
                            $rowGrade = $cfRow['grade'] !== '' ? strtolower($cfRow['grade']) : 'personnel';

                            // The row carries its own calendar date, stamped
                            // server-side so the filter never depends on the
                            // browser's clock or timezone.
                            $consultedOn = $c->consulted_at?->toDateString() ?? '';
                        @endphp
                        <tr class="js-consult-row"
                            data-search="{{ strtolower($studentName.' '.$gradeSection.' '.$condition.' '.$treatment) }}"
                            data-grade="{{ $rowGrade }}"
                            data-section="{{ strtolower($cfRow['section']) }}"
                            data-sex="{{ strtolower($cfRow['sex']) }}"
                            data-date="{{ $consultedOn }}">
                            <td>
                                <div class="td-person">
                                    <div class="td-avatar">{{ $initials }}</div>
                                    <div class="td-name">{{ $studentName !== '' ? $studentName : '—' }}</div>
                                </div>
                            </td>
                            <td>{{ $gradeSection !== '' ? $gradeSection : '—' }}</td>
                            <td class="tnum">{{ $c->consulted_at?->format('M j, Y') ?? '—' }}</td>
                            <td>{{ $condition !== '' ? $condition : '—' }}</td>
                            <td>{{ $treatment !== '' ? $treatment : '—' }}</td>
                            <td>
                                @if($c->status === 'referred')
                                    <span class="badge badge-monitor">Referred</span>
                                @else
                                    <span class="badge badge-normal">Treated</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="table-empty">No consultations recorded yet.</td>
                        </tr>
                        @endforelse
                        <tr id="consultNoMatch" hidden>
                            <td colspan="6" class="table-empty">No consultations match your search or filters.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="summary-row">
            <div class="card summary-panel">
                <div class="card-head">
                    <div>
                        <div class="card-title">Top Consultation Cases</div>
                        <div class="card-sub">This month</div>
                    </div>
                </div>
                @php $maxConditionCount = $topConditions->max('total') ?: 1; @endphp
                @forelse($topConditions as $cond)
                <div class="meter-row">
                    <div class="meter-label">{{ Str::ucfirst($cond['name']) }}</div>
                    <div class="meter-track">
                        <div class="meter-fill" style="width:{{ round($cond['total'] / $maxConditionCount * 100) }}%"></div>
                    </div>
                    <div class="meter-value">{{ $cond['total'] }}</div>
                </div>
                @empty
                <div class="empty-panel">No consultations this month.</div>
                @endforelse
            </div>

            <div class="card summary-panel">
                <div class="card-head">
                    <div>
                        <div class="card-title">Medicine Stock Monitor</div>
                        <div class="card-sub">At or below reorder point</div>
                    </div>
                </div>
                @forelse($lowStockMedicines as $med)
                @php
                    $pct = $med->minimum_threshold > 0
                        ? min(100, (int) round($med->stock_quantity / $med->minimum_threshold * 100))
                        : 0;
                    // A quarter of the reorder point or less is critical; the rest
                    // is still worth watching but not yet an emergency.
                    $stockState = $pct <= 25 ? 'is-critical' : 'is-low';
                @endphp
                <div class="stock-row {{ $stockState }}">
                    <div class="stock-meta">
                        <strong>{{ $med->name }}</strong>
                        <span class="tnum">{{ $med->stock_quantity }} / {{ $med->minimum_threshold }} {{ $med->unit }}</span>
                    </div>
                    <div class="meter-track"><div class="meter-fill" style="width:{{ $pct }}%"></div></div>
                </div>
                @empty
                <div class="empty-panel">All medicines are adequately stocked.</div>
                @endforelse
            </div>
        </div>

        <div class="quick-access">
            <a href="{{ route('dashboard.student-health-records') }}" class="card qa-card accent-brand">
                <div class="qa-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <div>
                    <div class="qa-title">Health Records</div>
                    <div class="qa-desc">Review and examine learner health cards</div>
                </div>
            </a>
            <a href="{{ route('dashboard.consultation-log') }}" class="card qa-card accent-info">
                <div class="qa-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 12l2 2 4-4"/><path d="M21 12c0 4.97-4.03 9-9 9S3 16.97 3 12 7.03 3 12 3s9 4.03 9 9z"/></svg>
                </div>
                <div>
                    <div class="qa-title">Consultation Log</div>
                    <div class="qa-desc">Record and track clinic consultations</div>
                </div>
            </a>
            <a href="{{ route('dashboard.medicine-inventory') }}" class="card qa-card accent-amber">
                <div class="qa-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="2" width="18" height="20" rx="2"/><path d="M9 2v4h6V2"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
                </div>
                <div>
                    <div class="qa-title">Medicine Inventory</div>
                    <div class="qa-desc">Monitor stock levels and dispensing</div>
                </div>
            </a>
            <a href="{{ route('consent-forms.nurse-index') }}" class="card qa-card accent-orange">
                <div class="qa-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>
                </div>
                <div>
                    <div class="qa-title">Consent Forms</div>
                    <div class="qa-desc">Read released parent consent forms</div>
                </div>
            </a>
        </div>
    </div>
</div>

@include('partials.nurse-page-transition')



<script>
// Recent Consultations: search + date/grade/section/gender filters over the
// rendered rows.
(() => {
    const search = document.getElementById('consultSearch');
    const dateFilter = document.getElementById('consultDateFilter');
    const gradeFilter = document.getElementById('consultGradeFilter');
    const sectionFilter = document.getElementById('consultSectionFilter');
    const sexFilter = document.getElementById('consultSexFilter');
    const tbody = document.getElementById('consultTableBody');

    if (!search || !dateFilter || !gradeFilter || !sectionFilter || !sexFilter || !tbody) {
        return;
    }

    const rows = Array.from(tbody.querySelectorAll('.js-consult-row'));
    const noMatch = document.getElementById('consultNoMatch');
    const count = document.getElementById('consultCount');

    let sectionsByGrade = {};
    try {
        sectionsByGrade = JSON.parse(sectionFilter.dataset.sections || '{}') || {};
    } catch (error) {
        sectionsByGrade = {};
    }

    const byName = (a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });

    // The section list follows the grade: one grade's sections, every
    // section under "All grade levels", none for Personnel. A section the
    // new grade does not run is cleared rather than left matching nobody.
    const rebuildSections = () => {
        const grade = gradeFilter.value;
        const previous = sectionFilter.value;
        let names = [];

        if (grade === 'all') {
            const seen = new Map();
            Object.values(sectionsByGrade).flat().forEach((name) => {
                if (!seen.has(name.toLowerCase())) seen.set(name.toLowerCase(), name);
            });
            names = Array.from(seen.values()).sort(byName);
        } else {
            names = (sectionsByGrade[grade] || []).slice();
        }

        sectionFilter.replaceChildren(
            new Option('All sections', 'all'),
            ...names.map((name) => new Option(name, name.toLowerCase()))
        );
        sectionFilter.value = names.some((name) => name.toLowerCase() === previous) ? previous : 'all';
        sectionFilter.disabled = names.length === 0;
    };

    // Each row is stamped server-side with its calendar date, so matching
    // the picked day never depends on the browser's clock or timezone.
    const apply = () => {
        const keyword = search.value.trim().toLowerCase();
        const date = dateFilter.value;
        const grade = gradeFilter.value;
        const section = sectionFilter.value;
        const sex = sexFilter.value;
        let visible = 0;

        rows.forEach((row) => {
            const haystack = row.dataset.search || '';
            const matchesKeyword = !keyword || haystack.includes(keyword);
            const matchesPeriod = !date || (row.dataset.date || '') === date;
            const matchesGrade = grade === 'all' || (row.dataset.grade || '') === grade;
            const matchesSection = section === 'all' || (row.dataset.section || '') === section;
            const matchesSex = sex === 'all' || (row.dataset.sex || '') === sex;
            const show = matchesKeyword && matchesPeriod && matchesGrade && matchesSection && matchesSex;

            row.hidden = !show;
            if (show) {
                visible += 1;
            }
        });

        if (noMatch) {
            noMatch.hidden = rows.length === 0 || visible > 0;
        }
        if (count) {
            count.textContent = visible + (visible === 1 ? ' entry' : ' entries');
        }
    };

    search.addEventListener('input', apply);
    dateFilter.addEventListener('change', apply);
    dateFilter.addEventListener('input', apply);
    gradeFilter.addEventListener('change', () => {
        rebuildSections();
        apply();
    });
    sectionFilter.addEventListener('change', apply);
    sexFilter.addEventListener('change', apply);
    rebuildSections();
    apply();
})();
</script>
@include('partials.workspace-live')
</body>
</html>
