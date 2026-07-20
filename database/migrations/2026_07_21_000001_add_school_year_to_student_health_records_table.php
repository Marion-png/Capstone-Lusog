<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_health_records', function (Blueprint $table) {
            if (! Schema::hasColumn('student_health_records', 'school_year')) {
                $table->string('school_year')->nullable()->after('institution_id');
                $table->index(['student_id', 'institution_id', 'school_year'], 'shr_student_institution_year_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('student_health_records', function (Blueprint $table) {
            if (Schema::hasColumn('student_health_records', 'school_year')) {
                $table->dropIndex('shr_student_institution_year_idx');
                $table->dropColumn('school_year');
            }
        });
    }
};
