<?php

namespace App\Support;

use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\MedicineReceipt;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which lot of a medicine is on the shelf, and which one is handed over next.
 *
 * An item's stock is held in lots (`medicine_batches`) — the stock it was
 * created with and each delivery after it — every lot with its own expiry
 * date, and the lots of an item always add up to its `stock_quantity`. Three
 * rules follow, and this class is the only place they are written down:
 *
 *  1. **First expiry, first out.** A dispense draws from the usable lot that
 *     expires soonest and moves to the next only when that one runs out. A
 *     lot with no date on file goes after every dated one — "unknown" is not
 *     a reason to hand it over first — and between two lots with the same
 *     date the older delivery goes first. The lots a dispense drew from are
 *     recorded on it, so what went to the child can always be told.
 *
 *  2. **Expired stock is never dispensed.** A lot is usable until the day it
 *     expires; on that day it is expired, exactly as Medicine::expiryStatus()
 *     judges an item. Expired stock stays counted until a stock writer takes
 *     it off the shelf (disposeExpired), because it is physically there.
 *
 *  3. **The item's own date is read off the lots.** `medicines.expiry_date` is
 *     the earliest usable lot's date, so it moves on when that lot is used up
 *     instead of reporting a box nobody holds any more. With nothing usable
 *     left it is the expired stock's date, so the item reads Expired.
 *
 * The stock figure is the authority when the two disagree. Stock that no lot
 * accounts for (an item created before lots existed, a level set outside this
 * class) is an untracked lot at the item's own date; lots that claim more than
 * the item holds no longer describe the shelf, and the shelf is read as one
 * untracked lot instead. A reading pass computes that without writing it;
 * every write path persists it first, inside its own transaction, with the
 * medicine row locked.
 */
class MedicineBatches
{
    public const OPENING_SOURCE = 'Opening stock';

    public const UNTRACKED_SOURCE = 'Stock on hand';

    /** Whether this database has lots yet. Without them stock is one figure, as before. */
    public static function supported(): bool
    {
        return SchemaCache::hasTable('medicine_batches') && SchemaCache::hasTable('medicine_dispense_batches');
    }

    // ── Reading ────────────────────────────────────────────────────────

    /**
     * The shelf of every medicine given, in one query, keyed by medicine id.
     *
     * @param  iterable<Medicine>  $medicines
     * @return array<int, list<array{batch_id: ?int, expiry: ?CarbonInterface, received_at: ?CarbonInterface, remaining: int, source: string}>>
     */
    public static function shelves(iterable $medicines): array
    {
        $medicines = collect($medicines)->filter(fn ($m) => $m instanceof Medicine)->values();

        if ($medicines->isEmpty()) {
            return [];
        }

        $stored = self::supported()
            ? MedicineBatch::query()
                ->whereIn('medicine_id', $medicines->pluck('id')->all())
                ->onShelf()
                ->get()
                ->groupBy('medicine_id')
            : collect();

        $shelves = [];
        foreach ($medicines as $medicine) {
            $shelves[(int) $medicine->id] = self::lots($medicine, $stored->get($medicine->id, collect()));
        }

        return $shelves;
    }

