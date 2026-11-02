<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The services a "yes, except" consent left out, as the class adviser marked
 * them when confirming an upload.
 *
 * The exceptions are the parent's handwriting, so an uploaded partial consent
 * could only ever read "Check the exceptions" for every service. Recording the
 * excepted services beside the ones the letter lists lets each service read
 * approved or not approved. Encrypted through the model's cast like the rest of
 * the parent's answers; NULL keeps the old reading for every row filed before
 * this.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('parental_consent_forms') || Schema::hasColumn('parental_consent_forms', 'excluded_services')) {
            return;
        }

        Schema::table('parental_consent_forms', function (Blueprint $table) {
            $table->text('excluded_services')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('parental_consent_forms') && Schema::hasColumn('parental_consent_forms', 'excluded_services')) {
            Schema::table('parental_consent_forms', function (Blueprint $table) {
                $table->dropColumn('excluded_services');
            });
        }
    }
};
