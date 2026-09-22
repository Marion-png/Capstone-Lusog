{{--
    Improved / remained wasted / regressed, as shares of the whole roll.

    One partial for the coordinator's Nutritional Progress panel and the School
    Head's Reports, fed by FeedingNutritionProgress::split() so both print one
    answer. A stacked bar over every beneficiary — the learners not yet
    re-measured are drawn as their own segment, never dropped, so "60%
    improved" cannot be read off five learners in a programme of ninety — and
    a legend that carries every count and share as text, so nothing is gated
    behind a colour.

    Colour carries direction and nothing else: green up, amber level, red down,
    blue off the scale, and the unmeasured remainder is a neutral hatch. Those
    are the four hues at *chart* steps rather than at the interface tints the
    bar used to borrow — a tint is sized to sit behind text in a badge, and two
    of them failed the dataviz checks outright as fills (the amber at 1.74:1
    against the card, the teal under the chroma floor, so it read as grey).
    The steps here pass all six. Every segment is also labelled in the legend,
    so the bar reads without colour at all.

    Needs $split (from ::split()), optional $splitTitle.
--}}
@once
	<style>
		/* Declared here, not read from a role's sheet. This partial is drawn by
		   the Feeding Coordinator and by the School Head, and the --sh-* steps
		   live only in schoolhead.css — so reading them would give the head the
		   tokens and the coordinator the fallbacks, and one retheme would leave
		   the two roles drawing the same split in different colours. */
		.os-wrap{
			margin-top:14px;
			--os-improved:#126B3A;
			--os-unchanged:#C97A1A;
			--os-declined:#8C2F2F;
			--os-off-scale:#2F6FB3;
		}
		.os-head{display:flex;align-items:baseline;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:8px}
		.os-title{font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--lg-ink-soft,#6B7C72)}
		.os-total{font-size:.76rem;color:var(--lg-ink-soft,#6B7C72);font-variant-numeric:tabular-nums}
		.os-bar{display:flex;height:14px;border-radius:999px;overflow:hidden;background:var(--lg-rail,#EEF4F0)}
		/* 2px of the surface colour between fills, never a stroke: two
		   segments that appear to touch are one segment to a reader who
		   cannot separate the hues. The gap is a box-shadow rather than a
		   margin so it costs the bar no width and the shares still sum
		   to the roll. */
		.os-seg{height:100%;min-width:0;transition:filter .12s ease}
		.os-seg + .os-seg{box-shadow:-2px 0 0 0 var(--lg-card,#fff)}
		.os-seg:hover,.os-seg:focus-visible{filter:saturate(1.15) brightness(.94)}
		/* Same four hues as before — green up, amber level, red down, blue off
		   the scale — but at steps that pass the dataviz checks as *fills*.
		   The tints they used to carry are interface colours, sized for a
		   badge behind text, and two of them failed outright on a chart:
		   --lg-amber #F2B84B sits at L .82 with 1.74:1 against the card (the
		   theme already records that it fails as a line colour) and --lg-info
		   #3D8FA3 falls under the chroma floor, so it reads as grey to anyone
		   it reads as anything. These are the School Head's status steps,
		   already validated as a set: all six checks pass, worst adjacent
		   ΔE 12.6 protan / 22.7 normal, every slot ≥ 3:1.
		   Re-run scripts/validate_palette.js before changing any of them. */
		.os-seg-improved{background:var(--os-improved)}
		.os-seg-unchanged{background:var(--os-unchanged)}
		.os-seg-declined{background:var(--os-declined)}
		.os-seg-off_scale{background:var(--os-off-scale)}
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
					<span class="os-seg os-seg-{{ $segment['key'] }}" style="width: {{ $segment['pct'] }}%"
					data-tip-title="{{ $segment['label'] }}"
					data-tip="{{ $segment['count'] }} of {{ $osTotal }} beneficiaries ({{ rtrim(rtrim(number_format($segment['pct'], 1), '0'), '.') }}%)"></span>
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
