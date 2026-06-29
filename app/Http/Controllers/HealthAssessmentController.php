<?php

namespace App\Http\Controllers;

use App\Models\HealthAssessment;
use App\Models\StudentHealthRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class HealthAssessmentController extends Controller
{
    /**
     * Store a health assessment submitted by the class adviser.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless(
            $request->session()->get('active_role') === 'class_adviser',
            403,
            'Only a Class Adviser may submit health assessments.'
        );

        $advisedGrade   = (string) $request->session()->get('assigned_grade_level', '');
        $advisedSection = (string) $request->session()->get('assigned_section', '');

        abort_if(
            $advisedGrade === '' || $advisedSection === '',
            403,
            'Your account has no assigned class.'
        );

        $validated = $request->validate([
            'lrn'                            => ['required', 'string', 'max:50'],
            'date_of_assessment'             => ['nullable', 'date'],
            'assessed_by'                    => ['nullable', 'string', 'max:255'],
            // Medical history
            'med_asthma'                     => ['nullable', 'boolean'],
            'med_diabetes'                   => ['nullable', 'boolean'],
            'med_seizure_disorder'           => ['nullable', 'boolean'],
            'med_frequent_infections'        => ['nullable', 'boolean'],
            'med_current_medications'        => ['nullable', 'string', 'max:500'],
            'med_allergies'                  => ['nullable', 'boolean'],
            'med_allergies_detail'           => ['nullable', 'string', 'max:255'],
            'med_heart_condition'            => ['nullable', 'boolean'],
            'med_tuberculosis'               => ['nullable', 'boolean'],
            'med_hospitalization_surgery'    => ['nullable', 'boolean'],
            'med_hospitalization_detail'     => ['nullable', 'string', 'max:255'],
            'med_other_conditions'           => ['nullable', 'string', 'max:500'],
            // Family history
            'fam_hypertension'               => ['nullable', 'boolean'],
            'fam_diabetes'                   => ['nullable', 'boolean'],
            'fam_heart_disease'              => ['nullable', 'boolean'],
            'fam_cancer'                     => ['nullable', 'boolean'],
            'fam_mental_health'              => ['nullable', 'boolean'],
            'fam_genetic_hereditary'         => ['nullable', 'string', 'max:255'],
            // General appearance
            'appearance_consciousness'       => ['nullable', 'string', 'max:50'],
            'appearance_consciousness_other' => ['nullable', 'string', 'max:100'],
            'appearance_posture_gait'        => ['nullable', 'string', 'max:50'],
            'appearance_posture_detail'      => ['nullable', 'string', 'max:100'],
            'appearance_hygiene'             => ['nullable', 'string', 'max:50'],
            // Vital signs
            'vital_height_cm'               => ['nullable', 'numeric', 'min:0', 'max:300'],
            'vital_weight_kg'               => ['nullable', 'numeric', 'min:0', 'max:300'],
            'vital_bmi'                     => ['nullable', 'numeric', 'min:0', 'max:100'],
            'vital_temperature_c'           => ['nullable', 'numeric', 'min:30', 'max:45'],
            'vital_pulse_rate'              => ['nullable', 'integer', 'min:0', 'max:300'],
            'vital_blood_pressure'          => ['nullable', 'string', 'max:20'],
            // Body systems
            'body_systems'                  => ['nullable', 'array'],
            'body_systems.*'               => ['nullable', 'array'],
            // Vision and hearing
            'vision_right_eye'              => ['nullable', 'string', 'max:20'],
            'vision_left_eye'               => ['nullable', 'string', 'max:20'],
            'vision_result'                 => ['nullable', 'string', 'max:10'],
            'hearing_result'                => ['nullable', 'string', 'max:30'],
            // Oral health
            'teeth_condition'               => ['nullable', 'array'],
            'last_dental_visit'             => ['nullable', 'string', 'max:100'],
            'dental_referral'               => ['nullable', 'boolean'],
            // Immunization
            'immunization_status'           => ['nullable', 'string', 'max:30'],
            'missing_needed_vaccines'       => ['nullable', 'string', 'max:500'],
            'immunization_date_reviewed'    => ['nullable', 'date'],
            // Summary
            'summary_of_findings'           => ['nullable', 'string', 'max:2000'],
            'recommendations'               => ['nullable', 'string', 'max:2000'],
            'examiner_signature'            => ['nullable', 'string', 'max:255'],
        ]);

        $expectedSection = trim("{$advisedGrade} / {$advisedSection}");
        $record = StudentHealthRecord::where('student_id', $validated['lrn'])->first();

        abort_if(
            $record === null || $record->section !== $expectedSection,
            403,
            'You may only submit assessments for students in your assigned class.'
        );

        $schoolYear = HealthAssessment::currentSchoolYear();

        // Upsert: replace existing assessment for this student/school year
        HealthAssessment::where('student_health_record_id', $record->id)
            ->where('school_year', $schoolYear)
            ->delete();

        HealthAssessment::create([
            'student_health_record_id'       => $record->id,
            'school_year'                    => $schoolYear,
            'date_of_assessment'             => $validated['date_of_assessment'] ?? null,
            'assessed_by'                    => $validated['assessed_by'] ?? null,
            'med_asthma'                     => !empty($validated['med_asthma']),
            'med_diabetes'                   => !empty($validated['med_diabetes']),
            'med_seizure_disorder'           => !empty($validated['med_seizure_disorder']),
            'med_frequent_infections'        => !empty($validated['med_frequent_infections']),
            'med_current_medications'        => $validated['med_current_medications'] ?? null,
            'med_allergies'                  => !empty($validated['med_allergies']),
            'med_allergies_detail'           => $validated['med_allergies_detail'] ?? null,
            'med_heart_condition'            => !empty($validated['med_heart_condition']),
            'med_tuberculosis'               => !empty($validated['med_tuberculosis']),
            'med_hospitalization_surgery'    => !empty($validated['med_hospitalization_surgery']),
            'med_hospitalization_detail'     => $validated['med_hospitalization_detail'] ?? null,
            'med_other_conditions'           => $validated['med_other_conditions'] ?? null,
            'fam_hypertension'               => !empty($validated['fam_hypertension']),
            'fam_diabetes'                   => !empty($validated['fam_diabetes']),
            'fam_heart_disease'              => !empty($validated['fam_heart_disease']),
            'fam_cancer'                     => !empty($validated['fam_cancer']),
            'fam_mental_health'              => !empty($validated['fam_mental_health']),
            'fam_genetic_hereditary'         => $validated['fam_genetic_hereditary'] ?? null,
            'appearance_consciousness'       => $validated['appearance_consciousness'] ?? null,
            'appearance_consciousness_other' => $validated['appearance_consciousness_other'] ?? null,
            'appearance_posture_gait'        => $validated['appearance_posture_gait'] ?? null,
            'appearance_posture_detail'      => $validated['appearance_posture_detail'] ?? null,
            'appearance_hygiene'             => $validated['appearance_hygiene'] ?? null,
            'vital_height_cm'               => $validated['vital_height_cm'] ?? null,
            'vital_weight_kg'               => $validated['vital_weight_kg'] ?? null,
            'vital_bmi'                     => $validated['vital_bmi'] ?? null,
            'vital_temperature_c'           => $validated['vital_temperature_c'] ?? null,
            'vital_pulse_rate'              => $validated['vital_pulse_rate'] ?? null,
            'vital_blood_pressure'          => $validated['vital_blood_pressure'] ?? null,
            'body_systems'                  => $validated['body_systems'] ?? null,
            'vision_right_eye'              => $validated['vision_right_eye'] ?? null,
            'vision_left_eye'               => $validated['vision_left_eye'] ?? null,
            'vision_result'                 => $validated['vision_result'] ?? null,
            'hearing_result'                => $validated['hearing_result'] ?? null,
            'teeth_condition'               => $validated['teeth_condition'] ?? null,
            'last_dental_visit'             => $validated['last_dental_visit'] ?? null,
            'dental_referral'               => !empty($validated['dental_referral']),
            'immunization_status'           => $validated['immunization_status'] ?? null,
            'missing_needed_vaccines'       => $validated['missing_needed_vaccines'] ?? null,
            'immunization_date_reviewed'    => $validated['immunization_date_reviewed'] ?? null,
            'summary_of_findings'           => $validated['summary_of_findings'] ?? null,
            'recommendations'               => $validated['recommendations'] ?? null,
            'examiner_signature'            => $validated['examiner_signature'] ?? null,
            'submitted_by_name'             => (string) $request->session()->get('active_name', 'Class Adviser'),
        ]);

        return back()->with('health_assessment_success', 'Health assessment saved successfully for SY ' . $schoolYear . '.');
    }

    /**
     * Return health assessment data for a student.
     * Accessible by class_adviser (own class), school_nurse, and clinic_staff.
     */
    public function status(Request $request): JsonResponse
    {
        $activeRole = (string) $request->session()->get('active_role', '');

        abort_unless(
            in_array($activeRole, ['class_adviser', 'school_nurse', 'clinic_staff'], true),
            403,
            'Access denied.'
        );

        if (!Schema::hasTable('health_assessments')) {
            return response()->json(['has_assessment' => false]);
        }

        $lrn = (string) $request->query('lrn', '');
        if ($lrn === '') {
            return response()->json(['has_assessment' => false]);
        }

        $record = StudentHealthRecord::where('student_id', $lrn)->first();

        if ($activeRole === 'class_adviser' && $record !== null) {
            $grade   = (string) $request->session()->get('assigned_grade_level', '');
            $section = (string) $request->session()->get('assigned_section', '');
            $expected = trim("{$grade} / {$section}");
            if ($grade === '' || $section === '' || $record->section !== $expected) {
                return response()->json(['has_assessment' => false]);
            }
        }

        if ($record === null) {
            return response()->json(['has_assessment' => false]);
        }

        $schoolYear  = HealthAssessment::currentSchoolYear();
        $assessment  = HealthAssessment::forStudent($record->id, $schoolYear);

        if ($assessment === null) {
            return response()->json(['has_assessment' => false, 'school_year' => $schoolYear]);
        }

        return response()->json([
            'has_assessment'              => true,
            'school_year'                 => $assessment->school_year,
            'date_of_assessment'          => $assessment->date_of_assessment?->format('M d, Y'),
            'assessed_by'                 => $assessment->assessed_by,
            'submitted_by'                => $assessment->submitted_by_name,
            'submitted_at'                => $assessment->created_at?->format('M d, Y'),
            // Medical history
            'med_asthma'                  => (bool) $assessment->med_asthma,
            'med_diabetes'                => (bool) $assessment->med_diabetes,
            'med_seizure_disorder'        => (bool) $assessment->med_seizure_disorder,
            'med_frequent_infections'     => (bool) $assessment->med_frequent_infections,
            'med_current_medications'     => $assessment->med_current_medications,
            'med_allergies'               => (bool) $assessment->med_allergies,
            'med_allergies_detail'        => $assessment->med_allergies_detail,
            'med_heart_condition'         => (bool) $assessment->med_heart_condition,
            'med_tuberculosis'            => (bool) $assessment->med_tuberculosis,
            'med_hospitalization_surgery' => (bool) $assessment->med_hospitalization_surgery,
            'med_hospitalization_detail'  => $assessment->med_hospitalization_detail,
            'med_other_conditions'        => $assessment->med_other_conditions,
            // Family history
            'fam_hypertension'            => (bool) $assessment->fam_hypertension,
            'fam_diabetes'                => (bool) $assessment->fam_diabetes,
            'fam_heart_disease'           => (bool) $assessment->fam_heart_disease,
            'fam_cancer'                  => (bool) $assessment->fam_cancer,
            'fam_mental_health'           => (bool) $assessment->fam_mental_health,
            'fam_genetic_hereditary'      => $assessment->fam_genetic_hereditary,
            // General appearance
            'appearance_consciousness'    => $assessment->appearance_consciousness,
            'appearance_consciousness_other' => $assessment->appearance_consciousness_other,
            'appearance_posture_gait'     => $assessment->appearance_posture_gait,
            'appearance_posture_detail'   => $assessment->appearance_posture_detail,
            'appearance_hygiene'          => $assessment->appearance_hygiene,
            // Vital signs
            'vital_height_cm'             => $assessment->vital_height_cm,
            'vital_weight_kg'             => $assessment->vital_weight_kg,
            'vital_bmi'                   => $assessment->vital_bmi,
            'vital_temperature_c'         => $assessment->vital_temperature_c,
            'vital_pulse_rate'            => $assessment->vital_pulse_rate,
            'vital_blood_pressure'        => $assessment->vital_blood_pressure,
            // Body systems
            'body_systems'                => $assessment->body_systems ?? [],
            // Vision and hearing
            'vision_right_eye'            => $assessment->vision_right_eye,
            'vision_left_eye'             => $assessment->vision_left_eye,
            'vision_result'               => $assessment->vision_result,
            'hearing_result'              => $assessment->hearing_result,
            // Oral health
            'teeth_condition'             => $assessment->teeth_condition ?? [],
            'last_dental_visit'           => $assessment->last_dental_visit,
            'dental_referral'             => (bool) $assessment->dental_referral,
            // Immunization
            'immunization_status'         => $assessment->immunization_status,
            'missing_needed_vaccines'     => $assessment->missing_needed_vaccines,
            'immunization_date_reviewed'  => $assessment->immunization_date_reviewed?->format('M d, Y'),
            // Summary
            'summary_of_findings'         => $assessment->summary_of_findings,
            'recommendations'             => $assessment->recommendations,
            'examiner_signature'          => $assessment->examiner_signature,
        ]);
    }
}
