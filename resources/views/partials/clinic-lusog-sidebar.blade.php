{{--
    Clinic Staff side rail, LUSOG design system.

    Pass $active to highlight the current item: 'dashboard' | 'records' |
    'consultations' | 'inventory'.

    Same .sb-* markup and css/nurse-sidebar.css as the School Nurse rail —
    the two roles share the clinic section, so they share a rail design.
    What differs is the nav: Clinic Staff has no Review Queue, no health
    programmes, and no Dispensing Log, which is the nurse's alone.
--}}
@php
    $active = $active ?? 'dashboard';

    $clinicSbName = trim((string) session('active_name', 'Clinic Staff')) ?: 'Clinic Staff';
    $clinicSbInitials = collect(preg_split('/\s+/', $clinicSbName))
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
        <div class="sb-section-label">Clinic</div>
        <a href="{{ route('dashboard.clinic-staff') }}" class="sb-link {{ $active === 'dashboard' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Dashboard
        </a>
        <a href="{{ route('dashboard.student-health-records') }}" class="sb-link {{ $active === 'records' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Health Records
        </a>
        <a href="{{ route('dashboard.consultation-log') }}" class="sb-link {{ $active === 'consultations' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 12l2 2 4-4"/><path d="M21 12c0 4.97-4.03 9-9 9S3 16.97 3 12 7.03 3 12 3s9 4.03 9 9z"/></svg>
            Consultation Log
        </a>

        <div class="sb-section-label">Inventory</div>
        <a href="{{ route('dashboard.medicine-inventory') }}" class="sb-link {{ $active === 'inventory' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="2" width="18" height="20" rx="2"/><path d="M9 2v4h6V2"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
            Medicine Inventory
        </a>
        {{-- Dispensing Log is deliberately absent: recording a dispense is
             the School Nurse's alone, enforced in MedicineDispenseController. --}}
    </nav>

    <div class="sb-user">
        <div class="sb-avatar">{{ $clinicSbInitials ?: 'CS' }}</div>
        <div class="sb-user-meta">
            <div class="sb-user-name">{{ $clinicSbName }}</div>
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
