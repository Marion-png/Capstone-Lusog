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

    /**
     * The rule Sta. Ana actually runs: a beneficiary is flagged once they have
     * missed a week of feeding without an excuse.
     *
     * It is not a percentage of the cycle at all. A learner who has attended
     * every session for two months and then vanishes for a week is 90-something
     * per cent attended and needs following up today; a rate threshold would
     * not notice them until weeks later. The unit is the *feeding week*, so the
     * default is four sessions (Monday-Thursday, the days the budget covers).
     *
     * Only unexcused absences count — an excused one is the coordinator's
     * "buffer" and reaches this class as NULL (see FeedingAttendanceMark).
     */
    public const MODE_UNEXCUSED_ABSENCE_DAYS = 'unexcused_absence_days';

    /** The modes a school may be set to; anything else falls back to the rate. */
    public const MODES = [
        self::MODE_ATTENDANCE_RATE,
        self::MODE_CONSECUTIVE_ABSENCES,
        self::MODE_UNEXCUSED_ABSENCE_DAYS,
    ];

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

    /**
     * One feeding week, in sessions.
     *
     * Monday to Thursday is what the budget covers, so a feeding week is four
     * days and not five. It is a constant rather than a figure typed into each
     * sentence because the rule is *stated* in weeks — "a week of unexcused
     * absence" — and only counted in days.
     */
    public const FEEDING_DAYS_PER_WEEK = 4;

    /** One feeding week: Monday to Thursday, the days the budget covers. */
    public const DEFAULT_ABSENCE_FLAG_DAYS = self::FEEDING_DAYS_PER_WEEK;

    /** Two feeding weeks — the point at which removal is worth reviewing. */
    public const DEFAULT_ABSENCE_REMOVAL_DAYS = self::FEEDING_DAYS_PER_WEEK * 2;

    public function __construct(
        private readonly string $mode,
        private readonly float $thresholdPercent,
        private readonly int $consecutiveAbsences,
        private readonly int $minimumObservationDays,
        private readonly int $absenceFlagDays = self::DEFAULT_ABSENCE_FLAG_DAYS,
        private readonly int $absenceRemovalDays = self::DEFAULT_ABSENCE_REMOVAL_DAYS,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('feeding.at_risk.mode', self::MODE_ATTENDANCE_RATE),
            (float) config('feeding.at_risk.threshold_percent', self::DEFAULT_THRESHOLD_PERCENT),
            (int) config('feeding.at_risk.consecutive_absences', 3),
            (int) config('feeding.at_risk.minimum_observation_days', self::DEFAULT_MINIMUM_OBSERVATION_DAYS),
            (int) config('feeding.at_risk.absence_flag_days', self::DEFAULT_ABSENCE_FLAG_DAYS),
            (int) config('feeding.at_risk.absence_removal_days', self::DEFAULT_ABSENCE_REMOVAL_DAYS),
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

        $columns = array_values(array_filter(array_map(
            fn (string $column): ?string => SchemaCache::hasColumn('institutions', $column) ? $column : null,
            [
                'feeding_at_risk_threshold',
                'feeding_min_observation_days',
                'feeding_at_risk_mode',
                'feeding_absence_flag_days',
                'feeding_absence_removal_days',
            ]
        )));

        if ($columns === []) {
            return $rule;
        }

        // One read for every setting: this runs on every page of the feeding
        // module, and they are all halves of one policy. Read off the raw
        // attributes, since a column the migration has not added yet is simply
        // not in the select list.
        $settings = (array) Institution::query()->whereKey($institutionId)->first($columns)?->getAttributes();

        $threshold = $settings['feeding_at_risk_threshold'] ?? null;
        // 0 is a real answer here — "classify from the first confirmed session"
        // — so only NULL falls back to the app default.
        $minimum = $settings['feeding_min_observation_days'] ?? null;
        // Which rule this school runs at all. A school that has chosen nothing
        // keeps the platform default rather than being switched to somebody
        // else's policy, and an unrecognised value is treated as unset.
        $mode = (string) ($settings['feeding_at_risk_mode'] ?? '');
        $flagDays = $settings['feeding_absence_flag_days'] ?? null;
        $removalDays = $settings['feeding_absence_removal_days'] ?? null;

        return new self(
            in_array($mode, self::MODES, true) ? $mode : $rule->mode,
            ($threshold === null || (float) $threshold <= 0) ? $rule->thresholdPercent : (float) $threshold,
            $rule->consecutiveAbsences,
            $minimum === null ? $rule->minimumObservationDays : max(0, (int) $minimum),
            ($flagDays === null || (int) $flagDays < 1) ? $rule->absenceFlagDays : (int) $flagDays,
            ($removalDays === null || (int) $removalDays < 1) ? $rule->absenceRemovalDays : (int) $removalDays,
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

        return match ($this->mode) {
            self::MODE_CONSECUTIVE_ABSENCES => $this->hasAbsenceRun($confirmed, $this->consecutiveAbsences),
            self::MODE_UNEXCUSED_ABSENCE_DAYS => $this->hasAbsenceRun($confirmed, $this->absenceFlagDays),
            default => $this->isBelowRate($confirmed),
        };
    }

    /**
     * Whether this learner's absence has run long enough that the school's own
     * removal review opens — the second, later threshold the coordinator
     * described: roughly a fortnight of unexcused absence, at which point the
     * adviser confirms whether the learner is coming back and, if not, the slot
     * goes to somebody on the waiting list.
     *
     * It is deliberately NOT a removal. Nothing in this application takes a
     * child off the feeding line on the strength of an attendance count; this
     * only says the question is now worth asking of a human, who records the
     * answer with a reason (FeedingEnrollmentController::remove).
     *
     * @param  list<bool|null>  $marks
     */
    public function needsRemovalReview(array $marks): bool
    {
        $confirmed = array_values(array_filter($marks, static fn ($m) => $m !== null));

        return $this->hasAbsenceRun($confirmed, $this->absenceRemovalDays);
    }

    /** The learner's current run of unexcused absences, most recent first. */
    public function currentAbsenceRun(array $marks): int
    {
        $run = 0;

        foreach (array_reverse($marks) as $mark) {
            if ($mark === null) {
                // Excused, or nobody has read it: not a data point either way,
                // so it neither extends the run nor ends it.
                continue;
            }
            if ($mark === true) {
                break;
            }
            $run++;
        }

        return $run;
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
        return $this->confirmedCount($marks) >= $this->observationDays();
    }

    /** How many more confirmed sessions before the threshold starts classifying. */
    public function sessionsUntilClassification(array $marks): int
    {
        return max(0, $this->observationDays() - $this->confirmedCount($marks));
    }

    /**
     * The window this mode actually needs, in confirmed sessions.
     *
     * For a rate the window is the school's own setting: a percentage over four
     * sessions is a sample, not a finding. The unexcused-absence rule carries
     * its own evidence requirement instead — a full week of missed feeding IS
     * the finding, and holding it back for another six sessions would mean the
     * coordinator learning about it a fortnight after the child stopped coming,
     * which is the delay the rule exists to remove. So it needs whichever is
     * smaller: the school's window, or the run that triggers it.
     */
    private function observationDays(): int
    {
        $window = max(1, $this->minimumObservationDays);

        return $this->mode === self::MODE_UNEXCUSED_ABSENCE_DAYS
            ? min($window, max(1, $this->absenceFlagDays))
            : $window;
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

    /** Unexcused absences in a row that flag a learner — one feeding week. */
    public function absenceFlagDays(): int
    {
        return $this->absenceFlagDays;
    }

    /** Unexcused absences in a row that open a removal review — two feeding weeks. */
    public function absenceRemovalDays(): int
    {
        return $this->absenceRemovalDays;
    }

    /** A short line the UI can show so staff know what the flag currently means. */
    public function describe(): string
    {
        $test = $this->describeThreshold();

        // A window of 0 or 1 is no window at all, so it is not worth a clause
        // that would only make the sentence longer.
        return $this->minimumObservationDays > 1
            ? $test.', after at least '.$this->minimumObservationDays.' recorded feeding days'
            : $test;
    }

    /**
     * The test on its own, without the observation clause — what a KPI card's
     * hint has room for.
     *
     * One `match`, so a card and a sentence can never name different rules;
     * a card that wants sentence case applies `ucfirst()` to it.
     */
    public function describeThreshold(): string
    {
        return match ($this->mode) {
            self::MODE_CONSECUTIVE_ABSENCES => $this->consecutiveAbsences.' or more absences in a row',
            // Said in weeks, because that is the unit the rule was written in
            // and the one a coordinator checks against a calendar; the days
            // only mean anything against the sessions the school actually
            // feeds.
            self::MODE_UNEXCUSED_ABSENCE_DAYS => $this->runLabel($this->absenceFlagDays).' of consecutive unexcused absences',
            default => 'attendance below '.rtrim(rtrim(number_format($this->thresholdPercent, 1), '0'), '.').'%',
        };
    }

    /**
     * A run of feeding days, said the way the coordinator says it.
     *
     * A whole number of feeding weeks is stated in weeks — a run of four is
     * "1 week", not "4 days" — because that is how the rule is written and
     * checked. Anything that is not a whole number of weeks is stated in days,
     * since rounding it to one would misstate the rule.
     */
    private function runLabel(int $days): string
    {
        if ($days >= self::FEEDING_DAYS_PER_WEEK && $days % self::FEEDING_DAYS_PER_WEEK === 0) {
            $weeks = intdiv($days, self::FEEDING_DAYS_PER_WEEK);

            return $weeks.' '.($weeks === 1 ? 'week' : 'weeks');
        }

        return $days.' '.($days === 1 ? 'day' : 'days');
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
     * which keeps an unreadable photo from masking a genuine streak. An
     * *excused* absence reaches this class as NULL for the same reason and
     * behaves the same way: the buffer means a fasting learner is never pushed
     * toward the flag, not that a week away resets because one day of it had a
     * note.
     *
     * @param  list<bool>  $confirmed
     */
    private function hasAbsenceRun(array $confirmed, int $required): bool
    {
        if ($required < 1) {
            return false;
        }

        $run = 0;
        foreach ($confirmed as $mark) {
            $run = $mark === false ? $run + 1 : 0;
            if ($run >= $required) {
                return true;
            }
        }

        return false;
    }
}
