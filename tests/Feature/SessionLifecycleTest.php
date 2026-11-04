<?php

namespace Tests\Feature;

use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A session holds the school's roster, names and addresses included, and these
 * computers are shared. Signing out has to take all of it, and signing in has to
 * start clean whatever the last person left behind.
 */
class SessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = Institution::create(['name' => 'Test School', 'status' => 'active']);

        DB::table('accounts')->insert([
            'name' => 'Nena Nurse',
            'username' => 'nurse.test',
            'password_hash' => Hash::make('password1'),
            'role' => 'school_nurse',
            'institution_id' => $this->school->id,
            'school_name' => 'Test School',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function leftBehind(): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Somebody Else',
            'active_username' => 'adviser.test',
            'active_institution_id' => 999,
            'active_school_name' => 'Another School',
            'assigned_grade_level' => 'Grade 7',
            'assigned_section' => 'Rizal',
            'school_health_card_records' => [['lrn' => '100000000201', 'last_name' => 'Dimaculangan', 'address' => 'Purok 1']],
            'deworming_requests' => [['id' => 'x']],
        ];
    }

    #[Test]
    public function signing_out_takes_the_roster_with_it(): void
    {
        $this->withSession($this->leftBehind())
            ->post('/logout')
            ->assertRedirect(route('login'));

        $this->assertFalse(session()->has('school_health_card_records'));
        $this->assertFalse(session()->has('deworming_requests'));
        $this->assertFalse(session()->has('active_role'));
        $this->assertFalse(session()->has('assigned_section'));
    }

    #[Test]
    public function signing_in_starts_a_new_session(): void
    {
        $this->withSession($this->leftBehind());
        $before = session()->getId();

        $this->post('/login', ['email' => 'nurse.test', 'password' => 'password1'])
            ->assertRedirect(route('dashboard.school-nurse'));

        $this->assertNotSame($before, session()->getId(), 'A session id planted before signing in is worth nothing after.');
        $this->assertSame('school_nurse', session('active_role'));
        $this->assertSame($this->school->id, session('active_institution_id'));
        $this->assertFalse(session()->has('school_health_card_records'), 'The last person\'s roster does not carry over.');
        $this->assertFalse(session()->has('assigned_section'));
    }

    #[Test]
    public function a_failed_sign_in_leaves_the_session_as_it_was(): void
    {
        $this->withSession($this->leftBehind())
            ->post('/login', ['email' => 'nurse.test', 'password' => 'wrong-password1'])
            ->assertSessionHas('error');

        $this->assertSame('class_adviser', session('active_role'));
    }

    #[Test]
    public function the_system_admin_does_not_inherit_a_school(): void
    {
        config(['app.system_admin' => ['username' => 'systemadmin', 'password' => 'admin-pass-1']]);

        $this->withSession($this->leftBehind())
            ->post('/admin-login', ['username' => 'systemadmin', 'password' => 'admin-pass-1'])
            ->assertRedirect(route('dashboard.system-admin'));

        $this->assertSame('system_admin', session('active_role'));
        $this->assertFalse(session()->has('active_institution_id'), 'The one unscoped role carries no school.');
        $this->assertFalse(session()->has('school_health_card_records'));
    }
}
