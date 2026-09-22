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
    /* Held on screen while it leaves. A dialog that vanishes on the frame
       the button is pressed reads as a glitch — the eye loses where it went,
       and on a blurred backdrop the whole page appears to jump. */
    .bmodal.open, .bmodal.is-closing { display: flex; }
    .bmodal { animation: bmodalBackdropIn .18s ease both; }
    .bmodal.is-closing { animation: bmodalBackdropOut .14s ease both; }

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
    /* Out is quicker than in and travels less. Arriving is worth watching;
       leaving is only worth following. */
    .bmodal.is-closing .bmodal-panel {
        animation: bmodalOut .14s cubic-bezier(.4, 0, 1, 1) both;
    }
    @keyframes bmodalIn {
        from { opacity: 0; transform: translateY(10px) scale(.99); }
        to   { opacity: 1; transform: none; }
    }
    @keyframes bmodalOut {
        from { opacity: 1; transform: none; }
        to   { opacity: 0; transform: translateY(6px) scale(.99); }
    }
    @keyframes bmodalBackdropIn  { from { opacity: 0; } to { opacity: 1; } }
    @keyframes bmodalBackdropOut { from { opacity: 1; } to { opacity: 0; } }

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
    /* The panel caps its height and the body scrolls inside it — but every
       dialog wraps head, body and foot in a <form>, and a form is a plain
       block: it took the body's full height, the panel clipped the rest,
       and a form taller than the screen (the consultation, with its notes
       and photos) could not be scrolled to its Save. The form is a column
       like the panel, and the body is the part that gives. */
    .bmodal-panel > form { display: flex; flex-direction: column; min-height: 0; max-height: inherit; }
    .bmodal-body { padding: 16px 18px; overflow-y: auto; flex: 1 1 auto; min-height: 0; }
    .bmodal-head, .bmodal-foot { flex: 0 0 auto; }
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

    /* A field the dialog filled in from a record. Read-only, not disabled:
       a disabled input posts nothing, so the value would vanish on save. */
    .bmodal-body input[readonly] {
        background: #eef3f0;
        color: #3E5348;
        border-color: #DCE8E0;
        cursor: default;
    }
    .bmodal-body input[readonly]:focus { border-color: #DCE8E0; box-shadow: none; }
    .bmodal-note {
        display: none;
        align-items: center;
        gap: 5px;
        margin-top: 5px;
        font-size: .72rem;
        color: #6B7C72;
    }
    .bmodal-note svg { width: 12px; height: 12px; flex: 0 0 auto; }

    /* Condition search — type to find, not a list to scroll.
       The results hang over the fields below rather than pushing them down,
       so the dialog does not resize under the nurse's hands while typing. */
    .cmcombo { position: relative; display: flex; align-items: center; }
    .cmcombo > svg {
        position: absolute;
        left: 11px;
        width: 15px;
        height: 15px;
        color: #6B7C72;
        pointer-events: none;
    }
    .cmcombo input[type="text"] { padding-left: 33px; }
    .cmcombo-list {
        display: none;
        position: absolute;
        left: 0;
        right: 0;
        top: calc(100% + 5px);
        z-index: 20;
        max-height: 260px;
        overflow-y: auto;
        background: #fff;
        border: 1px solid #C7DCCE;
        border-radius: 10px;
        box-shadow: 0 14px 34px rgba(16, 32, 24, .16);
        padding: 4px;
    }
    .cmcombo-list.show { display: block; }
    .cmcombo-row {
        display: flex;
        align-items: baseline;
        gap: 9px;
        width: 100%;
        text-align: left;
        border: none;
        background: none;
        font: inherit;
        cursor: pointer;
        padding: 8px 10px;
        border-radius: 7px;
    }
    .cmcombo-row:hover, .cmcombo-row:focus-visible { background: #E7F5EC; outline: none; }
    .cmcombo-name { font-size: .84rem; font-weight: 600; color: #1F2D25; }
    /* The category is context, not the answer — it never competes with the name. */
    .cmcombo-cat { margin-left: auto; font-size: .7rem; color: #6B7C72; }
    .cmcombo-empty { padding: 13px 11px; text-align: center; font-size: .8rem; color: #6B7C72; }
    /* "Others" is pinned to the bottom of the list, always. The rule marks it
       as the way out rather than one more match — and it sits in the same
       place every time, so the nurse is not chasing it up and down the list
       as the results change. */
    .cmcombo-row-other {
        margin-top: 4px;
        border-top: 1px solid #DCE8E0;
        border-radius: 0 0 7px 7px;
        padding-top: 10px;
    }
    .cmcombo-row-other .cmcombo-name { color: #126B3A; }
    .cmcombo-row-other .cmcombo-cat { font-style: italic; }
    .bmodal-field.is-locked .bmodal-note { display: flex; }

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
        .bmodal, .bmodal-panel, .bmodal.is-closing, .bmodal.is-closing .bmodal-panel {
            animation: none;
        }
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

    // Somebody who has asked their system for less motion gets none: the
    // dialog simply goes.
    const stillMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    const open = (modal) => {
        if (!modal) return;
        lastFocused = document.activeElement;
        // Re-opening mid-exit: cancel the leave rather than letting the two
        // animations fight over the same element.
        modal.classList.remove('is-closing');
        modal.classList.add('open');
        lockBody();
        // Focus the first real field so the dialog is usable from the
        // keyboard the moment it appears.
        const first = modal.querySelector('input:not([type=hidden]), textarea, select');
        if (first) first.focus();
    };

    const close = (modal) => {
        // Already gone, or already leaving — Escape and a backdrop click can
        // both land before the animation ends.
        if (!modal || !modal.classList.contains('open')) return;
        if (modal.classList.contains('is-closing')) return;

        const finish = () => {
            modal.classList.remove('open', 'is-closing');
            unlockBody();
            // Send focus back where it came from, not to the top of the page.
            if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
        };

        if (stillMotion.matches) { finish(); return; }

        // The dialog keeps `open` while it leaves, so the body stays locked
        // and the page behind cannot scroll under a half-faded panel.
        modal.classList.add('is-closing');

        let done = false;
        const settle = () => { if (!done) { done = true; finish(); } };

        const panel = modal.querySelector('.bmodal-panel');
        (panel || modal).addEventListener('animationend', settle, { once: true });

        // A backstop. animationend never fires on a hidden tab or an element
        // whose animation was interrupted, and a dialog stuck half-closed
        // would block the whole page.
        setTimeout(settle, 300);
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