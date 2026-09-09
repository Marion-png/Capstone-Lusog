<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Announcements gain an archive.
 *
 * The board shows a handful of notices at a time, so a nurse clearing space
 * for this week's announcement had only one control: Remove, which destroys
 * the notice outright. That is the wrong instrument for "this has happened
 * already" — the deworming schedule that ran last Friday is still the record
 * of what the school told its staff, and a nurse should not have to choose
 * between an accurate board and a retrievable one.
 *
 * So archiving is a stamp beside the announcement, never a deletion of it:
 * `archived_at` (plain, indexed) with `archived_by_name`. Both columns are
 * plain, matching `posted_by_name` beside them — an announcement carries no
 * personal information about a learner, and `archived_at` has to be filtered
 * in SQL on every dashboard read.
 *
 * Deleting still exists and still means gone. Archiving is the other answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            if (! Schema::hasColumn('announcements', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->index()->after('audience');
            }

            if (! Schema::hasColumn('announcements', 'archived_by_name')) {
                $table->string('archived_by_name')->nullable()->after('archived_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn(['archived_at', 'archived_by_name']);
        });
    }
};
