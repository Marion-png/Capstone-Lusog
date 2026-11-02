<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>Parent SMS - System Admin - SIGLA</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="icon" type="image/png" href="{{ asset('images/sigla-mark.png') }}">
	<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
	<script>document.documentElement.classList.add('js');</script>
	<style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/system-admin.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/role-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.system-admin-sidebar', ['active' => 'sms'])

@php
	$schoolTz = config('app.school_timezone', 'Asia/Manila');
	$gateway = match (true) {
		$dryRun => ['Dry run', 'accent-amber', 'Messages are logged, not sent'],
		$configured => ['Live', 'accent-success', 'Semaphore'.($senderName !== '' ? ' · '.$senderName : '')],
		default => ['Off', 'accent-danger', 'SEMAPHORE_API_KEY is not set'],
	};
	$delivered = (int) ($counts[\App\Models\SmsNotification::STATUS_DELIVERED] ?? 0);
	$inTransit = (int) ($counts[\App\Models\SmsNotification::STATUS_ACCEPTED] ?? 0) + (int) ($counts[\App\Models\SmsNotification::STATUS_QUEUED] ?? 0);
	$failed = (int) ($counts[\App\Models\SmsNotification::STATUS_FAILED] ?? 0);
	$simulated = (int) ($counts[\App\Models\SmsNotification::STATUS_SIMULATED] ?? 0);
	$isFiltered = $filterStatus !== '' || $filterEvent !== '';
@endphp

