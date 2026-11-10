{{-- ── School Form 2 (SF2) summary ─────────────────────────────────────
     Computed by App\Support\Sf2AttendanceForm from the marks and the
     enrollment stamps; nothing here is typed. Needs: $summary, $monthLabel. --}}
@php
	$figure = fn ($value, string $suffix = ''): string => $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 2), '0'), '.').$suffix;
@endphp
<section class="card sf2-summary" aria-labelledby="sf2SummaryTitle">
	<div class="sf2-summary-meta">
		<h2 class="card-title" id="sf2SummaryTitle">Summary</h2>
		<dl>
			<div><dt>Month</dt><dd>{{ $monthLabel }}</dd></div>
			<div><dt>No. of feeding days</dt><dd data-summary="feeding_days">{{ $summary['feeding_days'] }}</dd></div>
		</dl>
	</div>
	<div class="table-scroll">
		<table class="sf2-sum">
			<thead>
				<tr><th scope="col"></th><th scope="col">M</th><th scope="col">F</th><th scope="col">Total</th></tr>
			</thead>
			<tbody>
				@foreach ([
					'enrolment_start' => ['Enrollment as of '.\Illuminate\Support\Carbon::parse($summary['cutoff'])->format('F j, Y'), ''],
					'late_enrolment' => ['Late enrollment during the month', ''],
					'registered' => ['Registered learners as of end of month', ''],
					'percent_enrolment' => ['Percentage of enrollment as of end of month', '%'],
					'ada' => ['Average daily attendance', ''],
					'percent_attendance' => ['Percentage of attendance for the month', '%'],
					'consecutive_absent' => ['Absent for 5 consecutive days', ''],
					'dropped' => ['Removed from the program', ''],
				] as $key => [$label, $suffix])
					<tr data-summary-row="{{ $key }}">
						<th scope="row">{{ $label }}</th>
						@foreach (['Male', 'Female', 'Total'] as $sex)
							<td data-sex="{{ $sex }}" @if ($key === 'registered') data-registered="{{ $summary['registered'][$sex] }}" @endif>{{ $figure($summary[$key][$sex], $suffix) }}</td>
						@endforeach
					</tr>
				@endforeach
			</tbody>
		</table>
	</div>
</section>
