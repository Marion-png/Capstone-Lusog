{{--
    Shared System Admin sidebar. Same panel as every other role's rail
    (resources/css/role-sidebar.css, .asb-*) so the colorway and layout match
    the School Head / Feeding Coordinator side. Pass $active to highlight the
    current item: 'dashboard' | 'audit'.

    The Control Center is one page, so its sections are reached by jump
    links rather than tabs of their own. From the Audit Trail those links
    carry the full route, so they land on the section from either page.

    The panel is a fixed width and never opens or closes on hover — a cursor
    crossing the rail must not move the page's content.
--}}
@php
    $active = $active ?? 'dashboard';
    $saName = trim((string) session('active_name', 'System Admin')) ?: 'System Admin';
    $saInitials = collect(preg_split('/\s+/', $saName))
        ->filter()
        ->map(fn ($part) => strtoupper(substr($part, 0, 1)))
        ->take(2)
        ->implode('');
    // On the Control Center a jump link is an in-page anchor; anywhere
    // else it has to carry the page as well.
    $saJump = fn (string $anchor) => $active === 'dashboard'
        ? '#'.$anchor
        : route('dashboard.system-admin').'#'.$anchor;
@endphp
<aside class="asb-sidebar">
    <div class="asb-logo">
        <img src="{{ asset('images/lusog-logo.png') }}" alt="LUSOG" class="asb-logo-full">
    </div>

    <nav class="asb-nav">
        <div class="asb-nav-label">Main Menu</div>
        <a href="{{ route('dashboard.system-admin') }}" class="asb-link {{ $active === 'dashboard' ? 'active' : '' }}" title="Control Center">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
            <span class="asb-link-text">Control Center</span>
        </a>

        <div class="asb-nav-label">Governance</div>
        <a href="{{ $saJump('requests') }}" class="asb-link" title="Account Requests">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
            <span class="asb-link-text">Account Requests</span>
        </a>
        <a href="{{ $saJump('accounts') }}" class="asb-link" title="Accounts">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <span class="asb-link-text">Accounts</span>
        </a>
        <a href="{{ $saJump('feeding-policy') }}" class="asb-link" title="Feeding Policy">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>
            <span class="asb-link-text">Feeding Policy</span>
        </a>

        <div class="asb-nav-label">Oversight</div>
        <a href="{{ route('dashboard.system-admin.audit-logs') }}" class="asb-link {{ $active === 'audit' ? 'active' : '' }}" title="Audit Trail">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>
            <span class="asb-link-text">Audit Trail</span>
        </a>
    </nav>

    <div class="asb-user">
        <div class="asb-avatar">{{ $saInitials ?: 'SA' }}</div>
        <div class="asb-user-meta">
            <div class="asb-user-name">{{ $saName }}</div>
            <div class="asb-user-role">Platform Control</div>
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
            // An in-page jump scrolls; it never leaves the page, so it must
            // not light up as a tab change.
            if (!href || href.startsWith('#') || link.classList.contains('active')) return;
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
            // Purely visual — the page's own transition handler owns navigation.
            link.classList.add('is-navigating');
        });
    });
})();
</script>
