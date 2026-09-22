{{--
    One hover/focus readout for every chart in the app.

    An HTML chart is interactive whether or not anybody designed it to be, so
    the choice is between a styled readout and the browser's native `title=`
    bubble — which arrives after about a second, cannot be styled, never
    appears for a keyboard, and is announced twice by a screen reader that has
    already read the label. This replaces it.

    Any mark carrying `data-tip` gets it: the value is read off the element, so
    the tooltip and the mark are one render and cannot disagree, exactly as the
    beneficiary dialogs read their own rows. `data-tip-title` is optional and
    sets the bold first line.

    Interaction rules it keeps (see the dataviz and UI/UX guidance):
    - It follows focus as well as hover, so a mark that is focusable for any
      reason is readable without a mouse. It does **not** hand out tabindex —
      see the note at the foot of the script for why, and for what carries the
      same numbers to a keyboard and a screen reader instead.
    - It is positioned in the viewport and flipped rather than clipped, so a
      bar at the right edge of a panel does not push a readout off screen.
    - `aria-hidden`: the tooltip repeats what the mark's own accessible name
      already says, so it is decoration for the pointer and never a second
      announcement.
    - It disappears on scroll and on Escape, and it is never drawn on paper.
    - Nothing here fetches anything.

    Include once per page that carries a chart; the markup and the listener are
    both `@once`, so including it from several partials costs one copy.
--}}
@once
	<style>
		.chart-tip {
			position: fixed;
			/* Above the dialog layer (250 across this app's modal sheets): a
			   tooltip is the topmost transient thing on screen, and a chart
			   inside a dialog — the At-Risk record opens one — would otherwise
			   hand out readouts nobody can see. */
			z-index: 260;
			max-width: 240px;
			padding: 8px 10px;
			border-radius: var(--lg-r-btn, 9px);
			background: var(--lg-ink, #1F2D25);
			color: #fff;
			font-size: .74rem;
			line-height: 1.45;
			box-shadow: var(--lg-shadow-pop, 0 10px 26px rgba(16, 32, 24, .24));
			pointer-events: none;
			opacity: 0;
			transform: translateY(2px);
			transition: opacity .12s ease, transform .12s ease;
		}
		.chart-tip.is-on { opacity: 1; transform: translateY(0); }
		.chart-tip-title {
			display: block;
			font-weight: 700;
			letter-spacing: .01em;
			margin-bottom: 1px;
		}
		.chart-tip-body {
			display: block;
			color: rgba(255, 255, 255, .82);
			font-variant-numeric: var(--lg-figures, tabular-nums);
			font-feature-settings: var(--lg-figure-features, normal);
		}

		/* The mark says it is readable. Colour is not the affordance — the
		   cursor and a focus ring are, because a fill that lightens on hover
		   is invisible to anybody who cannot separate the two shades. */
		[data-tip] { cursor: help; }
		[data-tip]:focus-visible {
			outline: none;
			box-shadow: var(--lg-focus, 0 0 0 3px rgba(31, 138, 76, .28));
		}

		@media (prefers-reduced-motion: reduce) {
			.chart-tip { transition: none; }
		}

		/* Paper has no pointer, and the table view under every chart already
		   prints each value as text. */
		@media print { .chart-tip { display: none !important; } }
	</style>

	<div class="chart-tip" id="chartTip" role="presentation" aria-hidden="true" hidden>
		<b class="chart-tip-title" data-tip-title></b>
		<span class="chart-tip-body" data-tip-body></span>
	</div>

	<script>
	(() => {
		const tip = document.getElementById('chartTip');
		if (!tip) return;

		const titleEl = tip.querySelector('[data-tip-title]');
		const bodyEl = tip.querySelector('[data-tip-body]');
		let current = null;

		const hide = () => {
			current = null;
			tip.classList.remove('is-on');
			tip.hidden = true;
		};

		const place = (mark) => {
			const r = mark.getBoundingClientRect();
			const t = tip.getBoundingClientRect();
			const gap = 10;

			// Above the mark by default, below it when there is no room —
			// a readout clipped by the top of the window is no readout.
			let top = r.top - t.height - gap;
			if (top < 8) top = r.bottom + gap;

			// Centred on the mark, then pulled inside whichever edge it
			// would otherwise cross.
			let left = r.left + (r.width / 2) - (t.width / 2);
			left = Math.max(8, Math.min(left, window.innerWidth - t.width - 8));

			tip.style.top = Math.round(top) + 'px';
			tip.style.left = Math.round(left) + 'px';
		};

		const show = (mark) => {
			const body = mark.getAttribute('data-tip');
			if (!body) return;

			current = mark;
			const heading = mark.getAttribute('data-tip-title') || '';

			titleEl.textContent = heading;
			titleEl.hidden = heading === '';
			bodyEl.textContent = body;

			// Laid out before it is measured, or the first placement of the
			// session reads a zero-sized box and lands in the corner.
			tip.hidden = false;
			place(mark);
			tip.classList.add('is-on');
		};

		// Delegated, so a chart re-rendered by a live refresh keeps working
		// without anything re-binding it — which is how these panels update.
		document.addEventListener('pointerover', (event) => {
			const mark = event.target.closest?.('[data-tip]');
			if (mark && mark !== current) show(mark);
		});

		document.addEventListener('pointerout', (event) => {
			const mark = event.target.closest?.('[data-tip]');
			if (mark && mark === current && !mark.contains(event.relatedTarget)) hide();
		});

		document.addEventListener('focusin', (event) => {
			const mark = event.target.closest?.('[data-tip]');
			if (mark) show(mark);
		});

		document.addEventListener('focusout', () => hide());

		document.addEventListener('keydown', (event) => {
			if (event.key === 'Escape') hide();
		});

		// A tooltip is positioned against the viewport, so anything that moves
		// the mark underneath it leaves the readout stranded.
		window.addEventListener('scroll', hide, { passive: true, capture: true });
		window.addEventListener('resize', hide, { passive: true });

		// Deliberately no tabindex is handed out here. Making every mark a tab
		// stop would put 120 of them in the School Head's feeding-day grid
		// alone, which is worse for a keyboard user than not reaching the
		// marks at all — and it would buy them nothing, because every chart in
		// this app already carries a table view or a direct label with the
		// same numbers in text. The tooltip is a pointer convenience; the
		// readable twin is the accessible path, and it is always present.
		// The focus handler above still fires for a mark made focusable for
		// some other reason, so nothing regresses if one ever is.
	})();
	</script>
@endonce
