<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The services an uploaded Sulat-Pahibalo lists, as the class adviser ticked
 * them off the paper.
 *
 * The printed letter lists every health service the school will give, and the
 * parent's answer is an answer to that list. An upload used to record only the
 * answer, so it was read as covering the one program it was filed under
 * (Deworming) and a full consent to nine services showed as one. Encrypted
 * through the model's cast like the rest of the parent's answers; NULL keeps
 * the old reading for every row filed before this.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('parental_consent_forms') || Schema::hasColumn('parental_consent_forms', 'services')) {
            return;
        }

        Schema::table('parental_consent_forms', function (Blueprint $table) {
            $table->text('services')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('parental_consent_forms') && Schema::hasColumn('parental_consent_forms', 'services')) {
            Schema::table('parental_consent_forms', function (Blueprint $table) {
                $table->dropColumn('services');
            });
        }
    }
};
