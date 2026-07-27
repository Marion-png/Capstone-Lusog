<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('institution_id')->nullable()->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('event_date')->index();
            $table->string('category')->default('program');
            $table->string('created_by_name');
            $table->string('created_by_role');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
