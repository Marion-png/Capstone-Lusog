<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Priority learner rule
    |--------------------------------------------------------------------------
    |
    | A learner is flagged "Priority" when the health assessment the class
    | adviser submits reports a chronic or potentially life-threatening
    | condition. The clinic needs to know who those learners are before an
    | emergency, not during one.
    |
    | The flag is COMPUTED from the assessment on every read — it is never
    | stored, never manually toggled, and never inferred from nutritional
    | status (that is the feeding programme's at-risk rule, a different thing
    | with a different meaning). Correct an assessment and the flag follows.
    |
    | `conditions` lists the assessment fields that raise it. The defaults are
    | the chronic and emergency-risk ones:
    |
    |   med_asthma            can become an airway emergency
    |   med_diabetes          chronic; hypo/hyperglycaemia risk
    |   med_seizure_disorder  chronic; seizure risk on school grounds
    |   med_heart_condition   chronic; cardiac risk during PE
    |   med_tuberculosis      serious and communicable
    |   med_allergies         can be anaphylactic
    |
    | Two further fields exist on the assessment and are deliberately NOT in
    | the default set — add them here if a school wants them:
    |
    |   med_frequent_infections     a symptom pattern, not a diagnosis
    |   med_hospitalization_surgery a past event, not an ongoing condition
    |
    | Changing this list changes who is flagged for everyone, immediately.
    |
    */

    'priority' => [
        /*
         | Fields on the Health Assessment form (health_assessments table).
         */
        'conditions' => [
            'med_asthma' => 'Asthma',
            'med_diabetes' => 'Diabetes',
            'med_seizure_disorder' => 'Seizure disorder',
            'med_heart_condition' => 'Heart condition',
            'med_tuberculosis' => 'Tuberculosis',
            'med_allergies' => 'Severe allergies',
        ],

        /*
         | The SAME conditions as recorded on the adviser's student profile
         | (student_health_records.student_details['health_history']).
         |
         | That form is older and names three of them differently — med_seizure
         | rather than med_seizure_disorder, med_heart rather than
         | med_heart_condition. Both forms must flag a learner, so the rule
         | reads both and the labels are deliberately identical: a learner
         | recorded in both places is one Priority entry, not two.
         */
        'profile_conditions' => [
            'med_asthma' => 'Asthma',
            'med_diabetes' => 'Diabetes',
            'med_seizure' => 'Seizure disorder',
            'med_heart' => 'Heart condition',
            'med_tuberculosis' => 'Tuberculosis',
            'med_allergies' => 'Severe allergies',
        ],
    ],

];
