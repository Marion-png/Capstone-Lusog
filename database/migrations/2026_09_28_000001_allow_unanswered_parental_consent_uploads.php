<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A filed consent scan need not say what the parent answered.
 *
 * The Upload Consent dialog used to ask the adviser to re-declare the parent's
 * choice — Full / Partial / Declined — beside the scan of the signed form. That
 * question is now asked once, on Record Signed Paper Form, where the adviser
 * reads the sheet field by field and attests to it; two screens asking the same
 * question is how they come to disagree about what a parent agreed to.
 *
 * So the upload dialog files the document and nothing more, and `consent_type`
 * has to be able to say "nobody recorded an answer with this". That reads as
 * **pending** everywhere it is consumed (`deriveConsentStatus()` already ends
 * in `default => 'pending'`), and `NurseController::saveExamination` refuses
 * deworming on it exactly as it refuses an explicit refusal: a document on file
 * is not the same claim as a parent's agreement.
 *
 * Existing rows are untouched — every one of them carries the answer the
 * adviser gave at the time, and that is still the best record of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parental_consent_forms', function (Blueprint $table) {
            $table->string('consent_type')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('parental_consent_forms', function (Blueprint $table) {
            $table->string('consent_type')->nullable(false)->change();
        });
    }
};
