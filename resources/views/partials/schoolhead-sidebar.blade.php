{{--
    Shared School Head sidebar. Same panel as every other role's rail
    (resources/css/role-sidebar.css, .asb-*) so the colorway and layout match
    the Class Adviser / Feeding Coordinator side. Pass $active to highlight the
    current item: 'dashboard' | 'reports'.

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
