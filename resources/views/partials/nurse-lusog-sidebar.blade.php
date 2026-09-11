{{--
    School Nurse side rail, LUSOG design system.

    Pass $active to highlight the current item: 'dashboard' | 'records' |
    'queue' | 'consultations' | 'feeding' | 'deworming' | 'consent' |
    'assessments' | 'inventory' | 'dispensing' | 'visualization' | 'reports'.

    Markup only — the .sb-* rules live in css/nurse-sidebar.css, which the
    page must inline after css/lusog-theme.css. This replaces the older
    .nsb-* rail in partials/nurse-sidebar.blade.php; both exist while the
    remaining nurse pages are still being moved over.
--}}
@php
    $active = $active ?? 'dashboard';

    // Cards the adviser has handed over but nobody has examined yet. Same
    // source as the old rail so the number does not change meaning.
    $nurseSbPending = collect(session('school_health_card_records', []))
        ->filter(fn ($row) => empty($row['examination']))
        ->count();

    $nurseSbName = trim((string) session('active_name', 'School Nurse')) ?: 'School Nurse';
    $nurseSbInitials = collect(preg_split('/\s+/', $nurseSbName))
        ->filter()
        ->map(fn ($part) => strtoupper(substr($part, 0, 1)))
        ->take(2)
        ->implode('');
@endphp
<aside class="sidebar">
    <div class="sb-grid"></div>
    <div class="sb-logo">
        <img src="{{ asset('images/lusog-logo.png') }}" alt="LUSOG" class="sb-logo-full">
    </div>

    <nav class="sb-nav">
        <div class="sb-section-label">Main</div>
        <a href="{{ route('dashboard.school-nurse') }}" class="sb-link {{ $active === 'dashboard' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Dashboard
        </a>
        <a href="{{ route('dashboard.student-health-records') }}" class="sb-link {{ $active === 'records' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Health Records
            @if ($nurseSbPending > 0)<span class="sb-count alert">{{ $nurseSbPending }}</span>@endif
        </a>
        {{-- One entry, not two. This and the old "Health Assessments" tab were
             the same job under different names — the nurse reading what the
             class advisers submitted — so the rail now names it once. The
             route behind it is unchanged (nurse.index); only the label moved.
             health-assessments.nurse-index is still live for old links. --}}
        <a href="{{ route('nurse.index') }}" class="sb-link {{ $active === 'queue' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg>
            Health Assessment
        </a>
        <a href="{{ route('dashboard.consultation-log') }}" class="sb-link {{ $active === 'consultations' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 12l2 2 4-4"/><path d="M21 12c0 4.97-4.03 9-9 9S3 16.97 3 12 7.03 3 12 3s9 4.03 9 9z"/></svg>
            Consultation Log
        </a>

        <div class="sb-section-label">Health Programs</div>
        <a href="{{ route('dashboard.school-nurse.feeding-program') }}" class="sb-link {{ $active === 'feeding' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
            Feeding Program
        </a>
        {{-- Deworming Program is deliberately not listed. The page, its
             routes and its data are all still live at
             dashboard.school-nurse.deworming — only the rail entry is
             hidden. Deworming also remains a parental-consent service and
             a field on the medical examination; neither is affected. --}}
        <a href="{{ route('consent-forms.nurse-index') }}" class="sb-link {{ $active === 'consent' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>
            Consent Forms
        </a>
        {{-- Health Assessments is deliberately not listed. It and the Review
             Queue were two names for one job, so the rail carries it once, as
             "Health Assessment", under Clinic. The page and its routes are
             still live at health-assessments.nurse-index — only the duplicate
             rail entry is gone, exactly as Deworming was handled above. --}}

        <div class="sb-section-label">Inventory</div>
        <a href="{{ route('dashboard.medicine-inventory') }}" class="sb-link {{ $active === 'inventory' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="2" width="18" height="20" rx="2"/><path d="M9 2v4h6V2"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
            Medicine Inventory
        </a>
        {{-- No Dispensing Log tab: a dispense is recorded in the consultation
             dialog, on the visit it belongs to, and nowhere else. --}}

        <div class="sb-section-label">Reports</div>
        <a href="{{ route('dashboard.data-visualization') }}" class="sb-link {{ $active === 'visualization' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            Data Visualization
        </a>
        <a href="#" class="sb-link {{ $active === 'reports' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Generate Reports
        </a>
    </nav>

    <div class="sb-user">
        <div class="sb-avatar">{{ $nurseSbInitials ?: 'SN' }}</div>
        <div class="sb-user-meta">
            <div class="sb-user-name">{{ $nurseSbName }}</div>
            <div class="sb-user-role">{{ session('active_school_name', 'No school assigned') }}</div>
        </div>
        {{-- Signing out changes state, so it posts a CSRF-protected form
             rather than following a link. --}}
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="sb-logout" title="Sign out">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </button>
        </form>
    </div>
</aside>
