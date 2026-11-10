{{-- One row per beneficiary: who they are, the status that made them one,
     their weight against the baseline, and their attendance.

     A learner with no baseline has no movement to report — the row says so
     rather than reading "No change" against a measurement nobody took. A
     learner on no sheet yet is listed with nothing claimed; an unconfirmed
     scanned mark is "awaiting review", never present or absent. The rate
     carries a plain meter in one neutral fill: whether a rate is too low is
     the school's own rule to say (the For Follow-up list), never a colour band
     typed into this row. --}}
@php $fpRows = $attendanceRoster ?? collect(); @endphp
@forelse ($fpRows as $row)
	@php
		$weightChange = $row['weight_change'] === null ? null : (float) $row['weight_change'];
		$hasAttendance = $row['sessions'] > 0;
		// Nobody re-weighed since the baseline: there is no change to report,
		// and the row says "Not measured" rather than "No change" (report #7).
		[$statusBadge, $statusLabel] = match (true) {
			$row['baseline_weight'] === null => ['badge-neutral', 'No baseline'],
			$row['current_weight'] === null => ['badge-neutral', 'Not measured'],
			($row['progress'] ?? null) === \App\Support\FeedingWeighIns::NOT_IMPROVING => ['badge-risk', 'Not improving'],
			$weightChange > 1 => ['badge-normal', 'Improved'],
			$weightChange < 0 => ['badge-risk', 'Declined'],
			default => ['badge-neutral', 'No change'],
		};
		$baseline = strtolower((string) $row['baseline_status']);
		$baselineBadge = str_contains($baseline, 'severe') ? 'badge-critical' : (str_contains($baseline, 'wast') ? 'badge-risk' : 'badge-neutral');
	@endphp
	<tr data-roster-gender="{{ strtolower((string) ($row['gender'] ?? '')) }}"
		data-roster-status="{{ $baseline }}"
		data-roster-search="{{ strtolower(trim(($row['student_name'] ?? '').' '.($row['section'] ?? ''))) }}">
		<td>
			<strong>{{ $row['student_name'] }}</strong>
			<span class="fp-sub">{{ $row['grade_level'] }}{{ ($row['section_name'] ?? '') !== '' ? ' · '.$row['section_name'] : '' }}{{ ($row['gender'] ?? '') !== '' ? ' · '.$row['gender'] : '' }}</span>
		</td>
		<td><span class="badge {{ $baselineBadge }}">{{ $row['baseline_status'] }}</span></td>
		<td class="num">{{ $row['baseline_weight'] === null ? '—' : number_format((float) $row['baseline_weight'], 1).' kg' }}</td>
		<td class="num"><strong>{{ $row['current_weight'] === null ? '—' : number_format((float) $row['current_weight'], 1).' kg' }}</strong></td>
		<td class="num {{ $weightChange === null ? 'muted' : ($weightChange > 0 ? 'bmi-up' : ($weightChange < 0 ? 'bmi-down' : 'muted')) }}">{{ $weightChange === null ? '—' : ($weightChange > 0 ? '+' : '').number_format($weightChange, 1).' kg' }}</td>
		<td class="num">{{ $hasAttendance ? $row['present'] : '—' }}@if ($row['pending'] > 0) <span class="roster-pending">{{ $row['pending'] }} awaiting review</span>@endif</td>
		<td class="num">{{ $hasAttendance ? $row['absent'] : '—' }}</td>
		{{-- No sheet has covered this learner: no rate, said in words rather
		     than a 0% nobody measured. --}}
		<td class="num fp-rate-cell">@if (! $hasAttendance)<span class="muted">No session yet</span>@else{{ $row['rate'] === null ? '—' : $row['rate'].'%' }}@if ($row['rate'] !== null)<span class="fp-meter" aria-hidden="true"><i style="width: {{ $row['rate'] }}%"></i></span>@endif @endif</td>
		<td><span class="badge {{ $statusBadge }}">{{ $statusLabel }}</span></td>
	</tr>
@empty
	<tr><td colspan="9" class="table-empty">No feeding beneficiaries on file yet.</td></tr>
@endforelse
@if ($fpRows->isNotEmpty())
	<tr id="rosterNoMatchRow" style="display:none;"><td colspan="9" class="table-empty">No learners match these filters.</td></tr>
@endif
