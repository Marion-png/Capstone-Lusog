<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Institution;
use App\Models\Medicine;
use App\Models\StudentHealthRecord;
use App\Support\MedicineCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstitutionScopeTest extends TestCase
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

    // -----------------------------------------------------------------------
    // Consultations
    // -----------------------------------------------------------------------

    /** @test */
    public function class_adviser_only_retrieves_consultations_from_their_school(): void
    {
        Consultation::create([
            'institution_id' => $this->schoolA->id,
            'consulted_at' => now(),
            'student_name' => 'Student A',
            'grade_section' => '1-A',
            'condition' => 'Fever',
            'status' => 'treated',
        ]);

        Consultation::create([
            'institution_id' => $this->schoolB->id,
            'consulted_at' => now(),
            'student_name' => 'Student B',
            'grade_section' => '1-B',
            'condition' => 'Cough',
            'status' => 'treated',
        ]);

        $response = $this->withSession([
            'active_role' => 'clinic_staff',
            'active_institution_id' => $this->schoolA->id,
        ])->get('/dashboard/consultation-log');

        $response->assertStatus(200);
        $response->assertSee('Student A');
        $response->assertDontSee('Student B');
    }

    /** @test */
    public function system_admin_can_see_all_consultations(): void
    {
        Consultation::create([
            'institution_id' => $this->schoolA->id,
            'consulted_at' => now(),
            'student_name' => 'Student A',
            'grade_section' => '1-A',
            'condition' => 'Fever',
            'status' => 'treated',
        ]);

        Consultation::create([
            'institution_id' => $this->schoolB->id,
            'consulted_at' => now(),
            'student_name' => 'Student B',
            'grade_section' => '1-B',
            'condition' => 'Cough',
            'status' => 'treated',
        ]);

        // System admin has no institution_id — null means see all
        $response = $this->withSession([
            'active_role' => 'system_admin',
            'active_institution_id' => null,
        ])->get('/dashboard/consultation-log');

        $response->assertStatus(200);
        $response->assertSee('Student A');
        $response->assertSee('Student B');
    }

    // -----------------------------------------------------------------------
    // Medicines
    // -----------------------------------------------------------------------

    /** @test */
    public function school_nurse_only_sees_medicines_from_their_school(): void
    {
        Medicine::create([
            'institution_id' => $this->schoolA->id,
            'name' => 'Paracetamol A',
            'stock_quantity' => 50,
            'minimum_threshold' => 10,
            'unit' => 'tablets',
        ]);

        Medicine::create([
            'institution_id' => $this->schoolB->id,
            'name' => 'Paracetamol B',
            'stock_quantity' => 30,
            'minimum_threshold' => 10,
            'unit' => 'tablets',
        ]);

        $response = $this->withSession([
            'active_role' => 'school_nurse',
            'active_institution_id' => $this->schoolA->id,
        ])->get('/dashboard/medicine-inventory');

        $response->assertStatus(200);
        $response->assertSee('Paracetamol A');
        $response->assertDontSee('Paracetamol B');
    }

    // -----------------------------------------------------------------------
    // Medicine store stamps institution_id
    // -----------------------------------------------------------------------

    /** @test */
    public function storing_medicine_stamps_the_nurses_institution_id(): void
    {
        $this->withSession([
            'active_role' => 'school_nurse',
            'active_institution_id' => $this->schoolA->id,
        ])->post('/dashboard/medicine-inventory', [
            // The name comes from the DepEd catalogue choice, never from a
            // posted `name` — see App\Support\MedicineCatalogue. Amoxicillin
            // is an antibiotic and is not on the list, so it goes in as an
            // explicit off-list entry, which is the path the nurse described.
            'catalogue_name' => MedicineCatalogue::OTHER,
            'custom_name' => 'Amoxicillin',
            'off_catalogue_reason' => 'Prescribed course dispensed at the clinic',
            'stock_quantity' => 100,
            'minimum_threshold' => 20,
            'unit' => 'capsules',
        ]);

        $this->assertDatabaseHas('medicines', [
            'name' => 'Amoxicillin',
            'institution_id' => $this->schoolA->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // Student health records
    // -----------------------------------------------------------------------

    /** @test */
    public function scoped_user_cannot_see_another_schools_health_records(): void
    {
        StudentHealthRecord::create([
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'institution_id' => $this->schoolB->id,
            'student_name' => 'Ghost Student',
            'student_id' => 'LRN-999',
            'section' => '1-B',
            'weight' => 30.0,
            'bmi_value' => 15.5,
            'nutritional_status' => 'Wasted',
        ]);

        $response = $this->withSession([
            'active_role' => 'clinic_staff',
            'active_institution_id' => $this->schoolA->id,
        ])->get('/dashboard/student-health-records');

        $response->assertStatus(200);
        $response->assertDontSee('Ghost Student');
    }

    // -----------------------------------------------------------------------
    // InstitutionScope middleware — blocks scoped user with no institution_id
    // -----------------------------------------------------------------------

    /** @test */
    public function scoped_role_with_no_institution_id_is_redirected_to_login(): void
    {
        $response = $this->withSession([
            'active_role' => 'school_nurse',
            'active_institution_id' => null,
        ])->get('/dashboard/school-nurse');

        $response->assertRedirect(route('login'));
    }

    // -----------------------------------------------------------------------
    // GET /api/institutions
    // -----------------------------------------------------------------------

    /**
     * The account-request form is this endpoint's only consumer and
     * registration is limited to one school, so it returns that school alone —
     * never the whole catalogue, or the form would silently reopen to every
     * school it renders.
     *
     * @test
     */
    public function api_institutions_returns_only_the_registration_school(): void
    {
        Institution::create(['name' => 'Inactive School', 'status' => 'inactive']);
        Institution::firstOrCreate(
            ['name' => Institution::REGISTRATION_SCHOOL],
            ['status' => 'active']
        );

        $response = $this->get('/api/institutions');

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['name' => Institution::REGISTRATION_SCHOOL]);
        $response->assertJsonMissing(['name' => 'School A']);
        $response->assertJsonMissing(['name' => 'Inactive School']);
    }
}
