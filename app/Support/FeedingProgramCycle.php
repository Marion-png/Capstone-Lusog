<?php

namespace App\Support;

use App\Models\Consultation;
use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Carbon\Carbon;

/**
 * Where a school currently sits in its SBFP feeding cycle.
 *
 * Day 1 is the first recorded feeding session, not the day the coordinator
 * opened the page: the cycle is a fact about the school's attendance history,
 * so the dashboard header and the Feeding Program page must never disagree
 * about which day it is. Both read it from here.
 *
 * A school with no attendance yet has no cycle — day 0, hasStarted() false —
 * rather than a day 1 that nothing supports.
 *
 * The LENGTH of the cycle is the school's own. Division policy says 120 feeding
 * days and 90 has been under discussion, so a figure compiled into the
 * application is a figure a school cannot correct when its Division settles the
 * question. `institutions.feeding_cycle_days` overrides the app default
 * (config/feeding.php); read it through `durationDays()` on the instance —
 * never through the constant, which is only the fallback.
 */
class FeedingProgramCycle
{
    /** The programme default, for a school that has set no length of its own. */
    public const DURATION_DAYS = 120;

    private function __construct(
        private readonly ?Carbon $startDate,
        private readonly int $durationDays = self::DURATION_DAYS,
    ) {}

    /**
     * Forget the memoized per-school cycle settings.
     *
     * The duration and the start date are read once per request, which is only
     * safe while they have not moved. A request that changes a school's
     * configured cycle length (the System Admin's form) has moved one of them.
     */
    public static function forgetInstitutionSettings(): void
    {
        RequestMemo::forgetPrefix('cycle:days:');
        RequestMemo::forgetPrefix('cycle:start:');
    }

    public static function forInstitution(?int $institutionId = null): self
    {
        return new self(self::resolveStartDate($institutionId), self::durationForInstitution($institutionId));
    }

    /**
     * The cycle length one school runs, in feeding days.
     *
     * NULL in the column means "use the app default", so a school that has set
     * nothing moves with the programme rather than being pinned to whatever the
     * figure was the day the column shipped.
     */
    public static function durationForInstitution(?int $institutionId = null): int
    {
        $default = max(1, (int) config('feeding.cycle_days', self::DURATION_DAYS));

        if (! $institutionId || ! SchemaCache::hasColumn('institutions', 'feeding_cycle_days')) {
            return $default;
        }

        // Read once per request: this is asked by the header, the cycle bar,
        // the adviser's endline gate and every screen that says "day N of M",
        // and a school's configured length cannot change mid-request.
        $configured = RequestMemo::remember(
            'cycle:days:'.$institutionId,
            fn () => Institution::query()->whereKey($institutionId)->value('feeding_cycle_days'),
        );

        return ($configured === null || (int) $configured < 1) ? $default : (int) $configured;
    }

    /** How many feeding days this cycle runs for. */
    public function durationDays(): int
    {
        return $this->durationDays;
    }

    public function hasStarted(): bool
    {
        return $this->startDate !== null;
    }

    /**
     * Whether a school could have fed anyone on this date.
     *
     * Nobody is fed on a Saturday or a Sunday — there is no class to feed — so a
     * weekend is not a feeding day the programme failed to hold, it is not a
     * feeding day at all. Counting it as one stretched a 120-day cycle across
     * ~24 calendar weeks of *elapsed* days while only ~120 school days existed
     * inside it, so the header ran ahead of the programme and every "day N of
     * 120" was too high. It is asked here so the cycle, the write guard and the
     * calendar all agree on which dates exist.
     */
    public static function isFeedingDay(Carbon|string $date): bool
    {
        return ! (is_string($date) ? Carbon::parse($date) : $date)->isWeekend();
    }

    /**
     * The current feeding day, 1-based and capped at the cycle length; 0 before
     * the first session.
     *
     * Counted in school days (Mon-Fri), never in elapsed calendar days.
     */
    public function day(): int
    {
        if ($this->startDate === null) {
            return 0;
        }

        return min($this->durationDays, self::countFeedingDays($this->startDate, now()));
    }

