<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>Record Attendance - Feeding Coordinator - SIGLA</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="icon" type="image/png" href="{{ asset('images/sigla-mark.png') }}">
	<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
	<script>document.documentElement.classList.add('js');</script>
	<style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/feeding-sf2.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/feeding-record-attendance.css')) !!}</style>
	{{-- The shared dialog sheet: this screen carries the confirmation dialog,
	     and the anatomy it is drawn on belongs in one file, not a copy per
	     page. --}}
	<style>{!! file_get_contents(resource_path('css/feeding-record-modal.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/feeding-attendance-confirm.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/role-sidebar.css')) !!}</style>
</head>
<body>
{{-- Attendance owns a mark, so recording one belongs under the Attendance tab. --}}
@include('partials.feedingcor-sidebar', ['active' => 'attendance'])

@php
	$readOnly = ! $sessionOpen;
	$monthUrl = fn (string $month): string => route('feedingcor-program.attendance.record', array_filter([
		'month' => $month, 'grade' => $grade, 'section' => $section,
	]));
@endphp

<div class="main">
	<header class="topbar">
		<div class="topbar-bc"><a href="{{ route('dashboard.feedingcor-attendance') }}">Attendance</a><span class="bc-sep">&rsaquo;</span><span>Record Attendance</span></div>
		@include('partials.live-clock')
	</header>

	<div class="content">
		<div class="page-header">
			<div>
				<h1 class="page-title">Record <span>Attendance</span></h1>
				<p class="page-sub">School Form 2 (SF2) &middot; Daily Attendance Report of Learners</p>
			</div>
		</div>

		@if (session('success'))
			<div class="flash ok">{{ session('success') }}</div>
		@endif
		@if (session('error'))
			<div class="flash err">{{ session('error') }}</div>
		@endif
		@if ($errors->any())
			<div class="flash err">{{ $errors->first() }}</div>
		@endif

		{{-- A session is open on its own day and closed after it, recorded or
		     not: the endpoint refuses the write regardless of what is on screen. --}}
		@if ($sessionDate !== null && ($readOnly || $sessionRecorded))
			<div class="alert-bar is-info">
				<div class="alert-body">
					<div>
						@if (! $isFeedingDay)
							<strong>{{ $noSession['title'] ?? $sessionLabel }}</strong>
							<span>{{ $noSession['detail'] ?? '' }}</span>
						@elseif ($sessionClosed)
							<strong>Attendance for {{ $sessionLabel }} is closed</strong>
							<span>A day&rsquo;s attendance can only be recorded or changed on that day.</span>
						@elseif ($readOnly)
							<strong>{{ $sessionLabel }} is outside the running feeding program</strong>
						@else
							<strong>Attendance for {{ $sessionLabel }} has been recorded</strong>
							<span>You can still change a mark until the end of the day. After that, this session is closed.</span>
						@endif
					</div>
				</div>
			</div>
		@endif

		{{-- The SF2 heading, with the two controls that cluster the form:
		     an SF2 is kept one class at a time. --}}
		<form method="GET" action="{{ route('feedingcor-program.attendance.record') }}" class="card sf2-head" id="sf2Filter">
			<input type="hidden" name="month" value="{{ $month }}">
			<div class="sf2-field sf2-field-wide">
				<span class="sf2-label">Name of School</span>
				<span class="sf2-value">{{ $schoolName }}</span>
			</div>
			<div class="sf2-field">
				<span class="sf2-label">School Year</span>
				<span class="sf2-value">{{ str_replace('-', ' – ', $schoolYear) }}</span>
			</div>
			<div class="sf2-field">
				<span class="sf2-label" id="sf2MonthLabel">Report for the Month of</span>
				<div class="sf2-month" role="group" aria-labelledby="sf2MonthLabel">
					<a class="sf2-step" href="{{ $monthUrl($prevMonth) }}" aria-label="Previous month">&lsaquo;</a>
					<span class="sf2-value">{{ $monthLabel }}</span>
					@if ($nextMonth)
						<a class="sf2-step" href="{{ $monthUrl($nextMonth) }}" aria-label="Next month">&rsaquo;</a>
					@else
						<span class="sf2-step is-off" aria-hidden="true">&rsaquo;</span>
					@endif
				</div>
			</div>
			<div class="sf2-field">
				<label class="sf2-label" for="sf2Grade">Grade Level</label>
				<select class="select" name="grade" id="sf2Grade">
					<option value="">All</option>
					@foreach ($grades as $option)
						<option value="{{ $option }}" @selected($grade === $option)>{{ $option }}</option>
					@endforeach
				</select>
			</div>
			<div class="sf2-field">
				<label class="sf2-label" for="sf2Section">Section</label>
				<select class="select" name="section" id="sf2Section">
					<option value="">All</option>
					@foreach ($sections as $option)
						<option value="{{ $option }}" @selected($section === $option)>{{ $option }}</option>
					@endforeach
				</select>
			</div>
			<noscript><button type="submit" class="btn btn-secondary">Apply</button></noscript>
		</form>

		<form method="POST" action="{{ route('feedingcor-program.attendance.record.store') }}" id="recordForm">
			@csrf
			@if ($sessionDate !== null)
				<input type="hidden" name="session_date" value="{{ $sessionDate }}">
			@endif
			<input type="hidden" name="return_to" value="record">
			<input type="hidden" name="grade" value="{{ $grade }}">
			<input type="hidden" name="section" value="{{ $section }}">

			@include('feedingcor-dashboard.partials.sf2-grid')

			@if ($rows->isNotEmpty() && ! $readOnly)
				{{-- A learner left unmarked is saved as nothing at all, never as an
				     absence, so the bar counts what is still undecided. --}}
				<div class="ra-savebar">
					<div class="ra-tally">
						<strong class="ra-session">{{ $sessionLabel }}</strong>
						<span class="badge badge-normal">Present <span data-tally="present">0</span></span>
						<span class="badge badge-critical">Absent <span data-tally="absent">0</span></span>
						<span class="badge badge-monitor">Excused <span data-tally="excused">0</span></span>
						<span class="badge badge-neutral">{{ \App\Support\FeedingAttendanceMark::NOT_RECORDED }} <span data-tally="none">{{ $rows->count() }}</span></span>
					</div>
					<div class="ra-actions">
						<a class="btn btn-ghost" href="{{ route('dashboard.feedingcor-attendance') }}">Cancel</a>
						<button type="submit" class="btn btn-primary" id="raSave">Save attendance</button>
					</div>
				</div>
			@endif
		</form>

		@include('feedingcor-dashboard.partials.sf2-summary')
	</div>
