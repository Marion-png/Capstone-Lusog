<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>Feeding Coordinator Dashboard - SIGLA</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
	<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
	<script>document.documentElement.classList.add('js');</script>
	@php $pageCssPath = resource_path('css/feeding-dashboard.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
</head>
<body>
<aside class="sidebar">
	<div class="sb-grid"></div>
	<div class="sb-logo">
		<img src="{{ asset('images/lusog-logo.png') }}" alt="SIGLA Logo" class="sb-logo-full">
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
			$displayName = trim((string) session('active_name', 'Feeding Coordinator'));
			$initials = collect(preg_split('/\s+/', $displayName))
				->filter()
				->map(fn ($part) => strtoupper(substr($part, 0, 1)))
				->take(2)
				->implode('');
		@endphp
		<div class="sb-avatar">{{ $initials ?: 'FC' }}</div>
		<div class="sb-user-meta">
			<div class="sb-user-name">{{ $displayName }}</div>
			<div class="sb-user-role">{{ session('active_school_name', 'No school assigned') }}</div>
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
		<div class="topbar-bc"><span>Dashboard</span><span>&rsaquo;</span><span>Feeding Program</span></div>
		<div class="topbar-chip"><div class="dot"></div>Monitoring Active</div>
	    @include('partials.live-clock')
	</header>

	<div class="content">
		<div class="page-header" id="dashboard">
			<div class="page-eyebrow">Feeding Program</div>
			<h1 class="page-title">Dashboard <span>Feeding Program</span></h1>
			<p class="page-sub">Monitor JHS/SHS participation, nutritional outcomes, and weekly check-ins at a glance.</p>
		</div>

		@include('partials.announcements')

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

		<section class="dash-stack" id="feeding-program">
			<article class="card chart-card full-chart">
				<h2 class="chart-title">Avg BMI Progress Over Time</h2>
				<div class="chart-surface">
				@php
					$plot = $bmiChart['plot'] ?? ['left' => 48, 'right' => 900, 'top' => 24, 'bottom' => 196];
					$chartMonths = $bmiChart['months'] ?? [];
					$yTicks = $bmiChart['y_ticks'] ?? [];
				@endphp
				<svg class="chart-svg bmi-chart-svg" viewBox="0 0 {{ ($plot['right'] ?? 900) + 20 }} 250" role="img" aria-label="Average BMI progress line chart">
					<defs>
						<linearGradient id="bmiAreaGradient" x1="0" y1="0" x2="0" y2="1">
							<stop offset="0%" stop-color="#2a9d8f" stop-opacity="0.30"></stop>
							<stop offset="55%" stop-color="#2a9d8f" stop-opacity="0.08"></stop>
							<stop offset="100%" stop-color="#2a9d8f" stop-opacity="0"></stop>
						</linearGradient>
						<linearGradient id="bmiLineGradient" x1="0" y1="0" x2="1" y2="0">
							<stop offset="0%" stop-color="#1f9d76"></stop>
							<stop offset="55%" stop-color="#2a9d8f"></stop>
							<stop offset="100%" stop-color="#12b3a6"></stop>
						</linearGradient>
						<filter id="bmiLineGlow" x="-20%" y="-50%" width="140%" height="220%">
							<feDropShadow dx="0" dy="3" stdDeviation="4" flood-color="#2a9d8f" flood-opacity="0.30"></feDropShadow>
						</filter>
						<filter id="bmiDotShadow" x="-70%" y="-70%" width="240%" height="240%">
							<feDropShadow dx="0" dy="1.5" stdDeviation="1.6" flood-color="#0d3b33" flood-opacity="0.30"></feDropShadow>
						</filter>
					</defs>

					@foreach (($bmiChart['bands'] ?? []) as $band)
						<rect class="bmi-band band-{{ $band['class'] }}" x="{{ $plot['left'] }}" y="{{ $band['y'] }}" width="{{ $plot['right'] - $plot['left'] }}" height="{{ max(0, $band['height']) }}"></rect>
						<text class="bmi-band-label" x="{{ $plot['left'] + 6 }}" y="{{ $band['label_y'] }}">{{ $band['label'] }}</text>
					@endforeach

					@foreach ($yTicks as $tick)
						<line class="grid-line" x1="{{ $plot['left'] }}" y1="{{ $tick['y'] }}" x2="{{ $plot['right'] }}" y2="{{ $tick['y'] }}"></line>
					@endforeach
					<line class="axis-line" x1="{{ $plot['left'] }}" y1="{{ $plot['bottom'] }}" x2="{{ $plot['right'] }}" y2="{{ $plot['bottom'] }}"></line>

					<path class="area-fill" d="{{ $bmiChart['area_path'] ?? '' }}"></path>
					<path class="line-main" d="{{ $bmiChart['line_path'] ?? '' }}"></path>
					@foreach ($chartMonths as $month)
						<circle class="line-dot bmi-dot{{ $month['is_outlier'] ? ' is-outlier' : '' }}{{ $month['has_data'] ? '' : ' no-data' }}" cx="{{ $month['x'] }}" cy="{{ $month['y'] }}" r="{{ $month['is_outlier'] ? 6 : 5 }}" data-index="{{ $month['index'] }}" tabindex="0" role="button" aria-label="{{ $month['full'] }} summary">
							<title>{{ $month['full'] }}@if ($month['has_data']) &mdash; {{ $month['count'] }} learners, avg BMI {{ $month['avg_bmi'] }} ({{ $month['band'] }})@endif</title>
						</circle>
					@endforeach

					@foreach ($yTicks as $tick)
						<text class="axis-txt" x="{{ $plot['left'] - 10 }}" y="{{ $tick['y'] + 4 }}" text-anchor="end">{{ $tick['label'] }}</text>
					@endforeach
					@foreach ($chartMonths as $month)
						<text class="axis-txt" x="{{ $month['x'] }}" y="{{ $plot['bottom'] + 24 }}" text-anchor="middle">{{ $month['label'] }}</text>
					@endforeach
				</svg>
				</div>
				@if (! empty($bmiChart['has_outlier']))
					<div class="bmi-outlier-banner">&#9888; {{ $bmiChart['outlier_label'] }}'s average is an outlier &mdash; worth verifying against individual records before reporting.</div>
				@endif
				<div class="bmi-month-summary" id="bmiMonthSummary" data-default="{{ $bmiChart['default_index'] ?? 0 }}"></div>
				<script>
				(() => {
					const months = @json($bmiChart['months'] ?? []);
					const panel = document.getElementById('bmiMonthSummary');
					if (!panel) return;
					const dots = Array.from(document.querySelectorAll('.bmi-dot'));
					const bandClass = (b) => b === 'Overweight watch' ? 'over' : (b === 'Underweight watch' ? 'under' : 'healthy');
					const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
					const render = (i) => {
						const m = months[i];
						if (!m) return;
						let h = '<div class="bmi-sum-head"><span class="bmi-sum-month">' + esc(m.full) + '</span>';
						if (m.band) h += '<span class="bmi-sum-badge ' + bandClass(m.band) + '">' + esc(m.band) + '</span>';
						h += '</div>';
						if (m.has_data) {
							h += '<div class="bmi-sum-stats"><span><strong>' + m.count + '</strong> learner' + (m.count === 1 ? '' : 's') + ' measured</span><span><strong>' + m.avg_bmi + '</strong> avg BMI</span></div>';
							if (m.status && m.status.length) h += '<div class="bmi-sum-chips">' + m.status.map((s) => '<span class="bmi-chip">' + esc(s.label) + ': ' + s.count + '</span>').join('') + '</div>';
							if (m.is_outlier) h += '<div class="bmi-sum-flag">Flagged as an outlier &mdash; verify individual records.</div>';
						} else {
							h += '<div class="bmi-sum-empty">No measurements recorded this month.</div>';
						}
						panel.innerHTML = h;
						panel.classList.remove('bmi-anim');
						void panel.offsetWidth;
						panel.classList.add('bmi-anim');
						dots.forEach((d) => d.classList.toggle('bmi-dot-active', Number(d.dataset.index) === i));
					};
					const surface = document.querySelector('.chart-surface');
					let tip = null;
					if (surface) {
						tip = document.createElement('div');
						tip.className = 'bmi-tip';
						surface.appendChild(tip);
					}
					const showTip = (i, dot) => {
						if (!tip || !surface) return;
						const m = months[i];
						if (!m) return;
						let h = '<div class="bmi-tip-label">' + esc(m.label) + ' &middot; avg. BMI</div>';
						if (m.has_data) {
							h += '<div class="bmi-tip-value">' + m.avg_bmi + '</div>';
							if (m.is_outlier) h += '<div class="bmi-tip-flag">&#9873; flagged for review</div>';
						} else {
							h += '<div class="bmi-tip-empty">No measurements</div>';
						}
						tip.innerHTML = h;
						const dr = dot.getBoundingClientRect();
						const sr = surface.getBoundingClientRect();
						const half = tip.offsetWidth / 2;
						let x = dr.left + dr.width / 2 - sr.left;
						x = Math.max(half + 4, Math.min(sr.width - half - 4, x));
						tip.style.left = x + 'px';
						tip.style.top = (dr.top - sr.top) + 'px';
						tip.classList.add('show');
					};
					const hideTip = () => { if (tip) tip.classList.remove('show'); };
					dots.forEach((d) => {
						const i = Number(d.dataset.index);
						d.addEventListener('click', () => render(i));
						d.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); render(i); } });
						d.addEventListener('mouseenter', () => showTip(i, d));
						d.addEventListener('mouseleave', hideTip);
						d.addEventListener('focus', () => showTip(i, d));
						d.addEventListener('blur', hideTip);
					});
					render(Number(panel.dataset.default) || 0);
				})();
				</script>
			</article>

			<article class="card roster-card">
				<h2 class="chart-title">Student Roster</h2>
				<div class="roster-chips">
					<span class="rchip rchip-improving">Improving {{ $roster['improving'] }}</span>
					<span class="rchip rchip-stable">Stable {{ $roster['stable'] }}</span>
					<span class="rchip rchip-attention">Needs attention {{ $roster['attention'] }}</span>
				</div>
				<div class="roster-list">
					@forelse ($roster['students'] as $s)
						<div class="roster-row">
							<div class="roster-avatar">{{ $s['initials'] }}</div>
							<div class="roster-meta">
								<div class="roster-name">{{ $s['name'] }}</div>
								<div class="roster-grade">{{ $s['grade'] }}</div>
							</div>
							@if ($s['trend'] === 'up')
								<div class="roster-change up">&#9650; +{{ number_format($s['change'], 1) }}</div>
							@elseif ($s['trend'] === 'down')
								<div class="roster-change down">&#9660; {{ number_format($s['change'], 1) }}</div>
							@else
								<div class="roster-change flat">&middot; {{ number_format($s['change'], 1) }}</div>
							@endif
						</div>
					@empty
						<div class="roster-empty">No students on file yet.</div>
					@endforelse
				</div>
			</article>
		</section>

		<section class="card checkins-card full-chart">
			<h2 class="chart-title">Weight &amp; BMI Log</h2>
			@php $statusLabels = ['improving' => 'Improving', 'stable' => 'Stable', 'attention' => 'Needs attention']; @endphp
			<div class="table-scroll">
				<table class="checkins-table">
					<thead>
						<tr>
							<th>Student</th>
							<th>Grade</th>
							<th class="ta-r">Weight</th>
							<th class="ta-r">BMI</th>
							<th class="ta-r">Change</th>
							<th class="ta-r">Status</th>
						</tr>
					</thead>
					<tbody>
						@forelse ($roster['students'] as $s)
							<tr>
								<td class="ci-name">{{ $s['name'] }}</td>
								<td class="ci-grade">{{ $s['grade'] }}</td>
								<td class="ta-r ci-mono">{{ $s['weight'] }} kg</td>
								<td class="ta-r ci-mono">{{ $s['bmi'] }}</td>
								<td class="ta-r ci-mono {{ $s['trend'] }}">{{ $s['trend'] === 'up' ? '+' : '' }}{{ number_format($s['change'], 1) }}</td>
								<td class="ta-r"><span class="status-chip status-{{ $s['status'] }}">{{ $statusLabels[$s['status']] }}</span></td>
							</tr>
						@empty
							<tr><td colspan="6" class="ci-empty">No check-ins recorded yet.</td></tr>
						@endforelse
					</tbody>
				</table>
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
@include('partials.sidebar-hover-pin')
</body>
</html>
