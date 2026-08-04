<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Encrypted-at-rest columns must be text.
 *
 * These columns hold AES-256 ciphertext (see the EncryptedString / EncryptedArray /
 * EncryptedBoolean casts), but they were declared as numeric / smallint / boolean /
 * json / varchar(n) back when the app ran on SQLite. SQLite is dynamically typed and
 * ignores varchar lengths, so it happily stored base64 ciphertext in a "decimal(8,2)"
 * or a "varchar(20)". PostgreSQL enforces both, and rejects the same writes with
 * SQLSTATE[22P02] "invalid input syntax" and SQLSTATE[22001] "value too long".
 *
 * The length caps matter even at varchar(255): encrypting a short string yields
 * roughly 250 characters of base64, so any real value overflows.
 *
 * Nothing is lost by widening them: the encryption invariant already forbids using
 * any of these columns in a WHERE / ORDER BY / aggregate, so their SQL type carries
 * no weight — filtering and arithmetic happen in PHP after decryption.
 */
return new class extends Migration
{
    /**
     * Nullable numeric-ish columns; plain ::text cast preserves any legacy plaintext.
     */
    private const NUMERIC_COLUMNS = [
        'student_health_records' => [
            'weight', 'bmi_value',
            'baseline_height_cm', 'baseline_weight_kg', 'baseline_bmi_value', 'baseline_age',
            'endline_height_cm', 'endline_weight_kg', 'endline_bmi_value', 'endline_age',
        ],
        'health_assessments' => [
            'vital_height_cm', 'vital_weight_kg', 'vital_bmi',
            'vital_temperature_c', 'vital_pulse_rate',
        ],
    ];

    /**
     * json columns holding an encrypted JSON payload.
     */
    private const JSON_COLUMNS = [
        'student_health_records' => ['attendance_by_month', 'examination'],
        'health_consent_forms' => ['services', 'audit'],
    ];

    /**
     * NOT NULL DEFAULT false booleans. EncryptedBoolean::get() falls back to
     * (bool) $value when a value is not decryptable, so the plaintext default has
     * to be '0' — the string 'false' would read back as true.
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

    /**
     * Length-capped varchar columns, mapped to the cap they had, so down() can
     * put it back.
     */
    private const VARCHAR_COLUMNS = [
        'clinic_notes' => ['author_name' => 255],
        'consultations' => [
            'condition' => 255, 'grade_section' => 255, 'student_name' => 255,
        ],
        'health_assessments' => [
            'appearance_consciousness' => 50, 'appearance_consciousness_other' => 100,
            'appearance_hygiene' => 50, 'appearance_posture_detail' => 100,
            'appearance_posture_gait' => 50, 'examiner_signature' => 255,
            'fam_genetic_hereditary' => 255, 'hearing_result' => 30,
            'immunization_status' => 30, 'last_dental_visit' => 100,
            'med_allergies_detail' => 255, 'med_current_medications' => 500,
            'med_hospitalization_detail' => 255, 'med_other_conditions' => 500,
            'missing_needed_vaccines' => 500, 'vision_left_eye' => 20,
            'vision_result' => 10, 'vision_right_eye' => 20,
            'vital_blood_pressure' => 20,
        ],
        'health_consent_forms' => [
            'consent_choice' => 255, 'parent_guardian_name' => 255,
            'student_address' => 255, 'student_name' => 255,
        ],
        'medical_certificates' => ['doctor_clinic' => 255, 'file_original_name' => 255],
        'parental_consent_forms' => [
            'allergy_food_detail' => 255, 'allergy_medicine_detail' => 255,
            'consent_type' => 20, 'file_original_name' => 255,
            'med_cert_original_name' => 255, 'other_illness_detail' => 255,
            'prev_immunization_detail' => 255,
        ],
        'student_health_conditions' => ['condition_name' => 255],
        'student_health_records' => [
            'baseline_nutritional_status' => 255, 'endline_nutritional_status' => 255,
            'nutritional_status' => 255, 'student_name' => 255,
        ],
    ];

    public function up(): void
    {
        foreach (self::VARCHAR_COLUMNS as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                DB::statement("alter table \"{$table}\" alter column \"{$column}\" type text");
            }
        }

        foreach (self::NUMERIC_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("alter table \"{$table}\" alter column \"{$column}\" type text using \"{$column}\"::text");
            }
        }

        foreach (self::JSON_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("alter table \"{$table}\" alter column \"{$column}\" type text using \"{$column}\"::text");
            }
        }

        foreach (self::BOOLEAN_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                // The boolean default has to go before the type change, or Postgres
                // tries to coerce `false` into the new text type.
                DB::statement("alter table \"{$table}\" alter column \"{$column}\" drop default");
                DB::statement("alter table \"{$table}\" alter column \"{$column}\" type text using (case when \"{$column}\" then '1' else '0' end)");
                DB::statement("alter table \"{$table}\" alter column \"{$column}\" set default '0'");
            }
        }
    }

    /**
     * Reversing only succeeds while the columns still hold plaintext. Once real
     * ciphertext is in them the cast back fails loudly, which is the honest
     * outcome — narrowing the type would otherwise destroy the encrypted data.
     */
    public function down(): void
    {
        foreach (self::VARCHAR_COLUMNS as $table => $columns) {
            foreach ($columns as $column => $length) {
                DB::statement("alter table \"{$table}\" alter column \"{$column}\" type varchar({$length})");
            }
        }

        foreach (self::NUMERIC_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $type = str_ends_with($column, '_age') || $column === 'vital_pulse_rate' ? 'smallint' : 'numeric';
                DB::statement("alter table \"{$table}\" alter column \"{$column}\" type {$type} using nullif(\"{$column}\", '')::{$type}");
            }
        }

        foreach (self::JSON_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("alter table \"{$table}\" alter column \"{$column}\" type json using nullif(\"{$column}\", '')::json");
            }
        }

        foreach (self::BOOLEAN_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("alter table \"{$table}\" alter column \"{$column}\" drop default");
                DB::statement("alter table \"{$table}\" alter column \"{$column}\" type boolean using (\"{$column}\" = '1')");
                DB::statement("alter table \"{$table}\" alter column \"{$column}\" set default false");
            }
        }
    }
};
