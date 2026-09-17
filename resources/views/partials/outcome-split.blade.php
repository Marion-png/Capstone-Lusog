{{--
    Improved / remained wasted / regressed, as shares of the whole roll.

    One partial for the coordinator's Nutritional Progress panel and the School
    Head's Reports, fed by FeedingNutritionProgress::split() so both print one
    answer. A stacked bar over every beneficiary — the learners not yet
    re-measured are drawn as their own segment, never dropped, so "60%
    improved" cannot be read off five learners in a programme of ninety — and
    a legend that carries every count and share as text, so nothing is gated
    behind a colour.

    Colour carries direction and nothing else: emerald up, amber level, coral
    down, teal off the scale, and the unmeasured remainder is a neutral hatch.
    Every segment is also labelled in the legend, so the bar reads without it.

    Needs $split (from ::split()), optional $splitTitle.
--}}
@once
	<style>
		.os-wrap{margin-top:14px}
		.os-head{display:flex;align-items:baseline;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:8px}
		.os-title{font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--lg-ink-soft,#6B7C72)}
		.os-total{font-size:.76rem;color:var(--lg-ink-soft,#6B7C72);font-variant-numeric:tabular-nums}
		.os-bar{display:flex;height:14px;border-radius:999px;overflow:hidden;background:var(--lg-rail,#EEF4F0)}
		.os-seg{height:100%;min-width:0}
		.os-seg-improved{background:var(--series-healthy,#126B3A)}
		.os-seg-unchanged{background:var(--lg-amber,#F2B84B)}
		.os-seg-declined{background:var(--lg-danger,#D95C5C)}
		.os-seg-off_scale{background:var(--lg-info,#3D8FA3)}
		.os-seg-unmeasured{background:repeating-linear-gradient(135deg,#D7E2DB 0 4px,#EEF4F0 4px 8px)}
		.os-legend{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:6px 14px;margin-top:10px}
		.os-item{display:flex;align-items:center;gap:8px;font-size:.78rem;color:var(--lg-ink,#1F2D25)}
		.os-dot{width:10px;height:10px;border-radius:3px;flex:0 0 auto}
		.os-item b{font-variant-numeric:tabular-nums;margin-left:auto;white-space:nowrap}
		.os-item small{color:var(--lg-ink-soft,#6B7C72);font-variant-numeric:tabular-nums}
		.os-empty{font-size:.78rem;color:var(--lg-ink-soft,#6B7C72);margin-top:10px}
	</style>
@endonce
@php
	$osTotal = (int) ($split['total'] ?? 0);
	$osMeasured = (int) ($split['measured'] ?? 0);
	$osSegments = $split['segments'] ?? [];
@endphp
<div class="os-wrap">
	<div class="os-head">
		<span class="os-title">{{ $splitTitle ?? 'Outcome, baseline to endline' }}</span>
		<span class="os-total">{{ $osMeasured }} of {{ $osTotal }} {{ \Illuminate\Support\Str::plural('beneficiary', $osTotal) }} measured at endline</span>
	</div>
	@if ($osTotal === 0)
		<p class="os-empty">No beneficiaries enrolled, so there is no outcome to split.</p>
	@else
		<div class="os-bar" role="img" aria-label="{{ collect($osSegments)->filter(fn ($s) => $s['count'] > 0)->map(fn ($s) => $s['label'].' '.$s['count'].' ('.$s['pct'].'%)')->implode(', ') }}">
			@foreach ($osSegments as $segment)
				@if ($segment['count'] > 0)
					<span class="os-seg os-seg-{{ $segment['key'] }}" style="width: {{ $segment['pct'] }}%"></span>
				@endif
			@endforeach
		</div>
		<div class="os-legend">
			@foreach ($osSegments as $segment)
				{{-- Off-scale is rare and only worth a line when it happened. --}}
				@continue($segment['key'] === 'off_scale' && $segment['count'] === 0)
				<div class="os-item" data-outcome="{{ $segment['key'] }}">
					<i class="os-dot os-seg-{{ $segment['key'] }}"></i>
					<span>{{ $segment['label'] }}</span>
					<b>{{ $segment['count'] }} <small>&middot; {{ rtrim(rtrim(number_format($segment['pct'], 1), '0'), '.') }}%</small></b>
				</div>
			@endforeach
		</div>
	@endif
</div>
