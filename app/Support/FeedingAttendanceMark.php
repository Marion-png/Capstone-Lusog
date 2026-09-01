<?php

namespace App\Support;

/**
 * What one attendance row actually says — the single reading of a mark.
 *
 * A feeding mark now has four possible readings, and the difference between
 * them is the difference between a child being followed up and being left
 * alone:
 *
 *   present      they came
 *   absent       they did not come, and nobody excused it
 *   excused      they did not come, and there was a reason for it — the
 *                coordinator's "buffer": fasting during Ramadan, illness, a
 *                family emergency. Still an absence on the sheet, and never
 *                counted against the learner
 *   unconfirmed  a scanned mark no human has read yet — evidence of nothing
 *
 * `forRule()` is the projection every attendance calculation must go through.
 * It collapses the two readings that carry no verdict — excused and
 * unconfirmed — to NULL, which FeedingAtRiskRule already excludes from both the
 * numerator and the denominator. That is what makes the buffer work: a fasting
 * learner's rate is taken over the sessions they were actually expected at, so
 * excusing an absence can never push them toward a flag, and can never
 * artificially lift them above one either.
 *
 * It exists as one class because the `needs_review ? null : is_present` line
 * was written out in fourteen places. A third state added to fourteen copies
 * would have been a third state that disagreed with itself.
 */
final class FeedingAttendanceMark
{
    public const PRESENT = 'present';

    public const ABSENT = 'absent';

    /** An absence with a reason: logged, shown, and never held against anyone. */
    public const EXCUSED = 'excused';

    /** A scanned mark nobody has confirmed. */
    public const UNCONFIRMED = 'unconfirmed';

    /** No row at all — a session no sheet covered this learner on. */
    public const NOT_MARKED = 'not_marked';

    /** The three answers a coordinator may record for a learner on a session. */
    public const RECORDABLE = [self::PRESENT, self::ABSENT, self::EXCUSED];

    /**
     * How one row reads. Accepts anything carrying the three columns — an
     * Eloquent model or the stdClass a lean `get([...])` returns — and NULL for
     * a session with no row.
     */
    public static function state(?object $row): string
    {
        if ($row === null) {
            return self::NOT_MARKED;
        }

        if (($row->needs_review ?? false) || ($row->is_present ?? null) === null) {
            return self::UNCONFIRMED;
        }

        if ((bool) $row->is_present) {
            return self::PRESENT;
        }

        return ($row->is_excused ?? false) ? self::EXCUSED : self::ABSENT;
    }

    /**
     * The mark as the at-risk rule must see it: TRUE attended, FALSE missed
     * without excuse, NULL no evidence either way.
     */
    public static function forRule(?object $row): ?bool
    {
        return match (self::state($row)) {
            self::PRESENT => true,
            self::ABSENT => false,
            default => null,
        };
    }

    /** Whether a state is an absence of any kind — what a sheet's tally counts. */
    public static function isAbsence(string $state): bool
    {
        return $state === self::ABSENT || $state === self::EXCUSED;
    }

    public static function label(string $state): string
    {
        return match ($state) {
            self::PRESENT => 'Present',
            self::ABSENT => 'Absent',
            self::EXCUSED => 'Excused',
            self::UNCONFIRMED => 'Unconfirmed',
            default => 'Not marked',
        };
    }

    /**
     * The recordable answers as a select offers them.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::PRESENT => 'Present',
            self::ABSENT => 'Absent',
            self::EXCUSED => 'Excused',
        ];
    }

    /** Whether a posted value is one a coordinator may actually record. */
    public static function isRecordable(?string $mark): bool
    {
        return in_array((string) $mark, self::RECORDABLE, true);
    }

    /**
     * The two columns a recordable answer writes.
     *
     * @return array{is_present: bool, is_excused: bool}
     */
    public static function columns(string $mark): array
    {
        return [
            'is_present' => $mark === self::PRESENT,
            'is_excused' => $mark === self::EXCUSED,
        ];
    }
}
