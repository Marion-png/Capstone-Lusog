{{--
    Shared floating toast for the adviser shell — reads the standard
    session('success')/session('error') flash keys so every adviser action
    (deworming request, health record save, etc.) gets the same bottom-right
    confirmation instead of each page rolling its own inline flash box.
--}}
@php
    $asbToastType = session('success') || session('health_assessment_success') ? 'success' : (session('error') ? 'error' : null);
    $asbToastMessage = session('success') ?? session('health_assessment_success') ?? session('error');
@endphp
@if ($asbToastType)
    <div class="asb-toast {{ $asbToastType }}" id="asbToast" role="status" aria-live="polite">
        <span class="asb-toast-icon" aria-hidden="true">
            @if ($asbToastType === 'success')
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            @else
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            @endif
        </span>
        <div class="asb-toast-content">
            <div class="asb-toast-title">{{ $asbToastType === 'success' ? 'Success' : 'Error' }}</div>
            <div class="asb-toast-message">{{ $asbToastMessage }}</div>
        </div>
        <button type="button" class="asb-toast-close" id="asbToastClose" aria-label="Close">&times;</button>
    </div>
    <script>
    (() => {
        const toast = document.getElementById('asbToast');
        if (!toast) {
            return;
        }
        requestAnimationFrame(() => toast.classList.add('show'));
        const dismiss = () => toast.classList.remove('show');
        document.getElementById('asbToastClose')?.addEventListener('click', dismiss);
        window.setTimeout(dismiss, 4000);
    })();
    </script>
@endif
