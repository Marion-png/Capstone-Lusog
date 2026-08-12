{{--
    Shared Class Adviser sidebar. Used by every adviser-side page so the nav
    only exists in one place — pass $active to highlight the current item:
    'dashboard' | 'students' | 'consent' | 'feeding'.
    The enrolment form has no nav entry of its own — it opens from the Enroll
    Student button on My Students, so ?tab=form highlights 'students'.
--}}
@php
    $active = $active ?? 'dashboard';
    $cfUnread = \App\Support\SchemaCache::hasTable('health_consent_forms')
        ? \App\Models\HealthConsentForm::where('adviser_unread', true)
            ->when(session('active_institution_id'), fn ($q, $id) => $q->where('institution_id', $id))
            ->count()
        : 0;
@endphp
<aside class="asb-sidebar">
    <div class="asb-logo">
        {{-- The full LUSOG mark, same as the School Nurse rail, so every role
             opens on the same lockup. --}}
        <img src="{{ asset('images/lusog-logo.png') }}" alt="LUSOG" class="asb-logo-full">
    </div>

    <nav class="asb-nav">
        <div class="asb-nav-label">Main Menu</div>
        <a href="{{ route('dashboard.class-adviser') }}" class="asb-link {{ $active === 'dashboard' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 1 9 9h-9z"/></svg>
            Dashboard
        </a>
        <a href="{{ route('dashboard.class-adviser', ['tab' => 'saved']) }}" class="asb-link {{ $active === 'students' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            My Students
        </a>
        <a href="{{ route('consent-forms.index') }}" class="asb-link {{ $active === 'consent' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>
            Consent Forms
            @if ($cfUnread > 0)<span class="asb-badge">{{ $cfUnread }}</span>@endif
        </a>
        <a href="{{ route('dashboard.class-adviser.feeding-status') }}" class="asb-link {{ $active === 'feeding' ? 'active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
            Feeding Status
        </a>
        <div class="asb-nav-label">System</div>
        <a href="#" class="asb-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            Settings
        </a>
    </nav>

    <div class="asb-user">
        <div class="asb-avatar">{{ strtoupper(substr(session('active_name', 'CA'), 0, 2)) }}</div>
        <div class="asb-user-meta">
            <div class="asb-user-name">{{ session('active_name', 'Class Adviser') }}</div>
            <div class="asb-user-role">Class Adviser</div>
        </div>
        <form method="POST" action="{{ route('logout') }}" class="asb-logout-form">
            @csrf
            <button type="submit" class="asb-logout" title="Sign out" aria-label="Sign out">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </button>
        </form>
    </div>
</aside>
