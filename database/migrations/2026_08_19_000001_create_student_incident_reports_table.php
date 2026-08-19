<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incident reports a class adviser files against one learner.
 *
 * Keyed by the plain `student_lrn` + `institution_id` pair, the same way
 * medical certificates are, so a report can be found without touching an
 * encrypted column. Everything a person wrote — what happened, what was
 * done, where, who filed it — is personal information about a child and is
 * encrypted at rest by the model's casts, so those columns are `text`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_incident_reports', function (Blueprint $table) {
            $table->id();

            // Lookup keys, deliberately plain — see the encryption invariant.
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('student_lrn', 50);
            $table->string('school_year', 20);

            // The date is a lookup key too: the list is ordered by it, and an
            // encrypted column cannot be sorted in SQL.
            $table->date('occurred_at');

            // A fixed catalogue, not free text, so the list can be filtered
            // and a report cannot be filed under a category nobody recognises.
            $table->string('category', 40);
            $table->string('severity', 20);

            // Written by a person, about a child. Encrypted.
            $table->text('location')->nullable();
            $table->text('description');
            $table->text('action_taken')->nullable();
            $table->text('witnesses')->nullable();

            // Who filed it. A staff name is personal information; the role is
            // not, and the list groups by it.
            $table->text('reported_by_name')->nullable();
            $table->string('reported_by_role', 40)->nullable();

            // Whether the parent or guardian was told, and when.
            $table->boolean('guardian_notified')->default(false);

            $table->timestamps();

            $table->index(['institution_id', 'student_lrn']);
            $table->index(['institution_id', 'school_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_incident_reports');
    }
};
