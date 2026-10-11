{{--
    The behaviour behind partials/mlat-menus: opening and closing the two
    menus, keyboard movement inside them, printing a sheet through a hidden
    frame, and MlatMenus.setLearner() for a profile that changes learner.

    Kept apart from the markup, as the documents panel keeps its script, so a
    page includes it with its other scripts at the end of the body rather than
    in the middle of the profile card.
--}}
<script>
window.MlatMenus = (() => {
    const lists = () => Array.from(document.querySelectorAll('[data-mlat-menus] .mlat-menu-list'));
    const toggleOf = (list) => document.getElementById(list.getAttribute('aria-labelledby'));
    const itemsOf = (list) => Array.from(list.querySelectorAll('[role="menuitem"]'));

    const close = (list, refocus = false) => {
        if (!list || list.hidden) return;
        list.hidden = true;
        const toggle = toggleOf(list);
        if (toggle) {
            toggle.setAttribute('aria-expanded', 'false');
            if (refocus) toggle.focus();
        }
    };
    const closeAll = (except = null) => lists().forEach((list) => { if (list !== except) close(list); });

    const open = (list, focusFirst = false) => {
        closeAll(list);
        list.hidden = false;
        toggleOf(list)?.setAttribute('aria-expanded', 'true');
        if (focusFirst) itemsOf(list)[0]?.focus();
    };

    // The sheet is printed from a hidden frame, so the profile — and the
    // learner the nurse is reading — stays where it is behind the dialog.
    const printInFrame = (url, toggle) => {
        document.getElementById('mlatPrintFrame')?.remove();

        const frame = document.createElement('iframe');
        frame.id = 'mlatPrintFrame';
        frame.title = 'MLAT print';
        frame.tabIndex = -1;
        frame.setAttribute('aria-hidden', 'true');
        frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;opacity:0;pointer-events:none';

        toggle?.classList.add('is-busy');
        toggle?.setAttribute('aria-busy', 'true');

        frame.addEventListener('load', () => {
            toggle?.classList.remove('is-busy');
            toggle?.removeAttribute('aria-busy');

            let doc = null;
            try { doc = frame.contentDocument; } catch (e) { doc = null; }

            // Not the sheet — a session that ran out, a record no longer on
            // file. Show what came back rather than print it.
            if (!doc || !doc.body || !doc.body.hasAttribute('data-mlat-sheet')) {
                frame.remove();
                window.open(url, '_blank', 'noopener');
                return;
            }

            frame.contentWindow.focus();
            frame.contentWindow.print();
        }, { once: true });

        frame.src = url;
        document.body.appendChild(frame);
    };

    document.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-mlat-menus] .mlat-menu-toggle');
        if (toggle) {
            const list = document.getElementById(toggle.getAttribute('aria-controls'));
            if (!list) return;
            // A click from the keyboard (detail 0) moves focus into the menu.
            list.hidden ? open(list, event.detail === 0) : close(list);
            return;
        }

        const item = event.target.closest('[data-mlat-menus] [role="menuitem"]');
        if (item) {
            const list = item.closest('.mlat-menu-list');
            close(list);
            if (item.hasAttribute('data-mlat-print')) {
                event.preventDefault();
                printInFrame(item.href, toggleOf(list));
            }
            // A download is an ordinary link: the file arrives, the page stays.
            return;
        }

        if (!event.target.closest('.mlat-menu-list')) closeAll();
    });

    // Captured, so Escape closes the menu and not the profile dialog the menu
    // sits in — that dialog listens for Escape on the document too.
    document.addEventListener('keydown', (event) => {
        const openList = lists().find((list) => !list.hidden);
        if (!openList) {
            if (event.key === 'ArrowDown') {
                const toggle = event.target.closest?.('[data-mlat-menus] .mlat-menu-toggle');
                const list = toggle && document.getElementById(toggle.getAttribute('aria-controls'));
                if (list) { event.preventDefault(); open(list, true); }
            }
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close(openList, true);
            return;
        }
        if (event.key === 'Tab') {
            close(openList);
            return;
        }

        const items = itemsOf(openList);
        const at = items.indexOf(document.activeElement);
        const step = { ArrowDown: 1, ArrowUp: -1 }[event.key];
        if (step) {
            event.preventDefault();
            items[(at + step + items.length) % items.length]?.focus();
        } else if (event.key === 'Home' || event.key === 'End') {
            event.preventDefault();
            items[event.key === 'Home' ? 0 : items.length - 1]?.focus();
        }
    }, true);

    /** Point a menu set at one learner; with no LRN there is no form to give, so they hide. */
    const setLearner = (root, lrn) => {
        if (!root) return;
        const key = String(lrn || '').trim();
        root.hidden = key === '';
        root.querySelectorAll('[data-mlat-template]').forEach((item) => {
            item.href = key === '' ? '#' : item.dataset.mlatTemplate.replace('__LRN__', encodeURIComponent(key));
        });
        root.querySelectorAll('.mlat-menu-list').forEach((list) => close(list));
    };

    return { setLearner };
})();
</script>
