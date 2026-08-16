<?php

namespace App\Support;

use App\Models\Institution;

/**
 * Decides whether a learner is an at-risk feeding beneficiary.
 *
 * Two invariants this class exists to protect:
 *
 * 1. At-risk is derived from feeding-session attendance and NOTHING else. A
 *    learner is never flagged for being wasted, underweight, or any other
 *    nutritional status — those describe the problem the programme is treating,
 *    not a failure to show up.
 *
 * 2. An unconfirmed mark is not an absence. A photographed sheet that came back
 *    unreadable for one learner yields NULL, and NULL is excluded from both the
 *    numerator and the denominator. Counting it as an absence would push a
 *    learner toward the flag on evidence no human has read; counting it as
 *    attendance would hide a real gap. Neither is honest, so it does not vote.
 *
 * 3. A rate is not a classification until there is enough history behind it.
 *    One of four recorded sessions is 25%, and 25% is under every threshold a
 *    school might set — but four sessions is not a programme problem, it is a
 *    sample too small to judge a child on, and flagging it would put a learner
 *    on a follow-up list on the strength of a single missed morning. So the
 *    threshold is applied only after `minimumObservationDays` confirmed
 *    sessions; before that the learner is EARLY MONITORING. The rate is still
 *    computed and still shown — it simply does not classify yet.
 *
 *    The window is counted in the learner's own **confirmed** sessions, not in
 *    programme days elapsed, because that is the denominator the rate is taken
 *    over. A school on feeding day 20 that only recorded four sheets has four
 *    days of evidence about that learner, not twenty; the sixteen days nobody
 *    marked are missing records, never absences (see invariant 2).
 *
 * The rule is config-driven (config/feeding.php) because schools tune it. It is
 * deliberately pure — given marks in, a verdict out — so the consequences are
 * testable without a database.
 */
class FeedingAtRiskRule
{
    public const MODE_ATTENDANCE_RATE = 'attendance_rate';

    public const MODE_CONSECUTIVE_ABSENCES = 'consecutive_absences';

    /** Too little attendance history to classify on — see invariant 3. */
    public const STATUS_EARLY_MONITORING = 'early_monitoring';

    /** Enough history, and below the school's threshold. */
    public const STATUS_AT_RISK = 'at_risk';

    /** Enough history, and at or above the school's threshold. */
    public const STATUS_ON_TRACK = 'on_track';

    /** The approved programme default, used by any school that has not set its own. */
    public const DEFAULT_THRESHOLD_PERCENT = 80.0;

    /** The approved observation window, in confirmed feeding days. */
    public const DEFAULT_MINIMUM_OBSERVATION_DAYS = 10;

