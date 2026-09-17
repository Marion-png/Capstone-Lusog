{{--
    Programme outlook — the feeding programme projected from its own history.

    Two readings a head is asked for and has answered from memory: how many
    learners the next cycle will have to feed (the budget request) and whether
    the programme is working (the effectiveness review). Both are trends
    across completed cycles, and both are drawn only once there are at least
    FeedingProgramForecast::MIN_CYCLES of them on record. Until then the panel
    reports what it has and what it needs — a line through one point is a
    guess with a chart behind it, and this is what a budget request must not
    carry.

    Every figure is labelled a projection, every input is printed in the
    history table under it, and no peso rate is compiled in: the projected
    feeding days are the roll times the school's own cycle length, and the
    Division's per-meal rate is applied by the reader.

    Needs $outlook (from FeedingProgramForecast::for()).
--}}
@php
	$projection = $outlook['projection'] ?? null;
	$fmtRate = fn ($rate): string => $rate === null ? '—' : rtrim(rtrim(number_format((float) $rate, 1), '0'), '.').'%';
	$trendWord = fn (string $t): string => match ($t) { 'rising' => 'rising', 'falling' => 'falling', default => 'steady' };
	$trendGlyph = fn (string $t): string => match ($t) { 'rising' => '↗', 'falling' => '↘', default => '→' };
@endphp
<section class="card section" id="programOutlook">
	<div class="section-head">
		<h2 class="section-title">Programme Outlook</h2>
		<div class="section-meta">
			Projected from {{ $outlook['completed'] }} completed {{ \Illuminate\Support\Str::plural('cycle', $outlook['completed']) }}
			&middot; a cycle counts once a prior year has an endline on record
		</div>
	</div>

	@if (! $outlook['ready'])
		<div class="alert-bar is-info" style="margin-bottom:14px">
			<div class="alert-body">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
				<div>
					<strong>Predictive analytics will open after {{ \App\Support\FeedingProgramForecast::MIN_CYCLES }} completed cycles.</strong>
					<span>
						{{ $outlook['completed'] }} on record so far &middot; {{ $outlook['needed'] }} more {{ \Illuminate\Support\Str::plural('cycle', $outlook['needed']) }} needed.
						A projection through fewer would read as evidence it is not, so the trend is held until the history exists.
					</span>
				</div>
			</div>
		</div>
	@else
		<div class="sh-figs cols-3">
			<div class="sh-fig">
				<div class="sh-fig-label">Projected beneficiaries, S.Y. {{ $projection['school_year'] }}</div>
				<div class="sh-fig-value">{{ number_format($projection['beneficiaries']) }}</div>
				<div class="sh-fig-hint">Enrolment {{ $trendWord($projection['beneficiaries_trend']) }} {{ $trendGlyph($projection['beneficiaries_trend']) }} across completed cycles</div>
			</div>
			<div class="sh-fig">
				<div class="sh-fig-label">Projected feeding days to fund</div>
				<div class="sh-fig-value">{{ number_format($projection['meals']) }}</div>
				<div class="sh-fig-hint">{{ number_format($projection['beneficiaries']) }} learners &times; {{ $projection['feeding_days'] }}-day cycle &middot; apply the Division's per-meal rate</div>
			</div>
			<div class="sh-fig">
				<div class="sh-fig-label">Projected improvement rate</div>
				<div class="sh-fig-value">{{ $fmtRate($projection['improved_rate']) }}</div>
				<div class="sh-fig-hint">Share climbing the wasting scale, {{ $trendWord($projection['improvement_trend']) }} {{ $trendGlyph($projection['improvement_trend']) }}</div>
			</div>
		</div>
	@endif

	{{-- The inputs, always printed: a projection nobody can check against
	     its history is a number, not an analysis. --}}
	<div class="table-card" style="margin-top:16px">
		<div class="table-scroll">
			<table>
				<thead>
					<tr>
						<th>School year</th>
						<th class="num">Beneficiaries</th>
						<th class="num">Measured at endline</th>
						<th class="num">Improved</th>
						<th class="num">Improvement rate</th>
						<th class="num">Still wasted</th>
						<th>Standing</th>
					</tr>
				</thead>
				<tbody>
					@forelse ($outlook['history'] as $row)
						<tr>
							<td><strong>{{ $row['school_year'] }}</strong></td>
							<td class="num tnum">{{ number_format($row['beneficiaries']) }}</td>
							<td class="num tnum">{{ number_format($row['measured']) }}</td>
							<td class="num tnum">{{ number_format($row['improved']) }}</td>
							<td class="num tnum">{{ $fmtRate($row['improved_rate']) }}</td>
							<td class="num tnum">{{ number_format($row['still_wasted']) }}</td>
							<td>
								@if ($row['completed'])
									<span class="badge badge-normal">Completed cycle</span>
								@elseif ($row['school_year'] === \App\Models\StudentHealthRecord::currentSchoolYear())
									<span class="badge badge-neutral">In progress</span>
								@else
									<span class="badge badge-monitor">No endline on record</span>
								@endif
							</td>
						</tr>
					@empty
						<tr><td colspan="7" class="table-empty">No school year on record yet.</td></tr>
					@endforelse
					@if ($projection !== null)
						<tr class="sh-outlook-projection">
							<td><strong>{{ $projection['school_year'] }}</strong> <span class="badge badge-neutral">Projection</span></td>
							<td class="num tnum">{{ number_format($projection['beneficiaries']) }}</td>
							<td class="num tnum">—</td>
							<td class="num tnum">—</td>
							<td class="num tnum">{{ $fmtRate($projection['improved_rate']) }}</td>
							<td class="num tnum">—</td>
							<td>Least-squares trend through the completed cycles</td>
						</tr>
					@endif
				</tbody>
			</table>
		</div>
	</div>
</section>
