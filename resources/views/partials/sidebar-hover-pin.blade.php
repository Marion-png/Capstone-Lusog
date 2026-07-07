{{--
    Keeps the sidebar expanded across page navigations.

    Browsers do not re-apply :hover after a page load until the mouse moves,
    so navigating via a sidebar link made the sidebar collapse even though
    the cursor never left it. When a sidebar link is clicked we set a flag;
    the next page pins the sidebar open (.sb-pin mirrors the :hover styles)
    with animations suppressed for the first paint, then hands control back
    to real :hover on the first mouse movement.
--}}
<style>
    .sidebar.sb-no-anim, .sidebar.sb-no-anim *, .sidebar.sb-no-anim ~ .main { transition: none !important; }
</style>
<script>
(function () {
    var sb = document.querySelector('.sidebar');
    if (!sb || !window.sessionStorage) return;

    try {
        if (sessionStorage.getItem('sbPin') === '1') {
            sessionStorage.removeItem('sbPin');
            sb.classList.add('sb-pin', 'sb-no-anim');
            requestAnimationFrame(function () {
                requestAnimationFrame(function () { sb.classList.remove('sb-no-anim'); });
            });
            document.addEventListener('mousemove', function unpin() {
                sb.classList.remove('sb-pin');
                document.removeEventListener('mousemove', unpin);
            });
        }

        sb.addEventListener('click', function (e) {
            if (e.target.closest('a')) sessionStorage.setItem('sbPin', '1');
        });
    } catch (err) { /* sessionStorage unavailable (e.g. blocked) — degrade to default behavior */ }
})();
</script>
