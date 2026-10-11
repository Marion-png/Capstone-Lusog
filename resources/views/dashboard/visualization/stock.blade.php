{{-- Each medicine's stock against its own reorder line, worst first. Bars
     are scaled per item (units differ), so the reading is the fill against
     the tick. Every row carries its status badge, never colour alone. --}}
@php
    $states = [
        'out' => ['Out of stock', 'badge-critical'],
        'low' => ['Low', 'badge-risk'],
        'monitor' => ['Monitor', 'badge-monitor'],
        'good' => ['Good', 'badge-normal'],
    ];
    $stockRows = $stock['rows'];
    $extra = max(0, count($stockRows) - $stock['visible']);
@endphp
<div class="nv-head">
    <div>
        <h2 class="nv-title">Medicine Inventory</h2>
        <p class="nv-meta">Source: Medicine Inventory Module (stock quantity vs minimum threshold)</p>
    </div>
    <div class="nv-tools">
        <a class="nv-tool" href="{{ route('dashboard.medicine-inventory') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7"/><path d="M7 7h10v10"/></svg>
            <span>Inventory</span>
        </a>
        @include('dashboard.visualization.table-toggle')
    </div>
</div>
<div class="nv-body">
    @if (empty($stockRows))
        <div class="nv-empty">No medicine on the inventory.</div>
    @else
        <div class="nv-chart">
            <div class="nv-stock-summary">
                @foreach ($states as $state => [$label, $badge])
                    <span class="badge {{ $badge }}"><span class="tnum">{{ number_format($stock['counts'][$state]) }}</span>&nbsp;{{ $label }}</span>
                @endforeach
                <span class="nv-tick-key"><i></i>Reorder line</span>
            </div>
            <ul class="nv-stock">
                @foreach ($stockRows as $index => $row)
                    @php
                        $tip = number_format($row['stock']).' '.$row['unit'].' in stock · reorder at '.number_format($row['threshold'])
                            .' · '.number_format($row['used_this_month']).' used this month'
                            .($row['months_of_cover'] !== null ? ' · about '.rtrim(rtrim(number_format($row['months_of_cover'], 1), '0'), '.').' months of cover' : '');
                    @endphp
                    <li class="nv-stock-row {{ $index >= $stock['visible'] ? 'nv-extra' : '' }}"
                        data-tip-title="{{ $row['name'] }}"
                        data-tip="{{ $tip }}">
                        <span class="nv-stock-name">
                            <span class="nv-stock-text">{{ $row['name'] }}</span>
                            <span class="badge {{ $row['badge'] }}">{{ $row['label'] }}</span>
                        </span>
                        <span class="nv-stock-figure"><b class="tnum">{{ number_format($row['stock']) }}</b> {{ $row['unit'] }}</span>
                        <span class="nv-bullet" aria-hidden="true">
                            @if ($row['stock'] > 0)
                                <span class="nv-bullet-fill is-{{ $row['state'] === 'out' ? 'low' : $row['state'] }}" style="width: {{ $row['fill'] }}%"></span>
                            @endif
                            @if ($row['tick'] !== null)
                                <span class="nv-bullet-tick" style="left: {{ $row['tick'] }}%"></span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
            @if ($extra > 0)
                <button type="button" class="nv-more" data-stock-toggle aria-expanded="false">
                    <span class="nv-more-closed">Show all {{ number_format(count($stockRows)) }} medicines</span>
                    <span class="nv-more-open">Show fewer</span>
                </button>
            @endif
        </div>

        <div class="nv-table table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Medicine</th>
                        <th class="num">Stock</th>
                        <th class="num">Reorder line</th>
                        <th class="num">Used this month</th>
                        <th class="num">Months of cover</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($stockRows as $row)
                        <tr>
                            <td>{{ $row['name'] }}</td>
                            <td class="num">{{ number_format($row['stock']) }} {{ $row['unit'] }}</td>
                            <td class="num">{{ number_format($row['threshold']) }}</td>
                            <td class="num">{{ number_format($row['used_this_month']) }}</td>
                            <td class="num">{{ $row['months_of_cover'] !== null ? rtrim(rtrim(number_format($row['months_of_cover'], 1), '0'), '.') : '—' }}</td>
                            <td><span class="badge {{ $row['badge'] }}">{{ $row['label'] }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
