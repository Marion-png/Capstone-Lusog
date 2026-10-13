<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Feeding Coordinator's word that a learner's attendance record is correct.
 *
 * The capstone adviser's flow puts a human between the marks and the flag:
 *
 *   day 3 of unexcused absence in a row — an attendance notice asks the
 *          coordinator to verify the record. Nothing is flagged.
 *   day 7 — the learner is due to be flagged for follow-up, but only once the
 *          coordinator has confirmed the record is correct.
 *
 * At-risk itself stays derived from the marks. What cannot be derived is that
 * confirmation, so it is the one thing this table keeps: who confirmed which
 * learner's record, at which stage, over which run of absences.
 *
 *   stage            `notice` (verified at the early notice) or `flag` (confirmed
 *                    for follow-up — this is what flags the learner)
 *   run_started_on   the first absence of the run the coordinator looked at, so
 *                    a verification covers that run and never the next one
 *   confirmed_through the last absence the coordinator saw
 *   absences         how long the run was when it was confirmed
 *
 * Nothing here touches enrolment: a flag is a reason to follow a learner up,
 * never to stop feeding them.
 *
 * `feeding_absence_notice_days` joins the school's other rule settings (NULL =
 * the app default of 3), so the notice is as much the school's policy as the
 * flag it comes before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('feeding_attendance_confirmations')) {
            Schema::create('feeding_attendance_confirmations', function (Blueprint $table) {
                $table->id();

                // The learner and the school — plain, because every read of
                // this table is scoped by them.
                $table->unsignedBigInteger('student_health_record_id')->index();
                $table->unsignedBigInteger('institution_id')->nullable()->index();
                $table->string('school_year', 20)->nullable()->index();

                $table->string('stage', 16)->index();
                $table->date('run_started_on')->nullable();
                $table->date('confirmed_through')->nullable();
                $table->unsignedSmallInteger('absences')->default(0);

                // Who confirmed it, and anything they noted. A staff name and a
                // note about a named child are personal information, so both
                // are encrypted at rest (text — the ciphertext is long).
                $table->text('confirmed_by_name')->nullable();
                $table->string('confirmed_by_role', 40)->nullable();
                $table->text('remarks')->nullable();

                $table->timestamps();

                $table->index(['student_health_record_id', 'stage']);
            });
        }

        if (Schema::hasTable('institutions') && ! Schema::hasColumn('institutions', 'feeding_absence_notice_days')) {
            Schema::table('institutions', function (Blueprint $table) {
                $table->unsignedTinyInteger('feeding_absence_notice_days')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('feeding_attendance_confirmations');

        if (Schema::hasTable('institutions') && Schema::hasColumn('institutions', 'feeding_absence_notice_days')) {
            Schema::table('institutions', function (Blueprint $table) {
                $table->dropColumn('feeding_absence_notice_days');
            });
        }
    }
};
