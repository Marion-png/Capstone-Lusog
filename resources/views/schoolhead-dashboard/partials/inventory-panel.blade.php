{{--
    Whether the clinic can still dispense. Stock levels and reorder standings
    only: receiving and dispensing belong to the clinic, and the dispensing log
    names the learner a medicine went to, which is not a head's reading.
    Needs $inventory.

    The rows listed are the ones under their own reorder line, worst first — a
    well-stocked shelf needs no row.
--}}
@php $shAttention = collect($inventory['attention_rows'])->take(\App\Support\SchoolHeadHealthOverview::PANEL_ROWS); @endphp

<div class="sh-figs">
    <div class="sh-fig">
        <div class="sh-fig-label">Medicines Tracked</div>
        <div class="sh-fig-value tnum">{{ number_format($inventory['tracked']) }}</div>
    </div>
    <div class="sh-fig">
        <div class="sh-fig-label">Low Stock</div>
        <div class="sh-fig-value tnum">{{ number_format($inventory['low']) }}</div>
    </div>
    <div class="sh-fig">
        <div class="sh-fig-label">Out of Stock</div>
        <div class="sh-fig-value tnum">{{ number_format($inventory['out']) }}</div>
    </div>
    <div class="sh-fig">
        <div class="sh-fig-label">Dispensed This Month</div>
        <div class="sh-fig-value tnum">{{ number_format($inventory['dispensed_this_month']) }}</div>
    </div>
</div>

@if ($inventory['tracked'] === 0)
    <div class="sh-empty">No medicine on this school&rsquo;s inventory yet.</div>
@elseif ($shAttention->isEmpty())
    <div class="sh-empty">Every medicine is above its reorder threshold.</div>
@else
    <div class="table-card">
        <table class="sh-table">
            <thead>
                <tr>
                    <th>Medicine</th>
                    <th class="num">Stock</th>
                    <th class="num">Reorder at</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($shAttention as $row)
                    <tr>
                        <td>{{ $row['name'] }}</td>
                        <td class="num tnum">{{ number_format($row['stock']) }} {{ $row['unit'] }}</td>
                        <td class="num tnum">{{ number_format($row['threshold']) }}</td>
                        <td><span class="badge {{ $row['badge'] }}">{{ $row['label'] }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

<a class="btn btn-secondary sh-panel-action" href="{{ route('dashboard.school-head.inventory') }}">
    View inventory
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
</a>
