{{--
    The options of the "Medicine given" field, shared by the New Consultation
    dialog and the standalone consultation page so the shelf is listed one way.

    Every medicine in stock, paracetamol first (DispensingRights::ordered). An
    option is disabled when the session's role may not give it — Clinic Staff
    and the Clinic Teacher are restricted to paracetamol — or when nothing of it
    is left within its expiry date. Each option carries the lots a draw would
    come out of, soonest expiry first and expired stock left out
    (App\Support\MedicineBatches), so the page can say which box is next.

    A disabled option is a hint, not the rule: ConsultationController asks
    DispensingRights again, against the row it locked, before it writes.

    Expects: $role (the session's role). Renders <option> elements only.
--}}
@php
    $cmoRole = (string) ($role ?? session('active_role', ''));

    $cmoMedicines = (\App\Support\DispensingRights::mayDispense($cmoRole) && \App\Support\SchemaCache::hasTable('medicines'))
        ? \App\Support\DispensingRights::ordered(\App\Models\Medicine::query()
            ->when(session('active_institution_id'), fn ($q, $id) => $q->where('institution_id', $id))
            ->where('stock_quantity', '>', 0)
            ->get())
        : collect();

    $cmoToday = now()->startOfDay();
    $cmoShelves = collect(\App\Support\MedicineBatches::shelves($cmoMedicines))
        ->map(fn (array $lots) => \App\Support\MedicineBatches::summary($lots, $cmoToday));
@endphp
<option value="">None</option>
@foreach ($cmoMedicines as $medicine)
    @php
        $cmoShelf = $cmoShelves->get($medicine->id, ['usable' => 0, 'next' => null, 'lots' => []]);
        $cmoUsable = (int) $cmoShelf['usable'];
        $cmoAllowed = \App\Support\DispensingRights::allows($cmoRole, $medicine->name);
        $cmoNext = $cmoShelf['next'];
        $cmoLots = collect($cmoShelf['lots'])
            ->filter(fn (array $lot) => \App\Support\MedicineBatches::isUsable($lot, $cmoToday))
            ->map(fn (array $lot) => [
                'expiry' => $lot['expiry'] !== null
                    ? 'exp '.$lot['expiry']->format('d M Y')
                    : \App\Support\MedicineBatches::expiryLabel(null),
                'remaining' => (int) $lot['remaining'],
            ])
            ->values()
            ->all();
    @endphp
    <option value="{{ $medicine->id }}"
            @selected($cmoAllowed && $cmoUsable > 0 && (string) old('medicine_id') === (string) $medicine->id)
            @disabled(! $cmoAllowed || $cmoUsable === 0)
            data-name="{{ $medicine->name }}"
            data-unit="{{ $medicine->unit }}"
            data-usable="{{ $cmoUsable }}"
            data-lots='@json($cmoLots, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)'>
        {{ $medicine->name }} ({{ $cmoUsable }} {{ $medicine->unit }} left{{ $cmoNext && $cmoNext['expiry'] ? ', exp '.$cmoNext['expiry']->format('d M Y') : '' }})@if (! $cmoAllowed) &mdash; School Nurse only @elseif ($cmoUsable === 0) &mdash; expired stock only @endif
    </option>
@endforeach
