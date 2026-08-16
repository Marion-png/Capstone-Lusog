<?php

namespace App\Support;

/**
 * How urgently one beneficiary needs following up — the operational reading of
 * attendance the At-Risk Students tab sorts its list by.
 *
 * This class layers a monitoring band on top of FeedingAtRiskRule; it never
 * replaces it. The distinction is the whole point:
 *
 * - **At Risk** is the school's business rule (FeedingAtRiskRule::isAtRisk) —
 *   below the configured threshold. It is the ONLY thing the official at-risk
 *   figure counts, on this tab and everywhere else.
 * - **Critical** is an at-risk learner who is far below the threshold or whose
 *   attendance is falling fast. Still at risk, drawn louder.
 * - **Watch** is a learner ABOVE the threshold but close enough to it to be
 *   worth a look. A Watch learner is deliberately NOT at risk and must never be
 *   added to the at-risk count, or the tab would report a different programme
 *   size than the Dashboard and the Beneficiaries tab.
 * - **Observing** is a learner still inside the rule's observation window
 *   (FeedingAtRiskRule invariant 3). It outranks every other band: a learner
 *   with four confirmed sessions at 25% is neither At Risk nor Watch — there is
 *   simply not enough history to say which, and calling 25% "Watch" (a band
 *   that means *above* the threshold) would be plainly wrong.
 *
 * Both bands are derived from the school's own threshold plus a configured
 * margin (config/feeding.php), so a school that moves its threshold moves the
 * bands with it and nothing here has to be re-tuned.
 *
 * Like the rule it wraps, this is pure: marks in, a verdict out, no database.
 */
final class FeedingRiskSeverity
{
    /** Below the threshold, and far below it or falling fast. */
    public const CRITICAL = 'critical';

    /** Below the threshold — the official business rule. */
    public const AT_RISK = 'at_risk';

    /** Above the threshold, inside the monitoring band. */
    public const WATCH = 'watch';

    /** Above the monitoring band — nothing to follow up. */
    public const STEADY = 'steady';

    /** Inside the observation window — not yet classified either way. */
    public const OBSERVING = 'observing';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_MEDIUM = 'medium';

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NONE = 'none';

    /**
     * A trend needs enough sessions to have one: fewer than this many confirmed
     * marks and the answer is "not enough evidence", never a direction.
     */
    private const TREND_MIN_SESSIONS = 4;

    /** How many percentage points of movement read as a real change of direction. */
    private const TREND_DELTA_POINTS = 10.0;

    /** The top of the monitoring band: the school's threshold plus its margin. */
    public static function watchThresholdPercent(FeedingAtRiskRule $rule): float
    {
        $margin = max(0.0, (float) config('feeding.at_risk.watch_margin_percent', 5));

        return min(100.0, $rule->thresholdPercent() + $margin);
    }

    /** Below this, an at-risk learner is drawn as Critical rather than At Risk. */
    public static function criticalThresholdPercent(FeedingAtRiskRule $rule): float
    {
        $margin = max(0.0, (float) config('feeding.at_risk.critical_margin_percent', 10));

        return max(0.0, $rule->thresholdPercent() - $margin);
    }

    /**
     * One learner's standing, from one reading of their marks.
     *
     * `at_risk` is the rule's own verdict and is what the at-risk figure counts;
     * `severity` and `priority` only decide how the row is drawn and where it
     * sorts.
     *
     * @param  list<bool|null>  $marks  session marks in date order; NULL = unconfirmed
     * @return array{present: int, absent: int, confirmed: int, unconfirmed: int, rate: ?float, at_risk: bool, observing: bool, sessions_until_classification: int, status: string, severity: string, priority: string, trend: ?string, reason: string}
     */
    public static function evaluate(array $marks, FeedingAtRiskRule $rule): array
    {
        $confirmed = count(array_filter($marks, static fn ($mark) => $mark !== null));
        $present = $rule->presentCount($marks);
        $rate = $rule->attendanceRate($marks);
        $atRisk = $rule->isAtRisk($marks);
        $observing = ! $rule->hasEnoughObservation($marks);
        $trend = self::trend($marks);

        $severity = self::severity($atRisk, $observing, $rate, $trend, $rule);

        return [
            'present' => $present,
            'absent' => $confirmed - $present,
            'confirmed' => $confirmed,
            'unconfirmed' => count($marks) - $confirmed,
            // Null, not zero: no confirmed session is not a 0% turnout.
            'rate' => $rate,
            'at_risk' => $atRisk,
            // Still inside the observation window: a rate, but not yet a verdict.
            'observing' => $observing,
            'sessions_until_classification' => $rule->sessionsUntilClassification($marks),
            'status' => $rule->status($marks),
            'severity' => $severity,
            'priority' => self::priority($severity, $rate, $rule),
            'trend' => $trend,
            'reason' => self::reason($severity, $rate, $trend, $rule),
        ];
    }

