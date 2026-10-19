<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A class adviser may archive a learner who has left their class mid-year —
 * transferred out, stopped attending — so the learner leaves the adviser's and
 * the nurse's working lists while their record stays exactly where it is.
 *
 * Archiving is a stamp on that school year's row, never a deletion and never a
 * move: `archived_at` is plain (lists filter on it), and who archived the
 * learner and why are personal information, encrypted through the model's
 * casts like every other staff name and note in this schema.
 *
 * The stamp deliberately plays no part in retention. The 5-year countdown is
 * still derived from the last school year the learner was enrolled in
 * (StudentRetention), so a mistaken archive can never bring a deletion closer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('student_health_records')) {
            return;
        }

        Schema::table('student_health_records', function (Blueprint $table) {
            if (! Schema::hasColumn('student_health_records', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->index();
            }
            if (! Schema::hasColumn('student_health_records', 'archived_by')) {
                $table->text('archived_by')->nullable();
            }
            if (! Schema::hasColumn('student_health_records', 'archive_reason')) {
                $table->text('archive_reason')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('student_health_records')) {
            return;
        }

        if (Schema::hasColumn('student_health_records', 'archived_at')) {
            Schema::table('student_health_records', function (Blueprint $table) {
                $table->dropIndex(['archived_at']);
            });
        }

        $columns = array_values(array_filter([
            'archived_at',
            'archived_by',
            'archive_reason',
        ], fn (string $column): bool => Schema::hasColumn('student_health_records', $column)));

        if ($columns === []) {
            return;
        }

        Schema::table('student_health_records', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }
};
