<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who last edited an incident report, and when.
 *
 * The sheet is signed by whoever charted it, and that signature stands through
 * an edit. So a clinic teacher correcting a nurse's note needs a line of their
 * own, or the new words read as the nurse's. The name is staff data about a
 * record of a child and is encrypted through the model's cast, like the name of
 * who filed it. All three are NULL for a report nobody has edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('student_incident_reports') || Schema::hasColumn('student_incident_reports', 'edited_at')) {
            return;
        }

        Schema::table('student_incident_reports', function (Blueprint $table) {
            $table->text('edited_by_name')->nullable();
            $table->string('edited_by_role', 40)->nullable();
            $table->timestamp('edited_at')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('student_incident_reports') && Schema::hasColumn('student_incident_reports', 'edited_at')) {
            Schema::table('student_incident_reports', function (Blueprint $table) {
                $table->dropColumn(['edited_by_name', 'edited_by_role', 'edited_at']);
            });
        }
    }
};
