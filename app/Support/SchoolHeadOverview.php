<?php

namespace App\Support;

use App\Models\FeedingAttendance;
use App\Models\StudentHealthRecord;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything the School Head reads, computed once from one reading of the
 * school's own records.
 *
 * The role writes nothing: it reviews, monitors and exports. So every figure on
 * its four tabs is derived here at read time, and all four tabs read this one
 * class — otherwise the Dashboard's "still undernourished" and the Reports
 * tab's "still undernourished" would be two counts of the same children that
 * could quietly disagree, which is the exact failure the feeding invariants are
 * written to prevent.
 *
 * It does not re-decide anything the programme already decides elsewhere:
 *
 * - who is a beneficiary  → FeedingBeneficiarySummary::isBeneficiary()
 * - which grades are covered → FeedingBeneficiarySummary::GRADE_LEVELS
 * - who is at risk        → FeedingAtRiskRule::forInstitution() (the school's
 *                           own threshold and observation window, never a
 *                           constant in this file)
 * - where the cycle stands → FeedingProgramCycle
 * - what counts as improvement → FeedingNutritionProgress
 *
 * Two rules this class adds, both of them about honesty of measurement:
 *
 * 1. **A learner with no measurement is "Not measured", never Normal.** The
 *    coordinator's FeedingBeneficiarySummary::statusOf() folds an unrecorded
 *    status into Normal on purpose, so its four cards always sum to the
 *    beneficiary total. That is wrong for a monitoring report: a school head
 *    reading "412 Normal" must not be reading 60 children nobody weighed. So
 *    phaseStatus() returns an empty string for an absent reading and every
 *    breakdown here carries a separate Not measured bucket.
 *
 * 2. **An unconfirmed mark votes neither way.** NULL/needs-review marks are
 *    excluded from both the numerator and the denominator of every rate, and a
 *    feeding day no sheet covered for a learner is "not marked", never an
 *    absence — the same rule FeedingAtRiskRule applies, applied to the same
 *    marks, so a rate printed here is the rate that decided the flag.
 */
final class SchoolHeadOverview
{
    /**
     * The DepEd BMI-for-age categories, worst first. Order is the ranking the
     * diverging chart draws, and the boundary the bars align on sits between
     * Wasted and Normal.
     *
     * The app's classifier also emits "Underweight", which the DepEd sheets
     * have no column for — it folds into Wasted here exactly as it does in the
     * BMI report and on the coordinator's cards.
     */
    public const NUTRITION_SCALE = ['Severely Wasted', 'Wasted', 'Normal', 'Overweight', 'Obese'];

    /** The two categories the feeding programme exists to treat. */
    public const UNDERNOURISHED = ['Severely Wasted', 'Wasted'];

    /** A reading nobody has taken. Never folded into Normal — see the class docblock. */
    public const NOT_MEASURED = 'Not measured';

    /**
     * Turnout at or above this share of the expected beneficiaries is a full
     * feeding day; below it the day is drawn as low turnout and named on the
     * head's action queue. It is a monitoring aid for the grid and the queue —
     * never a substitute for FeedingAtRiskRule, which alone decides whether a
     * *learner* is at risk.
     */
    public const FULL_TURNOUT_PERCENT = 85.0;

    /** @var array<int, list<array{date: string, status: string}>> */
    private array $marksByRecord;

    /** @var list<string> */
    private array $sessionDates;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $standings = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $sessions = null;

    /**
     * @param  Collection<int, StudentHealthRecord>  $records
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @param  array<int, list<array{date: string, status: string}>>  $marksByRecord
     * @param  list<string>  $sessionDates
     */
    private function __construct(
        public readonly ?int $institutionId,
        public readonly string $schoolYear,
        public readonly Collection $records,
        public readonly Collection $beneficiaries,
        public readonly FeedingAtRiskRule $rule,
        public readonly FeedingProgramCycle $cycle,
        array $marksByRecord,
        array $sessionDates,
    ) {
        $this->marksByRecord = $marksByRecord;
        $this->sessionDates = $sessionDates;
    }

