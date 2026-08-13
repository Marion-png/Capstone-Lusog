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

    /** The current feeding day, 1-based and capped at the cycle length; 0 before the first session. */
    public function day(): int
    {
        if ($this->startDate === null) {
            return 0;
        }

        return min(
            self::DURATION_DAYS,
            $this->startDate->copy()->startOfDay()->diffInDays(now()->startOfDay()) + 1
        );
    }

    public function daysRemaining(): int
    {
        return max(0, self::DURATION_DAYS - $this->day());
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
                return Carbon::parse($firstAttendanceDate);
            }
        }

        if (SchemaCache::hasTable('consultations')) {
            $firstFeedingDate = Consultation::query()
                ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
                ->min('consulted_at');

            if ($firstFeedingDate) {
                return Carbon::parse($firstFeedingDate);
            }
        }

        return null;
    }
}
