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
@endphp

<div class="np-headline">
	<div class="np-figure">
		<span class="np-count">{{ $progress['improved'] }} / {{ $progress['total'] }}</span>
		<span class="np-rate {{ $hasEndline ? '' : 'is-idle' }}">{{ number_format((float) $progress['rate'], 1) }}%</span>
	</div>
	<span class="np-measured">{{ $progress['measured'] }} of {{ $progress['total'] }} measured at endline</span>
</div>

<div class="np-legend">
	<span class="np-legend-item"><i class="np-dot np-dot-baseline"></i>Baseline</span>
	<span class="np-legend-item"><i class="np-dot np-dot-endline"></i>Endline</span>
</div>

<div class="np-chart">
	@foreach ($progress['rows'] as $row)
		<div class="np-row">
			<div class="np-label">{{ $row['label'] }}</div>
			<div class="np-bars">
				<div class="np-bar-line">
					<span class="np-bar np-bar-baseline" style="width: {{ $row['baseline_pct'] }}%"></span>
					<span class="np-value">{{ $row['baseline'] }}</span>
				</div>
				<div class="np-bar-line">
					<span class="np-bar np-bar-endline" style="width: {{ $row['endline_pct'] }}%"></span>
					<span class="np-value">{{ $row['endline'] }}</span>
				</div>
			</div>
		</div>
	@endforeach
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
