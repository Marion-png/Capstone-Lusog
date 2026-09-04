<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photographs taken at the clinic during a consultation — a cut, a rash, a
 * swelling — so the injury can be seen rather than described.
 *
 * A photograph of a child's injury is sensitive personal information, more
 * so than the text beside it, so the file itself is written through
 * EncryptedFileStorage and everything a person typed about it is encrypted
 * here. Only `consultation_id`, `institution_id` and the share flag stay
 * plain, because those are the lookup keys.
 *
 * `shared_with_adviser` is the whole reason this table has a flag at all:
 * consultation detail does not reach the class adviser (see
 * App\Support\ConsultationVisibility), so a photo is clinic-only unless the
 * nurse deliberately decides the learner's teacher needs to see it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_photos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();

            // The encrypted file on the private disk.
            $table->string('file_path');
            $table->text('file_original_name');
            $table->unsignedBigInteger('file_size')->default(0);

            // What the nurse wants the reader to notice. Encrypted: it
            // describes a child's injury.
            $table->text('caption')->nullable();

            // Clinic-only until the nurse says otherwise.
            $table->boolean('shared_with_adviser')->default(false);

            $table->text('uploaded_by_name')->nullable();
            $table->string('uploaded_by_role', 40)->nullable();

            $table->timestamps();

            $table->index(['institution_id', 'consultation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_photos');
    }
};
