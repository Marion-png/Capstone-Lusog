<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of the at-risk rule becomes the school's own, not the platform's.
 *
 * `feeding_at_risk_threshold` and `feeding_min_observation_days` already let a
 * school say *how much* attendance is enough and *how much history* to judge
 * on. Field work at Sta. Ana turned up a school whose rule is not a percentage
 * at all: a beneficiary is flagged after one week of unexcused absence, and
 * after roughly a second week — once the adviser has confirmed the learner is
 * not coming back — they are removed from the active list and a learner from
 * the waiting list takes the slot.
 *
 * That is a different *kind* of rule, so the mode itself has to be settable per
 * school rather than being a platform-wide constant. Four columns:
 *
 *   feeding_at_risk_mode         which rule this school runs
 *   feeding_absence_flag_days    unexcused absences that flag (one feeding week)
 *   feeding_absence_removal_days unexcused absences that open a removal review
 *   feeding_cycle_days           the mandated cycle length
 *
 * The last one is here because the Division has 120 days in policy and 90 under
 * discussion. A cycle length compiled into the application is a number a school
 * cannot correct when its Division settles the question.
 *
 * All four are NULL by default, meaning "use the app default" (config/feeding.php),
 * so a school that sets nothing keeps moving with the programme. All four are
 * plain: they are policy settings, never personal data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('institutions')) {
            return;
        }

        Schema::table('institutions', function (Blueprint $table) {
            if (! Schema::hasColumn('institutions', 'feeding_at_risk_mode')) {
                $table->string('feeding_at_risk_mode', 40)->nullable();
            }
            if (! Schema::hasColumn('institutions', 'feeding_absence_flag_days')) {
                $table->unsignedTinyInteger('feeding_absence_flag_days')->nullable();
            }
            if (! Schema::hasColumn('institutions', 'feeding_absence_removal_days')) {
                $table->unsignedTinyInteger('feeding_absence_removal_days')->nullable();
            }
            if (! Schema::hasColumn('institutions', 'feeding_cycle_days')) {
                $table->unsignedSmallInteger('feeding_cycle_days')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('institutions')) {
            return;
        }

        $columns = array_values(array_filter([
            'feeding_at_risk_mode',
            'feeding_absence_flag_days',
            'feeding_absence_removal_days',
            'feeding_cycle_days',
        ], fn (string $column): bool => Schema::hasColumn('institutions', $column)));

        if ($columns === []) {
            return;
        }

        Schema::table('institutions', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }
};
