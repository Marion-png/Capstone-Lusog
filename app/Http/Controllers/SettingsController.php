<?php

namespace App\Http\Controllers;

use App\Models\HealthConsentForm;
use App\Models\HealthServiceSetting;
use App\Models\Institution;
use App\Models\InstitutionSection;
use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;
use App\Support\AccountPassword;
use App\Support\AccountSettings;
use App\Support\AuditTrail;
use App\Support\FeedingWeighIns;
use App\Support\HealthServicesOffered;
use App\Support\RequestMemo;
use App\Support\WorkingSchoolYear;
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
        $institutionId = (int) $request->session()->get('active_institution_id');

        $view = $profile['role'] === 'nutricor' ? 'nutricor.settings' : 'dashboard.settings';

        return view($view, [
            'profile' => $profile,
            'passwordBlockedReason' => AccountSettings::passwordBlockedReason($profile),
            'assignmentSchoolYear' => StudentHealthRecord::currentSchoolYear(),
            'calendarSchoolYear' => ParentalConsentForm::calendarSchoolYear(),
            // Any school year may be chosen only while testing; otherwise the
            // assignment is for the year the calendar is in, as before.
            'assignmentYearOptions' => $profile['can_change_assignment'] && WorkingSchoolYear::enabled()
                ? WorkingSchoolYear::options($institutionId)
                : [],
            'assignmentCatalog' => $profile['can_change_assignment']
                ? InstitutionSection::catalogFor($institutionId)
                : [],
            // The School Nurse's Health Services Offered; nobody else's page
            // carries it.
            'healthServices' => in_array($profile['role'], HealthServicesOffered::EDIT_ROLES, true) && $institutionId > 0
                ? $this->healthServicesPanel($institutionId, (string) $request->query('services_year', ''))
                : null,
            // The Feeding Program Coordinator's Progress weigh-ins switch.
            'progressWeighIns' => $profile['role'] === 'feeding_coor' && $institutionId > 0
                ? ['supported' => FeedingWeighIns::supported(), 'enabled' => FeedingWeighIns::enabled($institutionId)]
                : null,
        ]);
    }

    /**
     * Turn the school's optional progress weigh-ins on or off (report #33).
     * The coordinator's, for their own school, which the request never names.
     * Off hides the weigh-ins everywhere and keeps every one recorded.
     */
    public function updateProgressWeighIns(Request $request)
    {
        $institutionId = (int) $request->session()->get('active_institution_id');

        abort_unless((string) $request->session()->get('active_role') === 'feeding_coor' && $institutionId > 0, 403, 'Only the Feeding Program Coordinator can change progress weigh-ins.');

        if (! FeedingWeighIns::supported()) {
            return redirect()->route('settings')->with('error', 'The database has not been updated for this setting yet.');
        }

        $on = $request->boolean('enabled');
        $was = FeedingWeighIns::enabled($institutionId);

        Institution::query()->whereKey($institutionId)->update(['feeding_progress_weigh_ins' => $on]);
        RequestMemo::forgetPrefix('weigh-ins:');

        // A raw write, so the audit entry is recorded here.
        AuditTrail::record('updated', 'Institution', $institutionId, 'Progress weigh-ins turned '.($on ? 'on' : 'off').' (were '.($was ? 'on' : 'off').')');

        return redirect()->route('settings')->withFragment('progress-weigh-ins')
            ->with('success', 'Progress weigh-ins are now '.($on ? 'on' : 'off').' for your school.');
    }

    /**
     * Save the school's Health Services Offered for one school year: the
     * services the School Nurse switched on. Scoped to the nurse's own school,
     * which the request never names; the year must be one the panel offers.
     */
    public function updateHealthServices(Request $request)
    {
        $role = (string) $request->session()->get('active_role', '');
        $institutionId = (int) $request->session()->get('active_institution_id');

        abort_unless(in_array($role, HealthServicesOffered::EDIT_ROLES, true) && $institutionId > 0, 403, 'Only the School Nurse can set the health services the school offers.');

        if (! HealthServicesOffered::supported()) {
            return redirect()->route('settings')->with('error', 'The database has not been updated for this setting yet.');
        }

        $validated = $request->validateWithBag('healthServices', [
            'school_year' => ['required', Rule::in($this->healthServicesYears($institutionId))],
            'offered' => ['nullable', 'array'],
            'offered.*' => ['string', Rule::in(array_keys(HealthConsentForm::PLANNED_SERVICES))],
        ], [
            'school_year.in' => 'Choose a school year from the list.',
        ]);

        $schoolYear = (string) $validated['school_year'];

        HealthServicesOffered::save(
            $institutionId,
            $schoolYear,
            array_values(array_unique($validated['offered'] ?? [])),
            (string) $request->session()->get('active_name', 'School Nurse'),
        );

        return redirect()->route('settings', ['services_year' => $schoolYear])
            ->withFragment('health-services')
            ->with('success', "Health services offered for S.Y. {$schoolYear} saved.");
    }

    /**
     * What the Health Services Offered panel shows for one school year.
     *
     * @return array<string, mixed>
     */
    private function healthServicesPanel(int $institutionId, string $requested): array
    {
        $current = HealthConsentForm::currentSchoolYear();
        $years = $this->healthServicesYears($institutionId);
        $schoolYear = in_array($requested, $years, true) ? $requested : $current;
        $setting = HealthServicesOffered::settingFor($institutionId, $schoolYear);

        return [
            'supported' => HealthServicesOffered::supported(),
            'school_year' => $schoolYear,
            'current_year' => $current,
            'years' => $years,
            'offered' => HealthServicesOffered::for($institutionId, $schoolYear),
            // The year the setting on screen was saved for: another year when
            // it was carried over, null when none was ever saved.
            'saved_for' => $setting['school_year'] ?? null,
        ];
    }

    /**
     * The school years the setting can be saved for: the last one, this one
     * and the next, and any year the school's records or settings name.
     *
     * @return list<string>
     */
    private function healthServicesYears(int $institutionId): array
    {
        $start = (int) explode('-', HealthConsentForm::currentSchoolYear())[0];
        $years = [($start - 1).'-'.$start, $start.'-'.($start + 1), ($start + 1).'-'.($start + 2)];

        if (HealthServicesOffered::supported()) {
            $years = [...$years, ...HealthServiceSetting::query()
                ->where('institution_id', $institutionId)
                ->pluck('school_year')
                ->map(fn ($year): string => (string) $year)
                ->all()];
        }

        $years = array_values(array_unique(array_filter($years, fn (string $year): bool => WorkingSchoolYear::isSchoolYear($year))));
        rsort($years);

        return $years;
    }

    /**
     * Update the teacher's assignment without moving student records.
     *
     * While school-year testing is on, the assignment may be for any school
     * year, and the teacher then works in that year (WorkingSchoolYear). The
     * calendar's year is stored as NULL, so the assignment follows the
     * calendar into the next year again.
     */
    public function updateAssignment(Request $request)
    {
        $profile = AccountSettings::profileFor($request);
        abort_unless($profile['can_change_assignment'], 403, 'Only teachers with a stored account can update their own assignment.');

        $institutionId = (int) $request->session()->get('active_institution_id');
        $anyYear = WorkingSchoolYear::enabled();

        $validated = $request->validateWithBag('assignment', [
            'assigned_grade_level' => ['required', 'string', 'max:50'],
            'assigned_section' => ['required', 'string', 'max:100'],
            'school_year' => ['required', Rule::in($anyYear
                ? WorkingSchoolYear::options($institutionId)
                : [StudentHealthRecord::currentSchoolYear()])],
            'confirm_assignment' => ['accepted'],
        ], [
            'school_year.in' => $anyYear
                ? 'Choose a school year from the list.'
                : 'The school year has changed. Reload Settings before updating your assignment.',
            'confirm_assignment.accepted' => 'Confirm that this is your assigned class and the change should take effect now.',
        ]);

        $schoolYear = $validated['school_year'];
        $grade = trim($validated['assigned_grade_level']);
        $section = trim($validated['assigned_section']);

        if (InstitutionSection::hasCatalog($institutionId)) {
            $canonical = InstitutionSection::canonical($institutionId, $grade, $section);

            if ($canonical === null) {
                return redirect()->route('settings')->withInput($request->only([
                    'assigned_grade_level', 'assigned_section', 'school_year',
                ]))->withErrors([
                    'assigned_section' => 'Select a grade level and section offered by your school.',
                ], 'assignment');
            }

            [$grade, $section] = $canonical;
        }

        $account = DB::transaction(function () use ($profile, $institutionId, $grade, $section, $schoolYear, $anyYear) {
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

            if ($anyYear) {
                $old['assigned_school_year'] = $row->assigned_school_year;
                $new['assigned_school_year'] = $schoolYear === ParentalConsentForm::calendarSchoolYear() ? null : $schoolYear;
            }

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
