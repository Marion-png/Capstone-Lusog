<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How long an archived learner's record is kept — and nobody else's.
 *
 * The SIGLA School Year Transition spec (rule 5): a learner who leaves is
 * archived, kept five years in case they return (through ALS, say), then
 * permanently deleted with every uploaded file. The deletion date is set the
 * day the learner is archived — `delete_after` = that day + the retention
 * window (SchoolYearTransition) — and restoring the learner clears it.
 *
 * Who is due is therefore one plain question: **is the learner's latest row
 * archived, with a `delete_after` on or before today?** Nothing else is ever
 * a candidate:
 *
 *  - **A learner who is not archived is never counted** — active in a section
 *    or waiting for one, at any age of their earliest row. `standingFor()`
 *    returns `ENROLLED` and no deletion date at all, so nothing downstream can
 *    compute one for them.
 *  - **Restoring cancels the deletion by construction.** A restored learner
 *    has `delete_after` cleared and a new open placement, so their latest row
 *    is no longer archived.
 *  - **No date on file is not "expired".** An archived row with no
 *    `delete_after` cannot be judged, and the safe answer for something
 *    irreversible is to leave it alone.
 *
 * `archived_at` and `delete_after` are plain columns, so the question is asked
 * without decrypting anybody.
 */
final class StudentRetention
{
    /** Not archived: on a section's roster or waiting for one. Never a candidate for deletion. */
    public const ENROLLED = 'enrolled';

    /** Archived, still inside the retention window. */
    public const RETAINED = 'retained';

    /** Archived and past `delete_after`: deleted the next time the job runs. */
    public const DUE = 'due';

    /** How many years an archived record is kept. Configuration, never a constant here. */
    public static function years(): int
    {
        return max(0, (int) config('retention.years', 5));
    }

    /** How far ahead a record is flagged as nearing deletion. */
    public static function warnWithinDays(): int
    {
        return max(0, (int) config('retention.warn_within_days', 180));
    }

    /** The date a learner archived on this day is permanently deleted. */
    public static function deletionDateFrom(CarbonImmutable $archivedOn): CarbonImmutable
    {
        return $archivedOn->startOfDay()->addYears(self::years());
    }

    /**
     * One learner's standing, from every row they have at one school.
     *
     * @param  iterable<object>  $rows
     * @return array{standing: string, last_school_year: string, deletion_date: ?CarbonImmutable, days_remaining: ?int, is_due: bool, is_nearing: bool}
     */
    public static function standingFor(iterable $rows, ?CarbonImmutable $asOf = null): array
    {
        $asOf = $asOf ?? CarbonImmutable::now();
        $latest = LearnerPlacement::latest($rows);

        if ($latest === null) {
            return self::answer(self::RETAINED, '', null, $asOf);
        }

        $last = (string) LearnerPlacement::value($latest, 'school_year');

        if (! LearnerPlacement::isArchived($latest)) {
            return self::answer(self::ENROLLED, $last, null, $asOf);
        }

        return self::answer(self::RETAINED, $last, LearnerPlacement::dateOf($latest, 'delete_after'), $asOf);
    }

    /**
     * @return array{standing: string, last_school_year: string, deletion_date: ?CarbonImmutable, days_remaining: ?int, is_due: bool, is_nearing: bool}
     */
    private static function answer(string $standing, string $last, ?CarbonImmutable $deletionDate, CarbonImmutable $asOf): array
    {
        $deletionDate = $deletionDate?->startOfDay();
        $isDue = $deletionDate !== null && $standing !== self::ENROLLED && $asOf->startOfDay()->greaterThanOrEqualTo($deletionDate);
        $daysRemaining = $deletionDate === null ? null : $asOf->startOfDay()->diffInDays($deletionDate, false);

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
     * Every learner at one school whose record is past its deletion date, as
     * LRN => standing.
     *
     * Scoped to one institution and **required** to be: without an id it reads
     * nothing rather than falling through to every school's children. Read
     * fresh on every call — never from a memo — because the job asks again
     * after it has deleted.
     *
     * @return array<string, array{standing: string, last_school_year: string, deletion_date: ?CarbonImmutable, days_remaining: ?int, is_due: bool, is_nearing: bool}>
     */
    public static function dueAt(?int $institutionId, ?CarbonImmutable $asOf = null): array
    {
        $asOf = $asOf ?? CarbonImmutable::now();

        return self::standings($institutionId, $asOf)
            ->filter(fn (array $standing): bool => $standing['is_due'])
            ->all();
    }

    /**
     * Archived learners at one school whose deletion date is near or past,
     * as LRN => standing — what the student list warns about.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function nearingAt(?int $institutionId, ?CarbonImmutable $asOf = null): array
    {
        $asOf = $asOf ?? CarbonImmutable::now();

        return self::standings($institutionId, $asOf)
            ->filter(fn (array $standing): bool => $standing['is_nearing'] || $standing['is_due'])
            ->all();
    }

    /**
     * Whether one learner is due for deletion now — asked again by the job
     * immediately before it deletes, so a learner restored in between is
     * never deleted on an old answer.
     */
    public static function isDue(?int $institutionId, string $lrn, ?CarbonImmutable $asOf = null): bool
    {
        if (! $institutionId || trim($lrn) === '' || ! self::readable()) {
            return false;
        }

        return self::standingFor(LearnerPlacement::rowsForLearner($institutionId, $lrn), $asOf)['is_due'];
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    private static function standings(?int $institutionId, CarbonImmutable $asOf): Collection
    {
        if (! $institutionId || ! self::readable()) {
            return collect();
        }

        // Only the archived learners can be anything but ENROLLED, so only
        // their LRNs are read in full. Plain columns only: deciding who is due
        // never decrypts the school.
        $archived = DB::table('student_health_records')
            ->where('institution_id', $institutionId)
            ->whereNotNull('archived_at')
            ->distinct()
            ->pluck('student_id')
            ->map(fn ($lrn) => (string) $lrn)
            ->filter(fn (string $lrn) => $lrn !== '')
            ->values()
            ->all();

        $standings = [];

        foreach (LearnerPlacement::rowsFor($institutionId, $archived) as $lrn => $rows) {
            $standings[(string) $lrn] = self::standingFor($rows, $asOf);
        }

        return collect($standings);
    }

    /** The columns the rule reads exist. Until the migration runs, nobody is due. */
    private static function readable(): bool
    {
        return SchemaCache::hasTable('student_health_records')
            && SchemaCache::hasColumn('student_health_records', 'archived_at')
            && SchemaCache::hasColumn('student_health_records', 'delete_after');
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
            return 'This archived record is past its '.self::years().'-year retention period and will be deleted on the next scheduled run. Restore the learner to keep it.';
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
