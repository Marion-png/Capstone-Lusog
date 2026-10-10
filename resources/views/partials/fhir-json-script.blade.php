{{--
    Highlights every <pre class="fx-json"> on the page and wires its
    [data-copy-target] button. The JSON is rendered server-side as escaped
    text, so the page is complete without this script; it only colours keys,
    strings, numbers and literals, working from textContent so nothing in the
    payload is ever interpreted as markup.
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

    document.querySelectorAll('[data-copy-target]').forEach((button) => {
        button.addEventListener('click', async () => {
            const target = document.getElementById(button.dataset.copyTarget);
            if (!target) return;
            const label = button.textContent;
            try {
                await navigator.clipboard.writeText(target.textContent || '');
                button.textContent = 'Copied';
            } catch (_) {
                button.textContent = 'Copy failed';
            }
            setTimeout(() => { button.textContent = label; }, 1600);
        });
    });
})();
</script>
