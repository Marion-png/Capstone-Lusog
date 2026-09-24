<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the clinic shared this visit's note with the learner's class adviser.
 *
 * The note itself is clinical text and stops at the clinic, exactly as the
 * complaint and the treatment do (App\Support\ConsultationVisibility). But a
 * nurse often *wants* the adviser to know something — watch for dizziness
 * this afternoon, no PE for a week — and the app already has the shape for
 * that: a photograph is clinic-only until the nurse marks it shared, per
 * photo, reversibly. This is the same decision for the note.
 *
 * The flag is not personal information — it says nothing about the child —
 * so it stays plain and may be used in SQL. The note it governs remains
 * encrypted by the model's cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            if (! Schema::hasColumn('consultations', 'notes_shared_with_adviser')) {
                $table->boolean('notes_shared_with_adviser')->default(false)->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropColumn('notes_shared_with_adviser');
        });
    }
};
