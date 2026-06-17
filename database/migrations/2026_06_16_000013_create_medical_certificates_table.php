<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_health_condition_id')
                  ->constrained('student_health_conditions')
                  ->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_original_name');
            $table->string('doctor_clinic', 255)->nullable();
            $table->date('diagnosis_date')->nullable();
            $table->string('uploaded_by_name', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_certificates');
    }
};
