{{-- Enrolment: the coordinator's decision about who the programme feeds.

     One dialog, included by every coordinator page that offers the action, so
     enrolling a learner works and looks identical wherever it is started from.
     Any control marked [data-enroll-open] opens it; the endpoints live on the
     backdrop rather than on a button, so a page can offer the action twice
     without repeating the wiring.

     The list is fetched when the modal opens and re-read whenever the page
     announces `fc:records-changed`, so a learner an adviser measures during the
     session appears here without a reload. Enrolling dispatches
     `fc:refresh-request` so the figures behind the dialog re-read at once.
     Filters are client-side because the list is small and the names are
     encrypted at rest — the server cannot search them. --}}
<div class="modal-backdrop" id="enrollBackdrop" aria-hidden="true"
	data-candidates-url="{{ route('feedingcor-program.enrollment.candidates') }}"
	data-enroll-url="{{ route('feedingcor-program.enrollment.store') }}">
	<div class="modal-panel enroll-panel" role="dialog" aria-modal="true" aria-labelledby="enrollTitle">
		<div class="modal-head enroll-head">
			<div>
				<div class="enroll-eyebrow">Enroll beneficiary</div>
				<h2 class="modal-title enroll-title" id="enrollTitle">Qualified learners</h2>
				<p class="enroll-sub" id="enrollSub">Learners measured as wasted or severely wasted. Enrolling one starts their meals at the next feeding day.</p>
			</div>
			<button type="button" class="modal-close enroll-close" id="enrollClose" aria-label="Close">&times;</button>
		</div>

		<div class="enroll-filters">
			<div class="enroll-filter">
				<label class="field-label" for="enrollSearch">Name</label>
				<input type="search" class="input" id="enrollSearch" placeholder="Search a learner" autocomplete="off">
			</div>
			<div class="enroll-filter">
				<label class="field-label" for="enrollGrade">Grade</label>
				<select class="select" id="enrollGrade"><option value="">All</option></select>
			</div>
			<div class="enroll-filter">
				<label class="field-label" for="enrollSection">Section</label>
				<select class="select" id="enrollSection"><option value="">All sections</option></select>
			</div>
			<div class="enroll-filter">
				<label class="field-label" for="enrollSex">Sex</label>
				<select class="select" id="enrollSex">
					<option value="">All</option>
					@foreach (\App\Support\FeedingBeneficiarySummary::SEX_OPTIONS as $sexOption)
						<option value="{{ $sexOption }}">{{ $sexOption }}</option>
					@endforeach
				</select>
			</div>
			<div class="enroll-filter">
				<label class="field-label" for="enrollStatus">Status</label>
				<select class="select" id="enrollStatus">
					<option value="">All statuses</option>
					<option value="Severely Wasted">Severely wasted</option>
					<option value="Wasted">Wasted</option>
				</select>
			</div>
		</div>

		<div class="enroll-countbar">
			<span id="enrollShowing">Loading&hellip;</span>
			<button type="button" class="enroll-clear" id="enrollClearFilters">Clear filters</button>
		</div>

		<div class="modal-body enroll-body">
			<table class="enroll-table">
				<thead>
					<tr>
						<th class="enroll-check"><input type="checkbox" id="enrollCheckAll" aria-label="Select every learner shown"></th>
						<th>Name</th>
						<th>Grade</th>
						<th>Section</th>
						<th>Sex</th>
						<th>Status</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody id="enrollRows"></tbody>
			</table>
			<p class="enroll-empty" id="enrollEmpty" hidden>No learner is waiting to be enrolled.</p>
			<p class="flash err enroll-error" id="enrollError" hidden></p>
		</div>

		<div class="modal-foot enroll-foot">
			<div class="enroll-tally">
				<strong id="enrollEnrolledCount">0</strong> currently enrolled
				<span class="enroll-sep">&middot;</span>
				<strong id="enrollWaitingCount">0</strong> waiting
			</div>
			<div class="enroll-foot-actions">
				<button type="button" class="btn btn-ghost" id="enrollCancel">Close</button>
				<button type="button" class="btn btn-primary" id="enrollSelected" disabled>Enroll selected</button>
			</div>
		</div>
	</div>