    /**
     * The school's roster for one year, with its marks, its rule and its cycle.
     *
     * Scoped by institution like every other read of school-owned data. A
     * session with no institution (legacy rows) falls through unscoped exactly
     * as StudentHealthRecord::forActiveInstitution does.
     */
    public static function for(?int $institutionId, ?string $schoolYear = null): self
    {
        $schoolYear = trim((string) $schoolYear) !== ''
            ? trim((string) $schoolYear)
            : StudentHealthRecord::currentSchoolYear();

        $records = collect();

        if (SchemaCache::hasTable('student_health_records')) {
            $records = StudentHealthRecord::query()
                ->when($institutionId, fn ($query) => $query->where('institution_id', $institutionId))
                // school_year is plain precisely so it can be a WHERE; every
                // other narrowing below happens in PHP because the columns are
                // encrypted at rest.
                ->where('school_year', $schoolYear)
                ->get()
                ->sortBy(fn (StudentHealthRecord $record): string => strtolower((string) $record->student_name))
                ->values();
        }

        $beneficiaries = $records
            ->filter(fn (StudentHealthRecord $record): bool => FeedingBeneficiarySummary::isBeneficiary($record))
            ->values();

        [$marksByRecord, $sessionDates] = self::readMarks($records);

        return new self(
            $institutionId,
            $schoolYear,
            $records,
            $beneficiaries,
            FeedingAtRiskRule::forInstitution($institutionId),
            FeedingProgramCycle::forInstitution($institutionId),
            $marksByRecord,
            $sessionDates,
        );
    }

    /** The school years this school has records for, newest first. */
    public static function schoolYears(?int $institutionId): array
    {
        $current = StudentHealthRecord::currentSchoolYear();

        if (! SchemaCache::hasTable('student_health_records')) {
            return [$current];
        }

        $years = StudentHealthRecord::query()
            ->when($institutionId, fn ($query) => $query->where('institution_id', $institutionId))
            ->distinct()
            ->orderByDesc('school_year')
            ->pluck('school_year')
            ->filter()
            ->values()
            ->all();

        return in_array($current, $years, true) ? $years : array_merge([$current], $years);
    }

    /**
     * The school's feeding days, oldest first. Feeding day *n* is the nth
     * recorded session — never the nth calendar day, because a day nobody fed
     * on is not a feeding day.
     *
     * @return list<string>
     */
    public function sessionDates(): array
    {
        return $this->sessionDates;
    }

    /** Feeding days actually recorded, which is what "days fed" means. */
    public function daysCompleted(): int
    {
        return count($this->sessionDates);
    }

    public function daysRemaining(): int
    {
        return max(0, FeedingProgramCycle::DURATION_DAYS - $this->daysCompleted());
    }

    /**
     * Where each beneficiary stands with the school's attendance rule.
     *
     * One reading of one set of marks produces the rate, the flag and the
     * three-way status together, so a row can never print 92% beside an
     * at-risk badge.
     *
     * @return array<int, array{present: int, absent: int, confirmed: int, not_marked: int, rate: ?float, at_risk: bool, status: string, status_label: string}>
     */
    public function standings(): array
    {
        if ($this->standings !== null) {
            return $this->standings;
        }

        $totalSessions = $this->daysCompleted();
        $standings = [];

        foreach ($this->beneficiaries as $record) {
            $marks = $this->marksByRecord[$record->id] ?? [];
            $sequence = self::toSequence($marks);

            $confirmed = $this->rule->confirmedCount($sequence);
            $present = $this->rule->presentCount($sequence);
            $status = $this->rule->status($sequence);

            $standings[$record->id] = [
                'present' => $present,
                'absent' => $confirmed - $present,
                'confirmed' => $confirmed,
                // Feeding days no sheet covered this learner. Reported on its
                // own so "1 of 4" can be read for what it is — never counted as
                // an absence.
                'not_marked' => max(0, $totalSessions - count($marks)),
                'rate' => $this->rule->attendanceRate($sequence),
                'at_risk' => $this->rule->isAtRisk($sequence),
                'status' => $status,
                'status_label' => FeedingAtRiskRule::statusLabel($status),
            ];
        }

        return $this->standings = $standings;
    }

    /** How many beneficiaries the school's own rule has flagged. */
    public function atRiskCount(): int
    {
        return count(array_filter($this->standings(), static fn (array $s): bool => $s['at_risk']));
    }

    /** Beneficiaries the observation window has not started classifying yet. */
    public function observingCount(): int
    {
        return count(array_filter(
            $this->standings(),
            static fn (array $s): bool => $s['status'] === FeedingAtRiskRule::STATUS_EARLY_MONITORING
        ));
    }

