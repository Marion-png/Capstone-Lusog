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
	<script>document.documentElement.classList.add('js');</script>
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
	<div class="content">
		<div class="page-eyebrow">Feeding Program</div>
		<h1 class="page-title">SBFP <span>Forms</span></h1>
		<p class="page-sub">Select a form template, then encode the required fields in a clean sheet view.</p>

		<section class="selector-wrap">
			<div class="selector-row">
				<label class="selector-label" for="formTemplateSelect">Select Form Template:</label>
				<select id="formTemplateSelect" class="selector-input" aria-label="Select SBFP form template">
					<option value="">Choose a form...</option>
					<option value="bmi-baseline">BMI Report - Baseline Nutritional Assessment (Grades 7-10)</option>
					<option value="bmi-final">BMI Report - Final Nutritional Assessment (Grades 7-10)</option>
					<option value="feeding-narrative">Feeding Program - Narrative Report</option>
					<option value="feeding-masterlist">Feeding Program - Masterlist of Qualified Recipients</option>
				</select>
			</div>
			<div class="selector-row">
				<label class="selector-label" for="gradeLevelSelect">Grade Level (auto-fill from adviser records):</label>
				<select id="gradeLevelSelect" class="selector-input" aria-label="Select grade level to auto-fill">
					<option value="">All Grade Level</option>
					@forelse (($gradeOptions ?? []) as $gradeOption)
						<option value="{{ $gradeOption }}">{{ $gradeOption }}</option>
					@empty
						<option value="" disabled>No students on file yet</option>
					@endforelse
				</select>
			</div>
		</section>

		<div class="placeholder-panel" id="emptyStatePanel">
			Please select a form template to open the encoder.
		</div>

		<section class="sheet-wrap form-panel" id="bmiBaselinePanel" data-template="bmi-baseline">
			<div class="sheet-tools">
				<div class="sheet-btns">
					<button type="button" class="btn btn-ghost" id="printBmiBaselineBtn">Print Form</button>
				</div>
			</div>

			<div class="form-sheet">
				<div class="form-sheet-inner">
					<div class="report-head">
						<div class="report-school">{{ session('active_school_name', 'School name not set') }}</div>
						<input type="text" class="report-addr-input" data-field="bmib_school_address" placeholder="School address" aria-label="School address">
						<div class="report-banner">Baseline Nutritional Assessment (BMI) Report</div>
						<div class="report-sy">S.Y. <input type="text" class="inline-line-input" data-field="bmib_school_year" value="{{ $schoolYear }}" aria-label="School year"></div>
					</div>

					<div class="report-body">
						@foreach ([7, 8, 9, 10] as $grade)
							<div class="bmi-grade-block" data-grade="{{ $grade }}">
								<div class="grade-title">GRADE {{ $grade }} BMI</div>
								@include('feedingcor-dashboard.partials.bmi-table', ['prefix' => 'bmib_g' . $grade, 'editable' => false, 'values' => $bmiValues])
							</div>
						@endforeach

						<div class="bmi-grade-block" data-grade="overall">
							<div class="grade-title grade-title-overall">OVERALL BMI</div>
							@include('feedingcor-dashboard.partials.bmi-table', ['prefix' => 'bmib_overall', 'editable' => false, 'values' => $bmiValues])
						</div>
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

		<section class="sheet-wrap form-panel" id="bmiFinalPanel" data-template="bmi-final">
			<div class="sheet-tools">
				<div class="sheet-btns">
					<button type="button" class="btn btn-ghost" id="printBmiFinalBtn">Print Form</button>
				</div>
			</div>

			<div class="form-sheet">
				<div class="form-sheet-inner">
					<div class="report-head">
						<div class="report-school">{{ session('active_school_name', 'School name not set') }}</div>
						<input type="text" class="report-addr-input" data-field="bmif_school_address" placeholder="School address" aria-label="School address">
						<div class="report-banner">Final Nutritional Assessment (BMI) Report</div>
						<div class="report-sy">S.Y. <input type="text" class="inline-line-input" data-field="bmif_school_year" value="{{ $schoolYear }}" aria-label="School year"></div>
					</div>

					<div class="report-body">
						@foreach ([7, 8, 9, 10] as $grade)
							<div class="bmi-grade-block" data-grade="{{ $grade }}">
								<div class="grade-title">GRADE {{ $grade }} BMI</div>
								@include('feedingcor-dashboard.partials.bmi-table', ['prefix' => 'bmif_g' . $grade, 'editable' => false, 'values' => $bmiValues])
							</div>
						@endforeach

						<div class="bmi-grade-block" data-grade="overall">
							<div class="grade-title grade-title-overall">OVERALL BMI</div>
							@include('feedingcor-dashboard.partials.bmi-table', ['prefix' => 'bmif_overall', 'editable' => false, 'values' => $bmiValues])
						</div>
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
	const gradeLevelSelect = document.getElementById('gradeLevelSelect');
	const emptyStatePanel = document.getElementById('emptyStatePanel');

	// Adviser-entered students grouped by grade level (one grade per form).
	const studentsByGrade = @json($studentsByGrade ?? []);

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
	const landscapeTemplates = ['bmi-baseline', 'bmi-final'];

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

	// --- Auto-fill roster forms from adviser records (one grade at a time) ---
	const gradeStudents = (grade) => (Array.isArray(studentsByGrade[grade]) ? studentsByGrade[grade] : []);

	const fillMasterlistForGrade = (grade) => {
		if (!mlTbody) {
			return;
		}

		const qualified = gradeStudents(grade).filter((student) => student && student.qualified);

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
			mlStatusNode.textContent = qualified.length > 0
				? `Loaded ${qualified.length} qualified ${grade} student(s) from adviser records. Save draft to keep changes.`
				: `No qualified (Wasted / Severely Wasted / Underweight) ${grade} students on file.`;
		}
	};

	const applyGradeAutofill = (grade) => {
		if (!grade) {
			return;
		}
		fillMasterlistForGrade(grade);
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
			applyGradeAutofill(gradeLevelSelect.value);
			filterBmiGrades(gradeLevelSelect.value);
		});
	}

	document.querySelectorAll('.narrative-textarea').forEach((textarea) => {
		textarea.addEventListener('input', () => autoGrowNarrative(textarea));
	});

	window.addEventListener('beforeprint', autoGrowAllNarratives);

	loadMlDraft();
	filterBmiGrades(gradeLevelSelect ? gradeLevelSelect.value : '');
	syncSelectedTemplate();
})();
</script>
@include('partials.role-page-transition')
</body>
</html>
