{{--
    A date picker for feeding days: Saturdays and Sundays cannot be chosen.

    Nobody is fed on a weekend, so a weekend is not a session at all — and a
    native <input type="date"> cannot grey out a weekday, which left the
    coordinator one click from a Saturday that opens nothing but a notice.
    This upgrades any `input[type=date][data-weekday-picker]` in place:

    - The input stays in the form (hidden), so it still submits, its id and
      name are unchanged, and its own change listeners still fire — a pick
      sets its value and dispatches `change` exactly as the native control did.
    - A button carrying the input's own classes takes its place, so the
      control looks the same wherever it sits.
    - The month grid disables weekends and any day outside the input's
      min/max. They are disabled <button>s, so they cannot be clicked, tabbed
      to or chosen from the keyboard; the arrow keys step over them.
    - The panel is appended to <body> and positioned from the button, because
      the controls it serves sit inside boxes that clip their overflow and a
      page whose wrapper can carry a transform while it fades in.

    The server refuses a weekend on save regardless — a control is a
    convenience, never the guarantee. Without JavaScript the native input is
    simply left as it was.
--}}
@once
<style>
	.wdp-trigger {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		cursor: pointer;
		text-align: left;
		white-space: nowrap;
		font-variant-numeric: var(--lg-figures, tabular-nums);
		font-feature-settings: var(--lg-figure-features, normal);
	}
	.wdp-trigger svg { width: 15px; height: 15px; flex: 0 0 auto; color: var(--lg-emerald, #1F8A4C); }
	.wdp-trigger[aria-expanded="true"] { box-shadow: var(--lg-focus, 0 0 0 3px rgba(31, 138, 76, .28)); }

	.wdp-panel {
		position: fixed;
		z-index: 1200;
		width: 284px;
		padding: 12px;
		background: var(--lg-card, #fff);
		border: 1px solid var(--lg-border, #DCE8E0);
		border-radius: var(--lg-r-card, 12px);
		box-shadow: var(--lg-shadow-pop, 0 10px 26px rgba(31, 45, 37, .14));
		font-family: inherit;
		color: var(--lg-ink, #1F2D25);
	}
	.wdp-panel[hidden] { display: none; }
	.wdp-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
	.wdp-title { font-size: .86rem; font-weight: 700; }
	.wdp-nav {
		width: 30px;
		height: 30px;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		border: 1px solid var(--lg-border, #DCE8E0);
		border-radius: 8px;
		background: var(--lg-card, #fff);
		color: var(--lg-ink-soft, #6B7C72);
		font-size: 1rem;
		font-weight: 700;
		line-height: 1;
		cursor: pointer;
	}
	.wdp-nav:hover:not(:disabled) { background: var(--lg-mint, #E7F5EC); color: var(--lg-emerald-dark, #0E5730); }
	.wdp-nav:disabled { opacity: .35; cursor: default; }
	.wdp-nav:focus-visible, .wdp-day:focus-visible { outline: none; box-shadow: var(--lg-focus, 0 0 0 3px rgba(31, 138, 76, .28)); }

	.wdp-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 3px; }
	.wdp-dow { padding: 4px 0; text-align: center; font-size: .64rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--lg-ink-soft, #6B7C72); }
	.wdp-dow.is-weekend { opacity: .55; }
	.wdp-day {
		position: relative;
		height: 34px;
		border: 1px solid transparent;
		border-radius: 8px;
		background: transparent;
		color: var(--lg-ink, #1F2D25);
		font: inherit;
		font-size: .8rem;
		font-weight: 600;
		font-variant-numeric: var(--lg-figures, tabular-nums);
		cursor: pointer;
	}
	.wdp-day:hover:not(:disabled) { background: var(--lg-mint, #E7F5EC); color: var(--lg-emerald-dark, #0E5730); }
	.wdp-day.is-today { border-color: var(--lg-border-strong, #C7DCCE); }
	.wdp-day.is-selected, .wdp-day.is-selected:hover { background: var(--lg-emerald, #1F8A4C); color: #fff; }
	.wdp-day.is-blank { visibility: hidden; }
	/* Locked: a day that is not in the programme's window. */
	.wdp-day:disabled { color: var(--lg-border-strong, #C7DCCE); cursor: not-allowed; }
	/* Locked for a stronger reason: a weekend is never a feeding day. Hatched
	   rather than merely faded, so it reads as closed and not as "later". */
	.wdp-day.is-weekend:disabled {
		color: var(--lg-ink-soft, #6B7C72);
		opacity: .55;
		background: repeating-linear-gradient(135deg, var(--lg-rail, #EEF4F0) 0 4px, transparent 4px 8px);
		text-decoration: line-through;
	}
	@media (prefers-reduced-motion: no-preference) {
		.wdp-panel:not([hidden]) { animation: wdp-in .12s ease-out; }
		@keyframes wdp-in { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }
	}
	@media print { .wdp-panel { display: none !important; } }
</style>
<script>
(() => {
	const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
	const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
	const ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';

	const pad = (n) => String(n).padStart(2, '0');
	const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
	const parse = (value) => {
		const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
		return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : null;
	};
	const isWeekend = (d) => d.getDay() === 0 || d.getDay() === 6;
	const longLabel = (d) => d.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
	const shortLabel = (d) => d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });

	const upgrade = (input) => {
		if (input.dataset.wdpReady) return;
		input.dataset.wdpReady = '1';

		const min = input.min || '';
		const max = input.max || '';
		const selectable = (d) => !isWeekend(d) && (min === '' || iso(d) >= min) && (max === '' || iso(d) <= max);

		const trigger = document.createElement('button');
		trigger.type = 'button';
		trigger.className = `${input.className} wdp-trigger`;
		trigger.id = `${input.id || 'wdp'}Picker`;
		trigger.setAttribute('aria-haspopup', 'dialog');
		trigger.setAttribute('aria-expanded', 'false');
		const labelText = input.getAttribute('aria-label') || 'Feeding date';

		const paintTrigger = () => {
			const d = parse(input.value);
			trigger.innerHTML = `${ICON}<span>${d ? shortLabel(d) : 'Choose a date'}</span>`;
			trigger.setAttribute('aria-label', `${labelText}: ${d ? longLabel(d) : 'none chosen'}`);
		};
		paintTrigger();

		input.hidden = true;
		input.insertAdjacentElement('afterend', trigger);
		// A <label for> pointed at the native input now names the button.
		if (input.id) {
			document.querySelectorAll(`label[for="${input.id}"]`).forEach((label) => label.setAttribute('for', trigger.id));
		}

		const panel = document.createElement('div');
		panel.className = 'wdp-panel';
		panel.hidden = true;
		panel.setAttribute('role', 'dialog');
		panel.setAttribute('aria-label', labelText);
		document.body.appendChild(panel);

		let view = parse(input.value) || parse(max) || new Date();
		view = new Date(view.getFullYear(), view.getMonth(), 1);
		let focusDate = null;

		// A month can be visited when it holds at least one day in the window.
		const monthOpen = (year, month) => {
			const first = new Date(year, month, 1);
			const last = new Date(year, month + 1, 0);
			return (max === '' || iso(first) <= max) && (min === '' || iso(last) >= min);
		};

		const render = () => {
			const year = view.getFullYear();
			const month = view.getMonth();
			const selected = input.value;
			const today = iso(new Date());
			const lead = (new Date(year, month, 1).getDay() + 6) % 7; // Monday first
			const days = new Date(year, month + 1, 0).getDate();

			let cells = DAYS.map((name, i) => `<span class="wdp-dow ${i >= 5 ? 'is-weekend' : ''}" aria-hidden="true">${name}</span>`).join('');
			for (let i = 0; i < lead; i++) cells += '<span class="wdp-day is-blank" aria-hidden="true"></span>';
			for (let day = 1; day <= days; day++) {
				const d = new Date(year, month, day);
				const key = iso(d);
				const open = selectable(d);
				const classes = ['wdp-day', isWeekend(d) ? 'is-weekend' : '', key === today ? 'is-today' : '', key === selected ? 'is-selected' : ''].join(' ');
				const note = isWeekend(d) ? ' — no feeding on weekends' : (open ? '' : ' — outside the programme');
				cells += `<button type="button" class="${classes}" data-date="${key}" tabindex="-1" ${open ? '' : 'disabled'} aria-label="${longLabel(d)}${note}" ${key === selected ? 'aria-pressed="true"' : ''}>${day}</button>`;
			}

			panel.innerHTML = `
				<div class="wdp-head">
					<button type="button" class="wdp-nav" data-step="-1" aria-label="Previous month" ${monthOpen(year, month - 1) ? '' : 'disabled'}>&lsaquo;</button>
					<span class="wdp-title" aria-live="polite">${MONTHS[month]} ${year}</span>
					<button type="button" class="wdp-nav" data-step="1" aria-label="Next month" ${monthOpen(year, month + 1) ? '' : 'disabled'}>&rsaquo;</button>
				</div>
				<div class="wdp-grid">${cells}</div>`;

			// One day in the grid takes the tab stop: the chosen one, else the
			// first open day of the month.
			const target = panel.querySelector(`.wdp-day[data-date="${focusDate}"]:not(:disabled)`)
				|| panel.querySelector('.wdp-day.is-selected:not(:disabled)')
				|| panel.querySelector('.wdp-day[data-date]:not(:disabled)');
			if (target) target.tabIndex = 0;
			return target;
		};

		const place = () => {
			const r = trigger.getBoundingClientRect();
			const width = panel.offsetWidth || 284;
			const height = panel.offsetHeight || 320;
			let left = Math.min(r.left, window.innerWidth - width - 8);
			left = Math.max(8, left);
			let top = r.bottom + 6;
			if (top + height > window.innerHeight - 8 && r.top - height - 6 > 8) {
				top = r.top - height - 6;
			}
			panel.style.left = `${left}px`;
			panel.style.top = `${top}px`;
		};

		const close = (returnFocus) => {
			if (panel.hidden) return;
			panel.hidden = true;
			trigger.setAttribute('aria-expanded', 'false');
			if (returnFocus) trigger.focus();
		};

		const open = () => {
			const chosen = parse(input.value);
			view = chosen ? new Date(chosen.getFullYear(), chosen.getMonth(), 1) : view;
			focusDate = input.value || null;
			panel.hidden = false;
			trigger.setAttribute('aria-expanded', 'true');
			const target = render();
			place();
			if (target) target.focus();
		};

		const choose = (value) => {
			close(true);
			if (value === input.value) return;
			input.value = value;
			paintTrigger();
			input.dispatchEvent(new Event('change', { bubbles: true }));
		};

		// Step the keyboard focus by a number of days, skipping any day that
		// cannot be chosen, crossing into the next or previous month as needed.
		const moveFocus = (from, by) => {
			let d = parse(from);
			for (let guard = 0; guard < 62; guard++) {
				d = new Date(d.getFullYear(), d.getMonth(), d.getDate() + by);
				if ((min !== '' && iso(d) < min) || (max !== '' && iso(d) > max)) return;
				if (selectable(d)) break;
				if (Math.abs(by) === 7) by = by > 0 ? 1 : -1;
			}
			if (!selectable(d)) return;
			focusDate = iso(d);
			if (d.getMonth() !== view.getMonth() || d.getFullYear() !== view.getFullYear()) {
				view = new Date(d.getFullYear(), d.getMonth(), 1);
			}
			const target = render();
			if (target) target.focus();
		};

		trigger.addEventListener('click', () => (panel.hidden ? open() : close(false)));
		trigger.addEventListener('keydown', (event) => {
			if (event.key === 'ArrowDown' && panel.hidden) {
				event.preventDefault();
				open();
			}
		});

		panel.addEventListener('click', (event) => {
			const nav = event.target.closest('.wdp-nav');
			if (nav && !nav.disabled) {
				view = new Date(view.getFullYear(), view.getMonth() + Number(nav.dataset.step), 1);
				focusDate = null;
				render();
				place();
				return;
			}
			const day = event.target.closest('.wdp-day[data-date]');
			if (day && !day.disabled) choose(day.dataset.date);
		});

		panel.addEventListener('keydown', (event) => {
			if (event.key === 'Escape') {
				event.preventDefault();
				close(true);
				return;
			}
			const day = event.target.closest('.wdp-day[data-date]');
			if (!day) return;
			const steps = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
			if (steps[event.key] !== undefined) {
				event.preventDefault();
				moveFocus(day.dataset.date, steps[event.key]);
			} else if (event.key === 'Enter' || event.key === ' ') {
				event.preventDefault();
				if (!day.disabled) choose(day.dataset.date);
			}
		});

		document.addEventListener('mousedown', (event) => {
			if (!panel.hidden && !panel.contains(event.target) && !trigger.contains(event.target)) close(false);
		});
		document.addEventListener('focusin', (event) => {
			if (!panel.hidden && !panel.contains(event.target) && event.target !== trigger) close(false);
		});
		// The page scrolls inside its own content box, so a scroll moves the
		// button under a panel pinned to the viewport: the panel follows it.
		// It follows rather than closes because a phone fires scroll and resize
		// whenever its address bar shows or hides, which would snap the picker
		// shut under the coordinator's finger.
		const follow = () => {
			if (!panel.hidden) place();
		};
		window.addEventListener('scroll', follow, true);
		window.addEventListener('resize', follow);
	};

	const init = () => document.querySelectorAll('input[type="date"][data-weekday-picker]').forEach(upgrade);
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
</script>
@endonce
