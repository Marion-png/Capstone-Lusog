<?php

return [

    /*
    |--------------------------------------------------------------------------
    | How long an archived learner's record is kept
    |--------------------------------------------------------------------------
    |
    | The countdown starts the day a learner is archived (transferred out,
    | dropped out, completed Grade 10): `delete_after` is set to that day plus
    | this many years, and restoring the learner clears it. A learner in a
    | section, or waiting for one, is never counted at all. See
    | App\Support\StudentRetention.
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
