<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who recorded a feeding mark.
 *
 * A mark is evidence that a named child was fed, so the record has to say where
 * it came from — the coordinator who recorded the session on site, or the one
 * who uploaded the sheet it was read from. Until now only a *reviewed* mark
 * carried a name (`reviewed_by_name`), which left every ordinary mark
 * attributable to nothing but its `source` string.
 *
 * The name is personal information, so it is encrypted at rest like every other
 * such column — text, not string, because the ciphertext is far longer than the
 * name. Writes that bypass Eloquent (the bulk upserts in
 * FeedingProgramController) must encrypt it themselves.
 *
 * Existing rows are left NULL rather than backfilled with a guess: nobody knows
 * who recorded them, and inventing an attribution in an audit trail is worse
 * than admitting the gap.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('feeding_attendances') && ! Schema::hasColumn('feeding_attendances', 'recorded_by_name')) {
            Schema::table('feeding_attendances', function (Blueprint $table) {
                $table->text('recorded_by_name')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('feeding_attendances') && Schema::hasColumn('feeding_attendances', 'recorded_by_name')) {
            Schema::table('feeding_attendances', function (Blueprint $table) {
                $table->dropColumn('recorded_by_name');
            });
        }
    }
};
