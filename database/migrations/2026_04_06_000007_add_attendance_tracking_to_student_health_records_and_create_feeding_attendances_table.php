<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('student_health_records')) {
            Schema::table('student_health_records', function (Blueprint $table) {
                if (!Schema::hasColumn('student_health_records', 'attendance_sessions_count')) {
                    $table->unsignedSmallInteger('attendance_sessions_count')->default(0)->after('endline_recorded_at');
                }
                if (!Schema::hasColumn('student_health_records', 'is_at_risk')) {
                    $table->boolean('is_at_risk')->default(false)->after('attendance_sessions_count');
                }
            });
        }

        if (!Schema::hasTable('feeding_attendances')) {
            Schema::create('feeding_attendances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_health_record_id')->constrained('student_health_records')->cascadeOnDelete();
                $table->date('session_date');
                $table->boolean('is_present')->default(false);
                $table->timestamps();

                $table->unique(['student_health_record_id', 'session_date'], 'feeding_attendance_unique_student_session');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('feeding_attendances')) {
            Schema::dropIfExists('feeding_attendances');
        }

        if (Schema::hasTable('student_health_records')) {
            Schema::table('student_health_records', function (Blueprint $table) {
                $columns = [
                    'attendance_sessions_count',
                    'is_at_risk',
                ];

                foreach ($columns as $column) {
                    if (Schema::hasColumn('student_health_records', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
