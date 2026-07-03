<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_health_records', function (Blueprint $table) {
            if (!Schema::hasColumn('student_health_records', 'examination')) {
                $table->json('examination')->nullable()->after('endline_recorded_at');
            }
            if (!Schema::hasColumn('student_health_records', 'attendance_by_month')) {
                $table->json('attendance_by_month')->nullable()->after('examination');
            }
        });
    }

    public function down(): void
    {
        Schema::table('student_health_records', function (Blueprint $table) {
            if (Schema::hasColumn('student_health_records', 'attendance_by_month')) {
                $table->dropColumn('attendance_by_month');
            }
            if (Schema::hasColumn('student_health_records', 'examination')) {
                $table->dropColumn('examination');
            }
        });
    }
};