</div>
<script>
// Enrolment modal. Qualifying is the adviser's measurement; enrolling is the
// coordinator's decision, and this is where it is made.
(() => {
	const backdrop = document.getElementById('enrollBackdrop');
	const openers = document.querySelectorAll('[data-enroll-open]');
	if (!backdrop || openers.length === 0) {
		return;
	}

	const el = (id) => document.getElementById(id);
	const rowsBody = el('enrollRows');
	const search = el('enrollSearch');
	const gradeSel = el('enrollGrade');
	const sectionSel = el('enrollSection');
	const sexSel = el('enrollSex');
	const statusSel = el('enrollStatus');
	const showing = el('enrollShowing');
	const emptyNote = el('enrollEmpty');
	const errorNote = el('enrollError');
	const checkAll = el('enrollCheckAll');
	const enrolledCount = el('enrollEnrolledCount');
	const waitingCount = el('enrollWaitingCount');
	const enrollSelected = el('enrollSelected');
	const subtitle = el('enrollSub');
	const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

	let candidates = [];
	const selected = new Set();
	let open = false;
	let busy = false;

	const esc = (value) => String(value).replace(/[&<>"']/g, (c) => ({
		'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
	}[c]));

	const visible = () => candidates.filter((row) => {
		const term = (search?.value ?? '').trim().toLowerCase();
		return (term === '' || row.name.toLowerCase().includes(term))
			&& (gradeSel.value === '' || row.grade === gradeSel.value)
			&& (sectionSel.value === '' || row.section === sectionSel.value)
			&& (sexSel.value === '' || row.sex === sexSel.value)
			&& (statusSel.value === '' || row.status === statusSel.value);
	});

	const setError = (message) => {
		if (!errorNote) return;
		errorNote.textContent = message ?? '';
		errorNote.hidden = !message;
	};

	const syncFooter = () => {
		if (enrollSelected) {
			enrollSelected.disabled = busy || selected.size === 0;
			enrollSelected.textContent = selected.size > 0
				? 'Enroll selected (' + selected.size + ')'
				: 'Enroll selected';
		}

		const shown = visible();
		if (checkAll) {
			const allChecked = shown.length > 0 && shown.every((row) => selected.has(row.id));
			checkAll.checked = allChecked;
			checkAll.indeterminate = !allChecked && shown.some((row) => selected.has(row.id));
		}
	};

	const render = () => {
		const shown = visible();

		if (showing) {
			showing.textContent = 'Showing ' + shown.length + ' of ' + candidates.length
				+ ' qualified learner' + (candidates.length === 1 ? '' : 's');
		}
		if (emptyNote) {
			emptyNote.hidden = candidates.length !== 0;
		}

		rowsBody.innerHTML = shown.map((row) => `
			<tr data-id="${row.id}">
				<td class="enroll-check"><input type="checkbox" data-select="${row.id}" ${selected.has(row.id) ? 'checked' : ''} aria-label="Select ${esc(row.name)}"></td>
				<td><strong>${esc(row.name)}</strong></td>
				<td>${esc(row.grade)}</td>
				<td>${esc(row.section || '—')}</td>
				<td>${esc(row.sex || '—')}</td>
				<td><span class="badge ${row.badge}">${esc(row.status_short)}</span></td>
				<td><button type="button" class="btn btn-primary btn-sm" data-enroll="${row.id}">Enroll</button></td>
			</tr>`).join('');

		syncFooter();
	};

	const fillOptions = (select, values, keepValue) => {
		const first = select.querySelector('option');
		select.innerHTML = '';
		select.appendChild(first);
		values.forEach((value) => {
			const option = document.createElement('option');
			option.value = value;
			option.textContent = value;
			select.appendChild(option);
		});
		// A filter the coordinator set survives a live refresh unless the value
		// it named has left the list entirely.
		select.value = values.includes(keepValue) ? keepValue : '';
	};

	const load = async () => {
		try {
			const response = await fetch(backdrop.dataset.candidatesUrl, {
				headers: { Accept: 'application/json' },
				credentials: 'same-origin',
			});
			if (!response.ok) {
				setError('Could not load the qualified learners. Try again in a moment.');
				return;
			}

			const payload = await response.json();
			candidates = Array.isArray(payload.rows) ? payload.rows : [];

			// Drop selections for learners who are no longer waiting — someone
			// else may have enrolled them while this modal sat open.
			const ids = new Set(candidates.map((row) => row.id));
			Array.from(selected).forEach((id) => { if (!ids.has(id)) selected.delete(id); });

			fillOptions(gradeSel, payload.grades ?? [], gradeSel.value);
			fillOptions(sectionSel, payload.sections ?? [], sectionSel.value);

			if (enrolledCount) enrolledCount.textContent = payload.enrolled ?? 0;
			if (waitingCount) waitingCount.textContent = payload.waiting ?? 0;
			if (subtitle && payload.weigh_in) {
				subtitle.textContent = 'Learners measured as wasted or severely wasted at the '
					+ payload.weigh_in + ' weigh-in. Enrolling one starts their meals at the next feeding day.';
			}

			setError(null);
			render();
		} catch (error) {
			setError('Could not load the qualified learners. Try again in a moment.');
		}
	};

	const enroll = async (ids) => {
		if (busy || ids.length === 0) {
			return;
		}

		busy = true;
		syncFooter();
		try {
			const response = await fetch(backdrop.dataset.enrollUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-CSRF-TOKEN': csrf,
				},
				credentials: 'same-origin',
				body: JSON.stringify({ record_ids: ids }),
			});

			if (!response.ok) {
				const payload = await response.json().catch(() => ({}));
				setError(payload.message ?? 'Those learners could not be enrolled.');
				return;
			}

			ids.forEach((id) => selected.delete(id));
			await load();
			// The figures behind the modal count enrolled learners, so they are
			// re-read now rather than at the next pulse.
			document.dispatchEvent(new CustomEvent('fc:refresh-request'));
		} catch (error) {
			setError('Those learners could not be enrolled. Check your connection and try again.');
		} finally {
			busy = false;
			syncFooter();
		}
	};

	const setOpen = (state) => {
		open = state;
		backdrop.classList.toggle('open', state);
		backdrop.setAttribute('aria-hidden', state ? 'false' : 'true');
		document.body.style.overflow = state ? 'hidden' : '';
		if (state) {
			setError(null);
			load().then(() => search?.focus());
		}
	};

	openers.forEach((opener) => opener.addEventListener('click', () => setOpen(true)));
	[el('enrollClose'), el('enrollCancel')].forEach((btn) => btn?.addEventListener('click', () => setOpen(false)));
	backdrop.addEventListener('click', (event) => { if (event.target === backdrop) setOpen(false); });
	document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && open) setOpen(false); });

	[search, gradeSel, sectionSel, sexSel, statusSel].forEach((control) => {
		control?.addEventListener('input', render);
		control?.addEventListener('change', render);
	});

	el('enrollClearFilters')?.addEventListener('click', () => {
		if (search) search.value = '';
		[gradeSel, sectionSel, sexSel, statusSel].forEach((select) => { if (select) select.value = ''; });
		render();
	});

	checkAll?.addEventListener('change', () => {
		visible().forEach((row) => {
			if (checkAll.checked) selected.add(row.id);
			else selected.delete(row.id);
		});
		render();
	});

	rowsBody.addEventListener('click', (event) => {
		const enrollOne = event.target.closest('[data-enroll]');
		if (enrollOne) {
			enroll([Number(enrollOne.dataset.enroll)]);
		}
	});

	rowsBody.addEventListener('change', (event) => {
		const box = event.target.closest('[data-select]');
		if (!box) return;
		const id = Number(box.dataset.select);
		if (box.checked) selected.add(id);
		else selected.delete(id);
		syncFooter();
	});

	enrollSelected?.addEventListener('click', () => enroll(Array.from(selected)));

	// An adviser measuring a learner mid-session moves the page's stamp; while
	// the modal is open, the waiting list follows it.
	document.addEventListener('fc:records-changed', () => { if (open) load(); });
})();
</script>