<div class="main">
	<header class="topbar">
		<div class="topbar-bc"><a href="{{ route('dashboard.system-admin') }}">System Admin</a><span class="bc-sep">&rsaquo;</span><span>Parent SMS</span></div>
		@include('partials.live-clock')
	</header>

	<div class="content">
		<div class="content-inner">

		<div class="page-header sa-header">
			<div class="sa-headline">
				<h1 class="page-title">Parent <span>SMS</span></h1>
				<p class="sa-meta">
					<span class="tnum">{{ number_format(array_sum($counts)) }} {{ \Illuminate\Support\Str::plural('message', array_sum($counts)) }}</span>
					<span class="sa-sep">&middot;</span>
					<span class="tnum">daily limit {{ number_format($dailyCap) }} per school</span>
				</p>
			</div>
		</div>

		@if (session('success'))
			<div class="flash ok">{{ session('success') }}</div>
		@endif
		@if (session('error'))
			<div class="flash err">{{ session('error') }}</div>
		@endif
		@if ($errors->any())
			<div class="flash err">{{ $errors->first() }}</div>
		@endif
		@unless ($hasTable)
			<div class="flash err">The database has not been updated for text messages yet. Run <code>php artisan migrate</code>.</div>
		@endunless
		{{-- The key can be right and every message still refused: Semaphore
		     sends nothing for an account it has not activated, or one with no
		     credits. Said here, so a refusal is not read as a wrong key. --}}
		@if ($account && $account['ok'] && ! \App\Support\SemaphoreClient::canSend($account))
			<div class="flash err">
				@if (strcasecmp(trim((string) $account['status']), 'Active') !== 0)
					The API key works, but Semaphore has not activated the account yet (status: {{ $account['status'] ?: 'unknown' }}). Messages are refused until it is.
				@endif
				@if ((float) ($account['credit_balance'] ?? 0) <= 0)
					The account has no credits; top it up on semaphore.co before messages can be sent.
				@endif
			</div>
		@endif

		<section class="kpi-grid">
			<article class="card kpi {{ $gateway[1] }}">
				<div class="kpi-top">
					<div class="kpi-label">Gateway</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>
				</div>
				<div class="kpi-value">{{ $gateway[0] }}</div>
				<div class="kpi-hint">{{ $gateway[2] }}</div>
			</article>
			<article class="card kpi {{ $account && (! $account['ok'] || ! \App\Support\SemaphoreClient::canSend($account)) ? 'accent-danger' : 'accent-info' }}">
				<div class="kpi-top">
					<div class="kpi-label">Credit Balance</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg></div>
				</div>
				@if ($account && $account['ok'])
					<div class="kpi-value">{{ $account['credit_balance'] === null ? '—' : number_format($account['credit_balance'], 2) }}</div>
					<div class="kpi-hint">{{ $account['name'] ?: 'Semaphore account' }}{{ ($account['status'] ?? '') !== '' ? ' · '.$account['status'] : '' }}</div>
				@elseif ($account)
					<div class="kpi-value">—</div>
					<div class="kpi-hint">{{ $account['error'] }}</div>
				@else
					<div class="kpi-value">—</div>
					<div class="kpi-hint">No account connected</div>
				@endif
			</article>
			<article class="card kpi accent-success">
				<div class="kpi-top">
					<div class="kpi-label">Delivered</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg></div>
				</div>
				<div class="kpi-value">{{ number_format($delivered) }}</div>
				<div class="kpi-hint tnum">{{ number_format($inTransit) }} on the way · {{ number_format($simulated) }} simulated</div>
			</article>
			<article class="card kpi {{ $failed > 0 ? 'accent-danger' : 'accent-brand' }}">
				<div class="kpi-top">
					<div class="kpi-label">Failed</div>
					<div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
				</div>
				<div class="kpi-value">{{ number_format($failed) }}</div>
				<div class="kpi-hint">Not delivered</div>
			</article>
		</section>

		<section class="card sa-sms-actions">
			<form method="POST" action="{{ route('dashboard.system-admin.sms.test') }}" class="sa-sms-test">
				@csrf
				<div>
					<label class="field-label" for="smsTestNumber">Send a sample message</label>
					<input class="input" type="tel" id="smsTestNumber" name="number" inputmode="tel" maxlength="30"
					       placeholder="0917 123 4567" value="{{ old('number') }}" required>
				</div>
				<button type="submit" class="btn btn-primary">Send</button>
			</form>
			<form method="POST" action="{{ route('dashboard.system-admin.sms.refresh') }}">
				@csrf
				<button type="submit" class="btn btn-secondary" @disabled(! $configured || $dryRun)>Refresh delivery status</button>
			</form>
		</section>

		<form class="toolbar" method="GET" action="{{ route('dashboard.system-admin.sms') }}">
			<div>
				<label class="field-label" for="smsStatus">Status</label>
				<select class="select" id="smsStatus" name="status">
					<option value="">All statuses</option>
					@foreach (\App\Models\SmsNotification::STATUSES as $value => $label)
						<option value="{{ $value }}" @selected($filterStatus === $value)>{{ $label }}</option>
					@endforeach
				</select>
			</div>
			<div>
				<label class="field-label" for="smsEvent">Event</label>
				<select class="select" id="smsEvent" name="event">
					<option value="">All events</option>
					@foreach (\App\Models\SmsNotification::EVENTS as $value => $label)
						<option value="{{ $value }}" @selected($filterEvent === $value)>{{ $label }}</option>
					@endforeach
				</select>
			</div>
			<button type="submit" class="btn btn-primary">Filter</button>
			@if ($isFiltered)
				<a href="{{ route('dashboard.system-admin.sms') }}" class="btn btn-secondary">Clear</a>
			@endif
		</form>

		<div class="table-card">
			<div class="table-scroll">
				<table class="sa-sms-table">
					<thead>
						<tr>
							<th>When</th>
							<th>School</th>
							<th>Event</th>
							<th>To</th>
							<th>Message</th>
							<th>Status</th>
							<th>Gateway</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						@forelse ($rows as $row)
							<tr>
								<td class="sa-nowrap tnum">{{ $row->created_at?->copy()->setTimezone($schoolTz)->format('M d, Y g:i A') }}</td>
								<td>{{ $row->institution_id ? ($schools[$row->institution_id] ?? '—') : '—' }}</td>
								<td>
									{{ $row->eventLabel() }}
									<span class="sa-cell-sub">{{ \App\Support\ParentNotifier::LANGUAGES[$row->language] ?? $row->language }}</span>
								</td>
								<td class="sa-nowrap tnum">{{ \App\Support\PhilippineMobileNumber::masked($row->recipient_last4) }}</td>
								<td class="sa-sms-message">
									{{ $row->message }}
									@if ($row->error)
										<span class="sa-sms-error">{{ $row->error }}</span>
									@endif
								</td>
								<td><span class="badge {{ $row->statusBadge() }}">{{ $row->statusLabel() }}</span></td>
								<td class="muted">
									{{ $row->provider_status ?: '—' }}
									@if ($row->network || $row->provider_message_id)
										<span class="sa-cell-sub sa-cell-mono">{{ trim(($row->network ?? '').' '.($row->provider_message_id ? '#'.$row->provider_message_id : '')) }}</span>
									@endif
								</td>
								<td>
									@if ($row->canRetry())
										<form method="POST" action="{{ route('dashboard.system-admin.sms.retry', $row) }}">
											@csrf
											<button type="submit" class="btn btn-secondary sa-sms-retry">Retry</button>
										</form>
									@endif
								</td>
							</tr>
						@empty
							<tr><td colspan="8" class="table-empty">{{ $isFiltered ? 'No message matches the current filter.' : 'No text message has been sent yet.' }}</td></tr>
						@endforelse
					</tbody>
				</table>
			</div>
		</div>

		</div>
	</div>
</div>

@include('partials.role-page-transition')
</body>
</html>
