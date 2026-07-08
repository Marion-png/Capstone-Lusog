@php
	/** @var string $prefix Field prefix, e.g. "bmib_g7" */
	$editable = $editable ?? true;
	$nsColumns = ['sw' => 'Severely Wasted', 'w' => 'Wasted', 'n' => 'Normal', 'ow' => 'Overweight', 'ob' => 'Obese'];
	$hfaColumns = ['ss' => 'Severely Stunted', 'st' => 'Stunted', 'hn' => 'Normal', 't' => 'Tall'];
@endphp
<table class="template-table bmi-table" aria-label="BMI nutritional status and height-for-age table">
	<thead>
		<tr>
			<th class="sex-col" rowspan="2">Sex</th>
			<th colspan="6">Nutritional Status</th>
			<th colspan="5">Height for Age (HFA)</th>
		</tr>
		<tr>
			@foreach ($nsColumns as $label)
				<th class="subhead">{{ $label }}</th>
			@endforeach
			<th class="subhead">Total</th>
			@foreach ($hfaColumns as $label)
				<th class="subhead">{{ $label }}</th>
			@endforeach
			<th class="subhead">Total</th>
		</tr>
	</thead>
	<tbody>
		@foreach (['male' => 'MALE', 'female' => 'FEMALE', 'total' => 'TOTAL'] as $sexKey => $sexLabel)
			@php $rowEditable = $editable && $sexKey !== 'total'; @endphp
			<tr>
				<td class="sex-cell">{{ $sexLabel }}</td>
				@foreach (array_keys($nsColumns) as $key)
					<td><input type="number" min="0" class="cell-input bmi-input" data-field="{{ $prefix }}_{{ $sexKey }}_{{ $key }}" @if ($rowEditable) placeholder="0" @else readonly @endif></td>
				@endforeach
				<td><input type="number" class="cell-input bmi-input" data-field="{{ $prefix }}_{{ $sexKey }}_nst" readonly></td>
				@foreach (array_keys($hfaColumns) as $key)
					<td><input type="number" min="0" class="cell-input bmi-input" data-field="{{ $prefix }}_{{ $sexKey }}_{{ $key }}" @if ($rowEditable) placeholder="0" @else readonly @endif></td>
				@endforeach
				<td><input type="number" class="cell-input bmi-input" data-field="{{ $prefix }}_{{ $sexKey }}_hfat" readonly></td>
			</tr>
		@endforeach
	</tbody>
</table>
