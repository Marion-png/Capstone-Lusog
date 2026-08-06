{{--
    Shared Feeding Coordinator sidebar — same look as the Class Adviser sidebar
    (partials/adviser-sidebar.blade.php), collapsed to an icon rail until the
    cursor enters it. Pass $active to highlight the current item:
    'dashboard' | 'records' | 'program' | 'forms'.

    Every label sits in its own element so the collapse can fade text out
    without reflowing the icons. Requires resources/css/feedingcor-sidebar.css
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
        <a href="{{ route('dashboard.feedingcor-dashboard') }}" class="asb-link {{ $active === 'dashboard' ? 'active' : '' }}" title="Dashboard">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            <span class="asb-link-text">Dashboard</span>
        </a>
        <a href="{{ route('dashboard.feedingcor-health-records') }}" class="asb-link {{ $active === 'records' ? 'active' : '' }}" title="Student Health Records">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <span class="asb-link-text">Student Health Records</span>
        </a>
        <a href="{{ route('dashboard.feedingcor-program') }}" class="asb-link {{ $active === 'program' ? 'active' : '' }}" title="Feeding Program">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
            <span class="asb-link-text">Feeding Program</span>
        </a>
        <a href="{{ route('dashboard.feedingcor-sbfp-forms') }}" class="asb-link {{ $active === 'forms' ? 'active' : '' }}" title="SBFP Forms">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>
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
