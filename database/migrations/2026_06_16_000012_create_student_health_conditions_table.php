<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_health_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_health_record_id')
                  ->constrained('student_health_records')
                  ->cascadeOnDelete();
            $table->string('condition_name', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_health_conditions');
    }
};
