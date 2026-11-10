<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The time and date on the school's own clock.
 *
 * The server runs on UTC and the schools eight hours ahead of it, so from
 * midnight until 8 AM in Davao the server's "today" is still yesterday, and all
 * day its hour is wrong. A date or time somebody reads, types or is greeted by
 * comes from here. Stored timestamps stay in UTC (config/app.php): only what
 * "now" and "today" mean to a person changes.
 */
final class SchoolClock
{
    public static function now(): CarbonInterface
    {
        return now((string) config('app.school_timezone'));
    }

    /** Today's date, as Y-m-d. */
    public static function today(): string
    {
        return self::now()->toDateString();
    }

    /**
     * A stored timestamp as the school reads it. Stamps the application
     * writes (created_at, signed_at, dispensed_at…) are UTC, and printed as
     * they come they read eight hours early (testers' report #10: a visit at
     * 10:34 AM shown as 2:34 AM). Formatting this copy, never the stored one,
     * keeps the value written back unchanged.
     *
     * Not for a time a person typed (consulted_at, occurred_at), which is
     * already the school's wall clock.
     */
    public static function local(mixed $at): ?CarbonInterface
    {
        if ($at === null || $at === '') {
            return null;
        }

        $moment = $at instanceof CarbonInterface ? $at->copy() : Carbon::parse((string) $at, 'UTC');

        return $moment->setTimezone((string) config('app.school_timezone'));
    }
}