    /**
     * One item's lots, made to add up to its stock, in dispensing order.
     *
     * @param  iterable<MedicineBatch>  $batches  the item's stored lots
     * @return list<array{batch_id: ?int, expiry: ?CarbonInterface, received_at: ?CarbonInterface, remaining: int, source: string}>
     */
    public static function lots(Medicine $medicine, iterable $batches): array
    {
        $stock = max(0, (int) $medicine->stock_quantity);

        $lots = collect($batches)
            ->filter(fn ($batch) => $batch instanceof MedicineBatch && (int) $batch->quantity_remaining > 0)
            ->map(fn (MedicineBatch $batch): array => [
                'batch_id' => (int) $batch->id,
                'expiry' => $batch->expiry_date?->copy()->startOfDay(),
                'received_at' => $batch->received_at?->copy()->startOfDay(),
                'remaining' => (int) $batch->quantity_remaining,
                'source' => (string) ($batch->source ?? ''),
            ])
            ->values()
            ->all();

        $tracked = array_sum(array_column($lots, 'remaining'));

        // Lots claiming more than the item holds do not describe the shelf.
        if ($tracked > $stock) {
            $lots = [];
            $tracked = 0;
        }

        if ($tracked < $stock) {
            $lots[] = [
                'batch_id' => null,
                'expiry' => Medicine::supportsExpiry() ? $medicine->expiry_date?->copy()->startOfDay() : null,
                'received_at' => null,
                'remaining' => $stock - $tracked,
                'source' => self::UNTRACKED_SOURCE,
            ];
        }

        return self::ordered($lots);
    }

    /**
     * What a shelf comes to: how much may be handed over, how much is past
     * its date, the lot that goes next, and the date the item is judged by.
     *
     * @param  list<array{batch_id: ?int, expiry: ?CarbonInterface, received_at: ?CarbonInterface, remaining: int, source: string}>  $lots
     * @return array{usable: int, expired: int, next: ?array, item_expiry: ?CarbonInterface, lots: list<array>}
     */
    public static function summary(array $lots, ?CarbonInterface $today = null): array
    {
        $today = ($today ?? Carbon::now())->copy()->startOfDay();

        $usable = array_values(array_filter($lots, fn (array $lot) => self::isUsable($lot, $today)));
        $expired = array_values(array_filter($lots, fn (array $lot) => ! self::isUsable($lot, $today)));

        return [
            'usable' => array_sum(array_column($usable, 'remaining')),
            'expired' => array_sum(array_column($expired, 'remaining')),
            'next' => $usable[0] ?? null,
            'item_expiry' => self::itemExpiry($lots, $today),
            'lots' => $lots,
        ];
    }

    /**
     * Which lots a draw of this many would come out of, without taking it.
     *
     * @param  list<array{batch_id: ?int, expiry: ?CarbonInterface, received_at: ?CarbonInterface, remaining: int, source: string}>  $lots
     * @return list<array{batch_id: ?int, expiry: ?CarbonInterface, quantity: int}>|null null when the usable stock is short
     */
    public static function plan(array $lots, int $quantity, ?CarbonInterface $today = null): ?array
    {
        $today = ($today ?? Carbon::now())->copy()->startOfDay();
        $left = max(0, $quantity);
        $plan = [];

        foreach ($lots as $lot) {
            if ($left === 0) {
                break;
            }
            if (! self::isUsable($lot, $today)) {
                continue;
            }

            $take = min($left, (int) $lot['remaining']);
            $plan[] = ['batch_id' => $lot['batch_id'], 'expiry' => $lot['expiry'], 'quantity' => $take];
            $left -= $take;
        }

        return $left === 0 ? $plan : null;
    }

    /** Is this lot within its expiry date today? An undated lot is usable. */
    public static function isUsable(array $lot, CarbonInterface $today): bool
    {
        return (int) ($lot['remaining'] ?? 0) > 0
            && ($lot['expiry'] === null || $lot['expiry']->gt($today));
    }

    /**
     * The date an item is judged by: the earliest usable lot; unknown when the
     * usable stock carries no date; the expired stock's date only once nothing
     * usable is left; nothing at all for an empty shelf.
     *
     * @param  list<array{expiry: ?CarbonInterface, remaining: int}>  $lots
     */
    public static function itemExpiry(array $lots, CarbonInterface $today): ?CarbonInterface
    {
        $usableDated = null;
        $usableUndated = false;
        $expired = null;

        foreach ($lots as $lot) {
            if ((int) $lot['remaining'] <= 0) {
                continue;
            }

            if ($lot['expiry'] === null) {
                $usableUndated = true;
            } elseif ($lot['expiry']->gt($today)) {
                $usableDated = $usableDated === null || $lot['expiry']->lt($usableDated) ? $lot['expiry'] : $usableDated;
            } else {
                $expired = $expired === null || $lot['expiry']->lt($expired) ? $lot['expiry'] : $expired;
            }
        }

        if ($usableDated !== null) {
            return $usableDated;
        }

        return $usableUndated ? null : $expired;
    }

