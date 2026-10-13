<?php

namespace App\Support;

use App\Models\FeedingAttendanceConfirmation;
use App\Models\FeedingFollowUp;
use App\Models\StudentHealthRecord;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Where one feeding beneficiary stands under the attendance flow the capstone
 * adviser set — the one reading every screen counts, lists and badges from.
 *
 *   ON TRACK / EARLY MONITORING
 *       nothing to act on (Early Monitoring: a rate with too little history)
 *   ATTENDANCE NOTICE                       day 3 of unexcused absence in a row
 *       the coordinator is asked to verify the record. NOBODY IS FLAGGED.
 *   AWAITING CONFIRMATION                   day 7 (the school's flag threshold)
 *       the threshold is reached, but the coordinator has not yet confirmed the
 *       record is correct. STILL NOBODY IS FLAGGED.
 *   FLAGGED FOR FOLLOW-UP
 *       the coordinator confirmed the record. The flag stays — whether or not
 *       the learner comes back the next day — until the coordinator records a
 *       Resolved follow-up, because both putting a child on the follow-up list
 *       and taking them off it are human decisions.
 *
 * Four invariants:
 *
 * 1. **Confirmation comes first.** Reaching the threshold is never a flag; only
 *    a FeedingAttendanceConfirmation at the FLAG stage is. Everything that
 *    counts at-risk learners counts FLAGGED and nothing else.
 * 2. **Only the coordinator and the School Nurse see a flag** (`VISIBLE_TO`).
 *    The Clinic Teacher, Clinic Staff and the class adviser read the nurse's
 *    and the coordinator's screens elsewhere; on those screens a learner's flag
 *    is simply not rendered.
 * 3. **Enrolment is never touched.** A flag is a reason to follow a learner up,
 *    never to stop feeding them; nothing here writes `feeding_enrolled_at` or
 *    `feeding_removed_at`.
 * 4. **A closed flag's absences are not new evidence.** Once a flag is
 *    resolved, the marks it covered are behind a cutoff, so the same week of
 *    absence cannot flag the learner a second time. A fresh run after the
 *    cutoff starts the flow again from day 1.
 *
 * `evaluate()` is pure (marks and history in, a standing out) so every
 * consequence is testable without a database; `forRecords()` reads the marks
 * and the confirmations for a whole roll in a fixed number of queries.
 */
final class FeedingAttendanceFlag
{
    public const ON_TRACK = 'on_track';

    public const EARLY_MONITORING = 'early_monitoring';

    public const NOTICE = 'notice';

    public const AWAITING_CONFIRMATION = 'awaiting_confirmation';

    public const FLAGGED = 'flagged';

    /** The standings that need the coordinator to look at the learner. */
    public const ATTENTION = [self::FLAGGED, self::AWAITING_CONFIRMATION, self::NOTICE];

    /** The two roles that may see which learners are flagged (invariant 2). */
    public const VISIBLE_TO = ['feeding_coor', 'school_nurse'];

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_MEDIUM = 'medium';

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NONE = 'none';

    /** A trend needs at least this many confirmed marks to have a direction. */
    private const TREND_MIN_SESSIONS = 4;

    /** How many percentage points of movement read as a real change of direction. */
    private const TREND_DELTA_POINTS = 10.0;

    public static function visibleTo(?string $role): bool
    {
        return in_array(strtolower(trim((string) $role)), self::VISIBLE_TO, true);
    }

    /** Whether the signed-in role may see a learner's flag. */
    public static function visibleToSession(): bool
    {
        return self::visibleTo((string) session('active_role', ''));
    }

    /**
     * One learner's standing.
     *
     * @param  list<array{date: string, mark: bool|null}>  $datedMarks  in date order; NULL = unconfirmed or excused
     * @param  array{open?: array<string, mixed>|null, cutoff?: string|null, verified_runs?: list<string>, last?: array<string, mixed>|null}  $history
     * @return array{state: string, label: string, badge: string, flagged: bool, needs_action: bool, threshold_reached: bool, run: int, run_started_on: ?string, notice_verified: bool, evidence: list<string>, confirmation: ?array<string, mixed>, priority: string}
     */
    public static function evaluate(array $datedMarks, FeedingAtRiskRule $rule, array $history = []): array
    {
        $open = $history['open'] ?? null;
        $cutoff = $history['cutoff'] ?? null;
        $verifiedRuns = $history['verified_runs'] ?? [];

        // Marks a resolved flag has already dealt with are not new evidence
        // (invariant 4): only what was recorded after its cutoff can start the
        // flow again.
        $live = $cutoff === null
            ? array_values($datedMarks)
            : array_values(array_filter($datedMarks, static fn (array $m): bool => $m['date'] > $cutoff));
        $sequence = array_column($live, 'mark');

        $runDates = self::currentRunDates($live);
        $run = count($runDates);
        $runStart = $runDates[0] ?? null;
        $thresholdReached = $rule->thresholdReached($sequence);

        $state = match (true) {
            $open !== null => self::FLAGGED,
            $thresholdReached => self::AWAITING_CONFIRMATION,
            $run >= $rule->absenceNoticeDays() => self::NOTICE,
            ! $rule->hasEnoughObservation(array_column($datedMarks, 'mark')) => self::EARLY_MONITORING,
            default => self::ON_TRACK,
        };

        $noticeVerified = $state === self::NOTICE && $runStart !== null && in_array($runStart, $verifiedRuns, true);

        // The absences a confirmation would be about: the run that reached the
        // threshold, or for a rate every unexcused absence since the cutoff.
        $evidence = match ($state) {
            self::AWAITING_CONFIRMATION => $rule->isRateMode()
                ? self::absenceDates($live)
                : self::latestQualifyingRun($live, $rule->mode() === FeedingAtRiskRule::MODE_CONSECUTIVE_ABSENCES
                    ? $rule->consecutiveAbsences()
                    : $rule->absenceFlagDays()),
            self::NOTICE => $runDates,
            default => [],
        };

        return [
            'state' => $state,
            'label' => self::label($state),
            'badge' => self::badge($state),
            // The ONLY thing any at-risk figure counts (invariant 1).
            'flagged' => $state === self::FLAGGED,
            'needs_action' => $state === self::AWAITING_CONFIRMATION || ($state === self::NOTICE && ! $noticeVerified),
            'threshold_reached' => $thresholdReached,
            'run' => $run,
            'run_started_on' => $runStart,
            'notice_verified' => $noticeVerified,
            'evidence' => $evidence,
            // The confirmation that flagged them, while it is open; otherwise
            // the most recent one, so a verified notice can say who verified.
            'confirmation' => $open ?? ($noticeVerified ? ($history['last'] ?? null) : null),
            'priority' => self::priority($state, $noticeVerified),
        ];
    }

    /**
     * The standing of every learner on a roll, keyed by record id.
     *
     * Marks come from the same memoized read every feeding panel uses, and the
     * confirmations for the whole roll are one more query (two when any learner
     * has ever been flagged), so a page pays the same whatever the roll's size.
     *
     * @param  Collection<int, StudentHealthRecord>  $records
     * @return array<int, array<string, mixed>>
     */
    public static function forRecords(Collection $records, FeedingAtRiskRule $rule): array
    {
        if ($records->isEmpty()) {
            return [];
        }

        $dated = FeedingBeneficiarySummary::datedMarksByRecord($records);
        $histories = self::historiesFor($records->pluck('id'));
        $standings = [];

        foreach ($records as $record) {
            $standings[$record->id] = self::evaluate(
                $dated->get($record->id, []),
                $rule,
                $histories[$record->id] ?? []
            );
        }

        return $standings;
    }

    /** How many learners on a roll are flagged — the at-risk figure. */
    public static function flaggedCount(Collection $records, FeedingAtRiskRule $rule): int
    {
        return count(array_filter(self::forRecords($records, $rule), static fn (array $s): bool => $s['flagged']));
    }

    /**
     * What the coordinator has already decided about each learner.
     *
     * @param  Collection<int, int>|array<int, int>  $ids
     * @return array<int, array{open: ?array<string, mixed>, cutoff: ?string, verified_runs: list<string>, last: ?array<string, mixed>}>
     */
    public static function historiesFor(Collection|array $ids): array
    {
        $ids = collect($ids)->filter()->map(fn ($id): int => (int) $id)->unique()->sort()->values();

        if ($ids->isEmpty() || ! SchemaCache::hasTable('feeding_attendance_confirmations')) {
            return [];
        }

        return RequestMemo::remember(
            'feeding:flags:'.md5($ids->implode(',')),
            fn (): array => self::readHistories($ids->all()),
        );
    }

    /**
     * Forget the memoized histories. Called after a confirmation or a
     * follow-up is written, so the rest of the request reads the new flag.
     */
    public static function forget(): void
    {
        RequestMemo::forgetPrefix('feeding:flags:');
    }

    /**
     * Writes each learner's flag to the plain `is_at_risk` column, which the
     * clinic dashboards count in SQL rather than decrypting the whole school.
     *
     * Runs after every write that can move a flag — an attendance mark, a
     * confirmation, a follow-up — and stores FLAGGED only, so a learner awaiting
     * confirmation is never counted at risk on a screen that reads the column.
     */
    public static function refreshStoredFlags(?int $institutionId): void
    {
        FeedingBeneficiarySummary::forgetMarks();
        self::forget();

        if (! SchemaCache::hasTable('student_health_records')) {
            return;
        }

        $rule = FeedingAtRiskRule::forInstitution($institutionId);

        $records = StudentHealthRecord::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->forCurrentSchoolYear()
            ->get();

        $standings = self::forRecords($records, $rule);
        $dated = FeedingBeneficiarySummary::datedMarksByRecord($records);
        $changed = 0;

        foreach ($records as $record) {
            $flagged = (bool) ($standings[$record->id]['flagged'] ?? false);
            $present = $rule->presentCount(array_column($dated->get($record->id, []), 'mark'));

            if ((bool) $record->is_at_risk !== $flagged) {
                $changed++;
            }

            $record->update([
                'attendance_sessions_count' => $present,
                'is_at_risk' => $flagged,
            ]);
        }

        if ($changed > 0) {
            AuditTrail::record(
                'updated',
                'StudentHealthRecord',
                null,
                "Feeding follow-up flags recomputed: {$changed} learner flag(s) changed"
            );
        }
    }

    public static function label(string $state): string
    {
        return match ($state) {
            self::FLAGGED => 'Flagged for Follow-Up',
            self::AWAITING_CONFIRMATION => 'Awaiting Confirmation',
            self::NOTICE => 'Attendance Notice',
            self::EARLY_MONITORING => 'Early Monitoring',
            default => 'On Track',
        };
    }

    /** The status scale's badge, so a standing reads the same wherever it appears. */
    public static function badge(string $state): string
    {
        return match ($state) {
            self::FLAGGED => 'badge-risk',
            self::AWAITING_CONFIRMATION => 'badge-monitor',
            self::NOTICE => 'badge-info',
            self::EARLY_MONITORING => 'badge-neutral',
            default => 'badge-normal',
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

    /** Within a priority: the decision waiting on the coordinator first. */
    public static function stateRank(string $state): int
    {
        return match ($state) {
            self::AWAITING_CONFIRMATION => 0,
            self::FLAGGED => 1,
            self::NOTICE => 2,
            self::EARLY_MONITORING => 3,
            default => 4,
        };
    }

    /**
     * Whether attendance is improving, declining, or holding steady — the most
     * recent sessions against everything before them. Unconfirmed and excused
     * marks are excluded, exactly as the rule excludes them.
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

        $rateOf = static fn (array $slice): float => (count(array_filter($slice, static fn ($mark) => $mark === true)) / count($slice)) * 100;
        $delta = $rateOf($recent) - $rateOf($earlier);

        return match (true) {
            $delta >= self::TREND_DELTA_POINTS => 'improving',
            $delta <= -self::TREND_DELTA_POINTS => 'declining',
            default => 'steady',
        };
    }

    /** The last few confirmed marks, oldest first — the sequence a detail view prints. */
    public static function recentMarks(array $marks, int $limit = 5): array
    {
        $confirmed = array_values(array_filter($marks, static fn ($mark) => $mark !== null));

        return array_slice($confirmed, -$limit);
    }

    private static function priority(string $state, bool $noticeVerified): string
    {
        return match ($state) {
            self::AWAITING_CONFIRMATION, self::FLAGGED => self::PRIORITY_HIGH,
            self::NOTICE => $noticeVerified ? self::PRIORITY_LOW : self::PRIORITY_MEDIUM,
            default => self::PRIORITY_NONE,
        };
    }

    /**
     * The dates of the current run of unexcused absences, oldest first.
     *
     * @param  list<array{date: string, mark: bool|null}>  $marks
     * @return list<string>
     */
    private static function currentRunDates(array $marks): array
    {
        $dates = [];

        foreach (array_reverse($marks) as $mark) {
            if ($mark['mark'] === null) {
                // Excused or unread: neither extends the run nor ends it.
                continue;
            }
            if ($mark['mark'] === true) {
                break;
            }
            $dates[] = $mark['date'];
        }

        return array_reverse($dates);
    }

    /**
     * The most recent run that reached `$required` absences in a row, oldest
     * first — what a learner awaiting confirmation is awaiting it about, even
     * if they have come back since.
     *
     * @param  list<array{date: string, mark: bool|null}>  $marks
     * @return list<string>
     */
    private static function latestQualifyingRun(array $marks, int $required): array
    {
        $latest = [];
        $run = [];

        foreach ($marks as $mark) {
            if ($mark['mark'] === null) {
                continue;
            }
            if ($mark['mark'] === false) {
                $run[] = $mark['date'];

                continue;
            }
            if (count($run) >= $required) {
                $latest = $run;
            }
            $run = [];
        }

        return count($run) >= $required ? $run : $latest;
    }

    /**
     * @param  list<array{date: string, mark: bool|null}>  $marks
     * @return list<string>
     */
    private static function absenceDates(array $marks): array
    {
        return array_values(array_map(
            static fn (array $m): string => $m['date'],
            array_filter($marks, static fn (array $m): bool => $m['mark'] === false)
        ));
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{open: ?array<string, mixed>, cutoff: ?string, verified_runs: list<string>, last: ?array<string, mixed>}>
     */
    private static function readHistories(array $ids): array
    {
        $confirmations = FeedingAttendanceConfirmation::query()
            ->whereIn('student_health_record_id', $ids)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('student_health_record_id');

        if ($confirmations->isEmpty()) {
            return [];
        }

        // A Resolved follow-up is what closes a flag. Only learners who have
        // ever been flagged can have one that matters, so the follow-ups are
        // read for them alone — and not at all when nobody has been.
        $flaggedIds = $confirmations
            ->filter(fn (Collection $rows): bool => $rows->contains('stage', FeedingAttendanceConfirmation::STAGE_FLAG))
            ->keys()
            ->all();

        $resolutions = collect();
        if ($flaggedIds !== [] && SchemaCache::hasTable('feeding_follow_ups')) {
            $resolutions = FeedingFollowUp::query()
                ->whereIn('student_health_record_id', $flaggedIds)
                ->where('status', FeedingFollowUp::STATUS_RESOLVED)
                ->get(['student_health_record_id', 'followed_up_on', 'created_at'])
                ->groupBy('student_health_record_id');
        }

        $histories = [];

        foreach ($confirmations as $recordId => $rows) {
            $flag = $rows->where('stage', FeedingAttendanceConfirmation::STAGE_FLAG)->last();
            $open = null;
            $cutoff = null;

            if ($flag !== null) {
                // A resolution recorded at or after the confirmation closes it.
                $closing = $resolutions->get($recordId, collect())->filter(
                    fn (FeedingFollowUp $f): bool => $f->created_at !== null
                        && $flag->created_at !== null
                        && $f->created_at->greaterThanOrEqualTo($flag->created_at)
                );

                if ($closing->isEmpty()) {
                    $open = self::summarise($flag);
                } else {
                    $cutoff = collect([$flag->confirmed_through, ...$closing->pluck('followed_up_on')->all()])
                        ->filter()
                        ->map(fn (CarbonInterface $date): string => $date->toDateString())
                        ->max();
                }
            }

            $histories[(int) $recordId] = [
                'open' => $open,
                'cutoff' => $cutoff,
                'verified_runs' => $rows
                    ->pluck('run_started_on')
                    ->filter()
                    ->map(fn (CarbonInterface $date): string => $date->toDateString())
                    ->unique()
                    ->values()
                    ->all(),
                'last' => self::summarise($rows->last()),
            ];
        }

        return $histories;
    }

    /** @return array{stage: string, date_label: string, confirmed_on: ?string, by: string, absences: int, run_started_on: ?string, confirmed_through: ?string} */
    private static function summarise(FeedingAttendanceConfirmation $row): array
    {
        return [
            'stage' => (string) $row->stage,
            'confirmed_on' => $row->created_at?->toDateString(),
            'date_label' => $row->created_at?->format('M j, Y') ?? '—',
            'by' => trim((string) $row->confirmed_by_name),
            'absences' => (int) $row->absences,
            'run_started_on' => $row->run_started_on?->toDateString(),
            'confirmed_through' => $row->confirmed_through?->toDateString(),
        ];
    }
}
