<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>{{ $reportLabel }} - School Head - SIGLA</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
	<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
	<script>document.documentElement.classList.add('js');</script>
	{{-- Shared LUSOG system, this role's sheet, then the DepEd form facsimile —
	     the same sheet the Feeding Coordinator's SBFP Forms page loads, so the
	     head reads the form in the form's own register rather than a restyled
	     copy of it. The rail comes last, as on every role. --}}
	<style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/schoolhead.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/feeding-sbfp-forms.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/role-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.schoolhead-sidebar', ['active' => 'reports'])

@php
	$shYear = str_replace('-', '&ndash;', e($schoolYear));
	$grades = \App\Support\FeedingBeneficiarySummary::GRADE_LEVELS;
	// bmib_* is the baseline grid, bmif_* the final one — the same field keys
	// the coordinator's page and the workbook exporter address.
	$prefix = $report === 'endline' ? 'bmif' : 'bmib';
	$isAssessment = in_array($report, ['baseline', 'endline'], true);
@endphp

<div class="main">
	<header class="topbar">
		<div class="topbar-bc">
			<a href="{{ route('dashboard.school-head.reports', ['school_year' => $schoolYear]) }}">Reports</a>
			<span class="bc-sep">&rsaquo;</span><span>{{ $reportLabel }}</span>
		</div>
		@include('partials.live-clock')
	</header>

	<div class="content">
		@if (session('error'))
			<div class="flash err">{{ session('error') }}</div>
		@endif

		<div class="page-header sh-header">
			<div>
				<h1 class="page-title">{{ $reportLabel }} <span>Report</span></h1>
				<p class="sh-sub">{{ $schoolName }} &middot; S.Y. {!! $shYear !!} &middot; Read {{ $todayLabel }}</p>
			</div>

			<div class="sh-actions">
				<a class="btn btn-ghost" href="{{ route('dashboard.school-head.reports', ['school_year' => $schoolYear]) }}">
					&larr; All reports
				</a>
				<button type="button" class="btn btn-secondary" id="printReport">Print</button>
				{{-- Export is offered only once the weighing behind the form is
				     finished. The endpoint refuses it too — a button that is not
				     drawn is not a guarantee. --}}
				@if ($readiness['complete'])
					<a class="btn btn-primary"
					   href="{{ route('dashboard.school-head.reports.export', ['report' => $report, 'school_year' => $schoolYear]) }}">
						Export
					</a>
				@endif
			</div>
		</div>

		@unless ($readiness['complete'])
			<div class="alert-bar is-info">
				<div class="alert-body">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
					<div>
						<strong>This report cannot be exported yet</strong>
						<span>{{ $readiness['blocked_reason'] }}</span>
					</div>
				</div>
			</div>
		@endunless

		{{-- The document. Every figure is derived at read time from the learners'
		     own records, and from the same BmiAssessmentReport the export writes,
		     so the form on screen and the workbook cannot disagree. --}}
		<section class="sheet-wrap">
			<div class="form-sheet">
				<div class="form-sheet-inner">
					<div class="report-head">
						<div class="report-school">{{ $schoolName }}</div>
						@if ($isAssessment)
							<div class="report-banner">
								{{ $report === 'baseline' ? 'Baseline' : 'Final' }} Nutritional Assessment (BMI) Report
							</div>
						@elseif ($report === 'masterlist')
							<div class="report-title-lines">Masterlists of Identified Severely Wasted and Wasted Students Who Are Qualified for Feeding Program</div>
						@else
							<div class="report-banner">{{ $reportLabel }}</div>
						@endif
						<div class="report-sy">S.Y. {{ $schoolYear }}</div>
					</div>

					@if ($isAssessment)
						<div class="report-body">
							@foreach ($grades as $grade)
								<div class="bmi-grade-block">
									<div class="grade-title">GRADE {{ $grade }} BMI</div>
									@include('feedingcor-dashboard.partials.bmi-table', ['prefix' => $prefix.'_g'.$grade, 'editable' => false, 'values' => $bmiValues])
								</div>
							@endforeach

							<div class="bmi-grade-block">
								<div class="grade-title grade-title-overall">OVERALL BMI</div>
								@include('feedingcor-dashboard.partials.bmi-table', ['prefix' => $prefix.'_overall', 'editable' => false, 'values' => $bmiValues])
							</div>
						</div>
					@elseif ($report === 'masterlist')
						<table class="template-table" aria-label="Masterlist of feeding program recipients">
							<thead>
								<tr>
									<th class="num-col">No.</th>
									<th class="name-col">Name</th>
									<th class="grade-col">Grade</th>
									<th>Section</th>
								</tr>
							</thead>
							<tbody>
								{{-- Padded to twenty rows, as the printed form is
								     ruled: a short list still looks like the form. --}}
								@for ($row = 1; $row <= max(20, count($masterlistRows)); $row++)
									@php $entry = $masterlistRows[$row - 1] ?? null; @endphp
									<tr>
										<td class="num-col">{{ $row }}</td>
										<td class="name-col">{{ $entry['name'] ?? '' }}</td>
										<td class="grade-col">{{ $entry['grade'] ?? '' }}</td>
										<td>{{ $entry['section'] ?? '' }}</td>
									</tr>
								@endfor
							</tbody>
						</table>
					@elseif ($monthly)
						<table class="template-table" aria-label="Monthly accomplishment">
							<thead>
								<tr>
									<th>Grade</th>
									<th class="num">Present</th>
									<th class="num">Confirmed marks</th>
									<th class="num">Turnout</th>
								</tr>
							</thead>
							<tbody>
								@forelse ($monthly['grades'] as $grade)
									<tr>
										<td>{{ $grade['label'] }}</td>
										<td class="num">{{ number_format($grade['present']) }}</td>
										<td class="num">{{ number_format($grade['confirmed']) }}</td>
										<td class="num">{{ $grade['rate'] === null ? '—' : rtrim(rtrim(number_format($grade['rate'], 1), '0'), '.').'%' }}</td>
									</tr>
								@empty
									<tr><td colspan="4">No confirmed mark for this month.</td></tr>
								@endforelse
							</tbody>
						</table>
					@endif

					{{-- The signature lines carry the school's own staff. A name
					     the app does not hold prints as a blank line to sign —
					     never an invented signatory. --}}
					<div class="foot-grid cols-3">
						<div class="foot-block">
							<div class="foot-label">Prepared by:</div>
							<div class="sign-name">{{ $signatories['prepared'] }}</div>
							<div class="foot-line"></div>
							<div class="foot-role">School Clinic Nurse / Teacher</div>
						</div>
						<div class="foot-block">
							<div class="foot-label">Attested by:</div>
							<div class="sign-name">&nbsp;</div>
							<div class="foot-line"></div>
							<div class="foot-role">MAPEH Department Head</div>
						</div>
						<div class="foot-block">
							<div class="foot-label">Noted by:</div>
							<div class="sign-name">{{ $signatories['noted'] }}</div>
							<div class="foot-line"></div>
							<div class="foot-role">Principal</div>
						</div>
					</div>
				</div>
			</div>
		</section>
	</div>
</div>

<script>
	document.getElementById('printReport')?.addEventListener('click', () => window.print());
</script>
{{-- The report is derived at read time, so an adviser's weighing recorded while
     this page is open changes what it says. The pulse reloads it when it does. --}}
@include('partials.schoolhead-live')
@include('partials.role-page-transition')
</body>
</html>
