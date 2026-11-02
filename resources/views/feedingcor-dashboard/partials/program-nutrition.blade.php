{{-- The beneficiaries' nutritional status at baseline against endline — the
     coordinator's Nutritional Progress reading (FeedingNutritionProgress),
     drawn for the nurse.

     Laid out as a small table of bars: one row per status, one column per
     weighing, each column headed with its name. So which bar is which is
     read off the heading, not guessed from a colour, and a zero is an empty
     track under its heading rather than a figure floating beside nothing.
     Both columns share one scale, so equal lengths are equal counts. The
     colours are the theme's validated --series-risk / --series-healthy pair
     (ΔE 25.6 normal, 12.6 protan, both ≥ 3:1), and every bar prints its
     count as text.

     **The endline column is drawn only once somebody has been re-measured**
     — a column of zeros would read as "everyone left this status", the
     opposite of "not yet weighed". Under it sits the shared outcome split,
     whose "not yet measured" segment is how a mid-cycle page says so without
     a 0% headline. --}}
@php
	$np = $nutritionProgress ?? ['total' => 0, 'measured' => 0, 'rows' => [], 'axis_max' => 1];
	$npHasEndline = (int) ($np['measured'] ?? 0) > 0;
	$npLabels = [
		'Severely Wasted' => 'Severely Wasted',
		'Wasted' => 'Wasted',
		'Normal' => 'Normal',
		\App\Support\FeedingNutritionProgress::ABOVE_NORMAL => 'Above Normal',
	];
@endphp
<div class="card-head">
	<div>
		<h2 class="card-title">Nutritional Status</h2>
		<p class="card-sub">
			<span class="tnum">{{ (int) $np['total'] }}</span> {{ \Illuminate\Support\Str::plural('beneficiary', (int) $np['total']) }}
			&middot;
			@if ($npHasEndline)
				<span class="tnum">{{ (int) $np['measured'] }}</span> re-measured at endline
			@else
				endline not yet measured
			@endif
		</p>
	</div>
</div>

@if ((int) $np['total'] === 0)
	<div class="empty-panel fp-empty">No beneficiary is enrolled yet.</div>
@else
	<div class="fp-nut {{ $npHasEndline ? 'has-endline' : '' }}" role="table" aria-label="Beneficiaries by nutritional status, baseline{{ $npHasEndline ? ' and endline' : '' }}">
		<div class="fp-nut-row fp-nut-head" role="row">
			<span role="columnheader" class="fp-nut-label">Status</span>
			<span role="columnheader" class="fp-nut-col"><i class="fp-dot is-baseline"></i>Baseline</span>
			@if ($npHasEndline)
				<span role="columnheader" class="fp-nut-col"><i class="fp-dot is-endline"></i>Endline</span>
			@endif
		</div>
		@foreach ($np['rows'] as $row)
			@php $label = $npLabels[$row['label']] ?? $row['label']; @endphp
			<div class="fp-nut-row" role="row">
				<span role="rowheader" class="fp-nut-label">{{ $label }}</span>
				<span role="cell" class="fp-nut-cell">
					<span class="fp-track" data-tip-title="{{ $label }} · Baseline" data-tip="{{ $row['baseline'] }} {{ \Illuminate\Support\Str::plural('beneficiary', $row['baseline']) }}">
						<span class="fp-track-fill is-baseline" style="width: {{ $row['baseline_pct'] }}%"></span>
					</span>
					<b class="fp-nut-figure tnum">{{ $row['baseline'] }}</b>
				</span>
				@if ($npHasEndline)
					<span role="cell" class="fp-nut-cell">
						<span class="fp-track" data-tip-title="{{ $label }} · Endline" data-tip="{{ $row['endline'] }} {{ \Illuminate\Support\Str::plural('beneficiary', $row['endline']) }}">
							<span class="fp-track-fill is-endline" style="width: {{ $row['endline_pct'] }}%"></span>
						</span>
						<b class="fp-nut-figure tnum">{{ $row['endline'] }}</b>
					</span>
				@endif
			</div>
		@endforeach
	</div>

	@if (isset($np['split']))
		@include('partials.outcome-split', ['split' => $np['split'], 'splitTitle' => 'Outcome at endline'])
	@endif

	<details class="fp-table-view">
		<summary>Table view</summary>
		<div class="table-scroll">
			<table>
				<thead>
					<tr>
						<th>Status</th>
						<th class="num">Baseline</th>
						<th class="num">Endline</th>
					</tr>
				</thead>
				<tbody>
					@foreach ($np['rows'] as $row)
						<tr>
							<td>{{ $npLabels[$row['label']] ?? $row['label'] }}</td>
							<td class="num">{{ $row['baseline'] }}</td>
							<td class="num">{{ $npHasEndline ? $row['endline'] : '—' }}</td>
						</tr>
					@endforeach
				</tbody>
			</table>
		</div>
	</details>
@endif
