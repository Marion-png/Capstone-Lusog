{{--
	The class advisers' blank CLASS MASTERLIST, drawn on the School Nurse's
	Forms & Templates page.

	Every row comes from App\Support\ClassMasterlistTemplate::printRows(), the
	same model the .xlsx download is written from, so the sheet on screen and
	the file are one form. It is blank by design: an adviser lists their class on
	it, and the import reads it back. Nothing here is a fact about a child.

	A line the form has no value for — the school's address, the class, the
	adviser's name — prints as a rule to write on, never an invented value.

	Expects: $rows (printRows()), $widths (the model's column widths).
--}}
@php
	$cmTotalWidth = max(1, array_sum($widths));
	$cmBanner = [
		\App\Support\ClassMasterlistTemplate::R_SCHOOL,
		\App\Support\ClassMasterlistTemplate::R_HEADING,
		\App\Support\ClassMasterlistTemplate::R_CLASS,
	];
@endphp
<section class="sheet-wrap form-panel" id="classMasterlistPanel" data-template="class-masterlist">
	<div class="sheet-tools">
		<div class="sheet-status">The blank form class advisers fill in and upload to enrol their class.</div>
		<div class="sheet-btns">
			<a href="{{ route('dashboard.school-nurse.forms.class-masterlist') }}" class="btn btn-primary">Download Template (.xlsx)</a>
			<button type="button" class="btn btn-ghost" id="printClassMasterlistBtn">Print Form</button>
		</div>
	</div>

	<div class="form-sheet">
		<div class="form-sheet-inner cm-sheet">
			<table class="cm-table" aria-label="Class masterlist template">
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
</section>
