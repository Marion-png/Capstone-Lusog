{{--
    Clinic Teacher side rail, LUSOG design system.

    Pass $active to highlight the current item: 'dashboard' | 'records' |
    'queue' | 'consultations' | 'nutritional-status' | 'consent' |
    'inventory' | 'visualization' | 'reports'.

    Same .sb-* markup and css/nurse-sidebar.css as the School Nurse and
    Clinic Staff rails — a teacher covering the clinic works the same screens
    the nurse does, so the rail is the nurse's module list and not a third
    design.

    Two things differ, and only two:

      - Dashboard points at dashboard.clinic-teacher rather than the nurse's
        URL. The page behind it is the same reading (App\Support\ClinicDashboard),
        but the URL has to belong to this role: EnsureActiveSession re-seeds a
        prototype session for whichever role a URL belongs to, so a demo
        Clinic Teacher on /dashboard/school-nurse would become a demo nurse
        before the page rendered.
      - There is no entry that writes inventory. Receive Stock and Add
        Medicine are the nurse's; this role reads the shelf and draws on it
        through a consultation. The restriction lives on those endpoints, not
        on this rail — a rail entry that is merely absent is not enforcement.
--}}
@php
    $active = $active ?? 'dashboard';

    // Cards the advisers have handed over that nobody has examined yet — the
    // same count the nurse's rail carries, from the same source, so the badge
    // does not change meaning between the two desks.
    $ctSbPending = collect(session('school_health_card_records', []))
        ->filter(fn ($row) => empty($row['examination']))
        ->count();

    $ctSbName = trim((string) session('active_name', 'Clinic Teacher')) ?: 'Clinic Teacher';
    $ctSbInitials = collect(preg_split('/\s+/', $ctSbName))
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
        <a href="{{ route('dashboard.clinic-teacher') }}" class="sb-link {{ $active === 'dashboard' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Dashboard
        </a>
        <a href="{{ route('dashboard.student-health-records') }}" class="sb-link {{ $active === 'records' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Health Records
            @if ($ctSbPending > 0)<span class="sb-count alert">{{ $ctSbPending }}</span>@endif
        </a>
        <a href="{{ route('nurse.index') }}" class="sb-link {{ $active === 'queue' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg>
            Health Assessment
        </a>
        <a href="{{ route('dashboard.consultation-log') }}" class="sb-link {{ $active === 'consultations' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 12l2 2 4-4"/><path d="M21 12c0 4.97-4.03 9-9 9S3 16.97 3 12 7.03 3 12 3s9 4.03 9 9z"/></svg>
            Consultation Log
        </a>
        <a href="{{ route('dashboard.clinic-teacher.nutritional-status') }}" class="sb-link {{ $active === 'nutritional-status' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 3v18h18"/><path d="M7 16l4-5 3 3 5-7"/></svg>
            Nutritional Health Status
        </a>

        <div class="sb-section-label">Health Programs</div>
        <a href="{{ route('consent-forms.nurse-index') }}" class="sb-link {{ $active === 'consent' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>
            Consent Forms
        </a>

        <div class="sb-section-label">Inventory</div>
        <a href="{{ route('dashboard.medicine-inventory') }}" class="sb-link {{ $active === 'inventory' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="2" width="18" height="20" rx="2"/><path d="M9 2v4h6V2"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
            Medicine Inventory
        </a>

        <div class="sb-section-label">Reports</div>
        <a href="{{ route('dashboard.data-visualization') }}" class="sb-link {{ $active === 'visualization' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            Data Visualization
        </a>
        <a href="#" class="sb-link {{ $active === 'reports' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Generate Reports
        </a>

        <div class="sb-section-label">System</div>
        <a href="{{ route('settings') }}" class="sb-link {{ $active === 'settings' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            Settings
        </a>
    </nav>

    <div class="sb-user">
        <div class="sb-avatar">{{ $ctSbInitials ?: 'CT' }}</div>
        <div class="sb-user-meta">
            <div class="sb-user-name">{{ $ctSbName }}</div>
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
