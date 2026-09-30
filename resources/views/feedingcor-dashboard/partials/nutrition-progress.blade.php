{{--
    Baseline against endline, one row per rung of the wasting scale.

    Design notes worth keeping:
    - Two columns: the result on the left (the improvement rate, then who
      improved, remained wasted or regressed), the evidence on the right (the
      status bars it was counted from). Each figure is printed once.
    - Two series, not four status hues: the comparison the coordinator needs is
      "where were they, where are they now", so colour carries baseline vs
      endline and the row label carries the status. The pair is the theme's
      validated --series-risk / --series-healthy (ΔE 25.6 normal, 12.6 protan,
      both ≥ 3:1) — re-run the dataviz validator before changing either.
    - Both series share one scale, so a baseline bar and an endline bar of the
      same length are the same count.
    - Every bar carries its count at its own end, and the table view below
      repeats every number, so nothing is gated behind hue or hover.

    Needs $nutritionProgress.
--}}
@php
	$progress = $nutritionProgress ?? ['total' => 0, 'measured' => 0, 'improved' => 0, 'rate' => 0.0, 'rows' => []];
	$hasEndline = ($progress['measured'] ?? 0) > 0;

	// axisScale() returns the ticks largest first, for a column chart's y-axis.
	// This chart is horizontal, so it reads 0 on the left.
	$axisTicks = array_reverse($progress['ticks'] ?? [0, 1]);

	// A school runs to thousands of learners, so every count is grouped.
	$npNum = fn (int|float $n): string => number_format((float) $n);
@endphp

<div class="np-layout">
	<div class="np-summary">
		{{-- The rate's denominator is every beneficiary, never only those
		     re-measured, so it cannot creep up while few endlines exist. It
		     reads grey until there is an endline to count from. --}}
		<div class="np-hero {{ $hasEndline ? '' : 'is-idle' }}">
			<span class="np-eyebrow">Improved</span>
			<span class="np-rate">{{ number_format((float) $progress['rate'], 0) }}%</span>
			<span class="np-count"><strong>{{ $npNum($progress['improved']) }}</strong> of {{ $npNum($progress['total']) }} {{ \Illuminate\Support\Str::plural('beneficiary', (int) $progress['total']) }}</span>
		</div>

		{{-- The headline says who improved; this says what happened to everyone
		     else — remained wasted, regressed, not yet measured — as shares of
		     the same roll. --}}
		@if (isset($progress['split']))
			@include('partials.outcome-split', ['split' => $progress['split'], 'splitTitle' => 'Outcome at endline'])
		@endif
	</div>

	<div class="np-evidence">
		<div class="np-chart-head">
			<span class="np-chart-title">Beneficiaries by status</span>
			<div class="np-legend">
				<span class="np-legend-item"><i class="np-dot np-dot-baseline"></i>Baseline</span>
				<span class="np-legend-item"><i class="np-dot np-dot-endline"></i>Endline</span>
			</div>
		</div>

		@if ((int) $progress['total'] === 0)
			{{-- A chart of zeros drawn against an axis of one says nothing a
			     sentence cannot say better. --}}
			<p class="np-empty">No beneficiaries enrolled yet.</p>
		@else
		{{-- The bars sit in a ruled plot, not loose on the card: the rules are
		     the only thing that turns a length into a count a coordinator can
		     read off. The ticks are the axis the server stepped, so the chart
		     and the figures beside it cannot disagree. One scope over the plot
		     and its axis: the column widths are declared once, so the rules,
		     the rows and the ticks all read the same figures. --}}
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
									{{-- The count rides at the end of its own bar. A true
									     zero draws no bar at all — a 2px stub reads as "a
									     few", which a count of none must never look like —
									     and the 0 sits on the axis instead. --}}
									<div class="np-track">
										@if ($count > 0)
											<span class="np-bar np-bar-{{ $seriesKey }}" style="width: {{ $row[$seriesKey.'_pct'] }}%"
												data-tip-title="{{ $row['label'] }} &middot; {{ $seriesLabel }}"
												data-tip="{{ $npNum($count) }} of {{ $npNum($progress['total']) }} beneficiaries ({{ $share }}%)"></span>
										@endif
										<span class="np-value {{ $count === 0 ? 'is-zero' : '' }}">{{ $npNum($count) }}</span>
									</div>
								@endforeach
							</div>
						</div>
					@endforeach
				</div>
			</div>

			{{-- Labelled last so the rules above are read as counts rather than
			     as decoration. Aria-hidden: the table view carries every number
			     as text. --}}
			<div class="np-axis" aria-hidden="true">
				<span class="np-axis-pad"></span>
				<div class="np-axis-ticks">
					@foreach ($axisTicks as $tick)<span class="tnum">{{ $npNum($tick) }}</span>@endforeach
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
								<td class="ta-r tnum">{{ $npNum($row['baseline']) }}</td>
								<td class="ta-r tnum">{{ $npNum($row['endline']) }}</td>
								<td class="ta-r tnum">{{ $delta > 0 ? '+' : '' }}{{ $npNum($delta) }}</td>
							</tr>
						@endforeach
					</tbody>
				</table>
			</div>
		</details>
		@endif
	</div>
</div>
