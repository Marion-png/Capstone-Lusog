{{--
    Shared Feeding Coordinator sidebar — same look as the Class Adviser sidebar
    (partials/adviser-sidebar.blade.php), collapsed to an icon rail until the
    cursor enters it. Pass $active to highlight the current item:
    'dashboard' | 'records' | 'attendance' | 'at-risk' | 'program' | 'forms'.

    Every label sits in its own element so the collapse can fade text out
    without reflowing the icons. Requires resources/css/role-sidebar.css (the
    shared panel stylesheet, also used by partials/schoolhead-sidebar)
    to be inlined in the page head.
--}}
@php
    $active = $active ?? 'dashboard';
    $sbName = trim((string) session('active_name', 'Feeding Coordinator')) ?: 'Feeding Coordinator';
    $sbInitials = collect(preg_split('/\s+/', $sbName))
        ->filter()
        ->map(fn ($part) => strtoupper(substr($part, 0, 1)))
        ->take(2)
        ->implode('');
@endphp
<aside class="asb-sidebar">
    <div class="asb-logo">
        {{-- The full LUSOG mark, same as the School Nurse rail, so every role
             opens on the same lockup. --}}
        <img src="{{ asset('images/lusog-logo.png') }}" alt="LUSOG" class="asb-logo-full">
    </div>

    <nav class="asb-nav">
        <div class="asb-nav-label">Main Menu</div>
        <a href="{{ route('dashboard.feedingcor-dashboard') }}" class="asb-link {{ $active === 'dashboard' ? 'active' : '' }}" title="Dashboard">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
            <span class="asb-link-text">Dashboard</span>
        </a>
        <a href="{{ route('dashboard.feedingcor-health-records') }}" class="asb-link {{ $active === 'records' ? 'active' : '' }}" title="Beneficiaries">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/><path d="M3.22 12H9.5l.5-1 2 4.5 2-7 1.5 3.5h5.27"/></svg>
            <span class="asb-link-text">Beneficiaries</span>
        </a>
        <a href="{{ route('dashboard.feedingcor-attendance') }}" class="asb-link {{ $active === 'attendance' ? 'active' : '' }}" title="Attendance">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/><path d="m9 16 2 2 4-4"/></svg>
            <span class="asb-link-text">Attendance</span>
        </a>
        <a href="{{ route('dashboard.feedingcor-at-risk') }}" class="asb-link {{ $active === 'at-risk' ? 'active' : '' }}" title="At-Risk Students">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
            <span class="asb-link-text">At-Risk Students</span>
        </a>
        {{-- No Feeding Program tab: the cycle and the session figures live on
             Attendance, the threshold list on At-Risk Students and the roll on
             Beneficiaries. A page repeating all three is how two screens start
             reporting different numbers for one programme. --}}
        <a href="{{ route('dashboard.feedingcor-sbfp-forms') }}" class="asb-link {{ $active === 'forms' ? 'active' : '' }}" title="SBFP Forms">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>
            <span class="asb-link-text">SBFP Forms</span>
        </a>
    </nav>

    <div class="asb-user">
        <div class="asb-avatar">{{ $sbInitials ?: 'FC' }}</div>
        <div class="asb-user-meta">
            <div class="asb-user-name">{{ $sbName }}</div>
            <div class="asb-user-role">Feeding Coordinator</div>
        </div>
        <form method="POST" action="{{ route('logout') }}" class="asb-logout-form">
            @csrf
            <button type="submit" class="asb-logout" title="Sign out" aria-label="Sign out">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </button>
        </form>
    </div>
</aside>
<script>
(() => {
    const sidebar = document.querySelector('.asb-sidebar');
    if (!sidebar) return;
    const still = window.matchMedia('(prefers-reduced-motion: reduce)');

    sidebar.querySelectorAll('.asb-link').forEach((link) => {
        link.addEventListener('pointerdown', (e) => {
            if (still.matches) return;
            const box = link.getBoundingClientRect();
            const size = Math.max(box.width, box.height);
            const ripple = document.createElement('span');
            ripple.className = 'asb-ripple';
            ripple.style.width = ripple.style.height = size + 'px';
            ripple.style.left = (e.clientX - box.left - size / 2) + 'px';
            ripple.style.top = (e.clientY - box.top - size / 2) + 'px';
            link.appendChild(ripple);
            ripple.addEventListener('animationend', () => ripple.remove());
        });

        link.addEventListener('click', (e) => {
            const href = link.getAttribute('href');
            if (!href || href === '#' || link.classList.contains('active')) return;
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
            // Purely visual — the page's own transition handler owns navigation.
            link.classList.add('is-navigating');
        });
    });
})();
</script>
