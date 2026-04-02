<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->dateTime('consulted_at');
            $table->string('student_name');
            $table->string('grade_section');
            $table->string('condition');
            $table->text('treatment_given')->nullable();
            $table->string('status', 20)->default('treated');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultations');
    }
};
