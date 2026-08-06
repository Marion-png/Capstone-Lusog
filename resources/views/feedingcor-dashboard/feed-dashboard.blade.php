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
    <style>{!! file_get_contents(resource_path('css/feedingcor-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.feedingcor-sidebar', ['active' => 'dashboard'])

<div class="main">
	<header class="topbar">
		<div class="topbar-bc"><span>Dashboard</span><span>&rsaquo;</span><span>Feeding Program</span></div>
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
					// Hit areas are full-height columns so a data point stays an
					// easy target on a phone, well past the 44px minimum.
					$hitWidth = count($chartMonths) > 1
						? ($plot['right'] - $plot['left']) / (count($chartMonths) - 1)
						: ($plot['right'] - $plot['left']);
				@endphp
				<svg class="chart-svg bmi-chart-svg" viewBox="0 0 {{ ($plot['right'] ?? 900) + 20 }} 238" role="img" aria-label="Average BMI progress line chart">
					{{-- One vertical gradient feeds the line, the fill and every marker
					     ring, so a point's colour is the WHO band it sits in. Stops are
					     positioned by buildBmiChart at the real 18.5 / 25 boundaries. --}}
					<defs>
						<linearGradient id="bmiZoneGrad" gradientUnits="userSpaceOnUse" x1="0" y1="{{ $plot['top'] }}" x2="0" y2="{{ $plot['bottom'] }}">
							@foreach (($bmiChart['gradient_stops'] ?? []) as $stop)
								<stop class="grad-{{ $stop['zone'] }}" offset="{{ $stop['offset'] }}"></stop>
							@endforeach
						</linearGradient>
						{{-- Fades the fill downward so colour is densest under the curve
						     and clears out before it reaches the axis labels. --}}
						<linearGradient id="bmiFadeGrad" gradientUnits="userSpaceOnUse" x1="0" y1="{{ $plot['top'] }}" x2="0" y2="{{ $plot['bottom'] }}">
							<stop offset="0" stop-color="#ffffff" stop-opacity=".85"></stop>
							<stop offset=".5" stop-color="#ffffff" stop-opacity=".3"></stop>
							<stop offset=".9" stop-color="#ffffff" stop-opacity="0"></stop>
						</linearGradient>
						<mask id="bmiFadeMask">
							<rect x="0" y="0" width="{{ ($plot['right'] ?? 900) + 20 }}" height="238" fill="url(#bmiFadeGrad)"></rect>
						</mask>
					</defs>

					@foreach (($bmiChart['bands'] ?? []) as $band)
						<rect class="bmi-band band-{{ $band['class'] }}" x="{{ $plot['left'] }}" y="{{ $band['y'] }}" width="{{ $plot['right'] - $plot['left'] }}" height="{{ $band['height'] }}"></rect>
					@endforeach

					@foreach (($bmiChart['grid_lines'] ?? []) as $gridY)
						<line class="grid-line" x1="{{ $plot['left'] }}" y1="{{ $gridY }}" x2="{{ $plot['right'] }}" y2="{{ $gridY }}"></line>
					@endforeach

					@foreach (($bmiChart['zone_lines'] ?? []) as $zoneY)
						<line class="zone-line" x1="{{ $plot['left'] }}" y1="{{ $zoneY }}" x2="{{ $plot['right'] }}" y2="{{ $zoneY }}"></line>
					@endforeach

					<path class="area-fill" d="{{ $bmiChart['area_path'] ?? '' }}" mask="url(#bmiFadeMask)"></path>
					<path class="line-main" d="{{ $bmiChart['line_path'] ?? '' }}"></path>

					{{-- Zone labels paint last so the line and area fill can never sit on top of them. --}}
					@foreach (($bmiChart['bands'] ?? []) as $band)
						<text class="bmi-band-label label-{{ $band['class'] }}" x="{{ $plot['left'] + 10 }}" y="{{ $band['label_y'] }}">{{ $band['label'] }}</text>
					@endforeach

					@foreach ($chartMonths as $month)
						<g class="bmi-point" data-index="{{ $month['index'] }}" tabindex="0" role="button"
							aria-label="{{ $month['full'] }}@if ($month['has_data']) — average BMI {{ $month['avg_bmi'] }}, {{ $month['band'] }}, {{ $month['count'] }} learners @else — no measurements @endif">
							<rect class="bmi-hit" x="{{ round($month['x'] - $hitWidth / 2, 1) }}" y="{{ $plot['top'] }}" width="{{ round($hitWidth, 1) }}" height="{{ $plot['bottom'] - $plot['top'] }}"></rect>
							<circle class="bmi-dot{{ $month['is_current'] ? ' is-current' : '' }}{{ $month['has_data'] ? '' : ' no-data' }}" cx="{{ $month['x'] }}" cy="{{ $month['y'] }}" r="{{ $month['is_current'] ? 4.5 : 4 }}"></circle>
						</g>
					@endforeach

					@foreach ($yTicks as $tick)
						<text class="axis-txt axis-y" x="{{ $plot['left'] - 12 }}" y="{{ $tick['y'] + 4 }}" text-anchor="end">{{ $tick['label'] }}</text>
					@endforeach
					@foreach ($chartMonths as $month)
						<text class="axis-txt axis-x" x="{{ $month['x'] }}" y="{{ $plot['bottom'] + 26 }}" text-anchor="middle">{{ $month['label'] }}</text>
					@endforeach
				</svg>
				<div class="bmi-card" id="bmiMonthSummary" role="status" data-default="{{ $bmiChart['default_index'] ?? 0 }}"></div>
				</div>
				@if (! empty($bmiChart['has_outlier']))
					<div class="bmi-outlier-banner">&#9888; {{ $bmiChart['outlier_label'] }}'s average is an outlier &mdash; worth verifying against individual records before reporting.</div>
				@endif
				<script>
				(() => {
					const months = @json($bmiChart['months'] ?? []);
					const card = document.getElementById('bmiMonthSummary');
					const surface = document.querySelector('.chart-surface');
					if (!card || !surface) return;

					const points = Array.from(surface.querySelectorAll('.bmi-point'));
					const bandClass = (b) => b === 'Overweight watch' ? 'over' : (b === 'Underweight watch' ? 'under' : 'healthy');
					const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
					let pinned = null;

					const build = (m) => {
						let h = '<div class="bmi-card-head"><span class="bmi-card-date">' + esc(m.full) + '</span>';
						if (m.band) h += '<span class="bmi-card-zone ' + bandClass(m.band) + '">' + esc(m.band) + '</span>';
						h += '</div>';
						if (m.has_data) {
							h += '<div class="bmi-card-figure"><span class="bmi-card-value">' + m.avg_bmi + '</span><span class="bmi-card-unit">avg BMI</span>';
							if (m.delta !== null && m.delta !== undefined) {
								const dir = m.delta > 0 ? 'up' : (m.delta < 0 ? 'down' : 'flat');
								const sign = m.delta > 0 ? '+' : '';
								h += '<span class="bmi-card-delta ' + dir + '">' + sign + m.delta.toFixed(1) + ' vs previous</span>';
							} else {
								h += '<span class="bmi-card-delta flat">first reading</span>';
							}
							h += '</div>';
							h += '<div class="bmi-card-meta">' + m.count + ' learner' + (m.count === 1 ? '' : 's') + ' measured</div>';
							if (m.status && m.status.length) h += '<div class="bmi-card-chips">' + m.status.map((s) => '<span class="bmi-chip">' + esc(s.label) + ': ' + s.count + '</span>').join('') + '</div>';
							if (m.is_outlier) h += '<div class="bmi-card-flag">Flagged as an outlier &mdash; verify individual records.</div>';
						} else {
							h += '<div class="bmi-card-empty">No measurements recorded this month.</div>';
						}
						return h;
					};

					const show = (i) => {
						const m = months[i];
						const point = points[i];
						if (!m || !point) return;

						card.innerHTML = build(m);
						card.classList.add('show');
						points.forEach((p) => p.classList.toggle('is-active', p === point));

						// Anchor above the dot, clamped so the card never leaves the surface.
						const dot = point.querySelector('.bmi-dot').getBoundingClientRect();
						const box = surface.getBoundingClientRect();
						const half = card.offsetWidth / 2;
						const x = Math.max(half + 8, Math.min(box.width - half - 8, dot.left + dot.width / 2 - box.left));
						const above = dot.top - box.top - 14;
						card.style.left = x + 'px';
						card.classList.toggle('below', above < card.offsetHeight);
						card.style.top = (above < card.offsetHeight ? dot.bottom - box.top + 14 : above) + 'px';
					};

					const hide = () => {
						if (pinned !== null) return;
						card.classList.remove('show');
						points.forEach((p) => p.classList.remove('is-active'));
					};

					const togglePin = (i) => {
						if (pinned === i) {
							pinned = null;
							card.classList.remove('is-pinned');
							hide();
							return;
						}
						pinned = i;
						card.classList.add('is-pinned');
						show(i);
					};

					points.forEach((point, i) => {
						point.addEventListener('pointerenter', () => { if (pinned === null) show(i); });
						point.addEventListener('pointerleave', hide);
						point.addEventListener('focus', () => { if (pinned === null) show(i); });
						point.addEventListener('blur', hide);
						point.addEventListener('click', (e) => { e.stopPropagation(); togglePin(i); });
						point.addEventListener('keydown', (e) => {
							if (e.key !== 'Enter' && e.key !== ' ') return;
							e.preventDefault();
							togglePin(i);
						});
					});

					// Clicking away releases the pin.
					document.addEventListener('click', (e) => {
						if (pinned === null || e.target.closest('.bmi-point')) return;
						pinned = null;
						card.classList.remove('is-pinned');
						hide();
					});
					document.addEventListener('keydown', (e) => {
						if (e.key !== 'Escape' || pinned === null) return;
						pinned = null;
						card.classList.remove('is-pinned');
						hide();
					});
					window.addEventListener('resize', () => { if (pinned !== null) show(pinned); });
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

	document.querySelectorAll('.asb-link[href]').forEach((link) => {
		link.addEventListener('click', (event) => {
			const href = link.getAttribute('href');
			if (!href || href === '#' || link.classList.contains('active')) {
				return;
			}
			if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
				return;
			}

			event.preventDefault();
			main.classList.remove('page-ready');
			main.classList.add('page-exit');
			// Matches --asb-page-out in feedingcor-sidebar.css.
			window.setTimeout(() => {
				window.location.href = href;
			}, 340);
		});
	});
})();
</script>
</body>
</html>
