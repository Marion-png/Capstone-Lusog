<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A note on the visit, written where the visit is recorded.
 *
 * The nurse's profile used to carry a separate Clinic Notes tab — a note
 * filed against a learner, keyed by LRN, beside the consultations rather than
 * on them. It was a second place to write about the same visit, and the
 * comment about a visit belongs on that visit: so the New Consultation dialog
 * now takes the note, and the profile's Consultation Log tab shows it under
 * the consultation it was written on. The old `clinic_notes` rows are kept
 * and still readable on the adviser's profile.
 *
 * A note is clinical text about a child, so it is encrypted at rest by the
 * model's cast and the column is `text`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            if (! Schema::hasColumn('consultations', 'notes')) {
                $table->text('notes')->nullable()->after('treatment_given');
            }
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
