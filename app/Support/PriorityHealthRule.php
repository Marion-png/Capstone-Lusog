<?php

namespace App\Support;

use App\Models\HealthAssessment;

/**
 * Decides whether a learner is a "Priority" case.
 *
 * A learner is Priority when the class adviser's health assessment reports a
 * chronic or potentially life-threatening condition — asthma, diabetes, a
 * seizure disorder, a heart condition, tuberculosis or severe allergies. The
 * set is config-driven (config/health.php) so a school can tune it.
 *
 * Three properties this rule deliberately has:
 *
 *  - It is DERIVED, never stored and never manually set. Correcting an
 *    assessment corrects the flag on the next page load; there is no second
 *    copy of the truth to drift.
 *  - It is NOT the feeding programme's at-risk flag. That one comes from
 *    attendance and means something else entirely; conflating the two would
 *    make both meaningless.
 *  - It reads the assessment in PHP, never in SQL. Those columns are
 *    encrypted at rest, so no WHERE clause can see them.
 */
class PriorityHealthRule
{
    /**
     * The assessment fields that raise the flag, as field => label.
     *
     * @return array<string, string>
     */
    public static function conditions(): array
    {
        $configured = config('health.priority.conditions', []);

        return is_array($configured) ? $configured : [];
    }

    /**
     * Which of the configured conditions this assessment reports.
     *
     * Returns the human labels, so callers can say *why* a learner is
     * Priority rather than only that they are. An absent assessment yields
     * an empty list — unknown is not the same as clear.
     *
     * @return list<string>
     */
    public static function reasonsFor(?HealthAssessment $assessment): array
    {
        if ($assessment === null) {
            return [];
        }

        $reasons = [];

        foreach (self::conditions() as $field => $label) {
            // The cast returns a real bool; a legacy plaintext '1'/'0' still
            // reads correctly through App\Casts\EncryptedBoolean.
            if ((bool) ($assessment->{$field} ?? false)) {
                $reasons[] = $label;
            }
        }

        return $reasons;
    }

    /** Whether this assessment makes the learner a Priority case. */
    public static function applies(?HealthAssessment $assessment): bool
    {
        return self::reasonsFor($assessment) !== [];
    }

    /**
     * A short summary for a badge tooltip, e.g. "Asthma, Diabetes".
     */
    public static function summaryFor(?HealthAssessment $assessment): string
    {
        return implode(', ', self::reasonsFor($assessment));
    }
}
