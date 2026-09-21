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
		if (str_contains($action, 'failed') || in_array($action, ['deleted', 'declined'], true)) {
			return 'badge-critical';
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
						</tr>
					</thead>
					<tbody>
						@forelse ($logs as $log)
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
										<details class="sa-audit-details">
											<summary>View</summary>
											<pre>{{ json_encode($log->details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
										</details>
									@else
										<span class="muted">—</span>
									@endif
								</td>
							</tr>
						@empty
							<tr><td colspan="8" class="table-empty">No audit entries match the current filter.</td></tr>
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
