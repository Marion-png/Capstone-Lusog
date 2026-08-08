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
        <div class="asb-logo-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
        </div>
        <div class="asb-logo-text">
            <div class="asb-logo-title">LUSOG</div>
            <div class="asb-logo-sub">DepEd Clinic Management</div>
        </div>
    </div>

    <nav class="asb-nav">
        <div class="asb-nav-label">Main Menu</div>
        <a href="{{ route('dashboard.school-head') }}" class="asb-link {{ $active === 'dashboard' ? 'active' : '' }}" title="Dashboard">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            <span class="asb-link-text">Dashboard</span>
        </a>
        <a href="{{ route('dashboard.school-head.reports') }}" class="asb-link {{ $active === 'reports' ? 'active' : '' }}" title="Reports">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
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
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
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
