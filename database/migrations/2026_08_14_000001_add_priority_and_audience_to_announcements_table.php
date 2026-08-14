<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Announcements gain a priority and an audience.
 *
 * Priority lets the nurse mark a notice Important or Urgent so it reads
 * differently on every dashboard. Audience lets them send it to specific
 * roles instead of the whole staff — a deworming reminder concerns class
 * advisers, a stock warning concerns the clinic.
 *
 * Both columns are plain, not encrypted: they carry no personal information
 * and the audience must be filterable in SQL on every dashboard read.
 * `audience` holds a JSON list of role keys; an empty list means everyone.
 * Existing rows default to a normal-priority, everyone announcement, which
 * is exactly what they were before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            if (! Schema::hasColumn('announcements', 'priority')) {
                $table->string('priority', 20)->default('normal')->index()->after('body');
            }

            if (! Schema::hasColumn('announcements', 'audience')) {
                $table->json('audience')->nullable()->after('priority');
            }
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn(['priority', 'audience']);
        });
    }
};
