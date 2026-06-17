<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parental_consent_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_health_record_id')
                  ->constrained('student_health_records')
                  ->cascadeOnDelete();
            $table->string('program_type', 50)->default('Deworming');
            $table->string('school_year', 9); // e.g. "2025-2026"
            $table->string('file_path');
            $table->string('file_original_name');
            $table->string('uploaded_by_name', 255);
            $table->timestamps();

            $table->index(['student_health_record_id', 'program_type', 'school_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parental_consent_forms');
    }
};
