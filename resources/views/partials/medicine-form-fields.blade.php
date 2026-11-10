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
    // What this school already stocks, compared as the server compares it
    // (MedicineCatalogue::comparableName): an item already on the shelf is
    // received into, never added a second time.
    $stocked = array_flip($stockedNames ?? []);
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
                        @php $inStock = isset($stocked[\App\Support\MedicineCatalogue::comparableName($item)]); @endphp
                        <option value="{{ $item }}" @selected(! $inStock && old('catalogue_name') === $item) @disabled($inStock)
                                @if (\App\Support\MedicineCatalogue::isSupply($item)) data-supply @endif
                                @if ($generic = \App\Support\MedicineCatalogue::genericFor($item)) data-generic="{{ $generic }}" @endif
                                @if ($hint = \App\Support\MedicineCatalogue::labelHint($item)) data-generic-hint="{{ $hint }}" @endif>{{ $item }}{{ $inStock ? ' (in inventory)' : '' }}</option>
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

    {{-- Medicine or supply (testers' report #16): a supply — gauze, bandages,
         cotton, tape, gloves, masks — has no generic name and is never looked
         up in RxNorm. Set from the item chosen above; the nurse may change it. --}}
    @if (\App\Models\Medicine::supportsItemType())
        <div class="field full">
            <label for="item_type">Item type</label>
            <select class="select" id="item_type" name="item_type">
                <option value="{{ \App\Models\Medicine::TYPE_MEDICINE }}" @selected(old('item_type', \App\Models\Medicine::TYPE_MEDICINE) === \App\Models\Medicine::TYPE_MEDICINE)>Medicine</option>
                <option value="{{ \App\Models\Medicine::TYPE_SUPPLY }}" @selected(old('item_type') === \App\Models\Medicine::TYPE_SUPPLY)>Supply</option>
            </select>
            @error('item_type') <div class="err">{{ $message }}</div> @enderror
        </div>
    @endif

    {{-- What RxNorm is asked for, for the allergy check on every dispense
         (App\Support\RxNormService). Filled from the name chosen above until
         the nurse types their own. --}}
    @if (\App\Support\RxNormService::supported())
        <div class="field full" id="genericNameField">
            <label for="generic_name">Generic Name</label>
            <input class="input" id="generic_name" name="generic_name" type="text" maxlength="100" required
                   pattern="[A-Za-z0-9 .\/\-]{2,100}" value="{{ old('generic_name') }}" placeholder="e.g. Mefenamic Acid" autocomplete="off">
            @error('generic_name') <div class="err">{{ $message }}</div> @enderror
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
    // "Paracetamol 500mg tablet" is looked up as "Paracetamol": the name
    // without its strength or form, or the generic the catalogue gives it
    // (data-generic). A generic the nurse typed is never overwritten. A
    // supply (data-supply) is not a drug and has no generic to ask for, so
    // the field leaves the form while one is chosen.
    const select = document.getElementById('catalogue_name');
    const generic = document.getElementById('generic_name');
    const field = document.getElementById('genericNameField');
    const custom = document.getElementById('custom_name');
    const other = @json(\App\Support\MedicineCatalogue::OTHER);
    // MedicineCatalogue::isSupply, for a name typed outside the list.
    const supplyWords = @json(\App\Support\MedicineCatalogue::SUPPLY_WORDS);
    if (!select || !generic) return;

    let automatic = generic.value === '';
    generic.addEventListener('input', () => { automatic = generic.value === ''; });

    const typedSupply = () => {
        const words = ' ' + (custom?.value || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ') + ' ';
        return supplyWords.some((word) => words.includes(' ' + word + ' '));
    };

    // The Item type select (testers' report #16) is what decides; choosing an
    // item, or typing one outside the list, sets it to what the name reads as,
    // and the nurse may change it.
    const type = document.getElementById('item_type');
    const readType = () => {
        const option = select.options[select.selectedIndex];
        return Boolean(option && option.hasAttribute('data-supply')) || (select.value === other && typedSupply())
            ? 'supply' : 'medicine';
    };

    const sync = () => {
        const option = select.options[select.selectedIndex];
        const supply = type ? type.value === 'supply' : readType() === 'supply';
        if (field) field.hidden = supply;
        generic.required = !supply;
        generic.disabled = supply;
        return { option, supply };
    };

    type?.addEventListener('change', sync);
    custom?.addEventListener('input', () => { if (type) type.value = readType(); });

    const placeholder = generic.placeholder;

    select.addEventListener('change', () => {
        if (type) type.value = readType();
        const { option, supply } = sync();
        // An item named by its class ("Antifungal cream") has whatever generic
        // its product has: the field is left empty with where to read it,
        // never filled with a guess (testers' report #17).
        const hint = option?.dataset.genericHint || '';
        generic.placeholder = hint ? 'e.g. ' + hint : placeholder;
        if (!automatic || supply) return;
        if (hint) { generic.value = ''; return; }
        generic.value = option && option.value !== '' && option.closest('optgroup')
            ? (option.dataset.generic || option.value.split(/\s\d|\s\(/)[0]
                .replace(/\s+(tablets?|capsules?|syrup|suspension|solution|cream|lotion|sachets?|drops)\b.*$/i, '')
                .trim())
            : '';
    });
    custom?.addEventListener('input', sync);
    sync();
})();

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
