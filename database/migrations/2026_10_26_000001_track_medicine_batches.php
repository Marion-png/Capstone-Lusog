<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which box a dispensed medicine came out of.
 *
 * The stock record knew how many tablets of paracetamol the clinic held and
 * the earliest expiry among them, but not which delivery each one belonged
 * to — so nothing could say which box to hand over, and once the earliest box
 * was used up the item went on reporting its date. Three additions:
 *
 *  - `medicine_batches` — one row per lot on the shelf: the stock an item was
 *    created with ("Opening stock") and every delivery after it, each with its
 *    own expiry date and how much of it is left. The batches of an item always
 *    add up to its `stock_quantity`. Nothing here is personal information
 *    except who removed an expired lot, which is a staff name and is
 *    encrypted like every other one.
 *
 *  - `medicine_dispense_batches` — which lots a dispense drew from, and how
 *    much from each. Dispensing takes the lot that expires first (FEFO), so
 *    the record of a dispense says which expiry date went to the child.
 *
 *  - `medicine_dispenses.consultation_id` — the visit a dispense was recorded
 *    on, so the consultation log and the clinic dashboard can print what was
 *    given beside the visit it was given at.
 *
 * Existing stock is divided into lots once, here. Dispensing has always taken
 * the oldest box first in practice, so what is left on the shelf is taken to
 * be the latest-expiring deliveries; anything the deliveries do not account
 * for is the opening stock, at the date the item already carried.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('medicine_dispenses') && ! Schema::hasColumn('medicine_dispenses', 'consultation_id')) {
            Schema::table('medicine_dispenses', function (Blueprint $table) {
                $table->foreignId('consultation_id')
                    ->nullable()
                    ->after('medicine_id')
                    ->constrained('consultations')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasTable('medicine_batches')) {
            Schema::create('medicine_batches', function (Blueprint $table) {
                $table->id();

                $table->foreignId('medicine_id')->constrained()->cascadeOnDelete();
                $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
                // The delivery this lot arrived in; null for opening stock.
                $table->foreignId('medicine_receipt_id')->nullable()->constrained()->nullOnDelete();

                $table->date('expiry_date')->nullable();
                $table->date('received_at')->nullable();

                $table->unsignedInteger('quantity_received');
                $table->unsignedInteger('quantity_remaining');
                $table->unsignedInteger('quantity_disposed')->default(0);

                $table->string('source', 120)->nullable();

                // Expired stock taken off the shelf. The name is a staff name
                // and is encrypted; the role is plain.
                $table->timestamp('disposed_at')->nullable();
                $table->text('disposed_by_name')->nullable();
                $table->string('disposed_by_role', 40)->nullable();

                $table->timestamps();

                $table->index(['medicine_id', 'quantity_remaining']);
                $table->index(['institution_id', 'medicine_id']);
            });
        }

        if (! Schema::hasTable('medicine_dispense_batches')) {
            Schema::create('medicine_dispense_batches', function (Blueprint $table) {
                $table->id();

                $table->foreignId('medicine_dispense_id')->constrained()->cascadeOnDelete();
                $table->foreignId('medicine_batch_id')->constrained()->cascadeOnDelete();

                $table->unsignedInteger('quantity');
                // Copied from the lot, so the dispense keeps saying which date
                // it was however the lot is later read.
                $table->date('expiry_date')->nullable();

                $table->timestamps();

                $table->index('medicine_dispense_id');
                $table->index('medicine_batch_id');
            });
        }

        $this->divideExistingStock();
    }

    public function down(): void
    {
        Schema::dropIfExists('medicine_dispense_batches');
        Schema::dropIfExists('medicine_batches');

        if (Schema::hasTable('medicine_dispenses') && Schema::hasColumn('medicine_dispenses', 'consultation_id')) {
            Schema::table('medicine_dispenses', function (Blueprint $table) {
                $table->dropConstrainedForeignId('consultation_id');
            });
        }
    }

    /**
     * Split each item's stock into lots that add up to it, and re-read the
     * item's expiry off them.
     */
    private function divideExistingStock(): void
    {
        if (! Schema::hasTable('medicines')) {
            return;
        }

        $hasReceipts = Schema::hasTable('medicine_receipts');
        $hasExpiry = Schema::hasColumn('medicines', 'expiry_date');
        $today = Carbon::today();
        $now = Carbon::now();

        DB::table('medicines')->orderBy('id')->chunkById(100, function ($medicines) use ($hasReceipts, $hasExpiry, $today, $now): void {
            foreach ($medicines as $medicine) {
                // Run once per item: a second run finds the lots already there.
                if (DB::table('medicine_batches')->where('medicine_id', $medicine->id)->exists()) {
                    continue;
                }

                $left = max(0, (int) $medicine->stock_quantity);
                $lots = [];

                $receipts = $hasReceipts
                    ? DB::table('medicine_receipts')->where('medicine_id', $medicine->id)->get()
                    : collect();

                // What is still on the shelf is what would be dispensed last:
                // an undated delivery first, then the latest expiry, then the
                // latest arrival.
                $receipts = $receipts->sort(function ($a, $b): int {
                    if (($a->expiry_date === null) !== ($b->expiry_date === null)) {
                        return $a->expiry_date === null ? -1 : 1;
                    }

                    return [(string) $b->expiry_date, (string) $b->received_at, (int) $b->id]
                        <=> [(string) $a->expiry_date, (string) $a->received_at, (int) $a->id];
                });

                foreach ($receipts as $receipt) {
                    $remaining = min((int) $receipt->quantity, $left);
                    $left -= $remaining;

                    $lots[] = [
                        'medicine_id' => $medicine->id,
                        'institution_id' => $medicine->institution_id,
                        'medicine_receipt_id' => $receipt->id,
                        'expiry_date' => $receipt->expiry_date,
                        'received_at' => $receipt->received_at,
                        'quantity_received' => (int) $receipt->quantity,
                        'quantity_remaining' => $remaining,
                        'quantity_disposed' => 0,
                        'source' => $receipt->source,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($left > 0) {
                    $lots[] = [
                        'medicine_id' => $medicine->id,
                        'institution_id' => $medicine->institution_id,
                        'medicine_receipt_id' => null,
                        'expiry_date' => $hasExpiry ? $medicine->expiry_date : null,
                        'received_at' => $medicine->created_at ? Carbon::parse($medicine->created_at)->toDateString() : null,
                        'quantity_received' => $left,
                        'quantity_remaining' => $left,
                        'quantity_disposed' => 0,
                        'source' => 'Opening stock',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($lots !== []) {
                    DB::table('medicine_batches')->insert($lots);
                }

                if ($hasExpiry) {
                    DB::table('medicines')->where('id', $medicine->id)->update([
                        'expiry_date' => $this->itemExpiry($lots, $today),
                    ]);
                }
            }
        });
    }

    /**
     * The date the item is judged by, by the rule App\Support\MedicineBatches
     * applies: the earliest lot still usable; an undated usable lot is
     * unknown; expired stock only when nothing usable is left.
     *
     * @param  list<array<string, mixed>>  $lots
     */
    private function itemExpiry(array $lots, Carbon $today): ?string
    {
        $usableDated = [];
        $usableUndated = false;
        $expired = [];

        foreach ($lots as $lot) {
            if ((int) $lot['quantity_remaining'] <= 0) {
                continue;
            }

            if ($lot['expiry_date'] === null) {
                $usableUndated = true;

                continue;
            }

            $date = Carbon::parse($lot['expiry_date'])->startOfDay();
            if ($date->gt($today)) {
                $usableDated[] = $date->toDateString();
            } else {
                $expired[] = $date->toDateString();
            }
        }

        if ($usableDated !== []) {
            return min($usableDated);
        }

        if ($usableUndated || $expired === []) {
            return null;
        }

        return min($expired);
    }
};