    // ── Writing (inside the caller's transaction, medicine row locked) ─

    /**
     * Make the stored lots add up to the item's stock, and return them in
     * dispensing order, locked.
     *
     * @return Collection<int, MedicineBatch>
     */
    public static function reconcile(Medicine $locked): Collection
    {
        $stored = MedicineBatch::query()
            ->where('medicine_id', $locked->id)
            ->onShelf()
            ->lockForUpdate()
            ->get();

        $lots = self::lots($locked, $stored);
        $kept = array_filter(array_column($lots, 'batch_id'));

        foreach ($stored as $batch) {
            if (! in_array((int) $batch->id, $kept, true)) {
                $batch->forceFill(['quantity_remaining' => 0])->save();
            }
        }

        foreach ($lots as $lot) {
            if ($lot['batch_id'] !== null) {
                continue;
            }

            MedicineBatch::create([
                'medicine_id' => $locked->id,
                'institution_id' => $locked->institution_id,
                'medicine_receipt_id' => null,
                'expiry_date' => $lot['expiry']?->toDateString(),
                'received_at' => null,
                'quantity_received' => $lot['remaining'],
                'quantity_remaining' => $lot['remaining'],
                'source' => $stored->isEmpty() && ! MedicineBatch::query()->where('medicine_id', $locked->id)->exists()
                    ? self::OPENING_SOURCE
                    : self::UNTRACKED_SOURCE,
            ]);
        }

        return self::sortedBatches(
            MedicineBatch::query()->where('medicine_id', $locked->id)->onShelf()->lockForUpdate()->get()
        );
    }

    /** The stock an item is created with is its first lot. */
    public static function open(Medicine $medicine, ?string $expiryDate): ?MedicineBatch
    {
        $quantity = max(0, (int) $medicine->stock_quantity);

        if ($quantity === 0) {
            return null;
        }

        return MedicineBatch::create([
            'medicine_id' => $medicine->id,
            'institution_id' => $medicine->institution_id,
            'medicine_receipt_id' => null,
            'expiry_date' => $expiryDate !== null && $expiryDate !== '' ? Carbon::parse($expiryDate)->toDateString() : null,
            'received_at' => Carbon::today()->toDateString(),
            'quantity_received' => $quantity,
            'quantity_remaining' => $quantity,
            'source' => self::OPENING_SOURCE,
        ]);
    }

    /**
     * A delivery is a new lot. Called before the stock is raised: the shelf is
     * brought into line with the figure as it stood, then the lot is added.
     */
    public static function receive(Medicine $locked, MedicineReceipt $receipt): MedicineBatch
    {
        self::reconcile($locked);

        return MedicineBatch::create([
            'medicine_id' => $locked->id,
            'institution_id' => $locked->institution_id,
            'medicine_receipt_id' => $receipt->id,
            'expiry_date' => $receipt->expiry_date?->toDateString(),
            'received_at' => $receipt->received_at?->toDateString(),
            'quantity_received' => (int) $receipt->quantity,
            'quantity_remaining' => (int) $receipt->quantity,
            'source' => $receipt->source,
        ]);
    }

