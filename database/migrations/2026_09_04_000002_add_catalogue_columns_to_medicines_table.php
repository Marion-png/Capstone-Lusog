<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks stock the school holds outside the DepEd-allowed list.
 *
 * The nurse dispenses beyond the list when it is clinically necessary. That
 * is recorded rather than refused — but it is recorded *as* off-list, with
 * the reason, so the school can see what it is holding beyond what DepEd
 * supplies and answer for it when asked.
 *
 * Both columns are plain: a medicine name is not personal information, and
 * `off_catalogue` has to be filterable in SQL for the inventory screens.
 * Existing rows default to on-list — they were entered before the catalogue
 * existed and nobody can retroactively say otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->boolean('off_catalogue')->default(false)->after('name');
            $table->string('off_catalogue_reason', 255)->nullable()->after('off_catalogue');
        });
    }

    public function down(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->dropColumn(['off_catalogue', 'off_catalogue_reason']);
        });
    }
};
