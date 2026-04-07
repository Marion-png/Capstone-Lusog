<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<title>Feeding Head - Feeding Program</title>
		<link rel="preconnect" href="https://fonts.googleapis.com">
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
		<link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
		<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
		<script>document.documentElement.classList.add('js');</script>
	@php $pageCssPath = resource_path('css/feeding-feed-program.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
</head>
<body>
@php
	$activeRole = (string) session('active_role', '');
	$isReadOnly = (bool) ($isReadOnly ?? ($activeRole === 'school_nurse'));
	$programRouteName = $isReadOnly ? 'dashboard.school-nurse.feeding-program' : 'dashboard.feedingcor-program';
	$dashboardRouteName = $isReadOnly ? 'dashboard.school-nurse' : 'dashboard.feedingcor-dashboard';
	$healthRecordsRouteName = $isReadOnly ? 'dashboard.student-health-records' : 'dashboard.feedingcor-health-records';
	$displayName = trim((string) session('active_name', $isReadOnly ? 'School Nurse' : 'Feeding Coordinator'));
	$roleLabel = $isReadOnly ? 'School Nurse' : 'Feeding Coordinator';
@endphp
<aside class="sidebar">
	<div class="sb-grid"></div>
	<div class="sb-logo">
		<img src="{{ asset('images/lusog-logo.png') }}" alt="LUSOG Logo" class="sb-logo-full">
	</div>
	<nav class="sb-nav">
		<div class="sb-section-label">Main</div>
		<a href="{{ route($dashboardRouteName) }}" class="sb-link">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
			Dashboard
		</a>
		<a href="{{ route($healthRecordsRouteName) }}" class="sb-link">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
			{{ $isReadOnly ? 'Health Records' : 'Student Health Records' }}
		</a>
		@if ($isReadOnly)
		<a href="{{ route('dashboard.consultation-log') }}" class="sb-link">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4"/><path d="M21 12c0 4.97-4.03 9-9 9S3 16.97 3 12 7.03 3 12 3s9 4.03 9 9z"/></svg>
			Consultation Log
		</a>
		<div class="sb-section-label">Health Programs</div>
		@endif
		<a href="{{ route($programRouteName) }}" class="sb-link active">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
			Feeding Program
		</a>
		@if ($isReadOnly)
		<a href="{{ route('dashboard.school-nurse.deworming') }}" class="sb-link">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 3-7-3"/><path d="M3 9v6l7 3 7-3V9"/><polyline points="3 9 12 6 21 9"/></svg>
			Deworming Program
		</a>
		<div class="sb-section-label">Inventory</div>
		<a href="{{ route('dashboard.medicine-inventory') }}" class="sb-link">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="2" width="18" height="20" rx="2"/><path d="M9 2v4h6V2"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
			Medicine Inventory
		</a>
		<a href="#" class="sb-link">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
			Dispensing Log
		</a>
		<div class="sb-section-label">Reports</div>
		<a href="{{ route('dashboard.data-visualization') }}" class="sb-link">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
			Data Visualization
		</a>
		<a href="#" class="sb-link">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
			Generate Reports
		</a>
		@else
		<a href="{{ route('dashboard.feedingcor-sbfp-forms') }}" class="sb-link">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/><line x1="8" y1="9" x2="10" y2="9"/></svg>
			SBFP Forms
		</a>
		@endif
	</nav>
	<div class="sb-user">
		@php
			$initials = collect(preg_split('/\s+/', $displayName))
				->filter()
				->map(fn ($part) => strtoupper(substr($part, 0, 1)))
				->take(2)
				->implode('');
		@endphp
		<div class="sb-avatar">{{ $initials ?: 'FC' }}</div>
		<div class="sb-user-meta">
			<div class="sb-user-name">{{ $displayName }}</div>
			<div class="sb-user-role">{{ $roleLabel }}</div>
		</div>
		<form method="POST" action="{{ route('logout') }}">
			@csrf
			<button type="submit" class="sb-logout" title="Sign out">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
			</button>
		</form>
	</div>
</aside>

<div class="main">
	<header class="topbar">
		<div class="topbar-bc"><span>{{ $isReadOnly ? 'School Nurse' : 'Dashboard' }}</span><span>&gt;</span><span>Feeding Program</span></div>
	</header>

	<div class="content">
		@if (session('success'))
			<div class="flash ok">{{ session('success') }}</div>
		@endif
		@if (session('error'))
			<div class="flash err">{{ session('error') }}</div>
		@endif
		@if ($isReadOnly)
			<div class="flash">View-only mode: School Nurse can review feeding data but cannot submit attendance.</div>
		@endif

		<div class="head-row">
			<div>
				<div class="page-eyebrow">Feeding Program</div>
				<h1 class="page-title">Feeding <span>Program</span></h1>
				<p class="page-sub">120-Day Supplementary Feeding Program tracking.</p>
				@if ($hasSchoolColumn)
					<form method="GET" action="{{ route($programRouteName) }}" class="school-filter-form">
						<label for="schoolFilterSelect" class="school-filter-label">School:</label>
						<select id="schoolFilterSelect" name="school" class="school-filter-select" onchange="this.form.submit()">
							<option value="all" {{ ($selectedSchool ?? 'all') === 'all' ? 'selected' : '' }}>All Schools</option>
							@foreach (($schoolOptions ?? collect()) as $schoolOption)
								<option value="{{ $schoolOption }}" {{ ($selectedSchool ?? 'all') === $schoolOption ? 'selected' : '' }}>{{ $schoolOption }}</option>
							@endforeach
						</select>
					</form>
				@endif
			</div>
		</div>

		@if (($programStats['at_risk_count'] ?? 0) > 0)
			<div class="risk-alert">
				<div>
					<strong>{{ $programStats['at_risk_count'] }} at-risk beneficiaries detected</strong><br>
					<span>Attendance below {{ $programStats['at_risk_threshold'] ?? 75 }}% of expected sessions ({{ $programStats['at_risk_threshold_count'] ?? 0 }} required by current program day).</span>
				</div>
				<button type="button" class="btn btn-ghost" id="focusAtRiskBtn">Review List</button>
			</div>
		@endif

		<section class="stats">
			<article class="card stat">
				<div class="label">Enrolled Students</div>
				<div class="num" id="enrolledStudentsValue">{{ $programStats['enrolled_students'] ?? 0 }}</div>
			</article>
			<article class="card stat">
				<div class="label">Program Day</div>
				<div class="num">{{ $programStats['program_day'] ?? '0/120' }}</div>
			</article>
			<article class="card stat">
				<div class="label">Avg. Attendance</div>
				<div class="num" id="avgAttendanceValue">{{ $programStats['avg_attendance'] ?? '0%' }}</div>
			</article>
			<article class="card stat">
				<div class="label">Improving</div>
				<div class="num">{{ $programStats['improving_rate'] ?? '0%' }}</div>
				<div class="hint">{{ $programStats['improving_hint'] ?? '0 of 0 students' }}</div>
			</article>
		</section>

		<section class="card progress-card">
			@php
				$programDayValue = (int) explode('/', $programStats['program_day'] ?? '0/120')[0];
				$progressPercent = max(0, min(100, ($programDayValue / 120) * 100));
			@endphp
			<h2 class="section-title">Program Progress</h2>
			<div class="prog-track"><div class="prog-fill" style="width: {{ $progressPercent }}%;"></div><div class="prog-marker" style="left: {{ $progressPercent }}%;"></div></div>
			<div class="prog-day">Day {{ $programDayValue }} of 120</div>
			<div class="prog-labels"><span>Baseline (Day 1)</span><span>Endline(Day 120)</span></div>
		</section>

		<section class="risk-section" id="atRiskSection">
			<div class="risk-head">
				<h2 class="section-title">At-Risk Beneficiaries</h2>
				<select class="risk-filter" id="riskFilter" aria-label="Filter at-risk list by nutritional status">
					<option value="all">All Nutritional Status</option>
					<option value="severe">Severely Wasted</option>
					<option value="wasted">Wasted</option>
					<option value="normal">Normal</option>
					<option value="over">Overweight</option>
				</select>
			</div>
			<div class="table-card">
				<table id="riskTable">
					<thead>
						<tr>
							<th>Student</th>
							<th>Section</th>
							<th>Attendance</th>
							<th>Nutritional Status</th>
							<th>Risk Level</th>
						</tr>
					</thead>
					<tbody>
						@forelse (($atRiskStudents ?? collect()) as $student)
							@php
								$status = strtolower((string) ($student['nutritional_status'] ?? ''));
								$riskClass = ($student['attendance_percent'] ?? 0) < 50 ? 'risk-high' : 'risk-mid';
								$riskLabel = ($student['attendance_percent'] ?? 0) < 50 ? 'High' : 'Moderate';
							@endphp
							<tr data-risk-status="{{ $status }}">
								<td><strong>{{ $student['student_name'] }}</strong></td>
								<td>{{ $student['section'] }}</td>
								<td>{{ $student['attendance'] }} ({{ $student['attendance_percent'] }}%)</td>
								<td>{{ $student['nutritional_status'] }}</td>
								<td><span class="risk-pill {{ $riskClass }}">{{ $riskLabel }}</span></td>
							</tr>
						@empty
							<tr><td colspan="5">No at-risk beneficiaries right now.</td></tr>
						@endforelse
					</tbody>
				</table>
			</div>
		</section>

		@php
			$studentCollection = ($students ?? collect());
			$totalStudents = $studentCollection->count();
			$avgAttendanceNumeric = (float) preg_replace('/[^\d.]/', '', (string) ($programStats['avg_attendance'] ?? '0'));
			$presentEstimate = (int) round(($avgAttendanceNumeric / 100) * $totalStudents);
			$absentEstimate = max(0, $totalStudents - $presentEstimate);
			$lowAttendanceCount = $studentCollection->filter(fn ($student) => (float) ($student['attendance_percent'] ?? 0) < 70)->count();
		@endphp

		<section class="card section" style="margin-top: 14px;">
			<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px;">
				<h2 class="section-title" style="margin-bottom:0;">Today's Feeding Session</h2>
				<span class="muted">{{ now()->format('F d, Y') }}</span>
			</div>
			<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 12px;background:#f0fdf4;border:1px solid #dcfce7;border-radius:10px;">
				<div>
					<div class="student-name" style="font-size:.86rem;">Meal: Nutribun + Fortified Milk</div>
					<div class="muted" style="font-size:.7rem;">Served at 10:00 AM - Recess Time</div>
				</div>
				<div style="display:flex;gap:8px;align-items:center;">
					<span class="session-chip present">{{ $presentEstimate }} Present</span>
					<span class="session-chip absent">{{ $absentEstimate }} Absent</span>
					@if (!$isReadOnly)
					<button type="button" class="btn btn-primary session-action" id="recordAttendanceBtn" style="padding:7px 12px;">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px;margin-right:6px;"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
						Mark Attendance
					</button>
					@endif
				</div>
			</div>
		</section>

		<section class="table-section" style="margin-top:16px;">
			<h2 class="table-title">Feeding Program Beneficiaries</h2>
			<div class="table-card">
				<table>
					<thead>
						<tr>
							<th>Student Name</th>
							<th>Grade &amp; Section</th>
							<th>Baseline</th>
							<th>Current</th>
							<th>Weight Change</th>
							<th>Attendance</th>
							<th>Status</th>
						</tr>
					</thead>
					<tbody>
						@forelse ($studentCollection as $student)
							@php
								$baselineWeight = (float) ($student['baseline_weight'] ?? 0);
								$currentWeight = (float) ($student['current_weight'] ?? 0);
								$weightChange = round($currentWeight - $baselineWeight, 1);
								$attendancePercent = (float) ($student['attendance_percent'] ?? 0);
								$statusClass = $weightChange > 1 ? 't-improving' : ($weightChange < 0 ? 't-regressing' : 't-stable');
								$statusLabel = $weightChange > 1 ? 'improved' : ($weightChange < 0 ? 'declined' : 'no change');
							@endphp
							<tr>
								<td><div class="student-name">{{ $student['student_name'] ?? '-' }}</div></td>
								<td>{{ $student['section'] ?? '-' }}</td>
								<td>{{ number_format($baselineWeight, 1) }} kg</td>
								<td><strong>{{ number_format($currentWeight, 1) }} kg</strong></td>
								<td class="{{ $weightChange > 0 ? 'bmi-up' : ($weightChange < 0 ? 'bmi-down' : 'muted') }}">{{ $weightChange > 0 ? '+' : '' }}{{ number_format($weightChange, 1) }} kg</td>
								<td>{{ number_format($attendancePercent, 0) }}%</td>
								<td><span class="trend {{ $statusClass }}">{{ $statusLabel }}</span></td>
							</tr>
						@empty
							<tr><td colspan="7">No beneficiaries available.</td></tr>
						@endforelse
					</tbody>
				</table>
			</div>
		</section>

		@if ($lowAttendanceCount > 0)
			<div class="risk-alert" style="margin-top:16px;">
				<div>
					<strong>Follow-up Required</strong><br>
					<span>{{ $lowAttendanceCount }} students have attendance below 70%. Please coordinate with advisers and parents.</span>
				</div>
			</div>
		@endif
	</div>
</div>

<div class="modal-backdrop" id="weightsModalBackdrop" aria-hidden="true">
	<div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="weightsModalTitle">
		<div class="modal-head">
			<h2 id="weightsModalTitle" class="modal-title">Update Student Weights</h2>
			<button type="button" class="modal-close" id="closeWeightsModal" aria-label="Close">&times;</button>
		</div>
		<form id="weightsForm">
			<div class="modal-body">
				@forelse (($students ?? collect()) as $student)
					<div class="weight-item">
						<div class="weight-label">{{ $student['student_name'] }} <span>({{ $student['section'] }})</span></div>
						<div class="weight-field-wrap">
							<input type="number" class="weight-input" data-student="{{ $student['student_name'] }}" step="0.1" min="1" value="{{ $student['current_weight'] }}" required>
							<span class="weight-unit">kg</span>
						</div>
					</div>
				@empty
					<div class="weight-item"><div class="weight-label">No beneficiaries from master list.</div></div>
				@endforelse
			</div>
			<div class="modal-foot">
				<button type="button" class="btn btn-ghost" id="cancelWeightsModal">Cancel</button>
				<button type="submit" class="btn btn-primary">Save Weights</button>
			</div>
		</form>
	</div>
</div>

<div class="modal-backdrop" id="attendanceModalBackdrop" aria-hidden="true">
	<div class="modal-panel attendance-modal-panel" role="dialog" aria-modal="true" aria-labelledby="attendanceModalTitle">
		<div class="modal-head">
			<h2 id="attendanceModalTitle" class="modal-title">Record Attendance Session</h2>
			<button type="button" class="modal-close" id="closeAttendanceModal" aria-label="Close">&times;</button>
		</div>
		<form method="POST" action="{{ route('feedingcor-program.attendance.store') }}" id="attendanceForm">
			@csrf
			<input type="hidden" name="school" value="{{ $selectedSchool ?? 'all' }}">
			@php
				$attendanceStudents = ($students ?? collect())
					->filter(fn ($student) => (bool) ($student['is_attendance_eligible'] ?? false))
					->values();
			@endphp
			<div class="modal-body">
				<div class="attendance-legend" aria-label="Feeding attendance coding legend">
					<table>
						<thead>
							<tr>
								<th>B. Deworming</th>
								<th>D. Actual Feeding</th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td>(x) - not dewormed</td>
								<td>(H) - Present, served with Hot meals</td>
							</tr>
							<tr>
								<td>(/) - dewormed</td>
								<td>(M) - Present, served with Milk</td>
							</tr>
							<tr>
								<td>&nbsp;</td>
								<td>(H/M) - Present, served with Hot meals &amp; Milk</td>
							</tr>
							<tr>
								<td>&nbsp;</td>
								<td>(A) - Absent, not served</td>
							</tr>
							<tr>
								<td>&nbsp;</td>
								<td>(H2/M2)(H/M2) - Present, served twice</td>
							</tr>
						</tbody>
					</table>
				</div>
				<div class="weight-item">
					<div class="weight-label">Session Date</div>
					<div class="weight-field-wrap">
						<input type="date" name="session_date" class="weight-input" value="{{ now()->toDateString() }}" required>
					</div>
				</div>
				<div class="weight-item">
					<div class="weight-label">Mark Present Beneficiaries</div>
					<p class="attendance-rule-note">Only learners tagged as <strong>Wasted</strong>, <strong>Severely Wasted</strong>, or <strong>Underweight</strong> from Class Adviser BMI results are included in this attendance update.</p>
					@if ($attendanceStudents->isNotEmpty())
						<div class="attendance-tools">
							<button type="button" class="attendance-mini-btn present" id="markAllPresentBtn">Mark All Present</button>
							<button type="button" class="attendance-mini-btn absent" id="markAllAbsentBtn">Mark All Absent</button>
						</div>
					@endif
					@php
						$oldPresentIds = collect(old('present_student_ids', []))->map(fn ($value) => (string) $value)->all();
					@endphp
					<div class="attendance-list-wrap">
						@forelse ($attendanceStudents as $student)
							@php
								$isPresent = empty($oldPresentIds) ? true : in_array((string) $student['id'], $oldPresentIds, true);
								$oldDeworming = old('deworming_codes.' . $student['id'], '/');
								$oldFeeding = old('feeding_codes.' . $student['id'], $isPresent ? 'H' : 'A');
							@endphp
							<div class="attendance-row">
								<input type="checkbox" class="attendance-present-input" name="present_student_ids[]" value="{{ $student['id'] }}" id="present_{{ $student['id'] }}" {{ $isPresent ? 'checked' : '' }} hidden>
								<div>
									<div class="weight-label" style="font-size:.9rem;">{{ $student['student_name'] }}</div>
									<div class="attendance-meta">{{ $student['section'] }} · {{ $student['attendance'] }} ({{ $student['attendance_percent'] }}%) · BMI: {{ $student['nutritional_status'] }}</div>
								</div>
								<select name="deworming_codes[{{ $student['id'] }}]" class="attendance-inline-select" aria-label="Deworming status for {{ $student['student_name'] }}">
									<option value="/" {{ $oldDeworming === '/' ? 'selected' : '' }}>/</option>
									<option value="x" {{ $oldDeworming === 'x' ? 'selected' : '' }}>x</option>
								</select>
								<select name="feeding_codes[{{ $student['id'] }}]" class="attendance-inline-select" aria-label="Actual feeding code for {{ $student['student_name'] }}">
									<option value="H" {{ $oldFeeding === 'H' ? 'selected' : '' }}>H</option>
									<option value="M" {{ $oldFeeding === 'M' ? 'selected' : '' }}>M</option>
									<option value="H/M" {{ $oldFeeding === 'H/M' ? 'selected' : '' }}>H/M</option>
									<option value="A" {{ $oldFeeding === 'A' ? 'selected' : '' }}>A</option>
									<option value="H2/M2" {{ $oldFeeding === 'H2/M2' ? 'selected' : '' }}>H2/M2</option>
								</select>
								<div class="attendance-choice-group" aria-label="Attendance choice for {{ $student['student_name'] }}">
									<label class="attendance-choice-label"><input type="radio" class="attendance-choice" name="attendance_choice_{{ $student['id'] }}" value="present" {{ $isPresent ? 'checked' : '' }}> Present</label>
									<label class="attendance-choice-label"><input type="radio" class="attendance-choice" name="attendance_choice_{{ $student['id'] }}" value="absent" {{ $isPresent ? '' : 'checked' }}> Absent</label>
								</div>
							</div>
					@empty
						<div class="weight-label" style="font-size:.86rem;">No eligible learners found for this school. Only Wasted/Severely Wasted/Underweight BMI results can be recorded here.</div>
						@endforelse
					</div>
				</div>
			</div>
			<div class="modal-foot">
				<button type="button" class="btn btn-ghost" id="cancelAttendanceModal">Cancel</button>
				<button type="submit" class="btn btn-primary">Save Attendance</button>
			</div>
		</form>
	</div>
</div>

<div class="modal-backdrop" id="encodeFormBackdrop" aria-hidden="true">
	<div class="modal-panel encode-modal-panel" role="dialog" aria-modal="true" aria-labelledby="encodeFormTitle">
		<div class="modal-head">
			<h2 id="encodeFormTitle" class="modal-title">Encode SBFP Form</h2>
			<button type="button" class="modal-close" id="closeEncodeFormModal" aria-label="Close">&times;</button>
		</div>
		<form id="encodeFormForm">
			<div class="modal-body">
				<div class="weight-item">
					<div class="weight-label" id="encodeFormLabel">Form</div>
				</div>
				<p class="encode-help">Encode data directly using the official form columns below. Rows saved here are shown in Overview.</p>
				<div class="encode-toolbar">
					<div class="encode-toolbar-note" id="encodeToolbarNote">Simple View is on. Fill only key fields first.</div>
					<button type="button" class="mode-toggle" id="toggleEncodeModeBtn" aria-pressed="false">Show Full Template</button>
				</div>
				<div class="form1-meta" id="form1MetaSection">
					<div class="form1-meta-item"><label for="metaDivision">Division/Province:</label><input id="metaDivision" data-meta-field="division" type="text"></div>
					<div class="form1-meta-item"><label for="metaPrincipal">Name of Principal:</label><input id="metaPrincipal" data-meta-field="principal" type="text"></div>
					<div class="form1-meta-item"><label for="metaCity">City/Municipality/Barangay:</label><input id="metaCity" data-meta-field="city" type="text"></div>
					<div class="form1-meta-item"><label for="metaFocal">Name of Feeding Focal Person:</label><input id="metaFocal" data-meta-field="focal_person" type="text"></div>
					<div class="form1-meta-item"><label for="metaSchoolName">Name of School / School District:</label><input id="metaSchoolName" data-meta-field="school_name" type="text"></div>
					<div class="form1-meta-item"><label for="metaSchoolId">School ID Number:</label><input id="metaSchoolId" data-meta-field="school_id" type="text"></div>
				</div>
				<div class="encode-grid-wrap">
					<table class="encode-grid" aria-label="Encode SBFP rows">
						<thead id="encodeGridHead"></thead>
						<tbody id="encodeGridBody"></tbody>
					</table>
				</div>
				<button type="button" class="btn btn-ghost encode-add-row" id="addEncodeRowBtn">+ Add Row</button>
				<input type="hidden" id="encodeTemplateKey" value="form1">
			</div>
			<div class="modal-foot">
				<button type="button" class="btn btn-ghost" id="cancelEncodeFormModal">Cancel</button>
				<button type="submit" class="btn btn-primary">Save Encoded Rows</button>
			</div>
		</form>
	</div>
</div>

@php
	$improvingHintValue = $programStats['improving_hint'] ?? '0 of 0 students';
	$avgAttendanceValue = $programStats['avg_attendance'] ?? '0%';
	$todayLabelValue = now()->format('M d, Y');
	$programDayLabelValue = $programStats['program_day'] ?? '0/120';
	$liveStudentsPayload = ($students ?? collect())->map(function ($student) {
		return [
			'name' => $student['student_name'] ?? '',
			'section' => $student['section'] ?? '',
			'baseline' => $student['baseline_weight'] ?? '',
			'current' => $student['current_weight'] ?? '',
			'bmi' => $student['bmi_range'] ?? '',
			'attendance' => $student['attendance'] ?? '',
			'trend' => $student['trend_label'] ?? '',
		];
	})->values();
@endphp

<script>
(() => {
	const formsStorageKey = 'sbfp_forms_entries_v1';
	const formsRowsStorageKey = 'sbfp_forms_encoded_rows_v1';
	const formsMetaStorageKey = 'sbfp_forms_meta_v1';
	const form1AgeIndex = 6;
	const form1WeightIndex = 7;
	const form1HeightIndex = 8;
	const form1BmiIndex = 9;
	const form1BmiAStatusIndex = 10;
	const form1HfaStatusIndex = 11;
	const formsData = [
		{ key: 'form1', code: 'Form 1', title: 'Master List of Beneficiaries', category: 'beneficiary', description: 'Master list of SBFP beneficiaries with nutritional status, BMI, deworming, and 4Ps participation.' },
		{ key: 'form2', code: 'Form 2', title: 'List of Schools', category: 'beneficiary', description: 'List of participating schools with BEIS ID, address, principal information, and total beneficiaries.' },
		{ key: 'form3', code: 'Form 3', title: 'Summary of Beneficiaries & Start of Feeding', category: 'beneficiary', description: 'Summary of undernourished learners by grade level with start and end status of feeding.' },
		{ key: 'form4', code: 'Form 4', title: 'Record of Daily Feeding', category: 'feeding', description: 'Daily feeding attendance record over the feeding days with learner-level tracking.' },
		{ key: 'form5', code: 'Form 5', title: 'Consolidated Nutrition and Attendance', category: 'report', description: 'Consolidated before/after nutritional status and attendance percentage report.' },
		{ key: 'milk5', code: 'Milk Form 5', title: 'List of Authorized Consignees', category: 'milk', description: 'Authorized consignee details for milk deliveries including contact and signature.' },
		{ key: 'form6', code: 'Form 6', title: 'Milk Beneficiaries', category: 'milk', description: 'Milk component beneficiary list and intolerance classification.' },
		{ key: 'form7', code: 'Form 7 / 7-a', title: 'Milk Deliveries', category: 'milk', description: 'Milk deliveries and school/drop-off allocation records.' },
		{ key: 'form8', code: 'Form 8', title: 'Monthly / Quarterly Report', category: 'report', description: 'Implementation and financial status summary for SBFP reporting.' },
	];

	const main = document.querySelector('.main');
	const formsGrid = document.getElementById('formsGrid');
	const formsFilter = document.getElementById('formsFilter');
	const overviewTabBtn = document.getElementById('overviewTabBtn');
	const formsTabBtn = document.getElementById('formsTabBtn');
	const formsHub = document.getElementById('formsHub');
	const overviewSection = document.getElementById('overviewSection');
	const selectedFormTitle = document.getElementById('selectedFormTitle');
	const selectedFormHint = document.getElementById('selectedFormHint');
	const overviewTableHead = document.getElementById('overviewTableHead');
	const overviewTableBody = document.getElementById('overviewTableBody');
	const encodeFormBackdrop = document.getElementById('encodeFormBackdrop');
	const closeEncodeFormModal = document.getElementById('closeEncodeFormModal');
	const cancelEncodeFormModal = document.getElementById('cancelEncodeFormModal');
	const encodeFormForm = document.getElementById('encodeFormForm');
	const encodeFormLabel = document.getElementById('encodeFormLabel');
	const encodeTemplateKey = document.getElementById('encodeTemplateKey');
	const encodeGridHead = document.getElementById('encodeGridHead');
	const encodeGridBody = document.getElementById('encodeGridBody');
	const addEncodeRowBtn = document.getElementById('addEncodeRowBtn');
	const toggleEncodeModeBtn = document.getElementById('toggleEncodeModeBtn');
	const toggleOverviewModeBtn = document.getElementById('toggleOverviewModeBtn');
	const encodeToolbarNote = document.getElementById('encodeToolbarNote');
	const overviewToolbarNote = document.getElementById('overviewToolbarNote');
	const form1MetaSection = document.getElementById('form1MetaSection');
	const form1MetaInputs = Array.from(document.querySelectorAll('[data-meta-field]'));
	const openBtn = document.getElementById('openWeightsModal');
	const recordAttendanceBtn = document.getElementById('recordAttendanceBtn');
	const attendanceForm = document.getElementById('attendanceForm');
	const markAllPresentBtn = document.getElementById('markAllPresentBtn');
	const markAllAbsentBtn = document.getElementById('markAllAbsentBtn');
	const attendanceBackdrop = document.getElementById('attendanceModalBackdrop');
	const closeAttendanceModal = document.getElementById('closeAttendanceModal');
	const cancelAttendanceModal = document.getElementById('cancelAttendanceModal');
	const focusAtRiskBtn = document.getElementById('focusAtRiskBtn');
	const atRiskSection = document.getElementById('atRiskSection');
	const riskFilter = document.getElementById('riskFilter');
	const riskRows = Array.from(document.querySelectorAll('#riskTable tbody tr[data-risk-status]'));
	const backdrop = document.getElementById('weightsModalBackdrop');
	const closeBtn = document.getElementById('closeWeightsModal');
	const cancelBtn = document.getElementById('cancelWeightsModal');
	const form = document.getElementById('weightsForm');
	const weightCells = Array.from(document.querySelectorAll('.current-weight'));
	const inputs = Array.from(form ? form.querySelectorAll('.weight-input') : []);
	let currentViewedForm = '';
	let activeView = 'forms';
	const improvingHint = @json($improvingHintValue);
	const avgAttendance = @json($avgAttendanceValue);
	const todayLabel = @json($todayLabelValue);
	const programDayLabel = @json($programDayLabelValue);
	const liveStudents = @json($liveStudentsPayload);
	const enrolledStudentsValue = document.getElementById('enrolledStudentsValue');
	const avgAttendanceValue = document.getElementById('avgAttendanceValue');
	let simpleEncodeMode = true;
	let simpleOverviewMode = true;

	const simpleColumnMap = {
		form1: [0, 1, 2, 3, 6, 7, 8, 9, 10, 11],
		form2: [0, 1, 4],
		form3: [0, 1, 2],
		form4: [0, 1, 2, 3],
		form5: [0, 2, 4],
		milk5: [0, 1, 2],
		form6: [0, 1, 2],
		form7: [0, 1, 2, 5],
		form8: [0, 3, 5],
	};

	const baseStudents = liveStudents.map((student) => [student.name, student.section]);

	const overviewSchemas = {
		form1: {
			title: 'Form 1 - Master List of Beneficiaries',
			headers: [
				'No.',
				'Name',
				'Sex',
				'Grade/Section',
				'Date of Birth (MM/DD/YYYY)',
				'Date of Weighing / Measuring (MM/DD/YYYY)',
				'Age in Years / Months',
				'Weight (kg)',
				'Height (cm)',
				'BMI for 6 y.o. and above',
				'BMI-A',
				'HFA',
				'Dewormed? (yes or no)',
				"Parent's consent for milk? (yes or no)",
				'Participation in 4Ps (yes or no)',
				'Beneficiary of SBFP in Previous Years (yes or no)',
			],
			rows: liveStudents.map((student, index) => {
				const normalizedStatus = String(student.bmi || '').toLowerCase();
				let nutritionStatus = 'N';
				if (normalizedStatus.includes('severe')) {
					nutritionStatus = 'Severely Wasted';
				} else if (normalizedStatus.includes('wasted')) {
					nutritionStatus = 'Wasted';
				} else if (normalizedStatus.includes('normal')) {
					nutritionStatus = 'Normal';
				}

				return [
					String(index + 1),
					student.name,
					'',
					student.section,
					'',
					todayLabel,
					'',
					student.current,
					'',
					student.bmi,
					nutritionStatus,
					'',
					'',
					'',
					'',
					'',
				];
			}),
		},
		form2: {
			title: 'Form 2 - List of Schools',
			headers: ['Name of School', 'BEIS ID No.', 'School Address', 'Principal/OIC', 'Total Beneficiaries'],
			rows: [['DCNHS', '-', '-', '-', String(liveStudents.length)]],
		},
		form3: {
			title: 'Form 3 - Summary of Beneficiaries & Start of Feeding',
			headers: ['Category', 'Count', 'Notes'],
			rows: [
				['Undernourished Learners', String(liveStudents.length), 'Start of Feeding'],
				['4Ps Beneficiaries', '-', 'For validation'],
				['Repeaters', '-', 'From previous cycle'],
			],
		},
		form4: {
			title: 'Form 4 - Record of Daily Feeding',
			headers: ['Name of Pupil', 'Day 1', 'Day 2', 'Day 3', 'Day 4', 'Day 5', 'Day 6', 'Day 7', 'Day 8'],
			rows: baseStudents.map((item) => [item[0], '/', '/', '/', '/', '/', '/', '/', '/']),
		},
		form5: {
			title: 'Form 5 - Consolidated Nutrition and Attendance',
			headers: ['Grades & Sections', 'No. Dewormed', 'Before Status', 'After Status', '% Attendance'],
			rows: [['All Feeding Beneficiaries', '-', String(liveStudents.length), improvingHint, avgAttendance]],
		},
		milk5: {
			title: 'Milk Form 5 - Authorized Consignees',
			headers: ['Name & Designation', 'Tel. No.', 'Mobile No.', 'Email', 'Specimen Signature'],
			rows: [
				['School Head', '-', '-', '-', '-'],
				['Feeding Coordinator', '-', '-', '-', '-'],
				['Property Custodian', '-', '-', '-', '-'],
			],
		},
		form6: {
			title: 'Form 6 - Milk Beneficiaries',
			headers: ['Name', 'Grade & Section', 'Without Intolerance', 'With Intolerance but Willing', 'Not Allowed by Parents'],
			rows: baseStudents.map((item) => [item[0], item[1], '/', '-', '-']),
		},
		form7: {
			title: 'Form 7 / 7-a - Milk Deliveries',
			headers: ['Grade Level / School', 'No. of Beneficiaries', 'Date Delivered', 'Packs Received', 'Replacements', 'Remarks'],
			rows: [['All Sections', String(liveStudents.length), todayLabel, '-', '-', 'For encoding']],
		},
		form8: {
			title: 'Form 8 - Monthly / Quarterly Report',
			headers: ['Division / School', 'Target SBFP Schools', 'Actual SBFP Schools', 'Status of Implementation', 'Amount Allocated', 'Liquidation Status'],
			rows: [['DCNHS', '1', '1', `Ongoing (${programDayLabel})`, '-', 'For submission']],
		},
	};

	const getEffectiveForm1Rows = () => {
		const rowsByForm = getRowsByForm();
		const encodedRows = Array.isArray(rowsByForm.form1)
			? rowsByForm.form1.filter((row) => Array.isArray(row) && row.some((cell) => String(cell || '').trim() !== ''))
			: [];
		if (encodedRows.length > 0) {
			return encodedRows;
		}
		const defaultRows = overviewSchemas.form1 && Array.isArray(overviewSchemas.form1.rows)
			? overviewSchemas.form1.rows
			: [];
		return defaultRows;
	};

	const getBeneficiaryRowsFromForm1 = () => {
		return getEffectiveForm1Rows()
			.map((row) => ({
				name: String((Array.isArray(row) ? row[1] : '') || '').trim(),
				section: String((Array.isArray(row) ? row[3] : '') || '').trim(),
			}))
			.filter((student) => student.name !== '');
	};

	const getEnrolledStudentsCount = () => getBeneficiaryRowsFromForm1().length;

	const normalizeStudentKey = (value) => String(value || '').trim().toLowerCase();

	const buildLiveForm4Rows = (rowsByForm = getRowsByForm()) => {
		const headers = getTemplateHeaders('form4');
		const defaultRow = ['', '/', '/', '/', '/', '/', '/', '/', '/'];
		const existingRows = Array.isArray(rowsByForm.form4)
			? rowsByForm.form4.filter((row) => Array.isArray(row) && row.some((cell) => String(cell || '').trim() !== ''))
			: [];

		const existingByStudent = new Map();
		existingRows.forEach((row) => {
			const nameKey = normalizeStudentKey(Array.isArray(row) ? row[0] : '');
			if (!nameKey) {
				return;
			}
			existingByStudent.set(nameKey, row);
		});

		const beneficiaries = getBeneficiaryRowsFromForm1();
		if (beneficiaries.length === 0) {
			return existingRows;
		}

		return beneficiaries.map((student) => {
			const existing = existingByStudent.get(normalizeStudentKey(student.name));
			const row = Array.isArray(existing) ? [...existing] : [...defaultRow];
			row[0] = student.name;
			return headers.map((_, index) => (index < row.length ? row[index] : ''));
		});
	};

	const syncEnrolledStudentsValue = () => {
		if (!enrolledStudentsValue) {
			return;
		}
		enrolledStudentsValue.textContent = String(getEnrolledStudentsCount());
	};

	const toAttendanceToken = (value) => String(value || '').trim().toLowerCase();

	const calculateAttendancePercent = (form4Rows) => {
		const rows = Array.isArray(form4Rows) ? form4Rows : [];
		let presentCount = 0;
		let encodedCount = 0;

		rows.forEach((row) => {
			if (!Array.isArray(row)) {
				return;
			}
			for (let index = 1; index < row.length; index++) {
				const token = toAttendanceToken(row[index]);
				if (!token || token === '/' || token === '-') {
					continue;
				}
				encodedCount++;
				if (token === 'present' || token === 'p') {
					presentCount++;
				}
			}
		});

		if (encodedCount === 0) {
			return avgAttendance;
		}

		return `${Math.round((presentCount / encodedCount) * 100)}%`;
	};

	const getLiveAttendancePercent = (rowsByForm = getRowsByForm()) => {
		const form4Rows = buildLiveForm4Rows(rowsByForm);
		return calculateAttendancePercent(form4Rows);
	};

	const syncAvgAttendanceValue = (rowsByForm = getRowsByForm()) => {
		if (!avgAttendanceValue) {
			return;
		}
		avgAttendanceValue.textContent = getLiveAttendancePercent(rowsByForm);
	};

	const getEntries = () => {
		try {
			const parsed = JSON.parse(window.localStorage.getItem(formsStorageKey) || '{}');
			return parsed && typeof parsed === 'object' ? parsed : {};
		} catch (error) {
			return {};
		}
	};

	const setEntries = (value) => {
		window.localStorage.setItem(formsStorageKey, JSON.stringify(value));
	};

	const getRowsByForm = () => {
		try {
			const parsed = JSON.parse(window.localStorage.getItem(formsRowsStorageKey) || '{}');
			return parsed && typeof parsed === 'object' ? parsed : {};
		} catch (error) {
			return {};
		}
	};

	const setRowsByForm = (value) => {
		window.localStorage.setItem(formsRowsStorageKey, JSON.stringify(value));
	};

	const getMetaByForm = () => {
		try {
			const parsed = JSON.parse(window.localStorage.getItem(formsMetaStorageKey) || '{}');
			return parsed && typeof parsed === 'object' ? parsed : {};
		} catch (error) {
			return {};
		}
	};

	const setMetaByForm = (value) => {
		window.localStorage.setItem(formsMetaStorageKey, JSON.stringify(value));
	};

	const setForm1MetaVisibility = (visible) => {
		if (!form1MetaSection) {
			return;
		}
		form1MetaSection.classList.toggle('active', visible);
	};

	const loadFormMetaToInputs = (formKey) => {
		if (!form1MetaInputs.length) {
			return;
		}
		const metaByForm = getMetaByForm();
		const formMeta = metaByForm[formKey] && typeof metaByForm[formKey] === 'object' ? metaByForm[formKey] : {};
		form1MetaInputs.forEach((input) => {
			const key = input.dataset.metaField;
			input.value = key && formMeta[key] ? String(formMeta[key]) : '';
		});
	};

	const saveFormMetaFromInputs = (formKey) => {
		if (!form1MetaInputs.length) {
			return;
		}
		const metaByForm = getMetaByForm();
		const payload = {};
		form1MetaInputs.forEach((input) => {
			const key = input.dataset.metaField;
			if (!key) {
				return;
			}
			payload[key] = String(input.value || '').trim();
		});
		metaByForm[formKey] = payload;
		setMetaByForm(metaByForm);
	};

	const escapeHtml = (value) => String(value ?? '')
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#39;');

	const getTemplateHeaders = (formKey) => {
		const schema = overviewSchemas[formKey] || overviewSchemas.form1;
		return Array.isArray(schema.headers) ? schema.headers : [];
	};

	const buildForm1HeaderRows = (includeAction = false) => {
		const actionCell = includeAction ? '<th class="gov-head" rowspan="2">Action</th>' : '';
		return `
			<tr>
				<th class="gov-head" data-col-index="0" rowspan="2">No.</th>
				<th class="gov-head" data-col-index="1" rowspan="2">Name</th>
				<th class="gov-head" data-col-index="2" rowspan="2">Sex</th>
				<th class="gov-head" data-col-index="3" rowspan="2">Grade/ Section</th>
				<th class="gov-head" data-col-index="4" rowspan="2">Date of Birth<br>(MM/DD/YYYY)</th>
				<th class="gov-head" data-col-index="5" rowspan="2">Date of Weighing / Measuring<br>(MM/DD/YYYY)</th>
				<th class="gov-head" data-col-index="6" rowspan="2">Age in Years / Months</th>
				<th class="gov-head" data-col-index="7" rowspan="2">Weight<br>(Kg)</th>
				<th class="gov-head" data-col-index="8" rowspan="2">Height<br>(cm)</th>
				<th class="gov-head" data-col-index="9" rowspan="2">BMI for 6 y.o.<br>and above</th>
				<th class="gov-head" colspan="2">Nutritional Status (NS)</th>
				<th class="gov-head" data-col-index="12" rowspan="2">Dewormed?<br>(yes or no)</th>
				<th class="gov-head" data-col-index="13" rowspan="2">Parent's consent for milk?<br>(yes or no)</th>
				<th class="gov-head" data-col-index="14" rowspan="2">Participation in 4Ps<br>(yes or no)</th>
				<th class="gov-head" data-col-index="15" rowspan="2">Beneficiary of SBFP in Previous Years<br>(yes or no)</th>
				${actionCell}
			</tr>
			<tr>
				<th class="gov-head gov-subhead" data-col-index="10">BMI-A</th>
				<th class="gov-head gov-subhead" data-col-index="11">HFA</th>
			</tr>
		`;
	};

	const getVisibleColumns = (formKey, isSimpleMode) => {
		if (!isSimpleMode) {
			return null;
		}
		return simpleColumnMap[formKey] || null;
	};

	const applyTableColumnVisibility = (tableElement, formKey, isSimpleMode) => {
		if (!tableElement) {
			return;
		}

		const visibleColumns = getVisibleColumns(formKey, isSimpleMode);
		const visibleLookup = visibleColumns ? new Set(visibleColumns) : null;
		const nodes = Array.from(tableElement.querySelectorAll('[data-col-index]'));

		nodes.forEach((node) => {
			const index = Number(node.getAttribute('data-col-index'));
			const isVisible = !visibleLookup || visibleLookup.has(index);
			node.classList.toggle('column-hidden', !isVisible);
		});
	};

	const updateModeToggleLabels = () => {
		if (toggleEncodeModeBtn) {
			toggleEncodeModeBtn.textContent = simpleEncodeMode ? 'Show Full Template' : 'Show Simple View';
			toggleEncodeModeBtn.setAttribute('aria-pressed', String(!simpleEncodeMode));
		}
		if (toggleOverviewModeBtn) {
			toggleOverviewModeBtn.textContent = simpleOverviewMode ? 'Show Full Template' : 'Show Simple View';
			toggleOverviewModeBtn.setAttribute('aria-pressed', String(!simpleOverviewMode));
		}
		if (encodeToolbarNote) {
			encodeToolbarNote.textContent = simpleEncodeMode
				? 'Simple View is on. Fill only key fields first.'
				: 'Full template is visible. All official columns are available.';
		}
		if (overviewToolbarNote) {
			overviewToolbarNote.textContent = simpleOverviewMode
				? 'Simple View is on. Only key columns are shown.'
				: 'Full template is visible. All official columns are shown.';
		}
	};

	const buildEncodeHeader = (formKey, headers) => {
		if (!encodeGridHead) {
			return;
		}
		if (formKey === 'form1') {
			encodeGridHead.innerHTML = buildForm1HeaderRows(true);
			return;
		}
		encodeGridHead.innerHTML = `<tr>${headers.map((header, index) => `<th data-col-index="${index}">${escapeHtml(header)}</th>`).join('')}<th>Action</th></tr>`;
	};

	const buildEncodeRow = (formKey, headers, rowValues = []) => {
		const cells = headers.map((_, index) => {
			const value = escapeHtml(rowValues[index] || '');
			const isComputedBmi = formKey === 'form1' && index === form1BmiIndex;
			const isComputedStatus = formKey === 'form1' && index === form1BmiAStatusIndex;
			const isComputedHfa = formKey === 'form1' && index === form1HfaStatusIndex;
			const readonly = (isComputedBmi || isComputedStatus || isComputedHfa) ? ' readonly' : '';
			return `<td data-col-index="${index}"><input type="text" class="encode-cell-input" data-col-index="${index}" value="${value}"${readonly} /></td>`;
		});
		return `<tr>${cells.join('')}<td><div class="encode-row-actions"><button type="button" class="encode-inline-btn" data-remove-row="1">Remove</button></div></td></tr>`;
	};

	const computeBmi = (weightKg, heightCm) => {
		const weight = Number(weightKg);
		const height = Number(heightCm);
		if (Number.isNaN(weight) || Number.isNaN(height) || weight <= 0 || height <= 0) {
			return '';
		}
		const meters = height / 100;
		if (meters <= 0) {
			return '';
		}
		const bmi = weight / (meters * meters);
		return Number.isFinite(bmi) ? bmi.toFixed(2) : '';
	};

	const detectBmiAStatus = (bmiValue) => {
		const bmi = Number(bmiValue);
		if (Number.isNaN(bmi) || bmi <= 0) {
			return '';
		}
		if (bmi < 16) {
			return 'Severely Wasted';
		}
		if (bmi < 18.5) {
			return 'Wasted';
		}
		if (bmi < 25) {
			return 'Normal';
		}
		return 'Obese';
	};

	const parseAgeToYears = (ageRaw) => {
		const text = String(ageRaw || '').trim();
		if (!text) {
			return null;
		}
		const values = (text.match(/\d+(?:\.\d+)?/g) || []).map((value) => Number(value));
		if (!values.length || values.some((value) => Number.isNaN(value))) {
			return null;
		}
		if (values.length >= 2) {
			return values[0] + (values[1] / 12);
		}
		const single = values[0];
		if (single <= 30) {
			return single;
		}
		return single / 12;
	};

	const expectedHeightForAge = (ageYears) => {
		if (!Number.isFinite(ageYears) || ageYears <= 0) {
			return null;
		}
		if (ageYears <= 12) {
			return 77 + (6 * ageYears);
		}
		return 149 + (5 * (ageYears - 12));
	};

	const detectHfaStatus = (heightCm, ageRaw) => {
		const height = Number(heightCm);
		if (!Number.isFinite(height) || height <= 0) {
			return '';
		}
		const ageYears = parseAgeToYears(ageRaw);
		const expectedHeight = expectedHeightForAge(ageYears);
		if (!Number.isFinite(expectedHeight) || expectedHeight <= 0) {
			return '';
		}
		const ratio = height / expectedHeight;
		if (ratio < 0.90) {
			return 'Severe Stunting';
		}
		if (ratio < 0.95) {
			return 'Moderate Stunting';
		}
		return 'Normal';
	};

	const recalcForm1BmiInRow = (row) => {
		if (!row) {
			return;
		}
		const ageInput = row.querySelector(`.encode-cell-input[data-col-index="${form1AgeIndex}"]`);
		const weightInput = row.querySelector(`.encode-cell-input[data-col-index="${form1WeightIndex}"]`);
		const heightInput = row.querySelector(`.encode-cell-input[data-col-index="${form1HeightIndex}"]`);
		const bmiInput = row.querySelector(`.encode-cell-input[data-col-index="${form1BmiIndex}"]`);
		const statusInput = row.querySelector(`.encode-cell-input[data-col-index="${form1BmiAStatusIndex}"]`);
		const hfaInput = row.querySelector(`.encode-cell-input[data-col-index="${form1HfaStatusIndex}"]`);
		if (!ageInput || !weightInput || !heightInput || !bmiInput || !statusInput || !hfaInput) {
			return;
		}
		const bmi = computeBmi(weightInput.value, heightInput.value);
		bmiInput.value = bmi;
		statusInput.value = detectBmiAStatus(bmi);
		hfaInput.value = detectHfaStatus(heightInput.value, ageInput.value);
	};

	const recalcAllForm1Bmi = () => {
		if (!encodeGridBody) {
			return;
		}
		Array.from(encodeGridBody.querySelectorAll('tr')).forEach((row) => recalcForm1BmiInRow(row));
	};

	const renderEncodeRows = (formKey) => {
		if (!encodeGridBody) {
			return;
		}
		setForm1MetaVisibility(formKey === 'form1');
		if (formKey === 'form1') {
			loadFormMetaToInputs(formKey);
		}
		const headers = getTemplateHeaders(formKey);
		buildEncodeHeader(formKey, headers);
		const existingRowsByForm = getRowsByForm();
		const existingRows = Array.isArray(existingRowsByForm[formKey]) ? existingRowsByForm[formKey] : [];
		if (formKey === 'form4') {
			const liveRows = buildLiveForm4Rows(existingRowsByForm);
			if (liveRows.length > 0) {
				encodeGridBody.innerHTML = liveRows.map((row) => buildEncodeRow(formKey, headers, row)).join('');
				applyTableColumnVisibility(document.querySelector('.encode-grid'), formKey, simpleEncodeMode);
				return;
			}
		}
		if (existingRows.length > 0) {
			encodeGridBody.innerHTML = existingRows.map((row) => buildEncodeRow(formKey, headers, row)).join('');
			if (formKey === 'form1') {
				recalcAllForm1Bmi();
			}
			applyTableColumnVisibility(document.querySelector('.encode-grid'), formKey, simpleEncodeMode);
			return;
		}
		encodeGridBody.innerHTML = buildEncodeRow(formKey, headers);
		if (formKey === 'form1') {
			recalcAllForm1Bmi();
		}
		applyTableColumnVisibility(document.querySelector('.encode-grid'), formKey, simpleEncodeMode);
	};

	const appendEncodeRow = () => {
		if (!encodeGridBody || !encodeTemplateKey) {
			return;
		}
		const key = encodeTemplateKey.value || 'form1';
		const headers = getTemplateHeaders(key);
		encodeGridBody.insertAdjacentHTML('beforeend', buildEncodeRow(key, headers));
		if (key === 'form1') {
			recalcAllForm1Bmi();
		}
		applyTableColumnVisibility(document.querySelector('.encode-grid'), key, simpleEncodeMode);
	};

	const collectEncodedRows = (formKey) => {
		if (!encodeGridBody) {
			return [];
		}
		const headerCount = getTemplateHeaders(formKey).length;
		return Array.from(encodeGridBody.querySelectorAll('tr'))
			.map((row) => Array.from(row.querySelectorAll('.encode-cell-input')).slice(0, headerCount).map((input) => String(input.value || '').trim()))
			.filter((rowValues) => rowValues.some((value) => value !== ''));
	};

	const setModal = (target, open) => {
		if (!target) {
			return;
		}
		target.classList.toggle('open', open);
		const hasOpen = document.querySelectorAll('.modal-backdrop.open').length > 0;
		document.body.style.overflow = hasOpen ? 'hidden' : '';
	};

	const setActiveView = (view) => {
		const showOverview = view === 'overview';
		activeView = showOverview ? 'overview' : 'forms';
		if (overviewTabBtn) {
			overviewTabBtn.classList.toggle('active', showOverview);
			overviewTabBtn.classList.toggle('disabled', !showOverview);
		}
		if (formsTabBtn) {
			formsTabBtn.classList.toggle('active', !showOverview);
		}
		if (formsHub) {
			formsHub.classList.toggle('active', !showOverview);
		}
		if (overviewSection) {
			overviewSection.classList.toggle('active', showOverview);
		}
	};

	const switchViewSmooth = (targetView) => {
		if (targetView !== 'overview' && targetView !== 'forms') {
			return;
		}
		if (activeView === targetView) {
			return;
		}

		const currentPanel = activeView === 'overview' ? overviewSection : formsHub;
		const nextPanel = targetView === 'overview' ? overviewSection : formsHub;

		if (!currentPanel || !nextPanel) {
			setActiveView(targetView);
			return;
		}

		currentPanel.classList.add('panel-leave');
		window.setTimeout(() => {
			currentPanel.classList.remove('panel-leave');
			setActiveView(targetView);
			nextPanel.classList.add('panel-enter');
			requestAnimationFrame(() => {
				nextPanel.classList.remove('panel-enter');
			});
		}, 200);
	};

	const renderOverviewTable = (formKey) => {
		const schema = overviewSchemas[formKey] || overviewSchemas.form1;
		const encodedRowsByForm = getRowsByForm();
		const encodedRows = Array.isArray(encodedRowsByForm[formKey]) ? encodedRowsByForm[formKey] : null;
		const beneficiaryRows = getBeneficiaryRowsFromForm1();
		const enrolledCount = beneficiaryRows.length;
		let rowsToRender = encodedRows && encodedRows.length > 0 ? encodedRows : schema.rows;

		if ((!encodedRows || encodedRows.length === 0) && formKey === 'form2') {
			rowsToRender = [['DCNHS', '-', '-', '-', String(enrolledCount)]];
		}

		if ((!encodedRows || encodedRows.length === 0) && formKey === 'form3') {
			rowsToRender = [
				['Undernourished Learners', String(enrolledCount), 'Start of Feeding'],
				['4Ps Beneficiaries', '-', 'For validation'],
				['Repeaters', '-', 'From previous cycle'],
			];
		}

		if (formKey === 'form4') {
			rowsToRender = buildLiveForm4Rows(encodedRowsByForm);
		}

		if ((!encodedRows || encodedRows.length === 0) && formKey === 'form5') {
			rowsToRender = [['All Feeding Beneficiaries', '-', String(enrolledCount), improvingHint, getLiveAttendancePercent(encodedRowsByForm)]];
		}

		if ((!encodedRows || encodedRows.length === 0) && formKey === 'form6') {
			rowsToRender = beneficiaryRows.map((item) => [item.name, item.section, '/', '-', '-']);
		}

		if ((!encodedRows || encodedRows.length === 0) && formKey === 'form7') {
			rowsToRender = [['All Sections', String(enrolledCount), todayLabel, '-', '-', 'For encoding']];
		}

		if (selectedFormTitle) {
			selectedFormTitle.textContent = schema.title;
		}

		if (overviewTableHead) {
			overviewTableHead.innerHTML = formKey === 'form1'
				? buildForm1HeaderRows(false)
				: `<tr>${schema.headers.map((header, index) => `<th data-col-index="${index}">${escapeHtml(header)}</th>`).join('')}</tr>`;
		}

		if (overviewTableBody) {
			overviewTableBody.innerHTML = rowsToRender
				.map((row) => {
					const normalizedRow = schema.headers.map((_, index) => (Array.isArray(row) && index < row.length ? row[index] : ''));
					return `<tr>${normalizedRow.map((cell, index) => `<td data-col-index="${index}">${escapeHtml(cell)}</td>`).join('')}</tr>`;
				})
				.join('');
		}

		applyTableColumnVisibility(document.getElementById('overviewTable'), formKey, simpleOverviewMode);
	};

	const renderForms = () => {
		if (!formsGrid) {
			return;
		}

		const filter = formsFilter ? formsFilter.value : 'all';
		const filtered = filter === 'all' ? formsData : formsData.filter((item) => item.category === filter);

		if (filtered.length === 0) {
			formsGrid.innerHTML = '<div class="form-hint">No forms found for the selected filter.</div>';
			return;
		}

		formsGrid.innerHTML = filtered.map((item) => {
			return `
				<article class="form-card">
					<div class="form-card-head">
						<div>
							<div class="form-code">${item.code}</div>
							<h3 class="form-title">${item.title}</h3>
						</div>
					</div>
					<p class="form-desc">${item.description}</p>
					<div class="form-actions">
						<button type="button" class="form-btn" data-view-form="${item.key}">View</button>
						<button type="button" class="form-btn primary" data-encode-form="${item.key}">Encode</button>
					</div>
				</article>
			`;
		}).join('');
	};

	const viewForm = (formKey) => {
		const selected = formsData.find((item) => item.key === formKey);
		if (!selected) {
			return;
		}

		currentViewedForm = formKey;
		renderOverviewTable(formKey);
		switchViewSmooth('overview');
		if (selectedFormHint) {
			selectedFormHint.textContent = `Currently viewing ${selected.code}. Use Encode to add new entries.`;
		}

		const previewSection = overviewSection;
		if (previewSection) {
			previewSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}
	};

	const openEncodeForm = (formKey) => {
		const selected = formsData.find((item) => item.key === formKey);
		if (!selected) {
			return;
		}
		if (encodeTemplateKey) {
			encodeTemplateKey.value = selected.key;
		}
		if (encodeFormLabel) {
			encodeFormLabel.textContent = `${selected.code} - ${selected.title}`;
		}
		renderEncodeRows(selected.key);
		updateModeToggleLabels();
		setModal(encodeFormBackdrop, true);
	};

	if (main) {
		requestAnimationFrame(() => {
			main.classList.add('page-ready');
		});

		window.addEventListener('pageshow', () => {
			main.classList.add('page-ready');
		});

		document.querySelectorAll('.sb-link[href]').forEach((link) => {
			link.addEventListener('click', (event) => {
				const href = link.getAttribute('href');
				if (!href || link.classList.contains('active')) {
					return;
				}
				if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
					return;
				}

				event.preventDefault();
				main.classList.remove('page-ready');
				main.classList.add('page-exit');
				window.setTimeout(() => {
					window.location.href = href;
				}, 220);
			});
		});
	}

	if (formsFilter) {
		formsFilter.addEventListener('change', renderForms);
	}

	if (overviewTabBtn) {
		overviewTabBtn.addEventListener('click', () => {
			if (activeView === 'forms') {
				if (selectedFormHint) {
					selectedFormHint.textContent = 'Select a form first and click View to open it in Overview.';
				}
				return;
			}
			if (!currentViewedForm) {
				return;
			}
			renderOverviewTable(currentViewedForm);
			switchViewSmooth('overview');
		});
	}

	if (formsTabBtn) {
		formsTabBtn.addEventListener('click', () => {
			switchViewSmooth('forms');
			if (formsHub) {
				formsHub.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		});
	}

	if (formsGrid) {
		formsGrid.addEventListener('click', (event) => {
			const viewButton = event.target.closest('[data-view-form]');
			if (viewButton) {
				viewForm(viewButton.dataset.viewForm);
				return;
			}

			const encodeButton = event.target.closest('[data-encode-form]');
			if (encodeButton) {
				openEncodeForm(encodeButton.dataset.encodeForm);
			}
		});
	}

	if (toggleEncodeModeBtn) {
		toggleEncodeModeBtn.addEventListener('click', () => {
			simpleEncodeMode = !simpleEncodeMode;
			updateModeToggleLabels();
			const key = encodeTemplateKey ? encodeTemplateKey.value : 'form1';
			applyTableColumnVisibility(document.querySelector('.encode-grid'), key, simpleEncodeMode);
		});
	}

	if (toggleOverviewModeBtn) {
		toggleOverviewModeBtn.addEventListener('click', () => {
			simpleOverviewMode = !simpleOverviewMode;
			updateModeToggleLabels();
			const key = currentViewedForm || 'form1';
			applyTableColumnVisibility(document.getElementById('overviewTable'), key, simpleOverviewMode);
		});
	}

	if (recordAttendanceBtn) {
		recordAttendanceBtn.addEventListener('click', () => {
			if (attendanceBackdrop) {
				setModal(attendanceBackdrop, true);
			}
		});
	}

	const syncAttendanceHiddenInputs = () => {
		if (!attendanceForm) {
			return;
		}
		const rows = Array.from(attendanceForm.querySelectorAll('.attendance-row'));
		rows.forEach((row) => {
			const hiddenInput = row.querySelector('.attendance-present-input');
			const selectedChoice = row.querySelector('.attendance-choice:checked');
			if (!hiddenInput || !selectedChoice) {
				return;
			}
			hiddenInput.checked = selectedChoice.value === 'present';
		});
	};

	if (markAllPresentBtn) {
		markAllPresentBtn.addEventListener('click', () => {
			if (!attendanceForm) {
				return;
			}
			Array.from(attendanceForm.querySelectorAll('.attendance-choice[value="present"]')).forEach((radio) => {
				radio.checked = true;
			});
			syncAttendanceHiddenInputs();
		});
	}

	if (markAllAbsentBtn) {
		markAllAbsentBtn.addEventListener('click', () => {
			if (!attendanceForm) {
				return;
			}
			Array.from(attendanceForm.querySelectorAll('.attendance-choice[value="absent"]')).forEach((radio) => {
				radio.checked = true;
			});
			syncAttendanceHiddenInputs();
		});
	}

	if (attendanceForm) {
		attendanceForm.addEventListener('change', (event) => {
			if (event.target && event.target.classList && event.target.classList.contains('attendance-choice')) {
				syncAttendanceHiddenInputs();
			}
		});

		attendanceForm.addEventListener('submit', () => {
			syncAttendanceHiddenInputs();
		});

		syncAttendanceHiddenInputs();
	}

	if (closeAttendanceModal) {
		closeAttendanceModal.addEventListener('click', () => setModal(attendanceBackdrop, false));
	}

	if (cancelAttendanceModal) {
		cancelAttendanceModal.addEventListener('click', () => setModal(attendanceBackdrop, false));
	}

	if (attendanceBackdrop) {
		attendanceBackdrop.addEventListener('click', (event) => {
			if (event.target === attendanceBackdrop) {
				setModal(attendanceBackdrop, false);
			}
		});
	}

	if (focusAtRiskBtn && atRiskSection) {
		focusAtRiskBtn.addEventListener('click', () => {
			atRiskSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
		});
	}

	if (riskFilter && riskRows.length > 0) {
		riskFilter.addEventListener('change', () => {
			const selected = String(riskFilter.value || 'all').toLowerCase();
			riskRows.forEach((row) => {
				const rowStatus = String(row.getAttribute('data-risk-status') || '').toLowerCase();
				const visible = selected === 'all' || rowStatus.includes(selected);
				row.style.display = visible ? '' : 'none';
			});
		});
	}

	if (closeEncodeFormModal) {
		closeEncodeFormModal.addEventListener('click', () => setModal(encodeFormBackdrop, false));
	}

	if (cancelEncodeFormModal) {
		cancelEncodeFormModal.addEventListener('click', () => setModal(encodeFormBackdrop, false));
	}

	if (encodeFormForm) {
		encodeFormForm.addEventListener('submit', (event) => {
			event.preventDefault();
			const key = encodeTemplateKey ? encodeTemplateKey.value : '';
			if (!key) {
				return;
			}
			if (key === 'form1') {
				saveFormMetaFromInputs(key);
			}

			const encodedRows = collectEncodedRows(key);
			if (encodedRows.length === 0) {
				window.alert('Please encode at least one row before saving.');
				return;
			}

			const entries = getEntries();
			entries[key] = Number(entries[key] || 0) + encodedRows.length;
			setEntries(entries);

			const rowsByForm = getRowsByForm();
			rowsByForm[key] = encodedRows;
			if (key === 'form1') {
				rowsByForm.form4 = buildLiveForm4Rows(rowsByForm);
			}
			setRowsByForm(rowsByForm);
			syncEnrolledStudentsValue();
			syncAvgAttendanceValue(rowsByForm);

			renderForms();
			viewForm(key);
			setModal(encodeFormBackdrop, false);
			encodeFormForm.reset();
		});
	}

	if (addEncodeRowBtn) {
		addEncodeRowBtn.addEventListener('click', appendEncodeRow);
	}

	if (encodeGridBody) {
		encodeGridBody.addEventListener('input', (event) => {
			if (!encodeTemplateKey) {
				return;
			}
			const target = event.target.closest('.encode-cell-input');
			if (!target) {
				return;
			}

			if (encodeTemplateKey.value === 'form4') {
				const liveDraftRows = collectEncodedRows('form4');
				syncAvgAttendanceValue({ ...getRowsByForm(), form4: liveDraftRows });
				return;
			}

			if (encodeTemplateKey.value !== 'form1') {
				return;
			}
			const index = Number(target.dataset.colIndex);
			if (index !== form1AgeIndex && index !== form1WeightIndex && index !== form1HeightIndex) {
				return;
			}
			recalcForm1BmiInRow(target.closest('tr'));
		});

		encodeGridBody.addEventListener('click', (event) => {
			const removeButton = event.target.closest('[data-remove-row]');
			if (!removeButton) {
				return;
			}
			const row = removeButton.closest('tr');
			if (!row) {
				return;
			}
			const rowCount = encodeGridBody.querySelectorAll('tr').length;
			if (rowCount <= 1) {
				Array.from(row.querySelectorAll('.encode-cell-input')).forEach((input) => {
					input.value = '';
				});
				if (encodeTemplateKey && encodeTemplateKey.value === 'form4') {
					const liveDraftRows = collectEncodedRows('form4');
					syncAvgAttendanceValue({ ...getRowsByForm(), form4: liveDraftRows });
				}
				return;
			}
			row.remove();
			if (encodeTemplateKey && encodeTemplateKey.value === 'form4') {
				const liveDraftRows = collectEncodedRows('form4');
				syncAvgAttendanceValue({ ...getRowsByForm(), form4: liveDraftRows });
			}
		});
	}

	renderForms();
	setActiveView('forms');
	syncEnrolledStudentsValue();
	syncAvgAttendanceValue();
	updateModeToggleLabels();

	if (!openBtn || !backdrop || !closeBtn || !cancelBtn || !form) {
		return;
	}

	const tableWeightByStudent = () => {
		const map = new Map();
		weightCells.forEach((cell) => {
			const student = cell.dataset.student;
			const raw = (cell.textContent || '').trim();
			const parsed = parseFloat(raw.replace('kg', '').trim());
			if (student && !Number.isNaN(parsed)) {
				map.set(student, parsed);
			}
		});
		return map;
	};

	const syncInputsFromTable = () => {
		const weights = tableWeightByStudent();
		inputs.forEach((input) => {
			const student = input.dataset.student;
			if (!student || !weights.has(student)) {
				return;
			}
			input.value = Number(weights.get(student)).toFixed(1);
		});
	};

	const closeModal = () => {
		setModal(backdrop, false);
	};

	openBtn.addEventListener('click', () => {
		syncInputsFromTable();
		setModal(backdrop, true);
	});

	closeBtn.addEventListener('click', closeModal);
	cancelBtn.addEventListener('click', closeModal);

	backdrop.addEventListener('click', (event) => {
		if (event.target === backdrop) {
			closeModal();
		}
	});

	document.addEventListener('keydown', (event) => {
		if (event.key === 'Escape' && backdrop.classList.contains('open')) {
			closeModal();
		}
		if (event.key === 'Escape' && attendanceBackdrop && attendanceBackdrop.classList.contains('open')) {
			setModal(attendanceBackdrop, false);
		}
		if (event.key === 'Escape' && encodeFormBackdrop && encodeFormBackdrop.classList.contains('open')) {
			setModal(encodeFormBackdrop, false);
		}
	});

	form.addEventListener('submit', (event) => {
		event.preventDefault();

		inputs.forEach((input) => {
			const student = input.dataset.student;
			const nextValue = Number(input.value);
			if (Number.isNaN(nextValue)) {
				return;
			}
			const nextWeight = `${nextValue.toFixed(1)} kg`;
			const targetCell = weightCells.find((cell) => cell.dataset.student === student);
			if (targetCell) {
				targetCell.textContent = nextWeight;
			}
		});

		closeModal();
	});
})();
</script>
</body>
</html>
