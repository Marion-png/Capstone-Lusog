{{--
    Keeps any screen current with what the other desks are writing.

    Nobody in this app works alone: a class adviser enrols a learner and files
    a consent form, the nurse examines them, the clinic dispenses, the
    coordinator marks a feeding day, the head reads all of it. None of that
    happens on the screen of the person who needs to see it, so "submitted"
    used to mean "submitted, and now go and tell them."

    Include it and nothing else — there is no argument to pass and no stamp to
    thread through a controller. It reads `App\Support\SchoolPulse` itself, and
    `ChangeStamp` memoizes per request, so a page that already computed the
    stamp does not pay for a second round trip.

    What it polls carries no personal information: a hash of row counts and
    last-touched times for this school. That is why `workspace.pulse` is exempt
    in `AuditSensitiveAccess` — the expensive, audited re-read happens only
    when the hash actually moves.

    **It never throws away typing.** A page is reloaded only when there is
    nothing on it to lose. If a dialog is open, a field is focused or any form
    holds an edit, the reload is replaced by a notice the reader can act on
    when they are ready — because a roster that refreshes itself under a
    half-written consultation is worse than a stale one. A page whose whole job
    is a form (Fill Medical Record, the adviser's entry form, the parent's
    consent letter) should simply not include this.

    Pages that re-render their own panels in place from a metrics endpoint —
    the School Head's and the Feeding Coordinator's dashboards — do not include
    it either: redrawing a panel is better than reloading a document.
--}}
@once
@php
    $wlInstitutionId = session('active_institution_id');
    $wlStamp = \App\Support\SchoolPulse::stamp($wlInstitutionId ? (int) $wlInstitutionId : null);
@endphp
<style>
    /* Only drawn when a reload has been held back, so it has to explain itself
       and offer the action. Teal: this is neutral information, not a warning —
       nothing is wrong and nothing has been lost. */
    .wl-notice {
        position: fixed;
        inset-inline-end: 18px;
        inset-block-end: 18px;
        z-index: 1200;
        display: none;
        align-items: center;
        gap: 12px;
        max-width: min(420px, calc(100vw - 36px));
        padding: 11px 13px;
        border: 1px solid var(--lg-info-line, #C6DEE5);
        border-radius: 10px;
        background: var(--lg-info-tint, #EAF4F7);
        color: var(--lg-info-ink, #1C5867);
        box-shadow: 0 4px 16px rgba(5, 46, 22, .12);
        font-size: .8rem;
        line-height: 1.45;
    }
    .wl-notice.is-open { display: flex; }
    .wl-notice-text { flex: 1 1 auto; }
    .wl-notice-reload {
        flex: 0 0 auto;
        padding: 6px 11px;
        border: 1px solid var(--lg-info-ink, #1C5867);
        border-radius: 7px;
        background: transparent;
        color: inherit;
        font: inherit;
        font-weight: 600;
        cursor: pointer;
    }
    .wl-notice-reload:hover { background: rgba(28, 88, 103, .1); }
    .wl-notice-dismiss {
        flex: 0 0 auto;
        padding: 4px 7px;
        border: 0;
        background: transparent;
        color: inherit;
        font: inherit;
        font-size: 1rem;
        line-height: 1;
        cursor: pointer;
        opacity: .65;
    }
    .wl-notice-dismiss:hover { opacity: 1; }
    @media print { .wl-notice { display: none !important; } }
</style>

<div class="wl-notice" id="wlNotice" role="status" aria-live="polite">
    <span class="wl-notice-text">Another desk has updated this school's records.</span>
    <button type="button" class="wl-notice-reload" id="wlNoticeReload">Reload</button>
    <button type="button" class="wl-notice-dismiss" id="wlNoticeDismiss" aria-label="Dismiss">&times;</button>
</div>

<script>
(() => {
    const PULSE_MS = 20000;
    const pulseUrl = @json(route('workspace.pulse'));
    let stamp = @json($wlStamp);

    const notice = document.getElementById('wlNotice');
    const showNotice = () => notice && notice.classList.add('is-open');

    document.getElementById('wlNoticeReload')?.addEventListener('click', () => window.location.reload());
    document.getElementById('wlNoticeDismiss')?.addEventListener('click', () => notice?.classList.remove('is-open'));

    // ── Is there anything on this page a reload would destroy? ──
    //
    // Three things count, and each is a real case on these screens: a dialog
    // somebody is filling in (New Consultation, Receive Stock, Add Medicine),
    // a field they are typing in right now, and a form carrying an edit they
    // have not submitted.

    const anOpenDialog = () => Array.from(document.querySelectorAll('[role="dialog"]'))
        // `hidden`, `display:none` and a zero box all mean "not on screen";
        // offsetParent is null for every one of them.
        .some((dialog) => dialog.offsetParent !== null || dialog.getClientRects().length > 0);

    const aFocusedField = () => {
        const el = document.activeElement;
        if (!el) return false;
        return el.matches('input:not([type="hidden"]), textarea, select, [contenteditable="true"]');
    };

    // Compared against each control's own default, which is what the server
    // rendered — so this is "changed since the page loaded", and a file input
    // with a chosen file counts (its value is set, its default never is).
    const aDirtyForm = () => Array.from(document.querySelectorAll('form')).some((form) =>
        Array.from(form.elements).some((el) => {
            if (el.disabled || el.type === 'hidden' || el.type === 'submit' || el.type === 'button') return false;
            if (el.type === 'checkbox' || el.type === 'radio') return el.checked !== el.defaultChecked;
            if (el.tagName === 'SELECT') {
                return Array.from(el.options).some((o) => o.selected !== o.defaultSelected);
            }
            return typeof el.defaultValue === 'string' && el.value !== el.defaultValue;
        })
    );

    const hasWorkInProgress = () => anOpenDialog() || aFocusedField() || aDirtyForm();

    const pulse = async () => {
        // A hidden tab is not being read, and a page mid-print must not be
        // pulled out from under the printer.
        if (document.hidden) return;

        try {
            const response = await fetch(pulseUrl, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) return;

            const payload = await response.json();
            if (!payload.stamp || payload.stamp === stamp) return;

            // Take the new stamp either way, so one change is acted on once.
            stamp = payload.stamp;

            if (hasWorkInProgress()) {
                showNotice();
                return;
            }

            window.location.reload();
        } catch (error) {
            // Offline, or a dropped request: the next pulse retries.
        }
    };

    window.setInterval(pulse, PULSE_MS);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) pulse();
    });
})();
</script>
@endonce
