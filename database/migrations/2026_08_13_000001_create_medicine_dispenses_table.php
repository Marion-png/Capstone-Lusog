<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dispensing Log — one row per issue of medicine to a learner.
 *
 * Until now the clinic could only see a stock level, with no record of where
 * the stock went; MedicineInventoryController had to forecast reorders from
 * sample figures "until dispensing transactions are persisted". This is that
 * table.
 *
 * Column choices follow the encryption invariant: the learner's name and the
 * clinical reason are personal information and are stored encrypted, so they
 * are `text` (ciphertext is far longer than the plaintext) and are never
 * filtered or grouped in SQL. `student_lrn`, `quantity`, `medicine_id` and
 * `institution_id` stay plain on purpose — they are the lookup and
 * aggregation keys the log and the reorder forecast need.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicine_dispenses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('medicine_id')->constrained()->cascadeOnDelete();

            // Plain lookup key, as elsewhere in the system.
            $table->string('student_lrn')->nullable()->index();
            // Encrypted at rest.
            $table->text('student_name');
            $table->text('reason')->nullable();

            $table->unsignedInteger('quantity');

            // Who handed it over. The role is plain so the log can be filtered
            // by desk; the name is personal information and is encrypted.
            $table->text('dispensed_by_name')->nullable();
            $table->string('dispensed_by_role')->nullable();

            $table->timestamp('dispensed_at')->index();

            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            // The log is read newest-first within a school.
            $table->index(['institution_id', 'dispensed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicine_dispenses');
    }
};
