<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An absence with a reason is not the same fact as an absence without one.
 *
 * The Feeding Coordinator keeps what she calls a "buffer": a learner who misses
 * a session for a valid reason — fasting during Ramadan, illness, a family
 * emergency — is logged as absent, but that absence is deliberately not held
 * against them. Only unexcused absence accumulates toward the at-risk flag and,
 * beyond it, toward removal from the active list.
 *
 * Until now the table could only say present or absent (or NULL for a scanned
 * mark nobody had confirmed), so an excused absence had to be recorded as one
 * of the two and either lost the reason or pushed a fasting child onto a
 * follow-up list. `is_excused` is the third state, carried beside `is_present`
 * rather than folded into it, so every existing reading of `is_present` keeps
 * meaning exactly what it meant.
 *
 * Plain boolean, not encrypted: it is the *kind* of absence, a programme fact
 * the rule filters on. The reason itself lives in the encrypted `remarks`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('feeding_attendances') && ! Schema::hasColumn('feeding_attendances', 'is_excused')) {
            Schema::table('feeding_attendances', function (Blueprint $table) {
                // False, not nullable: every mark already on file was recorded
                // before excusing existed, and none of them was excused.
                $table->boolean('is_excused')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('feeding_attendances') && Schema::hasColumn('feeding_attendances', 'is_excused')) {
            Schema::table('feeding_attendances', function (Blueprint $table) {
                $table->dropColumn('is_excused');
            });
        }
    }
};
