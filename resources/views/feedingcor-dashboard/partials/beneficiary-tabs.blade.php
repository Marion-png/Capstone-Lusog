{{-- Three views of one roster, with the size of each on the tab itself so the
     coordinator knows what is behind a view before opening it.

     The counts come from the same FeedingBeneficiarySummary the cards read, so
     "All beneficiaries" is always the Total Beneficiaries figure and "At risk"
     is always the At Risk figure — a tab can never disagree with the card above
     it. Re-rendered on every live refresh alongside the cards. --}}
@php
	$segView = $segmentView ?? 'all';
	$segCounts = $segmentCounts ?? [];
	$segTabs = [
		['key' => 'all', 'label' => 'All beneficiaries'],
		['key' => 'pending', 'label' => 'Pending enrollment'],
		['key' => 'at_risk', 'label' => 'At risk'],
	];
	// A tab keeps every filter already applied and changes only the view, so
	// switching views never silently drops the grade the coordinator chose.
	$segQuery = fn (string $key): string => '?'.http_build_query(
		array_filter(request()->query(), fn ($value, $name) => $name !== 'view' && $value !== '', ARRAY_FILTER_USE_BOTH)
		+ ['view' => $key]
	);
@endphp
<nav class="seg-tabs" aria-label="Beneficiary views">
	@foreach ($segTabs as $tab)
		<a class="seg-tab {{ $segView === $tab['key'] ? 'is-active' : '' }}"
			href="{{ $segQuery($tab['key']) }}"
			@if ($segView === $tab['key']) aria-current="page" @endif>
			<span class="seg-tab-label">{{ $tab['label'] }}</span>
			<span class="seg-tab-count">{{ (int) ($segCounts[$tab['key']] ?? 0) }}</span>
		</a>
	@endforeach
</nav>
