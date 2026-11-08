<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The physical examination charted on an incident report: the vital signs
 * taken at the time, the level of consciousness, the pain score, the body part
 * affected and the findings (App\Support\IncidentPhysicalExam).
 *
 * One JSON column rather than nine, because the section is read and written as
 * a whole and nothing filters on any one reading. It is health information
 * about a child, so it is encrypted through the model's cast and never named
 * in SQL. NULL for every report charted before it, and for one charted with no
 * examination.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('student_incident_reports') || Schema::hasColumn('student_incident_reports', 'physical_exam')) {
            return;
        }

        Schema::table('student_incident_reports', function (Blueprint $table) {
            $table->text('physical_exam')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('student_incident_reports') && Schema::hasColumn('student_incident_reports', 'physical_exam')) {
            Schema::table('student_incident_reports', function (Blueprint $table) {
                $table->dropColumn('physical_exam');
            });
        }
    }
};
