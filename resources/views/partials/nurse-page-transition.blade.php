{{--
    Fade the nurse page in on load and back out when a rail tab is clicked.

    This is not decoration you can skip: css/nurse-sidebar.css starts
    `.sidebar ~ .main` at opacity 0 under `html.js`, so a page that never
    adds .page-ready renders blank. Every page using the nurse rail must
    include this (or carry its own equivalent, as feed-program does).
--}}
<script>
(() => {
	const main = document.querySelector('.main');
	if (!main) return;

	requestAnimationFrame(() => main.classList.add('page-ready'));
	// Back/forward out of the bfcache skips the load path, so re-assert it.
	window.addEventListener('pageshow', () => main.classList.add('page-ready'));

	document.querySelectorAll('.sb-link[href]').forEach((link) => {
		link.addEventListener('click', (event) => {
			const href = link.getAttribute('href');
			if (!href || href === '#' || link.classList.contains('active')) return;
			if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;

			event.preventDefault();
			main.classList.remove('page-ready');
			main.classList.add('page-exit');
			// Matches the .3s transition on `.sidebar ~ .main`, cut short so
			// the navigation does not feel held back by the animation.
			window.setTimeout(() => { window.location.href = href; }, 220);
		});
	});
})();
</script>
