<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_notes', function (Blueprint $table) {
            $table->id();
            // Lookup/scoping columns stay plain — never queried against
            // ciphertext (see the encryption-at-rest invariant).
            $table->unsignedBigInteger('institution_id')->nullable()->index();
            $table->string('student_lrn')->index();
            $table->string('school_year')->index();
            // Clinical observation and the nurse who signed it: sensitive
            // personal information, encrypted at rest.
            $table->text('note');
            $table->string('author_name');
            $table->date('follow_up_date')->nullable();
            $table->timestamps();

            $table->index(['student_lrn', 'school_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_notes');
    }
};
