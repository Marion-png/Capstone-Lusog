<?php

namespace App\Support;

use App\Models\StudentHealthRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * How long a learner's record is kept once they stop appearing.
 *
 * This settles `docs/open-decisions.md` entry 2. Until now nothing deleted a
 * `StudentHealthRecord` ever, so the behaviour was "retain forever" by
 * omission — which is the first thing a data-privacy reviewer asks about when
 * the data is children's health records.
 *
 * **The countdown is derived, never stored.** There is no `left_at` column, no
 * `is_inactive` flag and no archive state, deliberately: enrolment in this
 * application *is* a `student_health_records` row (one per learner per school
 * year, keyed `student_id` + `institution_id` + `school_year`), so the last
 * year a learner was enrolled is already recorded — it is the highest
 * `school_year` on their rows. A second flag saying the same thing is a second
 * thing to keep in step, and the one that drifts is the one that deletes a
 * child's health history early.
 *
 * `school_year` is plain (never encrypted), which is what makes that `MAX`
 * answerable in SQL at all.
 *
 * Three rules, and the first is the one that matters:
 *
 *  - **A learner enrolled in the current school year is never counted.** Not
 *    "usually not" — `standingFor()` returns `ENROLLED` and no deletion date
 *    at all, so nothing downstream can compute one for them.
 *  - **Re-enrolment clears the countdown by construction.** Enrolling a
 *    learner writes a row for the new school year, which raises the `MAX` and
 *    moves the deletion date forward; coming back inside the window needs no
 *    flag cleared and no job cancelled, because there was never any state to
 *    undo. Their Sheet 1/2, baseline, endline, consents and documents are
 *    untouched throughout — nothing here writes to them.
 *  - **The year ends when the school year ends.** The window runs from the end
 *    of the last enrolled year, read through
 *    `SchoolHeadHealthOverview::periodFor()` so "when does 2026-2027 finish"
 *    has one answer (31 May 2027) rather than one here and another there.
 */
final class StudentRetention
{
    /** A learner on this year's roll. Never a candidate for deletion. */
    public const ENROLLED = 'enrolled';

    /** Not on this year's roll, still inside the retention window. */
    public const RETAINED = 'retained';

    /** Past the window: due for deletion the next time the purge runs. */
    public const DUE = 'due';

    /** How many years a record is kept. Configuration, never a constant here. */
    public static function years(): int
    {
        return max(0, (int) config('retention.years', 5));
    }

    /** How far ahead a record is flagged as nearing deletion. */
    public static function warnWithinDays(): int
    {
        return max(0, (int) config('retention.warn_within_days', 180));
    }

    /**
     * The date a learner's record is deleted, given the last school year they
     * were enrolled in.
     *
     * End of that school year + the retention window. Reusing `periodFor()`
     * means a school year's closing date is defined once in the app.
     */
    public static function deletionDateFor(string $lastSchoolYear): CarbonImmutable
    {
        [, $closes] = SchoolHeadHealthOverview::periodFor($lastSchoolYear);

        return CarbonImmutable::parse($closes)->addYears(self::years());
    }

    /**
     * One learner's standing, from every row they have at one school.
     *
     * @param  Collection<int, StudentHealthRecord>|iterable<StudentHealthRecord>  $rows
     * @return array{standing: string, last_school_year: string, deletion_date: ?CarbonImmutable, days_remaining: ?int, is_due: bool, is_nearing: bool}
     */
    public static function standingFor(iterable $rows, ?CarbonImmutable $asOf = null, ?string $currentSchoolYear = null): array
    {
        $asOf = $asOf ?? CarbonImmutable::now();
        $currentSchoolYear = $currentSchoolYear ?? StudentHealthRecord::currentSchoolYear();

        $years = [];
        foreach ($rows as $row) {
            $year = trim((string) ($row->school_year ?? ''));
            if ($year !== '') {
                $years[] = $year;
            }
        }

        // No year on file is not "expired" — it is a record this rule cannot
        // judge, and the safe answer for something irreversible is to leave it
        // alone rather than to guess it is old.
        if ($years === []) {
            return self::answer(self::RETAINED, '', null, $asOf);
        }

        // String comparison is the right one here: "2026-2027" sorts after
        // "2025-2026" because the opening year leads and is zero-padded by
        // being four digits.
        rsort($years);
        $last = $years[0];

        if (in_array($currentSchoolYear, $years, true)) {
            return self::answer(self::ENROLLED, $last, null, $asOf);
        }

        return self::answer(self::RETAINED, $last, self::deletionDateFor($last), $asOf);
    }

