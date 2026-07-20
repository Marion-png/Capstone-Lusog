<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_health_conditions', function (Blueprint $table) {
            if (! Schema::hasColumn('student_health_conditions', 'student_lrn')) {
                $table->string('student_lrn')->nullable()->after('student_health_record_id');
            }
            if (! Schema::hasColumn('student_health_conditions', 'institution_id')) {
                $table->unsignedBigInteger('institution_id')->nullable()->after('student_lrn');
            }
        });

        Schema::table('student_health_conditions', function (Blueprint $table) {
            $table->index(['student_lrn', 'institution_id'], 'shc_student_institution_idx');
        });
    }

    public function down(): void
    {
        Schema::table('student_health_conditions', function (Blueprint $table) {
            $table->dropIndex('shc_student_institution_idx');
            $table->dropColumn(['student_lrn', 'institution_id']);
        });
    }
};
