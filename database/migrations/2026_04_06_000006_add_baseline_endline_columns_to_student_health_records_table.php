<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('student_health_records')) {
            return;
        }

        Schema::table('student_health_records', function (Blueprint $table) {
            if (!Schema::hasColumn('student_health_records', 'baseline_age')) {
                $table->unsignedTinyInteger('baseline_age')->nullable()->after('nutritional_status');
            }
            if (!Schema::hasColumn('student_health_records', 'baseline_height_cm')) {
                $table->decimal('baseline_height_cm', 6, 2)->nullable()->after('baseline_age');
            }
            if (!Schema::hasColumn('student_health_records', 'baseline_weight_kg')) {
                $table->decimal('baseline_weight_kg', 6, 2)->nullable()->after('baseline_height_cm');
            }
            if (!Schema::hasColumn('student_health_records', 'baseline_bmi_value')) {
                $table->decimal('baseline_bmi_value', 6, 2)->nullable()->after('baseline_weight_kg');
            }
            if (!Schema::hasColumn('student_health_records', 'baseline_nutritional_status')) {
                $table->string('baseline_nutritional_status')->nullable()->after('baseline_bmi_value');
            }
            if (!Schema::hasColumn('student_health_records', 'baseline_recorded_at')) {
                $table->date('baseline_recorded_at')->nullable()->after('baseline_nutritional_status');
            }

            if (!Schema::hasColumn('student_health_records', 'endline_age')) {
                $table->unsignedTinyInteger('endline_age')->nullable()->after('baseline_recorded_at');
            }
            if (!Schema::hasColumn('student_health_records', 'endline_height_cm')) {
                $table->decimal('endline_height_cm', 6, 2)->nullable()->after('endline_age');
            }
            if (!Schema::hasColumn('student_health_records', 'endline_weight_kg')) {
                $table->decimal('endline_weight_kg', 6, 2)->nullable()->after('endline_height_cm');
            }
            if (!Schema::hasColumn('student_health_records', 'endline_bmi_value')) {
                $table->decimal('endline_bmi_value', 6, 2)->nullable()->after('endline_weight_kg');
            }
            if (!Schema::hasColumn('student_health_records', 'endline_nutritional_status')) {
                $table->string('endline_nutritional_status')->nullable()->after('endline_bmi_value');
            }
            if (!Schema::hasColumn('student_health_records', 'endline_recorded_at')) {
                $table->date('endline_recorded_at')->nullable()->after('endline_nutritional_status');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('student_health_records')) {
            return;
        }

        Schema::table('student_health_records', function (Blueprint $table) {
            $columns = [
                'baseline_age',
                'baseline_height_cm',
                'baseline_weight_kg',
                'baseline_bmi_value',
                'baseline_nutritional_status',
                'baseline_recorded_at',
                'endline_age',
                'endline_height_cm',
                'endline_weight_kg',
                'endline_bmi_value',
                'endline_nutritional_status',
                'endline_recorded_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('student_health_records', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
