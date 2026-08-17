<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The School Head's decision on one report, for one school, for one year.
     *
     * The role reads and decides while other roles write, so this is the only
     * table it owns. A row records that a named person approved, returned or
     * locked a report and when — the report itself is derived from the
     * learners' records at read time and is never stored, because a stored copy
     * would start disagreeing with the data it summarises.
     *
     * One row per (school, year, report): a decision replaces the previous one
     * rather than accumulating drafts, and the audit trail keeps the history of
     * what each decision replaced.
     */
    public function up(): void
    {
        Schema::create('report_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('institution_id')->nullable()->index();
            $table->string('school_year')->index();
            // 'baseline', 'endline', or 'monthly:YYYY-MM'.
            $table->string('report_key')->index();
            // pending / approved / returned / locked
            $table->string('status')->default('pending')->index();

            // A remark names a person and a school's business, so it is
            // personal information and encrypted at rest like every other note
            // in this system.
            $table->text('remarks')->nullable();
            $table->text('reviewed_by_name')->nullable();
            $table->string('reviewed_by_role')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->text('locked_by_name')->nullable();
            $table->timestamp('locked_at')->nullable();

            $table->timestamps();

            $table->unique(['institution_id', 'school_year', 'report_key'], 'report_reviews_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_reviews');
    }
};
