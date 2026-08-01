{{--
    Shared Class Adviser topbar. Pass $breadcrumb for the current page label
    (e.g. "Dashboard", "Feeding Status"). Search submits into the My
    Students tab, filtered by name/LRN.
--}}
@php
    $breadcrumb = $breadcrumb ?? 'Dashboard';
    $asbHasAlert = \Illuminate\Support\Facades\Schema::hasTable('health_consent_forms')
        ? \App\Models\HealthConsentForm::where('adviser_unread', true)
            ->when(session('active_institution_id'), fn ($q, $id) => $q->where('institution_id', $id))
            ->exists()
        : false;
@endphp
<header class="asb-topbar">
    <div class="asb-crumb">
        {{-- The dashboard switches its tabs client-side, so these two carry ids
             for switchAdviserTab() to keep in step with the visible panel. --}}
        <div class="asb-crumb-title" id="asbCrumbTitle">{{ $breadcrumb }}</div>
        <div class="asb-crumb-path">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" style="width:12px;height:12px;"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
            <a href="{{ route('dashboard.class-adviser') }}">Home</a>
            <span>/</span>
            <span id="asbCrumbCurrent">{{ $breadcrumb }}</span>
        </div>
    </div>

    <form method="GET" action="{{ route('dashboard.class-adviser') }}" class="asb-search">
        <input type="hidden" name="tab" value="saved">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="search" name="q" placeholder="Search students..." value="{{ request('q') }}">
    </form>

    <div class="asb-topbar-right">
        @include('partials.live-clock')
        <a href="{{ route('dashboard.class-adviser') }}#needs-attention" class="asb-icon-btn" title="Notifications" aria-label="Notifications">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            @if ($asbHasAlert)<span class="asb-icon-dot"></span>@endif
        </a>
        <div class="asb-icon-btn asb-profile" title="{{ session('active_name', 'Class Adviser') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </div>
    </div>
</header>
