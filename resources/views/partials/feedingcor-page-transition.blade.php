{{--
    Fade/slide the page content out when a sidebar tab is clicked and back in
    on the next page. Include only on coordinator pages that do not already
    carry their own .page-ready / .page-exit script (feed-dashboard and
    feed-program have theirs inline).
--}}
<script>
(() => {
	const main = document.querySelector('.main');
	if (!main) return;

	requestAnimationFrame(() => main.classList.add('page-ready'));
	window.addEventListener('pageshow', () => main.classList.add('page-ready'));

	document.querySelectorAll('.asb-link[href]').forEach((link) => {
		link.addEventListener('click', (event) => {
			const href = link.getAttribute('href');
			if (!href || href === '#' || link.classList.contains('active')) return;
			if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;

			event.preventDefault();
			main.classList.remove('page-ready');
			main.classList.add('page-exit');
			// Matches --asb-page-out in feedingcor-sidebar.css.
			window.setTimeout(() => { window.location.href = href; }, 340);
		});
	});
})();
</script>
