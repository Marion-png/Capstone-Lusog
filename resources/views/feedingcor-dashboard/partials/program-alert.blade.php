{{-- Flagged means confirmed: the threshold was reached AND the Feeding
     Coordinator confirmed the record. Only the coordinator and the School
     Nurse see it; the Clinic Teacher reads this page without it. The rule is
     the school's own, printed as it stands — two schools read different lines
     here, and the figure named is the one that was reached. --}}
@if (($showFlags ?? false) && ($programStats['at_risk_count'] ?? 0) > 0)
	<div class="alert-bar fp-alert">
		<div class="alert-body">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
			<div>
				<strong>{{ $programStats['at_risk_count'] }} {{ \Illuminate\Support\Str::plural('beneficiary', $programStats['at_risk_count']) }} flagged for follow-up</strong>
				<span>{{ ucfirst($programStats['at_risk_rule'] ?? '') }}@if (($programStats['at_risk_threshold_count'] ?? null) !== null) &middot; {{ $programStats['at_risk_threshold_count'] }} of {{ $programStats['sessions_held'] ?? 0 }} sessions needed to clear it @endif</span>
			</div>
		</div>
		<a class="btn btn-secondary" href="#atRiskSection">Review List</a>
	</div>
@endif
