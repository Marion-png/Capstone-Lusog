<?php

return [

    /*
    |--------------------------------------------------------------------------
    | At-risk beneficiary rule
    |--------------------------------------------------------------------------
    |
    | At-risk is COMPUTED from feeding-session attendance and nothing else — a
    | learner is never flagged for their nutritional status. Two modes:
    |
    |   attendance_rate       flag when attended% < `threshold_percent`
    |   consecutive_absences  flag on a run of >= `consecutive_absences` misses
    |
    | The approved default is 80% cumulative attendance, but the threshold is
    | school-configurable: `institutions.feeding_at_risk_threshold` overrides
    | this per school (System Admin sets it), and this value is what a school
    | that has set nothing is judged on. Read it through
    | FeedingAtRiskRule::forInstitution(), never straight from config, or two
    | screens will disagree about who is flagged. Changing either figure
    | mid-year silently re-flags learners.
    |
    | Sessions a human has not yet confirmed (an unresolved "?" from a photo
    | scan) are excluded from both the numerator and the denominator — an unread
    | mark is not evidence of attendance or of absence.
    |
    | `minimum_observation_days` is the observation window the threshold is only
    | applied AFTER. A learner one of four sessions has covered is at 25%, but
    | four sessions is not a programme problem — it is too little history to
    | classify anyone on, and flagging them would put a child on a follow-up
    | list on the strength of a single missed morning. Until the window is met
    | the learner reads Early Monitoring; the rate is still computed and shown,
    | it simply does not classify. Also school-configurable
    | (`institutions.feeding_min_observation_days`); 0 or 1 means the threshold
    | applies from the first confirmed session.
    |
    */

    'at_risk' => [
        'mode' => env('FEEDING_AT_RISK_MODE', 'attendance_rate'),
        'threshold_percent' => (float) env('FEEDING_AT_RISK_THRESHOLD_PERCENT', 80),
        'consecutive_absences' => (int) env('FEEDING_AT_RISK_CONSECUTIVE_ABSENCES', 3),
        'minimum_observation_days' => (int) env('FEEDING_MINIMUM_OBSERVATION_DAYS', 10),

        /*
        | The two monitoring bands the At-Risk Students tab draws around the
        | official threshold. They are an operational aid and NOTHING else: the
        | threshold above is still the only thing that decides who is at risk,
        | and only at-risk learners are counted by the at-risk figure.
        |
        |   watch_margin_percent     how far ABOVE the threshold still warrants
        |                            a look — "Watch"
        |   critical_margin_percent  how far BELOW the threshold stops being
        |                            "At Risk" and becomes "Critical"
        |
        | Read both through App\Support\FeedingRiskSeverity, never from config
        | directly, so the bands move with the school's own threshold.
        */
        'watch_margin_percent' => (float) env('FEEDING_WATCH_MARGIN_PERCENT', 5),
        'critical_margin_percent' => (float) env('FEEDING_CRITICAL_MARGIN_PERCENT', 10),
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
