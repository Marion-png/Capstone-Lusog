<?php

namespace App\Http\Controllers;

use App\Support\AccountPassword;
use App\Support\AccountSettings;
use App\Support\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Every role's own account settings — one page, one write.
 *
 * It answers two questions and nothing else: which account am I signed in as,
 * and how do I change its password. It is deliberately **not** a user
 * management screen: a person can read their own details and change their own
 * password, and nothing here edits a role, a school, a class assignment or
 * anybody else's account. Those are the System Admin's, on their own screen,
 * for the same reason a learner's measurement belongs to the class adviser —
 * a second place to change a role is a second place for two screens to
 * disagree about who somebody is.
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
        ]);
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
