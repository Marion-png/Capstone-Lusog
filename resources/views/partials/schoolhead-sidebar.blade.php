{{--
    Shared School Head sidebar. Same panel as every other role's rail
    (resources/css/role-sidebar.css, .asb-*) so the colorway and layout match
    the Class Adviser / Feeding Coordinator side. Pass $active to highlight the
    current item: 'dashboard' | 'health' | 'masterlist' | 'program' | 'consent'
    | 'inventory' | 'reports'.

    Grouped by the thing the head is accountable for rather than by the tab's
    machinery — clinic, learners, feeding, compliance, oversight — because the
    role's job is oversight across every programme, not one workflow in order.
    Nothing on the rail leads to an encoding screen: this role reads, decides
    and exports, and RestrictSchoolHeadWrites enforces that server-side.

    The panel is a fixed width and never opens or closes on hover — a cursor
    crossing the rail must not move the page's content.
--}}
@php
    $active = $active ?? 'dashboard';
    $shName = trim((string) session('active_name', 'School Head')) ?: 'School Head';
    $shInitials = collect(preg_split('/\s+/', $shName))
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
        <a href="{{ route('dashboard.school-head') }}" class="asb-link {{ $active === 'dashboard' ? 'active' : '' }}" title="Dashboard">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
            <span class="asb-link-text">Dashboard</span>
        </a>

        <div class="asb-nav-label">Health</div>
        <a href="{{ route('dashboard.school-head.health') }}" class="asb-link {{ $active === 'health' ? 'active' : '' }}" title="Health Overview">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
            <span class="asb-link-text">Health Overview</span>
        </a>

        <div class="asb-nav-label">Students</div>
        <a href="{{ route('dashboard.school-head.masterlist') }}" class="asb-link {{ $active === 'masterlist' ? 'active' : '' }}" title="Masterlist">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/></svg>
            <span class="asb-link-text">Masterlist</span>
        </a>

        <div class="asb-nav-label">Feeding Program</div>
        <a href="{{ route('dashboard.school-head.program') }}" class="asb-link {{ $active === 'program' ? 'active' : '' }}" title="Feeding Program">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/></svg>
            <span class="asb-link-text">Feeding Program</span>
        </a>

        <div class="asb-nav-label">Compliance</div>
        <a href="{{ route('dashboard.school-head.consent') }}" class="asb-link {{ $active === 'consent' ? 'active' : '' }}" title="Consent Compliance">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="m9 15 2 2 4-4"/></svg>
            <span class="asb-link-text">Consent Compliance</span>
        </a>
        <a href="{{ route('dashboard.school-head.inventory') }}" class="asb-link {{ $active === 'inventory' ? 'active' : '' }}" title="Medicine Inventory">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="m8.5 8.5 7 7"/></svg>
            <span class="asb-link-text">Medicine Inventory</span>
        </a>

        <div class="asb-nav-label">Oversight</div>
        <a href="{{ route('dashboard.school-head.reports') }}" class="asb-link {{ $active === 'reports' ? 'active' : '' }}" title="Reports">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="m19 9-5 5-4-4-3 3"/></svg>
            <span class="asb-link-text">Reports</span>
        </a>
    </nav>

    <div class="asb-user">
        <div class="asb-avatar">{{ $shInitials ?: 'SH' }}</div>
        <div class="asb-user-meta">
            <div class="asb-user-name">{{ $shName }}</div>
            <div class="asb-user-role">{{ session('active_school_name', 'No school assigned') }}</div>
        </div>
        {{-- Signing out changes state, so it posts a CSRF-protected form
             rather than following a link. --}}
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
