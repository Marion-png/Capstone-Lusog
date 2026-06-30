<?php

namespace Tests\Feature;

use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstitutionRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    /** @test */
    public function school_nurse_cannot_register_without_selecting_a_school(): void
    {
        $response = $this->post('/account-request', [
            'name'                  => 'Nurse No School',
            'username'              => 'nurse.noschool',
            'password'              => 'password1',
            'password_confirmation' => 'password1',
            'role'                  => 'school_nurse',
            // institution_id intentionally omitted
        ]);

        $response->assertSessionHasErrors('institution_id');
    }

    /** @test */
    public function clinic_staff_cannot_register_without_selecting_a_school(): void
    {
        $response = $this->post('/account-request', [
            'name'                  => 'Staff No School',
            'username'              => 'staff.noschool',
            'password'              => 'password1',
            'password_confirmation' => 'password1',
            'role'                  => 'clinic_staff',
        ]);

        $response->assertSessionHasErrors('institution_id');
    }

    /** @test */
    public function school_head_cannot_register_without_selecting_a_school(): void
    {
        $response = $this->post('/account-request', [
            'name'                  => 'Head No School',
            'username'              => 'head.noschool',
            'password'              => 'password1',
            'password_confirmation' => 'password1',
            'role'                  => 'school_head',
        ]);

        $response->assertSessionHasErrors('institution_id');
    }

    /** @test */
    public function class_adviser_cannot_register_without_selecting_a_school(): void
    {
        $response = $this->post('/account-request', [
            'name'                  => 'Adviser No School',
            'username'              => 'adviser.noschool',
            'password'              => 'password1',
            'password_confirmation' => 'password1',
            'role'                  => 'class_adviser',
            'assigned_grade_level'  => 'Grade 1',
            'assigned_section'      => 'A',
        ]);

        $response->assertSessionHasErrors('institution_id');
    }

    /** @test */
    public function scoped_role_registers_successfully_with_valid_institution(): void
    {
        $response = $this->post('/account-request', [
            'name'                  => 'Nurse Maria',
            'username'              => 'nurse.maria',
            'password'              => 'password1',
            'password_confirmation' => 'password1',
            'role'                  => 'school_nurse',
            'institution_id'        => $this->institution->id,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('account.request'));

        $this->assertDatabaseHas('account_requests', [
            'username'       => 'nurse.maria',
            'institution_id' => $this->institution->id,
            'school_name'    => 'Test School',
            'status'         => 'pending',
        ]);
    }

    /** @test */
    public function account_request_page_seeds_and_shows_default_schools_when_none_exist(): void
    {
        Institution::query()->delete();

        $response = $this->get('/account-request');

        $response->assertOk();
        $response->assertSee('Davao City National High School');
        $response->assertSee('Doña Carmen Denia National High School');
    }

    /** @test */
    public function institution_id_must_reference_existing_institution(): void
    {
        $response = $this->post('/account-request', [
            'name'                  => 'Nurse Bad',
            'username'              => 'nurse.bad',
            'password'              => 'password1',
            'password_confirmation' => 'password1',
            'role'                  => 'school_nurse',
            'institution_id'        => 99999,
        ]);

        $response->assertSessionHasErrors('institution_id');
    }

    /** @test */
    public function feeding_coordinator_registers_successfully_with_institution(): void
    {
        $response = $this->post('/account-request', [
            'name'                  => 'Feeding Coor',
            'username'              => 'feeding.test',
            'password'              => 'password1',
            'password_confirmation' => 'password1',
            'role'                  => 'feeding_coor',
            'institution_id'        => $this->institution->id,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('account.request'));
    }
}
