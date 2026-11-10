<?php

use App\Support\MedicineCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an inventory item is a medicine or a supply (testers' report #16).
 *
 * RxNorm names drugs; gauze, bandages, cotton balls, tape, gloves and masks
 * were being looked up in it. The type used to be guessed from the item's
 * name every time it was read; it is now chosen when the item is added and
 * kept on the row. Existing rows are set from that same reading of the name
 * (MedicineCatalogue::isSupply), so every supply already on the shelf is a
 * Supply from the moment this runs. Plain: a type is not personal data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('medicines')) {
            return;
        }

        Schema::table('medicines', function (Blueprint $table) {
            if (! Schema::hasColumn('medicines', 'item_type')) {
                $table->string('item_type', 10)->nullable();
            }
        });

        DB::table('medicines')->whereNull('item_type')->orderBy('id')->get(['id', 'name'])
            ->each(fn ($row) => DB::table('medicines')->where('id', $row->id)->update([
                'item_type' => MedicineCatalogue::isSupply((string) $row->name) ? 'supply' : 'medicine',
            ]));
    }

    public function down(): void
    {
        if (Schema::hasColumn('medicines', 'item_type')) {
            Schema::table('medicines', function (Blueprint $table) {
                $table->dropColumn('item_type');
            });
        }
    }
};
