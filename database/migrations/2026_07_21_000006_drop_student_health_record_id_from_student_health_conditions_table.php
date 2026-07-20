<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conditions/certificates now carry forward across school years via
     * student_lrn + institution_id (backfilled in the previous migration)
     * instead of being pinned to one year's student_health_records row.
     */
    public function up(): void
    {
        if (! Schema::hasTable('student_health_conditions') || ! Schema::hasColumn('student_health_conditions', 'student_health_record_id')) {
            return;
        }

        Schema::table('student_health_conditions', function (Blueprint $table) {
            $table->dropForeign(['student_health_record_id']);
            $table->dropColumn('student_health_record_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('student_health_conditions') || Schema::hasColumn('student_health_conditions', 'student_health_record_id')) {
            return;
        }

        Schema::table('student_health_conditions', function (Blueprint $table) {
            $table->foreignId('student_health_record_id')->nullable()->after('id')
                ->constrained('student_health_records')->cascadeOnDelete();
        });
    }
};
