{{-- ── One beneficiary's attendance history ───────────────────────────────
     Opened from the whole name cell on the By Beneficiary roll. It answers the
     question the roll's counts raise and cannot answer: 1 of 4 — which four,
     and what was written beside them.

     It is a reading, never a form. Marks are entered in the Record Attendance
     dialog and corrected on the learner's own beneficiary record, where the
     change is attributed and audited; this dialog renders no radio, no remark
     field and no endpoint at all.

     Every figure it prints is the row's own, carried on the button that opened
     it, so the dialog and the row are one render and cannot disagree. The
     history walks **the school's feeding days**, not the learner's marks, so a
     session no sheet covered this child reads "Not marked" — never an absence,
     and never silently missing.

     Anatomy is the shared one (`.modal-backdrop` / `.modal-panel` / `-head` /
     `-body` / `-foot`), so a dialog looks the same product-wide. --}}
<div class="modal-backdrop al-backdrop" id="learnerBackdrop">
	<div class="modal-panel al-modal" role="dialog" aria-modal="true" aria-labelledby="learnerTitle">
		<header class="modal-head">
			<div>
				<p class="modal-eyebrow">Beneficiary Attendance</p>
				<h2 class="modal-title" id="learnerTitle" data-learner-name></h2>
				<p class="modal-sub" data-learner-sub></p>
			</div>
			<button type="button" class="modal-close" data-learner-close aria-label="Close">&times;</button>
		</header>

		{{-- The cumulative standing, in the five figures the roll prints. Rate
		     is the one the school's threshold judged, over confirmed sessions
		     alone — an excused absence is in neither half of it. --}}
		<div class="al-figures">
			<div class="al-figure">
				<span class="al-figure-label">Present</span>
				<span class="al-figure-value" data-learner-figure="present">0</span>
			</div>
			<div class="al-figure">
				<span class="al-figure-label">Absent</span>
				<span class="al-figure-value" data-learner-figure="absent">0</span>
			</div>
			<div class="al-figure">
				<span class="al-figure-label">Excused</span>
				<span class="al-figure-value" data-learner-figure="excused">0</span>
			</div>
			<div class="al-figure">
				<span class="al-figure-label">Not marked</span>
				<span class="al-figure-value" data-learner-figure="notMarked">0</span>
			</div>
			<div class="al-figure">
				<span class="al-figure-label">Rate</span>
				<span class="al-figure-value" data-learner-figure="rate">&mdash;</span>
			</div>
		</div>

		<div class="modal-body">
			<div class="table-scroll al-scroll">
				<table class="al-table">
					<thead>
						<tr>
							<th>Date</th>
							<th>Attendance</th>
							<th>Remarks</th>
						</tr>
					</thead>
					<tbody data-learner-rows></tbody>
				</table>
				<p class="table-empty" data-learner-empty hidden>No feeding session has been recorded yet.</p>
			</div>
		</div>

		<footer class="modal-foot">
			<span class="badge badge-neutral" data-learner-standing></span>
			<div class="modal-actions">
				<button type="button" class="btn btn-ghost" data-learner-close>Close</button>
				{{-- The learner's full record, where a mark can actually be
				     corrected. This dialog reads; that page is where a change
				     is made, attributed and audited. --}}
				<a class="btn btn-secondary" data-learner-record href="#">Open Beneficiary Record</a>
			</div>
		</footer>
	</div>