    /**
     * Take this many out of the usable lots, soonest expiry first.
     *
     * @return list<array{batch: MedicineBatch, quantity: int}>|null null, and nothing taken, when the usable stock is short
     */
    public static function draw(Medicine $locked, int $quantity, ?CarbonInterface $today = null): ?array
    {
        $today = ($today ?? Carbon::now())->copy()->startOfDay();
        $batches = self::reconcile($locked);
        $plan = self::plan(self::lots($locked, $batches), $quantity, $today);

        if ($plan === null) {
            return null;
        }

        $byId = $batches->keyBy('id');
        $taken = [];

        foreach ($plan as $step) {
            $batch = $byId->get($step['batch_id']);
            $batch->forceFill(['quantity_remaining' => (int) $batch->quantity_remaining - $step['quantity']])->save();
            $taken[] = ['batch' => $batch, 'quantity' => $step['quantity']];
        }

        return $taken;
    }

    /**
     * Take every expired lot off the shelf. Returns how much was removed;
     * the caller lowers the stock by the same amount.
     */
    public static function disposeExpired(Medicine $locked, string $staffName, string $staffRole, ?CarbonInterface $today = null): int
    {
        $today = ($today ?? Carbon::now())->copy()->startOfDay();
        $removed = 0;

        foreach (self::reconcile($locked) as $batch) {
            $lot = ['remaining' => (int) $batch->quantity_remaining, 'expiry' => $batch->expiry_date?->copy()->startOfDay()];

            if (self::isUsable($lot, $today)) {
                continue;
            }

            $removed += $lot['remaining'];
            $batch->forceFill([
                'quantity_disposed' => (int) $batch->quantity_disposed + $lot['remaining'],
                'quantity_remaining' => 0,
                'disposed_at' => Carbon::now(),
                'disposed_by_name' => $staffName,
                'disposed_by_role' => $staffRole,
            ])->save();
        }

        return $removed;
    }

    /**
     * Re-read the item's own date off its stored lots once the stock has
     * moved. Sets the attribute; the caller saves the item.
     */
    public static function refreshExpiry(Medicine $locked, ?CarbonInterface $today = null): void
    {
        if (! Medicine::supportsExpiry()) {
            return;
        }

        $today = ($today ?? Carbon::now())->copy()->startOfDay();
        $stored = MedicineBatch::query()->where('medicine_id', $locked->id)->onShelf()->get();
        $expiry = self::itemExpiry(self::lots($locked, $stored), $today);

        $locked->expiry_date = $expiry?->toDateString();
    }

    // ── Presentation ───────────────────────────────────────────────────

    /** "31 Jan 2027", or "No expiry date" for an undated lot. */
    public static function expiryLabel(?CarbonInterface $expiry): string
    {
        return $expiry !== null ? $expiry->format('d M Y') : 'No expiry date';
    }

    // ── Ordering ───────────────────────────────────────────────────────

    /**
     * Dispensing order: soonest expiry first, undated after every dated lot,
     * then the older arrival, then the older row (untracked stock first —
     * it was on the shelf before any recorded delivery).
     *
     * @param  list<array<string, mixed>>  $lots
     * @return list<array<string, mixed>>
     */
    private static function ordered(array $lots): array
    {
        usort($lots, fn (array $a, array $b): int => self::sortKey($a) <=> self::sortKey($b));

        return array_values($lots);
    }

    /** @return array{0: int, 1: string, 2: string, 3: int} */
    private static function sortKey(array $lot): array
    {
        return [
            $lot['expiry'] === null ? 1 : 0,
            $lot['expiry']?->format('Y-m-d') ?? '',
            $lot['received_at']?->format('Y-m-d') ?? '0000-00-00',
            (int) ($lot['batch_id'] ?? 0),
        ];
    }

    /**
     * @param  Collection<int, MedicineBatch>  $batches
     * @return Collection<int, MedicineBatch>
     */
    private static function sortedBatches(Collection $batches): Collection
    {
        return $batches
            ->sortBy(fn (MedicineBatch $batch) => self::sortKey([
                'expiry' => $batch->expiry_date,
                'received_at' => $batch->received_at,
                'batch_id' => $batch->id,
            ]))
            ->values();
    }
}
