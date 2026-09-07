<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One identifying photograph per learner, for their profile.
 *
 * Keyed by the plain `student_lrn` + `institution_id` pair, like medical
 * certificates and incident reports, so the photo belongs to the learner and
 * carries across grade promotion rather than being tied to one school year.
 * A learner has one current photo; re-uploading replaces it.
 *
 * A photograph of a child's face is sensitive personal information — arguably
 * the most identifying field in the whole record — so the file itself goes
 * through EncryptedFileStorage and the original filename, which often carries
 * the learner's own name, is encrypted here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_photos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('student_lrn', 50);

            $table->string('file_path');
            $table->text('file_original_name');
            $table->unsignedBigInteger('file_size')->default(0);

            $table->text('uploaded_by_name')->nullable();
            $table->string('uploaded_by_role', 40)->nullable();

            $table->timestamps();

            // One current photo per learner per school.
            $table->unique(['institution_id', 'student_lrn']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_photos');
    }
};