</div>
<script>
(() => {
	const backdrop = document.getElementById('learnerBackdrop');
	if (!backdrop) {
		return;
	}

	const nameEl = backdrop.querySelector('[data-learner-name]');
	const subEl = backdrop.querySelector('[data-learner-sub]');
	const body = backdrop.querySelector('[data-learner-rows]');
	const empty = backdrop.querySelector('[data-learner-empty]');
	const standing = backdrop.querySelector('[data-learner-standing]');
	const recordLink = backdrop.querySelector('[data-learner-record]');
	const figures = {
		present: backdrop.querySelector('[data-learner-figure="present"]'),
		absent: backdrop.querySelector('[data-learner-figure="absent"]'),
		excused: backdrop.querySelector('[data-learner-figure="excused"]'),
		notMarked: backdrop.querySelector('[data-learner-figure="notMarked"]'),
		rate: backdrop.querySelector('[data-learner-figure="rate"]'),
	};

	// The same badges the roll behind it prints, so a mark looks like itself
	// wherever it is read.
	const MARKS = {
		present: ['badge-normal', 'Present'],
		absent: ['badge-critical', 'Absent'],
		excused: ['badge-monitor', 'Excused'],
		unconfirmed: ['badge-neutral', 'Unconfirmed'],
	};

	const setOpen = (open) => {
		backdrop.classList.toggle('open', open);
		if (!open) body.textContent = '';
	};

	const parse = (value, fallback) => {
		try {
			return JSON.parse(value);
		} catch (error) {
			return fallback;
		}
	};

	const build = (button) => {
		// The school's feeding days, newest first — rendered with the roll, so
		// a live refresh replaces both together.
		const index = document.querySelector('[data-session-dates]');
		const sessions = index ? parse(index.dataset.sessionDates, []) : [];
		const marks = parse(button.dataset.marks || '{}', {});

		nameEl.textContent = button.dataset.name || '';
		subEl.textContent = ['Grade ' + (button.dataset.grade || '—'), button.dataset.section, button.dataset.sex]
			.filter((part) => part !== '' && part !== undefined)
			.join(' · ');

		figures.present.textContent = button.dataset.present || '0';
		figures.absent.textContent = button.dataset.absent || '0';
		figures.excused.textContent = button.dataset.excused || '0';
		figures.notMarked.textContent = button.dataset.notMarked || '0';
		figures.rate.textContent = button.dataset.rate || '—';

		standing.textContent = button.dataset.standing || '';
		standing.className = 'badge ' + (button.dataset.standingBadge || 'badge-neutral');
		recordLink.href = button.dataset.recordUrl || '#';

		body.textContent = '';

		sessions.forEach((session) => {
			const mark = marks[session.d];
			const tr = document.createElement('tr');

			const date = document.createElement('td');
			date.className = 'al-date';
			date.textContent = session.l;
			tr.appendChild(date);

			const state = document.createElement('td');
			if (mark && MARKS[mark.status]) {
				const chip = document.createElement('span');
				chip.className = 'badge ' + MARKS[mark.status][0];
				chip.textContent = MARKS[mark.status][1];
				state.appendChild(chip);
			} else {
				// A feeding day no sheet covered this learner on. A filing gap,
				// never an absence — the difference between a follow-up and a
				// paperwork job.
				const none = document.createElement('span');
				none.className = 'fa-unmarked';
				none.textContent = 'Not marked';
				state.appendChild(none);
			}
			tr.appendChild(state);

			const remark = document.createElement('td');
			remark.className = 'al-remark';
			remark.textContent = (mark && mark.remarks) ? mark.remarks : '—';
			tr.appendChild(remark);

			body.appendChild(tr);
		});

		empty.hidden = sessions.length > 0;
	};

	// Delegated, because the roll is a live pane: the rows behind this dialog
	// are replaced whenever a mark lands elsewhere in the school, and a
	// listener bound to a row would go with them.
	document.addEventListener('click', (event) => {
		const opener = event.target.closest('[data-learner-open]');
		if (opener) {
			build(opener);
			setOpen(true);
			return;
		}

		if (event.target === backdrop || event.target.closest('[data-learner-close]')) {
			setOpen(false);
		}
	});

	document.addEventListener('keydown', (event) => {
		if (event.key === 'Escape' && backdrop.classList.contains('open')) {
			setOpen(false);
		}
	});
})();
</script>
