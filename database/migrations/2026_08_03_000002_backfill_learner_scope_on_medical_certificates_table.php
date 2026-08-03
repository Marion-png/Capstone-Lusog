<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Certificates uploaded before the learner columns existed reach their LRN
     * through the condition they were attached to. Both source columns
     * (student_lrn, institution_id on student_health_conditions) are plain, so
     * a correlated UPDATE is enough — no decrypt loop needed.
     */
    public function up(): void
    {
        if (! Schema::hasTable('medical_certificates') || ! Schema::hasColumn('medical_certificates', 'student_lrn')) {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE medical_certificates
            SET student_lrn = (
                    SELECT student_lrn FROM student_health_conditions
                    WHERE student_health_conditions.id = medical_certificates.student_health_condition_id
                ),
                institution_id = (
                    SELECT institution_id FROM student_health_conditions
                    WHERE student_health_conditions.id = medical_certificates.student_health_condition_id
                )
            WHERE student_health_condition_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        if (Schema::hasTable('medical_certificates') && Schema::hasColumn('medical_certificates', 'student_lrn')) {
            DB::table('medical_certificates')
                ->whereNotNull('student_health_condition_id')
                ->update(['student_lrn' => null, 'institution_id' => null]);
        }
    }
};
