<?php

namespace App\Support;

/**
 * Sheet 1's medical and family history checklist
 * (`student_details.health_history`), read the one way every page reads it.
 *
 * Testers found Allergies and Hospitalization/Surgery unticked beside the
 * details typed for them (report #8). The checklist round-trips intact — the
 * form, the save, the database and every page agree on the keys — but each
 * of those two boxes has its own detail line, open whether the box is ticked
 * or not, so a detail could be saved under an unticked box, and every page
 * then showed the details beside an empty box. A detail written for one of
 * them is the parent's or adviser's answer that the learner has it, so the
 * box follows it: on save, on every read (so records saved before read right
 * too), and as it is typed (the form ticks it).
 *
 * A detail that says there is nothing ("wala", "none", "N/A") does not tick
 * anything (ConsentFormScanner::saysSomething).
 */
final class HealthHistory
{
    /** Each detail line, keyed to the box it belongs to. */
    public const DETAIL_FLAGS = [
        'allergies_detail' => 'med_allergies',
        'hospitalization_detail' => 'med_hospitalization',
    ];

    /**
     * @param  array<string, mixed>  $history
     * @return array<string, mixed>
     */
    public static function normalize(array $history): array
    {
        foreach (self::DETAIL_FLAGS as $detail => $flag) {
            if (ConsentFormScanner::saysSomething(trim((string) ($history[$detail] ?? '')))) {
                $history[$flag] = true;
            }
        }

        return PostureGait::normalizeHistory($history);
    }

    /**
     * The checklist off a record's details, normalized; empty when none.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public static function fromDetails(array $details): array
    {
        return is_array($details['health_history'] ?? null) ? self::normalize($details['health_history']) : [];
    }

    /**
     * Whether a stored checklist has a detail under an unticked box — what
     * the data check lists and repairs.
     *
     * @param  array<string, mixed>  $history
     */
    public static function needsRepair(array $history): bool
    {
        foreach (self::DETAIL_FLAGS as $detail => $flag) {
            if (ConsentFormScanner::saysSomething(trim((string) ($history[$detail] ?? '')))
                && ! filter_var($history[$flag] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }
        }

        return false;
    }
}
