<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The RxNorm allergy check (App\Support\RxNormService, App\Support\AllergyCheck).
 *
 * RxNav is asked only when a medicine or an allergy is saved, and what it
 * answers is kept here, so the check at dispense time reads this database and
 * nothing else.
 *
 *  - `rx_concepts`: one row per RxNorm concept, shared by medicines and
 *    allergies, with the ATC classes of its ingredients. Not personal data.
 *  - `rx_concept_ingredients`: a product's ingredients (co-amoxiclav is two).
 *  - `learner_medicine_allergies`: one row per recorded medicine allergy,
 *    keyed by LRN like the other learner tables. The text is encrypted.
 *  - `dispense_allergy_checks`: every check made on a dispense, its result and
 *    any override. The reason and the staff name are encrypted.
 *  - `medicines`: the generic name the nurse gave and the concept it matched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rx_concepts')) {
            Schema::create('rx_concepts', function (Blueprint $table) {
                $table->id();
                $table->string('rxcui', 20)->unique();
                $table->string('name');
                $table->string('tty', 20)->nullable();
                $table->json('atc_codes')->nullable();
                $table->timestamp('fetched_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('rx_concept_ingredients')) {
            Schema::create('rx_concept_ingredients', function (Blueprint $table) {
                $table->id();
                $table->foreignId('rx_concept_id')->constrained('rx_concepts')->cascadeOnDelete();
                $table->string('ingredient_rxcui', 20)->index();
                $table->string('ingredient_name');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('learner_medicine_allergies')) {
            Schema::create('learner_medicine_allergies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('institution_id')->nullable()->index();
                $table->string('student_lrn', 20)->index();
                $table->text('entered_text');
                $table->foreignId('rx_concept_id')->nullable()->constrained('rx_concepts')->nullOnDelete();
                $table->string('match_status', 20)->index();
                $table->string('source', 20);
                $table->text('created_by_name')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('dispense_allergy_checks')) {
            Schema::create('dispense_allergy_checks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('institution_id')->nullable()->index();
                $table->foreignId('consultation_id')->nullable()->index();
                $table->foreignId('medicine_id')->nullable()->index();
                $table->string('student_lrn', 20)->nullable()->index();
                $table->string('result', 20);
                $table->foreignId('learner_medicine_allergy_id')->nullable();
                $table->boolean('overridden')->default(false);
                $table->text('override_reason')->nullable();
                $table->text('checked_by_name')->nullable();
                $table->string('checked_by_role', 40)->nullable();
                $table->timestamp('checked_at');
                $table->timestamps();
            });
        }

        if (Schema::hasTable('medicines') && ! Schema::hasColumn('medicines', 'rx_status')) {
            Schema::table('medicines', function (Blueprint $table) {
                $table->string('generic_name', 100)->nullable();
                $table->foreignId('rx_concept_id')->nullable();
                $table->string('rx_status', 20)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('medicines') && Schema::hasColumn('medicines', 'rx_status')) {
            Schema::table('medicines', function (Blueprint $table) {
                $table->dropColumn(['generic_name', 'rx_concept_id', 'rx_status']);
            });
        }

        Schema::dropIfExists('dispense_allergy_checks');
        Schema::dropIfExists('learner_medicine_allergies');
        Schema::dropIfExists('rx_concept_ingredients');
        Schema::dropIfExists('rx_concepts');
    }
};
