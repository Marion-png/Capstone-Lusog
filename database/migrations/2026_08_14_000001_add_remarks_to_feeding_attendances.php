<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a learner missed a feeding session.
 *
 * The remark is written by the coordinator alongside the mark ("sick", "went
 * home early"), so it is personal information about a named child and is
 * encrypted at rest like every other such column — text, not string, because
 * the ciphertext is far longer than the note.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('feeding_attendances') && ! Schema::hasColumn('feeding_attendances', 'remarks')) {
            Schema::table('feeding_attendances', function (Blueprint $table) {
                $table->text('remarks')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('feeding_attendances') && Schema::hasColumn('feeding_attendances', 'remarks')) {
            Schema::table('feeding_attendances', function (Blueprint $table) {
                $table->dropColumn('remarks');
            });
        }
    }
};
