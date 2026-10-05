<?php

return [

    /*
    |--------------------------------------------------------------------------
    | How long a learner's record is kept after they stop appearing
    |--------------------------------------------------------------------------
    |
    | The countdown starts at the end of the last school year the learner was
    | enrolled in, and a learner enrolled in the current school year is never
    | counted at all. See App\Support\StudentRetention.
    |
    | It is configuration rather than a constant for the same reason
    | `institutions.feeding_cycle_days` is: the figure belongs to DepEd's
    | records-retention schedule, not to this application, and a school whose
    | Division settles on a different number must be able to correct it
    | without a code change. docs/open-decisions.md entry 2 records that 3, 6
    | and 10 years were all floated before 5 was chosen.
    |
    */

    'years' => (int) env('RETENTION_YEARS', 5),

    /*
    |--------------------------------------------------------------------------
    | How far ahead a record is flagged as nearing deletion
    |--------------------------------------------------------------------------
    |
    | The window in which the student list and profile warn that a record is
    | about to expire, so somebody has the chance to re-enrol the learner
    | before it goes. Deletion is irreversible, so the warning is the only
    | thing standing between a lapsed enrolment and a lost health history.
    |
    */

    'warn_within_days' => (int) env('RETENTION_WARN_WITHIN_DAYS', 180),

];
