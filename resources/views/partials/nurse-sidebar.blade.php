{{--
    Shared School Nurse side menu. Pass $active to highlight the current item:
    'dashboard' | 'queue' | 'records' | 'consultations' | 'feeding' |
    'deworming' | 'consent' | 'assessments' | 'inventory' | 'dispensing' |
    'visualization' | 'reports' | 'settings'.

    Styles are self-contained and namespaced .nsb-* so they never collide with
    the .sidebar / .sb-* rules each nurse stylesheet still carries. The .main
    offset lives here too, since every nurse page sizes its shell from its own
    CSS.
--}}
@php
    $active = $active ?? 'dashboard';
    $nsbPendingCards = collect(session('school_health_card_records', []))
        ->filter(fn ($row) => empty($row['examination']))
        ->count();
@endphp
<style>
    .nsb {
        position: fixed; left: 0; top: 0; bottom: 0; width: 264px; z-index: 100;
        background: #0A2218; display: flex; flex-direction: column;
        overflow-y: auto; overflow-x: hidden;
    }
    .nsb::-webkit-scrollbar { width: 4px; }
    .nsb::-webkit-scrollbar-track { background: transparent; }
    .nsb::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: 4px; }

    .nsb-brand { display: flex; align-items: center; gap: 12px; padding: 24px 20px 20px; border-bottom: 1px solid rgba(255,255,255,.07); }
    .nsb-brand-icon {
        width: 38px; height: 38px; flex-shrink: 0; border-radius: 10px;
        background: linear-gradient(135deg, #2A6044, #3D7A57);
        display: grid; place-items: center; color: #fff;
        box-shadow: 0 2px 8px rgba(0,0,0,.3);
    }
    .nsb-brand-icon svg { width: 18px; height: 18px; }
    .nsb-brand-name { font-size: 17px; font-weight: 800; color: #fff; letter-spacing: 1.5px; line-height: 1.15; }
    .nsb-brand-sub { display: block; font-size: 10px; color: rgba(255,255,255,.4); letter-spacing: .3px; margin-top: 1px; }

    .nsb-nav { flex: 1; padding: 20px 12px 0; }
    .nsb-label { font-size: 9px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: rgba(255,255,255,.28); padding: 0 8px; margin: 20px 0 6px; }
    .nsb-label:first-child { margin-top: 0; }

    .nsb-item {
        position: relative; display: flex; align-items: center; gap: 10px; width: 100%;
        padding: 9px 10px; margin-bottom: 1px; border: none; border-radius: 8px;
        background: none; text-align: left; text-decoration: none; cursor: pointer;
        font-family: inherit; font-size: 13.5px; font-weight: 500;
        color: rgba(255,255,255,.58); transition: background .15s ease, color .15s ease;
    }
    .nsb-item:hover { background: rgba(255,255,255,.06); color: rgba(255,255,255,.9); }
    .nsb-item.active { background: rgba(93,160,122,.18); color: #7ECDA0; }
    .nsb-item.active::before {
        content: ''; position: absolute; left: -12px; top: 50%; transform: translateY(-50%);
        width: 3px; height: 20px; background: #5BA07A; border-radius: 0 3px 3px 0;
    }
    .nsb-item svg { width: 17px; height: 17px; flex-shrink: 0; opacity: .7; }
    .nsb-item.active svg { opacity: 1; }

    .nsb-badge {
        margin-left: auto; min-width: 20px; text-align: center;
        font-size: 10px; font-weight: 700; padding: 1px 7px; border-radius: 10px;
        background: rgba(255,255,255,.1); color: rgba(255,255,255,.7);
    }
    .nsb-badge.alert { background: rgba(192,57,43,.3); color: #F1948A; }

    .nsb-footer { margin-top: auto; padding: 14px 12px; border-top: 1px solid rgba(255,255,255,.06); }
    .nsb-user { display: flex; align-items: center; gap: 10px; padding: 10px; border-radius: 8px; background: rgba(255,255,255,.05); }
    .nsb-avatar {
        width: 34px; height: 34px; flex-shrink: 0; border-radius: 50%;
        background: linear-gradient(135deg, #2A6044, #3D7A57);
        display: grid; place-items: center;
        font-size: 12px; font-weight: 700; color: #fff; letter-spacing: .5px;
    }
    .nsb-user-meta { min-width: 0; }
    .nsb-user-name { font-size: 12.5px; font-weight: 600; color: rgba(255,255,255,.9); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .nsb-user-role { font-size: 10.5px; color: rgba(255,255,255,.38); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    /* Fixed 264px shell — overrides the collapsed/hover widths each nurse
       stylesheet sets for its own .sidebar. */
    .main { margin-left: 264px; }

    @media (max-width: 900px) {
        .nsb { display: none; }
        .main { margin-left: 0; }
    }
</style>

<aside class="nsb">
    <div class="nsb-brand">
        <div class="nsb-brand-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
        </div>
        <div>
            <div class="nsb-brand-name">LUSOG</div>
            <span class="nsb-brand-sub">DepEd Clinic System</span>
        </div>
    </div>

    <nav class="nsb-nav">
        <div class="nsb-label">Clinic</div>
        <a href="{{ route('dashboard.school-nurse') }}" class="nsb-item {{ $active === 'dashboard' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            <span>Dashboard</span>
        </a>
        <a href="{{ route('dashboard.student-health-records') }}" class="nsb-item {{ $active === 'records' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <span>Health Records</span>
            @if ($nsbPendingCards > 0)<span class="nsb-badge alert">{{ $nsbPendingCards }}</span>@endif
        </a>
        <a href="{{ route('nurse.index') }}" class="nsb-item {{ $active === 'queue' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg>
            <span>Review Queue</span>
        </a>
        <a href="{{ route('dashboard.consultation-log') }}" class="nsb-item {{ $active === 'consultations' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 12l2 2 4-4"/><path d="M21 12c0 4.97-4.03 9-9 9S3 16.97 3 12 7.03 3 12 3s9 4.03 9 9z"/></svg>
            <span>Consultation Log</span>
        </a>

        <div class="nsb-label">Health Programs</div>
        <a href="{{ route('dashboard.school-nurse.feeding-program') }}" class="nsb-item {{ $active === 'feeding' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
            <span>Feeding Program</span>
        </a>
        {{-- Deworming Program is deliberately not listed — see the note in
             partials/nurse-lusog-sidebar. The page itself is untouched. --}}
        <a href="{{ route('consent-forms.nurse-index') }}" class="nsb-item {{ $active === 'consent' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>
            <span>Consent Forms</span>
        </a>
        <a href="{{ route('health-assessments.nurse-index') }}" class="nsb-item {{ $active === 'assessments' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
            <span>Health Assessments</span>
        </a>

        <div class="nsb-label">Inventory</div>
        <a href="{{ route('dashboard.medicine-inventory') }}" class="nsb-item {{ $active === 'inventory' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="2" width="18" height="20" rx="2"/><path d="M9 2v4h6V2"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
            <span>Medicine Inventory</span>
        </a>
        <a href="#" class="nsb-item {{ $active === 'dispensing' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <span>Dispensing Log</span>
        </a>

        <div class="nsb-label">Reports</div>
        <a href="{{ route('dashboard.data-visualization') }}" class="nsb-item {{ $active === 'visualization' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            <span>Data Visualization</span>
        </a>
        <a href="#" class="nsb-item {{ $active === 'reports' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            <span>Generate Reports</span>
        </a>

        <div class="nsb-label">System</div>
        <a href="#" class="nsb-item {{ $active === 'settings' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            <span>Settings</span>
        </a>
        {{-- Signing out changes state, so it posts a CSRF-protected form
             rather than following a link. --}}
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="nsb-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                <span>Logout</span>
            </button>
        </form>
    </nav>

    <div class="nsb-footer">
        <div class="nsb-user">
            <div class="nsb-avatar">{{ strtoupper(substr(session('active_name', 'SN'), 0, 2)) }}</div>
            <div class="nsb-user-meta">
                <div class="nsb-user-name">{{ session('active_name', 'School Nurse') }}</div>
                <div class="nsb-user-role">{{ session('active_school_name', 'No school assigned') }}</div>
            </div>
        </div>
    </div>
</aside>
