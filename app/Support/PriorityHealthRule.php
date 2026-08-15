<?php

namespace App\Support;

use App\Models\HealthAssessment;
use App\Models\StudentHealthRecord;

/**
 * Decides whether a learner is a "Priority" case.
 *
 * A learner is Priority when a chronic or potentially life-threatening
 * condition — asthma, diabetes, a seizure disorder, a heart condition,
 * tuberculosis or severe allergies — is recorded anywhere the class adviser
 * can record one. There are two such places, and both count:
 *
 *  1. the Health Assessment form            -> health_assessments columns
 *  2. the student profile's Health History  -> student_details['health_history']
 *
 * The second form is older and names three fields differently (med_seizure,
 * med_heart), which is why config/health.php carries a map per source. The
 * labels are identical on purpose, so a learner recorded in both places
 * yields one Priority entry rather than a duplicate.
 *
 * Three properties this rule deliberately has:
 *
 *  - It is DERIVED, never stored and never manually set. Correcting either
 *    form corrects the flag on the next page load; there is no second copy
 *    of the truth to drift.
 *  - It is NOT the feeding programme's at-risk flag. That one comes from
 *    attendance and means something else entirely; conflating the two would
 *    make both meaningless.
 *  - It reads in PHP, never in SQL. Both sources are encrypted at rest, so
 *    no WHERE clause can see them.
 */
class PriorityHealthRule
{
    /**
     * Health Assessment fields that raise the flag, as field => label.
     *
     * @return array<string, string>
     */
    public static function conditions(): array
    {
        $configured = config('health.priority.conditions', []);

        return is_array($configured) ? $configured : [];
    }

    /**
     * Student-profile Health History fields that raise the flag.
     *
     * @return array<string, string>
     */
    public static function profileConditions(): array
    {
        $configured = config('health.priority.profile_conditions', []);

        return is_array($configured) ? $configured : [];
    }

    /**
     * Which conditions are recorded for this learner, across both forms.
     *
     * Returns the human labels, so callers can say *why* a learner is
     * Priority rather than only that they are. Nothing recorded anywhere
     * yields an empty list — unknown is not the same as clear.
     *
     * @return list<string>
     */
    public static function reasonsFor(?HealthAssessment $assessment, ?StudentHealthRecord $record = null): array
    {
        $reasons = [];

        // 1. The Health Assessment form.
        if ($assessment !== null) {
            foreach (self::conditions() as $field => $label) {
                // The cast returns a real bool; legacy plaintext '1'/'0'
                // still reads correctly through App\Casts\EncryptedBoolean.
                if ((bool) ($assessment->{$field} ?? false)) {
                    $reasons[] = $label;
                }
            }
        }

        // 2. The student profile's Health History checklist.
        foreach (self::historyFlags($record) as $field => $value) {
            $label = self::profileConditions()[$field] ?? null;
            if ($label !== null && self::isTicked($value)) {
                $reasons[] = $label;
            }
        }

        // A learner recorded in both places is one entry, not two.
        return array_values(array_unique($reasons));
    }

    /** Whether this learner is a Priority case. */
    public static function applies(?HealthAssessment $assessment, ?StudentHealthRecord $record = null): bool
    {
        return self::reasonsFor($assessment, $record) !== [];
    }

    /**
     * A short summary for a badge or tooltip, e.g. "Asthma, Diabetes".
     */
    public static function summaryFor(?HealthAssessment $assessment, ?StudentHealthRecord $record = null): string
    {
        return implode(', ', self::reasonsFor($assessment, $record));
    }

    /**
     * The health_history checklist off a record's encrypted details blob.
     *
     * @return array<string, mixed>
     */
    private static function historyFlags(?StudentHealthRecord $record): array
    {
        if ($record === null) {
            return [];
        }

        $details = $record->student_details;
        if (! is_array($details)) {
            return [];
        }

        $history = $details['health_history'] ?? null;

        return is_array($history) ? $history : [];
    }

    /**
     * A checkbox the adviser ticked.
     *
     * The profile form round-trips through session and an encrypted JSON
     * column, so the same answer can come back as true, 1, or "1" depending
     * on the path. Only an explicit affirmative counts — "0" and "" do not.
     */
    private static function isTicked(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
