{{--
    Shared dialog styling and behaviour for the dashboard boards
    (partials/announcements and partials/upcoming-events).

    Included by both, but guarded so it only ever emits once even when both
    boards are on the same dashboard — which they are on six of the seven.

    Class names are namespaced .bmodal-* rather than the design system's
    .modal-*: feed-dashboard.css and feeding-program.css already define
    .modal-panel / .modal-head / .modal-body for the Feeding Coordinator's
    own dialogs, and the announcements board is included on that very page.
    The anatomy (backdrop / panel / head / body / foot) matches the LUSOG
    modal so a dialog still looks the same product-wide.
--}}
@once
<style>
    /* Backdrop: dim AND blur, so the dashboard behind reads as out of
       reach rather than merely tinted. -webkit- prefix for Safari. */
    .bmodal {
        position: fixed;
        inset: 0;
        z-index: 900;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 24px;
        background: rgba(15, 32, 24, .46);
        -webkit-backdrop-filter: blur(6px);
        backdrop-filter: blur(6px);
    }
    .bmodal.open { display: flex; }

    .bmodal-panel {
        width: 100%;
        max-width: 520px;
        max-height: calc(100vh - 48px);
        display: flex;
        flex-direction: column;
        background: #fff;
        border: 1px solid #DCE8E0;
        border-radius: 14px;
        box-shadow: 0 18px 48px rgba(15, 32, 24, .26);
        overflow: hidden;
        animation: bmodalIn .18s cubic-bezier(.22, .61, .36, 1);
    }
    @keyframes bmodalIn {
        from { opacity: 0; transform: translateY(10px) scale(.99); }
        to   { opacity: 1; transform: none; }
    }

    .bmodal-head {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 16px 18px;
        border-bottom: 1px solid #DCE8E0;
    }
    .bmodal-eyebrow { font-size: .64rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: #1F8A4C; }
    .bmodal-title { font-size: 1rem; font-weight: 700; color: #1F2D25; margin-top: 3px; }
    .bmodal-sub { font-size: .74rem; color: #6B7C72; margin-top: 3px; }
    .bmodal-close {
        margin-left: auto;
        flex: 0 0 auto;
        width: 30px;
        height: 30px;
        display: grid;
        place-items: center;
        border: none;
        border-radius: 8px;
        background: #eef3f0;
        color: #3E5348;
        cursor: pointer;
        font-family: inherit;
    }
    .bmodal-close:hover { background: #E7F5EC; color: #126B3A; }
    .bmodal-close svg { width: 15px; height: 15px; }

    /* The body scrolls, not the page behind it. */
    .bmodal-body { padding: 16px 18px; overflow-y: auto; }
    .bmodal-body label { display: block; font-size: .7rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #6B7C72; margin-bottom: 5px; }
    .bmodal-body input, .bmodal-body textarea, .bmodal-body select {
        width: 100%;
        border: 1px solid #d1dbd5;
        border-radius: 8px;
        padding: 9px 11px;
        font-family: inherit;
        font-size: .84rem;
        color: #1d3c31;
        background: #fff;
        box-sizing: border-box;
    }
    .bmodal-body input:focus, .bmodal-body textarea:focus, .bmodal-body select:focus {
        outline: none;
        border-color: #1F8A4C;
        box-shadow: 0 0 0 3px rgba(31, 138, 76, .2);
    }
    .bmodal-body textarea { min-height: 96px; resize: vertical; }
    .bmodal-field { margin-bottom: 13px; }
    .bmodal-field:last-child { margin-bottom: 0; }
    .bmodal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .bmodal-error { color: #b91c1c; font-size: .74rem; margin-top: 5px; }

    .bmodal-foot {
        display: flex;
        justify-content: flex-end;
        gap: 9px;
        padding: 13px 18px;
        border-top: 1px solid #DCE8E0;
        background: #f7faf8;
    }
    .bmodal-btn { border: none; border-radius: 8px; padding: 9px 16px; font-size: .8rem; font-weight: 600; cursor: pointer; font-family: inherit; }
    .bmodal-btn-primary { background: #1F8A4C; color: #fff; }
    .bmodal-btn-primary:hover { background: #126B3A; }
    .bmodal-btn-ghost { background: #eef3f0; color: #3E5348; }
    .bmodal-btn-ghost:hover { background: #e2eae5; }

    /* While a dialog is open the page behind must not scroll under it. */
    body.bmodal-open { overflow: hidden; }

    @media (max-width: 560px) {
        .bmodal { padding: 12px; }
        .bmodal-grid { grid-template-columns: 1fr; }
    }
    @media (prefers-reduced-motion: reduce) {
        .bmodal-panel { animation: none; }
    }
</style>

<script>
// One controller for every dashboard dialog. A dialog is any .bmodal with
// an id; anything with data-bmodal-open="<id>" opens it, anything with
// data-bmodal-close inside it closes it.
(() => {
    const lockBody = () => document.body.classList.add('bmodal-open');
    const unlockBody = () => {
        if (!document.querySelector('.bmodal.open')) {
            document.body.classList.remove('bmodal-open');
        }
    };

    let lastFocused = null;

    const open = (modal) => {
        if (!modal) return;
        lastFocused = document.activeElement;
        modal.classList.add('open');
        lockBody();
        // Focus the first real field so the dialog is usable from the
        // keyboard the moment it appears.
        const first = modal.querySelector('input:not([type=hidden]), textarea, select');
        if (first) first.focus();
    };

    const close = (modal) => {
        if (!modal) return;
        modal.classList.remove('open');
        unlockBody();
        // Send focus back where it came from, not to the top of the page.
        if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
    };

    // Delegated from document on purpose. This block is emitted by whichever
    // board renders first, which is BEFORE that board's own dialog markup and
    // before the second board exists at all — so binding to elements directly
    // would wire up nothing but the first trigger.
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-bmodal-open]');
        if (trigger) {
            event.preventDefault();
            open(document.getElementById(trigger.getAttribute('data-bmodal-open')));

            return;
        }

        const closer = event.target.closest('[data-bmodal-close]');
        if (closer) {
            event.preventDefault();
            close(closer.closest('.bmodal'));
        }
    });

    // Clicking the blurred backdrop dismisses; clicking the panel does not.
    document.addEventListener('mousedown', (event) => {
        if (event.target.classList && event.target.classList.contains('bmodal')) {
            close(event.target);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        const openModal = document.querySelector('.bmodal.open');
        if (openModal) close(openModal);
    });

    // A dialog whose submission failed validation re-opens itself, so the
    // messages are not stranded behind a closed panel. Deferred until the
    // document is parsed, for the same ordering reason as above.
    const autoOpen = () => document.querySelectorAll('.bmodal[data-bmodal-autoopen]').forEach(open);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoOpen);
    } else {
        autoOpen();
    }
})();
</script>
@endonce