</div>

@if ($rows->isNotEmpty() && ! $readOnly)
	{{-- The cell's pop-up: the three answers a mark has, and the reason an
	     absence may carry. --}}
	<div class="sf2-pop" id="sf2Pop" role="dialog" aria-modal="false" aria-labelledby="sf2PopTitle" hidden>
		<p class="sf2-pop-title" id="sf2PopTitle"></p>
		<div class="sf2-pop-choices">
			<button type="button" class="sf2-choice is-present" data-choose="present"><span class="sf2-mark is-present">✓</span>Present</button>
			<button type="button" class="sf2-choice is-absent" data-choose="absent"><span class="sf2-mark is-absent">✗</span>Absent</button>
			<button type="button" class="sf2-choice is-excused" data-choose="excused"><span class="sf2-mark is-excused">E</span>Excused</button>
		</div>
		<label class="sf2-pop-reason">
			<span class="field-label">Reason</span>
			<input type="text" class="input" id="sf2PopReason" maxlength="255" autocomplete="off">
		</label>
		<div class="sf2-pop-actions">
			{{-- Only before a mark is on file: an unmarked row writes nothing, so
			     clearing a recorded mark would look undone and stay on file. --}}
			<button type="button" class="btn btn-ghost sf2-pop-clear" data-pop-clear>Clear</button>
			<button type="button" class="btn btn-primary sf2-pop-save" data-pop-save>Save</button>
		</div>
	</div>

	{{-- Outside .main so the fixed backdrop stays relative to the viewport. --}}
	@include('feedingcor-dashboard.partials.attendance-confirm-modal', [
		'confirmSessionLabel' => $sessionLabel,
	])
@endif

