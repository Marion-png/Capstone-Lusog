<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>Record Attendance - Feeding Coordinator - SIGLA</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
	<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
	<script>document.documentElement.classList.add('js');</script>
	<style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/feeding-record-attendance.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/role-sidebar.css')) !!}</style>
</head>
<body>
{{-- Attendance owns a mark, so recording one belongs under the Attendance tab.
     This screen used to light up Feeding Program, which reads the marks and
     deliberately offers no way to change one — the rail was pointing at the tab
     that cannot do what the screen is for. --}}
@include('partials.feedingcor-sidebar', ['active' => 'attendance'])

<div class="main">
	<header class="topbar">
		<div class="topbar-bc"><a href="{{ route('dashboard.feedingcor-attendance') }}">Attendance</a><span class="bc-sep">&rsaquo;</span><span>Record Attendance</span></div>
		@include('partials.live-clock')
	</header>

	<div class="content">
		<div class="page-header">
			<h1 class="page-title">Record <span>Attendance</span></h1>
		</div>

		@if (session('error'))
			<div class="flash err">{{ session('error') }}</div>
		@endif
		@if ($errors->any())
			<div class="flash err">{{ $errors->first() }}</div>
		@endif

		{{-- A recorded session is closed as a whole: this screen becomes a record,
		     the same way the Attendance tab's sheet does, and the endpoint refuses
		     the write regardless of what is on screen. A wrong mark, or a learner
		     this session missed, is put right on that learner's beneficiary record
		     where the change is attributed and audited. --}}
		@if ($sessionLocked || ! $isFeedingDay)
			<div class="alert-bar is-info">
				<div class="alert-body">
					<div>
						@if ($sessionLocked)
							<strong>Attendance for {{ $sessionLabel }} has already been recorded</strong>
							<span>This session is read-only. Correct a mark on the learner&rsquo;s beneficiary record.</span>
						@else
							<strong>{{ $sessionLabel }} is a {{ \Carbon\Carbon::parse($sessionDate)->format('l') }}</strong>
							<span>There are no feeding sessions on Saturdays or Sundays.</span>
						@endif
					</div>
				</div>
			</div>
		@endif

		<form method="POST" action="{{ route('feedingcor-program.attendance.record.store') }}" id="recordForm">
			@csrf

			<div class="card ra-toolbar">
				<div class="ra-field">
					<label class="field-label" for="sessionDate">Session date</label>
					{{-- Changing the date reloads the screen so marks already on
					     file for that day come back pre-selected. --}}
					<input type="date" class="input" id="sessionDate" name="session_date"
						value="{{ $sessionDate }}" max="{{ $today }}" required
						data-reload-url="{{ route('feedingcor-program.attendance.record') }}">
				</div>
				<div class="ra-field ra-field-grow">
					<label class="field-label" for="raSearch">Search</label>
					<div class="lg-search">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
						<input type="search" id="raSearch" placeholder="Name or section" autocomplete="off" aria-label="Search beneficiaries by name or section">
					</div>
				</div>
				<div class="ra-bulk" @if ($sessionLocked || ! $isFeedingDay) hidden @endif>
					<button type="button" class="btn btn-secondary" data-mark-all="present">All present</button>
					<button type="button" class="btn btn-secondary" data-mark-all="absent">All absent</button>
					<button type="button" class="btn btn-ghost" data-mark-all="">Clear</button>
				</div>
			</div>

			<div class="card ra-card">
				<div class="table-scroll">
					<table class="ra-table">
						<thead>
							<tr>
								<th>Student</th>
								<th>Grade</th>
								<th>Section</th>
								<th class="ta-r">Mark</th>
								<th>Remarks</th>
							</tr>
						</thead>
						<tbody>
							@forelse ($rows as $row)
								<tr data-search="{{ strtolower(trim($row['name'].' '.$row['grade'].' '.$row['section'])) }}">
									<td><strong>{{ $row['name'] }}</strong></td>
									<td>{{ $row['grade'] }}</td>
									<td>{{ $row['section'] ?: '—' }}</td>
									<td class="ta-r">
										@if ($sessionLocked || ! $isFeedingDay)
											{{-- The session is a record: the mark is shown and no
											     input is rendered at all. A disabled control is
											     still one a browser can re-enable and post. --}}
											<span class="badge {{ $row['mark'] === 'present' ? 'badge-normal' : ($row['mark'] === 'absent' ? 'badge-critical' : 'badge-neutral') }}">
												{{ $row['mark'] === '' ? 'Not recorded' : ucfirst($row['mark']) }}
											</span>
										@else
											<div class="ra-toggle" role="group" aria-label="Attendance for {{ $row['name'] }}">
												<label class="ra-opt ra-opt-present">
													<input type="radio" name="marks[{{ $row['id'] }}]" value="present" @checked($row['mark'] === 'present')>
													<span>Present</span>
												</label>
												<label class="ra-opt ra-opt-absent">
													<input type="radio" name="marks[{{ $row['id'] }}]" value="absent" @checked($row['mark'] === 'absent')>
													<span>Absent</span>
												</label>
											</div>
										@endif
									</td>
									<td>
										@if ($sessionLocked || ! $isFeedingDay)
											<span class="ra-remark-read">{{ $row['remarks'] !== '' ? $row['remarks'] : '—' }}</span>
										@else
											{{-- Only an absence carries a reason, so the field opens
											     when Absent is chosen and clears when it is not. --}}
											<input type="text" class="input ra-remark" maxlength="255"
												name="remarks[{{ $row['id'] }}]" value="{{ $row['remarks'] }}"
												aria-label="Reason {{ $row['name'] }} was absent"
												@disabled($row['mark'] !== 'absent')>
										@endif
									</td>
								</tr>
							@empty
								<tr><td colspan="5" class="table-empty">No beneficiaries are on file for this school year.</td></tr>
							@endforelse
							<tr id="raNoMatch" style="display:none;"><td colspan="5" class="table-empty">No learner matches this search.</td></tr>
						</tbody>
					</table>
				</div>
			</div>

			@if ($rows->isNotEmpty() && ! $sessionLocked && $isFeedingDay)
				{{-- A learner left unmarked is saved as nothing at all, never as an
				     absence, so the bar counts what is still undecided. --}}
				<div class="ra-savebar">
					<div class="ra-tally">
						<span class="badge badge-normal">Present <span data-tally="present">0</span></span>
						<span class="badge badge-critical">Absent <span data-tally="absent">0</span></span>
						<span class="badge badge-neutral">Unmarked <span data-tally="none">{{ $rows->count() }}</span></span>
					</div>
					<div class="ra-actions">
						<a class="btn btn-ghost" href="{{ route('dashboard.feedingcor-dashboard') }}">Cancel</a>
						<button type="submit" class="btn btn-primary" id="raSave">Save attendance</button>
					</div>
				</div>
			@endif
		</form>
	</div>
