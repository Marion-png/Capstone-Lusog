{{-- ── Confirm Attendance ─────────────────────────────────────────────────
     A recorded session is closed as a whole — the first confirmed mark ends
     the day, and a wrong one is put right afterwards on the learner's own
     beneficiary record, where the change is attributed and audited. So the
     save is worth reading back before it is made: this dialog names every
     learner and the mark entered for them, and nothing reaches the endpoint
     until it is confirmed.

     It reads the record form rather than the server, so it can only ever show
     what is about to be posted; it writes nothing itself and adds no second
     path into `feeding_attendances`. Rendered wherever `#recordForm` is, and
     wired to it by id — the Attendance tab's dialog and the standalone screen
     post the same marks to the same endpoint, so they confirm the same way.

     Anatomy is the shared one (`.modal-backdrop` / `.modal-panel` / `-head` /
     `-body` / `-foot`), so a dialog looks the same product-wide; it sits above
     the record dialog rather than replacing it, because Back must return the
     coordinator to the marks they were reading. --}}
<div class="modal-backdrop ac-backdrop" id="confirmBackdrop" data-session-label="{{ $confirmSessionLabel ?? '' }}">
	<div class="modal-panel ac-modal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
		<header class="modal-head">
			<div>
				<p class="modal-eyebrow">Confirm Submission</p>
				<h2 class="modal-title" id="confirmTitle">Confirm Attendance</h2>
				<p class="modal-sub" data-confirm-sub></p>
			</div>
			<button type="button" class="modal-close" data-confirm-close aria-label="Close">&times;</button>
		</header>

		<div class="ac-tally">
			<span class="badge badge-normal">Present <span data-confirm-tally="present">0</span></span>
			<span class="badge badge-critical">Absent <span data-confirm-tally="absent">0</span></span>
			<span class="badge badge-monitor">Excused <span data-confirm-tally="excused">0</span></span>
			<span class="badge badge-neutral">Unmarked <span data-confirm-tally="none">0</span></span>
		</div>

		<div class="modal-body">
			<div class="table-scroll ac-scroll">
				<table class="ac-table">
					<thead>
						<tr>
							<th class="ac-idx">#</th>
							<th>Student</th>
							<th>Grade</th>
							<th>Section</th>
							<th class="ac-mark-col">Attendance</th>
							<th>Remarks</th>
						</tr>
					</thead>
					<tbody data-confirm-rows></tbody>
				</table>
				<p class="table-empty" data-confirm-empty hidden>No learner has been marked.</p>
			</div>
		</div>

		<footer class="modal-foot">
			<div class="modal-actions">
				<button type="button" class="btn btn-ghost" data-confirm-close>Back</button>
				<button type="button" class="btn btn-primary" data-confirm-submit>Save Attendance</button>
			</div>
		</footer>
	</div>
</div>
<script>
(() => {
	const backdrop = document.getElementById('confirmBackdrop');
	const form = document.getElementById('recordForm');
	if (!backdrop || !form) {
		return;
	}

	const body = backdrop.querySelector('[data-confirm-rows]');
	const empty = backdrop.querySelector('[data-confirm-empty]');
	const sub = backdrop.querySelector('[data-confirm-sub]');
	const save = backdrop.querySelector('[data-confirm-submit]');
	const tallies = {
		present: backdrop.querySelector('[data-confirm-tally="present"]'),
		absent: backdrop.querySelector('[data-confirm-tally="absent"]'),
		excused: backdrop.querySelector('[data-confirm-tally="excused"]'),
		none: backdrop.querySelector('[data-confirm-tally="none"]'),
	};

	// The same three answers the form offers, on the same badges the sheet
	// prints them with, so the mark a coordinator confirms looks like the mark
	// they entered.
	const MARKS = {
		present: ['badge-normal', 'Present'],
		absent: ['badge-critical', 'Absent'],
		excused: ['badge-monitor', 'Excused'],
	};

	// Set once the coordinator has confirmed, so the second submit passes
	// straight through to the endpoint instead of reopening this dialog.
	let confirmed = false;

	const setOpen = (open) => {
		backdrop.classList.toggle('open', open);
		if (open) save.focus();
	};

	const cell = (text, className) => {
		const td = document.createElement('td');
		if (className) td.className = className;
		td.textContent = text;
		return td;
	};

	// Read back exactly what is about to be posted: the marks on the form, not
	// a second reading of the roster.
	const build = () => {
		const rows = Array.from(form.querySelectorAll('tbody tr[data-search]'));
		const counts = { present: 0, absent: 0, excused: 0 };
		let index = 0;

		body.textContent = '';

		rows.forEach((row) => {
			const checked = row.querySelector('input[type="radio"]:checked');
			// A learner left unmarked writes no row at all, so there is nothing
			// to confirm for them — they are counted, never listed.
			if (!checked || !(checked.value in MARKS)) {
				return;
			}

			counts[checked.value]++;
			index++;

			const remark = row.querySelector('.fa-remark, .ra-remark');
			const [badge, label] = MARKS[checked.value];
			const tr = document.createElement('tr');

			tr.appendChild(cell(String(index), 'ac-idx'));

			const name = document.createElement('td');
			const strong = document.createElement('strong');
			strong.textContent = row.dataset.name || '';
			name.className = 'ac-name';
			name.appendChild(strong);
			tr.appendChild(name);

			tr.appendChild(cell(row.dataset.grade || '—'));
			tr.appendChild(cell(row.dataset.section || '—'));

			const mark = document.createElement('td');
			mark.className = 'ac-mark-col';
			const chip = document.createElement('span');
			chip.className = 'badge ' + badge;
			chip.textContent = label;
			mark.appendChild(chip);
			tr.appendChild(mark);

			const reason = (remark && !remark.disabled ? remark.value : '').trim();
			tr.appendChild(cell(reason !== '' ? reason : '—', 'ac-remark'));

			body.appendChild(tr);
		});

		const marked = counts.present + counts.absent + counts.excused;
		tallies.present.textContent = String(counts.present);
		tallies.absent.textContent = String(counts.absent);
		tallies.excused.textContent = String(counts.excused);
		tallies.none.textContent = String(rows.length - marked);

		const date = backdrop.dataset.sessionLabel || '';
		sub.textContent = (date !== '' ? date + ' · ' : '')
			+ marked + ' of ' + rows.length + ' ' + (rows.length === 1 ? 'learner' : 'learners') + ' marked';

		empty.hidden = marked > 0;
		// Nothing marked is nothing to save; the endpoint refuses it too.
		save.disabled = marked === 0;
	};

	form.addEventListener('submit', (event) => {
		if (confirmed) {
			return;
		}

		event.preventDefault();
		build();
		setOpen(true);
	});

	save.addEventListener('click', () => {
		if (save.disabled) {
			return;
		}

		confirmed = true;
		save.disabled = true;
		setOpen(false);
		if (typeof form.requestSubmit === 'function') {
			form.requestSubmit();
		} else {
			form.submit();
		}
	});

	backdrop.addEventListener('click', (event) => {
		if (event.target === backdrop || event.target.closest('[data-confirm-close]')) {
			setOpen(false);
		}
	});

	// Escape closes this dialog and leaves the marks behind it untouched. The
	// record dialog's own handler stands down while this one is open, so one
	// press cannot dismiss both.
	document.addEventListener('keydown', (event) => {
		if (event.key === 'Escape' && backdrop.classList.contains('open')) {
			setOpen(false);
		}
	});
})();
</script>
