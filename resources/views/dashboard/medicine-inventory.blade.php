<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Medicine Inventory - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <script>document.documentElement.classList.add('js');</script>
    {{-- LUSOG order: theme, then this page's sheet, then the nurse rail. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
    @php $pageCssPath = resource_path('css/school-nurse-medicine-inventory.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
    <style>{!! file_get_contents(resource_path('css/nurse-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.clinic-rail', ['active' => 'inventory'])

<div class="main">
    @php
        $schoolName = session('active_school_name', 'No school assigned');
        $schoolYear = \App\Models\StudentHealthRecord::currentSchoolYear();
    @endphp

    <header class="topbar">
        <div class="topbar-bc"><span>{{ session('active_role') === 'clinic_staff' ? 'Clinic Staff' : 'School Nurse' }}</span><span class="bc-sep">&rsaquo;</span><span>Medicine Inventory</span></div>

        @include('partials.nurse-learner-search')
        <div class="topbar-spacer"></div>
        <div class="topbar-chip"><span class="dot"></span>{{ $schoolName }} &middot; SY {{ $schoolYear }}</div>
        @include('partials.live-clock')
    </header>

    <div class="content">
        @if (session('success'))
            <div class="flash ok">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="flash err">{{ session('error') }}</div>
        @endif

        <div class="page-header">
            <div class="card-head" style="margin-bottom:0">
                <div>
                    <div class="page-eyebrow">Inventory</div>
                    <h1 class="page-title">Medicine <span>Inventory</span></h1>
                    <p class="page-sub">Track current stock against reorder thresholds and add medicines quickly.</p>
                </div>
                <a href="{{ route('medicine-inventory.create') }}" class="btn btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Add Medicine
                </a>
            </div>
        </div>

        <div class="kpi-grid {{ $supports_expiry ? '' : 'cols-3' }}">
            <div class="card kpi accent-brand">
                <div class="kpi-top">
                    <div class="kpi-label">Total Medicines</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="2" width="18" height="20" rx="2"/><path d="M9 2v4h6V2"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($stats['total']) }}</div>
                <div class="kpi-hint">Items on the shelf list</div>
            </div>

            <div class="card kpi accent-success">
                <div class="kpi-top">
                    <div class="kpi-label">Above Threshold</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9 12l2 2 4-4"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($stats['good']) }}</div>
                <div class="kpi-hint">Comfortably stocked</div>
            </div>

            <div class="card kpi accent-amber">
                <div class="kpi-top">
                    <div class="kpi-label">Low Stock</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($stats['low']) }}</div>
                <div class="kpi-hint">At or below reorder point</div>
            </div>

            @if ($supports_expiry)
                {{-- Expiry, judged per item off the earliest date on hand. Expired
                     stock is counted on the hint rather than folded into the
                     figure: a box already past its date is a different job
                     (pull it) from one about to (use or replace it). --}}
                <div class="card kpi {{ $stats['expired'] > 0 ? 'accent-danger' : 'accent-orange' }}">
                    <div class="kpi-top">
                        <div class="kpi-label">Expiring Soon</div>
                        <div class="kpi-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M9 16l2 2 4-4"/></svg>
                        </div>
                    </div>
                    <div class="kpi-value">{{ number_format($stats['expiring']) }}</div>
                    <div class="kpi-hint">
                        Within {{ $expiry_warning_days }} days
                        @if ($stats['expired'] > 0)
                            &middot; <strong>{{ $stats['expired'] }} already expired</strong>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        <section class="card forecast-card">
            <div class="forecast-grid">
                <div class="forecast-main">
                    <div class="page-eyebrow">Predictive Reorder Module</div>
                    @if ($prediction['has_history'])
                        <h2 class="forecast-title">{{ $prediction['medicine_name'] }} is going out at about {{ $prediction['average'] }} {{ $prediction['unit'] }} a month.</h2>
                        {{-- Built as a string rather than with inline @if: a directive
                             that directly follows a word is not parsed as one, and the
                             orphaned @endif then closes the block around it. --}}
                        @php
                            $forecastNote = 'Read from recorded dispensing over the last '.$usage_months.' months';
                            $forecastNote .= $prediction['peak_month'] ? ', highest in '.$prediction['peak_month'].'.' : '.';
                            $forecastNote .= " The target applies a 20% safety buffer to the recent rate and never falls below this medicine's own reorder line.";

                            if ($prediction['months_of_cover'] !== null) {
                                $forecastNote .= ' At that rate the stock on hand lasts about '.$prediction['months_of_cover'].' months.';
                            }
                        @endphp
                        <p class="forecast-sub">{{ $forecastNote }}</p>
                    @else
                        {{-- No dispensing on record means no rate, and no rate means no
                             recommendation. Saying so is the honest answer; a target
                             invented from an empty log would read as evidence. --}}
                        <h2 class="forecast-title">Not enough dispensing history yet.</h2>
                        <p class="forecast-sub">
                            No medicine has been dispensed in the last {{ $usage_months }} months, so there is no consumption rate to forecast from.
                            Record a medicine on a consultation and this panel will fill in.
                        </p>
                    @endif
                    <div class="fc-stats">
                        <div class="fc-stat">
                            <div class="fc-stat-label">Current Stock</div>
                            <div class="fc-stat-value">{{ $prediction['current_stock'] }} {{ $prediction['unit'] }}</div>
                        </div>
                        <div class="fc-stat">
                            <div class="fc-stat-label">Used This Month</div>
                            <div class="fc-stat-value">{{ $prediction['this_month'] }} {{ $prediction['unit'] }}</div>
                        </div>
                        <div class="fc-stat">
                            <div class="fc-stat-label">Target For {{ $prediction['next_month'] }}</div>
                            <div class="fc-stat-value">{{ $prediction['has_history'] ? $prediction['recommended_doses'].' '.$prediction['unit'] : '—' }}</div>
                        </div>
                        <div class="fc-stat">
                            <div class="fc-stat-label">Recommended Order</div>
                            <div class="fc-stat-value {{ $prediction['has_history'] && $prediction['recommended_order'] > 0 ? 'is-order' : '' }}">{{ $prediction['has_history'] ? $prediction['recommended_order'].' '.$prediction['unit'] : '—' }}</div>
                        </div>
                    </div>
                </div>

                <div class="forecast-graph">
                    <div class="graph-title">Monthly Usage Report ({{ $prediction['medicine_name'] }})</div>
                    @php
                        $usageSeries = collect($prediction['monthly_usage'])->values();
                        $chartWidth = 560;
                        $chartHeight = 190;
                        $padX = 36;
                        $padY = 16;
                        $plotWidth = $chartWidth - ($padX * 2);
                        $plotHeight = $chartHeight - ($padY * 2);
                        $maxUsage = max(1, (int) $prediction['max_usage']);
                        $axisStep = max(10, (int) ceil(($maxUsage / 4) / 10) * 10);
                        $axisMax = $axisStep * 4;
                        $pointCount = max(1, $usageSeries->count());

                        $plotPoints = $usageSeries->map(function ($point, $index) use ($pointCount, $padX, $plotWidth, $padY, $plotHeight, $axisMax) {
                            $x = $padX + ($pointCount === 1 ? $plotWidth / 2 : ($index / ($pointCount - 1)) * $plotWidth);
                            $y = $padY + $plotHeight - (((int) $point['used'] / $axisMax) * $plotHeight);

                            return [
                                'month' => $point['month'],
                                'used' => (int) $point['used'],
                                'x' => round($x, 2),
                                'y' => round($y, 2),
                            ];
                        });

                        $linePoints = $plotPoints->map(fn ($p) => $p['x'] . ',' . $p['y'])->implode(' ');
                        $areaPoints = $linePoints . ' ' . ($padX + $plotWidth) . ',' . ($padY + $plotHeight) . ' ' . $padX . ',' . ($padY + $plotHeight);
                    @endphp
                    <div class="line-chart" role="img" aria-label="Monthly usage line graph for {{ $prediction['medicine_name'] }}">
                        <svg viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" aria-hidden="true" focusable="false">
                            @for ($i = 0; $i <= 4; $i++)
                                @php
                                    $y = $padY + (($plotHeight / 4) * $i);
                                    $label = $axisMax - ($axisStep * $i);
                                @endphp
                                <line x1="{{ $padX }}" y1="{{ $y }}" x2="{{ $padX + $plotWidth }}" y2="{{ $y }}" class="grid-line" />
                                <text x="8" y="{{ $y + 3 }}" class="axis-text">{{ $label }}</text>
                            @endfor

                            <polygon points="{{ $areaPoints }}" class="usage-area"></polygon>
                            <polyline points="{{ $linePoints }}" class="usage-line"></polyline>

                            @foreach($plotPoints as $point)
                                <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="5" class="usage-point {{ $maxUsage > 0 && $point['used'] === $maxUsage ? 'peak' : '' }}"></circle>
                                <text x="{{ $point['x'] }}" y="{{ $point['y'] - 10 }}" text-anchor="middle" class="axis-text">{{ $point['used'] > 0 ? $point['used'] : '' }}</text>
                                <text x="{{ $point['x'] }}" y="{{ $chartHeight - 6 }}" text-anchor="middle" class="axis-text">{{ $point['month'] }}</text>
                            @endforeach
                        </svg>
                    </div>
                    <div class="graph-note">
                        @if ($prediction['has_history'])
                            Each point is that month's dispensed total, recorded on the consultations it was given at; the highest is marked. A month with no dispensing shows as zero, not as a gap.
                        @else
                            Nothing has been dispensed in this window, so every month reads zero.
                        @endif
                    </div>
                </div>
            </div>
        </section>

        <div class="section-title" style="margin-top:24px">Current Inventory</div>
        <div class="table-card">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th class="num">Stock</th>
                            <th class="num">Minimum</th>
                            {{-- How fast it is going, beside how much is left: a stock
                                 figure on its own does not say whether to reorder. --}}
                            <th class="num">Used This Month</th>
                            <th class="num">Monthly Avg</th>
                            <th class="num">Cover</th>
                            @if ($supports_expiry)
                                <th>Expiry</th>
                            @endif
                            <th>Status</th>
                            <th>Updated</th>
                            @if ($supports_receipts)
                                <th></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($medicines as $medicine)
                        @php
                            $isCritical = $medicine->stock_quantity === 0;
                            $isLow = $medicine->stock_quantity > 0 && $medicine->stock_quantity < $medicine->minimum_threshold;
                            // Expiry only matters for stock that exists; an empty
                            // shelf has nothing to go off.
                            $expiryStatus = $medicine->stock_quantity > 0 ? $medicine->expiryStatus() : null;
                            $daysToExpiry = $medicine->daysToExpiry();
                        @endphp
                        <tr>
                            <td><strong>{{ $medicine->name }}</strong></td>
                            <td class="num">{{ $medicine->stock_quantity }} {{ $medicine->unit }}</td>
                            <td class="num">{{ $medicine->minimum_threshold }} {{ $medicine->unit }}</td>
                            @php
                                $u = $usage[$medicine->id] ?? ['this_month' => 0, 'average' => 0.0, 'months_of_history' => 0];
                                // An em dash, not a zero: nothing dispensed and no history
                                // are different claims, and only one of them is a rate.
                                $cover = $u['average'] > 0
                                    ? round($medicine->stock_quantity / $u['average'], 1)
                                    : null;
                            @endphp
                            <td class="num">{{ $u['this_month'] }}</td>
                            <td class="num">{{ $u['months_of_history'] > 0 ? $u['average'] : '—' }}</td>
                            <td class="num">{{ $cover !== null ? $cover.' mo' : '—' }}</td>
                            @if ($supports_expiry)
                                {{-- The date, and how it stands. An item with no date on
                                     file reads as unknown — an em dash — never as fine. --}}
                                <td class="tnum inv-expiry">
                                    @if ($medicine->expiry_date)
                                        <span class="inv-expiry-date">{{ $medicine->expiry_date->format('d M Y') }}</span>
                                        @if ($expiryStatus === \App\Models\Medicine::EXPIRY_EXPIRED)
                                            <span class="badge badge-critical">Expired</span>
                                        @elseif ($expiryStatus === \App\Models\Medicine::EXPIRY_SOON)
                                            <span class="badge badge-risk">{{ $daysToExpiry }} {{ \Illuminate\Support\Str::plural('day', $daysToExpiry) }} left</span>
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                            @endif
                            <td>
                                @if ($isCritical)
                                    <span class="badge badge-critical">Out of Stock</span>
                                @elseif ($isLow)
                                    <span class="badge badge-monitor">Low Stock</span>
                                @else
                                    <span class="badge badge-normal">In Stock</span>
                                @endif
                            </td>
                            <td class="tnum">{{ $medicine->updated_at?->format('Y-m-d') ?? '—' }}</td>
                            @if ($supports_receipts)
                                <td class="inv-actions">
                                    <button type="button" class="btn btn-secondary btn-sm" data-receive-open
                                            data-medicine-id="{{ $medicine->id }}"
                                            data-medicine-name="{{ $medicine->name }}"
                                            data-medicine-unit="{{ $medicine->unit }}"
                                            data-medicine-stock="{{ $medicine->stock_quantity }}"
                                            data-receive-url="{{ route('medicine-inventory.receive', $medicine) }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                        Receive
                                    </button>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ 8 + (int) $supports_expiry + (int) $supports_receipts }}" class="table-empty">No medicine records yet. Use Add Medicine to create your first item.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@if ($supports_receipts)
    {{-- ── Receive stock ──
         One dialog for every row: the button carries the item, the dialog
         posts to that item's own receive route. A delivery is the logbook's
         "in" column — quantity, expiry on the box, where it came from — and
         the server writes the receipt and the increment in one transaction. --}}
    <div class="inv-modal-backdrop" id="receiveBackdrop" hidden>
        <div class="inv-modal" role="dialog" aria-modal="true" aria-labelledby="receiveTitle">
            <form method="POST" id="receiveForm" action="">
                @csrf
                <div class="inv-modal-head">
                    <div>
                        <div class="page-eyebrow">Receive Stock</div>
                        <h2 id="receiveTitle" class="inv-modal-title">Medicine</h2>
                        <p class="inv-modal-sub" id="receiveSub"></p>
                    </div>
                    <button type="button" class="inv-modal-close" data-receive-close aria-label="Close">&times;</button>
                </div>
                <div class="inv-modal-body">
                    <div class="inv-form-grid">
                        <div class="field">
                            <label for="receiveQuantity">Quantity received</label>
                            <input class="input" id="receiveQuantity" name="quantity" type="number" min="1" max="100000" required>
                        </div>
                        <div class="field">
                            <label for="receiveExpiry">Expiry date on the box</label>
                            {{-- Already-expired stock is refused: the count is a count
                                 of usable medicine. --}}
                            <input class="input" id="receiveExpiry" name="expiry_date" type="date" min="{{ now()->addDay()->toDateString() }}">
                        </div>
                        <div class="field">
                            <label for="receiveDate">Date received</label>
                            <input class="input" id="receiveDate" name="received_at" type="date" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}">
                        </div>
                        <div class="field">
                            <label for="receiveSource">Source</label>
                            <input class="input" id="receiveSource" name="source" type="text" maxlength="120" placeholder="e.g. Division delivery, donation, purchase" autocomplete="off">
                        </div>
                        <div class="field full">
                            <label for="receiveNotes">Notes</label>
                            <input class="input" id="receiveNotes" name="notes" type="text" maxlength="255" placeholder="Optional — lot number, delivery reference" autocomplete="off">
                        </div>
                    </div>
                    @if ($errors->has('quantity') || $errors->has('expiry_date') || $errors->has('received_at'))
                        <div class="inv-form-error">{{ $errors->first('quantity') ?: ($errors->first('expiry_date') ?: $errors->first('received_at')) }}</div>
                    @endif
                </div>
                <div class="inv-modal-foot">
                    <button type="button" class="btn btn-secondary" data-receive-close>Cancel</button>
                    <button type="submit" class="btn btn-primary">Add to Stock</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    (() => {
        const backdrop = document.getElementById('receiveBackdrop');
        const form = document.getElementById('receiveForm');
        const title = document.getElementById('receiveTitle');
        const sub = document.getElementById('receiveSub');
        const quantity = document.getElementById('receiveQuantity');
        if (!backdrop || !form) return;

        const close = () => {
            backdrop.hidden = true;
            document.body.classList.remove('inv-modal-open');
        };

        document.querySelectorAll('[data-receive-open]').forEach((button) => {
            button.addEventListener('click', () => {
                form.action = button.dataset.receiveUrl || '';
                title.textContent = button.dataset.medicineName || 'Medicine';
                sub.textContent = 'On hand: ' + (button.dataset.medicineStock || '0') + ' ' + (button.dataset.medicineUnit || '');
                form.reset();
                backdrop.hidden = false;
                document.body.classList.add('inv-modal-open');
                quantity?.focus();
            });
        });

        backdrop.querySelectorAll('[data-receive-close]').forEach((button) => button.addEventListener('click', close));
        backdrop.addEventListener('click', (event) => { if (event.target === backdrop) close(); });
        document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !backdrop.hidden) close(); });
    })();
    </script>
@endif

@include('partials.nurse-page-transition')
</body>
</html>
