{{-- Nutritional status, at both weighings, for the population the coordinator
     chose.

     **Beneficiaries** is the programme — qualified and enrolled — so its counts
     always sum to the Beneficiary card above and the two panels can never tell
     different stories. **All Students** widens to every learner in a covered
     grade, which is the only place a Normal or Obese learner is counted: they
     are never beneficiaries, and they are still the school's children.

     Baseline and Endline sit side by side and are never merged. Endline counts
     only learners who have actually been re-measured, so it is allowed to sum to
     less than the total and says how many are still to come — an unmeasured
     learner is not "unchanged", and filing them under a status nobody recorded
     is the one thing this panel must not do. --}}
@php
	$nsPopulation = $nutritionStatus['population'] ?? \App\Http\Controllers\FeedingCoordinatorController::POPULATION_BENEFICIARIES;
	// The switch keeps every filter already applied and changes only the
	// population, so widening the breakdown never drops the chosen grade.
	$nsQuery = fn (string $value): string => '?'.http_build_query(
		array_filter(request()->query(), fn ($v, $k) => $k !== 'population' && $v !== '', ARRAY_FILTER_USE_BOTH)
		+ ['population' => $value]
	);
@endphp

<div class="ns-controls">
	<div class="ns-total">
		<span class="ns-total-label">{{ $nutritionStatus['total_label'] ?? 'Total Beneficiaries' }}</span>
		<span class="ns-total-value">{{ $nutritionStatus['total'] ?? 0 }}</span>
	</div>

	{{-- A link group, not a <select>: the panel is re-rendered by the live
	     refresh, and a select would lose its selection every time the poll
	     replaced the markup. --}}
	<nav class="ns-switch" aria-label="Nutritional status population">
		@foreach (\App\Http\Controllers\FeedingCoordinatorController::populationOptions() as $option)
			<a class="ns-switch-opt {{ $nsPopulation === $option['value'] ? 'is-active' : '' }}"
				href="{{ $nsQuery($option['value']) }}"
				@if ($nsPopulation === $option['value']) aria-current="true" @endif>{{ $option['label'] }}</a>
		@endforeach
	</nav>
</div>

<table class="ns-table">
	<thead>
		<tr>
			<th>Nutritional Status</th>
			<th class="ta-r">Baseline</th>
			<th class="ta-r">Endline</th>
		</tr>
	</thead>
	<tbody>
		@foreach (($nutritionStatus['rows'] ?? []) as $row)
			<tr class="{{ $row['eligible'] ? 'is-eligible' : '' }}">
				<td><span class="badge {{ $row['badge'] }}">{{ $row['label'] }}</span></td>
				<td class="ta-r ns-count">{{ $row['count'] }}</td>
				<td class="ta-r ns-count">{{ $row['endline'] }}</td>
			</tr>
		@endforeach
	</tbody>
	<tfoot>
		<tr>
			<td class="ns-foot-label">Endline measured</td>
			<td class="ta-r ns-count">&mdash;</td>
			<td class="ta-r ns-count">{{ $nutritionStatus['endline_measured'] ?? 0 }}</td>
		</tr>
		@if (($nutritionStatus['endline_pending'] ?? 0) > 0)
			<tr>
				<td class="ns-foot-label">Not yet measured</td>
				<td class="ta-r ns-count">&mdash;</td>
				<td class="ta-r ns-count">{{ $nutritionStatus['endline_pending'] }}</td>
			</tr>
		@endif
	</tfoot>
</table>
