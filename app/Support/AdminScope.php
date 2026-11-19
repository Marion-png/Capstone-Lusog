<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Who may use the admin Control Center, and over which schools.
 *
 * Two roles reach it:
 *
 *  - `system_admin` signs in with the SYSTEM_ADMIN_* environment credentials
 *    and administers every school. It is the only unscoped role.
 *  - `school_admin` is an `accounts` row stamped with one school. It sees and
 *    changes that school's accounts, account requests, feeding policy,
 *    calendar entries and audit trail, and nothing of any other school's.
 *
 * Every admin route asks this class rather than comparing the role itself, so
 * the two roles cannot drift apart on what "this admin covers this school"
 * means. A School Admin with no school covers nothing (InstitutionScope ejects
 * such a session anyway).
 */
class AdminScope
{
    public const SYSTEM_ADMIN = 'system_admin';

    public const SCHOOL_ADMIN = 'school_admin';

    public const ROLES = [self::SYSTEM_ADMIN, self::SCHOOL_ADMIN];

    public static function isAdmin(Request $request): bool
    {
        return in_array(self::role($request), self::ROLES, true);
    }

    public static function isSystemAdmin(Request $request): bool
    {
        return self::role($request) === self::SYSTEM_ADMIN;
    }

    public static function isSchoolAdmin(Request $request): bool
    {
        return self::role($request) === self::SCHOOL_ADMIN;
    }

    /**
     * The one school a School Admin administers, or null for the System Admin
     * (every school). Meaningful only after isAdmin().
     */
    public static function institutionId(Request $request): ?int
    {
        if (! self::isSchoolAdmin($request)) {
            return null;
        }

        $id = (int) $request->session()->get('active_institution_id', 0);

        // A School Admin without a school must read nothing, never everything:
        // 0 matches no row, where null would mean "unscoped".
        return $id > 0 ? $id : 0;
    }

    /** Whether this admin may act on a row belonging to the given school. */
    public static function covers(Request $request, mixed $institutionId): bool
    {
        if (self::isSystemAdmin($request)) {
            return true;
        }

        if (! self::isSchoolAdmin($request)) {
            return false;
        }

        $own = self::institutionId($request);

        return $own > 0 && (int) $institutionId === $own;
    }

    private static function role(Request $request): string
    {
        return (string) $request->session()->get('active_role', '');
    }
}
