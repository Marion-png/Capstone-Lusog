<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who entered a learner's endline weighing, and when (testers' report #32).
 *
 * The endline is now the Feeding Program Coordinator's to record, so the row
 * says who did: `endline_entered_by` is a staff name, encrypted by the model
 * like `feeding_enrolled_by`; `endline_entered_at` is the moment it was
 * entered, plain. NULL on every older row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_health_records', function (Blueprint $table) {
            if (! Schema::hasColumn('student_health_records', 'endline_entered_by')) {
                $table->text('endline_entered_by')->nullable();
            }
            if (! Schema::hasColumn('student_health_records', 'endline_entered_at')) {
                $table->timestamp('endline_entered_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('student_health_records', function (Blueprint $table) {
            $table->dropColumn(['endline_entered_by', 'endline_entered_at']);
        });
    }
};