    /**
     * @return array{standing: string, last_school_year: string, deletion_date: ?CarbonImmutable, days_remaining: ?int, is_due: bool, is_nearing: bool}
     */
    private static function answer(string $standing, string $last, ?CarbonImmutable $deletionDate, CarbonImmutable $asOf): array
    {
        $isDue = $deletionDate !== null && $standing !== self::ENROLLED && $asOf->greaterThanOrEqualTo($deletionDate);
        $daysRemaining = $deletionDate === null ? null : $asOf->startOfDay()->diffInDays($deletionDate->startOfDay(), false);

        return [
            'standing' => $isDue ? self::DUE : $standing,
            'last_school_year' => $last,
            'deletion_date' => $deletionDate,
            'days_remaining' => $daysRemaining === null ? null : (int) $daysRemaining,
            'is_due' => $isDue,
            'is_nearing' => $deletionDate !== null
                && ! $isDue
                && $standing !== self::ENROLLED
                && $daysRemaining !== null
                && $daysRemaining <= self::warnWithinDays(),
        ];
    }

    /**
     * Every learner at one school whose record is past its retention date,
     * as LRN => standing.
     *
     * Scoped to one institution and **required** to be: without an id it reads
     * nothing rather than falling through to every school's children, the same
     * lock `SchoolHeadOverview` and `FeedingEnrollmentController` keep. A purge
     * that fell through would delete another school's learners.
     *
     * The grouping is by LRN rather than by row, because a learner's years are
     * one history: a 2019 row does not expire while its owner is on this
     * year's roll.
     *
     * @return array<string, array{standing: string, last_school_year: string, deletion_date: ?CarbonImmutable, days_remaining: ?int, is_due: bool, is_nearing: bool}>
     */
    public static function dueAt(?int $institutionId, ?CarbonImmutable $asOf = null): array
    {
        if (! $institutionId) {
            return [];
        }

        if (! SchemaCache::hasTable('student_health_records')) {
            return [];
        }

        $asOf = $asOf ?? CarbonImmutable::now();
        $currentSchoolYear = StudentHealthRecord::currentSchoolYear();

        // Only the three plain columns this rule reads. A purge must not
        // decrypt the whole school to decide who is old.
        $rows = StudentHealthRecord::query()
            ->where('institution_id', $institutionId)
            ->get(['id', 'student_id', 'school_year', 'institution_id']);

        $due = [];

        foreach ($rows->groupBy(fn (StudentHealthRecord $row) => (string) $row->student_id) as $lrn => $learnerRows) {
            if ((string) $lrn === '') {
                continue;
            }

            $standing = self::standingFor($learnerRows, $asOf, $currentSchoolYear);

            if ($standing['is_due']) {
                $due[(string) $lrn] = $standing;
            }
        }

        return $due;
    }

    /**
     * The sentence shown on a record nearing deletion.
     *
     * Names the date and what prevents it, because a warning that says only
     * "expiring" tells the reader nothing they can act on.
     *
     * @param  array{standing: string, deletion_date: ?CarbonImmutable, ...}  $standing
     */
    public static function warningFor(array $standing): string
    {
        $date = $standing['deletion_date'] ?? null;

        if ($date === null || ($standing['standing'] ?? '') === self::ENROLLED) {
            return '';
        }

        if ($standing['is_due'] ?? false) {
            return 'This record is past its '.self::years().'-year retention period and will be deleted on the next scheduled purge. Re-enrol the learner to keep it.';
        }

        return 'Record will be deleted on '.$date->format('j F Y').' if the learner is not enrolled again.';
    }

    /** The roles that may be told a record is nearing deletion. */
    public const NOTICE_ROLES = [
        'school_nurse',
        'clinic_staff',
        'clinic_teacher',
        'class_adviser',
        'school_head',
        'system_admin',
    ];

    public static function maySeeNotice(?string $role): bool
    {
        return in_array((string) $role, self::NOTICE_ROLES, true);
    }
}
