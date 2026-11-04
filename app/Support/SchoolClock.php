<?php

namespace App\Support;

use Carbon\CarbonInterface;

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
}