    /**
     * School days from $from to $to inclusive. Whole weeks are counted
     * arithmetically so a long cycle costs no loop over ~170 dates.
     */
    public static function countFeedingDays(Carbon $from, Carbon $to): int
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        if ($end->lt($start)) {
            return 0;
        }

        $totalDays = (int) $start->diffInDays($end) + 1;
        $wholeWeeks = intdiv($totalDays, 7);
        $days = $wholeWeeks * 5;

        // The remainder, walked one day at a time — at most six of them.
        $cursor = $start->copy()->addDays($wholeWeeks * 7);
        while ($cursor->lte($end)) {
            if (self::isFeedingDay($cursor)) {
                $days++;
            }
            $cursor->addDay();
        }

        return $days;
    }

    public function daysRemaining(): int
    {
        return max(0, $this->durationDays - $this->day());
    }

    /**
     * Whether the cycle has run its full length.
     *
     * An endline measurement is the closing half of a baseline-to-endline
     * comparison, so it is only meaningful once the programme has actually
     * finished. A cycle nobody has started has not finished either — day()
     * returns 0 there, so this stays false.
     */
    public function isComplete(): bool
    {
        return $this->hasStarted() && $this->day() >= $this->durationDays;
    }

    /** Share of the cycle elapsed, 0-100, for the progress bar. */
    public function percent(): float
    {
        return round(($this->day() / $this->durationDays) * 100, 1);
    }

    /** ISO start date, so a long-lived page can advance the day itself. */
    public function startDateIso(): ?string
    {
        return $this->startDate?->toDateString();
    }

    /**
     * The calendar date of the cycle's last feeding day.
     *
     * Not `start + N-1 days`: 120 school days span about 24 calendar weeks, so a
     * calendar-day window closed the programme roughly seven weeks early and
     * refused genuine sessions near the end of the cycle as "outside" it.
     */
    public function endDateIso(): ?string
    {
        if ($this->startDate === null) {
            return null;
        }

        $cursor = $this->startDate->copy();
        $counted = 1; // The start date is itself a feeding day.

        while ($counted < $this->durationDays) {
            $cursor->addDay();
            if (self::isFeedingDay($cursor)) {
                $counted++;
            }
        }

        return $cursor->toDateString();
    }

    /**
     * The first feeding session on record. Consultations are the fallback for
     * schools whose attendance predates the feeding_attendances table.
     */
    private static function resolveStartDate(?int $institutionId): ?Carbon
    {
        // The first recorded session is day 1, and several panels build a cycle
        // each. Two aggregate queries per panel over a hosted database is worth
        // memoizing; the answer cannot change while one request is served.
        return RequestMemo::remember(
            'cycle:start:'.($institutionId ?? '-'),
            fn (): ?Carbon => self::readStartDate($institutionId),
        );
    }

    private static function readStartDate(?int $institutionId): ?Carbon
    {
        $todayDate = now()->toDateString();

        if (SchemaCache::hasTable('feeding_attendances')) {
            $firstAttendanceDate = FeedingAttendance::query()
                ->when($institutionId, fn ($q) => $q->whereIn(
                    'student_health_record_id',
                    StudentHealthRecord::query()->where('institution_id', $institutionId)->forCurrentSchoolYear()->select('id')
                ))
                ->whereDate('session_date', '<=', $todayDate)
                ->min('session_date');

            if ($firstAttendanceDate) {
                return self::toFeedingDay(Carbon::parse($firstAttendanceDate));
            }
        }

        if (SchemaCache::hasTable('consultations')) {
            $firstFeedingDate = Consultation::query()
                ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
                ->min('consulted_at');

            if ($firstFeedingDate) {
                return self::toFeedingDay(Carbon::parse($firstFeedingDate));
            }
        }

        return null;
    }

    /**
     * Day 1 has to be a day the school could have fed on.
     *
     * New marks can no longer land on a weekend, but rows written before that
     * guard existed still can. A cycle starting on a Saturday would count zero
     * school days up to that Saturday, reporting day 0 for a programme that has
     * demonstrably started, so a legacy weekend start is read as the Monday
     * that follows it.
     */
    private static function toFeedingDay(Carbon $date): Carbon
    {
        while (! self::isFeedingDay($date)) {
            $date->addDay();
        }

        return $date;
    }
}
