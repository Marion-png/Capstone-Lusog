<?php

namespace App\Models;

use App\Casts\EncryptedArray;
use App\Casts\EncryptedBoolean;
use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use App\Support\SchemaCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HealthAssessment extends Model
{
    use Auditable;

    /**
     * Section F of the paper MLHAT: body systems and their checkable
     * findings. Keys match the `body_systems` JSON structure submitted by
     * the adviser form (body_systems[<key>][findings][] / [notes]).
     */
    public const BODY_SYSTEMS = [
        'integumentary' => ['label' => 'Integumentary', 'findings' => ['Normal', 'Lesions/Rashes', 'Pallor', 'Other']],
        'heent_head' => ['label' => 'HEENT – Head/Scalp', 'findings' => ['Normal', 'Abnormal']],
        'heent_eyes' => ['label' => 'HEENT – Eyes', 'findings' => ['Clear', 'Redness', 'Discharge']],
        'heent_ears' => ['label' => 'HEENT – Ears', 'findings' => ['Clear', 'Pain', 'Discharge']],
        'heent_nose' => ['label' => 'HEENT – Nose', 'findings' => ['Clear', 'Congested']],
        'heent_throat' => ['label' => 'HEENT – Throat', 'findings' => ['Normal', 'Inflamed', 'Tonsillar Issues']],
        'respiratory' => ['label' => 'Respiratory', 'findings' => ['Clear Breath Sounds', 'Cough', 'Wheezing']],
        'cardiovascular' => ['label' => 'Cardiovascular', 'findings' => ['Regular Rhythm', 'Irregular', 'Murmur']],
        'gastrointestinal' => ['label' => 'Gastrointestinal', 'findings' => ['Abdomen Soft', 'Pain', 'Nausea/Vomiting']],
        'genitourinary' => ['label' => 'Genitourinary', 'findings' => ['No Complaints', 'Pain', 'Other']],
        'musculoskeletal' => ['label' => 'Musculoskeletal', 'findings' => ['Normal ROM', 'Deformity', 'Pain']],
        'neurological' => ['label' => 'Neurological', 'findings' => ['Oriented', 'Reflexes Normal', 'Abnormal']],
    ];

    /** Section B checkbox fields => labels (detail write-ins handled separately). */
    public const MEDICAL_HISTORY_FLAGS = [
        'med_asthma' => 'Asthma',
        'med_diabetes' => 'Diabetes',
        'med_seizure_disorder' => 'Seizure Disorder',
        'med_frequent_infections' => 'Frequent Infections',
        'med_allergies' => 'Allergies',
        'med_heart_condition' => 'Heart Condition',
        'med_tuberculosis' => 'Tuberculosis',
        'med_hospitalization_surgery' => 'Hospitalization/Surgery',
    ];

    /** Section C checkbox fields => labels. */
    public const FAMILY_HISTORY_FLAGS = [
        'fam_hypertension' => 'Hypertension',
        'fam_diabetes' => 'Diabetes',
        'fam_heart_disease' => 'Heart Disease',
        'fam_cancer' => 'Cancer',
        'fam_mental_health' => 'Mental Health Conditions',
    ];

    /** Section H teeth condition options. */
    public const TEETH_CONDITIONS = ['Good', 'Fair', 'Poor', 'Dental Caries', 'Gum Inflammation', 'Missing/Broken Teeth'];

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
        return ParentalConsentForm::currentSchoolYear();
    }

    public static function forStudent(int $studentHealthRecordId, string $schoolYear): ?self
    {
        if (! SchemaCache::hasTable('health_assessments')) {
            return null;
        }

        return static::where('student_health_record_id', $studentHealthRecordId)
            ->where('school_year', $schoolYear)
            ->latest()
            ->first();
    }
}
