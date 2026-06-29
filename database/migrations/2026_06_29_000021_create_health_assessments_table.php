<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('health_assessments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_health_record_id');
            $table->foreign('student_health_record_id')->references('id')->on('student_health_records')->cascadeOnDelete();
            $table->string('school_year', 20);
            $table->date('date_of_assessment')->nullable();
            $table->string('assessed_by', 255)->nullable();

            // B. Medical History
            $table->boolean('med_asthma')->default(false);
            $table->boolean('med_diabetes')->default(false);
            $table->boolean('med_seizure_disorder')->default(false);
            $table->boolean('med_frequent_infections')->default(false);
            $table->string('med_current_medications', 500)->nullable();
            $table->boolean('med_allergies')->default(false);
            $table->string('med_allergies_detail', 255)->nullable();
            $table->boolean('med_heart_condition')->default(false);
            $table->boolean('med_tuberculosis')->default(false);
            $table->boolean('med_hospitalization_surgery')->default(false);
            $table->string('med_hospitalization_detail', 255)->nullable();
            $table->string('med_other_conditions', 500)->nullable();

            // C. Family History
            $table->boolean('fam_hypertension')->default(false);
            $table->boolean('fam_diabetes')->default(false);
            $table->boolean('fam_heart_disease')->default(false);
            $table->boolean('fam_cancer')->default(false);
            $table->boolean('fam_mental_health')->default(false);
            $table->string('fam_genetic_hereditary', 255)->nullable();

            // D. General Appearance
            $table->string('appearance_consciousness', 50)->nullable();
            $table->string('appearance_consciousness_other', 100)->nullable();
            $table->string('appearance_posture_gait', 50)->nullable();
            $table->string('appearance_posture_detail', 100)->nullable();
            $table->string('appearance_hygiene', 50)->nullable();

            // E. Vital Signs
            $table->decimal('vital_height_cm', 5, 1)->nullable();
            $table->decimal('vital_weight_kg', 5, 2)->nullable();
            $table->decimal('vital_bmi', 5, 2)->nullable();
            $table->decimal('vital_temperature_c', 4, 1)->nullable();
            $table->unsignedSmallInteger('vital_pulse_rate')->nullable();
            $table->string('vital_blood_pressure', 20)->nullable();

            // F. Body Systems (JSON: {system: {findings: [], notes: ""}})
            $table->text('body_systems')->nullable();

            // G. Vision and Hearing
            $table->string('vision_right_eye', 20)->nullable();
            $table->string('vision_left_eye', 20)->nullable();
            $table->string('vision_result', 10)->nullable();
            $table->string('hearing_result', 30)->nullable();

            // H. Oral Health
            $table->text('teeth_condition')->nullable(); // JSON array
            $table->string('last_dental_visit', 100)->nullable();
            $table->boolean('dental_referral')->default(false);

            // I. Immunization Status
            $table->string('immunization_status', 30)->nullable();
            $table->string('missing_needed_vaccines', 500)->nullable();
            $table->date('immunization_date_reviewed')->nullable();

            // J. Assessment Summary
            $table->text('summary_of_findings')->nullable();
            $table->text('recommendations')->nullable();
            $table->string('examiner_signature', 255)->nullable();

            $table->string('submitted_by_name', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_assessments');
    }
};
