<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The school year a teacher's assignment is for, when it is not the
 * calendar's.
 *
 * NULL — every account until a teacher chooses otherwise — means the year the
 * calendar is in, so an assignment moves into the next school year on the
 * first of June as it always has. A chosen year is honoured only while
 * `app.school_year_testing` is on (WorkingSchoolYear). Plain, like every other
 * school_year column: a year is not a fact about a person.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounts') || Schema::hasColumn('accounts', 'assigned_school_year')) {
            return;
        }

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('assigned_school_year', 9)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('accounts') && Schema::hasColumn('accounts', 'assigned_school_year')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->dropColumn('assigned_school_year');
            });
        }
    }
};
