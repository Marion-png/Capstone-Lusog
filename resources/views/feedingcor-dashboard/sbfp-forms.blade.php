<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>SBFP Forms - Feeding Coordinator - SIGLA</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
	<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
	@php $pageCssPath = resource_path('css/feeding-sbfp-forms.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
	<style id="printPageStyle">@page{size:A4 portrait;margin:10mm}</style>
</head>
<body>
<aside class="sidebar">
	<div class="sb-logo"><img src="{{ asset('images/lusog-logo.png') }}" alt="SIGLA Logo"></div>
	<nav class="sb-nav">
		<a href="{{ route('dashboard.feedingcor-dashboard') }}" class="sb-link">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
			Dashboard
		</a>
		<a href="{{ route('dashboard.feedingcor-health-records') }}" class="sb-link">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
			Student Health Records
		</a>
		<a href="{{ route('dashboard.feedingcor-program') }}" class="sb-link">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
			Feeding Program
		</a>
		<a href="{{ route('dashboard.feedingcor-sbfp-forms') }}" class="sb-link active">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/><line x1="8" y1="9" x2="10" y2="9"/></svg>
			SBFP Forms
		</a>
	</nav>
	<div class="sb-user">
		<div class="sb-avatar">{{ strtoupper(substr(session('active_name', 'FC'), 0, 2)) }}</div>
		<div class="sb-user-meta">
			<div class="sb-user-name">{{ session('active_name', 'Feeding Coordinator') }}</div>
			<div class="sb-user-role" style="font-size:.68rem;color:var(--g300);line-height:1.2;">{{ session('active_school_name', 'No school assigned') }}</div>
		</div>
		<form method="POST" action="{{ route('logout') }}">
			@csrf
			<button type="submit" class="sb-logout" title="Sign out" aria-label="Sign out">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
					<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
					<path d="M16 17l5-5-5-5"/>
					<path d="M21 12H9"/>
				</svg>
			</button>
		</form>
	</div>
</aside>

<div class="main">
	<div class="content">
		<div class="page-eyebrow">Feeding Program</div>
		<h1 class="page-title">SBFP <span>Forms</span></h1>
		<p class="page-sub">Select a form template, then encode the required fields in a clean sheet view.</p>

		<section class="selector-wrap">
			<div class="selector-row">
				<label class="selector-label" for="formTemplateSelect">Select Form Template:</label>
				<select id="formTemplateSelect" class="selector-input" aria-label="Select SBFP form template">
					<option value="">Choose a form...</option>
					<option value="form5-milk">Form 5 - Milk Component (Authorized Consignees)</option>
					<option value="form6-milk">Form 6 - Milk Component (List of Beneficiaries)</option>
					<option value="form7-milk">Form 7 - Milk Component (Milk Deliveries)</option>
					<option value="form7a-milk">Form 7-a - Milk Component (Drop-off Points)</option>
					<option value="bmi-baseline">BMI Report - Baseline Nutritional Assessment (Grades 7-10)</option>
					<option value="bmi-summary">BMI Report - Baseline vs Endline Summary (Per Grade)</option>
					<option value="feeding-narrative">Feeding Program - Narrative Report</option>
					<option value="feeding-masterlist">Feeding Program - Masterlist of Qualified Recipients</option>
				</select>
			</div>
			<p class="selector-note">The selected template will appear below.</p>
		</section>

		<div class="placeholder-panel" id="emptyStatePanel">
			Please select a form template to open the encoder.
		</div>

		<section class="sheet-wrap form-panel" id="form5Panel" data-template="form5-milk">
			<div class="sheet-tools">
				<div class="sheet-status" id="form5DraftStatus">Draft not saved yet.</div>
				<div class="sheet-btns">
					<button type="button" class="btn btn-primary" id="saveForm5DraftBtn">Save Draft</button>
					<button type="button" class="btn btn-ghost" id="printForm5Btn">Print Form</button>
					<button type="button" class="btn btn-warn" id="clearForm5DraftBtn">Clear</button>
				</div>
			</div>

			<div class="form-sheet">
				<div class="form-sheet-inner">
					<div class="sheet-meta">
						<div class="meta-row">
							<label class="meta-label" for="regionDivision">Region/Division/District:</label>
							<input id="regionDivision" class="meta-input" type="text" data-field="form5_region_division_district">
						</div>
						<div class="meta-row">
							<label class="meta-label" for="schoolName">Name of School:</label>
							<input id="schoolName" class="meta-input" type="text" data-field="form5_name_of_school">
						</div>
						<div class="meta-row">
							<label class="meta-label" for="schoolIdNo">School ID No.:</label>
							<input id="schoolIdNo" class="meta-input" type="text" data-field="form5_school_id_no">
						</div>
					</div>

					<div class="sheet-head">
						<div class="line1">School-Based Feeding Program - Milk Component</div>
						<div class="line2">List of Authorized Consignees (SY <input type="text" class="meta-input" style="display:inline-block;width:140px;margin-left:4px" data-field="form5_school_year" aria-label="School year">)</div>
					</div>

					<table class="template-table" aria-label="SBFP Form 5 authorized consignees table">
						<thead>
							<tr>
								<th class="num-col">#</th>
								<th class="name-col">Name &amp; Designation</th>
								<th class="tel-col">Tel. No.</th>
								<th class="mobile-col">Mobile No.</th>
								<th class="email-col">Email Add</th>
								<th class="sign-col">Specimen Signature</th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td class="num-col">1</td>
								<td>
									<div class="designation-cell">
										<input type="text" class="designation-input" data-field="form5_row1_name" placeholder="Enter full name">
										<span class="designation-tag">(School Head)</span>
									</div>
								</td>
								<td><input type="text" class="cell-input" data-field="form5_row1_tel" placeholder="(000) 000-0000"></td>
								<td><input type="text" class="cell-input" data-field="form5_row1_mobile" placeholder="09XXXXXXXXX"></td>
								<td><input type="email" class="cell-input" data-field="form5_row1_email" placeholder="name@email.com"></td>
								<td><input type="text" class="cell-input" data-field="form5_row1_signature" placeholder="Type full name"></td>
							</tr>
							<tr>
								<td class="num-col">2</td>
								<td>
									<div class="designation-cell">
										<input type="text" class="designation-input" data-field="form5_row2_name" placeholder="Enter full name">
										<span class="designation-tag">(School Feeding Coordinator)</span>
									</div>
								</td>
								<td><input type="text" class="cell-input" data-field="form5_row2_tel" placeholder="(000) 000-0000"></td>
								<td><input type="text" class="cell-input" data-field="form5_row2_mobile" placeholder="09XXXXXXXXX"></td>
								<td><input type="email" class="cell-input" data-field="form5_row2_email" placeholder="name@email.com"></td>
								<td><input type="text" class="cell-input" data-field="form5_row2_signature" placeholder="Type full name"></td>
							</tr>
							<tr>
								<td class="num-col">3</td>
								<td>
									<div class="designation-cell">
										<input type="text" class="designation-input" data-field="form5_row3_name" placeholder="Enter full name">
										<span class="designation-tag">(School Property Custodian)</span>
									</div>
								</td>
								<td><input type="text" class="cell-input" data-field="form5_row3_tel" placeholder="(000) 000-0000"></td>
								<td><input type="text" class="cell-input" data-field="form5_row3_mobile" placeholder="09XXXXXXXXX"></td>
								<td><input type="email" class="cell-input" data-field="form5_row3_email" placeholder="name@email.com"></td>
								<td><input type="text" class="cell-input" data-field="form5_row3_signature" placeholder="Type full name"></td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</section>

		<section class="sheet-wrap form-panel" id="form6Panel" data-template="form6-milk">
			<div class="sheet-tools">
				<div class="sheet-status" id="form6DraftStatus">Draft not saved yet.</div>
				<div class="sheet-btns">
					<button type="button" class="btn btn-primary" id="saveForm6DraftBtn">Save Draft</button>
					<button type="button" class="btn btn-ghost" id="printForm6Btn">Print Form</button>
					<button type="button" class="btn btn-warn" id="clearForm6DraftBtn">Clear</button>
				</div>
			</div>

			<div class="form-sheet">
				<div class="form-sheet-inner">
					<div class="sheet-meta">
						<div class="meta-row">
							<label class="meta-label" for="form6RegionDivision">Region/Division/District:</label>
							<input id="form6RegionDivision" class="meta-input" type="text" data-field="form6_region_division_district">
						</div>
						<div class="meta-row">
							<label class="meta-label" for="form6SchoolName">Name of School:</label>
							<input id="form6SchoolName" class="meta-input" type="text" data-field="form6_name_of_school">
						</div>
						<div class="meta-row">
							<label class="meta-label" for="form6SchoolIdNo">School ID No.:</label>
							<input id="form6SchoolIdNo" class="meta-input" type="text" data-field="form6_school_id_no">
						</div>
					</div>

					<div class="sheet-head">
						<div class="line1">School-Based Feeding Program - Milk Component</div>
						<div class="line2">List of Beneficiaries (SY <input type="text" class="meta-input" style="display:inline-block;width:140px;margin-left:4px" data-field="form6_school_year" aria-label="School year">)</div>
					</div>

					<table class="template-table" aria-label="SBFP Form 6 beneficiaries table">
						<thead>
							<tr>
								<th class="name-col" rowspan="2">Name</th>
								<th class="grade-col" rowspan="2">Grade &amp; Section</th>
								<th class="subhead" colspan="3">Classification of Students in terms of Milk Tolerance</th>
							</tr>
							<tr>
								<th class="tol-col subhead">Without milk intolerance and will participate in milk feeding</th>
								<th class="tol-col subhead">With milk intolerance but willing to participate in milk feeding</th>
								<th class="tol-col subhead">Not allowed by parents to participate in milk feeding</th>
							</tr>
						</thead>
						<tbody>
							@for ($row = 1; $row <= 12; $row++)
								<tr>
									<td><input type="text" class="cell-input" data-field="form6_row{{ $row }}_name" placeholder="Student full name"></td>
									<td><input type="text" class="cell-input" data-field="form6_row{{ $row }}_grade_section" placeholder="Grade / Section"></td>
									<td><input type="text" class="cell-input" data-field="form6_row{{ $row }}_without_intolerance" placeholder="/ or remarks"></td>
									<td><input type="text" class="cell-input" data-field="form6_row{{ $row }}_with_intolerance" placeholder="/ or remarks"></td>
									<td><input type="text" class="cell-input" data-field="form6_row{{ $row }}_not_allowed" placeholder="/ or remarks"></td>
								</tr>
							@endfor
						</tbody>
					</table>
				</div>
			</div>
		</section>

		<section class="sheet-wrap form-panel" id="form7Panel" data-template="form7-milk">
			<div class="sheet-tools">
				<div class="sheet-status" id="form7DraftStatus">Draft not saved yet.</div>
				<div class="sheet-btns">
					<button type="button" class="btn btn-primary" id="saveForm7DraftBtn">Save Draft</button>
					<button type="button" class="btn btn-ghost" id="printForm7Btn">Print Form</button>
					<button type="button" class="btn btn-warn" id="clearForm7DraftBtn">Clear</button>
				</div>
			</div>

			<div class="form-sheet">
				<div class="form-sheet-inner">
					<div class="sheet-meta">
						<div class="meta-row">
							<label class="meta-label" for="form7RegionDivision">Region/Division/District:</label>
							<input id="form7RegionDivision" class="meta-input" type="text" data-field="form7_region_division_district">
						</div>
						<div class="meta-row">
							<label class="meta-label" for="form7SchoolName">Name of School:</label>
							<input id="form7SchoolName" class="meta-input" type="text" data-field="form7_name_of_school">
						</div>
						<div class="meta-row">
							<label class="meta-label" for="form7SchoolIdNo">School ID No.:</label>
							<input id="form7SchoolIdNo" class="meta-input" type="text" data-field="form7_school_id_no">
						</div>
					</div>

					<div class="sheet-head">
						<div class="line1">School-Based Feeding Program - Milk Component</div>
						<div class="line2">Milk Deliveries (SY <input type="text" class="meta-input" style="display:inline-block;width:140px;margin-left:4px" data-field="form7_school_year" aria-label="School year">)</div>
					</div>

					<table class="template-table" aria-label="SBFP Form 7 milk deliveries table">
						<thead>
							<tr>
								<th rowspan="2">Grade Level</th>
								<th rowspan="2">Number of Beneficiaries</th>
								<th rowspan="2">Date Delivered</th>
								<th colspan="3">No. of Packs Received</th>
								<th rowspan="2">No. of Packs for Replacement/Rejected</th>
								<th class="remarks-col" rowspan="2">Remarks</th>
							</tr>
							<tr>
								<th class="packs-col">New</th>
								<th class="packs-col">Replacement</th>
								<th class="packs-col subhead">Total (New + Replacement)</th>
							</tr>
						</thead>
						<tbody>
							@php
								$form7Levels = ['Kinder', 'Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6'];
							@endphp
							@foreach ($form7Levels as $index => $level)
								@php $row = $index + 1; @endphp
								<tr>
									<td><strong>{{ $level }}</strong></td>
									<td><input type="number" min="0" class="cell-input" data-field="form7_row{{ $row }}_beneficiaries" data-form7-beneficiaries="1" placeholder="0"></td>
									<td><input type="date" class="cell-input" data-field="form7_row{{ $row }}_date_delivered"></td>
									<td><input type="number" min="0" class="cell-input" data-field="form7_row{{ $row }}_new" data-form7-new="1" data-form7-row="{{ $row }}" placeholder="0"></td>
									<td><input type="number" min="0" class="cell-input" data-field="form7_row{{ $row }}_replacement" data-form7-replacement="1" data-form7-row="{{ $row }}" placeholder="0"></td>
									<td><input type="number" min="0" class="cell-input" data-field="form7_row{{ $row }}_total" data-form7-total="{{ $row }}" readonly></td>
									<td><input type="number" min="0" class="cell-input" data-field="form7_row{{ $row }}_rejected" placeholder="0"></td>
									<td><input type="text" class="cell-input" data-field="form7_row{{ $row }}_remarks" placeholder="Remarks"></td>
								</tr>
							@endforeach
							<tr>
								<td><strong>TOTAL:</strong></td>
								<td><input type="number" class="cell-input" data-field="form7_total_beneficiaries" id="form7TotalBeneficiaries" readonly></td>
								<td></td>
								<td><input type="number" class="cell-input" data-field="form7_total_new" id="form7TotalNew" readonly></td>
								<td><input type="number" class="cell-input" data-field="form7_total_replacement" id="form7TotalReplacement" readonly></td>
								<td><input type="number" class="cell-input" data-field="form7_total_received" id="form7TotalReceived" readonly></td>
								<td><input type="number" class="cell-input" data-field="form7_total_rejected" id="form7TotalRejected" readonly></td>
								<td></td>
							</tr>
						</tbody>
					</table>

					<div class="foot-grid">
						<div class="foot-block">
							<div class="foot-label">Prepared by:</div>
							<div class="foot-line"></div>
							<div class="foot-role">School Feeding Coordinator</div>
						</div>
						<div class="foot-block">
							<div class="foot-label">Approved by:</div>
							<div class="foot-line"></div>
							<div class="foot-role">School Head</div>
						</div>
					</div>
				</div>
			</div>
		</section>

		<section class="sheet-wrap form-panel" id="form7aPanel" data-template="form7a-milk">
			<div class="sheet-tools">
				<div class="sheet-status" id="form7aDraftStatus">Draft not saved yet.</div>
				<div class="sheet-btns">
					<button type="button" class="btn btn-secondary" id="addForm7aTableBtn">Add Table</button>
					<button type="button" class="btn btn-primary" id="saveForm7aDraftBtn">Save Draft</button>
					<button type="button" class="btn btn-ghost" id="printForm7aBtn">Print Form</button>
					<button type="button" class="btn btn-warn" id="clearForm7aDraftBtn">Clear</button>
				</div>
			</div>

			<div class="form-sheet">
				<div class="form-sheet-inner">
					<div class="sheet-meta">
						<div class="meta-row">
							<label class="meta-label" for="form7aRegionDivision">Region/Division/District:</label>
							<input id="form7aRegionDivision" class="meta-input" type="text" data-field="form7a_region_division_district">
						</div>
						<div class="meta-row">
							<label class="meta-label" for="form7aSchoolName">Name of School:</label>
							<input id="form7aSchoolName" class="meta-input" type="text" data-field="form7a_name_of_school">
						</div>
						<div class="meta-row">
							<label class="meta-label" for="form7aSchoolIdNo">School ID No.:</label>
							<input id="form7aSchoolIdNo" class="meta-input" type="text" data-field="form7a_school_id_no">
						</div>
					</div>

					<div class="sheet-head">
						<div class="line1">School-Based Feeding Program - Milk Component</div>
						<div class="line2">Milk Deliveries (SY <input type="text" class="meta-input" style="display:inline-block;width:140px;margin-left:4px" data-field="form7a_school_year" aria-label="School year">) for Drop-off Points</div>
					</div>

					<div id="form7aTablesContainer">
						<div class="form7a-block" data-form7a-table="1">
							<div class="form7a-block-title">Delivery Table 1</div>
							<table class="template-table" aria-label="SBFP Form 7-a drop-off table">
								<thead>
									<tr>
										<th rowspan="2">Date Delivered</th>
										<th colspan="3">No. of Packs Received</th>
										<th colspan="3">Allocation per School</th>
									</tr>
									<tr>
										<th class="packs-col">New</th>
										<th class="packs-col">Replacement</th>
										<th class="packs-col subhead">Total (New + Replacement)</th>
										<th>Schools</th>
										<th>Number of Beneficiaries</th>
										<th>Number of Milk Allocation</th>
									</tr>
								</thead>
								<tbody>
									@for ($school = 1; $school <= 10; $school++)
										<tr>
											@if ($school === 1)
												<td rowspan="11"><input type="date" class="cell-input" data-field="form7a_table1_date_delivered"></td>
												<td rowspan="11"><input type="number" min="0" class="cell-input" data-field="form7a_table1_new" data-form7a-new="1" placeholder="0"></td>
												<td rowspan="11"><input type="number" min="0" class="cell-input" data-field="form7a_table1_replacement" data-form7a-replacement="1" placeholder="0"></td>
												<td rowspan="11"><input type="number" min="0" class="cell-input" data-field="form7a_table1_total_received" data-form7a-total-received="1" readonly></td>
											@endif
											<td><strong>{{ $school }}</strong></td>
											<td><input type="number" min="0" class="cell-input" data-field="form7a_table1_school{{ $school }}_beneficiaries" data-form7a-beneficiaries="1" placeholder="0"></td>
											<td><input type="number" min="0" class="cell-input" data-field="form7a_table1_school{{ $school }}_milk_allocation" data-form7a-allocation="1" placeholder="0"></td>
										</tr>
									@endfor
									<tr>
										<td><strong>TOTAL:</strong></td>
										<td><input type="number" class="cell-input" data-field="form7a_table1_total_beneficiaries" data-form7a-total-beneficiaries="1" readonly></td>
										<td><input type="number" class="cell-input" data-field="form7a_table1_total_allocation" data-form7a-total-allocation="1" readonly></td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>

					<div class="foot-grid">
						<div class="foot-block">
							<div class="foot-label">Prepared by:</div>
							<div class="foot-line"></div>
							<div class="foot-role">School Feeding Coordinator</div>
						</div>
						<div class="foot-block">
							<div class="foot-label">Approved by:</div>
							<div class="foot-line"></div>
							<div class="foot-role">School Head</div>
						</div>
					</div>
				</div>
			</div>
		</section>

		<section class="sheet-wrap form-panel" id="bmiBaselinePanel" data-template="bmi-baseline">
			<div class="sheet-tools">
				<div class="sheet-status" id="bmiBaselineDraftStatus">Draft not saved yet.</div>
				<div class="sheet-btns">
					<button type="button" class="btn btn-primary" id="saveBmiBaselineDraftBtn">Save Draft</button>
					<button type="button" class="btn btn-ghost" id="printBmiBaselineBtn">Print Form</button>
					<button type="button" class="btn btn-warn" id="clearBmiBaselineDraftBtn">Clear</button>
				</div>
			</div>

			<div class="form-sheet">
				<div class="form-sheet-inner">
					<div class="report-head">
						<div class="report-school">{{ session('active_school_name', 'School name not set') }}</div>
						<input type="text" class="report-addr-input" data-field="bmib_school_address" placeholder="School address" aria-label="School address">
						<div class="report-banner">Baseline Nutritional Assessment (BMI) Report</div>
						<div class="report-sy">S.Y. <input type="text" class="inline-line-input" data-field="bmib_school_year" placeholder="20__-20__" aria-label="School year"></div>
					</div>

					<div class="report-body">
						@foreach ([7, 8, 9, 10] as $grade)
							<div class="grade-title">GRADE {{ $grade }} BMI</div>
							@include('feedingcor-dashboard.partials.bmi-table', ['prefix' => 'bmib_g' . $grade, 'editable' => true])
						@endforeach

						<div class="grade-title grade-title-overall">OVERALL BMI</div>
						<p class="auto-note">Overall figures and all Total cells are computed automatically from the grade-level entries.</p>
						@include('feedingcor-dashboard.partials.bmi-table', ['prefix' => 'bmib_overall', 'editable' => false])
					</div>

					<div class="foot-grid cols-3">
						<div class="foot-block">
							<div class="foot-label">Prepared by:</div>
							<input type="text" class="sign-input" data-field="bmib_prepared_name" placeholder="Full name" aria-label="Prepared by name">
							<input type="text" class="foot-role-input" data-field="bmib_prepared_role" placeholder="School Clinic Nurse / Teacher" aria-label="Prepared by designation">
						</div>
						<div class="foot-block">
							<div class="foot-label">Attested by:</div>
							<input type="text" class="sign-input" data-field="bmib_attested_name" placeholder="Full name" aria-label="Attested by name">
							<input type="text" class="foot-role-input" data-field="bmib_attested_role" placeholder="MAPEH Department Head" aria-label="Attested by designation">
						</div>
						<div class="foot-block">
							<div class="foot-label">Noted by:</div>
							<input type="text" class="sign-input" data-field="bmib_noted_name" placeholder="Full name" aria-label="Noted by name">
							<input type="text" class="foot-role-input" data-field="bmib_noted_role" placeholder="Principal III" aria-label="Noted by designation">
						</div>
					</div>
				</div>
			</div>
		</section>

		<section class="sheet-wrap form-panel" id="bmiSummaryPanel" data-template="bmi-summary">
			<div class="sheet-tools">
				<div class="sheet-status" id="bmiSummaryDraftStatus">Draft not saved yet.</div>
				<div class="sheet-btns">
					<button type="button" class="btn btn-primary" id="saveBmiSummaryDraftBtn">Save Draft</button>
					<button type="button" class="btn btn-ghost" id="printBmiSummaryBtn">Print Form</button>
					<button type="button" class="btn btn-warn" id="clearBmiSummaryDraftBtn">Clear</button>
				</div>
			</div>

			<div class="form-sheet">
				<div class="form-sheet-inner">
					<div class="report-head">
						<div class="report-school">{{ session('active_school_name', 'School name not set') }}</div>
						<input type="text" class="report-addr-input" data-field="bmis_school_address" placeholder="School address" aria-label="School address">
						<div class="report-banner">Baseline Nutritional Assessment (BMI) Report</div>
						<div class="report-sy">S.Y. <input type="text" class="inline-line-input" data-field="bmis_school_year" placeholder="20__-20__" aria-label="School year"></div>
					</div>

					<div class="report-body">
						@foreach (['m1' => 'JULY 2025', 'm2' => 'FEBRUARY 2026'] as $monthKey => $monthPlaceholder)
							<div class="grade-title">GRADE <input type="text" class="inline-line-input inline-short" data-field="bmis_{{ $monthKey }}_grade" placeholder="7" aria-label="Grade level"> BMI</div>
							<div class="month-title">MONTH OF <input type="text" class="inline-line-input" data-field="bmis_{{ $monthKey }}_month" placeholder="{{ $monthPlaceholder }}" aria-label="Month covered"></div>
							@include('feedingcor-dashboard.partials.bmi-table', ['prefix' => 'bmis_' . $monthKey, 'editable' => true])
						@endforeach
					</div>
				</div>
			</div>
		</section>

		<section class="sheet-wrap form-panel" id="narrativePanel" data-template="feeding-narrative">
			<div class="sheet-tools">
				<div class="sheet-status" id="narrativeDraftStatus">Draft not saved yet.</div>
				<div class="sheet-btns">
					<button type="button" class="btn btn-primary" id="saveNarrativeDraftBtn">Save Draft</button>
					<button type="button" class="btn btn-ghost" id="printNarrativeBtn">Print Form</button>
					<button type="button" class="btn btn-warn" id="clearNarrativeDraftBtn">Clear</button>
				</div>
			</div>

			<div class="form-sheet">
				<div class="form-sheet-inner">
					<div class="report-head">
						<div class="deped-lines">Republika ng Pilipinas<br>Kagawaran ng Edukasyon</div>
						<input type="text" class="report-addr-input" data-field="narr_region" placeholder="Rehiyon / Region" aria-label="Region">
						<input type="text" class="report-addr-input" data-field="narr_division" placeholder="Sangay / Division" aria-label="Division">
						<div class="report-school">{{ session('active_school_name', 'School name not set') }}</div>
						<input type="text" class="report-addr-input" data-field="narr_school_address" placeholder="School address" aria-label="School address">
						<div class="report-title-lines">Report on the Feeding Program of {{ session('active_school_name', 'the school') }} Funded by <input type="text" class="inline-line-input inline-wide" data-field="narr_funder" placeholder="Sponsor / Funding partner" aria-label="Funding partner"></div>
					</div>

					<div class="report-body">
						@foreach (['introduction' => 'Introduction', 'background' => 'Background and Rationale', 'implementation' => 'Implementation', 'results' => 'Results and Impact', 'conclusion' => 'Conclusion and Recommendation'] as $sectionKey => $sectionLabel)
							<div class="narrative-section">
								<div class="narrative-label">{{ $sectionLabel }}</div>
								<textarea class="narrative-textarea" data-field="narr_{{ $sectionKey }}" placeholder="Write the {{ strtolower($sectionLabel) }} here..." aria-label="{{ $sectionLabel }}"></textarea>
							</div>
						@endforeach
					</div>

					<div class="foot-grid">
						<div class="foot-block">
							<div class="foot-label">Prepared by:</div>
							<input type="text" class="sign-input" data-field="narr_prepared_name" placeholder="Full name" aria-label="Prepared by name">
							<div class="foot-role">School Feeding Coordinator</div>
						</div>
						<div class="foot-block">
							<div class="foot-label">Noted by:</div>
							<input type="text" class="sign-input" data-field="narr_noted_name" placeholder="Full name" aria-label="Noted by name">
							<input type="text" class="foot-role-input" data-field="narr_noted_role" placeholder="Principal III" aria-label="Noted by designation">
						</div>
					</div>
				</div>
			</div>
		</section>

		<section class="sheet-wrap form-panel" id="masterlistPanel" data-template="feeding-masterlist">
			<div class="sheet-tools">
				<div class="sheet-status" id="mlDraftStatus">Draft not saved yet.</div>
				<div class="sheet-btns">
					<button type="button" class="btn btn-secondary" id="addMlRowsBtn">Add 10 Rows</button>
					<button type="button" class="btn btn-primary" id="saveMlDraftBtn">Save Draft</button>
					<button type="button" class="btn btn-ghost" id="printMlBtn">Print Form</button>
					<button type="button" class="btn btn-warn" id="clearMlDraftBtn">Clear</button>
				</div>
			</div>

			<div class="form-sheet">
				<div class="form-sheet-inner">
					<div class="report-head">
						<div class="report-school">{{ session('active_school_name', 'School name not set') }}</div>
						<input type="text" class="report-addr-input" data-field="ml_school_address" placeholder="School address" aria-label="School address">
						<div class="report-title-lines">Masterlists of Identified Severely Wasted and Wasted Students Who Are Qualified for Feeding Program</div>
						<div class="report-sy">S.Y. <input type="text" class="inline-line-input" data-field="ml_school_year" placeholder="20__-20__" aria-label="School year"></div>
					</div>

					<table class="template-table" aria-label="Masterlist of feeding program recipients">
						<thead>
							<tr>
								<th class="num-col">No.</th>
								<th class="name-col">Name</th>
								<th class="grade-col">Grade</th>
								<th>Section</th>
							</tr>
						</thead>
						<tbody id="mlTbody">
							@for ($row = 1; $row <= 20; $row++)
								<tr class="ml-row">
									<td class="num-col ml-num">{{ $row }}</td>
									<td><input type="text" class="cell-input" data-field="ml_row{{ $row }}_name" placeholder="Student full name"></td>
									<td><input type="text" class="cell-input" data-field="ml_row{{ $row }}_grade" placeholder="Grade"></td>
									<td><input type="text" class="cell-input" data-field="ml_row{{ $row }}_section" placeholder="Section"></td>
								</tr>
							@endfor
						</tbody>
					</table>

					<div class="foot-grid">
						<div class="foot-block">
							<div class="foot-label">Prepared by:</div>
							<input type="text" class="sign-input" data-field="ml_prepared_name" placeholder="Full name" aria-label="Prepared by name">
							<div class="foot-role">Feeding Coordinator</div>
						</div>
						<div class="foot-block">
							<div class="foot-label">Noted by:</div>
							<input type="text" class="sign-input" data-field="ml_noted_name" placeholder="Full name" aria-label="Noted by name">
							<input type="text" class="foot-role-input" data-field="ml_noted_role" placeholder="Principal III" aria-label="Noted by designation">
						</div>
					</div>
				</div>
			</div>
		</section>
	</div>
</div>
<script>
(() => {
	const templateSelect = document.getElementById('formTemplateSelect');
	const emptyStatePanel = document.getElementById('emptyStatePanel');

	const stamp = () => new Date().toLocaleString('en-US', {
		year: 'numeric',
		month: 'short',
		day: '2-digit',
		hour: '2-digit',
		minute: '2-digit',
		second: '2-digit',
	});

	const initDraftModule = ({ storageKey, fieldPrefix, statusId, saveId, clearId, printId }) => {
		const fields = Array.from(document.querySelectorAll(`[data-field^="${fieldPrefix}"]`));
		const statusNode = document.getElementById(statusId);
		const saveBtn = document.getElementById(saveId);
		const clearBtn = document.getElementById(clearId);
		const printBtn = document.getElementById(printId);

		const loadDraft = () => {
			try {
				const raw = window.localStorage.getItem(storageKey);
				if (!raw) {
					return;
				}
				const parsed = JSON.parse(raw);
				fields.forEach((field) => {
					const key = field.getAttribute('data-field');
					if (!key) {
						return;
					}
					field.value = typeof parsed[key] === 'string' ? parsed[key] : '';
				});
				if (statusNode) {
					statusNode.textContent = 'Draft loaded from local storage.';
				}
			} catch (_error) {
				if (statusNode) {
					statusNode.textContent = 'Unable to load existing draft.';
				}
			}
		};

		const saveDraft = () => {
			const payload = {};
			fields.forEach((field) => {
				const key = field.getAttribute('data-field');
				if (!key) {
					return;
				}
				payload[key] = String(field.value || '').trim();
			});
			window.localStorage.setItem(storageKey, JSON.stringify(payload));
			if (statusNode) {
				statusNode.textContent = `Draft saved on ${stamp()}.`;
			}
		};

		const clearDraft = () => {
			fields.forEach((field) => {
				field.value = '';
			});
			window.localStorage.removeItem(storageKey);
			if (statusNode) {
				statusNode.textContent = 'Draft cleared.';
			}
		};

		if (saveBtn) {
			saveBtn.addEventListener('click', saveDraft);
		}

		if (clearBtn) {
			clearBtn.addEventListener('click', clearDraft);
		}

		if (printBtn) {
			printBtn.addEventListener('click', () => window.print());
		}

		loadDraft();
	};

	const printPageStyle = document.getElementById('printPageStyle');
	const landscapeTemplates = ['bmi-baseline', 'bmi-summary', 'form7-milk', 'form7a-milk'];

	const autoGrowNarrative = (textarea) => {
		if (!textarea.offsetParent) {
			return;
		}
		textarea.style.height = 'auto';
		textarea.style.height = `${textarea.scrollHeight + 2}px`;
	};

	const autoGrowAllNarratives = () => {
		document.querySelectorAll('.narrative-textarea').forEach(autoGrowNarrative);
	};

	const syncSelectedTemplate = () => {
		if (!templateSelect || !emptyStatePanel) {
			return;
		}

		const selected = String(templateSelect.value || '');
		let anyActive = false;
		document.querySelectorAll('.form-panel').forEach((panel) => {
			const isActive = selected !== '' && panel.getAttribute('data-template') === selected;
			panel.classList.toggle('active', isActive);
			anyActive = anyActive || isActive;
		});
		emptyStatePanel.style.display = anyActive ? 'none' : '';

		if (printPageStyle) {
			const orientation = landscapeTemplates.includes(selected) ? 'landscape' : 'portrait';
			printPageStyle.textContent = `@page{size:A4 ${orientation};margin:10mm}`;
		}

		if (selected === 'feeding-narrative') {
			autoGrowAllNarratives();
		}
	};

	const form7aTablesContainer = document.getElementById('form7aTablesContainer');
	const form7aStatusNode = document.getElementById('form7aDraftStatus');
	const addForm7aTableBtn = document.getElementById('addForm7aTableBtn');
	const saveForm7aDraftBtn = document.getElementById('saveForm7aDraftBtn');
	const clearForm7aDraftBtn = document.getElementById('clearForm7aDraftBtn');
	const printForm7aBtn = document.getElementById('printForm7aBtn');
	const form7aStorageKey = 'sbfp_milk_form7a_draft_v1';

	const updateForm7aTableTotals = (tableBlock) => {
		if (!tableBlock) {
			return;
		}

		const num = (value) => {
			const parsed = Number(value);
			return Number.isFinite(parsed) ? parsed : 0;
		};

		const newInput = tableBlock.querySelector('[data-form7a-new]');
		const replacementInput = tableBlock.querySelector('[data-form7a-replacement]');
		const totalReceivedInput = tableBlock.querySelector('[data-form7a-total-received]');

		const newValue = num(newInput ? newInput.value : 0);
		const replacementValue = num(replacementInput ? replacementInput.value : 0);
		if (totalReceivedInput) {
			totalReceivedInput.value = String(newValue + replacementValue);
		}

		let totalBeneficiaries = 0;
		let totalAllocation = 0;

		tableBlock.querySelectorAll('[data-form7a-beneficiaries]').forEach((input) => {
			totalBeneficiaries += num(input.value);
		});

		tableBlock.querySelectorAll('[data-form7a-allocation]').forEach((input) => {
			totalAllocation += num(input.value);
		});

		const totalBeneficiariesInput = tableBlock.querySelector('[data-form7a-total-beneficiaries]');
		const totalAllocationInput = tableBlock.querySelector('[data-form7a-total-allocation]');
		if (totalBeneficiariesInput) {
			totalBeneficiariesInput.value = String(totalBeneficiaries);
		}
		if (totalAllocationInput) {
			totalAllocationInput.value = String(totalAllocation);
		}
	};

	const renumberForm7aBlocks = () => {
		if (!form7aTablesContainer) {
			return;
		}

		Array.from(form7aTablesContainer.querySelectorAll('.form7a-block')).forEach((block, index) => {
			const tableNumber = index + 1;
			block.setAttribute('data-form7a-table', String(tableNumber));
			const title = block.querySelector('.form7a-block-title');
			if (title) {
				title.textContent = `Delivery Table ${tableNumber}`;
			}

			block.querySelectorAll('[data-field]').forEach((input) => {
				const key = input.getAttribute('data-field');
				if (!key) {
					return;
				}
				input.setAttribute('data-field', key.replace(/^form7a_table\d+_/, `form7a_table${tableNumber}_`));
			});

			updateForm7aTableTotals(block);
		});
	};

	const addForm7aTable = () => {
		if (!form7aTablesContainer) {
			return;
		}

		const firstBlock = form7aTablesContainer.querySelector('.form7a-block');
		if (!firstBlock) {
			return;
		}

		const clone = firstBlock.cloneNode(true);
		clone.querySelectorAll('input').forEach((input) => {
			input.value = '';
		});
		form7aTablesContainer.appendChild(clone);
		renumberForm7aBlocks();
		if (form7aStatusNode) {
			form7aStatusNode.textContent = 'New table added. Save draft to keep changes.';
		}
	};

	const saveForm7aDraft = () => {
		if (!form7aTablesContainer) {
			return;
		}

		const payload = {
			tableCount: form7aTablesContainer.querySelectorAll('.form7a-block').length,
			values: {},
		};

		document.querySelectorAll('[data-field^="form7a_"]').forEach((field) => {
			const key = field.getAttribute('data-field');
			if (!key) {
				return;
			}
			payload.values[key] = String(field.value || '').trim();
		});

		window.localStorage.setItem(form7aStorageKey, JSON.stringify(payload));
		if (form7aStatusNode) {
			form7aStatusNode.textContent = `Draft saved on ${stamp()}.`;
		}
	};

	const loadForm7aDraft = () => {
		if (!form7aTablesContainer) {
			return;
		}

		try {
			const raw = window.localStorage.getItem(form7aStorageKey);
			if (!raw) {
				renumberForm7aBlocks();
				return;
			}

			const parsed = JSON.parse(raw);
			const tableCount = Number(parsed.tableCount || 1);
			for (let i = form7aTablesContainer.querySelectorAll('.form7a-block').length; i < tableCount; i++) {
				addForm7aTable();
			}

			if (parsed.values && typeof parsed.values === 'object') {
				document.querySelectorAll('[data-field^="form7a_"]').forEach((field) => {
					const key = field.getAttribute('data-field');
					if (!key) {
						return;
					}
					field.value = typeof parsed.values[key] === 'string' ? parsed.values[key] : '';
				});
			}

			renumberForm7aBlocks();
			if (form7aStatusNode) {
				form7aStatusNode.textContent = 'Draft loaded from local storage.';
			}
		} catch (_error) {
			if (form7aStatusNode) {
				form7aStatusNode.textContent = 'Unable to load existing draft.';
			}
		}
	};

	const clearForm7aDraft = () => {
		if (!form7aTablesContainer) {
			return;
		}

		Array.from(form7aTablesContainer.querySelectorAll('.form7a-block')).forEach((block, index) => {
			if (index === 0) {
				block.querySelectorAll('input').forEach((input) => {
					input.value = '';
				});
				return;
			}
			block.remove();
		});

		renumberForm7aBlocks();
		window.localStorage.removeItem(form7aStorageKey);
		if (form7aStatusNode) {
			form7aStatusNode.textContent = 'Draft cleared.';
		}
	};

	const updateForm7Totals = () => {
		const num = (value) => {
			const parsed = Number(value);
			return Number.isFinite(parsed) ? parsed : 0;
		};

		let totalBeneficiaries = 0;
		let totalNew = 0;
		let totalReplacement = 0;
		let totalRejected = 0;

		for (let row = 1; row <= 7; row++) {
			const beneficiariesInput = document.querySelector(`[data-field="form7_row${row}_beneficiaries"]`);
			const newInput = document.querySelector(`[data-field="form7_row${row}_new"]`);
			const replacementInput = document.querySelector(`[data-field="form7_row${row}_replacement"]`);
			const totalInput = document.querySelector(`[data-field="form7_row${row}_total"]`);
			const rejectedInput = document.querySelector(`[data-field="form7_row${row}_rejected"]`);

			const beneficiaries = num(beneficiariesInput ? beneficiariesInput.value : 0);
			const newPacks = num(newInput ? newInput.value : 0);
			const replacement = num(replacementInput ? replacementInput.value : 0);
			const rejected = num(rejectedInput ? rejectedInput.value : 0);

			if (totalInput) {
				totalInput.value = String(newPacks + replacement);
			}

			totalBeneficiaries += beneficiaries;
			totalNew += newPacks;
			totalReplacement += replacement;
			totalRejected += rejected;
		}

		const totalBeneficiariesInput = document.getElementById('form7TotalBeneficiaries');
		const totalNewInput = document.getElementById('form7TotalNew');
		const totalReplacementInput = document.getElementById('form7TotalReplacement');
		const totalReceivedInput = document.getElementById('form7TotalReceived');
		const totalRejectedInput = document.getElementById('form7TotalRejected');

		if (totalBeneficiariesInput) {
			totalBeneficiariesInput.value = String(totalBeneficiaries);
		}
		if (totalNewInput) {
			totalNewInput.value = String(totalNew);
		}
		if (totalReplacementInput) {
			totalReplacementInput.value = String(totalReplacement);
		}
		if (totalReceivedInput) {
			totalReceivedInput.value = String(totalNew + totalReplacement);
		}
		if (totalRejectedInput) {
			totalRejectedInput.value = String(totalRejected);
		}
	};

	// --- BMI report templates: auto-computed totals ---
	const bmiNsKeys = ['sw', 'w', 'n', 'ow', 'ob'];
	const bmiHfaKeys = ['ss', 'st', 'hn', 't'];
	const bmiAllKeys = bmiNsKeys.concat(bmiHfaKeys);

	const bmiField = (name) => document.querySelector(`[data-field="${name}"]`);

	const bmiValue = (name) => {
		const input = bmiField(name);
		const parsed = Number(input ? input.value : 0);
		return Number.isFinite(parsed) ? parsed : 0;
	};

	const setBmiValue = (name, value) => {
		const input = bmiField(name);
		if (input) {
			input.value = String(value);
		}
	};

	const computeBmiTable = (prefix) => {
		['male', 'female'].forEach((sex) => {
			setBmiValue(`${prefix}_${sex}_nst`, bmiNsKeys.reduce((sum, key) => sum + bmiValue(`${prefix}_${sex}_${key}`), 0));
			setBmiValue(`${prefix}_${sex}_hfat`, bmiHfaKeys.reduce((sum, key) => sum + bmiValue(`${prefix}_${sex}_${key}`), 0));
		});

		bmiAllKeys.concat(['nst', 'hfat']).forEach((key) => {
			setBmiValue(`${prefix}_total_${key}`, bmiValue(`${prefix}_male_${key}`) + bmiValue(`${prefix}_female_${key}`));
		});
	};

	const bmiBaselineGrades = ['bmib_g7', 'bmib_g8', 'bmib_g9', 'bmib_g10'];

	const computeBmiBaseline = () => {
		bmiBaselineGrades.forEach(computeBmiTable);

		['male', 'female'].forEach((sex) => {
			bmiAllKeys.forEach((key) => {
				setBmiValue(`bmib_overall_${sex}_${key}`, bmiBaselineGrades.reduce((sum, grade) => sum + bmiValue(`${grade}_${sex}_${key}`), 0));
			});
		});

		computeBmiTable('bmib_overall');
	};

	const computeBmiSummary = () => {
		computeBmiTable('bmis_m1');
		computeBmiTable('bmis_m2');
	};

	// --- Masterlist template: dynamic rows + draft ---
	const mlTbody = document.getElementById('mlTbody');
	const mlStatusNode = document.getElementById('mlDraftStatus');
	const addMlRowsBtn = document.getElementById('addMlRowsBtn');
	const saveMlDraftBtn = document.getElementById('saveMlDraftBtn');
	const clearMlDraftBtn = document.getElementById('clearMlDraftBtn');
	const printMlBtn = document.getElementById('printMlBtn');
	const mlStorageKey = 'feeding_masterlist_draft_v1';
	const mlBaseRowCount = 20;

	const renumberMlRows = () => {
		if (!mlTbody) {
			return;
		}

		Array.from(mlTbody.querySelectorAll('.ml-row')).forEach((row, index) => {
			const rowNumber = index + 1;
			const numCell = row.querySelector('.ml-num');
			if (numCell) {
				numCell.textContent = String(rowNumber);
			}

			row.querySelectorAll('[data-field]').forEach((input) => {
				const key = input.getAttribute('data-field');
				if (!key) {
					return;
				}
				input.setAttribute('data-field', key.replace(/^ml_row\d+_/, `ml_row${rowNumber}_`));
			});
		});
	};

	const addMlRows = (count) => {
		if (!mlTbody) {
			return;
		}

		const firstRow = mlTbody.querySelector('.ml-row');
		if (!firstRow) {
			return;
		}

		for (let i = 0; i < count; i++) {
			const clone = firstRow.cloneNode(true);
			clone.querySelectorAll('input').forEach((input) => {
				input.value = '';
			});
			mlTbody.appendChild(clone);
		}

		renumberMlRows();
	};

	const saveMlDraft = () => {
		if (!mlTbody) {
			return;
		}

		const payload = {
			rowCount: mlTbody.querySelectorAll('.ml-row').length,
			values: {},
		};

		document.querySelectorAll('[data-field^="ml_"]').forEach((field) => {
			const key = field.getAttribute('data-field');
			if (!key) {
				return;
			}
			payload.values[key] = String(field.value || '').trim();
		});

		window.localStorage.setItem(mlStorageKey, JSON.stringify(payload));
		if (mlStatusNode) {
			mlStatusNode.textContent = `Draft saved on ${stamp()}.`;
		}
	};

	const loadMlDraft = () => {
		if (!mlTbody) {
			return;
		}

		try {
			const raw = window.localStorage.getItem(mlStorageKey);
			if (!raw) {
				return;
			}

			const parsed = JSON.parse(raw);
			const rowCount = Math.max(mlBaseRowCount, Number(parsed.rowCount || mlBaseRowCount));
			const missingRows = rowCount - mlTbody.querySelectorAll('.ml-row').length;
			if (missingRows > 0) {
				addMlRows(missingRows);
			}

			if (parsed.values && typeof parsed.values === 'object') {
				document.querySelectorAll('[data-field^="ml_"]').forEach((field) => {
					const key = field.getAttribute('data-field');
					if (!key) {
						return;
					}
					field.value = typeof parsed.values[key] === 'string' ? parsed.values[key] : '';
				});
			}

			if (mlStatusNode) {
				mlStatusNode.textContent = 'Draft loaded from local storage.';
			}
		} catch (_error) {
			if (mlStatusNode) {
				mlStatusNode.textContent = 'Unable to load existing draft.';
			}
		}
	};

	const clearMlDraft = () => {
		if (!mlTbody) {
			return;
		}

		Array.from(mlTbody.querySelectorAll('.ml-row')).forEach((row, index) => {
			if (index < mlBaseRowCount) {
				row.querySelectorAll('input').forEach((input) => {
					input.value = '';
				});
				return;
			}
			row.remove();
		});

		document.querySelectorAll('[data-field^="ml_"]').forEach((field) => {
			field.value = '';
		});

		renumberMlRows();
		window.localStorage.removeItem(mlStorageKey);
		if (mlStatusNode) {
			mlStatusNode.textContent = 'Draft cleared.';
		}
	};

	initDraftModule({
		storageKey: 'sbfp_milk_form5_draft_v1',
		fieldPrefix: 'form5_',
		statusId: 'form5DraftStatus',
		saveId: 'saveForm5DraftBtn',
		clearId: 'clearForm5DraftBtn',
		printId: 'printForm5Btn',
	});

	initDraftModule({
		storageKey: 'sbfp_milk_form6_draft_v1',
		fieldPrefix: 'form6_',
		statusId: 'form6DraftStatus',
		saveId: 'saveForm6DraftBtn',
		clearId: 'clearForm6DraftBtn',
		printId: 'printForm6Btn',
	});

	initDraftModule({
		storageKey: 'sbfp_milk_form7_draft_v1',
		fieldPrefix: 'form7_',
		statusId: 'form7DraftStatus',
		saveId: 'saveForm7DraftBtn',
		clearId: 'clearForm7DraftBtn',
		printId: 'printForm7Btn',
	});

	initDraftModule({
		storageKey: 'feeding_bmi_baseline_draft_v1',
		fieldPrefix: 'bmib_',
		statusId: 'bmiBaselineDraftStatus',
		saveId: 'saveBmiBaselineDraftBtn',
		clearId: 'clearBmiBaselineDraftBtn',
		printId: 'printBmiBaselineBtn',
	});

	initDraftModule({
		storageKey: 'feeding_bmi_summary_draft_v1',
		fieldPrefix: 'bmis_',
		statusId: 'bmiSummaryDraftStatus',
		saveId: 'saveBmiSummaryDraftBtn',
		clearId: 'clearBmiSummaryDraftBtn',
		printId: 'printBmiSummaryBtn',
	});

	initDraftModule({
		storageKey: 'feeding_narrative_report_draft_v1',
		fieldPrefix: 'narr_',
		statusId: 'narrativeDraftStatus',
		saveId: 'saveNarrativeDraftBtn',
		clearId: 'clearNarrativeDraftBtn',
		printId: 'printNarrativeBtn',
	});

	const bmiBaselinePanel = document.getElementById('bmiBaselinePanel');
	if (bmiBaselinePanel) {
		bmiBaselinePanel.addEventListener('input', computeBmiBaseline);
	}

	const bmiSummaryPanel = document.getElementById('bmiSummaryPanel');
	if (bmiSummaryPanel) {
		bmiSummaryPanel.addEventListener('input', computeBmiSummary);
	}

	if (addMlRowsBtn) {
		addMlRowsBtn.addEventListener('click', () => {
			addMlRows(10);
			if (mlStatusNode) {
				mlStatusNode.textContent = 'Rows added. Save draft to keep changes.';
			}
		});
	}

	if (saveMlDraftBtn) {
		saveMlDraftBtn.addEventListener('click', saveMlDraft);
	}

	if (clearMlDraftBtn) {
		clearMlDraftBtn.addEventListener('click', clearMlDraft);
	}

	if (printMlBtn) {
		printMlBtn.addEventListener('click', () => window.print());
	}

	document.querySelectorAll('[data-form7-beneficiaries],[data-form7-new],[data-form7-replacement],[data-field^="form7_row"][data-field$="_rejected"]').forEach((input) => {
		input.addEventListener('input', updateForm7Totals);
	});

	if (form7aTablesContainer) {
		form7aTablesContainer.addEventListener('input', (event) => {
			const input = event.target.closest('input');
			if (!input) {
				return;
			}
			const block = input.closest('.form7a-block');
			updateForm7aTableTotals(block);
		});
	}

	if (addForm7aTableBtn) {
		addForm7aTableBtn.addEventListener('click', addForm7aTable);
	}

	if (saveForm7aDraftBtn) {
		saveForm7aDraftBtn.addEventListener('click', saveForm7aDraft);
	}

	if (clearForm7aDraftBtn) {
		clearForm7aDraftBtn.addEventListener('click', clearForm7aDraft);
	}

	if (printForm7aBtn) {
		printForm7aBtn.addEventListener('click', () => window.print());
	}

	if (templateSelect) {
		templateSelect.addEventListener('change', syncSelectedTemplate);
	}

	document.querySelectorAll('.narrative-textarea').forEach((textarea) => {
		textarea.addEventListener('input', () => autoGrowNarrative(textarea));
	});

	window.addEventListener('beforeprint', autoGrowAllNarratives);

	updateForm7Totals();
	loadForm7aDraft();
	loadMlDraft();
	computeBmiBaseline();
	computeBmiSummary();
	syncSelectedTemplate();
})();
</script>
@include('partials.sidebar-hover-pin')
</body>
</html>
