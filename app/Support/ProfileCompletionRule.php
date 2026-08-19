<?php

namespace App\Support;

use App\Models\StudentHealthRecord;

/**
 * When a learner's School Health Card counts as complete.
 *
 * A card is closed out by the *end* of the feeding programme, not the start
 * of it — so nobody is complete while the cycle is still running. The
 * adviser's Complete Profile filter used to key on the health assessment
 * form alone, which meant a learner could read "Complete" on day 3 of 120
 * with no closing measurement anywhere in sight.
 *
 * Three conditions, all required:
 *
 *   1. The school's 120 feeding days are done (FeedingProgramCycle). This
 *      one is school-level, so mid-cycle the Complete bucket is empty and
 *      every learner falls to Pending Assessment.
 *   2. The learner has an endline reading. That is the closing measurement,
 *      and the adviser's endline form is itself gated on the same cycle.
 *   3. If the learner was enrolled as a beneficiary, they took part — the
 *      programme fed them and the roster can evidence it.
 *
 * On (3): only wasted, severely wasted and underweight Grade 7-10 learners
 * are ever enrolled, so requiring attendance of *everyone* would leave a
 * healthy learner permanently incomplete no matter how carefully their
 * adviser filled the card in. A learner the programme never enrolled has
 * nothing to attend, so for them the closing measurement is the whole test.
 *
 * "Took part" is one confirmed session, not 120 of them. The roster can
 * evidence that a learner was fed; it cannot evidence perfect attendance,
 * and a learner who missed sessions did not fail to complete the programme
 * — that is what the at-risk rule is for, and it is the Feeding
 * Coordinator's judgement, not the adviser's profile badge.
 */
class ProfileCompletionRule
{
    /**
     * @param  bool  $attended  whether the learner has a confirmed present session
     */
    public static function isComplete(
        FeedingProgramCycle $cycle,
        ?StudentHealthRecord $record,
        bool $attended
    ): bool {
        if (! $cycle->isComplete() || $record === null) {
            return false;
        }

        if (! self::hasEndline($record)) {
            return false;
        }

        return ! self::wasEnrolled($record) || $attended;
    }

    /**
     * The closing measurement. Read from the status rather than the BMI
     * value because a record can carry a status the classifier wrote with
     * no numeric copy on some legacy rows; either one proves it was taken.
     */
    public static function hasEndline(?StudentHealthRecord $record): bool
    {
        if ($record === null) {
            return false;
        }

        $status = trim((string) ($record->endline_nutritional_status ?? ''));

        return $status !== '' || (float) ($record->endline_bmi_value ?? 0) > 0;
    }

    /** Enrolment is the Feeding Coordinator's stamp — qualifying is not it. */
    public static function wasEnrolled(?StudentHealthRecord $record): bool
    {
        return $record?->feeding_enrolled_at !== null;
    }

    /**
     * Why a learner is not complete yet, for the badge's tooltip. Returns
     * an empty string when they are.
     */
    public static function outstanding(
        FeedingProgramCycle $cycle,
        ?StudentHealthRecord $record,
        bool $attended
    ): string {
        if (! $cycle->isComplete()) {
            return $cycle->hasStarted()
                ? 'Feeding programme is on day '.$cycle->day().' of '.FeedingProgramCycle::DURATION_DAYS
                : 'Feeding programme has not started';
        }

        if (! self::hasEndline($record)) {
            return 'Endline measurement not recorded';
        }

        if (self::wasEnrolled($record) && ! $attended) {
            return 'No confirmed feeding session on record';
        }

        return '';
    }
}
