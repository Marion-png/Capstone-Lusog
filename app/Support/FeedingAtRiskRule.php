<?php

namespace App\Support;

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
 * The rule is config-driven (config/feeding.php) because schools tune it. It is
 * deliberately pure — given marks in, a verdict out — so the consequences are
 * testable without a database.
 */
class FeedingAtRiskRule
{
    public const MODE_ATTENDANCE_RATE = 'attendance_rate';

    public const MODE_CONSECUTIVE_ABSENCES = 'consecutive_absences';

    public function __construct(
        private readonly string $mode,
        private readonly float $thresholdPercent,
        private readonly int $consecutiveAbsences,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('feeding.at_risk.mode', self::MODE_ATTENDANCE_RATE),
            (float) config('feeding.at_risk.threshold_percent', 75),
            (int) config('feeding.at_risk.consecutive_absences', 3),
        );
    }

    /**
     * @param  list<bool|null>  $marks  session marks in date order; NULL = unconfirmed
     */
    public function isAtRisk(array $marks): bool
    {
        $confirmed = array_values(array_filter($marks, static fn ($m) => $m !== null));

        // No confirmed sessions means no evidence either way — never flag.
        if ($confirmed === []) {
            return false;
        }

        return $this->mode === self::MODE_CONSECUTIVE_ABSENCES
            ? $this->hasAbsenceRun($confirmed)
            : $this->isBelowRate($confirmed);
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

    /** A short line the UI can show so staff know what the flag currently means. */
    public function describe(): string
    {
        return $this->mode === self::MODE_CONSECUTIVE_ABSENCES
            ? $this->consecutiveAbsences.' or more absences in a row'
            : 'attendance below '.rtrim(rtrim(number_format($this->thresholdPercent, 1), '0'), '.').'%';
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
