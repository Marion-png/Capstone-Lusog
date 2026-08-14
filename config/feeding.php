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
    */

    'at_risk' => [
        'mode' => env('FEEDING_AT_RISK_MODE', 'attendance_rate'),
        'threshold_percent' => (float) env('FEEDING_AT_RISK_THRESHOLD_PERCENT', 80),
        'consecutive_absences' => (int) env('FEEDING_AT_RISK_CONSECUTIVE_ABSENCES', 3),
    ],

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
