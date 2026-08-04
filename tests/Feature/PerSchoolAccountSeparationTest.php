<?php

namespace Tests\Feature;

use App\Models\Institution;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A teacher working in two schools holds two separate accounts.
 *
 * Accounts are central (login has to find a username across all schools to
 * offer the school picker), so their separation is enforced by the composite
 * `username + institution_id` unique key and by every read being scoped to one
 * institution — not by living in different databases.
 */
class PerSchoolAccountSeparationTest extends TestCase
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

    private function makeAccount(Institution $institution, string $username, string $password = 'secret123'): int
    {
        return DB::table('accounts')->insertGetId([
            'name' => 'Maria Cruz',
            'username' => $username,
            'password_hash' => Hash::make($password),
            'role' => 'class_adviser',
            'institution_id' => $institution->id,
            'school_name' => $institution->name,
            'assigned_grade_level' => 'Grade 7/SPED',
            'assigned_section' => 'Rosal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function the_same_username_can_hold_one_account_per_school(): void
    {
        $atA = $this->makeAccount($this->schoolA, 'mcruz');
        $atB = $this->makeAccount($this->schoolB, 'mcruz');

        $this->assertNotSame($atA, $atB, 'Each school must get its own account row.');

        $this->assertSame(2, DB::table('accounts')->where('username', 'mcruz')->count());
        $this->assertSame(1, DB::table('accounts')->where('username', 'mcruz')->where('institution_id', $this->schoolA->id)->count());
        $this->assertSame(1, DB::table('accounts')->where('username', 'mcruz')->where('institution_id', $this->schoolB->id)->count());
    }

    #[Test]
    public function a_duplicate_username_within_one_school_is_rejected(): void
    {
        $this->makeAccount($this->schoolA, 'mcruz');

        $this->expectException(QueryException::class);

        $this->makeAccount($this->schoolA, 'mcruz');
    }

    #[Test]
    public function each_account_carries_its_own_schools_assignment(): void
    {
        $this->makeAccount($this->schoolA, 'mcruz');

        DB::table('accounts')->insert([
            'name' => 'Maria Cruz',
            'username' => 'mcruz',
            'password_hash' => Hash::make('secret123'),
            'role' => 'class_adviser',
            'institution_id' => $this->schoolB->id,
            'school_name' => $this->schoolB->name,
            'assigned_grade_level' => 'Grade 10/SPED',
            'assigned_section' => 'Dalton',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rowA = DB::table('accounts')->where('username', 'mcruz')->where('institution_id', $this->schoolA->id)->first();
        $rowB = DB::table('accounts')->where('username', 'mcruz')->where('institution_id', $this->schoolB->id)->first();

        // The two accounts are genuinely independent records, not one account
        // shared across schools: changing one must not affect the other.
        $this->assertSame('Rosal', $rowA->assigned_section);
        $this->assertSame('Dalton', $rowB->assigned_section);
        $this->assertSame('School A', $rowA->school_name);
        $this->assertSame('School B', $rowB->school_name);
    }

    #[Test]
    public function login_offers_a_school_choice_when_the_username_exists_in_two_schools(): void
    {
        $this->makeAccount($this->schoolA, 'mcruz');
        $this->makeAccount($this->schoolB, 'mcruz');

        // The login form posts the username under `email`.
        $response = $this->post('/login', ['email' => 'mcruz', 'password' => 'secret123']);

        $choices = session('school_choices');

        $this->assertNotNull($choices, 'Login must ask which school when a username matches more than one.');
        $this->assertCount(2, $choices);
        $response->assertRedirect();
    }

    #[Test]
    public function logging_in_with_a_school_choice_binds_only_that_school(): void
    {
        $this->makeAccount($this->schoolA, 'mcruz');
        $this->makeAccount($this->schoolB, 'mcruz');

        $this->post('/login', [
            'email' => 'mcruz',
            'password' => 'secret123',
            'institution_id' => $this->schoolB->id,
        ]);

        $this->assertSame($this->schoolB->id, session('active_institution_id'));
        $this->assertSame('School B', session('active_school_name'));
    }

    #[Test]
    public function a_username_unique_to_one_school_logs_straight_in(): void
    {
        $this->makeAccount($this->schoolA, 'solo');

        $this->post('/login', ['email' => 'solo', 'password' => 'secret123']);

        $this->assertSame($this->schoolA->id, session('active_institution_id'));
        $this->assertNull(session('school_choices'));
    }
}
