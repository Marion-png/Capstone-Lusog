<?php

namespace App\Support;

use App\Models\ParentalConsentForm;

/**
 * The school year the signed-in teacher works in.
 *
 * Every "current school year" in this application is
 * `ParentalConsentForm::currentSchoolYear()`, which follows the calendar (June
 * to May) — except for a teacher who has set their Teaching Assignment to
 * another year while `app.school_year_testing` is on. For that teacher's
 * session the whole application then reads and writes that year: their
 * roster, closing the year, carrying learners over, consent, assessments.
 * That is what lets a learner be followed into the next school year without
 * waiting for June.
 *
 * Only the teacher's own session moves; every other desk stays on the
 * calendar. With the setting off, a stored year is ignored and everybody is
 * on the calendar again.
 */
final class WorkingSchoolYear
{
    /** How many years either side of the calendar's a teacher may choose. */
    private const RANGE = 5;

    /** Whether a teacher may choose a year — the setting, and the column to keep it in. */
    public static function enabled(): bool
    {
        return (bool) config('app.school_year_testing')
            && SchemaCache::hasColumn('accounts', 'assigned_school_year');
    }

    /** The year this request's teacher chose, or null to follow the calendar. */
    public static function chosen(): ?string
    {
        if (! config('app.school_year_testing') || ! app()->bound('request') || ! request()->hasSession()) {
            return null;
        }

        $session = request()->session();

        if (! in_array((string) $session->get('active_role', ''), AccountSettings::TEACHER_ROLES, true)) {
            return null;
        }

        $year = (string) $session->get('assigned_school_year', '');

        return self::isSchoolYear($year) ? $year : null;
    }

    /** "2027-2028": a year and the one after it. */
    public static function isSchoolYear(string $year): bool
    {
        return preg_match('/^(\d{4})-(\d{4})$/', $year, $m) === 1 && (int) $m[2] === (int) $m[1] + 1;
    }

    /**
     * The years a teacher may choose, newest first: five either side of the
     * calendar's, and every year the school holds records for.
     *
     * @return list<string>
     */
    public static function options(?int $institutionId): array
    {
        $start = (int) explode('-', ParentalConsentForm::calendarSchoolYear())[0];
        $years = SchoolHeadOverview::schoolYears($institutionId);

        for ($year = $start - self::RANGE; $year <= $start + self::RANGE; $year++) {
            $years[] = $year.'-'.($year + 1);
        }

        $years = array_values(array_unique(array_filter(
            array_map('strval', $years),
            fn (string $year): bool => self::isSchoolYear($year),
        )));
        rsort($years);

        return $years;
    }
}
