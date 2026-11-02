{{-- ── One feeding day, as it happened ───────────────────────────────────
     Opened from a day on the Calendar. It answers the question a tick or a
     half-moon on the grid raises and cannot answer: who came, who did not and
     why, who was excused, and who nobody wrote down.

     It is a reading, never a form. Marks are entered in the Record Attendance
     dialog on the session's own day; this one renders no radio, no remark
     field and no endpoint. Its one way onward is the day's own sheet.

     Everything it prints comes from the `data-calendar-detail` payload the
     calendar pane carries — the same marks the grid's ticks were counted
     from — so the dialog and the cell that opened it cannot disagree, and the
     live refresh that redraws the month replaces both together. A learner on
     the roll that a recorded day does not name is "Not marked": a filing gap,
     never an absence.

     The figures are also the filter: pressing Absent lists the absences.
     Anatomy is the shared one (`.modal-backdrop` / `.modal-panel` / `-head` /
     `-body` / `-foot`), so a dialog looks the same product-wide. --}}
<div class="modal-backdrop ad-backdrop" id="dayBackdrop">
	<div class="modal-panel ad-modal" role="dialog" aria-modal="true" aria-labelledby="dayTitle">
		<header class="modal-head">
			<div>
				<p class="modal-eyebrow" data-day-eyebrow>Feeding Day</p>
				<h2 class="modal-title" id="dayTitle" data-day-title></h2>
				<p class="modal-sub" data-day-sub></p>
			</div>
			<button type="button" class="modal-close" data-day-close aria-label="Close">&times;</button>
		</header>

		<div class="ad-figures" role="group" aria-label="Show learners by attendance">
			@foreach ([
				'all' => 'All',
				'present' => 'Present',
				'absent' => 'Absent',
				'excused' => 'Excused',
				'unmarked' => 'Not marked',
			] as $key => $label)
				<button type="button" class="ad-figure is-{{ $key }}" data-day-filter="{{ $key }}" aria-pressed="{{ $key === 'all' ? 'true' : 'false' }}">
					<span class="ad-figure-label">{{ $label }}</span>
					<span class="ad-figure-value" data-day-figure="{{ $key }}">0</span>
				</button>
			@endforeach
			<div class="ad-figure is-rate" aria-live="polite">
				<span class="ad-figure-label">Rate</span>
				<span class="ad-figure-value" data-day-figure="rate">&mdash;</span>
			</div>
		</div>

		<div class="modal-body ad-body">
			<p class="ad-empty" data-day-empty hidden></p>

			<div class="ad-tools" data-day-tools>
				<div class="lg-search ad-search">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
					<input type="search" data-day-search placeholder="Search a learner" autocomplete="off" aria-label="Search this day's learners by name or section">
				</div>
			</div>

			<div class="table-scroll ad-scroll" data-day-tablewrap>
				<table class="al-table ad-table">
					<thead>
						<tr>
							<th>Student</th>
							<th>Grade &amp; Section</th>
							<th>Attendance</th>
							<th>Remarks</th>
						</tr>
					</thead>
					<tbody data-day-rows></tbody>
				</table>
				<p class="table-empty" data-day-nomatch hidden>No learner matches.</p>
			</div>
		</div>

		<footer class="modal-foot">
			<span class="ad-foot-note" data-day-note></span>
			<div class="modal-actions">
				<button type="button" class="btn btn-ghost" data-day-close>Close</button>
				<a class="btn btn-secondary" data-day-sheet href="#" hidden>Open Attendance Sheet</a>
			</div>
		</footer>
	</div>
