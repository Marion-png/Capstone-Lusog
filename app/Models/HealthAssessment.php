<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class HealthAssessment extends Model
{
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

    protected $casts = [
        'date_of_assessment'         => 'date',
        'immunization_date_reviewed' => 'date',
        'med_asthma'                  => 'boolean',
        'med_diabetes'                => 'boolean',
        'med_seizure_disorder'        => 'boolean',
        'med_frequent_infections'     => 'boolean',
        'med_allergies'               => 'boolean',
        'med_heart_condition'         => 'boolean',
        'med_tuberculosis'            => 'boolean',
        'med_hospitalization_surgery' => 'boolean',
        'fam_hypertension'            => 'boolean',
        'fam_diabetes'                => 'boolean',
        'fam_heart_disease'           => 'boolean',
        'fam_cancer'                  => 'boolean',
        'fam_mental_health'           => 'boolean',
        'dental_referral'             => 'boolean',
        'body_systems'                => 'array',
        'teeth_condition'             => 'array',
    ];

    public function studentHealthRecord(): BelongsTo
    {
        return $this->belongsTo(StudentHealthRecord::class);
    }

    public static function currentSchoolYear(): string
    {
        $month = (int) now()->format('n');
        $year  = (int) now()->format('Y');
        return $month >= 6
            ? "{$year}-" . ($year + 1)
            : ($year - 1) . "-{$year}";
    }

    public static function forStudent(int $studentHealthRecordId, string $schoolYear): ?self
    {
        if (!Schema::hasTable('health_assessments')) {
            return null;
        }
        return static::where('student_health_record_id', $studentHealthRecordId)
            ->where('school_year', $schoolYear)
            ->latest()
            ->first();
    }
}
