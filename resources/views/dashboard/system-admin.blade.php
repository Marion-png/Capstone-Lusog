<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>Control Center - System Admin - SIGLA</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
	<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
	<script>document.documentElement.classList.add('js');</script>
	<style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/system-admin.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/role-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.system-admin-sidebar', ['active' => 'dashboard'])

@php
	$accountsCollection = collect($accounts ?? []);
	$pendingRequestsCollection = collect($pendingRequests ?? []);
	$requestHistoryCollection = collect($requestHistory ?? []);
	$institutionsCollection = collect($institutions ?? []);
	$classAdviserAccounts = $accountsCollection->where('role', 'class_adviser')->count();
	$classAdviserRequests = $pendingRequestsCollection->where('role', 'class_adviser')->count();

	// A school is on its own policy the moment any one setting is set; an
	// empty field follows the app default. Null-coalesced because each
	// column is selected only once its migration has run.
	$policyIsDefault = fn ($school): bool => $school->feeding_at_risk_threshold === null
		&& ($school->feeding_min_observation_days ?? null) === null
		&& ($school->feeding_at_risk_mode ?? null) === null
		&& ($school->feeding_absence_flag_days ?? null) === null
		&& ($school->feeding_absence_removal_days ?? null) === null
		&& ($school->feeding_cycle_days ?? null) === null;
	$schoolSetPolicies = $institutionsCollection->reject($policyIsDefault)->count();

	$roleLabel = [
		'school_nurse' => 'School Nurse',
		'clinic_staff' => 'Clinic Staff',
		'class_adviser' => 'Class Adviser',
		'school_head' => 'School Head',
		'feeding_coor' => 'Feeding Coordinator',
		'nutricor' => 'Nutritional Coordinator',
	];
	$schoolScopedRoles = ['school_nurse', 'clinic_staff', 'school_head', 'feeding_coor', 'nutricor'];

	// One rendering of the assignment cell for all three tables, so an
	// adviser's class and a nurse's school read the same way wherever
	// the row appears.
	$assignment = function (array $row) use ($schoolScopedRoles): string {
		$role = $row['role'] ?? '';
		$school = e($row['school_name'] ?? '-');
		if ($role === 'class_adviser') {
			$class = e($row['assigned_grade_level'] ?? '-').' / '.e($row['assigned_section'] ?? '-');
			return '<strong>'.$school.'</strong><span class="sa-cell-sub">'.$class.'</span>';
		}
		if (in_array($role, $schoolScopedRoles, true)) {
			return '<strong>'.$school.'</strong>';
		}
		return '<span class="muted">&mdash;</span>';
	};
	$when = fn ($value) => $value
		? \Illuminate\Support\Carbon::parse($value)->format('M d, Y h:i A')
		: '—';
@endphp

