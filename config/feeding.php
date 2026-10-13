<?php

return [

    /*
    |--------------------------------------------------------------------------
    | At-risk beneficiary rule
    |--------------------------------------------------------------------------
    |
    | At-risk is COMPUTED from feeding-session attendance and nothing else — a
    | learner is never flagged for their nutritional status. The flow is the
    | one the capstone adviser set (see App\Support\FeedingAttendanceFlag):
    |
    |   1. `absence_notice_days` (3) unexcused absences in a row raise an
    |      ATTENDANCE NOTICE: the Feeding Coordinator is asked to verify the
    |      record. Nobody is flagged.
    |   2. Reaching the mode's threshold below — `absence_flag_days` (7) in a
    |      row under the unexcused-absence rule — makes the learner AWAITING
    |      CONFIRMATION. Still nobody is flagged.
    |   3. The coordinator confirms the record is correct, and only then is the
    |      learner FLAGGED FOR FOLLOW-UP — visible to the coordinator and the
    |      School Nurse. A Resolved follow-up closes the flag.
    |
    | Enrolment is never touched by any of it.
    |
    | Which test reaches step 2 is the school's mode:
    |
    |   attendance_rate        attended% < `threshold_percent`
    |   consecutive_absences   a run of >= `consecutive_absences` misses
    |   unexcused_absence_days a run of >= `absence_flag_days` UNEXCUSED
    |                          misses. This is the rule Sta. Ana runs, and it
    |                          is not a percentage of the cycle at all: a
    |                          learner who attended every session for two
    |                          months and then vanished is still above 90%
    |                          and needs following up today.
    |
    | An EXCUSED absence — the coordinator's "buffer": fasting during Ramadan,
    | illness, a family emergency — is recorded on the sheet and deliberately
    | excluded from every one of these rules, exactly as an unconfirmed mark is
    | (see App\Support\FeedingAttendanceMark).
    |
    | The mode and every figure are school-configurable (the `institutions.
    | feeding_*` columns, set by the System Admin); these values are what a
    | school that has set nothing runs. Read them through
    | FeedingAtRiskRule::forInstitution(), never straight from config, or two
    | screens will disagree about who is flagged.
    |
    | Sessions a human has not yet confirmed (an unresolved "?" from a photo
    | scan) are excluded from both the numerator and the denominator — an unread
    | mark is not evidence of attendance or of absence.
    |
    | `minimum_observation_days` is the history a RATE needs before it means
    | anything: a learner one of four sessions has covered is at 25%, which is
    | a sample, not a finding. Until it is met the learner reads Early
    | Monitoring. A run of absences carries its own evidence, so the
    | run-based modes never wait on it.
    |
    */

    'at_risk' => [
        'mode' => env('FEEDING_AT_RISK_MODE', 'attendance_rate'),
        'threshold_percent' => (float) env('FEEDING_AT_RISK_THRESHOLD_PERCENT', 80),
        'consecutive_absences' => (int) env('FEEDING_AT_RISK_CONSECUTIVE_ABSENCES', 3),
        // The adviser's two days: the notice at day 3, the flag at day 7, both
        // counted in recorded feeding sessions of unexcused absence in a row.
        'absence_notice_days' => (int) env('FEEDING_ABSENCE_NOTICE_DAYS', 3),
        'absence_flag_days' => (int) env('FEEDING_ABSENCE_FLAG_DAYS', 7),
        'minimum_observation_days' => (int) env('FEEDING_MINIMUM_OBSERVATION_DAYS', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rehabilitation target
    |--------------------------------------------------------------------------
    |
    | The share of beneficiaries a school (or its Division) expects to see at
    | Normal by the endline weighing. The School Head's Reports tab prints the
    | achieved rate against it.
    |
    | NULL by default, and left that way on purpose: a target nobody set is a
    | number this application invented, and a rate compared against an invented
    | target is worse than a rate standing on its own. Set
    | FEEDING_REHABILITATION_TARGET_PERCENT only when a real target exists.
    |
    */

    'rehabilitation_target_percent' => env('FEEDING_REHABILITATION_TARGET_PERCENT'),

    /*
    |--------------------------------------------------------------------------
    | Cycle length
    |--------------------------------------------------------------------------
    |
    | How many feeding days one SBFP cycle runs for. Division policy says 120,
    | but 90 has been under discussion, and a school whose Division settles on
    | a different figure must be able to say so without a code change — hence
    | `institutions.feeding_cycle_days`, with this as the default for a school
    | that has set nothing.
    |
    | Counted in FEEDING days (Mon-Fri school days), never in elapsed calendar
    | days: read it through App\Support\FeedingProgramCycle, never directly.
    |
    */

    'cycle_days' => (int) env('FEEDING_CYCLE_DAYS', 120),

    /*
    |--------------------------------------------------------------------------
    | Attendance photo scanning
    |--------------------------------------------------------------------------
    |
    | A photographed sheet is sent to Claude together with the roster names, so
    | the model matches a mark to an already-known learner instead of reading
    | names cold. It is never used to identify anyone from a face.
    |
    | The image is held (encrypted) only while a scan still has unconfirmed
    | marks, because a reviewer cannot resolve a "?" without seeing it. Once
    | every mark on a scan is confirmed the image is purged.
    |
    */

    'scanning' => [
        'enabled' => (bool) env('FEEDING_SCAN_ENABLED', true),
        'max_upload_kb' => (int) env('FEEDING_SCAN_MAX_UPLOAD_KB', 10240),
        'purge_photo_after_review' => (bool) env('FEEDING_SCAN_PURGE_AFTER_REVIEW', true),
    ],

];