<script>
(() => {
	// Grade and section narrow the form; a new grade starts on all its sections.
	const filter = document.getElementById('sf2Filter');
	if (filter) {
		const grade = document.getElementById('sf2Grade');
		const section = document.getElementById('sf2Section');
		grade?.addEventListener('change', () => { if (section) section.value = ''; filter.submit(); });
		section?.addEventListener('change', () => filter.submit());
	}

	const form = document.getElementById('recordForm');
	const table = document.getElementById('sf2Table');
	if (!form || !table) return;

	const GLYPHS = { present: '✓', absent: '✗', excused: 'E', unconfirmed: '?' };
	const LABELS = { present: 'Present', absent: 'Absent', excused: 'Excused', unconfirmed: 'Unconfirmed', '': @json(\App\Support\FeedingAttendanceMark::NOT_RECORDED) };
	const rows = Array.from(table.querySelectorAll('tbody tr[data-search]'));
	const dates = rows.length ? Array.from(rows[0].querySelectorAll('td[data-date]'), (cell) => cell.dataset.date) : [];
	const count = (cells, state) => cells.filter((cell) => cell.dataset.state === state).length;
	const fmt = (value, suffix = '') => value === null ? '—' : String(Math.round(value * 100) / 100) + suffix;

	// Every figure on the form, recomputed from the cells, so an unsaved mark
	// moves the totals the moment it is entered.
	const recompute = () => {
		rows.forEach((row) => {
			const cells = Array.from(row.querySelectorAll('td[data-date]'));
			['absent', 'excused', 'present'].forEach((state) => {
				row.querySelector(`[data-total="${state}"]`).textContent = String(count(cells, state));
			});
		});

		table.querySelectorAll('[data-block]').forEach((block) => {
			const scope = block.dataset.block === 'all' ? rows : rows.filter((row) => block.contains(row));
			const totalRow = block.matches('tr') ? block : block.querySelector('tr.sf2-total');
			if (!totalRow) return;
			totalRow.querySelectorAll('[data-day-total]').forEach((cell) => {
				const n = scope.filter((row) => row.querySelector(`td[data-date="${cell.dataset.dayTotal}"]`)?.dataset.state === 'present').length;
				cell.textContent = n > 0 ? String(n) : '';
			});
			['absent', 'excused', 'present'].forEach((state) => {
				const n = scope.reduce((sum, row) => sum + count(Array.from(row.querySelectorAll('td[data-date]')), state), 0);
				totalRow.querySelector(`[data-block-total="${state}"]`).textContent = String(n);
			});
		});

		// Summary: a feeding day is a day somebody was recorded on.
		const settled = ['present', 'absent', 'excused'];
		const held = dates.filter((date) => rows.some((row) => settled.includes(row.querySelector(`td[data-date="${date}"]`)?.dataset.state)));
		const sexOf = (row) => row.closest('tbody')?.dataset.block || '';
		const feedingDays = document.querySelector('[data-summary="feeding_days"]');
		if (feedingDays) feedingDays.textContent = String(held.length);

		['Male', 'Female', 'Total'].forEach((sex) => {
			const group = sex === 'Total' ? rows : rows.filter((row) => sexOf(row) === sex);
			let present = 0;
			let longRuns = 0;
			group.forEach((row) => {
				let run = 0;
				let longest = 0;
				held.forEach((date) => {
					const state = row.querySelector(`td[data-date="${date}"]`)?.dataset.state;
					if (state === 'present') { present++; run = 0; }
					else if (state === 'absent') { longest = Math.max(longest, ++run); }
				});
				if (longest >= 5) longRuns++;
			});
			const registered = Number(document.querySelector(`[data-summary-row="registered"] [data-sex="${sex}"]`)?.dataset.registered || 0);
			const ada = held.length ? present / held.length : null;
			const set = (key, text) => {
				const cell = document.querySelector(`[data-summary-row="${key}"] [data-sex="${sex}"]`);
				if (cell) cell.textContent = text;
			};
			set('ada', fmt(ada));
			set('percent_attendance', ada !== null && registered > 0 ? fmt(Math.round(ada / registered * 1000) / 10, '%') : '—');
			set('consecutive_absent', String(longRuns));
		});

		// The save bar counts today's column alone: that is what is posted.
		const tally = { present: 0, absent: 0, excused: 0 };
		form.querySelectorAll('[data-cell]').forEach((button) => {
			const state = button.closest('td').dataset.state;
			if (state in tally) tally[state]++;
		});
		Object.entries(tally).forEach(([key, n]) => {
			const el = form.querySelector(`[data-tally="${key}"]`);
			if (el) el.textContent = String(n);
		});
		const none = form.querySelector('[data-tally="none"]');
		if (none) none.textContent = String(form.querySelectorAll('[data-cell]').length - tally.present - tally.absent - tally.excused);
	};

	const pop = document.getElementById('sf2Pop');
	if (pop) {
		const title = document.getElementById('sf2PopTitle');
		const reason = document.getElementById('sf2PopReason');
		const reasonField = pop.querySelector('.sf2-pop-reason');
		const clear = pop.querySelector('.sf2-pop-clear');
		let active = null;

		const close = (restoreFocus = true) => {
			if (pop.hidden) return;
			pop.hidden = true;
			if (restoreFocus && active) active.focus();
			active = null;
		};

		const place = (button) => {
			const box = button.getBoundingClientRect();
			const width = pop.offsetWidth;
			const height = pop.offsetHeight;
			let left = box.left + box.width / 2 - width / 2;
			left = Math.max(12, Math.min(left, window.innerWidth - width - 12));
			let top = box.bottom + 8;
			if (top + height > window.innerHeight - 12) top = Math.max(12, box.top - height - 8);
			pop.style.left = left + 'px';
			pop.style.top = top + 'px';
		};

		const save = pop.querySelector('[data-pop-save]');
		// The answer chosen in the pop-up, not yet put on the cell: a choice
		// only selects, and Save is what marks it — so a reason can be typed
		// first.
		let pending = '';

		const select = (value) => {
			pending = value;
			pop.querySelectorAll('[data-choose]').forEach((choice) => {
				choice.setAttribute('aria-pressed', String(choice.dataset.choose === value));
			});
			// Only an absence carries a reason; being present carries none.
			reason.disabled = value === 'present';
			if (value === 'present') reason.value = '';
			save.disabled = value === '';
		};

		const open = (button) => {
			active = button;
			const cell = button.closest('td');
			const row = button.closest('tr');
			title.textContent = row.dataset.name + ' · ' + @json($sessionLabel);
			reason.value = cell.querySelector('.ra-remark').value;
			select(['present', 'absent', 'excused'].includes(cell.dataset.state) ? cell.dataset.state : '');
			reasonField.hidden = false;
			clear.hidden = row.dataset.onfile !== '';
			pop.hidden = false;
			place(button);
			(pop.querySelector('[aria-pressed="true"]') || pop.querySelector('[data-choose]')).focus();
		};

		const apply = (value) => {
			if (!active) return;
			const cell = active.closest('td');
			const row = active.closest('tr');
			cell.querySelectorAll('input[type="radio"]').forEach((radio) => { radio.checked = radio.value === value; });
			const remark = cell.querySelector('.ra-remark');
			const away = value === 'absent' || value === 'excused';
			remark.disabled = !away;
			remark.value = away ? reason.value.trim() : '';
			cell.dataset.state = value;
			active.className = 'sf2-mark is-' + (value || 'blank');
			active.textContent = GLYPHS[value] || '';
			active.setAttribute('aria-label', row.dataset.name + ': ' + LABELS[value]);
			const remarkCell = row.querySelector('[data-remark-cell]');
			if (remarkCell) remarkCell.textContent = remark.value;
			recompute();
			close();
		};

		table.addEventListener('click', (event) => {
			const button = event.target.closest('[data-cell]');
			if (!button) return;
			if (active === button && !pop.hidden) { close(); return; }
			open(button);
		});
		pop.addEventListener('click', (event) => {
			const choice = event.target.closest('[data-choose]');
			if (choice) {
				select(choice.dataset.choose);
				if (choice.dataset.choose !== 'present') reason.focus();
				return;
			}
			if (event.target.closest('[data-pop-save]') && pending !== '') apply(pending);
			if (event.target.closest('[data-pop-clear]')) apply('');
		});
		reason.addEventListener('keydown', (event) => {
			if (event.key !== 'Enter') return;
			event.preventDefault();
			// Enter saves the answer chosen; a reason with none chosen is an absence.
			apply(pending !== '' ? pending : 'absent');
		});
		document.addEventListener('keydown', (event) => {
			if (event.key === 'Escape' && !pop.hidden) close();
		});
		document.addEventListener('pointerdown', (event) => {
			if (!pop.hidden && !pop.contains(event.target) && !event.target.closest('[data-cell]')) close(false);
		});
		// Follows its cell rather than closing: a phone fires resize and scroll
		// whenever its address bar shows or hides, which would snap the pop-up
		// shut under the coordinator's finger.
		const follow = () => { if (!pop.hidden && active) place(active); };
		window.addEventListener('resize', follow);
		window.addEventListener('scroll', follow, true);
	}

	recompute();
})();
</script>
@include('partials.role-page-transition')
</body>
</html>