</div>
<script>
(() => {
	const backdrop = document.getElementById('dayBackdrop');
	if (!backdrop) {
		return;
	}

	const el = (selector) => backdrop.querySelector(selector);
	const eyebrow = el('[data-day-eyebrow]');
	const title = el('[data-day-title]');
	const sub = el('[data-day-sub]');
	const empty = el('[data-day-empty]');
	const tools = el('[data-day-tools]');
	const tableWrap = el('[data-day-tablewrap]');
	const body = el('[data-day-rows]');
	const noMatch = el('[data-day-nomatch]');
	const search = el('[data-day-search]');
	const sheetLink = el('[data-day-sheet]');
	const note = el('[data-day-note]');
	const filters = Array.from(backdrop.querySelectorAll('[data-day-filter]'));
	const figure = (key) => el(`[data-day-figure="${key}"]`);

	// The same badges the sheet and the learner dialog print, so a mark looks
	// like itself wherever it is read. Listed in the order a day is read.
	const MARKS = {
		present: ['badge-normal', 'Present'],
		absent: ['badge-critical', 'Absent'],
		excused: ['badge-monitor', 'Excused'],
		unconfirmed: ['badge-neutral', 'Unconfirmed'],
		unmarked: ['', 'Not marked'],
	};
	const ORDER = Object.keys(MARKS);

	let rows = [];
	let filter = 'all';
	let opener = null;

	const readDetail = () => {
		const node = document.querySelector('[data-calendar-detail]');
		if (!node) return null;
		try {
			return JSON.parse(node.textContent);
		} catch (error) {
			return null;
		}
	};

	const render = () => {
		const term = (search.value || '').trim().toLowerCase();
		body.textContent = '';
		let shown = 0;

		rows.forEach((row) => {
			if (filter !== 'all' && row.state !== filter) return;
			if (term !== '' && !row.search.includes(term)) return;

			const tr = document.createElement('tr');

			const name = document.createElement('td');
			const strong = document.createElement('strong');
			strong.textContent = row.name;
			name.appendChild(strong);
			tr.appendChild(name);

			const place = document.createElement('td');
			place.className = 'ad-place';
			place.textContent = [row.grade ? 'Grade ' + row.grade : '', row.section].filter(Boolean).join(' · ') || '—';
			tr.appendChild(place);

			const state = document.createElement('td');
			const [badge, label] = MARKS[row.state];
			const chip = document.createElement('span');
			if (badge) {
				chip.className = 'badge ' + badge;
			} else {
				// A day no sheet covered this learner on: a filing gap, never
				// an absence.
				chip.className = 'fa-unmarked';
			}
			chip.textContent = label;
			state.appendChild(chip);
			tr.appendChild(state);

			const remark = document.createElement('td');
			remark.className = 'al-remark';
			remark.textContent = row.remarks || '—';
			tr.appendChild(remark);

			body.appendChild(tr);
			shown++;
		});

		noMatch.hidden = shown > 0 || rows.length === 0;
	};

	const setFilter = (key) => {
		filter = key;
		filters.forEach((button) => button.setAttribute('aria-pressed', button.dataset.dayFilter === key ? 'true' : 'false'));
		render();
	};

	const open = (button) => {
		const detail = readDetail();
		const date = button.dataset.dayOpen;
		const day = detail && detail.days ? detail.days[date] : null;
		if (!day) return;

		const roster = detail.roster || {};
		const named = new Set();
		rows = [];

		const add = (id, state, remarks) => {
			const learner = roster[id];
			if (!learner) return;
			named.add(String(id));
			rows.push({
				name: learner.n,
				grade: learner.g,
				section: learner.s,
				state,
				remarks: remarks || '',
				search: (learner.n + ' ' + learner.g + ' ' + learner.s).toLowerCase(),
			});
		};

		(day.present || []).forEach((id) => add(id, 'present'));
		(day.absent || []).forEach(([id, remarks]) => add(id, 'absent', remarks));
		(day.excused || []).forEach(([id, remarks]) => add(id, 'excused', remarks));
		(day.unconfirmed || []).forEach((id) => add(id, 'unconfirmed'));

		const recorded = rows.length;

		// Everybody on the roll the day does not name — but only on a day
		// that was recorded at all. On a day nobody recorded, ninety rows of
		// "Not marked" would bury the one thing worth saying.
		if (recorded > 0) {
			Object.keys(roster).forEach((id) => {
				if (!named.has(id)) add(id, 'unmarked');
			});
		}

		rows.sort((a, b) => (ORDER.indexOf(a.state) - ORDER.indexOf(b.state)) || a.name.localeCompare(b.name));

		const count = (state) => rows.filter((row) => row.state === state).length;
		const present = count('present');
		const absent = count('absent');
		const expected = Object.keys(roster).length;

		eyebrow.textContent = day.feeding_day ? 'Feeding Day ' + day.feeding_day : 'Feeding Day';
		title.textContent = day.label;
		sub.textContent = recorded > 0
			? recorded + ' of ' + expected + ' ' + (expected === 1 ? 'beneficiary' : 'beneficiaries') + ' marked'
			: expected + ' ' + (expected === 1 ? 'beneficiary' : 'beneficiaries') + ' on the roll';

		figure('all').textContent = recorded > 0 ? String(rows.length) : '0';
		figure('present').textContent = String(present);
		figure('absent').textContent = String(absent);
		figure('excused').textContent = String(count('excused'));
		figure('unmarked').textContent = recorded > 0 ? String(count('unmarked')) : '—';
		// Present over confirmed marks: an excused absence and an unread scan
		// are in neither half, exactly as Attendance History counts a day.
		figure('rate').textContent = (present + absent) > 0 ? Math.round((present / (present + absent)) * 100) + '%' : '—';

		const unconfirmed = count('unconfirmed');
		note.textContent = unconfirmed > 0
			? unconfirmed + ' scanned ' + (unconfirmed === 1 ? 'mark is' : 'marks are') + ' still awaiting review'
			: '';

		empty.hidden = recorded > 0;
		empty.textContent = recorded > 0 ? '' : 'No attendance was recorded on this day.';
		tools.hidden = recorded === 0;
		tableWrap.hidden = recorded === 0;
		filters.forEach((button) => { button.disabled = recorded === 0; });

		if (button.dataset.sheetUrl) {
			sheetLink.href = button.dataset.sheetUrl;
			sheetLink.hidden = false;
		} else {
			sheetLink.hidden = true;
			sheetLink.removeAttribute('href');
		}

		search.value = '';
		opener = button;
		setFilter('all');
		backdrop.classList.add('open');
		el('[data-day-close]').focus();
	};

	const close = () => {
		backdrop.classList.remove('open');
		body.textContent = '';
		rows = [];
		// Back to the day that opened it, if the live refresh has not
		// replaced the grid underneath in the meantime.
		if (opener && document.body.contains(opener)) opener.focus();
		opener = null;
	};

	filters.forEach((button) => button.addEventListener('click', () => setFilter(button.dataset.dayFilter)));
	search.addEventListener('input', render);

	// Delegated, because the calendar is a live pane: its days are replaced
	// whenever a mark lands elsewhere, and a listener bound to a day would go
	// with them.
	document.addEventListener('click', (event) => {
		const day = event.target.closest('[data-day-open]');
		if (day) {
			open(day);
			return;
		}

		if (event.target === backdrop || event.target.closest('[data-day-close]')) {
			close();
		}
	});

	document.addEventListener('keydown', (event) => {
		if (event.key === 'Escape' && backdrop.classList.contains('open')) {
			close();
		}
	});
})();
</script>
