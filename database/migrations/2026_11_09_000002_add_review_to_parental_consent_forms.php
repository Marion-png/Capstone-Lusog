<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things an uploaded Sulat-Pahibalo now carries:
 *
 *  - `incomplete`: the form is missing the parent's name or signature, as the
 *    adviser confirmed it against the photo. It is filed, and gives no service
 *    until a complete form is on file (ConsentStanding).
 *  - `scan_review`: for each service and field, the value the photo reader
 *    proposed and the value the adviser saved, so edited fields can be counted
 *    (ConsentReviewLog).
 *
 * Both are about a parent's consent and a child's health, so both are
 * encrypted by the model and never used in SQL. NULL on every older row: a
 * form filed before is read as complete, with no review recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parental_consent_forms', function (Blueprint $table) {
            if (! Schema::hasColumn('parental_consent_forms', 'incomplete')) {
                $table->text('incomplete')->nullable();
            }
            if (! Schema::hasColumn('parental_consent_forms', 'scan_review')) {
                $table->longText('scan_review')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('parental_consent_forms', function (Blueprint $table) {
            $table->dropColumn(['incomplete', 'scan_review']);
        });
    }
};
