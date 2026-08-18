<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>Consent Compliance - School Head - SIGLA</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
	<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
	<script>document.documentElement.classList.add('js');</script>
	<style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/schoolhead.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/role-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.schoolhead-sidebar', ['active' => 'consent'])

@php
	$shYearLabel = str_replace('-', '&ndash;', e($schoolYear));
	$shPct = fn ($value) => $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 1), '0'), '.') . '%';
	$shStateLabel = fn (string $state) => \App\Support\SchoolHeadHealthOverview::consentLabel($state);
	$shStateBadge = fn (string $state) => match ($state) {
		'declined' => 'badge-critical',
		'awaiting' => 'badge-monitor',
		'valid' => 'badge-normal',
		default => 'badge-neutral',
	};
@endphp

<div class="main">
	<header class="topbar">
		<div class="topbar-bc"><span>School Head</span><span class="bc-sep">&rsaquo;</span><span>Consent Compliance</span></div>
		@include('partials.live-clock')
	</header>

	<div class="content">
		<div class="content-inner">

		<div class="page-header sh-header">
			<div class="sh-headline">
				<div class="sh-title-row">
					<h1 class="page-title">Health Services <span>Consent Compliance</span></h1>
					<span class="sh-year tnum">S.Y. {!! $shYearLabel !!}</span>
				</div>
				<p class="sh-meta">
					<span>{{ $schoolName }}</span>
					<span class="sh-sep">&middot;</span>
					<span class="tnum">{{ number_format($consent['valid']) }} of {{ number_format($consent['required']) }} on file</span>
					<span class="sh-sep">&middot;</span>
					<span class="tnum">{{ $shPct($consent['rate']) }} complete</span>
				</p>
			</div>
			<div class="sh-actions">
				<a class="btn btn-secondary" href="{{ route('dashboard.school-head.consent.export', request()->query()) }}">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
					Export
				</a>
				<button type="button" class="btn btn-secondary" id="shPrint">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
					Print
				</button>
			</div>
		</div>

		<div class="print-masthead" aria-hidden="true">
			<h2>Health Services Consent &mdash; Compliance</h2>
			<p>{{ $schoolName }} &middot; S.Y. {{ $schoolYear }}</p>
			<p>Printed {{ $todayLabel }}</p>
		</div>

		<section class="kpi-grid">
			<article class="card kpi accent-brand">
				<div class="kpi-top">
					<div class="kpi-label">Students Requiring Consent</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
				</div>
				<div class="kpi-value">{{ number_format($consent['required']) }}</div>
				<div class="kpi-hint">On the roll this school year</div>
			</article>
			<article class="card kpi accent-success">
				<div class="kpi-top">
					<div class="kpi-label">Valid Consent</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg></div>
				</div>
				<div class="kpi-value">{{ number_format($consent['valid']) }}</div>
				<div class="kpi-hint">{{ $shPct($consent['rate']) }} completion</div>
			</article>
			<article class="card kpi accent-orange">
				<div class="kpi-top">
					<div class="kpi-label">Missing Consent</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg></div>
				</div>
				<div class="kpi-value">{{ number_format($consent['missing']) }}</div>
				<div class="kpi-hint">{{ number_format($consent['none']) }} with no form on file</div>
			</article>
			<article class="card kpi accent-info">
				<div class="kpi-top">
					<div class="kpi-label">Awaiting Parent</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
				</div>
				<div class="kpi-value">{{ number_format($consent['awaiting']) }}</div>
				<div class="kpi-hint">{{ number_format($consent['declined']) }} refused</div>
			</article>
		</section>

		<form method="GET" class="card sh-toolbar" id="shToolbar">
			<div class="sh-filter">
				<label class="field-label" for="csYear">School year</label>
				<select class="select" name="school_year" id="csYear">
					@foreach ($schoolYears as $year)
						<option value="{{ $year }}" @selected($filters['school_year'] === $year)>{{ $year }}</option>
					@endforeach
				</select>
			</div>
			<div class="sh-filter">
				<label class="field-label" for="csGrade">Grade</label>
				<select class="select" name="grade" id="csGrade">
					<option value="">All grades</option>
					@foreach ($filterOptions['grades'] as $grade)
						<option value="{{ $grade }}" @selected($filters['grade'] === $grade)>{{ $grade }}</option>
					@endforeach
				</select>
			</div>
			<div class="sh-filter">
				<label class="field-label" for="csSection">Section</label>
				<select class="select" name="section" id="csSection">
					<option value="">All sections</option>
					@foreach ($filterOptions['sections'] as $section)
						<option value="{{ $section }}" @selected($filters['section'] === $section)>{{ $section }}</option>
					@endforeach
				</select>
			</div>
			<div class="sh-filter">
				<label class="field-label" for="csState">Standing</label>
				<select class="select" name="state" id="csState">
					<option value="">All outstanding</option>
					@foreach ($states as $state)
						@continue($state === 'valid')
						<option value="{{ $state }}" @selected($filters['state'] === $state)>{{ $shStateLabel($state) }}</option>
					@endforeach
				</select>
			</div>
			{{-- The health service the head is asking about. It narrows the
			     consented list below — "show me who I may deworm" — and leaves
			     the outstanding list, which is about forms rather than
			     services, alone. --}}
			<div class="sh-filter">
				<label class="field-label" for="csService">Health service</label>
				<select class="select" name="service" id="csService">
					<option value="">All services</option>
					@foreach ($serviceLabels as $key => $label)
						<option value="{{ $key }}" @selected($filters['service'] === $key)>
							{{ \Illuminate\Support\Str::limit($label, 60) }} ({{ $serviceCounts[$key] ?? 0 }})
						</option>
					@endforeach
				</select>
			</div>
			<noscript><button type="submit" class="btn btn-secondary">Apply</button></noscript>
		</form>

		<section class="card section">
			<div class="section-head">
				<h2 class="section-title">Completion by Section</h2>
			</div>
			@if (empty($consent['sections']))
				<div class="sh-empty">No learner on the roll for this school year.</div>
			@else
				<div class="table-card">
					<table class="sh-table">
						<thead>
							<tr>
								<th>Grade</th>
								<th>Section</th>
								<th class="num">On roll</th>
								<th class="num">Valid</th>
								<th class="num">Missing</th>
								<th>Completion</th>
							</tr>
						</thead>
						<tbody>
							@foreach ($consent['sections'] as $row)
								<tr>
									<td>{{ $row['grade'] }}</td>
									<td>{{ $row['section'] }}</td>
									<td class="num tnum">{{ number_format($row['required']) }}</td>
									<td class="num tnum">{{ number_format($row['valid']) }}</td>
									<td class="num tnum">{{ number_format($row['missing']) }}</td>
									<td>
										<span class="sh-bar-cell">
											<span class="sh-bar"><span class="sh-bar-fill" style="width: {{ $row['rate'] ?? 0 }}%; background: var(--series-healthy);"></span></span>
											<span class="sh-bar-value tnum">{{ $shPct($row['rate']) }}</span>
										</span>
									</td>
								</tr>
							@endforeach
						</tbody>
					</table>
				</div>
			@endif
		</section>

		{{-- ── Who the school may give a service to ──────────────────────
		     The compliance question answered the other way round: not who is
		     outstanding, but who a parent has actually authorised, for which
		     service. Filtering to Deworming lists the learners whose parent
		     consented to it, and each row opens that parent's own signed
		     letter — the head checking a named learner's authority, which is a
		     different act from reading a monitoring table and is audited as
		     one.

		     Standing and service only. The allergies, the write-in exceptions
		     and the parent's signature stay off this table; the letter itself
		     is where they belong, behind a deliberate click. ── --}}
		<section class="table-card sh-listcard">
			<div class="sh-listhead">
				<div>
					<h2 class="card-title">
						@if ($service !== '')
							Consented to {{ \Illuminate\Support\Str::limit($serviceLabel, 48) }}
						@else
							Learners With Valid Consent
						@endif
					</h2>
					<p class="card-sub tnum">
						{{ number_format($grantedRows->count()) }} {{ \Illuminate\Support\Str::plural('learner', $grantedRows->count()) }}
						@if ($service !== '') &middot; parent answered and did not refuse @endif
					</p>
				</div>
			</div>

			@if ($grantedRows->isEmpty())
				<p class="table-empty">
					@if ($service !== '')
						No learner in this scope has a parent&rsquo;s consent for that service.
					@else
						No learner in this scope has a valid consent on file.
					@endif
				</p>
			@else
				<div class="table-scroll">
					<table class="sh-table">
						<thead>
							<tr>
								<th class="num">#</th>
								<th>LRN</th>
								<th>Name</th>
								<th>Grade</th>
								<th>Section</th>
								<th>Standing</th>
								<th>Signed form</th>
							</tr>
						</thead>
						<tbody>
							@foreach ($grantedRows as $index => $row)
								<tr>
									<td class="num sh-index tnum">{{ $index + 1 }}</td>
									<td class="tnum">{{ $row['lrn'] }}</td>
									<td><strong>{{ $row['name'] }}</strong></td>
									<td>{{ $row['grade'] }}</td>
									<td>{{ $row['section'] }}</td>
									<td><span class="badge badge-normal">Valid consent</span></td>
									<td>
										@if ($row['form_id'] > 0)
											<a href="{{ route('consent-forms.head-show', $row['form_id']) }}">View signed form</a>
										@else
											<span class="sh-none">&mdash;</span>
										@endif
									</td>
								</tr>
							@endforeach
						</tbody>
					</table>
				</div>
			@endif
		</section>

		<section class="table-card sh-listcard">
			<div class="sh-listhead">
				<div>
					<h2 class="card-title">Students Without Valid Consent</h2>
					<p class="card-sub tnum">{{ number_format($shown) }} {{ \Illuminate\Support\Str::plural('learner', $shown) }}</p>
				</div>
			</div>

			@if ($rows->isEmpty())
				<p class="table-empty">Every learner in this scope has a valid consent on file.</p>
			@else
				<div class="table-scroll">
					<table class="sh-table">
						<thead>
							<tr>
								<th class="num">#</th>
								<th>LRN</th>
								<th>Name</th>
								<th>Grade</th>
								<th>Section</th>
								<th>Standing</th>
							</tr>
						</thead>
						<tbody>
							@foreach ($rows as $index => $row)
								<tr>
									<td class="num sh-index tnum">{{ $index + 1 }}</td>
									<td class="tnum">{{ $row['lrn'] }}</td>
									<td><strong>{{ $row['name'] }}</strong></td>
									<td>{{ $row['grade'] }}</td>
									<td>{{ $row['section'] }}</td>
									<td><span class="badge {{ $shStateBadge($row['state']) }}">{{ $row['state_label'] }}</span></td>
								</tr>
							@endforeach
						</tbody>
					</table>
				</div>
			@endif
		</section>
		</div>
	</div>
</div>
<script>
	(function () {
		document.querySelectorAll('#shToolbar select').forEach(function (control) {
			control.addEventListener('change', function () {
				control.form.requestSubmit ? control.form.requestSubmit() : control.form.submit();
			});
		});

		const print = document.getElementById('shPrint');
		if (print) {
			print.addEventListener('click', function () { window.print(); });
		}
	})();
</script>
@include('partials.schoolhead-live')
@include('partials.role-page-transition')
</body>
</html>
