{{--
    The heading every DepEd form on this page opens under.

    Two seals, the Republic / Department lines, the region and the division,
    then the school and its address — the block the printed form carries, in
    the order it carries it. It is one partial because four forms head the same
    school, and four copies of a heading are four chances for them to disagree.

    The seals are looked up as files, so a school that drops its own into
    public/images gets it on every form without a code change, and a school
    that has not is drawn a placed outline rather than a broken image: a form
    with a gap where the seal goes is still the right shape to sign.

    Needs $letterhead (App\Support\SchoolLetterhead::for) and $seals.
    $addressField — the draft key for the typed address, when the form keeps one.
--}}
@php
	$addressField = $addressField ?? null;
	$address = trim((string) ($letterhead['address'] ?? ''));
@endphp
<div class="lh-block">
	<div class="lh-seal lh-seal-left">
		@if (! empty($seals['deped']))
			<img src="{{ asset($seals['deped']) }}" alt="Department of Education">
		@else
			<span class="lh-seal-slot" aria-hidden="true">DepEd</span>
		@endif
	</div>

	<div class="lh-lines">
		<div class="lh-republic">{{ $letterhead['republic'] }}</div>
		<div class="lh-department">{{ $letterhead['department'] }}</div>
		<div class="lh-region">{{ $letterhead['region'] }}</div>
		<div class="lh-division">{{ \App\Support\SchoolLetterhead::divisionLine($letterhead['division']) }}</div>
		<div class="report-school">{{ $letterhead['school'] }}</div>
		{{-- The address the school is on file with. A school with none gets a
		     line to write on, never a neighbouring school's street. --}}
		@if ($addressField !== null)
			<input type="text" class="report-addr-input" data-field="{{ $addressField }}"
				value="{{ $address }}" placeholder="School address" aria-label="School address">
		@else
			<div class="lh-address">{{ $address !== '' ? $address : '' }}</div>
		@endif
	</div>

	<div class="lh-seal lh-seal-right">
		@if (! empty($seals['school']))
			<img src="{{ asset($seals['school']) }}" alt="{{ $letterhead['school'] }}">
		@else
			<span class="lh-seal-slot" aria-hidden="true">School</span>
		@endif
	</div>
</div>
