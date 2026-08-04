<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen every encrypted column to `text`.
 *
 * Encryption at rest (see the invariant in CLAUDE.md) stores AES-256
 * ciphertext — a base64 string of ~200+ characters — in place of the original
 * value. The original migrations still declared these columns as `boolean`,
 * `numeric`, `smallint`, `json` and `varchar(20..500)`, none of which can hold
 * that ciphertext: the numeric and boolean types reject it outright and the
 * short varchars truncate it.
 *
 * The live databases were widened by hand, so this migration brings a fresh
 * `migrate` run in line with what production actually looks like. It is
 * idempotent — re-running it against an already-widened database is a no-op —
 * so it is safe on both new and existing installs.
 */
return new class extends Migration
{
    /**
     * Boolean flags re-stored as encrypted '1'/'0' strings.
     *
     * These need an explicit USING clause on PostgreSQL: a plain cast yields
     * the strings 'true'/'false', and App\Casts\EncryptedBoolean falls back to
     * `(bool) $value` for legacy plaintext — where `(bool) 'false'` is *true*,
     * silently flipping every cleared flag.
     */
    private const BOOLEAN_COLUMNS = [
        'health_assessments' => [
            'med_asthma', 'med_diabetes', 'med_seizure_disorder', 'med_frequent_infections',
            'med_allergies', 'med_heart_condition', 'med_tuberculosis',
            'med_hospitalization_surgery', 'fam_hypertension', 'fam_diabetes',
            'fam_heart_disease', 'fam_cancer', 'fam_mental_health', 'dental_referral',
        ],
        'parental_consent_forms' => [
            'allergy_food', 'allergy_medicine', 'prev_immunization',
            'has_other_illness', 'medical_cert_attached',
        ],
    ];

    /** Nullable columns widened to text. */
    private const NULLABLE_COLUMNS = [
        'health_assessments' => [
            'med_current_medications', 'med_allergies_detail', 'med_hospitalization_detail',
            'med_other_conditions', 'fam_genetic_hereditary',
            'appearance_consciousness', 'appearance_consciousness_other',
            'appearance_posture_gait', 'appearance_posture_detail', 'appearance_hygiene',
            'vital_height_cm', 'vital_weight_kg', 'vital_bmi',
            'vital_temperature_c', 'vital_pulse_rate', 'vital_blood_pressure',
            'vision_right_eye', 'vision_left_eye', 'vision_result', 'hearing_result',
            'last_dental_visit', 'immunization_status', 'missing_needed_vaccines',
            'examiner_signature',
        ],
        'health_consent_forms' => [
            'student_address', 'parent_guardian_name', 'consent_choice', 'services', 'audit',
        ],
        'medical_certificates' => ['doctor_clinic'],
        'parental_consent_forms' => [
            'allergy_food_detail', 'allergy_medicine_detail', 'prev_immunization_detail',
            'other_illness_detail', 'file_original_name', 'med_cert_original_name',
        ],
        'student_health_records' => [
            'baseline_age', 'baseline_height_cm', 'baseline_weight_kg',
            'baseline_bmi_value', 'baseline_nutritional_status',
            'endline_age', 'endline_height_cm', 'endline_weight_kg',
            'endline_bmi_value', 'endline_nutritional_status',
            'examination', 'attendance_by_month',
        ],
    ];

    /** NOT NULL columns widened to text. */
    private const REQUIRED_COLUMNS = [
        'clinic_notes' => ['author_name'],
        'consultations' => ['student_name', 'grade_section', 'condition'],
        'health_consent_forms' => ['student_name'],
        'medical_certificates' => ['file_original_name'],
        'student_health_conditions' => ['condition_name'],
        'student_health_records' => ['student_name', 'weight', 'bmi_value', 'nutritional_status'],
    ];

    public function up(): void
    {
        foreach (self::BOOLEAN_COLUMNS as $table => $columns) {
            foreach ($this->present($table, $columns) as $column) {
                $this->widenBoolean($table, $column);
            }
        }

        foreach (self::NULLABLE_COLUMNS as $table => $columns) {
            $this->widen($table, $columns, nullable: true);
        }

        foreach (self::REQUIRED_COLUMNS as $table => $columns) {
            $this->widen($table, $columns, nullable: false);
        }

        // Carries a default, so it cannot ride along with the plain NOT NULL set.
        if ($this->present('parental_consent_forms', ['consent_type']) !== []) {
            Schema::table('parental_consent_forms', function (Blueprint $table) {
                $table->text('consent_type')->default('full')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        // Deliberately irreversible. Narrowing these columns back to boolean,
        // numeric or varchar(n) would reject or truncate the ciphertext they
        // now hold, destroying data. Leaving them as text after a rollback is
        // harmless — text is a superset of every type they came from.
    }

    /** Filter a column list down to the ones that actually exist. */
    private function present(string $table, array $columns): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return array_values(array_filter(
            $columns,
            fn (string $column) => Schema::hasColumn($table, $column)
        ));
    }

    private function widen(string $table, array $columns, bool $nullable): void
    {
        $columns = $this->present($table, $columns);
        if ($columns === []) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $nullable) {
            foreach ($columns as $column) {
                $blueprint->text($column)->nullable($nullable)->change();
            }
        });
    }

    private function widenBoolean(string $table, string $column): void
    {
        // Already widened (the live databases were converted by hand). Bail
        // out before the USING clause below, which only type-checks against a
        // column that is still boolean.
        if (Schema::getColumnType($table, $column) === 'text') {
            return;
        }

        if (DB::getDriverName() !== 'pgsql') {
            // SQLite and MySQL store booleans as 0/1, which already cast to
            // the '0'/'1' strings the EncryptedBoolean cast expects.
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->text($column)->default('0')->nullable(false)->change();
            });

            return;
        }

        $table = '"'.$table.'"';
        $column = '"'.$column.'"';

        // Drop the default first: `false` has no automatic cast to text, so
        // PostgreSQL would refuse to convert the column while it is attached.
        DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} DROP DEFAULT");
        DB::statement(
            "ALTER TABLE {$table} ALTER COLUMN {$column} TYPE text "
            ."USING (CASE WHEN {$column} THEN '1' ELSE '0' END)"
        );
        DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} SET DEFAULT '0'");
        DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} SET NOT NULL");
    }
};
