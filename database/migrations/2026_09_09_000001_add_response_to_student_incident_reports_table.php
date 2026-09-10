<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The incident report becomes an FDAR chart, and FDAR has a fourth part.
 *
 * Focus charting is Focus / Data / Action / Response, and three of the four
 * were already here under other names: the category is the focus, the
 * description is the data, `action_taken` is the action. What no column held
 * was the **response** — what happened to the learner after the action, which
 * is the part that says whether the action worked. A chart that records an
 * intervention and never its outcome is the half of the record that cannot be
 * followed up.
 *
 * Written by a person, about a child, so it is encrypted at rest by the
 * model's cast and the column is `text`. Nullable: a report filed the moment
 * something happens has no response yet, and refusing to file one until it
 * does would lose the record of the incident itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_incident_reports', function (Blueprint $table) {
            if (! Schema::hasColumn('student_incident_reports', 'response')) {
                $table->text('response')->nullable()->after('action_taken');
            }
        });
    }

    public function down(): void
    {
        Schema::table('student_incident_reports', function (Blueprint $table) {
            $table->dropColumn('response');
        });
    }
};