</div>
<script>
(() => {
	const form = document.getElementById('recordForm');
	if (!form) {
		return;
	}

	const rows = Array.from(form.querySelectorAll('tbody tr[data-search]'));
	const tallies = {
		present: form.querySelector('[data-tally="present"]'),
		absent: form.querySelector('[data-tally="absent"]'),
		none: form.querySelector('[data-tally="none"]'),
	};

	// A remark belongs to an absence. Marking a learner present clears and
	// closes theirs, so a stale reason can never survive the correction.
	const syncRemark = (row) => {
		const remark = row.querySelector('.ra-remark');
		if (!remark) return;
		const checked = row.querySelector('input[type="radio"]:checked');
		const isAbsent = checked !== null && checked.value === 'absent';
		remark.disabled = !isAbsent;
		if (!isAbsent) remark.value = '';
	};

	const retally = () => {
		let present = 0;
		let absent = 0;
		rows.forEach((row) => {
			const checked = row.querySelector('input[type="radio"]:checked');
			if (!checked) return;
			if (checked.value === 'present') present++;
			else absent++;
		});
		if (tallies.present) tallies.present.textContent = String(present);
		if (tallies.absent) tallies.absent.textContent = String(absent);
		if (tallies.none) tallies.none.textContent = String(rows.length - present - absent);
	};

	form.addEventListener('change', (event) => {
		if (!event.target.matches('input[type="radio"]')) return;
		syncRemark(event.target.closest('tr'));
		retally();
	});

	// Bulk buttons only touch rows the current search leaves visible, so
	// "All present" after filtering to one grade marks that grade alone.
	form.querySelectorAll('[data-mark-all]').forEach((button) => {
		button.addEventListener('click', () => {
			const value = button.dataset.markAll;
			rows.forEach((row) => {
				if (row.style.display === 'none') return;
				row.querySelectorAll('input[type="radio"]').forEach((input) => {
					input.checked = value !== '' && input.value === value;
				});
				syncRemark(row);
			});
			retally();
		});
	});

	const search = document.getElementById('raSearch');
	const noMatch = document.getElementById('raNoMatch');
	if (search) {
		search.addEventListener('input', () => {
			const term = search.value.trim().toLowerCase();
			let shown = 0;
			rows.forEach((row) => {
				const hit = term === '' || row.dataset.search.includes(term);
				row.style.display = hit ? '' : 'none';
				if (hit) shown++;
			});
			if (noMatch) noMatch.style.display = shown === 0 && rows.length > 0 ? '' : 'none';
		});
	}

	const dateInput = document.getElementById('sessionDate');
	if (dateInput) {
		dateInput.addEventListener('change', () => {
			if (!dateInput.value) return;
			window.location.href = dateInput.dataset.reloadUrl + '?date=' + encodeURIComponent(dateInput.value);
		});
	}

	retally();
})();
</script>
@include('partials.role-page-transition')
</body>
</html>
