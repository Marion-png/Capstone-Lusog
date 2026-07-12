<?php

namespace App\Models;

use App\Casts\EncryptedArray;
use App\Casts\EncryptedBoolean;
use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class HealthAssessment extends Model
{
    use Auditable;

    protected $fillable = [
        'student_health_record_id', 'school_year', 'date_of_assessment', 'assessed_by',
        // Medical history
        'med_asthma', 'med_diabetes', 'med_seizure_disorder', 'med_frequent_infections',
        'med_current_medications', 'med_allergies', 'med_allergies_detail',
        'med_heart_condition', 'med_tuberculosis', 'med_hospitalization_surgery',
        'med_hospitalization_detail', 'med_other_conditions',
        // Family history
        'fam_hypertension', 'fam_diabetes', 'fam_heart_disease', 'fam_cancer',
        'fam_mental_health', 'fam_genetic_hereditary',
        // General appearance
        'appearance_consciousness', 'appearance_consciousness_other',
        'appearance_posture_gait', 'appearance_posture_detail', 'appearance_hygiene',
        // Vital signs
        'vital_height_cm', 'vital_weight_kg', 'vital_bmi',
        'vital_temperature_c', 'vital_pulse_rate', 'vital_blood_pressure',
        // Body systems (JSON)
        'body_systems',
        // Vision and hearing
        'vision_right_eye', 'vision_left_eye', 'vision_result', 'hearing_result',
        // Oral health
        'teeth_condition', 'last_dental_visit', 'dental_referral',
        // Immunization
        'immunization_status', 'missing_needed_vaccines', 'immunization_date_reviewed',
        // Summary
        'summary_of_findings', 'recommendations', 'examiner_signature',
        'submitted_by_name',
    ];

    /**
     * The entire medical/family history and examination payload is encrypted
     * at rest. Only student_health_record_id and school_year stay plain —
     * they are the lookup keys.
     */
    protected $casts = [
        'date_of_assessment' => 'date',
        'immunization_date_reviewed' => 'date',
        'med_asthma' => EncryptedBoolean::class,
        'med_diabetes' => EncryptedBoolean::class,
        'med_seizure_disorder' => EncryptedBoolean::class,
        'med_frequent_infections' => EncryptedBoolean::class,
        'med_allergies' => EncryptedBoolean::class,
        'med_heart_condition' => EncryptedBoolean::class,
        'med_tuberculosis' => EncryptedBoolean::class,
        'med_hospitalization_surgery' => EncryptedBoolean::class,
        'fam_hypertension' => EncryptedBoolean::class,
        'fam_diabetes' => EncryptedBoolean::class,
        'fam_heart_disease' => EncryptedBoolean::class,
        'fam_cancer' => EncryptedBoolean::class,
        'fam_mental_health' => EncryptedBoolean::class,
        'dental_referral' => EncryptedBoolean::class,
        'body_systems' => EncryptedArray::class,
        'teeth_condition' => EncryptedArray::class,
        'med_current_medications' => EncryptedString::class,
        'med_allergies_detail' => EncryptedString::class,
        'med_hospitalization_detail' => EncryptedString::class,
        'med_other_conditions' => EncryptedString::class,
        'fam_genetic_hereditary' => EncryptedString::class,
        'appearance_consciousness' => EncryptedString::class,
        'appearance_consciousness_other' => EncryptedString::class,
        'appearance_posture_gait' => EncryptedString::class,
        'appearance_posture_detail' => EncryptedString::class,
        'appearance_hygiene' => EncryptedString::class,
        'vital_height_cm' => EncryptedString::class,
        'vital_weight_kg' => EncryptedString::class,
        'vital_bmi' => EncryptedString::class,
        'vital_temperature_c' => EncryptedString::class,
        'vital_pulse_rate' => EncryptedString::class,
        'vital_blood_pressure' => EncryptedString::class,
        'vision_right_eye' => EncryptedString::class,
        'vision_left_eye' => EncryptedString::class,
        'vision_result' => EncryptedString::class,
        'hearing_result' => EncryptedString::class,
        'last_dental_visit' => EncryptedString::class,
        'immunization_status' => EncryptedString::class,
        'missing_needed_vaccines' => EncryptedString::class,
        'summary_of_findings' => EncryptedString::class,
        'recommendations' => EncryptedString::class,
        'examiner_signature' => EncryptedString::class,
    ];

    public function studentHealthRecord(): BelongsTo
    {
        return $this->belongsTo(StudentHealthRecord::class);
    }

    public static function currentSchoolYear(): string
    {
        $month = (int) now()->format('n');
        $year = (int) now()->format('Y');

        return $month >= 6
            ? "{$year}-".($year + 1)
            : ($year - 1)."-{$year}";
    }

    public static function forStudent(int $studentHealthRecordId, string $schoolYear): ?self
    {
        if (! Schema::hasTable('health_assessments')) {
            return null;
        }

        return static::where('student_health_record_id', $studentHealthRecordId)
            ->where('school_year', $schoolYear)
            ->latest()
            ->first();
    }
}
