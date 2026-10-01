{{--
    The fields that make one medicine record — one source, two hosts.

    The Medicine Inventory page opens this in a dialog (so the nurse never
    leaves the list they are reading), and `dashboard/medicine-create` is the
    same fields on their own page, which is where the button goes without
    JavaScript. Both post to `medicine-inventory.store`, so there is one write
    path and the two cannot ask for different things.

    `$gridClass` is the host's own grid — the inventory page rules its dialog
    forms with `.inv-form-grid`, the standalone page with `.grid`.
--}}
@php
    $gridClass = $gridClass ?? 'grid';
    $offCatalogue = \App\Support\MedicineCatalogue::allowsOffCatalogue();
@endphp

<div class="{{ $gridClass }}">
    {{-- Picked from the DepEd-allowed list rather than typed, so one school's
         "Paracetamol 500mg" and another's "paracetamol 500 mg" do not become
         two items nobody can total. Off-list stock is still possible — below. --}}
    <div class="field full">
        <label for="catalogue_name">Medicine Name</label>
        <select class="select" id="catalogue_name" name="catalogue_name" required>
            <option value="">Select a medicine…</option>
            @foreach (\App\Support\MedicineCatalogue::grouped() as $group => $items)
                <optgroup label="{{ $group }}">
                    @foreach ($items as $item)
                        <option value="{{ $item }}" @selected(old('catalogue_name') === $item)>{{ $item }}</option>
                    @endforeach
                </optgroup>
            @endforeach
            @if ($offCatalogue)
                {{-- Last, deliberately: the list is the default and this is the
                     exception. --}}
                <option value="{{ \App\Support\MedicineCatalogue::OTHER }}" @selected(old('catalogue_name') === \App\Support\MedicineCatalogue::OTHER)>
                    Other — not on the DepEd list
                </option>
            @endif
        </select>
        @error('name') <div class="err">{{ $message }}</div> @enderror
    </div>

    @if ($offCatalogue)
        {{-- Revealed only by the "Other" choice. The nurse dispenses beyond the
             list when it is clinically necessary; recording that is better than
             a stock list that quietly disagrees with what is on the shelf. It is
             marked as off-list and asks why, so the school can answer for it. --}}
        <div class="field full" id="offCatalogueBlock" hidden>
            <label for="custom_name">Medicine name (not on the list)</label>
            <input class="input" id="custom_name" name="custom_name" type="text" maxlength="255"
                   value="{{ old('custom_name') }}" placeholder="e.g. Mefenamic acid 500mg tablet">
            <label for="off_catalogue_reason" style="margin-top:10px;">Why it is stocked</label>
            <input class="input" id="off_catalogue_reason" name="off_catalogue_reason" type="text" maxlength="255"
                   value="{{ old('off_catalogue_reason') }}" placeholder="e.g. Prescribed for dysmenorrhea cases referred by the physician">
            @error('off_catalogue_reason') <div class="err">{{ $message }}</div> @enderror
            <div class="muted" style="font-size:.72rem;margin-top:6px;">
                This will be recorded as stock held outside the DepEd list.
            </div>
        </div>
    @endif

    <div class="field">
        <label for="stock_quantity">Current Stock</label>
        <input class="input" id="stock_quantity" name="stock_quantity" type="number" min="0" value="{{ old('stock_quantity', 0) }}" required>
        @error('stock_quantity') <div class="err">{{ $message }}</div> @enderror
    </div>
    <div class="field">
        <label for="minimum_threshold">Minimum Threshold</label>
        <input class="input" id="minimum_threshold" name="minimum_threshold" type="number" min="0" value="{{ old('minimum_threshold', 20) }}" required>
        @error('minimum_threshold') <div class="err">{{ $message }}</div> @enderror
    </div>
    <div class="field">
        <label for="unit">Unit</label>
        <input class="input" id="unit" name="unit" type="text" value="{{ old('unit', 'pcs') }}" required>
        @error('unit') <div class="err">{{ $message }}</div> @enderror
    </div>
    @if (\App\Models\Medicine::supportsExpiry())
        <div class="field">
            <label for="expiry_date">Expiry Date</label>
            {{-- The earliest expiry of the stock being entered. Optional — an item
                 created empty has nothing to expire yet; a delivery later carries
                 its own date. --}}
            <input class="input" id="expiry_date" name="expiry_date" type="date" value="{{ old('expiry_date') }}">
            @error('expiry_date') <div class="err">{{ $message }}</div> @enderror
        </div>
    @endif
    <div class="field">
        <label for="notes">Notes</label>
        <input class="input" id="notes" name="notes" type="text" value="{{ old('notes') }}" placeholder="Optional">
        @error('notes') <div class="err">{{ $message }}</div> @enderror
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
