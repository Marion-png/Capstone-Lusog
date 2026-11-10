<!DOCTYPE html>
{{--
	The narrative report as a PDF (App\Support\NarrativeReportPdf).

	The form the SBFP Forms page prints — letterhead, title line, five headed
	sections, Prepared by / Noted by — set for dompdf, which lays out tables
	and blocks but not grids or flex, so the letterhead and the signature block
	are tables. Every value is the coordinator's own typing, escaped; nothing
	here is fetched.
--}}
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Narrative Report</title>
<style>
	@page { size: A4 portrait; margin: 14mm 14mm 16mm; }
	body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #0f172a; margin: 0; }
	.lh { width: 100%; border-collapse: collapse; }
	.lh td { vertical-align: middle; text-align: center; padding: 0; }
	.lh .seal { width: 78px; }
	.lh .seal img { width: 66px; height: 66px; }
	.lh .seal-slot { display: inline-block; width: 66px; height: 66px; }
	.lh-republic { font-size: 8.5pt; }
	.lh-department { font-size: 10pt; font-weight: bold; }
	.lh-region, .lh-division { font-size: 8pt; }
	.lh-school { font-size: 11.5pt; font-weight: bold; text-transform: uppercase; margin-top: 2px; }
	.lh-address { font-size: 8pt; font-style: italic; min-height: 10pt; }
	.head { border-bottom: 1px solid #111827; padding-bottom: 8px; text-align: center; }
	.title { margin: 10px auto 0; width: 92%; font-size: 10pt; font-weight: bold; line-height: 1.4; text-transform: uppercase; }
	.body { padding: 10px 2px 4px; }
	.section { margin-bottom: 12px; }
	.label { font-size: 9.5pt; font-weight: bold; margin-bottom: 4px; }
	.text { font-size: 9.5pt; line-height: 1.6; text-align: justify; }
	.foot { width: 100%; border-collapse: collapse; margin-top: 10px; page-break-inside: avoid; }
	.foot td { width: 50%; border: 1px solid #111827; padding: 6px 10px 8px; vertical-align: top; }
	.foot-label { font-size: 8.5pt; font-weight: bold; text-transform: uppercase; }
	.sign { margin-top: 26px; border-bottom: 1px solid #111827; padding: 3px 2px; font-size: 9.5pt; font-weight: bold; text-align: center; text-transform: uppercase; min-height: 12pt; }
	.role { margin-top: 5px; font-size: 9pt; font-weight: bold; text-align: center; }
</style>
</head>
<body>
	<div class="head">
		<table class="lh">
			<tr>
				<td class="seal">
					@if ($seals['deped'])
						<img src="{{ $seals['deped'] }}" alt="">
					@else
						<span class="seal-slot"></span>
					@endif
				</td>
				<td>
					<div class="lh-republic">{{ $letterhead['republic'] }}</div>
					<div class="lh-department">{{ $letterhead['department'] }}</div>
					<div class="lh-region">{{ $letterhead['region'] }}</div>
					<div class="lh-division">{{ \App\Support\SchoolLetterhead::divisionLine($letterhead['division']) }}</div>
					<div class="lh-school">{{ $letterhead['school'] }}</div>
					<div class="lh-address">{{ $letterhead['address'] }}</div>
				</td>
				<td class="seal">
					@if ($seals['school'])
						<img src="{{ $seals['school'] }}" alt="">
					@else
						<span class="seal-slot"></span>
					@endif
				</td>
			</tr>
		</table>
		<div class="title">
			Report on the Feeding Program of {{ $letterhead['school'] }} Funded by
			{{ $funder !== '' ? $funder : '____________________' }}
		</div>
	</div>

	<div class="body">
		@foreach ($headings as $key => $heading)
			<div class="section">
				<div class="label">{{ $heading }}</div>
				<div class="text">{!! nl2br(e($sections[$key] ?? '')) !!}</div>
			</div>
		@endforeach
	</div>

	<table class="foot">
		<tr>
			<td>
				<div class="foot-label">Prepared by:</div>
				<div class="sign">{{ $preparedName }}</div>
				<div class="role">School Feeding Coordinator</div>
			</td>
			<td>
				<div class="foot-label">Noted by:</div>
				<div class="sign">{{ $notedName }}</div>
				<div class="role">{{ $notedRole }}</div>
			</td>
		</tr>
	</table>
</body>
</html>