    /**
     * One row per feeding day: how many were fed, how many were not, and how
     * many marks nobody has read yet.
     *
     * @return list<array{date: string, day: int, label: string, present: int, absent: int, unconfirmed: int, not_marked: int, expected: int, rate: ?float, state: string}>
     */
    public function sessions(): array
    {
        if ($this->sessions !== null) {
            return $this->sessions;
        }

        $expected = $this->beneficiaries->count();
        $enrolled = array_flip($this->beneficiaries->pluck('id')->all());

        $tally = [];
        foreach ($this->sessionDates as $date) {
            $tally[$date] = ['present' => 0, 'absent' => 0, 'unconfirmed' => 0];
        }

        foreach ($this->marksByRecord as $recordId => $marks) {
            // Turnout is a figure about the feeding line, so it is counted over
            // the enrolled roll. A mark on a learner nobody enrolled is kept in
            // the record (an imported sheet is evidence of who was fed) but it
            // is not part of "how many of our beneficiaries turned up".
            if (! isset($enrolled[$recordId])) {
                continue;
            }

            foreach ($marks as $mark) {
                if (isset($tally[$mark['date']])) {
                    $tally[$mark['date']][$mark['status']]++;
                }
            }
        }

        $sessions = [];
        $day = 0;

        foreach ($this->sessionDates as $date) {
            $day++;
            $counts = $tally[$date];
            $confirmed = $counts['present'] + $counts['absent'];

            $sessions[] = [
                'date' => $date,
                'day' => $day,
                'label' => Carbon::parse($date)->format('M j, Y'),
                'present' => $counts['present'],
                'absent' => $counts['absent'],
                'unconfirmed' => $counts['unconfirmed'],
                'not_marked' => max(0, $expected - $confirmed - $counts['unconfirmed']),
                'expected' => $expected,
                // Null, never 0%: a day whose every mark is still unread is not
                // a day nobody came to.
                'rate' => $confirmed > 0 ? round(($counts['present'] / $confirmed) * 100, 1) : null,
                'state' => match (true) {
                    $confirmed === 0 => 'review',
                    ($counts['present'] / $confirmed) * 100 >= self::FULL_TURNOUT_PERCENT => 'fed',
                    default => 'low',
                },
            ];
        }

        return $this->sessions = $sessions;
    }

    /**
     * Mean turnout across recorded feeding days, or null when none has a
     * confirmed mark. Division by zero is an em dash on screen, never 0%.
     */
    public function averageTurnout(): ?float
    {
        $rates = array_values(array_filter(
            array_column($this->sessions(), 'rate'),
            static fn (?float $rate): bool => $rate !== null
        ));

        return $rates === [] ? null : round(array_sum($rates) / count($rates), 1);
    }

    /** Meals actually served: present marks across every recorded feeding day. */
    public function mealsServed(): int
    {
        return (int) array_sum(array_column($this->sessions(), 'present'));
    }

    /** Meals the programme planned to serve up to today: beneficiaries × days fed. */
    public function mealsPlanned(): int
    {
        return $this->beneficiaries->count() * $this->daysCompleted();
    }

    /**
     * How many learners sit in each nutritional category at one phase.
     *
     * @param  'baseline'|'latest'|'endline'  $phase
     * @param  Collection<int, StudentHealthRecord>|null  $records  defaults to the whole roster
     * @return array<string, int> every scale label plus Not measured, always present
     */
    public function statusCounts(string $phase, ?Collection $records = null): array
    {
        $counts = array_fill_keys(array_merge(self::NUTRITION_SCALE, [self::NOT_MEASURED]), 0);

        foreach ($records ?? $this->records as $record) {
            $status = self::phaseStatus($record, $phase);
            $counts[$status === '' ? self::NOT_MEASURED : $status]++;
        }

        return $counts;
    }

    /**
     * The same breakdown, one row per grade level, for the diverging chart.
     *
     * Grades are read off the section string the adviser typed, so a roster
     * with no grade in it lands under "Unassigned" rather than vanishing.
     *
     * @param  'baseline'|'latest'|'endline'  $phase
     * @return list<array<string, mixed>>
     */
    public function gradeBreakdown(string $phase): array
    {
        $buckets = [];

        foreach ($this->records as $record) {
            $grade = FeedingBeneficiarySummary::gradeNumber((string) $record->section);
            $key = $grade ?? 99;

            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'grade' => $grade,
                    'label' => $grade !== null ? 'Grade '.$grade : 'Unassigned',
                    'counts' => array_fill_keys(array_merge(self::NUTRITION_SCALE, [self::NOT_MEASURED]), 0),
                ];
            }

