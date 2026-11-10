<?php

namespace App\Support;

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
        $today = SchoolClock::now();
        $start = (int) $today->format('Y') - ((int) $today->format('n') >= self::START_MONTH ? 0 : 1);

        return $start.'-'.($start + 1);
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

    private static function startOf(string $year): int
    {
        return (int) substr($year, 0, 4);
    }
}
