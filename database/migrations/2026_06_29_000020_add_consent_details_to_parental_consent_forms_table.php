<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parental_consent_forms', function (Blueprint $table) {
            // consent_type: full | partial | refused
            $table->string('consent_type', 20)->default('full')->after('school_year');
            $table->text('partial_exception')->nullable()->after('consent_type');
            $table->text('refused_reason')->nullable()->after('partial_exception');

            // allergy info
            $table->boolean('allergy_food')->default(false)->after('refused_reason');
            $table->string('allergy_food_detail', 255)->nullable()->after('allergy_food');
            $table->boolean('allergy_medicine')->default(false)->after('allergy_food_detail');
            $table->string('allergy_medicine_detail', 255)->nullable()->after('allergy_medicine');
            $table->boolean('prev_immunization')->default(false)->after('allergy_medicine_detail');
            $table->string('prev_immunization_detail', 255)->nullable()->after('prev_immunization');

            // other illness / medical cert
            $table->boolean('has_other_illness')->default(false)->after('prev_immunization_detail');
            $table->string('other_illness_detail', 255)->nullable()->after('has_other_illness');
            $table->boolean('medical_cert_attached')->default(false)->after('other_illness_detail');

            // file is now optional (scanned proof)
            $table->string('file_path')->nullable()->change();
            $table->string('file_original_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('parental_consent_forms', function (Blueprint $table) {
            $table->dropColumn([
                'consent_type',
                'partial_exception',
                'refused_reason',
                'allergy_food',
                'allergy_food_detail',
                'allergy_medicine',
                'allergy_medicine_detail',
                'prev_immunization',
                'prev_immunization_detail',
                'has_other_illness',
                'other_illness_detail',
                'medical_cert_attached',
            ]);
            $table->string('file_path')->nullable(false)->change();
            $table->string('file_original_name')->nullable(false)->change();
        });
    }
};
