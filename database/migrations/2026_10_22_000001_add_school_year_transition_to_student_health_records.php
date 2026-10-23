<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The school-year handover (SIGLA School Year Transition spec, 14 Oct 2026).
 *
 * A `student_health_records` row is one learner in one section for one school
 * year — the spec's placement — so the placement and the learner's standing
 * are recorded on it rather than in a second table that would have to be kept
 * in step with it:
 *
 *  - `left_at` / `end_status` close the placement: the date the learner left
 *    the section and why (promoted, retained, transferred_out, dropped_out,
 *    completed_grade_10).
 *  - `wait_grade` is the grade a promoted or retained learner is waiting to be
 *    placed in.
 *  - `delete_after` is the date an archived learner is permanently deleted;
 *    restoring the learner clears it.
 *  - `joined_at` / `joined_as` record how the learner came into the section
 *    (carried_over, new_enrollee, restored) — the spec's tag and its
 *    `is_new_enrollee`.
 *
 * Every one of these is a workflow state or a date, never free text about a
 * child, so they stay plain: the lists and the deletion job filter on them.
 * Who archived a learner and why stay encrypted in the existing
 * `archived_by` / `archive_reason` columns.
 *
 * `section_closures` is the spec's `sections.closed_at`: one row per section
 * per school year, written when the adviser submits the end-of-year statuses.
 * A section is school structure, so its columns are plain; the name of the
 * adviser who closed it is personal data and is encrypted by the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('student_health_records')) {
            Schema::table('student_health_records', function (Blueprint $table) {
                // Normally added by 2026_10_13_000001; repeated here so this
                // migration never depends on the order a deployment ran them in.
                if (! Schema::hasColumn('student_health_records', 'archived_at')) {
                    $table->timestamp('archived_at')->nullable()->index();
                }
                if (! Schema::hasColumn('student_health_records', 'archived_by')) {
                    $table->text('archived_by')->nullable();
                }
                if (! Schema::hasColumn('student_health_records', 'archive_reason')) {
                    $table->text('archive_reason')->nullable();
                }

                if (! Schema::hasColumn('student_health_records', 'left_at')) {
                    $table->timestamp('left_at')->nullable()->index();
                }
                if (! Schema::hasColumn('student_health_records', 'end_status')) {
                    $table->string('end_status', 30)->nullable();
                }
                if (! Schema::hasColumn('student_health_records', 'wait_grade')) {
                    $table->string('wait_grade', 50)->nullable();
                }
                if (! Schema::hasColumn('student_health_records', 'delete_after')) {
                    $table->date('delete_after')->nullable()->index();
                }
                if (! Schema::hasColumn('student_health_records', 'joined_at')) {
                    $table->timestamp('joined_at')->nullable();
                }
                if (! Schema::hasColumn('student_health_records', 'joined_as')) {
                    $table->string('joined_as', 20)->nullable();
                }
            });
        }

        if (! Schema::hasTable('section_closures')) {
            Schema::create('section_closures', function (Blueprint $table) {
                $table->id();
                $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
                $table->string('school_year', 20);
                $table->string('grade_level', 50);
                $table->string('section', 100);
                $table->timestamp('closed_at');
                $table->text('closed_by')->nullable();
                $table->timestamps();

                $table->unique(['institution_id', 'school_year', 'grade_level', 'section'], 'section_closures_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('section_closures');

        if (! Schema::hasTable('student_health_records')) {
            return;
        }

        foreach (['left_at', 'delete_after'] as $indexed) {
            if (Schema::hasColumn('student_health_records', $indexed)) {
                Schema::table('student_health_records', function (Blueprint $table) use ($indexed) {
                    $table->dropIndex([$indexed]);
                });
            }
        }

        // The archive columns belong to 2026_10_13_000001 and are left for it.
        $columns = array_values(array_filter([
            'left_at',
            'end_status',
            'wait_grade',
            'delete_after',
            'joined_at',
            'joined_as',
        ], fn (string $column): bool => Schema::hasColumn('student_health_records', $column)));

        if ($columns === []) {
            return;
        }

        Schema::table('student_health_records', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }
};
