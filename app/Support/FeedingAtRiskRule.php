<?php

namespace App\Support;

use App\Models\Institution;

/**
 * Decides when a learner's feeding attendance has reached the school's
 * thresholds. It does NOT decide who is flagged: that takes the Feeding
 * Coordinator's confirmation, and is FeedingAttendanceFlag's job.
 *
 * The flow it serves is the one the capstone adviser set:
 *
 *   day 3 of unexcused absence in a row  → an attendance notice asks the
 *                                          coordinator to verify the record
 *                                          (`reachesNotice()`); nobody is flagged
 *   day 7                                → the flag threshold is reached
 *                                          (`thresholdReached()`), and the learner
 *                                          waits for the coordinator to confirm
 *                                          the record before being flagged for
 *                                          follow-up
 *
 * Invariants this class exists to protect:
 *
 * 1. Everything here is derived from feeding-session attendance and NOTHING
 *    else. A learner is never flagged for being wasted, underweight, or any
 *    other nutritional status — those describe the problem the programme is
 *    treating, not a failure to show up.
 *
 * 2. An unconfirmed mark is not an absence. A photographed sheet that came back
 *    unreadable for one learner yields NULL, and NULL is excluded from both the
 *    numerator and the denominator. An excused absence reaches this class as
 *    NULL for the same reason (see FeedingAttendanceMark).
 *
 * 3. A RATE is not a classification until there is enough history behind it.
 *    One of four recorded sessions is 25%, which is a sample, not a finding, so
 *    the rate test applies only after `minimumObservationDays` confirmed
 *    sessions. A run of absences carries its own evidence — seven missed
 *    sessions in a row are seven confirmed sessions — so the run-based modes
 *    only need one confirmed session before they can say "on track".
 *
 * It is deliberately pure — given marks in, an answer out — so every
 * consequence is testable without a database.
 */
class FeedingAtRiskRule
{
    public const MODE_ATTENDANCE_RATE = 'attendance_rate';

    public const MODE_CONSECUTIVE_ABSENCES = 'consecutive_absences';

    /**
     * The rule Sta. Ana runs, and the adviser's: a learner is due to be flagged
     * once they have missed `absenceFlagDays` feeding sessions in a row without
     * an excuse.
     *
     * It is not a percentage of the cycle at all. A learner who has attended
     * every session for two months and then vanishes is 90-something per cent
     * attended and needs following up today; a rate threshold would not notice
     * them until weeks later.
     */
    public const MODE_UNEXCUSED_ABSENCE_DAYS = 'unexcused_absence_days';

    /** The modes a school may be set to; anything else falls back to the default. */
    public const MODES = [
        self::MODE_ATTENDANCE_RATE,
        self::MODE_CONSECUTIVE_ABSENCES,
        self::MODE_UNEXCUSED_ABSENCE_DAYS,
    ];

    /** The approved programme default, used by any school that has not set its own. */
    public const DEFAULT_THRESHOLD_PERCENT = 80.0;

    /** The history a rate needs, in confirmed feeding days. */
    public const DEFAULT_MINIMUM_OBSERVATION_DAYS = 10;

    /** Day 3: the early attendance notice. */
    public const DEFAULT_ABSENCE_NOTICE_DAYS = 3;

    /** Day 7: the flag threshold. */
    public const DEFAULT_ABSENCE_FLAG_DAYS = 7;