<div class="main">
	<header class="topbar">
		<div class="topbar-bc"><span>System Admin</span><span class="bc-sep">&rsaquo;</span><span>Control Center</span></div>
		@include('partials.live-clock')
	</header>

	<div class="content">
		<div class="content-inner">

		<div class="page-header sa-header">
			<div class="sa-headline">
				<h1 class="page-title">System Administrator <span>Control Center</span></h1>
				<p class="sa-meta">
					<span class="tnum">{{ number_format($accountsCollection->count()) }} {{ \Illuminate\Support\Str::plural('account', $accountsCollection->count()) }}</span>
					<span class="sa-sep">&middot;</span>
					<span class="tnum">{{ number_format($pendingRequestsCollection->count()) }} pending</span>
					<span class="sa-sep">&middot;</span>
					<span class="tnum">{{ number_format($institutionsCollection->count()) }} {{ \Illuminate\Support\Str::plural('school', $institutionsCollection->count()) }}</span>
				</p>
			</div>
			<div class="sa-actions">
				<a class="btn btn-secondary" href="{{ route('dashboard.system-admin.audit-logs') }}">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>
					Audit Trail
				</a>
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

		<section class="kpi-grid">
			<article class="card kpi accent-brand">
				<div class="kpi-top">
					<div class="kpi-label">Active Accounts</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
				</div>
				<div class="kpi-value">{{ number_format($accountsCollection->count()) }}</div>
				<div class="kpi-hint">{{ number_format($classAdviserAccounts) }} class {{ \Illuminate\Support\Str::plural('adviser', $classAdviserAccounts) }}</div>
			</article>
			<article class="card kpi {{ $pendingRequestsCollection->isNotEmpty() ? 'accent-amber' : 'accent-success' }}">
				<div class="kpi-top">
					<div class="kpi-label">Pending Approvals</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg></div>
				</div>
				<div class="kpi-value">{{ number_format($pendingRequestsCollection->count()) }}</div>
				<div class="kpi-hint">{{ number_format($classAdviserRequests) }} from class advisers</div>
			</article>
			<article class="card kpi accent-info">
				<div class="kpi-top">
					<div class="kpi-label">Schools on File</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><path d="M9 9v.01"/><path d="M9 12v.01"/><path d="M9 15v.01"/><path d="M9 18v.01"/></svg></div>
				</div>
				<div class="kpi-value">{{ number_format($institutionsCollection->count()) }}</div>
				<div class="kpi-hint">{{ number_format($schoolSetPolicies) }} with a school-set policy</div>
			</article>
			<article class="card kpi accent-brand">
				<div class="kpi-top">
					<div class="kpi-label">Requests Processed</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="m9 15 2 2 4-4"/></svg></div>
				</div>
				<div class="kpi-value">{{ number_format($requestHistoryCollection->count()) }}</div>
				<div class="kpi-hint">{{ number_format($requestHistoryCollection->where('status', 'accepted')->count()) }} accepted</div>
			</article>
		</section>

		<section class="sa-block" id="requests">
			<div class="section-head">
				<h2 class="section-title">Incoming Account Requests</h2>
				<span class="section-meta tnum">{{ number_format($pendingRequestsCollection->count()) }} pending</span>
			</div>
			<div class="table-card">
				<div class="table-scroll">
					<table>
						<thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Assignment</th><th>Submitted</th><th>Action</th></tr></thead>
						<tbody>
							@forelse($pendingRequestsCollection as $request)
								<tr>
									<td><strong>{{ $request['name'] ?? '-' }}</strong></td>
									<td class="sa-cell-mono">{{ $request['username'] ?? '-' }}</td>
									<td>{{ $roleLabel[$request['role'] ?? ''] ?? ($request['role'] ?? '-') }}</td>
									<td>{!! $assignment($request) !!}</td>
									<td class="sa-nowrap tnum">{{ $when($request['created_at'] ?? null) }}</td>
									<td>
										<div class="sa-row-actions">
											<form method="POST" action="{{ route('dashboard.system-admin.requests.approve', $request['id']) }}">
												@csrf
												<button type="submit" class="btn btn-primary btn-sm">Approve</button>
											</form>
											<form method="POST" action="{{ route('dashboard.system-admin.requests.decline', $request['id']) }}">
												@csrf
												<button type="submit" class="btn btn-secondary btn-sm is-danger">Decline</button>
											</form>
										</div>
									</td>
								</tr>
							@empty
								<tr><td colspan="6" class="table-empty">No pending account requests.</td></tr>
							@endforelse
						</tbody>
					</table>
				</div>
			</div>
		</section>

		<section class="sa-block" id="accounts">
			<div class="section-head">
				<h2 class="section-title">User and Role Management</h2>
				<span class="section-meta tnum">{{ number_format($accountsCollection->count()) }} {{ \Illuminate\Support\Str::plural('account', $accountsCollection->count()) }}</span>
			</div>
			<div class="table-card">
				<div class="table-scroll">
					<table>
						<thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Assignment</th><th>Status</th></tr></thead>
						<tbody>
							@forelse($accountsCollection as $account)
								<tr>
									<td><strong>{{ $account['name'] ?? '-' }}</strong></td>
									<td class="sa-cell-mono">{{ $account['username'] ?? '-' }}</td>
									<td>{{ $roleLabel[$account['role'] ?? ''] ?? ($account['role'] ?? '-') }}</td>
									<td>{!! $assignment($account) !!}</td>
									<td><span class="badge badge-normal">Active</span></td>
								</tr>
							@empty
								<tr><td colspan="5" class="table-empty">No created accounts yet.</td></tr>
							@endforelse
						</tbody>
					</table>
				</div>
			</div>
		</section>

		<section class="sa-block" id="feeding-policy">
			<div class="section-head">
				<h2 class="section-title">Feeding Program Policy</h2>
				<span class="section-meta tnum">{{ number_format($institutionsCollection->count()) }} {{ \Illuminate\Support\Str::plural('school', $institutionsCollection->count()) }}</span>
			</div>
			{{-- School-configurable by requirement, and by field work: the
			     rule at Sta. Ana is not a percentage of the cycle at all —
			     a beneficiary there is flagged after one week of unexcused
			     absence, and after roughly a second week, once the class
			     adviser has confirmed the learner is not returning, the
			     place is released to somebody on the waiting list. So the
			     KIND of rule is settable here, not only the figure it is
			     set to.

			     An empty field means the school follows the app default, so
			     it moves with the programme instead of being pinned to
			     whatever today's number happens to be. --}}
			<div class="sa-policy-list">
				@forelse($institutionsCollection as $school)
					@php
						$isDefault = $policyIsDefault($school);
						$fid = 'policy-'.$school->id;
					@endphp
					<article class="card sa-policy">
						{{-- One form per school, wholly inside its own card. --}}
						<form method="POST" action="{{ route('dashboard.system-admin.institutions.at-risk-threshold', $school->id) }}">
							@csrf
							<div class="sa-policy-head">
								<div class="sa-policy-school">{{ $school->name }}</div>
								@if ($isDefault)
									<span class="badge badge-neutral">App default</span>
								@else
									<span class="badge badge-normal">School-set</span>
								@endif
							</div>

							<div class="sa-policy-grid">
								{{-- Which rule this school runs. The list is built from
								     FeedingAtRiskRule::MODES, so a rule added to the class
								     cannot go missing from this form. --}}
								<div class="sa-field sa-field-wide">
									<label class="field-label" for="{{ $fid }}-mode">At-risk rule</label>
									<select class="select" id="{{ $fid }}-mode" name="at_risk_mode" aria-label="At-risk rule for {{ $school->name }}">
										<option value="">App default &mdash; {{ ($atRiskModes ?? [])[$defaultAtRiskMode ?? ''] ?? 'attendance rate' }}</option>
										@foreach (($atRiskModes ?? []) as $modeValue => $modeLabel)
											<option value="{{ $modeValue }}" @selected(($school->feeding_at_risk_mode ?? null) === $modeValue)>{{ $modeLabel }}</option>
										@endforeach
									</select>
								</div>

								<div class="sa-field">
									<label class="field-label" for="{{ $fid }}-threshold">Attendance threshold</label>
									<div class="sa-unit">
										<input type="number" id="{{ $fid }}-threshold" name="threshold" min="1" max="100" step="1"
											value="{{ $school->feeding_at_risk_threshold }}"
											placeholder="{{ (int) ($defaultAtRiskThreshold ?? 80) }}"
											aria-label="At-risk attendance threshold for {{ $school->name }}">
										<span>%</span>
									</div>
								</div>

								<div class="sa-field">
									<label class="field-label" for="{{ $fid }}-observe">Observation period</label>
									<div class="sa-unit">
										<input type="number" id="{{ $fid }}-observe" name="minimum_observation_days" min="0" max="120" step="1"
											value="{{ $school->feeding_min_observation_days ?? null }}"
											placeholder="{{ (int) ($defaultMinObservationDays ?? 10) }}"
											aria-label="Minimum observation period in feeding days for {{ $school->name }}">
										<span>feeding days</span>
									</div>
								</div>

								<div class="sa-field">
									<label class="field-label" for="{{ $fid }}-flag">Flag after</label>
									<div class="sa-unit">
										<input type="number" id="{{ $fid }}-flag" name="absence_flag_days" min="1" max="60" step="1"
											value="{{ $school->feeding_absence_flag_days ?? null }}"
											placeholder="{{ (int) ($defaultAbsenceFlagDays ?? 4) }}"
											aria-label="Unexcused absences before flagging for {{ $school->name }}">
										<span>absences</span>
									</div>
								</div>

								<div class="sa-field">
									<label class="field-label" for="{{ $fid }}-removal">Removal review after</label>
									<div class="sa-unit">
										<input type="number" id="{{ $fid }}-removal" name="absence_removal_days" min="1" max="120" step="1"
											value="{{ $school->feeding_absence_removal_days ?? null }}"
											placeholder="{{ (int) ($defaultAbsenceRemovalDays ?? 8) }}"
											aria-label="Unexcused absences before removal review for {{ $school->name }}">
										<span>absences</span>
									</div>
								</div>

								{{-- 120 in Division policy, 90 under discussion. A cycle
								     length compiled into the application is one a school
								     cannot correct when its Division settles the question. --}}
								<div class="sa-field">
									<label class="field-label" for="{{ $fid }}-cycle">Cycle length</label>
									<div class="sa-unit">
										<input type="number" id="{{ $fid }}-cycle" name="cycle_days" min="1" max="365" step="1"
											value="{{ $school->feeding_cycle_days ?? null }}"
											placeholder="{{ (int) ($defaultCycleDays ?? 120) }}"
											aria-label="Feeding cycle length for {{ $school->name }}">
										<span>feeding days</span>
									</div>
								</div>
							</div>

							<div class="sa-policy-foot">
								<button type="submit" class="btn btn-primary">Save Policy</button>
							</div>
						</form>
					</article>
				@empty
					<div class="empty-panel">No schools on file.</div>
				@endforelse
			</div>
		</section>

		<section class="sa-block" id="account-history">
			<div class="section-head">
				<h2 class="section-title">Account Request History</h2>
				<span class="section-meta tnum">{{ number_format($requestHistoryCollection->count()) }} processed</span>
			</div>
			<div class="table-card">
				<div class="table-scroll">
					<table>
						<thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Assignment</th><th>Decision</th><th>Submitted</th><th>Processed</th></tr></thead>
						<tbody>
							@forelse($requestHistoryCollection as $history)
								<tr>
									<td><strong>{{ $history['name'] ?? '-' }}</strong></td>
									<td class="sa-cell-mono">{{ $history['username'] ?? '-' }}</td>
									<td>{{ $roleLabel[$history['role'] ?? ''] ?? ($history['role'] ?? '-') }}</td>
									<td>{!! $assignment($history) !!}</td>
									<td>
										@if (($history['status'] ?? '') === 'accepted')
											<span class="badge badge-normal">Accepted</span>
										@else
											<span class="badge badge-critical">Declined</span>
										@endif
									</td>
									<td class="sa-nowrap tnum">{{ $when($history['submitted_at'] ?? null) }}</td>
									<td class="sa-nowrap tnum">{{ $when($history['decided_at'] ?? null) }}</td>
								</tr>
							@empty
								<tr><td colspan="7" class="table-empty">No processed account requests yet.</td></tr>
							@endforelse
						</tbody>
					</table>
				</div>
			</div>
		</section>

		</div>
	</div>
</div>
@include('partials.role-page-transition')
</body>
</html>
