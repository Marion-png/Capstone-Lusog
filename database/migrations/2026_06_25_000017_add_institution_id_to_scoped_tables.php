<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // student_health_records: core health data, always belongs to one school
        Schema::table('student_health_records', function (Blueprint $table) {
            $table->foreignId('institution_id')
                ->nullable()
                ->after('id')
                ->constrained('institutions')
                ->nullOnDelete();
        });

        // consultations: clinic visits belong to the school where they occurred
        Schema::table('consultations', function (Blueprint $table) {
            $table->foreignId('institution_id')
                ->nullable()
                ->after('id')
                ->constrained('institutions')
                ->nullOnDelete();
        });

        // medicines: inventory is per-school
        Schema::table('medicines', function (Blueprint $table) {
            $table->foreignId('institution_id')
                ->nullable()
                ->after('id')
                ->constrained('institutions')
                ->nullOnDelete();
        });

        // deworming_requests: submitted by a school's class adviser, reviewed by that school's nurse
        Schema::table('deworming_requests', function (Blueprint $table) {
            $table->foreignId('institution_id')
                ->nullable()
                ->after('id')
                ->constrained('institutions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['student_health_records', 'consultations', 'medicines', 'deworming_requests'] as $tbl) {
            Schema::table($tbl, function (Blueprint $table) {
                $table->dropForeignIdFor(\App\Models\Institution::class);
                $table->dropColumn('institution_id');
            });
        }
    }
};
