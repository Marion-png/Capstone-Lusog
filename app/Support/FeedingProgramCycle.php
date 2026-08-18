<?php

namespace App\Support;

use App\Models\Consultation;
use App\Models\FeedingAttendance;
use App\Models\StudentHealthRecord;
use Carbon\Carbon;

/**
 * Where a school currently sits in the 120-day SBFP feeding cycle.
 *
 * Day 1 is the first recorded feeding session, not the day the coordinator
 * opened the page: the cycle is a fact about the school's attendance history,
 * so the dashboard header and the Feeding Program page must never disagree
 * about which day it is. Both read it from here.
 *
 * A school with no attendance yet has no cycle — day 0, hasStarted() false —
 * rather than a day 1 that nothing supports.
 */
class FeedingProgramCycle
{
    public const DURATION_DAYS = 120;

    private function __construct(private readonly ?Carbon $startDate) {}

    public static function forInstitution(?int $institutionId = null): self
    {
        return new self(self::resolveStartDate($institutionId));
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

        return min(self::DURATION_DAYS, self::countFeedingDays($this->startDate, now()));
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
        return max(0, self::DURATION_DAYS - $this->day());
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
        return $this->hasStarted() && $this->day() >= self::DURATION_DAYS;
    }

    /** Share of the cycle elapsed, 0-100, for the progress bar. */
    public function percent(): float
    {
        return round(($this->day() / self::DURATION_DAYS) * 100, 1);
    }

    /** ISO start date, so a long-lived page can advance the day itself. */
    public function startDateIso(): ?string
    {
        return $this->startDate?->toDateString();
    }

    /**
     * The calendar date of the cycle's last feeding day.
     *
     * Not `start + 119 days`: 120 school days span about 24 calendar weeks, so a
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

        while ($counted < self::DURATION_DAYS) {
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
