{{--
    The school head's headline numbers. Rendered on first paint and re-rendered
    into #sh-stats by the live refresh, so there is one copy of the markup.
    Needs $stats.
--}}
<article class="card stat">
    <div class="label">Total Students</div>
    <div class="num">{{ number_format($stats['total_students'] ?? 0) }}</div>
    <div class="hint">Enrolled this school year</div>
</article>
<article class="card stat">
    <div class="label">Active Programs</div>
    <div class="num">{{ $stats['active_programs'] ?? 0 }}</div>
    <div class="hint">Of 3 school health programs</div>
</article>
<article class="card stat">
    <div class="label">Wasted Rate</div>
    <div class="num">{{ $stats['wasted_rate'] ?? '0%' }}</div>
    <div class="hint">{{ number_format($stats['wasted_count'] ?? 0) }} of {{ number_format($stats['total_students'] ?? 0) }} students</div>
</article>
