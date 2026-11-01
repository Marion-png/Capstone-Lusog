{{-- The coordinator's work list: who needs looking at, in the order they need
     it — a record awaiting confirmation first, then the flags being followed
     up, then the attendance notices.

     Every figure here is derived from the marks the Attendance tab recorded.
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
			<p class="card-sub">{{ $listedCount }} {{ \Illuminate\Support\Str::plural('beneficiary', $listedCount) }} &middot; notice at {{ $cards['notice_label'] }} &middot; flag at {{ $cards['threshold_label'] }}</p>
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
					<th class="num">In a Row</th>
					<th>Status</th>
					<th>Follow-Up</th>
				</tr>
			</thead>
			<tbody>
				@forelse ($rows as $row)
					<tr class="ar-row" data-row="{{ $row['id'] }}"
						data-name="{{ $row['name'] }}"
						data-meta="{{ trim($row['grade'].($row['section'] !== '' ? ' — '.$row['section'] : '')) }}"
						data-rate="{{ $row['rate'] !== null ? number_format($row['rate'], 0).'%' : '—' }}"
						data-standing="{{ $row['state_label'] }}"
						data-standing-badge="{{ $row['state_badge'] }}"
						data-confirm-stage="{{ $row['confirm_stage'] }}"
						data-flagged="{{ $row['at_risk'] ? '1' : '0' }}"
						data-search="{{ strtolower(trim($row['name'].' '.$row['grade'].' '.$row['section'])) }}">
						<td>
							<span class="ar-prio {{ $priorityTone[$row['priority']] ?? '' }}">
								{{ \App\Support\FeedingAttendanceFlag::priorityLabel($row['priority']) }}
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
						<td class="num tnum">{{ $row['rate'] !== null ? number_format($row['rate'], 0).'%' : '—' }}</td>
						<td class="num tnum">{{ $row['run'] }}</td>
						<td>
							<span class="badge {{ $row['state_badge'] }}">{{ $row['state_label'] }}</span>
						</td>
						<td>
							{{-- A follow-up belongs to a flag: before the confirmation there
							     is nothing to follow up, so the cell is a dash, not a badge. --}}
							@if ($row['at_risk'])
								<span class="badge {{ $row['follow_up']['badge'] }}">{{ $row['follow_up']['status_label'] }}</span>
							@else
								&mdash;
							@endif
						</td>
					</tr>
				@empty
					<tr>
						<td colspan="10" class="table-empty">
							@if ($cards['beneficiaries'] === 0)
								No beneficiaries are enrolled for this school year.
							@elseif ($filters['status'] !== '' || $filters['attendance'] !== '' || $filters['absences'] !== '' || $filters['follow_up'] !== '')
								No beneficiary matches these filters.
							@else
								No beneficiary has {{ $cards['notice_label'] }} of consecutive unexcused absence.
							@endif
						</td>
					</tr>
				@endforelse
				<tr id="arNoMatch" hidden><td colspan="10" class="table-empty">No learner matches this search.</td></tr>
			</tbody>
		</table>
	</div>
</div>

{{-- One learner's record: where they stand, the direction they are moving, the
     absences being verified, and what has already been done about it. --}}
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
		$lineColour = $row['at_risk'] ? 'var(--series-risk)' : 'var(--series-healthy)';
	@endphp

	<template class="ar-detail-source" data-detail-for="{{ $row['id'] }}">
		<div class="ar-detail">
			<section class="ar-panel">
				<p class="ar-panel-title">Attendance standing</p>
				<dl class="ar-facts">
					<div class="ar-fact">
						<dt>Attendance</dt>
						<dd>{{ $row['rate'] !== null ? number_format($row['rate'], 0).'%' : '—' }}</dd>
					</div>
					<div class="ar-fact">
						<dt>Present</dt>
						<dd>{{ $row['present'] }} / {{ $row['confirmed'] }}</dd>
					</div>
					<div class="ar-fact">
						<dt>Absent</dt>
						<dd>{{ $row['absent'] }}</dd>
					</div>
					<div class="ar-fact">
						<dt>Unexcused in a row</dt>
						<dd class="{{ $row['run'] > 0 ? 'is-risk' : '' }}">{{ $row['run'] }}</dd>
					</div>
					@if (($row['excused'] ?? 0) > 0)
						{{-- The buffer: an absence the school accepted. Shown, and
						     counted neither way by the rule. --}}
						<div class="ar-fact">
							<dt>Excused</dt>
							<dd>{{ $row['excused'] }}</dd>
						</div>
					@endif
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

				@if ($row['reason'] !== '')
					<p class="ar-reason"><strong>{{ $row['state_label'] }}:</strong> {{ $row['reason'] }}</p>
				@endif
			</section>

			{{-- The direction, not the decoration: a cumulative rate after each
			     confirmed session. A rate rule draws its threshold across it;
			     a run of absences has no line to draw. --}}
			<section class="ar-panel">
				<p class="ar-panel-title">Attendance trend</p>
				@if ($lastPoint !== null)
					<p class="ar-trendline">
						<span class="ar-trend-value {{ $row['at_risk'] ? 'is-risk' : '' }}">{{ number_format((float) $lastPoint['rate'], 0) }}%</span>
						@if ($row['trend'] !== null)
							<span class="ar-trend-tag is-{{ $row['trend'] }}">{{ $trendLabel[$row['trend']] ?? '' }}</span>
						@endif
					</p>
					<svg class="ar-chart" viewBox="0 0 {{ $chartW }} {{ $chartH }}" width="{{ $chartW }}" height="{{ $chartH }}"
						role="img"
						aria-label="Cumulative attendance across {{ $pointCount }} confirmed {{ \Illuminate\Support\Str::plural('session', $pointCount) }}, ending at {{ number_format((float) $lastPoint['rate'], 0) }} percent">
						@if ($row['is_rate_rule'])
							<line x1="0" y1="{{ $plotY((float) $row['threshold']) }}" x2="{{ $chartW }}" y2="{{ $plotY((float) $row['threshold']) }}"
								stroke="var(--lg-ink-soft)" stroke-width="1" stroke-dasharray="4 4"/>
						@endif
						@if ($pointCount > 1)
							<polyline points="{{ $line }}" fill="none"
								stroke="{{ $lineColour }}"
								stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
						@endif
						<circle cx="{{ $plotX($pointCount - 1) }}" cy="{{ $plotY((float) $lastPoint['rate']) }}" r="3"
							fill="{{ $lineColour }}"/>
					</svg>
					<p class="ar-axis">
						<span>Day {{ $points[0]['day'] }}</span>
						@if ($row['is_rate_rule'])
							<span>Threshold {{ $cards['threshold_label'] }}</span>
						@endif
						<span>Day {{ $lastPoint['day'] }}</span>
					</p>
				@else
					<p class="ar-empty">No confirmed session yet.</p>
				@endif
			</section>

			{{-- The absences a confirmation is about. While the record is being
			     verified, today's absence can be put right through the one audited
			     correction path; an earlier day's attendance closed when the day
			     did, so it is verified as it stands. Once confirmed, they are all
			     a reading. --}}
			<section class="ar-panel {{ $row['can_correct'] ? 'ar-panel-wide' : '' }}">
				<p class="ar-panel-title">{{ $row['can_correct'] ? 'Absences to verify' : 'Recent absences' }}</p>
				@if ($row['absences'] !== [])
					<ul class="ar-verify">
						@foreach ($row['absences'] as $absence)
							<li class="ar-verify-item">
								<div class="ar-verify-main">
									<strong class="tnum">{{ $absence['label'] }}</strong>
									<span class="ar-verify-meta">Day {{ $absence['day'] }}@if ($absence['recorded_by'] !== '') &middot; {{ $absence['recorded_by'] }}@endif</span>
									@if ($absence['remarks'] !== '')
										<span class="ar-verify-note">{{ $absence['remarks'] }}</span>
									@endif
								</div>
								@if ($row['can_correct'] && $absence['open'])
									<form method="POST" class="ar-correct"
										action="{{ route('feedingcor-program.beneficiary.attendance.correct', $row['id']) }}">
										@csrf
										<input type="hidden" name="session_date" value="{{ $absence['date'] }}">
										<select class="select" name="mark" aria-label="Correct the mark for {{ $absence['label'] }}">
											<option value="present">Present</option>
											@if ($excusedAvailable)
												<option value="excused">Excused</option>
											@endif
										</select>
										<input type="text" class="input" name="remarks" maxlength="255" placeholder="Reason"
											aria-label="Reason for the correction on {{ $absence['label'] }}">
										<button type="submit" class="btn btn-secondary">Correct</button>
									</form>
								@elseif ($row['can_correct'])
									<span class="ar-verify-closed">Closed &middot; a mark can only be changed on its own day</span>
								@endif
							</li>
						@endforeach
					</ul>
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
