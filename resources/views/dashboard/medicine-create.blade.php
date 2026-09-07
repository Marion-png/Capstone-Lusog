<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/lusog-logo.png') }}">
    <title>Add Medicine - SIGLA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    @php $pageCssPath = resource_path('css/school-nurse-medicine-create.css'); @endphp
    @if (file_exists($pageCssPath))
        <style>{!! file_get_contents($pageCssPath) !!}</style>
    @endif
    {{-- One shared palette for pages not yet on lusog-theme.css. Loaded
         last so it overrides this page's own :root colours. --}}
    <style>{!! file_get_contents(resource_path('css/lusog-palette.css')) !!}</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="head">
            <div>
                <div class="title">Add Medicine</div>
                <div class="sub">Create a new medicine record for the school clinic inventory.</div>
            </div>
            <a href="{{ route('dashboard.medicine-inventory') }}" class="btn btn-ghost">Back to Inventory</a>
        </div>
        <div class="body">
            <form method="POST" action="{{ route('medicine-inventory.store') }}">
                @csrf
                <div class="grid">
                    {{-- Picked from the DepEd-allowed list rather than typed, so
                         one school's "Paracetamol 500mg" and another's
                         "paracetamol 500 mg" do not become two items nobody can
                         total. Off-list stock is still possible — see below. --}}
                    <div class="field full">
                        <label for="catalogue_name">Medicine Name</label>
                        <select id="catalogue_name" name="catalogue_name" required>
                            <option value="">Select a medicine…</option>
                            @foreach (\App\Support\MedicineCatalogue::grouped() as $group => $items)
                                <optgroup label="{{ $group }}">
                                    @foreach ($items as $item)
                                        <option value="{{ $item }}" @selected(old('catalogue_name') === $item)>{{ $item }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                            @if (\App\Support\MedicineCatalogue::allowsOffCatalogue())
                                {{-- Last, deliberately: the list is the default and
                                     this is the exception. --}}
                                <option value="{{ \App\Support\MedicineCatalogue::OTHER }}" @selected(old('catalogue_name') === \App\Support\MedicineCatalogue::OTHER)>
                                    Other — not on the DepEd list
                                </option>
                            @endif
                        </select>
                        @error('name') <div class="err">{{ $message }}</div> @enderror
                    </div>

                    @if (\App\Support\MedicineCatalogue::allowsOffCatalogue())
                        {{-- Revealed only by the "Other" choice. The nurse dispenses
                             beyond the list when it is clinically necessary; recording
                             that is better than a stock list that quietly disagrees
                             with what is on the shelf. It is marked as off-list and
                             asks why, so the school can answer for it. --}}
                        <div class="field full" id="offCatalogueBlock" hidden>
                            <label for="custom_name">Medicine name (not on the list)</label>
                            <input id="custom_name" name="custom_name" type="text" maxlength="255"
                                   value="{{ old('custom_name') }}" placeholder="e.g. Mefenamic acid 500mg tablet">
                            <label for="off_catalogue_reason" style="margin-top:10px;">Why it is stocked</label>
                            <input id="off_catalogue_reason" name="off_catalogue_reason" type="text" maxlength="255"
                                   value="{{ old('off_catalogue_reason') }}" placeholder="e.g. Prescribed for dysmenorrhea cases referred by the physician">
                            @error('off_catalogue_reason') <div class="err">{{ $message }}</div> @enderror
                            <div class="muted" style="font-size:.72rem;margin-top:6px;">
                                This will be recorded as stock held outside the DepEd list.
                            </div>
                        </div>
                    @endif
                    <div class="field">
                        <label for="stock_quantity">Current Stock</label>
                        <input id="stock_quantity" name="stock_quantity" type="number" min="0" value="{{ old('stock_quantity', 0) }}" required>
                        @error('stock_quantity') <div class="err">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="minimum_threshold">Minimum Threshold</label>
                        <input id="minimum_threshold" name="minimum_threshold" type="number" min="0" value="{{ old('minimum_threshold', 20) }}" required>
                        @error('minimum_threshold') <div class="err">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="unit">Unit</label>
                        <input id="unit" name="unit" type="text" value="{{ old('unit', 'pcs') }}" required>
                        @error('unit') <div class="err">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="notes">Notes</label>
                        <input id="notes" name="notes" type="text" value="{{ old('notes') }}" placeholder="Optional">
                        @error('notes') <div class="err">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="actions">
                    <button type="submit" class="btn btn-primary">Save Medicine</button>
                    <a href="{{ route('dashboard.medicine-inventory') }}" class="btn btn-ghost">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
(() => {
    const select = document.getElementById('catalogue_name');
    const block = document.getElementById('offCatalogueBlock');
    if (!select || !block) return;

    const custom = document.getElementById('custom_name');
    const other = @json(\App\Support\MedicineCatalogue::OTHER);

    const sync = () => {
        const isOther = select.value === other;
        block.hidden = !isOther;
        // Required only while it is on screen, or the browser blocks
        // submission on a field it cannot focus.
        if (custom) custom.required = isOther;
    };

    select.addEventListener('change', sync);
    sync();
})();
</script>
</body>
</html>
