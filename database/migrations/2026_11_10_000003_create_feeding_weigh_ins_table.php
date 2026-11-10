<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional progress weigh-ins between baseline and endline (testers' report
 * #33, the Sta. Ana coordinator's practice of weighing every 30 days).
 *
 *  - `institutions.feeding_progress_weigh_ins`: the school's setting, OFF by
 *    default. Baseline and endline stay the only required weighings.
 *  - `feeding_weigh_ins`: one row per beneficiary per checkpoint (feeding day
 *    30, 60 or 90) per school year. The figures and the classifications are a
 *    child's health data and the staff name personal data, so all of them are
 *    encrypted by the model; the keys the pages look up by stay plain.
 *
 * Turning the setting off hides the weigh-ins; their rows are kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('institutions') && ! Schema::hasColumn('institutions', 'feeding_progress_weigh_ins')) {
            Schema::table('institutions', function (Blueprint $table) {
                $table->boolean('feeding_progress_weigh_ins')->default(false);
            });
        }

        if (! Schema::hasTable('feeding_weigh_ins')) {
            Schema::create('feeding_weigh_ins', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('institution_id')->index();
                $table->unsignedBigInteger('student_health_record_id')->index();
                $table->string('school_year', 9);
                $table->unsignedSmallInteger('feeding_day');
                $table->date('weighed_on');
                $table->text('height_cm');
                $table->text('weight_kg');
                $table->text('bmi_value')->nullable();
                $table->text('nutritional_status')->nullable();
                $table->text('height_for_age')->nullable();
                $table->text('recorded_by_name')->nullable();
                $table->timestamps();

                $table->unique(['student_health_record_id', 'feeding_day'], 'feeding_weigh_ins_checkpoint_unique');
                $table->index(['institution_id', 'school_year'], 'feeding_weigh_ins_scope_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('feeding_weigh_ins');

        if (Schema::hasColumn('institutions', 'feeding_progress_weigh_ins')) {
            Schema::table('institutions', function (Blueprint $table) {
                $table->dropColumn('feeding_progress_weigh_ins');
            });
        }
    }
};
