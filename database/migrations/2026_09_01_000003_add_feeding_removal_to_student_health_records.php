<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leaving the programme becomes a recorded decision, exactly as joining it is.
 *
 * A beneficiary who stops coming — transferred, moved away, withdrawn — is
 * eventually taken off the active list so the slot can go to a learner on the
 * waiting list. Until now the only way to express that was to clear
 * `feeding_enrolled_at`, which erases the fact that they were ever fed: their
 * attendance, their baseline and the reason they left all become unattributable.
 *
 * So removal is its own stamp beside the enrolment, never a deletion of it.
 * `feeding_removed_at` is plain (queries filter on it and it is programme
 * state); the staff member who decided and the reason they gave are personal
 * information and are encrypted through the model's casts, like every other
 * name and note in this schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('student_health_records')) {
            return;
        }

        Schema::table('student_health_records', function (Blueprint $table) {
            if (! Schema::hasColumn('student_health_records', 'feeding_removed_at')) {
                $table->timestamp('feeding_removed_at')->nullable()->index();
            }
            if (! Schema::hasColumn('student_health_records', 'feeding_removed_by')) {
                $table->text('feeding_removed_by')->nullable();
            }
            if (! Schema::hasColumn('student_health_records', 'feeding_removal_reason')) {
                $table->text('feeding_removal_reason')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('student_health_records')) {
            return;
        }

        if (Schema::hasColumn('student_health_records', 'feeding_removed_at')) {
            Schema::table('student_health_records', function (Blueprint $table) {
                $table->dropIndex(['feeding_removed_at']);
            });
        }

        $columns = array_values(array_filter([
            'feeding_removed_at',
            'feeding_removed_by',
            'feeding_removal_reason',
        ], fn (string $column): bool => Schema::hasColumn('student_health_records', $column)));

        if ($columns === []) {
            return;
        }

        Schema::table('student_health_records', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }
};
