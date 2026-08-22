{{--
    The grids above, read as a picture — one clustered column chart per grade,
    at the foot of the form, and it prints with the form.

    It is a picture *of the grid it follows*, not a second reading: the same
    eleven columns in the same order from `BmiAssessmentReport::chartColumns()`,
    the same three rows (MALE / FEMALE / TOTAL), the same numbers. So the chart
    and the table can never disagree, and the table is also the chart's own
    table view — every value a column encodes is printed as text a few
    centimetres above it, on the same sheet of paper.

    TOTAL is deliberately a *neutral*, not a third hue: it is the sum of the two
    beside it rather than a peer category, and painting a rollup like a series
    invites it to be read as one. The two real series are the validated
    `--sh-ob` blue and `--sh-w` orange from the School Head's status set; the
    trio passes the CVD, normal-vision and contrast checks on the white sheet
    (worst all-pairs ΔE 15.0 CVD / 15.2 normal, all ≥ 3:1). Re-run the dataviz
    validator before changing any of the three.

    Every column carries its own value above it and the legend names the three
    series, so identity and magnitude are both readable without colour — which
    is what makes it survive a school printer.

    Needs $phase, $prefix, $bmiValues, $schoolYear.
--}}
@php
	use App\Support\BmiAssessmentReport;

	$columns = BmiAssessmentReport::chartColumns();
	$series = ['male' => 'MALE', 'female' => 'FEMALE', 'total' => 'TOTAL'];
	// One chart per grid on the form, including OVERALL, so the grade filter
	// moves a chart and its table together.
	$chartKeys = array_merge(BmiAssessmentReport::gradeKeys(), ['overall']);

	$cell = fn (string $gradeKey, string $sex, string $col): int => (int) ($bmiValues[$prefix.'_'.$gradeKey.'_'.$sex.'_'.$col] ?? 0);
@endphp

<div class="bmi-charts">
	<div class="bmi-charts-head">Data Visualization &mdash; {{ $phase === 'baseline' ? 'Baseline' : 'Endline' }} Nutritional Assessment</div>

	@foreach ($chartKeys as $gradeKey)
		@php
			$peak = 0;
			foreach (array_keys($columns) as $col) {
				foreach (array_keys($series) as $sex) {
					$peak = max($peak, $cell($gradeKey, $sex, $col));
				}
			}
			// Every gridline a whole multiple of one step, so a column's height
			// can be read off the rules rather than guessed between them.
			$axis = BmiAssessmentReport::axisScale($peak);
			$axisMax = $axis['max'];
			$ticks = $axis['ticks'];
		@endphp

		<figure class="bmi-chart bmi-grade-block" data-grade="{{ $gradeKey === 'overall' ? 'overall' : substr($gradeKey, 1) }}"
			data-chart data-chart-grade="{{ $gradeKey }}" data-chart-prefix="{{ $prefix }}">
			<figcaption class="chart-title">
				{{ BmiAssessmentReport::gridTitle($gradeKey) }}
				<span class="chart-sy">{{ $schoolYear }}</span>
			</figcaption>

			<div class="chart-body">
				<div class="chart-yaxis" data-chart-yaxis>
					@foreach ($ticks as $tick)
						<span class="chart-tick tnum">{{ $tick }}</span>
					@endforeach
				</div>

				<div class="chart-plot">
					{{-- Recessive rules the eye reads a height against; the data
					     sits over them, never behind them. --}}
					<div class="chart-rules" aria-hidden="true">
						@foreach ($ticks as $tick)<i></i>@endforeach
					</div>

					<div class="chart-groups">
						@foreach ($columns as $col => $label)
							{{-- The grid rules a line between the two halves, so the
							     chart does too: everything left of it is BMI-for-age,
							     everything right is height-for-age. --}}
							<div class="chart-group {{ $col === 'ss' ? 'is-band-start' : '' }}" data-chart-group="{{ $col }}">
								<div class="chart-cluster">
									@foreach ($series as $sex => $sexLabel)
										@php
											$value = $cell($gradeKey, $sex, $col);
											$height = $axisMax > 0 ? round(($value / $axisMax) * 100, 2) : 0;
										@endphp
										<span class="chart-col is-{{ $sex }}" data-chart-col="{{ $sex }}"
											style="height: {{ $height }}%"
											title="{{ $sexLabel }} &middot; {{ $label }}: {{ $value }}">
											{{-- A zero prints no label: a row of noughts above an
											     empty axis is noise, and the grid above says zero
											     by leaving the cell blank. --}}
											<b class="chart-figure tnum" data-chart-figure>{{ $value > 0 ? $value : '' }}</b>
										</span>
									@endforeach
								</div>
								<div class="chart-xlabel">{{ $label }}</div>
							</div>
						@endforeach
					</div>
				</div>
			</div>

			<div class="chart-legend">
				@foreach ($series as $sex => $sexLabel)
					<span class="chart-legend-item"><i class="chart-swatch is-{{ $sex }}"></i>{{ $sexLabel }}</span>
				@endforeach
			</div>
		</figure>
	@endforeach
</div>
