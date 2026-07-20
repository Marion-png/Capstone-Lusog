<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Both source columns (student_id, institution_id on student_health_records)
     * are plain/unencrypted, so a correlated UPDATE is sufficient — no decrypt
     * loop needed.
     */
    public function up(): void
    {
        if (! Schema::hasTable('student_health_conditions') || ! Schema::hasColumn('student_health_conditions', 'student_lrn')) {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE student_health_conditions
            SET student_lrn = (
                    SELECT student_id FROM student_health_records
                    WHERE student_health_records.id = student_health_conditions.student_health_record_id
                ),
                institution_id = (
                    SELECT institution_id FROM student_health_records
                    WHERE student_health_records.id = student_health_conditions.student_health_record_id
                )
            WHERE student_health_record_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        if (Schema::hasTable('student_health_conditions')) {
            DB::table('student_health_conditions')->update(['student_lrn' => null, 'institution_id' => null]);
        }
    }
};
