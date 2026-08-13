{{-- Beneficiaries broken down by the status they were enrolled on. The counts
     always sum to the total above them, so this panel and the Beneficiary card
     can never tell two different stories. --}}
<div class="ns-total">
	<span class="ns-total-label">Total Beneficiaries</span>
	<span class="ns-total-value">{{ $nutritionStatus['total'] ?? 0 }}</span>
</div>

<table class="ns-table">
	<thead>
		<tr>
			<th>Baseline Status</th>
			<th class="ta-r">Count</th>
		</tr>
	</thead>
	<tbody>
		@foreach (($nutritionStatus['rows'] ?? []) as $row)
			<tr class="{{ $row['eligible'] ? 'is-eligible' : '' }}">
				<td><span class="badge {{ $row['badge'] }}">{{ $row['label'] }}</span></td>
				<td class="ta-r ns-count">{{ $row['count'] }}</td>
			</tr>
		@endforeach
	</tbody>
</table>
