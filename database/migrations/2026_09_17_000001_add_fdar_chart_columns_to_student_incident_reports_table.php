<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The incident report is charted as an F-DAR sheet, and the sheet has two
 * things the row did not.
 *
 * Focus charting is a three-column note — Date/Time | Focus | Progress Notes
 * (Data, Action, Response) — signed by the nurse. The first column is the date
 * AND the time: an incident at 10:20 and one at 2:45 on the same day are two
 * entries, and "yesterday" is not when a learner was hurt. `occurred_time` is
 * a plain time column beside the plain date, since a clock reading is not
 * personal information any more than the date already there.
 *
 * The Focus is a statement of what the note is about — a sign or symptom, a
 * behaviour, a treatment event — and not a medical diagnosis. The fixed
 * category is kept as the kind of incident the list is filtered by; `focus`
 * is the nurse's own words ("Abrasion, left knee"), written about a child and
 * so encrypted at rest by the model's cast. Both are nullable: a report filed
 * without them is still a report, and an un-migrated machine still charts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_incident_reports', function (Blueprint $table) {
            if (! Schema::hasColumn('student_incident_reports', 'occurred_time')) {
                $table->time('occurred_time')->nullable()->after('occurred_at');
            }
            if (! Schema::hasColumn('student_incident_reports', 'focus')) {
                $table->text('focus')->nullable()->after('severity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('student_incident_reports', function (Blueprint $table) {
            $table->dropColumn(['occurred_time', 'focus']);
        });
    }
};
