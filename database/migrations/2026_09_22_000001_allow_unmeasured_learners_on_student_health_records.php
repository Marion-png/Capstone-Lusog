<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A learner can be on the roll before anybody has weighed them.
 *
 * `weight`, `bmi_value` and `nutritional_status` have been NOT NULL since the
 * table was created, which was right while the only way onto the roll was the School
 * Health Card form — that form asks for height and weight, so every learner
 * arrived measured.
 *
 * The class masterlist does not. A DepEd CLASS MASTERLIST carries NO, the
 * learner's name, their LRN and a remarks column, and nothing else; it is the
 * document a school actually holds at the start of a year, and an adviser
 * importing it has no height or weight to give. With these columns NOT NULL
 * the only ways to accept that sheet were to refuse it or to write a made-up
 * figure — and a fabricated weight does not stay in this column. It is read by
 * BmiClassifier, becomes a nutritional status, decides whether the learner
 * qualifies for the feeding programme, and is counted in every DepEd BMI grid
 * the School Head exports. "0 kg, Severely Wasted" would be a child the
 * programme feeds on the strength of a measurement nobody took.
 *
 * So the column becomes nullable and NULL means exactly what it says: not
 * measured. That is the reading the rest of the app already expects — the
 * School Head's overview counts an absent status as "Not measured" rather than
 * Normal, the Feeding Program roster prints an em dash and "No baseline", and
 * StudentDataCompleteness already lists Height and Weight among the fields an
 * adviser still has to enter.
 *
 * Nothing is backfilled and nothing is dropped: every existing row keeps the
 * value it has. The change is only that a new row no longer has to invent one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('student_health_records')) {
            return;
        }

        // Declared as `text`, which is what these columns already are.
        //
        // They were widened from numeric and varchar by
        // 2026_08_04_000001_align_encrypted_columns_with_text_storage, because
        // they hold AES ciphertext rather than a number. Restating them here
        // as decimal/string would be asking PostgreSQL to cast that ciphertext
        // back to numeric, which it refuses outright:
        //
        //   column "weight" cannot be cast automatically to type numeric
        //
        // SQLite types columns loosely and accepts it, so this only fails
        // against the real database. The type must stay exactly as it is; the
        // one thing being changed is the NOT NULL.
        Schema::table('student_health_records', function (Blueprint $table) {
            $table->text('weight')->nullable()->change();
            // The BMI is computed from the other two, so it is as absent as
            // they are: there is no such thing as the BMI of a learner nobody
            // has weighed.
            $table->text('bmi_value')->nullable()->change();
            $table->text('nutritional_status')->nullable()->change();
        });
    }

    /**
     * Deliberately not reversed.
     *
     * Going back to NOT NULL would fail against any row this change allowed —
     * every learner imported from a masterlist and not yet weighed — so the
     * down path would either error or force a made-up weight onto a real
     * child's record. Widening a column is the kind of change that is meant to
     * stay widened.
     */
    public function down(): void
    {
        // No-op. See the note above.
    }
};
