<?php

namespace Tests\Feature;

use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Each role's own account settings.
 *
 * One page for all eight sessions, and one write: the signed-in person's own
 * password. Before this there was a Settings item in two rails that pointed at
 * `#`, and nobody in the application could change their password at all.
 *
 * The tests that matter here are the ones about *whose* password: the endpoint
 * takes no account to act on, so `a_session_cannot_change_another_accounts_…`
 * pins that there is nothing to post at it, and
 * `an_account_with_no_password_can_set_one` covers the state the login route
 * treats as matching anything typed — the hole this page is the only way to
 * close.
 */
class UserSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function account(array $overrides = []): int
    {
        return (int) DB::table('accounts')->insertGetId(array_merge([
            'name' => 'Nurse Cruz',
            'username' => 'nurse1',
            'password_hash' => Hash::make('oldpass123'),
            'role' => 'school_nurse',
            'institution_id' => $this->school->id,
            'school_name' => $this->school->name,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function sessionFor(string $role, string $username = 'nurse1'): array
    {
        return [
            'active_role' => $role,
            'active_name' => 'Nurse Cruz',
            'active_username' => $username,
            'active_school_name' => $this->school->name,
            'active_institution_id' => $this->school->id,
        ];
    }

    private function hashFor(int $id): string
    {
        return (string) DB::table('accounts')->where('id', $id)->value('password_hash');
    }

    // ── The page ────────────────────────────────────────────────────────

    /** Every role opens it, in its own rail. */
    #[Test]
    public function the_settings_page_opens_for_every_role(): void
    {
        foreach ([
            'school_nurse', 'clinic_staff', 'clinic_teacher', 'class_adviser',
            'school_head', 'feeding_coor', 'nutricor', 'system_admin',
        ] as $role) {
            $this->withSession($this->sessionFor($role))
                ->get(route('settings'))
                ->assertOk($role.' cannot open its settings.');
        }
    }

    /** It prints the account, and says the details are not changed here. */
    #[Test]
    public function it_prints_the_account_the_person_is_signed_in_with(): void
    {
        $this->account();

        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('settings'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Nurse Cruz', $html);
        $this->assertStringContainsString('nurse1', $html);
        $this->assertStringContainsString('School Nurse', $html);
        $this->assertStringContainsString('Sta. Ana NHS', $html);

        // It is not a user management screen: nothing here edits a role.
        $this->assertStringNotContainsString('name="role"', $html);
        $this->assertStringNotContainsString('name="institution_id"', $html);
    }

    /** A class adviser's own class is the other half of their scope, so it shows. */
    #[Test]
    public function a_class_advisers_assignment_is_printed(): void
    {
        $this->account([
            'username' => 'adviser1',
            'name' => 'Maria Santos',
            'role' => 'class_adviser',
            'assigned_grade_level' => 'Grade 10',
            'assigned_section' => 'Dalton',
        ]);

        $html = $this->withSession(array_merge(
            $this->sessionFor('class_adviser', 'adviser1'),
            ['assigned_grade_level' => 'Grade 10', 'assigned_section' => 'Dalton']
        ))->get(route('settings'))->assertOk()->getContent();

        $this->assertStringContainsString('Grade level', $html);
        $this->assertStringContainsString('Dalton', $html);
    }

    // ── Changing a password ─────────────────────────────────────────────

    /** The ordinary case. */
    #[Test]
    public function a_person_can_change_their_own_password(): void
    {
        $id = $this->account();

        $this->withSession($this->sessionFor('school_nurse'))
            ->from(route('settings'))
            ->post(route('settings.password'), [
                'current_password' => 'oldpass123',
                'password' => 'newpass456',
                'password_confirmation' => 'newpass456',
            ])
            ->assertRedirect(route('settings'))
            ->assertSessionHas('success');

        $hash = $this->hashFor($id);
        $this->assertTrue(Hash::check('newpass456', $hash), 'The new password does not work.');
        $this->assertFalse(Hash::check('oldpass123', $hash), 'The old password still works.');
    }

    /** It is audited, and the password is not in the entry. */
    #[Test]
    public function the_change_is_audited_without_recording_the_password(): void
    {
        $id = $this->account();

        $this->withSession($this->sessionFor('school_nurse'))
            ->from(route('settings'))
            ->post(route('settings.password'), [
                'current_password' => 'oldpass123',
                'password' => 'newpass456',
                'password_confirmation' => 'newpass456',
            ]);

        $entry = DB::table('audit_logs')
            ->where('subject_type', 'Account')
            ->where('subject_id', $id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($entry, 'The password change was not audited.');
        $this->assertStringContainsString('password', (string) $entry->description);

        // An audit log is read by people who are not its subject.
        $row = json_encode((array) $entry);
        $this->assertStringNotContainsString('newpass456', $row);
        $this->assertStringNotContainsString('oldpass123', $row);
    }

    /** A wrong current password changes nothing. */
    #[Test]
    public function the_current_password_must_match(): void
    {
        $id = $this->account();

        $this->withSession($this->sessionFor('school_nurse'))
            ->from(route('settings'))
            ->post(route('settings.password'), [
                'current_password' => 'notitatall',
                'password' => 'newpass456',
                'password_confirmation' => 'newpass456',
            ])
            ->assertRedirect(route('settings'))
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('oldpass123', $this->hashFor($id)), 'The password was changed anyway.');
    }

    /** The same rules registration applies, so neither path is the looser one. */
    #[Test]
    public function the_new_password_must_satisfy_the_registration_rules(): void
    {
        $id = $this->account();

        // Each is refused by a different half of the rule: too short, letters
        // only, digits only. They are a list and not array keys, because PHP
        // turns a numeric key into an int and the digits-only case would then
        // be refused for not being a string at all.
        foreach (['short1a', 'nodigitshere', '123456789'] as $candidate) {
            $this->withSession($this->sessionFor('school_nurse'))
                ->from(route('settings'))
                ->post(route('settings.password'), [
                    'current_password' => 'oldpass123',
                    'password' => $candidate,
                    'password_confirmation' => $candidate,
                ])
                ->assertSessionHasErrors('password', null, 'default', "'{$candidate}' was accepted as a password.");
        }

        $this->assertTrue(Hash::check('oldpass123', $this->hashFor($id)));
    }

    /** Both boxes have to agree. */
    #[Test]
    public function the_confirmation_must_match(): void
    {
        $id = $this->account();

        $this->withSession($this->sessionFor('school_nurse'))
            ->from(route('settings'))
            ->post(route('settings.password'), [
                'current_password' => 'oldpass123',
                'password' => 'newpass456',
                'password_confirmation' => 'newpass789',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('oldpass123', $this->hashFor($id)));
    }

    /** Reporting success for a change that changed nothing is a lie. */
    #[Test]
    public function the_new_password_cannot_be_the_old_one(): void
    {
        $this->account();

        $this->withSession($this->sessionFor('school_nurse'))
            ->from(route('settings'))
            ->post(route('settings.password'), [
                'current_password' => 'oldpass123',
                'password' => 'oldpass123',
                'password_confirmation' => 'oldpass123',
            ])
            ->assertSessionHasErrors('password');
    }

    /**
     * An account with no hash on file is matched by the login route against
     * anything typed. Setting a password is the only way to close that, so it
     * must not be gated behind knowing one that was never set.
     */
    #[Test]
    public function an_account_with_no_password_can_set_one(): void
    {
        $id = $this->account(['password_hash' => null]);

        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('settings'))
            ->assertOk()
            ->getContent();

        // The form says what the state is, and does not ask for a current one.
        $this->assertStringContainsString('Set your password', $html);
        $this->assertStringNotContainsString('name="current_password"', $html);

        $this->withSession($this->sessionFor('school_nurse'))
            ->from(route('settings'))
            ->post(route('settings.password'), [
                'password' => 'firstpass1',
                'password_confirmation' => 'firstpass1',
            ])
            ->assertSessionHas('success');

        $this->assertTrue(Hash::check('firstpass1', $this->hashFor($id)));
    }

    // ── Whose password ──────────────────────────────────────────────────

    /**
     * There is no account to name, so there is nothing to swap.
     *
     * The endpoint resolves the row from the session; an id, a username or a
     * school posted alongside is ignored, and the only password that moves is
     * the signed-in one's.
     */
    #[Test]
    public function a_session_cannot_change_another_accounts_password(): void
    {
        $mine = $this->account();
        $theirs = $this->account(['username' => 'nurse2', 'name' => 'Nurse Reyes']);

        $this->withSession($this->sessionFor('school_nurse'))
            ->from(route('settings'))
            ->post(route('settings.password'), [
                'current_password' => 'oldpass123',
                'password' => 'newpass456',
                'password_confirmation' => 'newpass456',
                // All ignored.
                'id' => $theirs,
                'account_id' => $theirs,
                'username' => 'nurse2',
                'institution_id' => 999,
            ])
            ->assertSessionHas('success');

        $this->assertTrue(Hash::check('newpass456', $this->hashFor($mine)), 'Own password did not change.');
        $this->assertTrue(Hash::check('oldpass123', $this->hashFor($theirs)), "Somebody else's password moved.");
    }

    /** A prototype session belongs to no account, and is told so. */
    #[Test]
    public function a_prototype_session_is_offered_no_password_form(): void
    {
        $html = $this->get(route('settings'))->assertOk()->getContent();

        $this->assertStringContainsString('prototype session', $html);
        $this->assertStringNotContainsString('name="password"', $html);
    }

    /** The System Admin's credentials are environment variables. */
    #[Test]
    public function the_system_admin_is_told_its_password_is_not_stored_here(): void
    {
        $html = $this->withSession($this->sessionFor('system_admin', 'systemadmin'))
            ->get(route('settings'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('server environment', $html);
        $this->assertStringNotContainsString('name="password"', $html);
    }

    /** And the endpoint refuses it, whatever the page renders. */
    #[Test]
    public function the_endpoint_refuses_a_session_with_no_account(): void
    {
        $this->withSession($this->sessionFor('system_admin', 'systemadmin'))
            ->from(route('settings'))
            ->post(route('settings.password'), [
                'password' => 'newpass456',
                'password_confirmation' => 'newpass456',
            ])
            ->assertRedirect(route('settings'))
            ->assertSessionHas('error');
    }

    // ── The School Head ─────────────────────────────────────────────────

    /**
     * The read-only role may still change its own credential.
     *
     * RestrictSchoolHeadWrites refuses every write for this role unless the
     * route is on its allow list. A head who cannot change their password is a
     * head who keeps the one the System Admin typed for them, and the endpoint
     * takes no account to act on, so it cannot reach anybody else's.
     */
    #[Test]
    public function the_school_head_can_change_its_own_password(): void
    {
        $id = $this->account(['username' => 'head1', 'name' => 'Principal Lim', 'role' => 'school_head']);

        $this->withSession($this->sessionFor('school_head', 'head1'))
            ->from(route('settings'))
            ->post(route('settings.password'), [
                'current_password' => 'oldpass123',
                'password' => 'newpass456',
                'password_confirmation' => 'newpass456',
            ])
            ->assertRedirect(route('settings'))
            ->assertSessionHas('success');

        $this->assertTrue(Hash::check('newpass456', $this->hashFor($id)));
    }

    // ── The way in ──────────────────────────────────────────────────────

    /** Every rail links to it, so no role has to know the URL. */
    #[Test]
    public function every_roles_rail_links_to_settings(): void
    {
        $pages = [
            'school_nurse' => '/dashboard/school-nurse',
            'clinic_staff' => '/dashboard/clinic-staff',
            'clinic_teacher' => '/dashboard/clinic-teacher',
            'class_adviser' => '/dashboard/class-adviser',
            'school_head' => '/dashboard/school-head',
            'feeding_coor' => '/dashboard/feedingcor-dashboard',
            'nutricor' => '/dashboard/nutricor-dashboard',
            'system_admin' => '/dashboard/system-admin',
        ];

        foreach ($pages as $role => $uri) {
            $html = $this->withSession($this->sessionFor($role))->get($uri)->assertOk()->getContent();

            $this->assertStringContainsString(
                route('settings'),
                $html,
                $role."'s rail offers no way to reach its settings."
            );
        }
    }
}
