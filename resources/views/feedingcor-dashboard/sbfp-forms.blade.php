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
	<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
	<script>document.documentElement.classList.add('js');</script>
	<style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
	@php $pageCssPath = resource_path('css/feeding-sbfp-forms.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
	<style>{!! file_get_contents(resource_path('css/role-sidebar.css')) !!}</style>
	<style id="printPageStyle">@page{size:A4 portrait;margin:10mm}</style>
</head>
<body>
@include('partials.feedingcor-sidebar', ['active' => 'forms'])

<div class="main">
	<header class="topbar">
		<div class="topbar-bc"><span>Dashboard</span><span class="bc-sep">&rsaquo;</span><span>SBFP Forms</span></div>
		@include('partials.live-clock')
	</header>

	<div class="content">
		<div class="page-header">
			<h1 class="page-title">SBFP <span>Forms</span></h1>
			<p class="page-sub">Select a form template, then encode the required fields in a clean sheet view.</p>
		</div>

		<section class="selector-wrap">
			<div class="selector-grid">
				<div>
					<label class="selector-label" for="formTemplateSelect">Form Template</label>
					<select id="formTemplateSelect" class="select selector-input" aria-label="Select SBFP form template">
						<option value="">Choose a form...</option>
						<optgroup label="Reports (auto-tabulated)">
							{{-- One entry, not two: which weighing the grid reports is
							     the Nutritional Report control's answer, so a
							     coordinator who wants both does not have to open the
							     page twice. --}}
							<option value="bmi-report">BMI Report - Nutritional Assessment ({{ \App\Support\FeedingBeneficiarySummary::gradeRangeLabel() }})</option>
						</optgroup>
						<optgroup label="Feeding Program (hand-encoded)">
							<option value="feeding-narrative">Feeding Program - Narrative Report</option>
							<option value="feeding-masterlist">Feeding Program - Masterlist of Qualified Recipients</option>
						</optgroup>
					</select>
				</div>
				<div id="reportPhaseField">
					<label class="selector-label" for="reportPhaseSelect">Nutritional Report</label>
					<select id="reportPhaseSelect" class="select selector-input" aria-label="Select which nutritional assessment to report">
						<option value="baseline">Baseline</option>
						<option value="endline">Endline</option>
						<option value="both">Both Baseline and Endline</option>
					</select>
				</div>
				<div>
					<label class="selector-label" for="gradeLevelSelect">Grade Level <span class="muted">(auto-fill from adviser records)</span></label>
					<select id="gradeLevelSelect" class="select selector-input" aria-label="Select grade level to auto-fill">
						<option value="">All Grade Level</option>
						@forelse (($gradeOptions ?? []) as $gradeOption)
							<option value="{{ $gradeOption }}">{{ $gradeOption }}</option>
						@empty
							<option value="" disabled>No students on file yet</option>
						@endforelse
					</select>
				</div>
				<div>
					{{-- Cascades off the grade, because a section belongs to one:
					     offering every section in the school would let a
					     coordinator pick a pair that names nobody. --}}
					<label class="selector-label" for="sectionSelect">Section</label>
					<select id="sectionSelect" class="select selector-input" aria-label="Select section">
						<option value="">All Sections</option>
					</select>
				</div>
			</div>
		</section>

		<div class="empty-panel placeholder-panel" id="emptyStatePanel">
			Please select a form template to open the encoder.
		</div>

		<section class="sheet-wrap form-panel" id="bmiBaselinePanel" data-template="bmi-report" data-phase="baseline">
			<div class="sheet-tools">
				<div class="sheet-btns">
					<button type="button" class="btn btn-ghost" id="printBmiBaselineBtn">Print Form</button>
				</div>
			</div>

			<div class="form-sheet">
				<div class="form-sheet-inner">
					<div class="report-head">
						@include('feedingcor-dashboard.partials.form-letterhead', ['addressField' => 'bmib_school_address'])
						<div class="report-banner">Baseline Nutritional Assessment (BMI) Report</div>
						<div class="report-sy">S.Y. <input type="text" class="inline-line-input" data-field="bmib_school_year" value="{{ $schoolYear }}" aria-label="School year"></div>
						<div class="report-scope" data-scope-line></div>
					</div>

					<div class="report-body">
						@foreach (\App\Support\FeedingBeneficiarySummary::GRADE_LEVELS as $grade)
							<div class="bmi-grade-block" data-grade="{{ $grade }}">
								<div class="grade-title">GRADE {{ $grade }} BMI</div>
								@include('feedingcor-dashboard.partials.bmi-table', ['prefix' => 'bmib_g' . $grade, 'editable' => false, 'values' => $bmiValues])
							</div>
						@endforeach

						<div class="bmi-grade-block" data-grade="overall">
							<div class="grade-title grade-title-overall">OVERALL BMI</div>
							@include('feedingcor-dashboard.partials.bmi-table', ['prefix' => 'bmib_overall', 'editable' => false, 'values' => $bmiValues])
						</div>

						{{-- The grids read as a picture, at the foot of the form and on the
						     printed copy: same columns, same numbers, same order. --}}
						@include('feedingcor-dashboard.partials.bmi-chart', ['phase' => 'baseline', 'prefix' => 'bmib'])
					</div>

					<div class="foot-grid cols-3">
						<div class="foot-block">
							<div class="foot-label">Prepared by:</div>
							<input type="text" class="sign-input" data-field="bmib_prepared_name" value="{{ $nurseName }}" placeholder="Full name" aria-label="Prepared by name">
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

		<section class="sheet-wrap form-panel" id="bmiFinalPanel" data-template="bmi-report" data-phase="endline">
			<div class="sheet-tools">
				<div class="sheet-btns">
					<button type="button" class="btn btn-ghost" id="printBmiFinalBtn">Print Form</button>
				</div>
			</div>

			<div class="form-sheet">
				<div class="form-sheet-inner">
					<div class="report-head">
						@include('feedingcor-dashboard.partials.form-letterhead', ['addressField' => 'bmif_school_address'])
						<div class="report-banner">Final Nutritional Assessment (BMI) Report</div>
						<div class="report-sy">S.Y. <input type="text" class="inline-line-input" data-field="bmif_school_year" value="{{ $schoolYear }}" aria-label="School year"></div>
						<div class="report-scope" data-scope-line></div>
					</div>

					<div class="report-body">
						@foreach (\App\Support\FeedingBeneficiarySummary::GRADE_LEVELS as $grade)
							<div class="bmi-grade-block" data-grade="{{ $grade }}">
								<div class="grade-title">GRADE {{ $grade }} BMI</div>
								@include('feedingcor-dashboard.partials.bmi-table', ['prefix' => 'bmif_g' . $grade, 'editable' => false, 'values' => $bmiValues])
							</div>
						@endforeach

						<div class="bmi-grade-block" data-grade="overall">
							<div class="grade-title grade-title-overall">OVERALL BMI</div>
							@include('feedingcor-dashboard.partials.bmi-table', ['prefix' => 'bmif_overall', 'editable' => false, 'values' => $bmiValues])
						</div>

						{{-- The grids read as a picture, at the foot of the form and on the
						     printed copy: same columns, same numbers, same order. --}}
						@include('feedingcor-dashboard.partials.bmi-chart', ['phase' => 'endline', 'prefix' => 'bmif'])
					</div>

					<div class="foot-grid cols-3">
						<div class="foot-block">
							<div class="foot-label">Prepared by:</div>
							<input type="text" class="sign-input" data-field="bmif_prepared_name" value="{{ $nurseName }}" placeholder="Full name" aria-label="Prepared by name">
							<input type="text" class="foot-role-input" data-field="bmif_prepared_role" placeholder="School Clinic Nurse / Teacher" aria-label="Prepared by designation">
						</div>
						<div class="foot-block">
							<div class="foot-label">Attested by:</div>
							<input type="text" class="sign-input" data-field="bmif_attested_name" placeholder="Full name" aria-label="Attested by name">
							<input type="text" class="foot-role-input" data-field="bmif_attested_role" placeholder="MAPEH Department Head" aria-label="Attested by designation">
						</div>
						<div class="foot-block">
							<div class="foot-label">Noted by:</div>
							<input type="text" class="sign-input" data-field="bmif_noted_name" placeholder="Full name" aria-label="Noted by name">
							<input type="text" class="foot-role-input" data-field="bmif_noted_role" placeholder="Principal III" aria-label="Noted by designation">
						</div>
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
						@include('feedingcor-dashboard.partials.form-letterhead', ['addressField' => 'narr_school_address'])
						<div class="report-title-lines">Report on the Feeding Program of {{ $letterhead['school'] }} Funded by <input type="text" class="inline-line-input inline-wide" data-field="narr_funder" placeholder="Sponsor / Funding partner" aria-label="Funding partner"></div>
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
						@include('feedingcor-dashboard.partials.form-letterhead', ['addressField' => 'ml_school_address'])
						<div class="report-title-lines">Masterlists of Identified Severely Wasted and Wasted Students Who Are Qualified for Feeding Program</div>
						<div class="report-sy">S.Y. <input type="text" class="inline-line-input" data-field="ml_school_year" value="{{ $schoolYear }}" aria-label="School year"></div>
						<div class="report-scope" data-scope-line></div>
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
	const gradeLevelSelect = document.getElementById('gradeLevelSelect');
	const sectionSelect = document.getElementById('sectionSelect');
	const reportPhaseSelect = document.getElementById('reportPhaseSelect');
	const reportPhaseField = document.getElementById('reportPhaseField');
	const emptyStatePanel = document.getElementById('emptyStatePanel');

	// Adviser-entered students grouped by grade level (one grade per form).
	const studentsByGrade = @json($studentsByGrade ?? []);
	// Each grade's own sections, so Section only ever offers what the chosen
	// grade actually runs.
	const sectionsByGrade = @json($sectionsByGrade ?? []);
	// The DepEd grid, counted server-side once for the whole school ('') and
	// once per section. Every field behind it is encrypted at rest, so the
	// counting cannot happen in SQL and must not happen twice — the grid, the
	// chart and the table view are all read from these same numbers.
	const bmiValueSets = @json($bmiValueSets ?? []);
	const gradeLevels = @json(\App\Support\FeedingBeneficiarySummary::GRADE_LEVELS);
	// The chart's eleven columns and three rows, read from the one place that
	// declares them, so the picture and the grid can never fall out of step.
	const chartColumns = Object.keys(@json(\App\Support\BmiAssessmentReport::chartColumns()));
	const series = ['male', 'female', 'total'];

	// A clean scale for a count axis: the smallest 1, 2 or 5 × 10ⁿ STEP that
	// covers the tallest column in at most four intervals, so every gridline is
	// a whole multiple of one step. Mirrors BmiAssessmentReport::axisScale(),
	// which draws the first paint and the printed copy — keep the two in step.
	const axisScale = (peak) => {
		peak = Math.max(0, peak);
		let step = 1;
		while (Math.ceil(peak / step) > 4) {
			const magnitude = 10 ** Math.floor(Math.log10(step));
			const lead = Math.round(step / magnitude);
			step = lead === 1 ? 2 * magnitude : (lead === 2 ? 5 * magnitude : 10 * magnitude);
		}
		const intervals = Math.max(1, Math.ceil(peak / step));
		const ticks = [];
		for (let t = intervals; t >= 0; t--) {
			ticks.push(step * t);
		}
		return { max: step * intervals, ticks };
	};

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
	const landscapeTemplates = ['bmi-report'];

	// ── The three filters, read in one place ──────────────────────────────
	// Grade and Section are scope: they decide which grid is on screen and
	// which children it counted. "Nutritional Report" decides which weighing
	// is reported — baseline, endline, or both side by side.
	const currentScope = () => ({
		grade: String(gradeLevelSelect ? gradeLevelSelect.value : ''),
		section: String(sectionSelect ? sectionSelect.value : ''),
		phase: String(reportPhaseSelect ? reportPhaseSelect.value : 'baseline'),
	});

	// "Grade 7" -> "g7"; nothing (All Grade Levels) -> the OVERALL grid.
	const gradeKeyFor = (grade) => {
		const match = String(grade || '').match(/(\d{1,2})/);
		if (!match) {
			return 'overall';
		}
		return gradeLevels.includes(Number(match[1])) ? `g${match[1]}` : 'overall';
	};

	// The value set a scope reads. A section is counted on its own; anything
	// wider reads the whole-school set, whose per-grade grids are already the
	// grade's own figures.
	const valuesFor = (scope) => {
		if (scope.grade && scope.section) {
			const key = `${scope.grade} / ${scope.section}`;
			if (bmiValueSets[key]) {
				return bmiValueSets[key];
			}
		}
		return bmiValueSets[''] || {};
	};

	const scopeLabel = (scope) => {
		const grade = scope.grade || 'All Grade Levels';
		const section = scope.section || 'All Sections';
		return `${grade} · ${section}`;
	};

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
		const scope = currentScope();
		let anyActive = false;
		document.querySelectorAll('.form-panel').forEach((panel) => {
			const phase = panel.getAttribute('data-phase');
			// A BMI panel is on screen when its template is chosen AND the
			// Nutritional Report control asked for its weighing. "Both" shows
			// the pair, one under the other, so a coordinator can read the two
			// weighings without opening the page twice.
			const phaseWanted = phase === null || scope.phase === 'both' || scope.phase === phase;
			const isActive = selected !== '' && panel.getAttribute('data-template') === selected && phaseWanted;
			panel.classList.toggle('active', isActive);
			anyActive = anyActive || isActive;
		});
		emptyStatePanel.style.display = anyActive ? 'none' : '';

		// The report control only answers a question the BMI report asks.
		if (reportPhaseField) {
			reportPhaseField.style.display = selected === 'bmi-report' ? '' : 'none';
		}

		if (printPageStyle) {
			const orientation = landscapeTemplates.includes(selected) ? 'landscape' : 'portrait';
			printPageStyle.textContent = `@page{size:A4 ${orientation};margin:10mm}`;
		}

		if (selected === 'feeding-narrative') {
			autoGrowAllNarratives();
		}

		if (selected === 'feeding-masterlist' && !mlDraftLoaded && !mlAutofilled) {
			mlAutofilled = true;
			fillMasterlist(currentScope());
		}
	};

	// ── The grids and their charts, repainted from the precomputed sets ────
	// Nothing is recomputed here: the cells, the bars and the table view all
	// read the same server-counted numbers, so the picture and the form can
	// never report different children.
	const paintReports = () => {
		const scope = currentScope();
		const values = valuesFor(scope);
		const gradeKey = gradeKeyFor(scope.grade);
		const label = scopeLabel(scope);

		document.querySelectorAll('.bmi-input[data-field]').forEach((input) => {
			const key = input.getAttribute('data-field');
			if (!key || !(key in values)) {
				return;
			}
			const value = values[key];
			input.value = value === null || value === undefined ? '' : String(value);
		});

		// The scope printed on the form itself, so a filed copy says which
		// children it counted. Blank when nothing is narrowed.
		document.querySelectorAll('[data-scope-line]').forEach((node) => {
			node.textContent = (scope.grade || scope.section) ? label : '';
		});

		// ── The charts ────────────────────────────────────────────────
		// Each chart is a picture of the grid above it, so it is redrawn from
		// the very numbers just written into that grid: same eleven columns,
		// same three rows, same value set. Nothing is recomputed but the axis.
		document.querySelectorAll('[data-chart]').forEach((chart) => {
			const prefix = chart.getAttribute('data-chart-prefix');
			// Each chart keeps its own grid key — a chart under GRADE 8 BMI
			// draws Grade 8 whatever the filter is set to; the filter decides
			// which charts are on screen, not what they contain.
			const chartGrade = chart.getAttribute('data-chart-grade');
			const at = (sex, col) => Number(values[`${prefix}_${chartGrade}_${sex}_${col}`] || 0);

			let peak = 0;
			chartColumns.forEach((col) => {
				series.forEach((sex) => { peak = Math.max(peak, at(sex, col)); });
			});
			const axis = axisScale(peak);

			chart.querySelectorAll('[data-chart-group]').forEach((group) => {
				const col = group.getAttribute('data-chart-group');
				group.querySelectorAll('[data-chart-col]').forEach((column) => {
					const sex = column.getAttribute('data-chart-col');
					const value = at(sex, col);
					column.style.height = `${Math.round((value / axis.max) * 10000) / 100}%`;
					const figure = column.querySelector('[data-chart-figure]');
					// A zero prints no label: a row of noughts over an empty
					// axis is noise, and the grid above already says zero by
					// leaving the cell blank.
					if (figure) figure.textContent = value > 0 ? String(value) : '';
				});
			});

			// The axis is rebuilt, not relabelled: a different scale can want a
			// different number of intervals, and leaving the old rules in place
			// would draw four gaps labelled with five numbers.
			const yaxis = chart.querySelector('[data-chart-yaxis]');
			if (yaxis) {
				yaxis.innerHTML = axis.ticks
					.map((tick) => `<span class="chart-tick tnum">${tick}</span>`)
					.join('');
			}
			const rules = chart.querySelector('.chart-rules');
			if (rules) {
				rules.innerHTML = axis.ticks.map(() => '<i></i>').join('');
			}
		});
	};

	// Section cascades off grade: a section belongs to one grade, so offering
	// the school's whole list would let a coordinator pick a pair that names
	// nobody. "All Grade Level" therefore offers no section either — the
	// masterlist below it is already every grade's qualified learners.
	const syncSectionOptions = (keep) => {
		if (!sectionSelect) {
			return;
		}

		const grade = String(gradeLevelSelect ? gradeLevelSelect.value : '');
		const sections = Array.isArray(sectionsByGrade[grade]) ? sectionsByGrade[grade] : [];

		sectionSelect.innerHTML = '';
		const all = document.createElement('option');
		all.value = '';
		all.textContent = 'All Sections';
		sectionSelect.appendChild(all);

		sections.forEach((section) => {
			const option = document.createElement('option');
			option.value = section;
			option.textContent = section;
			sectionSelect.appendChild(option);
		});

		sectionSelect.disabled = sections.length === 0;
		sectionSelect.value = keep && sections.includes(keep) ? keep : '';
	};

	// BMI Baseline / Final reports are tabulated server-side and rendered
	// read-only, so there is no client-side compute or draft here — only print.
	['printBmiBaselineBtn', 'printBmiFinalBtn'].forEach((id) => {
		const btn = document.getElementById(id);
		if (btn) {
			btn.addEventListener('click', () => window.print());
		}
	});

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

	// Whether a saved draft is on screen. The masterlist auto-fills itself the
	// first time it is opened — otherwise "All Grade Level", the default, is
	// the one choice that never fires a change event and so would leave the
	// form blank until the coordinator picked a grade and picked it back. A
	// draft is never clobbered: hand-typed work outranks a re-fill.
	let mlDraftLoaded = false;
	let mlAutofilled = false;

	const loadMlDraft = () => {
		if (!mlTbody) {
			return;
		}

		try {
			const raw = window.localStorage.getItem(mlStorageKey);
			if (!raw) {
				return;
			}
			mlDraftLoaded = true;

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

	// --- Auto-fill the masterlist from adviser records ---
	//
	// "All Grade Level" means every grade, not "no grade": the Masterlist of
	// Qualified Recipients is the school's list of who the programme feeds, and
	// a coordinator who has not narrowed to one grade wants all of them, in
	// grade order. It used to fill nothing at all in that case, so the one
	// choice that asks for the whole school produced an empty form.
	const gradeStudents = (grade) => (Array.isArray(studentsByGrade[grade]) ? studentsByGrade[grade] : []);

	// Every covered grade in order, so an unfiltered masterlist reads Grade 7
	// first and Grade 10 last rather than in whatever order the roster came back.
	const orderedGrades = () => Object.keys(studentsByGrade).sort((a, b) => {
		const na = Number((a.match(/(\d{1,2})/) || [])[1] || 0);
		const nb = Number((b.match(/(\d{1,2})/) || [])[1] || 0);
		return na - nb || a.localeCompare(b);
	});

	const qualifiedFor = (scope) => {
		const grades = scope.grade ? [scope.grade] : orderedGrades();

		return grades
			.flatMap((grade) => gradeStudents(grade))
			.filter((student) => student && student.qualified)
			.filter((student) => scope.section === '' || student.section === scope.section);
	};

	const fillMasterlist = (scope) => {
		if (!mlTbody) {
			return;
		}

		const qualified = qualifiedFor(scope);

		// Trim back to the base rows, then grow to fit the qualified list.
		Array.from(mlTbody.querySelectorAll('.ml-row')).forEach((row, index) => {
			if (index >= mlBaseRowCount) {
				row.remove();
			}
		});
		const needed = Math.max(mlBaseRowCount, qualified.length);
		const current = mlTbody.querySelectorAll('.ml-row').length;
		if (needed > current) {
			addMlRows(needed - current);
		}

		Array.from(mlTbody.querySelectorAll('.ml-row')).forEach((row, index) => {
			const student = qualified[index] || null;
			const nameInput = row.querySelector('[data-field$="_name"]');
			const gradeInput = row.querySelector('[data-field$="_grade"]');
			const sectionInput = row.querySelector('[data-field$="_section"]');
			if (nameInput) nameInput.value = student ? student.name : '';
			if (gradeInput) gradeInput.value = student ? student.grade : '';
			if (sectionInput) sectionInput.value = student ? student.section : '';
		});

		if (mlStatusNode) {
			const where = scopeLabel(scope);
			mlStatusNode.textContent = qualified.length > 0
				? `Loaded ${qualified.length} qualified student(s) — ${where}. Save draft to keep changes.`
				: `No qualified (Wasted / Severely Wasted / Underweight) students on file for ${where}.`;
		}
	};

	// Baseline / Final BMI reports: a specific grade shows only that grade's
	// table (Overall hidden too); "All Grade Level" (empty) shows every grade.
	const filterBmiGrades = (gradeValue) => {
		const match = String(gradeValue || '').match(/(\d{1,2})/);
		const wanted = match ? match[1] : null;
		document.querySelectorAll('.bmi-grade-block').forEach((block) => {
			const grade = block.getAttribute('data-grade');
			block.style.display = (wanted === null || grade === wanted) ? '' : 'none';
		});
	};

	// One entry point for every filter, so the grid, the chart, the printed
	// scope line and the masterlist can never be looking at different children.
	const applyFilters = () => {
		const scope = currentScope();
		filterBmiGrades(scope.grade);
		paintReports();
		fillMasterlist(scope);
	};

	initDraftModule({
		storageKey: 'feeding_narrative_report_draft_v1',
		fieldPrefix: 'narr_',
		statusId: 'narrativeDraftStatus',
		saveId: 'saveNarrativeDraftBtn',
		clearId: 'clearNarrativeDraftBtn',
		printId: 'printNarrativeBtn',
	});

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

	if (templateSelect) {
		templateSelect.addEventListener('change', syncSelectedTemplate);
	}

	if (gradeLevelSelect) {
		gradeLevelSelect.addEventListener('change', () => {
			syncSectionOptions('');
			applyFilters();
		});
	}

	if (sectionSelect) {
		sectionSelect.addEventListener('change', applyFilters);
	}

	if (reportPhaseSelect) {
		reportPhaseSelect.addEventListener('change', syncSelectedTemplate);
	}

	document.querySelectorAll('.narrative-textarea').forEach((textarea) => {
		textarea.addEventListener('input', () => autoGrowNarrative(textarea));
	});

	window.addEventListener('beforeprint', autoGrowAllNarratives);

	loadMlDraft();
	syncSectionOptions('');
	// The first paint is the whole school, which is what the server rendered —
	// so the grid on screen and the grid in the markup start out the same.
	filterBmiGrades('');
	paintReports();
	syncSelectedTemplate();
})();
</script>
@include('partials.role-page-transition')
</body>
</html>
