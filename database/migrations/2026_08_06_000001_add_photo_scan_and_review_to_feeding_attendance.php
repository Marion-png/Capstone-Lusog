<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds photo-scan support to feeding attendance.
 *
 * The load-bearing change is `is_present` becoming nullable. A scanned sheet
 * can come back unreadable for a given learner, and that is a THIRD state — not
 * an absence. Storing it as `false` would quietly push a learner toward the
 * at-risk flag on evidence nobody has read, which is exactly the silent
 * mis-flag this feature must not have. NULL means "not yet confirmed by a
 * human", and the at-risk computation ignores those rows entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('feeding_attendances')) {
            Schema::table('feeding_attendances', function (Blueprint $table) {
                // Which batch produced this mark — lets the review queue show
                // the right photo and lets us purge it once nothing is pending.
                $table->unsignedBigInteger('attendance_import_id')->nullable()->index();
                // 'spreadsheet' | 'photo_scan' | 'manual_review'
                $table->string('source')->default('spreadsheet')->index();
                // True only while a scanned mark is unread and unconfirmed.
                $table->boolean('needs_review')->default(false)->index();
                $table->text('reviewed_by_name')->nullable();
                $table->timestamp('reviewed_at')->nullable();
            });

            // SQLite cannot ALTER a column in place; rebuild is handled by
            // Laravel's schema builder on other drivers. Guarded so a re-run
            // (or a driver that already allows NULL) is a no-op.
            Schema::table('feeding_attendances', function (Blueprint $table) {
                $table->boolean('is_present')->nullable()->change();
            });
        }

        if (Schema::hasTable('attendance_imports')) {
            Schema::table('attendance_imports', function (Blueprint $table) {
                // 'spreadsheet' | 'photo_scan'
                $table->string('kind')->default('spreadsheet')->index();
                // A photo covers one session; a spreadsheet covers many.
                $table->date('session_date')->nullable();
                $table->unsignedInteger('unclear_count')->default(0);
                // Set when the source image is deleted after review completes.
                $table->timestamp('photo_purged_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('feeding_attendances')) {
            // Indexes must go first — SQLite leaves them dangling otherwise and
            // the column drop fails with "error in index ... after drop column".
            Schema::table('feeding_attendances', function (Blueprint $table) {
                $table->dropIndex(['attendance_import_id']);
                $table->dropIndex(['source']);
                $table->dropIndex(['needs_review']);
            });

            Schema::table('feeding_attendances', function (Blueprint $table) {
                $table->dropColumn([
                    'attendance_import_id',
                    'source',
                    'needs_review',
                    'reviewed_by_name',
                    'reviewed_at',
                ]);
            });
        }

        if (Schema::hasTable('attendance_imports')) {
            Schema::table('attendance_imports', function (Blueprint $table) {
                $table->dropColumn(['kind', 'session_date', 'unclear_count', 'photo_purged_at']);
            });
        }
    }
};
