<?php

use App\Models\StudentHealthRecord;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enrolling a learner into the feeding programme becomes a decision.
 *
 * Until now "beneficiary" was derived: any learner the adviser measured as
 * wasted or severely wasted was fed by definition. The coordinator now chooses
 * who to enrol, so the qualification and the enrolment are two different facts.
 *
 * The backfill records what was already true rather than reinterpreting it:
 * every currently-qualified learner is stamped as enrolled at the moment their
 * record was created, because that is when this system began treating them as a
 * beneficiary. Without it, every school's dashboard would empty out on deploy
 * and learners with attendance already recorded would read as never enrolled.
 *
 * nutritional_status is encrypted at rest, so qualification is decided in PHP
 * after fetch — the same reason no other query filters on it. The writes go
 * through the query builder, not the model: this is one historical fact being
 * recorded, and routing thousands of rows through the Auditable trait would
 * bury the audit log in entries for a decision no human made.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('student_health_records')) {
            return;
        }

        if (! Schema::hasColumn('student_health_records', 'feeding_enrolled_at')) {
            Schema::table('student_health_records', function (Blueprint $table) {
                // Plain timestamp: it is a programme state the queries filter
                // on, not personal information.
                $table->timestamp('feeding_enrolled_at')->nullable()->index();
                // Who enrolled them — a staff name, so encrypted like every
                // other name in this schema.
                $table->text('feeding_enrolled_by')->nullable();
            });
        }

        StudentHealthRecord::query()
            ->whereNull('feeding_enrolled_at')
            ->chunkById(200, function ($records): void {
                foreach ($records as $record) {
                    if (! $this->qualifies((string) $record->nutritional_status)) {
                        continue;
                    }

                    DB::table('student_health_records')
                        ->where('id', $record->id)
                        ->update(['feeding_enrolled_at' => $record->created_at ?? now()]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('student_health_records') && Schema::hasColumn('student_health_records', 'feeding_enrolled_at')) {
            Schema::table('student_health_records', function (Blueprint $table) {
                $table->dropIndex(['feeding_enrolled_at']);
            });

            Schema::table('student_health_records', function (Blueprint $table) {
                $table->dropColumn(['feeding_enrolled_at', 'feeding_enrolled_by']);
            });
        }
    }

    /** Mirrors FeedingProgramController::isAttendanceEligible at the time of writing. */
    private function qualifies(string $status): bool
    {
        $status = strtolower(preg_replace('/\s+/', ' ', trim($status)) ?? '');

        return in_array($status, ['wasted', 'severely wasted', 'severly wasted', 'underweight'], true);
    }
};
