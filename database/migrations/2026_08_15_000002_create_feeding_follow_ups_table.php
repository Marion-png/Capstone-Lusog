<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the coordinator did about a beneficiary who is not turning up.
 *
 * At-risk itself is derived and never stored — the rule reads the marks every
 * time. What cannot be derived is the follow-up: the day the guardian was
 * contacted, what was discussed, and where the concern now stands. That is the
 * one thing on the At-Risk Students tab a human enters, so it is the one thing
 * this table keeps.
 *
 * Everything a coordinator types here is about a named child, so every free-text
 * column is encrypted at rest (text, not string — the ciphertext is far longer
 * than the note) and read through the App\Casts\EncryptedString cast. The plain
 * columns are the ones something has to look a row up by: the learner, the
 * school, the school year, the date, and the status.
 *
 * `status` is deliberately plain and deliberately NOT an enrolment field.
 * "Resolved" means the attendance concern has been addressed — it never removes
 * a learner from the feeding programme, which is `feeding_enrolled_at`'s job and
 * follows its own workflow.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('feeding_follow_ups')) {
            return;
        }

        Schema::create('feeding_follow_ups', function (Blueprint $table) {
            $table->id();

            // The learner this follow-up is about, and the school that owns it —
            // both plain, because every read of this table is scoped by them.
            $table->unsignedBigInteger('student_health_record_id')->index();
            $table->unsignedBigInteger('institution_id')->nullable()->index();
            $table->string('school_year', 20)->nullable()->index();

            // When the coordinator followed up, and where the concern stands.
            $table->date('followed_up_on');
            $table->string('status', 32)->default('follow_up_required')->index();

            // The coordinator's own account of it. Personal information about a
            // named child, so all four are encrypted at rest.
            $table->text('reason')->nullable();
            $table->text('action_taken')->nullable();
            $table->text('person_contacted')->nullable();
            $table->text('remarks')->nullable();

            // Who recorded it. A staff name is personal information too.
            $table->text('recorded_by_name')->nullable();
            $table->string('recorded_by_role', 40)->nullable();

            $table->timestamps();

            // The one query this table serves: the latest follow-up for each
            // learner on the list.
            $table->index(['student_health_record_id', 'followed_up_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feeding_follow_ups');
    }
};
