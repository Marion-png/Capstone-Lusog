{{-- The prioritised intervention list: who needs attention, in the order they
     need it.

     Every figure here is derived from the marks the Attendance tab recorded —
     there is no way to change a mark from this screen, and there should not be.
     A learner no confirmed session has covered shows an em dash, never 0%, and
     an unconfirmed mark votes neither way.

     A row opens its learner's record in the details dialog. The record itself
     is rendered below in a <template> per row: it stays out of the document
     flow (and off the printer) until the dialog asks for it, and because it is
     server-rendered with the row, the dialog can never show a figure the row
     has moved past. --}}
@php
	$priorityTone = [
		'high' => 'is-high',
		'medium' => 'is-medium',
		'low' => 'is-low',
	];

	$severityBadge = [
		\App\Support\FeedingRiskSeverity::CRITICAL => 'badge-critical',
		\App\Support\FeedingRiskSeverity::AT_RISK => 'badge-risk',
		\App\Support\FeedingRiskSeverity::WATCH => 'badge-monitor',
	];

	$trendLabel = [
		'improving' => 'Improving',
		'declining' => 'Declining',
		'steady' => 'Consistent',
	];
@endphp

<div class="table-card ar-listcard">
	<div class="ar-listhead">
		<div>
			<p class="card-title">Priority Follow-Up List</p>
			<p class="card-sub">{{ $listedCount }} {{ \Illuminate\Support\Str::plural('beneficiary', $listedCount) }} &middot; at-risk threshold {{ $cards['threshold_label'] }}%</p>
		</div>
		<div class="lg-search ar-search">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
			<input type="search" id="arSearch" placeholder="Search name or section" autocomplete="off" aria-label="Search the at-risk list by name or section">
		</div>
	</div>

	<div class="table-scroll">
		<table class="ar-table" id="arTable">
			<thead>
				<tr>
					<th>Priority</th>
					<th>Student</th>
					<th>Grade</th>
					<th>Section</th>
					<th class="num">Present</th>
					<th class="num">Absent</th>
					<th class="num">Attendance</th>
					<th class="num">Days Remaining</th>
					<th>Risk</th>
					<th>Follow-Up</th>
				</tr>
			</thead>
			<tbody>
				@forelse ($rows as $row)
					<tr class="ar-row" data-row="{{ $row['id'] }}"
						data-name="{{ $row['name'] }}"
						data-meta="{{ trim($row['grade'].($row['section'] !== '' ? ' — '.$row['section'] : '')) }}"
						data-rate="{{ $row['rate'] !== null ? number_format($row['rate'], 1).'%' : '—' }}"
						data-standing="{{ \App\Support\FeedingRiskSeverity::severityLabel($row['severity']) }}"
						data-standing-badge="{{ $severityBadge[$row['severity']] ?? 'badge-neutral' }}"
						data-search="{{ strtolower(trim($row['name'].' '.$row['grade'].' '.$row['section'])) }}">
						<td>
							<span class="ar-prio {{ $priorityTone[$row['priority']] ?? '' }}">
								{{ \App\Support\FeedingRiskSeverity::priorityLabel($row['priority']) }}
							</span>
						</td>
						{{-- The name opens the learner's record, and the whole cell
						     is the target — the same anatomy the Beneficiaries tab
						     uses, so a coordinator aims at the column rather than
						     at the glyphs. --}}
						<td class="ar-name is-link">
							<button type="button" class="ar-namebtn" data-detail-open="{{ $row['id'] }}"
								aria-haspopup="dialog" aria-label="Details for {{ $row['name'] }}">
								<strong>{{ $row['name'] }}</strong>
							</button>
						</td>
						<td>{{ $row['grade_number'] !== '' ? $row['grade_number'] : '—' }}</td>
						<td>{{ $row['section'] }}</td>
						<td class="num tnum">{{ $row['present'] }}</td>
						<td class="num tnum">{{ $row['absent'] }}</td>
						<td class="num tnum">{{ $row['rate'] !== null ? number_format($row['rate'], 1).'%' : '—' }}</td>
						<td class="num tnum">{{ $row['days_remaining'] }}</td>
						<td>
							<span class="badge {{ $severityBadge[$row['severity']] ?? 'badge-neutral' }}">
								{{ \App\Support\FeedingRiskSeverity::severityLabel($row['severity']) }}
							</span>
						</td>
						<td>
							<span class="badge {{ $row['follow_up']['badge'] }}">{{ $row['follow_up']['status_label'] }}</span>
						</td>
					</tr>
				@empty
					<tr>
						<td colspan="10" class="table-empty">
							@if ($cards['beneficiaries'] === 0)
								No beneficiaries are enrolled for this school year.
							@elseif ($filters['risk'] !== '' || $filters['attendance'] !== '' || $filters['absences'] !== '' || $filters['follow_up'] !== '')
								No beneficiary matches these filters.
							@else
								No beneficiary is below or near the {{ $cards['threshold_label'] }}% threshold.
							@endif
						</td>
					</tr>
				@endforelse
				<tr id="arNoMatch" hidden><td colspan="10" class="table-empty">No learner matches this search.</td></tr>
			</tbody>
		</table>
	</div>
