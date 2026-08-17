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
