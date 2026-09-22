{{--
    Baseline against endline, one row per rung of the wasting scale.

    Design notes worth keeping:
    - Two series, not four status hues: the comparison the coordinator needs is
      "where were they, where are they now", so colour carries baseline vs
      endline and the row label carries the status. The pair is the theme's
      validated --series-risk / --series-healthy (ΔE 25.6 normal, 12.6 protan,
      both ≥ 3:1) — re-run the dataviz validator before changing either.
    - Both series share one scale, so a baseline bar and an endline bar of the
      same length are the same count.
    - Every bar is direct-labelled and the table view below repeats every
      number, so nothing is gated behind hue or hover.

    Needs $nutritionProgress.
--}}
@php
	$progress = $nutritionProgress ?? ['total' => 0, 'measured' => 0, 'improved' => 0, 'rate' => 0.0, 'rows' => []];
	$hasEndline = ($progress['measured'] ?? 0) > 0;

	// axisScale() returns the ticks largest first, for a column chart's y-axis.
	// This chart is horizontal, so it reads 0 on the left.
	$axisTicks = array_reverse($progress['ticks'] ?? [0, 1]);
@endphp

<div class="np-headline">
	<div class="np-figure">
		<span class="np-count">{{ $progress['improved'] }} / {{ $progress['total'] }}</span>
		<span class="np-rate {{ $hasEndline ? '' : 'is-idle' }}">{{ number_format((float) $progress['rate'], 0) }}%</span>
	</div>
	<span class="np-measured">{{ $progress['measured'] }} of {{ $progress['total'] }} measured at endline</span>
</div>

{{-- The headline says who improved; this says what happened to everyone
     else — remained wasted, regressed, not yet measured — as shares of the
     same roll. --}}
@if (isset($progress['split']))
	@include('partials.outcome-split', ['split' => $progress['split'], 'splitTitle' => 'Who improved, remained wasted or regressed'])
@endif

<div class="np-legend">
	<span class="np-legend-item"><i class="np-dot np-dot-baseline"></i>Baseline</span>
	<span class="np-legend-item"><i class="np-dot np-dot-endline"></i>Endline</span>
</div>

{{-- The bars sit in a ruled plot, not loose on the card: the rules are the
     only thing that turns a length into a count a coordinator can read off,
     and without them the longest bar is simply "the longest bar" whether it
     counts four learners or four hundred. The ticks are the axis the server
     stepped, so the chart and the figures beside it cannot disagree. --}}
{{-- One scope over the plot and its axis: the column widths are declared
     here, so the rules, the rows and the ticks all read the same figures
     instead of three copies that drift apart. --}}
<div class="np-graph">
<div class="np-plot">
	<div class="np-rules" aria-hidden="true">
		@foreach ($axisTicks as $tick)<i></i>@endforeach
	</div>

	<div class="np-chart">
		@foreach ($progress['rows'] as $row)
			<div class="np-row">
				<div class="np-label">{{ $row['label'] }}</div>
				<div class="np-bars">
					@foreach ([['baseline', 'Baseline'], ['endline', 'Endline']] as [$seriesKey, $seriesLabel])
						@php
							$count = (int) $row[$seriesKey];
							$share = $progress['total'] > 0 ? round(($count / $progress['total']) * 100) : 0;
						@endphp
						<div class="np-bar-line">
							<span class="np-track">
								{{-- A true zero draws no bar at all. A 2px stub reads as
								     "a few", which is the one thing a count of none must
								     never look like; the figure beside it says 0. --}}
								@if ($count > 0)
									<span class="np-bar np-bar-{{ $seriesKey }}" style="width: {{ $row[$seriesKey.'_pct'] }}%"
										data-tip-title="{{ $row['label'] }} &middot; {{ $seriesLabel }}"
										data-tip="{{ $count }} of {{ $progress['total'] }} beneficiaries ({{ $share }}%)"></span>
								@endif
							</span>
							<span class="np-value {{ $count === 0 ? 'is-zero' : '' }}">{{ $count }}</span>
						</div>
					@endforeach
				</div>
			</div>
		@endforeach
	</div>
</div>

{{-- Labelled last so the rules above are read as counts rather than as
     decoration. Aria-hidden: the table view carries every number as text. --}}
<div class="np-axis" aria-hidden="true">
	<span class="np-axis-pad"></span>
	{{-- Mirrors .np-bar-line exactly — track, then the figure gutter — so the
	     ticks line up with the rules by construction rather than by two
	     separate sums that have to be kept equal by hand. --}}
	<div class="np-axis-line">
		<div class="np-axis-ticks">
			@foreach ($axisTicks as $tick)<span class="tnum">{{ $tick }}</span>@endforeach
		</div>
		<span class="np-axis-gutter"></span>
	</div>
</div>
</div>

{{-- The WCAG-clean twin: every value the bars encode is readable as text. --}}
<details class="np-table">
	<summary>Table view</summary>
	<div class="table-scroll">
		<table>
			<thead>
				<tr><th>Status</th><th class="ta-r">Baseline</th><th class="ta-r">Endline</th><th class="ta-r">Change</th></tr>
			</thead>
			<tbody>
				@foreach ($progress['rows'] as $row)
					@php $delta = $row['endline'] - $row['baseline']; @endphp
					<tr>
						<td><strong>{{ $row['label'] }}</strong></td>
						<td class="ta-r tnum">{{ $row['baseline'] }}</td>
						<td class="ta-r tnum">{{ $row['endline'] }}</td>
						<td class="ta-r tnum">{{ $delta > 0 ? '+' : '' }}{{ $delta }}</td>
					</tr>
				@endforeach
			</tbody>
		</table>
	</div>
</details>
