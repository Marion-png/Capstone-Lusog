<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time data migration: encrypt personal and sensitive personal
 * information that was stored in plaintext before encryption at rest was
 * introduced. New rows are encrypted automatically by the model casts;
 * this brings pre-existing rows up to the same standard.
 */
return new class extends Migration
{
    /** table => plain string/JSON columns to encrypt in place */
    private const STRING_COLUMNS = [
        'student_health_records' => [
            'student_name', 'weight', 'bmi_value', 'nutritional_status',
            'baseline_age', 'baseline_height_cm', 'baseline_weight_kg',
            'baseline_bmi_value', 'baseline_nutritional_status',
            'endline_age', 'endline_height_cm', 'endline_weight_kg',
            'endline_bmi_value', 'endline_nutritional_status',
            'examination', 'attendance_by_month',
        ],
        'consultations' => ['student_name', 'grade_section', 'condition', 'treatment_given'],
        'student_health_conditions' => ['condition_name'],
        'medical_certificates' => ['doctor_clinic', 'file_original_name'],
        'parental_consent_forms' => [
            'consent_type', 'partial_exception', 'refused_reason',
            'allergy_food_detail', 'allergy_medicine_detail',
            'prev_immunization_detail', 'other_illness_detail', 'file_original_name',
        ],
        'health_assessments' => [
            'med_current_medications', 'med_allergies_detail', 'med_hospitalization_detail',
            'med_other_conditions', 'fam_genetic_hereditary',
            'appearance_consciousness', 'appearance_consciousness_other',
            'appearance_posture_gait', 'appearance_posture_detail', 'appearance_hygiene',
            'vital_height_cm', 'vital_weight_kg', 'vital_bmi',
            'vital_temperature_c', 'vital_pulse_rate', 'vital_blood_pressure',
            'vision_right_eye', 'vision_left_eye', 'vision_result', 'hearing_result',
            'last_dental_visit', 'immunization_status', 'missing_needed_vaccines',
            'summary_of_findings', 'recommendations', 'examiner_signature',
            'body_systems', 'teeth_condition',
        ],
        'health_consent_forms' => [
            'student_name', 'student_address', 'parent_guardian_name',
            'consent_choice', 'consent_exceptions', 'refusal_reason',
            'allergy_food', 'allergy_medicine', 'prev_immunization', 'other_illness',
            'signature', 'services', 'audit',
        ],
    ];

    /** table => boolean flag columns, re-stored as encrypted '1'/'0' */
    private const BOOLEAN_COLUMNS = [
        'parental_consent_forms' => [
            'allergy_food', 'allergy_medicine', 'prev_immunization',
            'has_other_illness', 'medical_cert_attached',
        ],
        'health_assessments' => [
            'med_asthma', 'med_diabetes', 'med_seizure_disorder', 'med_frequent_infections',
            'med_allergies', 'med_heart_condition', 'med_tuberculosis',
            'med_hospitalization_surgery', 'fam_hypertension', 'fam_diabetes',
            'fam_heart_disease', 'fam_cancer', 'fam_mental_health', 'dental_referral',
        ],
    ];

    public function up(): void
    {
        foreach (self::STRING_COLUMNS as $table => $columns) {
            $this->transform($table, $columns, function (string $value): ?string {
                if ($this->isEncrypted($value)) {
                    return null; // already encrypted, leave untouched
                }

                return Crypt::encryptString($value);
            });
        }

        foreach (self::BOOLEAN_COLUMNS as $table => $columns) {
            $this->transform($table, $columns, function (string $value): ?string {
                if ($this->isEncrypted($value)) {
                    return null;
                }

                return Crypt::encryptString($value === '1' || strtolower($value) === 'true' ? '1' : '0');
            });
        }
    }

    public function down(): void
    {
        $decrypt = function (string $value): ?string {
            try {
                return Crypt::decryptString($value);
            } catch (DecryptException) {
                return null; // already plaintext
            }
        };

        foreach (array_merge_recursive(self::STRING_COLUMNS, self::BOOLEAN_COLUMNS) as $table => $columns) {
            $this->transform($table, array_unique($columns), $decrypt);
        }
    }

    /** Apply $callback to every non-null value; null result means "skip". */
    private function transform(string $table, array $columns, callable $callback): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $columns = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c)));
        if ($columns === []) {
            return;
        }

        DB::table($table)->select(array_merge(['id'], $columns))->orderBy('id')->chunk(100, function ($rows) use ($table, $columns, $callback) {
            foreach ($rows as $row) {
                $updates = [];
                foreach ($columns as $column) {
                    $value = $row->{$column};
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $transformed = $callback((string) $value);
                    if ($transformed !== null) {
                        $updates[$column] = $transformed;
                    }
                }
                if ($updates !== []) {
                    DB::table($table)->where('id', $row->id)->update($updates);
                }
            }
        });
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
