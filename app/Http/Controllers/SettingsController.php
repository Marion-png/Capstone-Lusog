<?php

namespace App\Http\Controllers;

use App\Models\InstitutionSection;
use App\Models\StudentHealthRecord;
use App\Support\AccountPassword;
use App\Support\AccountSettings;
use App\Support\AuditTrail;
use App\Support\RequestMemo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Own account settings: passwords for all stored accounts, and grade/section
 * reassignment for teachers when their new school-year assignment takes effect.
 * Roles, schools and other people's accounts are never editable here.
 *
 * The account is resolved from the session by `AccountSettings`, never from
 * the request, so there is no id to swap for somebody else's.
 */
class SettingsController extends Controller
{
    /**
     * The page, in whichever shell the signed-in role belongs to.
     *
     * The Nutrition Coordinator's pages are built on their own layout rather
     * than the shared LUSOG shell, so that role gets its own thin host around
     * the **same** body partial — one reading, two shells, exactly as the
     * Masterlist is rendered in the nurse's rail and the head's.
     */
    public function index(Request $request)
    {
        $profile = AccountSettings::profileFor($request);

        $view = $profile['role'] === 'nutricor' ? 'nutricor.settings' : 'dashboard.settings';

        return view($view, [
            'profile' => $profile,
            'passwordBlockedReason' => AccountSettings::passwordBlockedReason($profile),
            'assignmentSchoolYear' => StudentHealthRecord::currentSchoolYear(),
            'assignmentCatalog' => $profile['can_change_assignment']
                ? InstitutionSection::catalogFor((int) $request->session()->get('active_institution_id'))
                : [],
        ]);
    }

    /** Update the teacher's current assignment without moving student records. */
    public function updateAssignment(Request $request)
    {
        $profile = AccountSettings::profileFor($request);
        abort_unless($profile['can_change_assignment'], 403, 'Only teachers with a stored account can update their own assignment.');

        $schoolYear = StudentHealthRecord::currentSchoolYear();
        $validated = $request->validateWithBag('assignment', [
            'assigned_grade_level' => ['required', 'string', 'max:50'],
            'assigned_section' => ['required', 'string', 'max:100'],
            'school_year' => ['required', Rule::in([$schoolYear])],
            'confirm_assignment' => ['accepted'],
        ], [
            'school_year.in' => 'The school year has changed. Reload Settings before updating your assignment.',
            'confirm_assignment.accepted' => 'Confirm that this is your assigned class and the change should take effect now.',
        ]);

        $institutionId = (int) $request->session()->get('active_institution_id');
        $grade = trim($validated['assigned_grade_level']);
        $section = trim($validated['assigned_section']);

        if (InstitutionSection::hasCatalog($institutionId)) {
            $canonical = InstitutionSection::canonical($institutionId, $grade, $section);

            if ($canonical === null) {
                return redirect()->route('settings')->withInput($request->only([
                    'assigned_grade_level', 'assigned_section',
                ]))->withErrors([
                    'assigned_section' => 'Select a grade level and section offered by your school.',
                ], 'assignment');
            }

            [$grade, $section] = $canonical;
        }

        $account = DB::transaction(function () use ($profile, $institutionId, $grade, $section, $schoolYear) {
            // Lock the account so concurrent saves record the actual previous
            // assignment. The request supplies neither the account nor school.
            $row = DB::table('accounts')
                ->where('id', $profile['account_id'])
                ->where('institution_id', $institutionId)
                ->where('role', $profile['role'])
                ->lockForUpdate()->first();

            abort_unless($row, 403);
            $old = [
                'assigned_grade_level' => $row->assigned_grade_level,
                'assigned_section' => $row->assigned_section,
            ];
            $new = ['assigned_grade_level' => $grade, 'assigned_section' => $section];

            if ($old !== $new) {
                DB::table('accounts')->where('id', $row->id)->update($new + ['updated_at' => now()]);
                AuditTrail::record('updated', 'Account', (int) $row->id, 'Changed own teaching assignment', [
                    'school_year' => $schoolYear,
                    'old' => $old,
                    'new' => $new,
                ]);
            }

            return array_merge((array) $row, $new);
        });

        RequestMemo::forgetPrefix('account-settings:');
        AccountSettings::syncTeacherAssignment($request, $account);

        return redirect()->route('settings')->with('success',
            "Your assignment for SY {$schoolYear} is now {$grade} / {$section}. Student records have not been moved.");
    }

    /**
     * Change the signed-in person's own password.
     *
     * Four refusals, each for its own reason:
     *
     *  - a session with no account behind it (prototype, or the System Admin's
     *    environment credentials) has nothing to write to;
     *  - the current password must be given and must match, so a borrowed
     *    unattended browser cannot silently lock the owner out of their own
     *    account;
     *  - the new password must satisfy the same rules registration applies
     *    (`AccountPassword`), typed twice;
     *  - and it must not be the password already on file, or the form would
     *    report success for a change that changed nothing.
     */
    public function updatePassword(Request $request)
    {
        $profile = AccountSettings::profileFor($request);

        if (! $profile['can_change_password']) {
            return back()->with('error', AccountSettings::passwordBlockedReason($profile)
                ?: 'This account\'s password cannot be changed here.');
        }

        $account = AccountSettings::accountFor($request);

        if ($account === null) {
            return back()->with('error', 'This session is not linked to a stored account.');
        }

        $rules = ['password' => AccountPassword::rules()];

        // An account with no hash on file is matched by the login route against
        // *any* password, so asking for the current one would be asking for a
        // secret that does not exist. This is the one path that closes that
        // hole, so it must not be gated behind knowing a password that was
        // never set.
        if ($profile['has_password']) {
            $rules['current_password'] = ['required', 'string'];
        }

        $validated = $request->validate($rules, AccountPassword::messages() + [
            'current_password.required' => 'Enter your current password.',
            'password.confirmed' => 'The two new passwords do not match.',
        ]);

        $storedHash = (string) ($account['password_hash'] ?? '');
        $newPassword = (string) $validated['password'];

        if ($profile['has_password']) {
            if (! Hash::check((string) $validated['current_password'], $storedHash)) {
                AuditTrail::record(
                    'password_change_failed',
                    'Account',
                    (int) $account['id'],
                    'Own password change refused: the current password did not match'
                );

                return back()->withErrors(['current_password' => 'That is not your current password.']);
            }

            if (Hash::check($newPassword, $storedHash)) {
                return back()->withErrors(['password' => 'Choose a password you are not already using.']);
            }
        }

        DB::table('accounts')->where('id', $account['id'])->update([
            'password_hash' => Hash::make($newPassword),
            'updated_at' => now(),
        ]);
        RequestMemo::forgetPrefix('account-settings:');

        // The account row is written by `DB::table`, which bypasses Eloquent
        // and so bypasses the Auditable trait — this write has to record
        // itself. The password is never in the entry: an audit log is read by
        // people who are not its subject.
        AuditTrail::record(
            'updated',
            'Account',
            (int) $account['id'],
            $profile['has_password']
                ? 'Changed own account password'
                : 'Set a password on own account, which previously had none'
        );

        // A new session id after a credential change, so a session anybody
        // else is holding a reference to stops being this person's.
        $request->session()->regenerate();

        return back()->with('success', $profile['has_password']
            ? 'Your password has been changed.'
            : 'Your password has been set. Use it the next time you sign in.');
    }
}