</div>

{{-- One learner's record, in the four readings a follow-up needs: the rule they
     failed, the direction they are moving, the sessions they missed, and what
     has already been done about it. --}}
@foreach ($rows as $row)
	@php
		$points = $row['trend_points'];
		$pointCount = count($points);
		$lastPoint = $pointCount > 0 ? $points[$pointCount - 1] : null;
		// A fixed drawing space, so the stroke is never stretched by the
		// column — or the dialog — it sits in.
		$chartW = 320;
		$chartH = 88;
		$chartPad = 8;
		$plotY = fn (float $rate): float => round($chartPad + ((100 - max(0, min(100, $rate))) / 100) * ($chartH - 2 * $chartPad), 2);
		$plotX = fn (int $i): float => $pointCount <= 1
			? round($chartW / 2, 2)
			: round(($i / ($pointCount - 1)) * ($chartW - 2), 2) + 1;
		$line = collect($points)
			->map(fn (array $point, int $i): string => $plotX($i).','.$plotY((float) $point['rate']))
			->implode(' ');
	@endphp

	<template class="ar-detail-source" data-detail-for="{{ $row['id'] }}">
		<div class="ar-detail">
			<section class="ar-panel">
				<p class="ar-panel-title">Why this student is at risk</p>
				<dl class="ar-facts">
					<div class="ar-fact">
						<dt>Attendance</dt>
						<dd class="{{ $row['at_risk'] ? 'is-risk' : '' }}">{{ $row['rate'] !== null ? number_format($row['rate'], 1).'%' : '—' }}</dd>
					</div>
					<div class="ar-fact">
						<dt>Threshold</dt>
						<dd>{{ $cards['threshold_label'] }}%</dd>
					</div>
					<div class="ar-fact">
						<dt>Present</dt>
						<dd>{{ $row['present'] }} / {{ $row['confirmed'] }}</dd>
					</div>
					<div class="ar-fact">
						<dt>Absent</dt>
						<dd>{{ $row['absent'] }}</dd>
					</div>
					@if ($row['unconfirmed'] > 0)
						{{-- Carried on its own line and counted neither way, never
						     as an absence. --}}
						<div class="ar-fact">
							<dt>Unconfirmed</dt>
							<dd>{{ $row['unconfirmed'] }}</dd>
						</div>
					@endif
				</dl>

				@if ($row['recent'] !== [])
					<p class="ar-sub">Recent attendance</p>
					<p class="ar-sequence">
						@foreach ($row['recent'] as $mark)
							<span class="ar-mark {{ $mark ? 'is-present' : 'is-absent' }}">{{ $mark ? 'Present' : 'Absent' }}</span>
						@endforeach
					</p>
				@endif

				<p class="ar-reason"><strong>Risk reason:</strong> {{ $row['reason'] }}</p>
			</section>

			{{-- The direction, not the decoration: a cumulative rate after each
			     confirmed session, with the school's own threshold drawn
			     across it. --}}
			<section class="ar-panel">
				<p class="ar-panel-title">Attendance trend</p>
				@if ($lastPoint !== null)
					<p class="ar-trendline">
						<span class="ar-trend-value {{ $row['at_risk'] ? 'is-risk' : '' }}">{{ number_format((float) $lastPoint['rate'], 1) }}%</span>
						@if ($row['trend'] !== null)
							<span class="ar-trend-tag is-{{ $row['trend'] }}">{{ $trendLabel[$row['trend']] ?? '' }}</span>
						@endif
					</p>
					<svg class="ar-chart" viewBox="0 0 {{ $chartW }} {{ $chartH }}" width="{{ $chartW }}" height="{{ $chartH }}"
						role="img"
						aria-label="Cumulative attendance across {{ $pointCount }} confirmed {{ \Illuminate\Support\Str::plural('session', $pointCount) }}, ending at {{ number_format((float) $lastPoint['rate'], 1) }} percent, against a {{ $cards['threshold_label'] }} percent threshold">
						{{-- The threshold, so the gap is what the eye reads. --}}
						<line x1="0" y1="{{ $plotY((float) $row['threshold']) }}" x2="{{ $chartW }}" y2="{{ $plotY((float) $row['threshold']) }}"
							stroke="var(--lg-ink-soft)" stroke-width="1" stroke-dasharray="4 4"/>
						@if ($pointCount > 1)
							<polyline points="{{ $line }}" fill="none"
								stroke="{{ $row['at_risk'] ? 'var(--series-risk)' : 'var(--series-healthy)' }}"
								stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
						@endif
						<circle cx="{{ $plotX($pointCount - 1) }}" cy="{{ $plotY((float) $lastPoint['rate']) }}" r="3"
							fill="{{ $row['at_risk'] ? 'var(--series-risk)' : 'var(--series-healthy)' }}"/>
					</svg>
					<p class="ar-axis">
						<span>Day {{ $points[0]['day'] }}</span>
						<span>Threshold {{ $cards['threshold_label'] }}%</span>
						<span>Day {{ $lastPoint['day'] }}</span>
					</p>
				@else
					<p class="ar-empty">No confirmed session yet.</p>
				@endif
			</section>

			<section class="ar-panel">
				<p class="ar-panel-title">Recent absences</p>
				@if ($row['absences'] !== [])
					<table class="ar-mini">
						<thead>
							<tr><th>Date</th><th>Feeding Day</th><th>Remarks</th></tr>
						</thead>
						<tbody>
							@foreach ($row['absences'] as $absence)
								<tr>
									<td>{{ $absence['label'] }}</td>
									<td class="tnum">Day {{ $absence['day'] }}</td>
									<td>{{ $absence['remarks'] !== '' ? $absence['remarks'] : '—' }}</td>
								</tr>
							@endforeach
						</tbody>
					</table>
				@else
					<p class="ar-empty">No confirmed absence on record.</p>
				@endif
			</section>

			{{-- The history only. Recording a new follow-up is the dialog's own
			     action and sits in its footer, so there is one button for it
			     rather than two in the same window. --}}
			<section class="ar-panel">
				<p class="ar-panel-title">Follow-up</p>
				@if ($row['follow_up_history'] !== [])
					<ol class="ar-timeline">
						@foreach ($row['follow_up_history'] as $entry)
							<li class="ar-entry">
								<div class="ar-entry-head">
									<span class="ar-entry-date">{{ $entry['date_label'] }}</span>
									<span class="badge {{ $entry['badge'] }}">{{ $entry['status_label'] }}</span>
								</div>
								@if ($entry['action_taken'] !== '')
									<p class="ar-entry-line"><strong>Action:</strong> {{ $entry['action_taken'] }}</p>
								@endif
								@if ($entry['person_contacted'] !== '')
									<p class="ar-entry-line"><strong>Contacted:</strong> {{ $entry['person_contacted'] }}</p>
								@endif
								@if ($entry['reason'] !== '')
									<p class="ar-entry-line"><strong>Context:</strong> {{ $entry['reason'] }}</p>
								@endif
								@if ($entry['remarks'] !== '')
									<p class="ar-entry-line"><strong>Remarks:</strong> {{ $entry['remarks'] }}</p>
								@endif
								@if ($entry['recorded_by'] !== '')
									<p class="ar-entry-by">Recorded by {{ $entry['recorded_by'] }}</p>
								@endif
							</li>
						@endforeach
					</ol>
				@else
					<p class="ar-empty">No follow-up recorded yet.</p>
				@endif
			</section>
		</div>
	</template>
@endforeach