    /**
     * Whether attendance is improving, declining, or holding steady — the most
     * recent sessions against everything before them.
     *
     * Unconfirmed marks are excluded, exactly as the rule excludes them: a
     * direction read off marks nobody has confirmed would be a guess.
     *
     * @param  list<bool|null>  $marks
     */
    public static function trend(array $marks): ?string
    {
        $confirmed = array_values(array_filter($marks, static fn ($mark) => $mark !== null));
        $count = count($confirmed);

        if ($count < self::TREND_MIN_SESSIONS) {
            return null;
        }

        $window = max(2, min(5, intdiv($count, 2)));
        $recent = array_slice($confirmed, -$window);
        $earlier = array_slice($confirmed, 0, $count - $window);

        if ($earlier === []) {
            return null;
        }

        $rateOf = static fn (array $window): float => (count(array_filter($window, static fn ($mark) => $mark === true)) / count($window)) * 100;
        $delta = $rateOf($recent) - $rateOf($earlier);

        return match (true) {
            $delta >= self::TREND_DELTA_POINTS => 'improving',
            $delta <= -self::TREND_DELTA_POINTS => 'declining',
            default => 'steady',
        };
    }

    /** The last few confirmed marks, oldest first — the sequence the detail view prints. */
    public static function recentMarks(array $marks, int $limit = 5): array
    {
        $confirmed = array_values(array_filter($marks, static fn ($mark) => $mark !== null));

        return array_slice($confirmed, -$limit);
    }

    public static function severityLabel(string $severity): string
    {
        return match ($severity) {
            self::CRITICAL => 'Critical',
            self::AT_RISK => 'At Risk',
            self::WATCH => 'Watch',
            self::OBSERVING => 'Early Monitoring',
            default => 'Steady',
        };
    }

    public static function priorityLabel(string $priority): string
    {
        return match ($priority) {
            self::PRIORITY_HIGH => 'High',
            self::PRIORITY_MEDIUM => 'Medium',
            self::PRIORITY_LOW => 'Low',
            default => '—',
        };
    }

    /** Worst first: the order the follow-up list is worked through. */
    public static function priorityRank(string $priority): int
    {
        return match ($priority) {
            self::PRIORITY_HIGH => 0,
            self::PRIORITY_MEDIUM => 1,
            self::PRIORITY_LOW => 2,
            default => 3,
        };
    }

    private static function severity(bool $atRisk, bool $observing, ?float $rate, ?string $trend, FeedingAtRiskRule $rule): string
    {
        // Checked first, and before Watch especially: Watch means "above the
        // threshold", and a learner at 25% of four sessions is not above
        // anything — they are unclassified, which is its own answer.
        if ($observing) {
            return self::OBSERVING;
        }

        if ($atRisk) {
            $farBelow = $rate !== null && $rate < self::criticalThresholdPercent($rule);

            return ($farBelow || $trend === 'declining') ? self::CRITICAL : self::AT_RISK;
        }

        // A learner no confirmed session has covered has no standing at all —
        // never Watch, which would put an unmeasured child on a follow-up list.
        if ($rate !== null && $rate < self::watchThresholdPercent($rule)) {
            return self::WATCH;
        }

        return self::STEADY;
    }

    private static function priority(string $severity, ?float $rate, FeedingAtRiskRule $rule): string
    {
        if ($severity === self::CRITICAL) {
            return self::PRIORITY_HIGH;
        }

        if ($severity === self::AT_RISK) {
            return self::PRIORITY_MEDIUM;
        }

        // Early Monitoring lands here too, and correctly: there is nothing to
        // prioritise until the rule has enough history to classify on.
        if ($severity !== self::WATCH) {
            return self::PRIORITY_NONE;
        }

        // Inside the watch band, the half nearer the threshold is the half worth
        // acting on first.
        $midpoint = ($rule->thresholdPercent() + self::watchThresholdPercent($rule)) / 2;

        return ($rate !== null && $rate < $midpoint) ? self::PRIORITY_MEDIUM : self::PRIORITY_LOW;
    }

    /**
     * Why this learner is on the list, in one sentence about attendance.
     *
     * Deliberately operational: it reports the figure and the rule it failed,
     * and never offers a reason for the absences or a conclusion about the
     * child — that is not something an attendance record can support.
     */
    private static function reason(string $severity, ?float $rate, ?string $trend, FeedingAtRiskRule $rule): string
    {
        $threshold = self::format($rule->thresholdPercent());
        $rateLabel = $rate !== null ? self::format($rate).'%' : null;

        if ($severity === self::CRITICAL && $trend === 'declining' && $rate !== null && $rate >= self::criticalThresholdPercent($rule)) {
            return 'Feeding attendance is below the configured '.$threshold.'% threshold and has fallen sharply over recent sessions.';
        }

        return match ($severity) {
            self::CRITICAL => 'Cumulative feeding attendance'.($rateLabel ? ' ('.$rateLabel.')' : '').' is far below the configured '.$threshold.'% threshold.',
            self::AT_RISK => 'Cumulative feeding attendance has fallen below the configured '.$threshold.'% threshold.',
            self::WATCH => 'Cumulative feeding attendance is above the '.$threshold.'% threshold but within the '.self::format(self::watchThresholdPercent($rule)).'% monitoring band.',
            // Says what is missing, not what the rate means: too few recorded
            // sessions is a gap in the evidence, never a finding about the child.
            self::OBSERVING => 'Attendance'.($rateLabel ? ' ('.$rateLabel.')' : '').' is still inside the minimum observation period of '
                .$rule->minimumObservationDays().' recorded feeding days, so the '.$threshold.'% threshold does not classify this learner yet.',
            default => 'Cumulative feeding attendance is above the configured '.$threshold.'% threshold.',
        };
    }

    private static function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }
}