    public function __construct(
        private readonly string $mode,
        private readonly float $thresholdPercent,
        private readonly int $consecutiveAbsences,
        private readonly int $minimumObservationDays,
        private readonly int $absenceFlagDays = self::DEFAULT_ABSENCE_FLAG_DAYS,
        private readonly int $absenceNoticeDays = self::DEFAULT_ABSENCE_NOTICE_DAYS,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('feeding.at_risk.mode', self::MODE_ATTENDANCE_RATE),
            (float) config('feeding.at_risk.threshold_percent', self::DEFAULT_THRESHOLD_PERCENT),
            (int) config('feeding.at_risk.consecutive_absences', 3),
            (int) config('feeding.at_risk.minimum_observation_days', self::DEFAULT_MINIMUM_OBSERVATION_DAYS),
            max(1, (int) config('feeding.at_risk.absence_flag_days', self::DEFAULT_ABSENCE_FLAG_DAYS)),
            max(1, (int) config('feeding.at_risk.absence_notice_days', self::DEFAULT_ABSENCE_NOTICE_DAYS)),
        );
    }

    /**
     * Forget the memoized per-school rule settings. The System Admin's
     * threshold form changes them, and must not then be answered from the copy
     * read before the change.
     */
    public static function forgetInstitutionSettings(): void
    {
        RequestMemo::forgetPrefix('at-risk:settings:');
    }

    /**
     * The rule as one school has set it.
     *
     * Every figure is school-configurable (the `institutions.feeding_*`
     * columns, set by the System Admin) because a programme's thresholds are
     * local policy, not platform constants. A school that has set nothing keeps
     * the app default, so its figures move with the programme rather than being
     * frozen at whatever they were when the columns shipped.
     *
     * Everything that computes, displays, or explains the thresholds must go
     * through here, or two screens will disagree about who is due a notice.
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
                'feeding_absence_notice_days',
            ]
        )));

        if ($columns === []) {
            return $rule;
        }

        // One read for every setting: this runs on every page of the feeding
        // module, and they are all halves of one policy. The school's policy row
        // cannot change while one request is being served, so it is read once.
        $settings = RequestMemo::remember(
            'at-risk:settings:'.$institutionId.':'.implode(',', $columns),
            fn (): array => (array) Institution::query()->whereKey($institutionId)->first($columns)?->getAttributes(),
        );

        $threshold = $settings['feeding_at_risk_threshold'] ?? null;
        // 0 is a real answer here — "classify from the first confirmed session"
        // — so only NULL falls back to the app default.
        $minimum = $settings['feeding_min_observation_days'] ?? null;
        // Which rule this school runs at all. A school that has chosen nothing
        // keeps the platform default rather than being switched to somebody
        // else's policy, and an unrecognised value is treated as unset.
        $mode = (string) ($settings['feeding_at_risk_mode'] ?? '');
        $flagDays = $settings['feeding_absence_flag_days'] ?? null;
        $noticeDays = $settings['feeding_absence_notice_days'] ?? null;

        return new self(
            in_array($mode, self::MODES, true) ? $mode : $rule->mode,
            ($threshold === null || (float) $threshold <= 0) ? $rule->thresholdPercent : (float) $threshold,
            $rule->consecutiveAbsences,
            $minimum === null ? $rule->minimumObservationDays : max(0, (int) $minimum),
            ($flagDays === null || (int) $flagDays < 1) ? $rule->absenceFlagDays : (int) $flagDays,
            ($noticeDays === null || (int) $noticeDays < 1) ? $rule->absenceNoticeDays : (int) $noticeDays,
        );
    }

    /**
     * Whether the school's flag threshold has been reached on these marks.
     *
     * This is NOT a flag. Reaching the threshold makes a learner AWAITING
     * CONFIRMATION; only the Feeding Coordinator's confirmation that the record
     * is correct flags them (FeedingAttendanceFlag). Anything that counts,
     * lists or shows flagged learners must read FeedingAttendanceFlag, never
     * this method.
     *
     * Returns false while a rate is still inside its observation window: a
     * verdict on too little history is not a verdict.
     *
     * @param  list<bool|null>  $marks  session marks in date order; NULL = unconfirmed or excused
     */
    public function thresholdReached(array $marks): bool
    {
        if (! $this->hasEnoughObservation($marks)) {
            return false;
        }

        $confirmed = array_values(array_filter($marks, static fn ($m) => $m !== null));

        return match ($this->mode) {
            self::MODE_CONSECUTIVE_ABSENCES => $this->hasAbsenceRun($confirmed, $this->consecutiveAbsences),
            self::MODE_UNEXCUSED_ABSENCE_DAYS => $this->hasAbsenceRun($confirmed, $this->absenceFlagDays),
            default => $this->isBelowRate($confirmed),
        };
    }

    /**
     * Whether the learner's CURRENT run of unexcused absences has reached the
     * early notice — day 3 of the adviser's flow. A notice asks the coordinator
     * to verify the record; it flags nobody.
     *
     * @param  list<bool|null>  $marks
     */
    public function reachesNotice(array $marks): bool
    {
        return $this->currentAbsenceRun($marks) >= $this->absenceNoticeDays;
    }

    /**
     * The learner's current run of unexcused absences, counted back from the
     * most recent confirmed session.
     *
     * @param  list<bool|null>  $marks
     */
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
     * Whether this learner has enough confirmed history for the rule to say
     * anything about them.
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

    /** How many more confirmed sessions before the rule starts classifying. */
    public function sessionsUntilClassification(array $marks): int
    {
        return max(0, $this->observationDays() - $this->confirmedCount($marks));
    }

    /**
     * The window this mode actually needs, in confirmed sessions.
     *
     * For a rate it is the school's own setting: a percentage over four
     * sessions is a sample, not a finding. A run of absences IS the finding —
     * holding seven missed sessions back for another three would mean the
     * coordinator hearing about it a week late — so the run-based modes need
     * only one confirmed session.
     */
    private function observationDays(): int
    {
        return $this->mode === self::MODE_ATTENDANCE_RATE
            ? max(1, $this->minimumObservationDays)
            : 1;
    }

    /** Whether this mode waits on an observation window at all. */
    public function usesObservationWindow(): bool
    {
        return $this->mode === self::MODE_ATTENDANCE_RATE && $this->minimumObservationDays > 1;
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

    /** Whether the flag threshold is a percentage rather than a run of days. */
    public function isRateMode(): bool
    {
        return $this->mode === self::MODE_ATTENDANCE_RATE;
    }

    public function thresholdPercent(): float
    {
        return $this->thresholdPercent;
    }

    public function consecutiveAbsences(): int
    {
        return $this->consecutiveAbsences;
    }

    /** The confirmed feeding days a rate needs before it classifies. */
    public function minimumObservationDays(): int
    {
        return $this->minimumObservationDays;
    }

    /** Unexcused absences in a row that reach the flag threshold — day 7. */
    public function absenceFlagDays(): int
    {
        return $this->absenceFlagDays;
    }

    /** Unexcused absences in a row that raise the attendance notice — day 3. */
    public function absenceNoticeDays(): int
    {
        return $this->absenceNoticeDays;
    }

    /**
     * The flag threshold in the unit it is set in: "7 days", "3 days" or
     * "80%" — what a KPI card has room for.
     */
    public function thresholdLabel(): string
    {
        return match ($this->mode) {
            self::MODE_CONSECUTIVE_ABSENCES => self::days($this->consecutiveAbsences),
            self::MODE_UNEXCUSED_ABSENCE_DAYS => self::days($this->absenceFlagDays),
            default => self::percent($this->thresholdPercent).'%',
        };
    }

    /** The notice in the same unit: "3 days". */
    public function noticeLabel(): string
    {
        return self::days($this->absenceNoticeDays);
    }

    /** A short line the UI can show so staff know what the threshold currently means. */
    public function describe(): string
    {
        $test = $this->describeThreshold();

        // Only a rate waits on an observation window, and a window of 0 or 1 is
        // no window at all, so neither is worth a clause.
        return $this->usesObservationWindow()
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
            self::MODE_UNEXCUSED_ABSENCE_DAYS => self::days($this->absenceFlagDays).' of consecutive unexcused absence',
            default => 'attendance below '.self::percent($this->thresholdPercent).'%',
        };
    }

    /** The early notice as a sentence fragment. */
    public function describeNotice(): string
    {
        return self::days($this->absenceNoticeDays).' of consecutive unexcused absence';
    }

    /** The observation window on its own, for a screen that names it separately. */
    public function describeObservation(): string
    {
        return $this->minimumObservationDays > 1
            ? 'the first '.$this->minimumObservationDays.' recorded feeding days'
            : 'the first recorded feeding day';
    }

    private static function days(int $days): string
    {
        return $days.' '.($days === 1 ? 'day' : 'days');
    }

    private static function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }

    /** @param  list<bool>  $confirmed */
    private function isBelowRate(array $confirmed): bool
    {
        if ($confirmed === []) {
            return false;
        }

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
