{{--
    Highlights every <pre class="fx-json"> on the page and wires the copy
    buttons: [data-copy-target] copies an element's text, [data-copy-text] a
    value it carries. The JSON is rendered server-side as escaped text, so the
    page is complete without this script; it only colours keys, strings,
    numbers and literals, working from textContent so nothing in the payload
    is ever interpreted as markup.

    A [data-pane] tab shows the [data-pane-id] pane it names and hides its
    siblings; without the script every pane stays visible.
--}}
<script>
(() => {
    const escape = (text) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const token = /("(?:\\u[a-fA-F0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(?:true|false|null)\b|-?\d+(?:\.\d+)?(?:[eE][+\-]?\d+)?)/g;

    document.querySelectorAll('pre.fx-json').forEach((pre) => {
        const text = pre.textContent || '';
        if (text.length > 400000) return;

        pre.innerHTML = escape(text).replace(token, (match, string, colon) => {
            if (string !== undefined) {
                return '<span class="' + (colon ? 'k' : 's') + '">' + match + '</span>';
            }
            return '<span class="' + (/^(true|false|null)$/.test(match) ? 'l' : 'n') + '">' + match + '</span>';
        });
    });

    // The Clipboard API needs a secure context; a plain-http local server
    // falls back to a selected, off-screen textarea.
    const copy = async (text) => {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return;
        }
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.top = '-1000px';
        document.body.appendChild(area);
        area.select();
        const ok = document.execCommand('copy');
        area.remove();
        if (!ok) throw new Error('copy failed');
    };

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-copy-target], [data-copy-text]');
        if (!button) return;

        let text = button.dataset.copyText;
        if (text === undefined) {
            const target = document.getElementById(button.dataset.copyTarget);
            if (!target) return;
            text = target.textContent || '';
        }

        if (!button.dataset.label) button.dataset.label = button.textContent.trim();
        clearTimeout(button._fxReset);
        try {
            await copy(text);
            button.textContent = 'Copied';
            button.classList.add('is-done');
        } catch (_) {
            button.textContent = 'Copy failed';
        }
        button._fxReset = setTimeout(() => {
            button.textContent = button.dataset.label;
            button.classList.remove('is-done');
        }, 1600);
    });

    document.querySelectorAll('[data-pane]').forEach((tab) => {
        tab.addEventListener('click', () => {
            const card = tab.closest('.table-card') || document;
            card.querySelectorAll('[data-pane]').forEach((other) => {
                const active = other === tab;
                other.classList.toggle('is-active', active);
                other.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            card.querySelectorAll('[data-pane-id]').forEach((pane) => {
                pane.classList.toggle('is-active', pane.dataset.paneId === tab.dataset.pane);
            });
            const copyButton = card.querySelector('[data-copy-pane]');
            if (copyButton) {
                copyButton.dataset.copyTarget = tab.dataset.pane;
                const target = document.getElementById(tab.dataset.pane);
                copyButton.hidden = !target || target.tagName !== 'PRE';
            }
        });
    });

    document.querySelectorAll('[data-copy-pane]').forEach((button) => {
        const target = document.getElementById(button.dataset.copyTarget);
        button.hidden = !target || target.tagName !== 'PRE';
    });
})();
</script>
