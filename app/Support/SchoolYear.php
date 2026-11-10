<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Which school year it is, and which years a page offers.
 *
 * One place for it, so every screen opens on the same year: the adviser's
 * Consent upload and Consent Forms page, the School Head's Consent Compliance
 * and Health Reports, the clinic's consent list and the feeding pages.
 * Testers found those screens on 2027-2028 and 2028-2029 in November 2026
 * (report #4), because a tab opened on the newest year anybody had a row in
 * and test rows had been filed under years that had not started.
 *
 * The year is worked out from today's date on the school's own clock (Asia/
 * Manila), never from the server's UTC clock and never from what is on
 * record: DepEd's school year starts in June, so June to December belong to
 * the year starting that calendar year and January to May to the one before.
 */
final class SchoolYear
{
    /** The month a DepEd school year starts. */
    public const START_MONTH = 6;

    /** "2026-2027": a year and the one after it. */
    public static function isValid(string $year): bool
    {
        return preg_match('/^(\d{4})-(\d{4})$/', $year, $m) === 1 && (int) $m[2] === (int) $m[1] + 1;
    }

    /** The school year today falls in, on the school's clock: "2026-2027". */
    public static function calendar(): string
    {
        return self::containing(SchoolClock::now());
    }

    /** The school year a date falls in: June to December its own year's, January to May the year before's. */
    public static function containing(CarbonInterface $date): string
    {
        $start = (int) $date->format('Y') - ((int) $date->format('n') >= self::START_MONTH ? 0 : 1);

        return $start.'-'.($start + 1);
    }

    /**
     * Where a school year starts and where the next one does, as
     * "Y-m-d H:i:s" on the school's clock: [from, before). For a column
     * holding the moment as the school read it — consulted_at is the date
     * and time the nurse typed in — so a range, never a date function, and
     * the same statement runs on Postgres and SQLite.
     *
     * @return array{0: string, 1: string}
     */
    public static function bounds(string $year): array
    {
        $start = self::startOf($year);
        $month = str_pad((string) self::START_MONTH, 2, '0', STR_PAD_LEFT);

        return ["{$start}-{$month}-01 00:00:00", ($start + 1)."-{$month}-01 00:00:00"];
    }

    /**
     * The school years from one date to another, newest first — the years a
     * table with a date but no school_year column has rows in, read off its
     * first and last date. A stray date years away is held to ten years
     * before the later one, so a typo cannot fill the dropdown.
     *
     * @return list<string>
     */
    public static function spanning(mixed $first, mixed $last): array
    {
        if ($first === null || $first === '' || $last === null || $last === '') {
            return [];
        }

        $from = self::startOf(self::containing(Carbon::parse((string) $first)));
        $to = self::startOf(self::containing(Carbon::parse((string) $last)));
        $from = max($from, $to - 10);

        $years = [];
        for ($start = $to; $start >= $from; $start--) {
            $years[] = $start.'-'.($start + 1);
        }

        return $years;
    }

    /**
     * The school year the application works in: the calendar's — unless the
     * signed-in teacher chose another for testing while that aid is switched
     * on (WorkingSchoolYear, never on for the deployed service).
     */
    public static function current(): string
    {
        return WorkingSchoolYear::chosen() ?? self::calendar();
    }

    /** Whether a year starts after the calendar's — a year that has not begun. */
    public static function isFuture(string $year): bool
    {
        return self::isValid($year) && self::startOf($year) > self::startOf(self::calendar());
    }

    /**
     * The years a page's school-year dropdown offers, newest first: the years
     * on record, and the current one even before anybody has a row in it. A
     * year with nothing on record is never offered otherwise, so a year that
     * has not begun is not a choice until something has been filed under it.
     *
     * @param  iterable<mixed>  $onRecord  the distinct school_year values of the page's rows
     * @return list<string>
     */
    public static function options(iterable $onRecord): array
    {
        $years = [self::current()];

        foreach ($onRecord as $year) {
            $year = trim((string) $year);

            if (self::isValid($year)) {
                $years[] = $year;
            }
        }

        $years = array_values(array_unique($years));
        rsort($years);

        return $years;
    }

    /**
     * The year a page opens on: the one asked for, when the dropdown offers
     * it, and the current year otherwise — never the newest year on record.
     *
     * @param  list<string>  $options  what options() returned
     */
    public static function pick(?string $requested, array $options): string
    {
        $requested = trim((string) $requested);

        return $requested !== '' && in_array($requested, $options, true) ? $requested : self::current();
    }

    /**
     * The year a clinic read asks for with `?school_year=`, or the current
     * one. Health Records opens an earlier year's roster, and the profile's
     * lookups (vital signs, consent, the adviser's MLHAT) follow it. A class
     * adviser always reads the current year: they see one class, this year.
     */
    public static function askedFor(Request $request): string
    {
        $year = trim((string) $request->query('school_year', ''));

        if ($year === '' || ! self::isValid($year) || (string) $request->session()->get('active_role', '') === 'class_adviser') {
            return self::current();
        }

        return $year;
    }

    private static function startOf(string $year): int
    {
        return (int) substr($year, 0, 4);
    }
}
