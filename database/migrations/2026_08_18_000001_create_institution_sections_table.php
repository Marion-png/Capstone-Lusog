<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sections each school actually runs, by grade level.
 *
 * A class adviser is scoped to one grade and one section, so those two strings
 * decide which learners they may enter and read. Typed free-hand they are a
 * guess: "MATIYAGA", "Matiyaga" and "Matiyaga " are three different classes as
 * far as every roster filter is concerned, and an adviser who mistypes their
 * own section silently gets an empty class they cannot explain. This table is
 * the school's own list, so registration offers a choice instead of a text box.
 *
 * Nothing here is personal information — a section name is school structure, not
 * a fact about a child — so every column is plain and may be used in SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('institution_sections')) {
            return;
        }

        Schema::create('institution_sections', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('institution_id')->index();

            // "Grade 7" … "Grade 10" — the same label the roster stores in the
            // "Grade 7 / MATIYAGA" section string, so a catalogue choice and an
            // adviser's assignment compare directly.
            $table->string('grade_level', 50);
            $table->string('name', 100);

            $table->timestamps();

            // One school never lists the same section twice within a grade.
            $table->unique(['institution_id', 'grade_level', 'name']);

            // The one query this table serves: this school's sections for the
            // grade the registrant just picked.
            $table->index(['institution_id', 'grade_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_sections');
    }
};
