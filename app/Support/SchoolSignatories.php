<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Who signs a school's form.
 *
 * The DepEd sheets end in a "Prepared by / Attested by / Noted by" block, and
 * the names that belong there are people the app already knows: the school
 * nurse who prepares the nutritional assessment, and the school head who notes
 * it. They are read from `accounts` — the same lookup the SBFP Forms page uses
 * to pre-fill its Prepared-by line — so a printed form and an exported one are
 * signed by the same people rather than by two different guesses.
 *
 * A name that is not on file comes back empty and the form prints a blank line
 * to write on. That is the honest answer: inventing a signatory would put a
 * person's name on a document they never saw.
 */
final class SchoolSignatories
{
    /**
     * The school head's designation under "Noted by" on the nutritional
     * health status forms and lists.
     */
    public const HEAD_DESIGNATION = 'Principal II';

    /** The school nurse, who prepares the nutritional assessment. */
    public static function preparedBy(?int $institutionId, string $schoolName): string
    {
        return self::nameFor('school_nurse', $institutionId, $schoolName);
    }

    /** The school head, who notes it. */
    public static function notedBy(?int $institutionId, string $schoolName): string
    {
        return self::nameFor('school_head', $institutionId, $schoolName);
    }

    /** The feeding coordinator, who prepares the beneficiaries' assessment. */
    public static function feedingCoordinator(?int $institutionId, string $schoolName): string
    {
        return self::nameFor('feeding_coor', $institutionId, $schoolName);
    }

    /**
     * The class adviser of one section, who signs a form about a learner in
     * it — the incident report. Matched on the account's own assignment,
     * without regard to case or spacing, because that is how the adviser's
     * scope reads it; empty where nobody holds the class.
     */
    public static function classAdviser(?int $institutionId, string $grade, string $section): string
    {
        $norm = static fn (string $value): string => mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($value)));

        if (! $institutionId || $norm($grade) === '' || $norm($section) === '' || ! SchemaCache::hasTable('accounts')) {
            return '';
        }

        $adviser = DB::table('accounts')
            ->where('role', 'class_adviser')
            ->where('institution_id', $institutionId)
            ->orderBy('id')
            ->get(['name', 'assigned_grade_level', 'assigned_section'])
            ->first(fn (object $account): bool => $norm((string) $account->assigned_grade_level) === $norm($grade)
                && $norm((string) $account->assigned_section) === $norm($section));

        return (string) ($adviser->name ?? '');
    }

    /**
     * The first account holding a role at this school.
     *
     * Scoped by institution first, because that is the boundary every other
     * read of school-owned data uses; the school name is only a fallback for
     * rows that predate institution ids.
     */
    private static function nameFor(string $role, ?int $institutionId, string $schoolName): string
    {
        if (! SchemaCache::hasTable('accounts')) {
            return '';
        }

        if ($institutionId) {
            $name = (string) (DB::table('accounts')
                ->where('role', $role)
                ->where('institution_id', $institutionId)
                ->orderBy('id')
                ->value('name') ?? '');

            if ($name !== '') {
                return $name;
            }
        }

        if (trim($schoolName) !== '') {
            return (string) (DB::table('accounts')
                ->where('role', $role)
                ->where('school_name', $schoolName)
                ->orderBy('id')
                ->value('name') ?? '');
        }

        return '';
    }
}
