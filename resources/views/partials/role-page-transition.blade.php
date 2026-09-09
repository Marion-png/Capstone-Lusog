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
			// The fade is feedback that the click landed, not something the
			// navigation waits on: the browser keeps painting this page (still
			// fading) until the next document commits, so the request is sent
			// now rather than a third of a second from now. Waiting for the
			// animation first added that delay to every single tab switch, on
			// top of however long the page itself took to come back.
			requestAnimationFrame(() => { window.location.href = href; });
		});
	});
})();
</script>
