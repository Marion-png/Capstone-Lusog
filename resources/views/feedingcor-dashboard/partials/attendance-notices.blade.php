{{-- What needs doing, said plainly and only when it is true.

     A blank table tells the coordinator nothing: these notices say whether the
     session was recorded at all, whether it was finished, who the school's
     threshold has flagged across the programme, and who it has not started
     judging yet. Neither figure is written here — both come from the school's
     own settings through FeedingAtRiskRule, so two schools read two different
     lines.

     The last two are separate notices on purpose. A learner inside the
     observation window is not a quieter kind of at-risk; they are unclassified,
     and putting them in the same red bar is exactly the confusion the window
     exists to prevent. --}}
@php
	$unrecorded = ! $sessionRecorded;
	$incomplete = $sessionRecorded && $tally['recorded'] < $beneficiaryCount;
@endphp

@if ($beneficiaryCount > 0 && $unrecorded)
	<div class="alert-bar">
		<div class="alert-body">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
			<div>
				<strong>Attendance has not been recorded for {{ $selectedDateLabel }}</strong>
				<span>{{ $beneficiaryCount }} beneficiaries are waiting on this session.</span>
			</div>
		</div>
		<button type="button" class="btn btn-primary" data-open-sheet>Record Attendance</button>
	</div>
@elseif ($incomplete)
	<div class="alert-bar is-info">
		<div class="alert-body">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
			<div>
				<strong>Attendance incomplete for {{ $selectedDateLabel }}</strong>
				<span>{{ $tally['recorded'] }} of {{ $beneficiaryCount }} beneficiaries recorded.</span>
			</div>
		</div>
		<button type="button" class="btn btn-secondary" data-open-sheet>Continue Recording</button>
	</div>
@endif

@if ($atRisk['count'] > 0)
	<div class="alert-bar is-critical">
		<div class="alert-body">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
			<div>
				<strong>{{ $atRisk['count'] }} {{ \Illuminate\Support\Str::plural('beneficiary', $atRisk['count']) }} currently below the {{ $atRisk['thresholdLabel'] }}% attendance threshold</strong>
				<span>SBFP attendance at-risk threshold: {{ $atRisk['thresholdLabel'] }}%</span>
			</div>
		</div>
		{{-- Opens the per-beneficiary roll with the flag already applied, so the
		     list the coordinator lands on is exactly the one counted here. --}}
		<a class="btn btn-secondary" href="{{ $pageUrl(['view' => 'beneficiary', 'standing' => 'at_risk', 'status' => '']) }}">View At-Risk Beneficiaries</a>
	</div>
@endif

@if ($atRisk['observing'] > 0)
	<div class="alert-bar is-info">
		<div class="alert-body">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
			<div>
				<strong>{{ $atRisk['observing'] }} {{ \Illuminate\Support\Str::plural('beneficiary', $atRisk['observing']) }} currently under observation</strong>
				<span>At-risk classification begins after {{ $atRisk['minimumObservationDays'] }} recorded feeding days &middot; {{ $cumulative['sessions'] }} recorded so far.</span>
			</div>
		</div>
		<a class="btn btn-secondary" href="{{ $pageUrl(['view' => 'beneficiary', 'standing' => 'early_monitoring', 'status' => '']) }}">View Under Observation</a>
	</div>
@endif
