{{--
	One class's CLASS MASTERLIST, filled from the roster, as the School Nurse
	and the Clinic Teacher see it on Forms & Templates. Fetched for the class
	chosen (ClinicClassMasterlistController::sheet) into #classMasterlistPanel.

	It opens under the same heading as every form template on the page — the
	shared `form-letterhead` (seals, Republic / Department / Region / Division,
	the school and its address), then the title, the school year and the class
	— so the two kinds of sheet read as one set. Below it, every row comes from
	App\Support\ClassMasterlistTemplate::printRows(), the same model the .xlsx
	download is written from; the model's own banner rows are left out here
	because the heading above says the same thing. A line the form has no value
	for (the adviser) prints as a rule to write on, never an invented value.

	Expects: $rows (printRows()), $widths (the model's column widths),
	$downloadUrl (this class's download under the reader's own path),
	$letterhead (SchoolLetterhead::for), $seals (SchoolLetterhead::seals),
	$classLabel ("GRADE 7 - MATIYAGA"), $schoolYear.
--}}
@php
	$cmTotalWidth = max(1, array_sum($widths));
	$cmBanner = [
		\App\Support\ClassMasterlistTemplate::R_SCHOOL,
		\App\Support\ClassMasterlistTemplate::R_HEADING,
		\App\Support\ClassMasterlistTemplate::R_CLASS,
	];
	$rows = array_values(array_filter($rows, fn (array $row): bool => ! in_array($row['register'], $cmBanner, true)));
@endphp
<div class="sheet-tools">
	<div class="sheet-btns">
		<a href="{{ $downloadUrl }}" class="btn btn-secondary">Download Excel</a>
		<button type="button" class="btn btn-ghost" data-print-class-masterlist>Print Form</button>
	</div>
</div>

<div class="form-sheet">
	<div class="form-sheet-inner">
		<div class="report-head">
			@include('feedingcor-dashboard.partials.form-letterhead')
			<div class="report-title-lines">{{ \App\Support\ClassMasterlistTemplate::TITLE }}</div>
			<div class="report-sy">S.Y. <span class="inline-line-input">{{ $schoolYear }}</span></div>
			<div class="report-scope">{{ $classLabel }}</div>
		</div>

		<div class="cm-sheet">
		<table class="cm-table" aria-label="Class masterlist">
			<colgroup>
				@foreach ($widths as $width)
					<col style="width: {{ round($width / $cmTotalWidth * 100, 2) }}%">
				@endforeach
			</colgroup>
			<tbody>
				@php $cmAfterSignature = false; @endphp
				@foreach ($rows as $row)
					<tr class="cm-{{ $row['register'] }}">
						@foreach ($row['cells'] as $cell)
							@php
								$cmText = trim($cell['text']);
								// The caption is the one heading the form sets in
								// sentence case; everything else in the head is caps.
								$cmCaption = $cmText === \App\Support\ClassMasterlistTemplate::NAME_CAPTION;
								$cmWriteOn = $cmText === '' && (in_array($row['register'], $cmBanner, true) || $row['signature']);
							@endphp
							<td @if ($cell['colspan'] > 1) colspan="{{ $cell['colspan'] }}" @endif
								@if ($cell['rowspan'] > 1) rowspan="{{ $cell['rowspan'] }}" @endif
								@class(['cm-caption' => $cmCaption, 'cm-signature' => $row['signature']])>
								@if ($cmWriteOn)
									<span class="cm-blank" aria-label="Blank line to write on"></span>
								@elseif ($row['signature'])
									<span class="cm-signed">{{ $cmText }}</span>
								@elseif ($cmAfterSignature)
									{{-- The signatory label, centred under the line above it. --}}
									<span class="cm-label">{{ $cmText }}</span>
								@else
									{{ $cmText }}
								@endif
							</td>
						@endforeach
					</tr>
					@php $cmAfterSignature = $row['signature']; @endphp
				@endforeach
			</tbody>
		</table>
		</div>
	</div>
</div>
