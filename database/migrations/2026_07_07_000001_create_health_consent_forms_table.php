<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_consent_forms', function (Blueprint $table) {
            $table->id();
            $table->string('token')->nullable()->unique();
            $table->string('school_year');
            $table->unsignedBigInteger('institution_id')->nullable()->index();

            // Form header (auto-populated)
            $table->string('division');
            $table->string('school_name');
            $table->string('school_address');

            // Student information (auto-populated from the health card record)
            $table->string('student_lrn')->index();
            $table->string('student_name');
            $table->string('student_address')->nullable();
            $table->string('grade_level')->nullable();
            $table->string('section')->nullable();
            $table->string('parent_guardian_name')->nullable();

            // Adviser section: selected health services (array of service keys)
            $table->json('services')->nullable();

            $table->string('status')->default('draft')->index();

            // Parent consent section
            $table->string('consent_choice')->nullable();     // all | specific | deny
            $table->text('consent_exceptions')->nullable();   // write-in for "specific"
            $table->text('refusal_reason')->nullable();       // required for "deny"
            $table->text('allergy_food')->nullable();
            $table->text('allergy_medicine')->nullable();
            $table->text('prev_immunization')->nullable();
            $table->text('other_illness')->nullable();
            $table->longText('signature')->nullable();        // base64 PNG data URL

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->string('created_by_name')->nullable();
            $table->boolean('adviser_unread')->default(false);
            $table->json('audit')->nullable();

            $table->timestamps();

            $table->unique(['student_lrn', 'school_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_consent_forms');
    }
};