            $status = self::phaseStatus($record, $phase);
            $buckets[$key]['counts'][$status === '' ? self::NOT_MEASURED : $status]++;
        }

        ksort($buckets);

        return array_values(array_map(static function (array $bucket): array {
            $counts = $bucket['counts'];
            $undernourished = $counts['Severely Wasted'] + $counts['Wasted'];
            $total = array_sum($counts);
            $measured = $total - $counts[self::NOT_MEASURED];

            return $bucket + [
                'undernourished' => $undernourished,
                'total' => $total,
                'measured' => $measured,
                // A share of the learners actually measured, and null when
                // none was. "0% undernourished" over a grade nobody weighed
                // reads as good news about children no one has looked at.
                'share' => $measured > 0 ? round(($undernourished / $measured) * 100, 1) : null,
            ];
        }, $buckets));
    }

    /**
     * What the programme achieved: how many beneficiaries left wasting.
     *
     * "Rehabilitated" means an endline reading of Normal — the DepEd outcome.
     * "Improved" is the wider figure FeedingNutritionProgress computes: a
     * learner who climbed the wasting scale (Severely Wasted → Wasted) has
     * improved but is *not* rehabilitated, and the two are counted separately
     * because conflating them overstates the programme.
     *
     * The denominator is every beneficiary, not only those measured, so the
     * headline cannot creep upward simply because few endline readings exist.
     *
     * But it stays NULL until at least one endline reading exists. "0%
     * rehabilitated" over a programme nobody has re-measured reads as "the
     * feeding achieved nothing", when the truth is that nobody has looked yet —
     * the same error as reporting an unweighed learner as Normal, and this
     * class refuses both. With no beneficiaries at all it is likewise
     * undefined, not 0%.
     *
     * @return array<string, mixed>
     */
    public function outcome(): array
    {
        $total = $this->beneficiaries->count();
        $rehabilitated = 0;
        $measured = 0;
        $improved = 0;
        $declined = 0;
        $unchanged = 0;

        foreach ($this->beneficiaries as $record) {
            $baseline = self::phaseStatus($record, 'baseline');
            $endline = self::phaseStatus($record, 'endline');

            if ($endline === '') {
                continue;
            }

            $measured++;

            if ($endline === 'Normal') {
                $rehabilitated++;
            }

            $from = FeedingNutritionProgress::rank($baseline);
            $to = FeedingNutritionProgress::rank($endline);

            if ($from === null || $to === null) {
                continue;
            }

            if ($to > $from) {
                $improved++;
            } elseif ($to < $from) {
                $declined++;
            } else {
                $unchanged++;
            }
        }

        return [
            'beneficiaries' => $total,
            'measured' => $measured,
            'not_measured' => $total - $measured,
            'rehabilitated' => $rehabilitated,
            'still_undernourished' => $total - $rehabilitated,
            'improved' => $improved,
            'unchanged' => $unchanged,
            'declined' => $declined,
            'rate' => ($total > 0 && $measured > 0) ? round(($rehabilitated / $total) * 100, 1) : null,
            'improved_rate' => ($total > 0 && $measured > 0) ? round(($improved / $total) * 100, 1) : null,
        ];
    }

    /**
     * How many learners are undernourished right now, and how that compares
     * with the baseline the programme started from.
     *
     * @return array{baseline: int, latest: int, change: int, measured: int}
     */
    public function undernourishedTrend(): array
    {
        $baseline = $this->statusCounts('baseline');
        $latest = $this->statusCounts('latest');

        $baselineTotal = $baseline['Severely Wasted'] + $baseline['Wasted'];
        $latestTotal = $latest['Severely Wasted'] + $latest['Wasted'];

        return [
            'baseline' => $baselineTotal,
            'latest' => $latestTotal,
            'change' => $latestTotal - $baselineTotal,
            'measured' => $this->records->count() - $latest[self::NOT_MEASURED],
        ];
    }

    /**
     * Learners the adviser's measurement qualifies for the programme but whom
     * the coordinator has not enrolled — the waiting list. Qualifying is not
     * enrolment, and only the coordinator enrols.
     *
     * @return Collection<int, StudentHealthRecord>
     */
    public function pendingEnrollment(): Collection
    {
        return $this->records
            ->filter(fn (StudentHealthRecord $record): bool => FeedingBeneficiarySummary::isEligible($record)
                && $record->feeding_enrolled_at === null)
            ->values();
    }

    /** Marks a human still has to read before they count either way. */
    public function unconfirmedMarkCount(): int
    {
        $count = 0;

        foreach ($this->marksByRecord as $marks) {
            foreach ($marks as $mark) {
                if ($mark['status'] === 'unconfirmed') {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * One learner's marks, oldest first, for the read-only measurement and
     * attendance history on the masterlist.
     *
     * @return list<array{date: string, status: string}>
     */
    public function marksFor(int $recordId): array
    {
        return $this->marksByRecord[$recordId] ?? [];
    }

    /**
     * One learner's status at one phase, or '' when nobody measured it.
     *
     * 'latest' is the closing reading if there is one, otherwise the reading
     * the record currently carries — which is what "latest" means to a head
     * reading a roster mid-cycle.
     *
     * @param  'baseline'|'latest'|'endline'  $phase
     */
    public static function phaseStatus(StudentHealthRecord $record, string $phase): string
    {
        $raw = match ($phase) {
            'baseline' => (string) $record->baseline_nutritional_status,
            'endline' => (string) $record->endline_nutritional_status,
            default => (string) ($record->endline_nutritional_status ?: $record->nutritional_status),
        };

        return self::toScale($raw);
    }

    /**
     * The many spellings an adviser may have typed, reduced to one DepEd
     * category — or '' when there is nothing to reduce.
     *
     * Underweight folds into Wasted (the DepEd sheet has no Underweight
     * column). Anything the classifier does not emit returns '' rather than
     * being filed under Normal, because an unrecognised reading is not a
     * healthy one.
     */
    public static function toScale(string $status): string
    {
        $normalized = FeedingBeneficiarySummary::normalize($status);

        return match ($normalized) {
            'Underweight' => 'Wasted',
            default => in_array($normalized, self::NUTRITION_SCALE, true) ? $normalized : '',
        };
    }

    /** Whether a status sits in the range the feeding programme treats. */
    public static function isUndernourished(string $scaleStatus): bool
    {
        return in_array($scaleStatus, self::UNDERNOURISHED, true);
    }

    /**
     * Which way a learner moved between two readings, as the masterlist's
     * Change column reports it.
     *
     * Off-scale readings (Overweight / Obese) have no rank, so they are
     * neither improvement nor decline — the column says so rather than
     * guessing.
     */
    public static function movement(string $baseline, string $latest): string
    {
        if ($baseline === '' || $latest === '') {
            return 'unknown';
        }

        $from = FeedingNutritionProgress::rank($baseline);
        $to = FeedingNutritionProgress::rank($latest);

        if ($from === null || $to === null) {
            return $baseline === $latest ? 'same' : 'off_scale';
        }

        return match (true) {
            $to > $from => 'improved',
            $to < $from => 'declined',
            default => 'same',
        };
    }

    public static function movementLabel(string $movement): string
    {
        return match ($movement) {
            'improved' => 'Improved',
            'declined' => 'Declined',
            'same' => 'No change',
            'off_scale' => 'Off scale',
            default => 'Not measured',
        };
    }

    /**
     * The rule's marks as the pure sequence FeedingAtRiskRule judges: true,
     * false, or NULL for a mark no human has confirmed.
     *
     * @param  list<array{date: string, status: string}>  $marks
     * @return list<bool|null>
     */
    private static function toSequence(array $marks): array
    {
        return array_map(static fn (array $mark): ?bool => match ($mark['status']) {
            'present' => true,
            'absent' => false,
            default => null,
        }, $marks);
    }

    /**
     * Every mark on this roster, fetched rather than aggregated.
     *
     * A SUM(CASE WHEN is_present …) folds an unconfirmed NULL into "absent",
     * which is the one thing attendance must never do — so the rows come back
     * whole and the counting happens in PHP.
     *
     * @param  Collection<int, StudentHealthRecord>  $records
     * @return array{0: array<int, list<array{date: string, status: string}>>, 1: list<string>}
     */
    private static function readMarks(Collection $records): array
    {
        if ($records->isEmpty() || ! SchemaCache::hasTable('feeding_attendances')) {
            return [[], []];
        }

        $columns = ['student_health_record_id', 'session_date', 'is_present'];
        if (SchemaCache::hasColumn('feeding_attendances', 'needs_review')) {
            $columns[] = 'needs_review';
        }

        $rows = FeedingAttendance::query()
            ->whereIn('student_health_record_id', $records->pluck('id'))
            ->whereDate('session_date', '<=', now()->toDateString())
            ->orderBy('session_date')
            ->get($columns);

        $byRecord = [];
        $dates = [];

        foreach ($rows as $row) {
            $date = optional($row->session_date)->toDateString();

            if ($date === null) {
                continue;
            }

            $byRecord[(int) $row->student_health_record_id][] = [
                'date' => $date,
                'status' => match (true) {
                    (bool) ($row->needs_review ?? false), $row->is_present === null => 'unconfirmed',
                    (bool) $row->is_present => 'present',
                    default => 'absent',
                },
            ];

            $dates[$date] = true;
        }

        $dates = array_keys($dates);
        sort($dates);

        return [$byRecord, $dates];
    }
}
