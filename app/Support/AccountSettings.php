<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * One reading of "the account the signed-in person is using".
 *
 * The Settings page prints it and the password endpoint writes to it, and both
 * resolve it here from **the session alone**. That is the whole security model
 * of this page: an account id, a username or a school off the wire decides
 * nothing, so there is no request anybody can craft that changes somebody
 * else's password. Usernames are unique per school rather than globally (see
 * the Multi-School invariant), so the lookup is keyed on both.
 *
 * Three sessions reach this page without an `accounts` row behind them, and
 * each is reported as what it is rather than as a broken account:
 *
 *  - the **prototype session** `EnsureActiveSession` seeds on any protected
 *    URL, which belongs to no account at all;
 *  - the **System Admin**, whose credentials are `SYSTEM_ADMIN_USERNAME` /
 *    `SYSTEM_ADMIN_PASSWORD` environment variables and are not in the database
 *    to change;
 *  - an account whose `password_hash` is empty, which the login route treats
 *    as matching **any** password. That is a real state — an approved request
 *    can carry a null hash — so Settings offers to set one rather than asking
 *    for a current password that would mean nothing.
 */
class AccountSettings
{
    /**
     * The seven roles as a person reads them.
     *
     * Kept here rather than in a view because the Settings page, which is
     * rendered in six different shells, must name a role the same way in all
     * of them.
     */
    public const ROLE_LABELS = [
        'school_nurse' => 'School Nurse',
        'clinic_staff' => 'Clinic Staff',
        'clinic_teacher' => 'Clinic Teacher',
        'class_adviser' => 'Class Adviser',
        'school_head' => 'School Head',
        'feeding_coor' => 'Feeding Coordinator',
        'nutricor' => 'Nutrition Coordinator',
        'system_admin' => 'System Admin',
    ];

    /** The session username the prototype guard seeds; it owns no account row. */
    public const PROTOTYPE_USERNAME = 'prototype';

    /** Teachers who may maintain their own grade and section in Settings. */
    public const TEACHER_ROLES = ['class_adviser', 'clinic_teacher'];

    public static function roleLabel(?string $role): string
    {
        $role = (string) $role;

        return self::ROLE_LABELS[$role] ?? ($role !== '' ? ucwords(str_replace('_', ' ', $role)) : 'User');
    }

    /**
     * The signed-in person's own `accounts` row, or null if they have none.
     *
     * @return array<string, mixed>|null
     */
    public static function accountFor(Request $request): ?array
    {
        $session = $request->session();
        $username = strtolower(trim((string) $session->get('active_username', '')));

        if ($username === '' || $username === self::PROTOTYPE_USERNAME) {
            return null;
        }

        if (! SchemaCache::hasTable('accounts')) {
            return null;
        }

        $institutionId = $session->get('active_institution_id');

        return RequestMemo::remember('account-settings:'.json_encode([$username, $institutionId]), function () use ($username, $institutionId) {
            $row = DB::table('accounts')
                ->whereRaw('LOWER(TRIM(username)) = ?', [$username])
                // Usernames are unique per school, not globally.
                ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
                ->when(! $institutionId, fn ($q) => $q->whereNull('institution_id'))
                ->first();

            return $row ? (array) $row : null;
        });
    }

    /**
     * Keep every signed-in browser on the stored teacher assignment. Clinic
     * teachers retain school-wide access; only advisers use this as a scope.
     */
    public static function syncTeacherAssignment(Request $request, array $account): void
    {
        $session = $request->session();
        $grade = (string) ($account['assigned_grade_level'] ?? '');
        $section = (string) ($account['assigned_section'] ?? '');

        if ($grade !== (string) $session->get('assigned_grade_level', '')
            || $section !== (string) $session->get('assigned_section', '')) {
            // Normal roster reads rebuild this from current-year records.
            $session->forget('school_health_card_records');
        }

        $session->put([
            'assigned_grade_level' => $grade,
            'assigned_section' => $section,
            'assigned_school_name' => $account['school_name'] ?? $session->get('active_school_name'),
        ]);
    }

    /**
     * What the Settings page prints, and why the password panel is in the
     * state it is in.
     *
     * Every value comes from the account row where there is one and from the
     * session otherwise, so a prototype session still sees a filled-in page
     * rather than a row of dashes.
     *
     * @return array<string, mixed>
     */
    public static function profileFor(Request $request): array
    {
        $session = $request->session();
        $role = (string) $session->get('active_role', '');
        $account = self::accountFor($request);

        $hasAccount = $account !== null;
        $hasPassword = $hasAccount && trim((string) ($account['password_hash'] ?? '')) !== '';

        // Both teacher roles can record an assignment. Only the adviser uses
        // it to limit learner access; clinic permissions remain school-wide.
        $grade = (string) ($account['assigned_grade_level'] ?? $session->get('assigned_grade_level', ''));
        $section = (string) ($account['assigned_section'] ?? $session->get('assigned_section', ''));

        return [
            'name' => trim((string) ($account['name'] ?? $session->get('active_name', ''))) ?: 'User',
            'username' => (string) ($account['username'] ?? $session->get('active_username', '')),
            'role' => $role,
            'role_label' => self::roleLabel($role),
            'school_name' => trim((string) ($account['school_name'] ?? $session->get('active_school_name', ''))),
            'assigned_grade_level' => $grade,
            'assigned_section' => $section,
            'shows_assignment' => in_array($role, self::TEACHER_ROLES, true),
            'can_change_assignment' => $hasAccount
                && in_array($role, self::TEACHER_ROLES, true)
                && ($account['role'] ?? null) === $role
                && ! empty($account['institution_id']),
            'has_account' => $hasAccount,
            'has_password' => $hasPassword,
            // The System Admin's credentials are environment variables, so
            // there is nothing here to change; saying so is better than
            // offering a form that could not work.
            'is_env_credentialed' => $role === 'system_admin',
            'is_prototype' => strtolower(trim((string) $session->get('active_username', ''))) === self::PROTOTYPE_USERNAME,
            'can_change_password' => $hasAccount && $role !== 'system_admin',
            'account_id' => $hasAccount ? (int) ($account['id'] ?? 0) : null,
        ];
    }

    /**
     * Why the password panel cannot be used, as one sentence, or '' when it
     * can be.
     *
     * The page is reachable by every role and by a demo session, so the panel
     * has to explain itself rather than quietly disappearing — a control that
     * vanishes leaves somebody with nothing to press and no way to tell
     * "not for you" from "broken".
     *
     * @param  array<string, mixed>  $profile
     */
    public static function passwordBlockedReason(array $profile): string
    {
        if ($profile['is_env_credentialed'] ?? false) {
            return 'The System Admin signs in with credentials held in the server environment, '
                .'so there is no stored password to change here.';
        }

        if ($profile['is_prototype'] ?? false) {
            return 'This is a prototype session, which belongs to no account. '
                .'Sign in with a real account to change its password.';
        }

        if (! ($profile['has_account'] ?? false)) {
            return 'This session is not linked to a stored account, so there is no password to change.';
        }

        return '';
    }
}