    public function __construct(
        private readonly string $mode,
        private readonly float $thresholdPercent,
        private readonly int $consecutiveAbsences,
        private readonly int $minimumObservationDays,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('feeding.at_risk.mode', self::MODE_ATTENDANCE_RATE),
            (float) config('feeding.at_risk.threshold_percent', self::DEFAULT_THRESHOLD_PERCENT),
            (int) config('feeding.at_risk.consecutive_absences', 3),
            (int) config('feeding.at_risk.minimum_observation_days', self::DEFAULT_MINIMUM_OBSERVATION_DAYS),
        );
    }

    /**
     * The rule as one school has set it.
     *
     * Both figures are school-configurable (`institutions.feeding_at_risk_threshold`
     * and `feeding_min_observation_days`, set by the System Admin) because a
     * programme's attendance expectation and the history it wants before acting
     * on one are local policy, not platform constants. A school that has set
     * nothing keeps the app default, so the figures move with the programme
     * rather than being frozen at whatever they were when the columns shipped.
     *
     * Everything that computes, displays, or explains at-risk must go through
     * here, or two screens will disagree about who is flagged.
     */
    public static function forInstitution(?int $institutionId): self
    {
        $rule = self::fromConfig();

        if (! $institutionId) {
            return $rule;
        }

        $columns = array_values(array_filter([
            SchemaCache::hasColumn('institutions', 'feeding_at_risk_threshold') ? 'feeding_at_risk_threshold' : null,
            SchemaCache::hasColumn('institutions', 'feeding_min_observation_days') ? 'feeding_min_observation_days' : null,
        ]));

        if ($columns === []) {
            return $rule;
        }

        // One read for both settings: this runs on every page of the feeding
        // module, and they are two halves of one policy. Read off the raw
        // attributes, since a column the migration has not added yet is simply
        // not in the select list.
        $settings = (array) Institution::query()->whereKey($institutionId)->first($columns)?->getAttributes();

        $threshold = $settings['feeding_at_risk_threshold'] ?? null;
        // 0 is a real answer here — "classify from the first confirmed session"
        // — so only NULL falls back to the app default.
        $minimum = $settings['feeding_min_observation_days'] ?? null;

        return new self(
            $rule->mode,
            ($threshold === null || (float) $threshold <= 0) ? $rule->thresholdPercent : (float) $threshold,
            $rule->consecutiveAbsences,
            $minimum === null ? $rule->minimumObservationDays : max(0, (int) $minimum),
        );
    }

    /**
     * Whether the school's rule has flagged this learner.
     *
     * Returns false while the learner is still inside the observation window:
     * a verdict on too little history is not a verdict, and everything that
     * counts, lists or exports at-risk learners reads this method, so the
     * window cannot be honoured on one screen and skipped on another.
     *
     * @param  list<bool|null>  $marks  session marks in date order; NULL = unconfirmed
     */
    public function isAtRisk(array $marks): bool
    {
        $confirmed = array_values(array_filter($marks, static fn ($m) => $m !== null));

        // No confirmed sessions means no evidence either way — never flag.
        // Too few of them means not enough evidence yet — also never flag.
        if (! $this->hasEnoughObservation($marks)) {
            return false;
        }

        return $this->mode === self::MODE_CONSECUTIVE_ABSENCES
            ? $this->hasAbsenceRun($confirmed)
            : $this->isBelowRate($confirmed);
    }

    /**
     * Where this learner stands: Early Monitoring, At Risk, or On Track.
     *
     * The three states are exhaustive and mutually exclusive, so a screen can
     * render one badge from one call and never show a learner as both.
     *
     * @param  list<bool|null>  $marks
     */
    public function status(array $marks): string
    {
        if (! $this->hasEnoughObservation($marks)) {
            return self::STATUS_EARLY_MONITORING;
        }

        return $this->isAtRisk($marks) ? self::STATUS_AT_RISK : self::STATUS_ON_TRACK;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_AT_RISK => 'At Risk',
            self::STATUS_ON_TRACK => 'On Track',
            default => 'Early Monitoring',
        };
    }

    /**
     * Whether this learner has enough confirmed history for the threshold to
     * mean anything.
     *
     * A learner with no confirmed session never has enough, whatever the window
     * is set to: the observation period can be waived, but "no evidence" cannot
     * become evidence.
     *
     * @param  list<bool|null>  $marks
     */
    public function hasEnoughObservation(array $marks): bool
    {
        return $this->confirmedCount($marks) >= max(1, $this->minimumObservationDays);
    }

    /** How many more confirmed sessions before the threshold starts classifying. */
    public function sessionsUntilClassification(array $marks): int
    {
        return max(0, max(1, $this->minimumObservationDays) - $this->confirmedCount($marks));
    }

    /** Sessions a human has decided — the denominator every rate here is taken over. */
    public function confirmedCount(array $marks): int
    {
        return count(array_filter($marks, static fn ($m) => $m !== null));
    }

    /** Attended percentage of confirmed sessions, or null when there are none. */
    public function attendanceRate(array $marks): ?float
    {
        $confirmed = array_values(array_filter($marks, static fn ($m) => $m !== null));

        if ($confirmed === []) {
            return null;
        }

        $present = count(array_filter($confirmed, static fn ($m) => $m === true));

        return round(($present / count($confirmed)) * 100, 1);
    }

    /** Confirmed sessions the learner attended — what the roster displays. */
    public function presentCount(array $marks): int
    {
        return count(array_filter($marks, static fn ($m) => $m === true));
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function thresholdPercent(): float
    {
        return $this->thresholdPercent;
    }

    public function consecutiveAbsences(): int
    {
        return $this->consecutiveAbsences;
    }

    /** The confirmed feeding days a learner needs before the threshold applies. */
    public function minimumObservationDays(): int
    {
        return $this->minimumObservationDays;
    }

    /** A short line the UI can show so staff know what the flag currently means. */
    public function describe(): string
    {
        $test = $this->mode === self::MODE_CONSECUTIVE_ABSENCES
            ? $this->consecutiveAbsences.' or more absences in a row'
            : 'attendance below '.rtrim(rtrim(number_format($this->thresholdPercent, 1), '0'), '.').'%';

        // A window of 0 or 1 is no window at all, so it is not worth a clause
        // that would only make the sentence longer.
        return $this->minimumObservationDays > 1
            ? $test.', after at least '.$this->minimumObservationDays.' recorded feeding days'
            : $test;
    }

    /** The observation window on its own, for a screen that names it separately. */
    public function describeObservation(): string
    {
        return $this->minimumObservationDays > 1
            ? 'the first '.$this->minimumObservationDays.' recorded feeding days'
            : 'the first recorded feeding day';
    }

    /** @param  list<bool>  $confirmed */
    private function isBelowRate(array $confirmed): bool
    {
        $present = count(array_filter($confirmed, static fn ($m) => $m === true));

        return (($present / count($confirmed)) * 100) < $this->thresholdPercent;
    }

    /**
     * A run is counted over confirmed sessions only. An unconfirmed session in
     * the middle of a run does not break it — it is simply not a data point —
     * which keeps an unreadable photo from masking a genuine streak.
     *
     * @param  list<bool>  $confirmed
     */
    private function hasAbsenceRun(array $confirmed): bool
    {
        if ($this->consecutiveAbsences < 1) {
            return false;
        }

        $run = 0;
        foreach ($confirmed as $mark) {
            $run = $mark === false ? $run + 1 : 0;
            if ($run >= $this->consecutiveAbsences) {
                return true;
            }
        }

        return false;
    }
}
