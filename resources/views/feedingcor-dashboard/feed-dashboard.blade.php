<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Feeding Head - Dashboard</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
	<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
	<script>document.documentElement.classList.add('js');</script>
	@php $pageCssPath = resource_path('css/feeding-feed-dashboard.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
</head>
<body>
<aside class="sidebar">
	<div class="sb-grid"></div>
	<div class="sb-logo">
		<img src="{{ asset('images/lusog-logo.png') }}" alt="LUSOG Logo" class="sb-logo-full">
	</div>
	<nav class="sb-nav">
		<div class="sb-section-label">Main</div>
		<a href="{{ route('dashboard.feedingcor-dashboard') }}" class="sb-link active">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
			Dashboard
		</a>
		<a href="{{ route('dashboard.feedingcor-health-records') }}" class="sb-link">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
			Student Health Records
		</a>
		<a href="{{ route('dashboard.feedingcor-program') }}" class="sb-link">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
			Feeding Program
		</a>
		<a href="{{ route('dashboard.feedingcor-sbfp-forms') }}" class="sb-link">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/><line x1="8" y1="9" x2="10" y2="9"/></svg>
			SBFP Forms
		</a>
	</nav>
	<div class="sb-user">
		@php
			$displayName = trim(auth()->user()->name ?? 'Feeding Coordinator');
			$initials = collect(preg_split('/\s+/', $displayName))
				->filter()
				->map(fn ($part) => strtoupper(substr($part, 0, 1)))
				->take(2)
				->implode('');
		@endphp
		<div class="sb-avatar">{{ $initials ?: 'FC' }}</div>
		<div class="sb-user-meta">
			<div class="sb-user-name">{{ auth()->user()->name ?? 'Feeding Coordinator' }}</div>
		</div>
		<form method="POST" action="{{ route('logout') }}">
			@csrf
			<button type="submit" class="sb-logout" title="Sign out">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
			</button>
		</form>
	</div>
</aside>

<div class="main">
	<header class="topbar">
		<div class="topbar-bc"><span>Dashboard</span><span>&gt;</span><span>Feeding Program</span></div>
		<div class="topbar-chip"><div class="dot"></div>Monitoring Active</div>
	</header>

	<div class="content">
		<div class="page-header" id="dashboard">
			<div class="page-eyebrow">Feeding Program</div>
			<h1 class="page-title">Dashboard <span>Feeding Program</span></h1>
			<p class="page-sub">Monitor JHS/SHS participation, nutritional outcomes, and weekly check-ins at a glance.</p>
		</div>

		<section class="stats">
			<article class="card stat">
				<div class="label">Enrolled Students</div>
				<div class="num">{{ $dashboardStats['total_students'] ?? 0 }}</div>
				<div class="hint">JHS: {{ $dashboardStats['jhs_count'] ?? 0 }} | SHS: {{ $dashboardStats['shs_count'] ?? 0 }}</div>
			</article>
			<article class="card stat">
				<div class="label">Program Day</div>
				<div class="num">{{ $dashboardStats['program_day'] ?? 0 }}</div>
				<div class="hint">of 120 day cycle</div>
			</article>
			<article class="card stat">
				<div class="label">Improving</div>
				<div class="num">{{ $dashboardStats['improving_rate'] ?? 0 }}%</div>
				<div class="hint">{{ $dashboardStats['improving_count'] ?? 0 }} of {{ $dashboardStats['total_students'] ?? 0 }} students</div>
			</article>
			<article class="card stat">
				<div class="label">Avg Check-ins</div>
				<div class="num">{{ $dashboardStats['avg_attendance'] ?? 0 }}%</div>
				<div class="hint">Last 5 weeks</div>
			</article>
		</section>

		<section class="grid-2" id="feeding-program">
			<article class="card chart-card">
				<h2 class="chart-title">Avg BMI Progress Over Time</h2>
				<div class="chart-surface">
				<svg class="chart-svg" viewBox="0 0 520 250" role="img" aria-label="Average BMI progress line chart">
					<defs>
						<linearGradient id="bmiAreaGradient" x1="0" y1="0" x2="0" y2="1">
							<stop offset="0%" stop-color="#2a9d8f" stop-opacity="0.25"></stop>
							<stop offset="100%" stop-color="#2a9d8f" stop-opacity="0"></stop>
						</linearGradient>
					</defs>
					<line class="axis-line" x1="48" y1="20" x2="48" y2="210"></line>
					<line class="axis-line" x1="48" y1="210" x2="500" y2="210"></line>
					<line class="grid-line" x1="48" y1="52" x2="500" y2="52"></line>
					<line class="grid-line" x1="48" y1="122" x2="500" y2="122"></line>
					<line class="grid-line" x1="48" y1="178" x2="500" y2="178"></line>
					<line class="grid-line" x1="48" y1="20" x2="48" y2="210"></line>
					<line class="grid-line" x1="138" y1="20" x2="138" y2="210"></line>
					<line class="grid-line" x1="228" y1="20" x2="228" y2="210"></line>
					<line class="grid-line" x1="318" y1="20" x2="318" y2="210"></line>
					<line class="grid-line" x1="408" y1="20" x2="408" y2="210"></line>
					<line class="grid-line" x1="500" y1="20" x2="500" y2="210"></line>

					@php
						$chartPoints = collect($bmiChart['points'] ?? []);
						$linePoints = $chartPoints->map(fn ($point) => $point['x'] . ',' . $point['y'])->implode(' ');
						$areaPoints = '48,210 ' . $linePoints . ' 500,210';
						$months = $bmiChart['month_labels'] ?? [];
						$yTicks = $bmiChart['y_ticks'] ?? [0, 0, 0];
					@endphp
					<polygon class="area-fill" points="{{ $areaPoints }}"></polygon>
					<polyline class="line-main" points="{{ $linePoints }}"></polyline>
					@foreach ($chartPoints as $point)
						<circle class="line-dot" cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="3.5"></circle>
					@endforeach

					<text class="axis-txt" x="24" y="56">{{ $yTicks[0] ?? 0 }}</text>
					<text class="axis-txt" x="24" y="126">{{ $yTicks[1] ?? 0 }}</text>
					<text class="axis-txt" x="24" y="182">{{ $yTicks[2] ?? 0 }}</text>
					@foreach ($months as $index => $month)
						<text class="axis-txt" x="{{ 40 + ($index * 90) }}" y="228">{{ $month }}</text>
					@endforeach
				</svg>
				</div>
			</article>

			<article class="card chart-card">
				<h2 class="chart-title">Student Progress Breakdown</h2>
				<div class="donut-wrap">
					<div class="donut" style="background: {{ $progressCounts['donut_style'] ?? 'conic-gradient(var(--teal) 0 100%)' }};">
						<div class="donut-center"><div><strong>{{ $dashboardStats['total_students'] ?? 0 }}</strong>Students</div></div>
					</div>
				</div>
				<div class="legend-row">
					<span class="legend-item"><span class="legend-dot" style="background: var(--teal);"></span>Improving ({{ $progressCounts['improving'] ?? 0 }})</span>
					<span class="legend-item"><span class="legend-dot" style="background: var(--blue);"></span>Stable ({{ $progressCounts['stable'] ?? 0 }})</span>
					<span class="legend-item"><span class="legend-dot" style="background: var(--red);"></span>Regressing ({{ $progressCounts['regressing'] ?? 0 }})</span>
				</div>
			</article>
		</section>

		<section class="card chart-card full-chart">
			<h2 class="chart-title">Weekly Check-ins (JHS/SHS)</h2>
			<div class="bars-area">
				@forelse (($weeklyBars ?? []) as $bar)
					<div class="bar-col">
						<div class="bar-value">{{ $bar['present'] }}</div>
						<div class="bar-stack">
							<div class="bar-good" style="height: {{ $bar['present_height'] }}px;"></div>
							<div class="bar-risk" style="height: {{ $bar['missed_height'] }}px;"></div>
						</div>
						<div class="bar-label">{{ $bar['label'] }}</div>
					</div>
				@empty
					<div class="bar-col"><div class="bar-value">0</div><div class="bar-stack"><div class="bar-good" style="height: 0;"></div><div class="bar-risk" style="height: 0;"></div></div><div class="bar-label">No Data</div></div>
				@endforelse
			</div>
		</section>

	</div>
</div>
<script>
(() => {
	const main = document.querySelector('.main');
	if (!main) {
		return;
	}

	requestAnimationFrame(() => {
		main.classList.add('page-ready');
	});

	window.addEventListener('pageshow', () => {
		main.classList.add('page-ready');
	});

	document.querySelectorAll('.sb-link[href]').forEach((link) => {
		link.addEventListener('click', (event) => {
			const href = link.getAttribute('href');
			if (!href || link.classList.contains('active')) {
				return;
			}
			if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
				return;
			}

			event.preventDefault();
			main.classList.remove('page-ready');
			main.classList.add('page-exit');
			window.setTimeout(() => {
				window.location.href = href;
			}, 220);
		});
	});
})();
</script>
</body>
</html>
