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
     is the one thing this panel must not do. Every row of the scale is listed,
     zeros included, because a missing row reads as a bug. --}}
@php
	$nsPopulation = $nutritionStatus['population'] ?? \App\Http\Controllers\FeedingCoordinatorController::POPULATION_BENEFICIARIES;
	// The switch keeps every filter already applied and changes only the
	// population, so widening the breakdown never drops the chosen grade.
	$nsQuery = fn (string $value): string => '?'.http_build_query(
		array_filter(request()->query(), fn ($v, $k) => $k !== 'population' && $v !== '', ARRAY_FILTER_USE_BOTH)
		+ ['population' => $value]
	);

	$nsTotal = (int) ($nutritionStatus['total'] ?? 0);
	$nsRows = $nutritionStatus['rows'] ?? [];
	$nsMeasured = (int) ($nutritionStatus['endline_measured'] ?? 0);
	$nsPending = (int) ($nutritionStatus['endline_pending'] ?? 0);

	// Each status keeps the fill the School Head's charts give it, so one
	// status reads as one colour across both roles; a learner nobody weighed
	// is a hatch, never a colour, because they have no status to show.
	$nsKey = fn (string $label): string => match ($label) {
		'Severely Wasted' => 'sw',
		'Wasted' => 'w',
		'Normal' => 'n',
		'Obese' => 'ob',
		default => 'none',
	};
	$nsShare = fn (int $count): string => $nsTotal > 0 ? rtrim(rtrim(number_format($count / $nsTotal * 100, 1), '0'), '.').'%' : '—';
	$nsMix = collect($nsRows)->filter(fn (array $row): bool => $row['count'] > 0);
	// A school runs to thousands of learners, so every count is grouped.
	$nsNum = fn (int $n): string => number_format($n);
@endphp

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

<div class="ns-total">
	<span class="ns-total-value">{{ $nsNum($nsTotal) }}</span>
	<span class="ns-total-label">{{ $nutritionStatus['total_label'] ?? 'Total Beneficiaries' }}</span>
</div>

{{-- The baseline mix in one bar: every learner in the population, split by
     the status they were weighed at. The list below names every part. --}}
<div class="ns-mix" role="img"
	aria-label="Baseline: {{ $nsMix->map(fn (array $row): string => $row['label'].' '.$row['count'])->implode(', ') ?: 'no learners' }}">
	@foreach ($nsMix as $row)
		<span class="ns-seg is-{{ $nsKey($row['label']) }}" style="width: {{ round($row['count'] / max(1, $nsTotal) * 100, 2) }}%"
			data-tip-title="{{ $row['label'] }} &middot; Baseline"
			data-tip="{{ $nsNum($row['count']) }} of {{ $nsNum($nsTotal) }} ({{ $nsShare($row['count']) }})"></span>
	@endforeach
</div>

<table class="ns-table">
	<thead>
		<tr>
			<th>Status</th>
			<th class="ta-r">Baseline</th>
			<th class="ta-r">Endline</th>
		</tr>
	</thead>
	<tbody>
		@foreach ($nsRows as $row)
			<tr class="{{ $row['eligible'] ? 'is-eligible' : '' }} {{ $row['count'] === 0 && $row['endline'] === 0 ? 'is-zero' : '' }}">
				<td>
					<span class="ns-name"><i class="ns-key is-{{ $nsKey($row['label']) }}" aria-hidden="true"></i>{{ $row['label'] }}</span>
				</td>
				<td class="ta-r">
					<span class="ns-count">{{ $nsNum($row['count']) }}</span>
					<span class="ns-share">{{ $nsShare($row['count']) }}</span>
				</td>
				<td class="ta-r"><span class="ns-count">{{ $nsNum($row['endline']) }}</span></td>
			</tr>
		@endforeach
	</tbody>
</table>

{{-- How far the closing weigh-in has got. The endline column above only
     counts the learners on this line. --}}
<div class="ns-endline">
	<div class="ns-endline-head">
		<span>Endline weigh-in</span>
		<span class="ns-endline-figure"><strong>{{ $nsNum($nsMeasured) }}</strong> of {{ $nsNum($nsTotal) }}</span>
	</div>
	<div class="ns-endline-bar" role="img" aria-label="{{ $nsNum($nsMeasured) }} of {{ $nsNum($nsTotal) }} measured at endline">
		@if ($nsTotal > 0 && $nsMeasured > 0)
			<span style="width: {{ round($nsMeasured / $nsTotal * 100, 2) }}%"></span>
		@endif
	</div>
	@if ($nsPending > 0)
		<p class="ns-endline-note">{{ $nsNum($nsPending) }} not yet measured</p>
	@endif
</div>
