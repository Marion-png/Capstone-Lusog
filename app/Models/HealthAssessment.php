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
        if (! Schema::hasTable('health_assessments')) {
            return null;
        }

        return static::where('student_health_record_id', $studentHealthRecordId)
            ->where('school_year', $schoolYear)
            ->latest()
            ->first();
    }

    /**
     * Shared validation rules for the Sheet 1/2 assessment fields — used both
     * by the standalone health-assessment.store route and by the combined
     * Enroll Student submission, so both accept exactly the same input.
     */
    public static function validationRules(): array
    {
        return [
            'lrn' => ['required', 'string', 'max:50'],
            'date_of_assessment' => ['nullable', 'date'],
            'assessed_by' => ['nullable', 'string', 'max:255'],
            // Medical history
            'med_asthma' => ['nullable', 'boolean'],
            'med_diabetes' => ['nullable', 'boolean'],
            'med_seizure_disorder' => ['nullable', 'boolean'],
            'med_frequent_infections' => ['nullable', 'boolean'],
            'med_current_medications' => ['nullable', 'string', 'max:500'],
            'med_allergies' => ['nullable', 'boolean'],
            'med_allergies_detail' => ['nullable', 'string', 'max:255'],
            'med_heart_condition' => ['nullable', 'boolean'],
            'med_tuberculosis' => ['nullable', 'boolean'],
            'med_hospitalization_surgery' => ['nullable', 'boolean'],
            'med_hospitalization_detail' => ['nullable', 'string', 'max:255'],
            'med_other_conditions' => ['nullable', 'string', 'max:500'],
            // Family history
            'fam_hypertension' => ['nullable', 'boolean'],
            'fam_diabetes' => ['nullable', 'boolean'],
            'fam_heart_disease' => ['nullable', 'boolean'],
            'fam_cancer' => ['nullable', 'boolean'],
            'fam_mental_health' => ['nullable', 'boolean'],
            'fam_genetic_hereditary' => ['nullable', 'string', 'max:255'],
            // General appearance
            'appearance_consciousness' => ['nullable', 'string', 'max:50'],
            'appearance_consciousness_other' => ['nullable', 'string', 'max:100'],
            'appearance_posture_gait' => ['nullable', 'string', 'max:50'],
            'appearance_posture_detail' => ['nullable', 'string', 'max:100'],
            'appearance_hygiene' => ['nullable', 'string', 'max:50'],
            // Vital signs
            'vital_height_cm' => ['nullable', 'numeric', 'min:0', 'max:300'],
            'vital_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:300'],
            'vital_bmi' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'vital_temperature_c' => ['nullable', 'numeric', 'min:30', 'max:45'],
            'vital_pulse_rate' => ['nullable', 'integer', 'min:0', 'max:300'],
            'vital_blood_pressure' => ['nullable', 'string', 'max:20'],
            // Body systems
            'body_systems' => ['nullable', 'array'],
            'body_systems.*' => ['nullable', 'array'],
            // Vision and hearing
            'vision_right_eye' => ['nullable', 'string', 'max:20'],
            'vision_left_eye' => ['nullable', 'string', 'max:20'],
            'vision_result' => ['nullable', 'string', 'max:10'],
            'hearing_result' => ['nullable', 'string', 'max:30'],
            // Oral health
            'teeth_condition' => ['nullable', 'array'],
            'last_dental_visit' => ['nullable', 'string', 'max:100'],
            'dental_referral' => ['nullable', 'boolean'],
            // Immunization
            'immunization_status' => ['nullable', 'string', 'max:30'],
            'missing_needed_vaccines' => ['nullable', 'string', 'max:500'],
            'immunization_date_reviewed' => ['nullable', 'date'],
            // Summary
            'summary_of_findings' => ['nullable', 'string', 'max:2000'],
            'recommendations' => ['nullable', 'string', 'max:2000'],
            'examiner_signature' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The Sheet 2 field names actually collected by the form (mirrors
     * validationRules() minus 'lrn') — used to detect whether an adviser
     * filled in any of it when Sheet 2 is optional (the combined Enroll
     * Student flow), so a blank Sheet 2 doesn't create an empty row that
     * would then read as "already assessed" and lock the student out.
     */
    public const SHEET_TWO_FIELDS = [
        'date_of_assessment', 'assessed_by',
        'med_asthma', 'med_diabetes', 'med_seizure_disorder', 'med_frequent_infections',
        'med_current_medications', 'med_allergies', 'med_allergies_detail', 'med_heart_condition',
        'med_tuberculosis', 'med_hospitalization_surgery', 'med_hospitalization_detail', 'med_other_conditions',
        'fam_hypertension', 'fam_diabetes', 'fam_heart_disease', 'fam_cancer', 'fam_mental_health', 'fam_genetic_hereditary',
        'appearance_consciousness', 'appearance_consciousness_other', 'appearance_posture_gait',
        'appearance_posture_detail', 'appearance_hygiene',
        'vital_height_cm', 'vital_weight_kg', 'vital_bmi', 'vital_temperature_c', 'vital_pulse_rate', 'vital_blood_pressure',
        'body_systems', 'vision_right_eye', 'vision_left_eye', 'vision_result', 'hearing_result',
        'teeth_condition', 'last_dental_visit', 'dental_referral',
        'immunization_status', 'missing_needed_vaccines', 'immunization_date_reviewed',
        'summary_of_findings', 'recommendations', 'examiner_signature',
    ];

    /**
     * Upsert (replace) the assessment for a given record/school year from
     * already-validated data. Shared by the standalone Sheet 2 submission
     * and the combined Enroll Student submission.
     */
    public static function saveFromValidated(array $validated, StudentHealthRecord $record, string $submittedByName): self
    {
        $schoolYear = static::currentSchoolYear();

        static::where('student_health_record_id', $record->id)
            ->where('school_year', $schoolYear)
            ->delete();

        return static::create([
            'student_health_record_id' => $record->id,
            'school_year' => $schoolYear,
            'date_of_assessment' => $validated['date_of_assessment'] ?? null,
            'assessed_by' => $validated['assessed_by'] ?? null,
            'med_asthma' => ! empty($validated['med_asthma']),
            'med_diabetes' => ! empty($validated['med_diabetes']),
            'med_seizure_disorder' => ! empty($validated['med_seizure_disorder']),
            'med_frequent_infections' => ! empty($validated['med_frequent_infections']),
            'med_current_medications' => $validated['med_current_medications'] ?? null,
            'med_allergies' => ! empty($validated['med_allergies']),
            'med_allergies_detail' => $validated['med_allergies_detail'] ?? null,
            'med_heart_condition' => ! empty($validated['med_heart_condition']),
            'med_tuberculosis' => ! empty($validated['med_tuberculosis']),
            'med_hospitalization_surgery' => ! empty($validated['med_hospitalization_surgery']),
            'med_hospitalization_detail' => $validated['med_hospitalization_detail'] ?? null,
            'med_other_conditions' => $validated['med_other_conditions'] ?? null,
            'fam_hypertension' => ! empty($validated['fam_hypertension']),
            'fam_diabetes' => ! empty($validated['fam_diabetes']),
            'fam_heart_disease' => ! empty($validated['fam_heart_disease']),
            'fam_cancer' => ! empty($validated['fam_cancer']),
            'fam_mental_health' => ! empty($validated['fam_mental_health']),
            'fam_genetic_hereditary' => $validated['fam_genetic_hereditary'] ?? null,
            'appearance_consciousness' => $validated['appearance_consciousness'] ?? null,
            'appearance_consciousness_other' => $validated['appearance_consciousness_other'] ?? null,
            'appearance_posture_gait' => $validated['appearance_posture_gait'] ?? null,
            'appearance_posture_detail' => $validated['appearance_posture_detail'] ?? null,
            'appearance_hygiene' => $validated['appearance_hygiene'] ?? null,
            'vital_height_cm' => $validated['vital_height_cm'] ?? null,
            'vital_weight_kg' => $validated['vital_weight_kg'] ?? null,
            'vital_bmi' => $validated['vital_bmi'] ?? null,
            'vital_temperature_c' => $validated['vital_temperature_c'] ?? null,
            'vital_pulse_rate' => $validated['vital_pulse_rate'] ?? null,
            'vital_blood_pressure' => $validated['vital_blood_pressure'] ?? null,
            'body_systems' => $validated['body_systems'] ?? null,
            'vision_right_eye' => $validated['vision_right_eye'] ?? null,
            'vision_left_eye' => $validated['vision_left_eye'] ?? null,
            'vision_result' => $validated['vision_result'] ?? null,
            'hearing_result' => $validated['hearing_result'] ?? null,
            'teeth_condition' => $validated['teeth_condition'] ?? null,
            'last_dental_visit' => $validated['last_dental_visit'] ?? null,
            'dental_referral' => ! empty($validated['dental_referral']),
            'immunization_status' => $validated['immunization_status'] ?? null,
            'missing_needed_vaccines' => $validated['missing_needed_vaccines'] ?? null,
            'immunization_date_reviewed' => $validated['immunization_date_reviewed'] ?? null,
            'summary_of_findings' => $validated['summary_of_findings'] ?? null,
            'recommendations' => $validated['recommendations'] ?? null,
            'examiner_signature' => $validated['examiner_signature'] ?? null,
            'submitted_by_name' => $submittedByName,
        ]);
    }
}
