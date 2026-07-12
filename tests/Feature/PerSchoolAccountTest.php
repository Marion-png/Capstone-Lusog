<?php

namespace Tests\Feature;

use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PerSchoolAccountTest extends TestCase
{
    use RefreshDatabase;

    private Institution $schoolA;

    private Institution $schoolB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schoolA = Institution::create(['name' => 'School A', 'status' => 'active']);
        $this->schoolB = Institution::create(['name' => 'School B', 'status' => 'active']);
    }

    private function makeAccount(Institution $school, string $username = 'teacher.ana', string $password = 'secret123'): void
    {
        DB::table('accounts')->insert([
            'name' => 'Teacher Ana',
            'username' => $username,
            'password_hash' => Hash::make($password),
            'role' => 'class_adviser',
            'institution_id' => $school->id,
            'school_name' => $school->name,
            'assigned_grade_level' => 'Grade 1',
            'assigned_section' => 'Sampaguita',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test */
    public function the_same_username_can_hold_one_account_per_school(): void
    {
        $this->makeAccount($this->schoolA);
        $this->makeAccount($this->schoolB);

        $this->assertSame(2, DB::table('accounts')->where('username', 'teacher.ana')->count());
    }

    /** @test */
    public function login_with_a_single_school_account_signs_in_directly(): void
    {
        $this->makeAccount($this->schoolA);

        $response = $this->post('/login', [
            'email' => 'teacher.ana',
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('dashboard.class-adviser'));
        $this->assertSame($this->schoolA->id, session('active_institution_id'));
    }

    /** @test */
    public function login_with_accounts_in_two_schools_requires_a_school_choice(): void
    {
        $this->makeAccount($this->schoolA);
        $this->makeAccount($this->schoolB);

        $response = $this->post('/login', [
            'email' => 'teacher.ana',
            'password' => 'secret123',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('school_choices');
        $this->assertNull(session('active_role'));
    }

    /** @test */
    public function login_with_a_selected_school_signs_into_that_schools_account(): void
    {
        $this->makeAccount($this->schoolA);
        $this->makeAccount($this->schoolB);

        $response = $this->post('/login', [
            'email' => 'teacher.ana',
            'password' => 'secret123',
            'institution_id' => $this->schoolB->id,
        ]);

        $response->assertRedirect(route('dashboard.class-adviser'));
        $this->assertSame($this->schoolB->id, session('active_institution_id'));
        $this->assertSame('School B', session('active_school_name'));
    }

    /** @test */
    public function login_rejects_a_wrong_password_even_when_multiple_accounts_exist(): void
    {
        $this->makeAccount($this->schoolA);
        $this->makeAccount($this->schoolB);

        $response = $this->post('/login', [
            'email' => 'teacher.ana',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Invalid username or password.');
        $this->assertNull(session('active_role'));
    }

    /** @test */
    public function registration_rejects_a_duplicate_username_only_within_the_same_school(): void
    {
        $this->makeAccount($this->schoolA);

        // Same username, same school -> rejected
        $sameSchool = $this->post('/account-request', [
            'name' => 'Teacher Ana',
            'username' => 'teacher.ana',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'role' => 'class_adviser',
            'institution_id' => $this->schoolA->id,
            'assigned_grade_level' => 'Grade 2',
            'assigned_section' => 'Rosal',
        ]);
        $sameSchool->assertSessionHasErrors('username');

        // Same username, different school -> accepted
        $otherSchool = $this->post('/account-request', [
            'name' => 'Teacher Ana',
            'username' => 'teacher.ana',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'role' => 'class_adviser',
            'institution_id' => $this->schoolB->id,
            'assigned_grade_level' => 'Grade 2',
            'assigned_section' => 'Rosal',
        ]);
        $otherSchool->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('account_requests')->where('username', 'teacher.ana')->where('institution_id', $this->schoolB->id)->count());
    }
}
