<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_health_records', function (Blueprint $table) {
            $table->id();
            $table->string('student_name');
            $table->string('student_id');
            $table->string('section');
            $table->decimal('weight', 6, 2);
            $table->decimal('bmi_value', 6, 2);
            $table->string('nutritional_status');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_health_records');
    }
};
