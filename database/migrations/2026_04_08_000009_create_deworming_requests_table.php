<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('deworming_requests')) {
            return;
        }

        Schema::create('deworming_requests', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->timestamp('submitted_at')->nullable()->index();
            $table->string('submitted_by')->nullable();
            $table->string('submitted_by_role')->nullable();
            $table->string('campaign', 20);
            $table->unsignedInteger('total_students');
            $table->unsignedInteger('consenting_students');
            $table->unsignedInteger('tablets_requested');
            $table->string('status', 30)->default('pending');
            $table->date('released_date')->nullable();
            $table->string('grade_level', 50)->nullable()->index();
            $table->string('section', 100)->nullable()->index();
            $table->text('nurse_comment')->nullable();
            $table->timestamp('commented_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reviewed_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deworming_requests');
    }
};
