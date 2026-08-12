<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Dispensing Log - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <script>document.documentElement.classList.add('js');</script>
    {{-- LUSOG order: theme, then this page's sheet, then the nurse rail. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-theme.css')) !!}</style>
    @php $pageCssPath = resource_path('css/school-nurse-dispensing-log.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
    <style>{!! file_get_contents(resource_path('css/nurse-sidebar.css')) !!}</style>
</head>
<body>
@include('partials.nurse-lusog-sidebar', ['active' => 'dispensing'])

<div class="main">
    @php
        $schoolName = session('active_school_name', 'No school assigned');
        $schoolYear = \App\Models\StudentHealthRecord::currentSchoolYear();
    @endphp

    <header class="topbar">
        <div class="topbar-bc"><span>School Nurse</span><span class="bc-sep">&rsaquo;</span><span>Dispensing Log</span></div>
        <div class="topbar-spacer"></div>
        <div class="topbar-chip"><span class="dot"></span>{{ $schoolName }} &middot; SY {{ $schoolYear }}</div>
        @include('partials.live-clock')
    </header>

    <div class="content">
        @if (session('success'))
            <div class="flash ok" role="status">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="flash err" role="alert">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="flash err" role="alert">{{ $errors->first() }}</div>
        @endif

        <div class="page-header">
            <div class="page-eyebrow">Inventory</div>
            <h1 class="page-title">Dispensing <span>Log</span></h1>
            <p class="page-sub">Every issue of medicine from the clinic stock. Recording a dispense here is what draws the inventory down — nothing else does.</p>
            <div class="role-note">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <span>This module is the School Nurse's alone. Clinic Staff can view stock levels on Medicine Inventory but cannot dispense.</span>
            </div>
        </div>

        <div class="kpi-grid">
            <div class="card kpi accent-brand">
                <div class="kpi-top">
                    <div class="kpi-label">Dispensed Today</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($stats['today']) }}</div>
                <div class="kpi-hint">{{ number_format($stats['today_units']) }} {{ Str::plural('unit', $stats['today_units']) }} issued</div>
            </div>

            <div class="card kpi accent-info">
                <div class="kpi-top">
                    <div class="kpi-label">This Month</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($stats['month']) }}</div>
                <div class="kpi-hint">{{ number_format($stats['month_units']) }} {{ Str::plural('unit', $stats['month_units']) }} issued</div>
            </div>

            <div class="card kpi accent-success">
                <div class="kpi-top">
                    <div class="kpi-label">Learners Served</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($stats['learners']) }}</div>
                <div class="kpi-hint">Distinct LRNs in this log</div>
            </div>

            <div class="card kpi accent-amber">
                <div class="kpi-top">
                    <div class="kpi-label">Medicines Stocked</div>
                    <div class="kpi-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="2" width="18" height="20" rx="2"/><path d="M9 2v4h6V2"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
                    </div>
                </div>
                <div class="kpi-value">{{ number_format($medicines->count()) }}</div>
                <div class="kpi-hint">Available to dispense</div>
            </div>
        </div>

        <section class="card dispense-form-card">
            <div class="card-head" style="margin-bottom:0">
                <div>
                    <div class="card-title">Record a Dispense</div>
                    <div class="card-sub">Stock is drawn down the moment this is saved.</div>
                </div>
            </div>

            @if ($medicines->isEmpty())
                <div class="empty-panel" style="margin-top:14px">
                    No medicines on the shelf list yet.
                    <a href="{{ route('medicine-inventory.create') }}">Add one first</a>.
                </div>
            @else
                <form method="POST" action="{{ route('dispensing-log.store') }}">
                    @csrf
                    <div class="dispense-grid">
                        <div class="field">
                            <label class="field-label" for="medicine_id">Medicine</label>
                            <select class="select" id="medicine_id" name="medicine_id" required>
                                @foreach ($medicines as $medicine)
                                    <option value="{{ $medicine->id }}"
                                            data-stock="{{ $medicine->stock_quantity }}"
                                            data-unit="{{ $medicine->unit }}"
                                            @selected(old('medicine_id') == $medicine->id)>
                                        {{ $medicine->name }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="stock-hint" id="stockHint"></div>
                        </div>

                        <div class="field">
                            <label class="field-label" for="student_name">Learner</label>
                            <input class="input" type="text" id="student_name" name="student_name"
                                   value="{{ old('student_name') }}" required maxlength="255"
                                   placeholder="Surname, First name" autocomplete="off">
                        </div>

                        <div class="field">
                            <label class="field-label" for="quantity">Quantity</label>
                            <input class="input" type="number" id="quantity" name="quantity"
                                   value="{{ old('quantity', 1) }}" required min="1" step="1">
                        </div>

                        <div class="field">
                            <label class="field-label" for="reason">Reason <span class="muted" style="text-transform:none;letter-spacing:0">(optional)</span></label>
                            <input class="input" type="text" id="reason" name="reason"
                                   value="{{ old('reason') }}" maxlength="500"
                                   placeholder="e.g. headache after PE" autocomplete="off">
                        </div>

                        <div class="field field-submit">
                            <button type="submit" class="btn btn-primary">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                Record
                            </button>
                        </div>
                    </div>

                    <div style="margin-top:12px;max-width:320px">
                        <label class="field-label" for="student_lrn">LRN <span class="muted" style="text-transform:none;letter-spacing:0">(optional, links this to the learner's record)</span></label>
                        <input class="input" type="text" id="student_lrn" name="student_lrn"
                               value="{{ old('student_lrn') }}" maxlength="32" autocomplete="off">
                    </div>
                </form>
            @endif
        </section>

        <div class="toolbar" style="margin-top:24px">
            <div class="section-title compact" style="margin-bottom:0;padding-bottom:9px">Recent Dispensing</div>
            <div class="spacer"></div>
            <div class="toolbar-count">{{ $dispenses->count() }} {{ Str::plural('record', $dispenses->count()) }}</div>
        </div>

        <div class="table-card">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Date &amp; Time</th>
                            <th>Learner</th>
                            <th>LRN</th>
                            <th>Medicine</th>
                            <th class="num">Qty</th>
                            <th>Reason</th>
                            <th>Dispensed By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($dispenses as $dispense)
                            <tr>
                                <td class="tnum">{{ $dispense->dispensed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td><strong>{{ $dispense->student_name ?: '—' }}</strong></td>
                                <td class="tnum">{{ $dispense->student_lrn ?: '—' }}</td>
                                <td>{{ $dispense->medicine?->name ?? '—' }}</td>
                                <td class="num">{{ $dispense->quantity }} {{ $dispense->medicine?->unit }}</td>
                                <td>{{ $dispense->reason ?: '—' }}</td>
                                <td>{{ $dispense->dispensed_by_name ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="table-empty">No medicine dispensed yet. Use the form above to record the first issue.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@include('partials.nurse-page-transition')

<script>
// Show what is left of the selected medicine, and cap the quantity field to
// it, so the nurse sees the limit before the server has to reject the form.
(() => {
    const picker = document.getElementById('medicine_id');
    const hint = document.getElementById('stockHint');
    const qty = document.getElementById('quantity');
    if (!picker || !hint) return;

    const update = () => {
        const option = picker.options[picker.selectedIndex];
        if (!option) return;

        const stock = parseInt(option.dataset.stock || '0', 10);
        const unit = option.dataset.unit || '';

        hint.textContent = stock + ' ' + unit + ' in stock';
        hint.classList.toggle('is-out', stock === 0);
        hint.classList.toggle('is-low', stock > 0 && stock <= 10);

        if (qty) {
            qty.max = String(Math.max(stock, 1));
            if (parseInt(qty.value || '1', 10) > stock) {
                qty.value = String(Math.max(stock, 1));
            }
        }
    };

    picker.addEventListener('change', update);
    update();
})();
</script>
</body>
</html>
