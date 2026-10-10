<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>Audit Trail - System Admin - SIGLA</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
	<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
	<script>document.documentElement.classList.add('js');</script>
	<style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/system-admin.css')) !!}</style>
	<style>{!! file_get_contents(resource_path('css/role-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.system-admin-sidebar', ['active' => 'audit'])

@php
	$isFiltered = $filterAction !== '' || $filterUsername !== '';

	// One reading of an action's severity for the badge scale: a refusal
	// or a deletion is critical, a read is information, everything else
	// is neutral. The label is always the action itself.
	$actionBadge = function (string $action): string {
		if (str_contains($action, 'failed') || str_contains($action, 'blocked') || in_array($action, ['deleted', 'declined'], true)) {
			return 'badge-critical';
		}
		// A record sent outside the school: worth a second look by design.
		if ($action === 'transmitted') {
			return 'badge-monitor';
		}
		if (in_array($action, ['viewed', 'read', 'downloaded', 'accessed'], true)) {
			return 'badge-info';
		}
		return 'badge-neutral';
	};
@endphp

<div class="main">
	<header class="topbar">
		<div class="topbar-bc"><a href="{{ route('dashboard.system-admin') }}">System Admin</a><span class="bc-sep">&rsaquo;</span><span>Audit Trail</span></div>
		@include('partials.live-clock')
	</header>

	<div class="content">
		<div class="content-inner">

		<div class="page-header sa-header">
			<div class="sa-headline">
				<h1 class="page-title">Audit <span>Trail</span></h1>
				<p class="sa-meta">
					<span class="tnum">{{ number_format($logs->count()) }} {{ \Illuminate\Support\Str::plural('entry', $logs->count()) }} shown</span>
					<span class="sa-sep">&middot;</span>
					<span>latest 200{{ $isFiltered ? ' matching' : '' }}</span>
				</p>
			</div>
		</div>

		<form class="toolbar" method="GET" action="{{ route('dashboard.system-admin.audit-logs') }}">
			<div>
				<label class="field-label" for="auditAction">Action</label>
				<select class="select" id="auditAction" name="action">
					<option value="">All actions</option>
					@foreach ($actions as $actionOption)
						<option value="{{ $actionOption }}" @selected($filterAction === $actionOption)>{{ $actionOption }}</option>
					@endforeach
				</select>
			</div>
			<div>
				<label class="field-label" for="auditUsername">Username</label>
				<div class="lg-search">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
					<input type="text" id="auditUsername" name="username" value="{{ $filterUsername }}" placeholder="Filter by username">
				</div>
			</div>
			<button type="submit" class="btn btn-primary">Filter</button>
			@if ($isFiltered)
				<a href="{{ route('dashboard.system-admin.audit-logs') }}" class="btn btn-secondary">Clear</a>
			@endif
		</form>

		<div class="table-card">
			<div class="table-scroll">
				<table class="sa-audit-table">
					<thead>
						<tr>
							<th>When</th>
							<th>Actor</th>
							<th>Action</th>
							<th>Description</th>
							<th>Subject</th>
							<th>Request</th>
							<th>IP</th>
							<th>Details</th>
							<th>Seal</th>
						</tr>
					</thead>
					<tbody>
						@forelse ($logs as $log)
							{{-- The entry's HMAC seal, re-checked as it is shown
							     (App\Support\AuditSeal). An entry written before
							     sealing existed is unsealed, never assumed intact. --}}
							@php
								$seal = $log->sealStatus();
								$sealBadge = match ($seal) {
									'sealed' => ['badge-normal', 'Sealed'],
									'altered' => ['badge-critical', 'Altered'],
									default => ['badge-neutral', 'Unsealed'],
								};
							@endphp
							<tr>
								<td class="sa-nowrap tnum">{{ $log->created_at?->format('M d, Y H:i:s') }}</td>
								<td>
									<span class="sa-audit-actor">{{ $log->actor_name ?: '—' }}</span>
									<span class="sa-cell-sub">{{ $log->actor_username }}{{ $log->actor_role ? ' · '.$log->actor_role : '' }}</span>
								</td>
								<td><span class="badge {{ $actionBadge((string) $log->action) }}">{{ $log->action }}</span></td>
								<td class="sa-audit-desc">{{ $log->description }}</td>
								<td class="muted">{{ $log->subject_type ? $log->subject_type.($log->subject_id ? ' #'.$log->subject_id : '') : '—' }}</td>
								<td class="muted sa-cell-mono">{{ $log->http_method }} {{ $log->route_name ?: parse_url((string) $log->url, PHP_URL_PATH) }}</td>
								<td class="muted sa-cell-mono">{{ $log->ip_address }}</td>
								<td>
									@if ($log->details)
										{{-- Opens the entry in a dialog. Its contents are this
										     row's own render, held in a template until asked for,
										     so the dialog and the row cannot disagree. --}}
										<button type="button" class="sa-audit-view"
										        data-audit-open="{{ $log->id }}"
										        data-audit-title="{{ $log->description ?: $log->action }}">View</button>
										<template id="sa-audit-entry-{{ $log->id }}">
											<dl class="sa-audit-facts">
												<div><dt>When</dt><dd class="tnum">{{ $log->created_at?->format('F j, Y · g:i:s A') }}</dd></div>
												<div><dt>Actor</dt><dd>{{ $log->actor_name ?: '—' }}@if ($log->actor_username)<span class="sa-cell-sub">{{ $log->actor_username }}{{ $log->actor_role ? ' · '.$log->actor_role : '' }}</span>@endif</dd></div>
												<div><dt>Action</dt><dd><span class="badge {{ $actionBadge((string) $log->action) }}">{{ $log->action }}</span></dd></div>
												<div><dt>Subject</dt><dd>{{ $log->subject_type ? $log->subject_type.($log->subject_id ? ' #'.$log->subject_id : '') : '—' }}</dd></div>
												<div><dt>Request</dt><dd class="sa-cell-mono">{{ $log->http_method }} {{ $log->route_name ?: parse_url((string) $log->url, PHP_URL_PATH) }}</dd></div>
												<div><dt>IP address</dt><dd class="sa-cell-mono">{{ $log->ip_address ?: '—' }}</dd></div>
												<div><dt>Seal</dt><dd><span class="badge {{ $sealBadge[0] }}">{{ $sealBadge[1] }}</span></dd></div>
											</dl>
											<pre class="sa-audit-json">{{ json_encode($log->details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
										</template>
									@else
										<span class="muted">—</span>
									@endif
								</td>
								<td><span class="badge {{ $sealBadge[0] }}">{{ $sealBadge[1] }}</span></td>
							</tr>
						@empty
							<tr><td colspan="9" class="table-empty">No audit entries match the current filter.</td></tr>
						@endforelse
					</tbody>
				</table>
			</div>
		</div>

		</div>
	</div>
</div>
{{-- One dialog for the page, filled from the row that opened it. --}}
<div class="modal-backdrop" id="auditBackdrop" aria-hidden="true">
	<div class="modal-panel sa-audit-modal" role="dialog" aria-modal="true" aria-labelledby="auditModalTitle">
		<div class="modal-head">
			<div>
				<div class="sa-audit-eyebrow" id="auditModalEyebrow">Audit entry</div>
				<div class="modal-title" id="auditModalTitle"></div>
			</div>
			<button type="button" class="modal-close" data-audit-close aria-label="Close">&times;</button>
		</div>
		<div class="modal-body sa-audit-modal-body" id="auditModalBody"></div>
		<div class="modal-foot">
			<button type="button" class="btn btn-secondary sa-audit-close" data-audit-close>Close</button>
		</div>
	</div>
</div>

@include('partials.role-page-transition')

<script>
(() => {
	const backdrop = document.getElementById('auditBackdrop');
	if (!backdrop) return;

	const body = document.getElementById('auditModalBody');
	const title = document.getElementById('auditModalTitle');
	const eyebrow = document.getElementById('auditModalEyebrow');
	let opener = null;

	const open = (button) => {
		const template = document.getElementById('sa-audit-entry-' + button.dataset.auditOpen);
		if (!template) return;

		body.replaceChildren(template.content.cloneNode(true));
		title.textContent = button.dataset.auditTitle || 'Audit entry';
		eyebrow.textContent = 'Audit entry #' + button.dataset.auditOpen;
		opener = button;

		backdrop.classList.remove('is-closing');
		backdrop.classList.add('open');
		backdrop.setAttribute('aria-hidden', 'false');
		backdrop.querySelector('.modal-close').focus();
	};

	const close = () => {
		if (!backdrop.classList.contains('open')) return;

		backdrop.classList.remove('open');
		backdrop.classList.add('is-closing');
		backdrop.setAttribute('aria-hidden', 'true');
		setTimeout(() => {
			backdrop.classList.remove('is-closing');
			body.replaceChildren();
		}, 140);

		if (opener) opener.focus();
	};

	document.addEventListener('click', (event) => {
		const button = event.target.closest('[data-audit-open]');
		if (button) {
			open(button);
			return;
		}
		if (event.target === backdrop || event.target.closest('[data-audit-close]')) {
			close();
		}
	});

	document.addEventListener('keydown', (event) => {
		if (event.key === 'Escape') close();
	});
})();
</script>
</body>
</html>
