{{-- The For Follow-up Beneficiaries rows: learners FLAGGED for follow-up — the
     threshold reached and the Feeding Coordinator's confirmation on record —
     with the date it was confirmed. Rendered only for a reader who may see
     flags — for anybody else it renders nothing, so the live refresh never
     carries a flag to a reader who may not see one. The page's search and
     filters re-apply after a refresh. --}}
@if ($showFlags ?? false)
@forelse (($atRiskStudents ?? collect()) as $student)
	@php
		$status = strtolower((string) ($student['nutritional_status'] ?? ''));
		// Same scale as everywhere else: severe is critical, any wasting is
		// at-risk, above normal needs monitoring.
		$statusBadge = str_contains($status, 'severe') ? 'badge-critical'
			: (str_contains($status, 'wast') ? 'badge-risk'
			: ((str_contains($status, 'over') || str_contains($status, 'obese')) ? 'badge-monitor'
			: ($status === '' ? 'badge-neutral' : 'badge-normal')));
	@endphp
	<tr data-risk-status="{{ $status }}"
		data-risk-gender="{{ strtolower((string) ($student['gender'] ?? '')) }}"
		data-risk-search="{{ strtolower(trim(($student['student_name'] ?? '').' '.($student['section'] ?? ''))) }}">
		<td>
			<strong>{{ $student['student_name'] }}</strong>
			<span class="fp-sub">{{ $student['grade_level'] }}{{ ($student['section_name'] ?? '') !== '' ? ' · '.$student['section_name'] : '' }}</span>
		</td>
		{{-- Present out of the sessions the rule judged. A learner no
		     confirmed session has covered has no rate to print. --}}
		<td class="tnum">{{ $student['attendance'] }}{{ $student['attendance_percent'] !== null ? ' ('.$student['attendance_percent'].'%)' : '' }}</td>
		<td class="tnum">{{ $student['flagged_on'] ?? '—' }}</td>
		<td><span class="badge {{ $statusBadge }}">{{ $student['nutritional_status'] ?: 'Not set' }}</span></td>
	</tr>
@empty
	<tr><td colspan="4" class="table-empty">No beneficiary is flagged for follow-up right now.</td></tr>
@endforelse
@if (($atRiskStudents ?? collect())->isNotEmpty())
	<tr id="riskNoMatchRow" style="display:none;"><td colspan="4" class="table-empty">No beneficiaries match these filters.</td></tr>
@endif
@endif
