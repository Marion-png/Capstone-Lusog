<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\InstitutionSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A class adviser registers against their school's own section list.
 *
 * The grade + section pair an adviser registers with is what scopes every
 * learner they may enter and read for the rest of the year, so registration
 * offers the school's published sections rather than a text box, and the server
 * refuses a pair that school does not run.
 */
class InstitutionSectionCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = Institution::create(['name' => 'Sta. Ana National High School', 'status' => 'active']);

        foreach ([
            'Grade 7' => ['MATIYAGA', 'MASIPAG'],
            'Grade 8' => ['EAGLE', 'FALCON'],
            'Grade 10' => ['RIZAL', 'MABINI'],
        ] as $grade => $names) {
            foreach ($names as $name) {
                InstitutionSection::create([
                    'institution_id' => $this->school->id,
                    'grade_level' => $grade,
                    'name' => $name,
                ]);
            }
        }
    }

    /** @test */
    public function the_sections_endpoint_returns_one_schools_grades_and_sections(): void
    {
        $payload = $this->getJson("/api/institutions/{$this->school->id}/sections")
            ->assertOk()
            ->json('grades');

        // Grade 10 sorts after Grade 9, which it would not do alphabetically.
        $this->assertSame(['Grade 7', 'Grade 8', 'Grade 10'], array_keys($payload));
        $this->assertSame(['MASIPAG', 'MATIYAGA'], $payload['Grade 7']);
        $this->assertSame(['MABINI', 'RIZAL'], $payload['Grade 10']);
    }

    /** @test */
    public function a_school_without_a_published_catalog_returns_nothing(): void
    {
        $other = Institution::create(['name' => 'Other National High School', 'status' => 'active']);

        $this->getJson("/api/institutions/{$other->id}/sections")
            ->assertOk()
            ->assertExactJson(['grades' => []]);
    }

    /** @test */
    public function an_adviser_may_register_with_a_section_their_school_runs(): void
    {
        $this->post('/account-request', [
            'name' => 'Maria Santos',
            'username' => 'maria.santos',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'role' => 'class_adviser',
            'institution_id' => $this->school->id,
            'assigned_grade_level' => 'Grade 7',
            'assigned_section' => 'MATIYAGA',
        ])->assertSessionHasNoErrors();

        $row = DB::table('account_requests')->where('username', 'maria.santos')->first();

        $this->assertSame('Grade 7', $row->assigned_grade_level);
        $this->assertSame('MATIYAGA', $row->assigned_section);
    }

    /** @test */
    public function a_section_the_school_does_not_run_is_refused(): void
    {
        $this->post('/account-request', [
            'name' => 'Typo Teacher',
            'username' => 'typo.teacher',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'role' => 'class_adviser',
            'institution_id' => $this->school->id,
            'assigned_grade_level' => 'Grade 7',
            'assigned_section' => 'MATIYGA',
        ])->assertSessionHasErrors('assigned_section');

        $this->assertDatabaseMissing('account_requests', ['username' => 'typo.teacher']);
    }

    /** @test */
    public function a_section_from_another_grade_is_refused(): void
    {
        // RIZAL is a Grade 10 section at this school, not a Grade 7 one.
        $this->post('/account-request', [
            'name' => 'Wrong Grade',
            'username' => 'wrong.grade',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'role' => 'class_adviser',
            'institution_id' => $this->school->id,
            'assigned_grade_level' => 'Grade 7',
            'assigned_section' => 'RIZAL',
        ])->assertSessionHasErrors('assigned_section');
    }

    /** @test */
    public function a_section_from_another_school_is_refused(): void
    {
        $other = Institution::create(['name' => 'Neighbouring National High School', 'status' => 'active']);
        InstitutionSection::create([
            'institution_id' => $other->id,
            'grade_level' => 'Grade 7',
            'name' => 'SAMPAGUITA',
        ]);

        $this->post('/account-request', [
            'name' => 'Wrong School',
            'username' => 'wrong.school',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'role' => 'class_adviser',
            'institution_id' => $this->school->id,
            'assigned_grade_level' => 'Grade 7',
            'assigned_section' => 'SAMPAGUITA',
        ])->assertSessionHasErrors('assigned_section');
    }

    /** @test */
    public function the_catalogs_own_spelling_is_what_gets_stored(): void
    {
        // Two advisers who type their section differently must still end up
        // scoped by the same string, or the roster filters would split the class.
        $this->post('/account-request', [
            'name' => 'Lower Case',
            'username' => 'lower.case',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'role' => 'class_adviser',
            'institution_id' => $this->school->id,
            'assigned_grade_level' => 'Grade 7',
            'assigned_section' => '  matiyaga ',
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            'MATIYAGA',
            DB::table('account_requests')->where('username', 'lower.case')->value('assigned_section')
        );
    }

    /** @test */
    public function a_school_without_a_catalog_still_accepts_a_typed_section(): void
    {
        $other = Institution::create(['name' => 'Unpublished National High School', 'status' => 'active']);

        $this->post('/account-request', [
            'name' => 'Typed Section',
            'username' => 'typed.section',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'role' => 'class_adviser',
            'institution_id' => $other->id,
            'assigned_grade_level' => 'Grade 4/SPED',
            'assigned_section' => 'SPED-A',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('account_requests', [
            'username' => 'typed.section',
            'assigned_section' => 'SPED-A',
        ]);
    }

    /** @test */
    public function a_non_adviser_role_is_never_checked_against_the_catalog(): void
    {
        $this->post('/account-request', [
            'name' => 'Nurse Cruz',
            'username' => 'nurse.cruz',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'role' => 'school_nurse',
            'institution_id' => $this->school->id,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('account_requests', ['username' => 'nurse.cruz']);
    }

    /**
     * Two advisers, one school, different sections: each enters and reads only
     * their own class. This is what the catalogue exists to make exact — the
     * grade + section pair chosen at registration is the whole scope.
     *
     * @test
     */
    public function two_advisers_at_one_school_read_only_their_own_section(): void
    {
        $this->enrol('Grade 7', 'MATIYAGA', 'Matiyaga', '100000000001');
        $this->enrol('Grade 10', 'RIZAL', 'Rizalio', '100000000002');

        $this->flushSession()
            ->withSession($this->adviserSession('Grade 7', 'MATIYAGA'))
            ->get(route('dashboard.class-adviser', ['tab' => 'saved']))
            ->assertOk()
            ->assertSee('100000000001')
            ->assertDontSee('100000000002');

        $this->flushSession()
            ->withSession($this->adviserSession('Grade 10', 'RIZAL'))
            ->get(route('dashboard.class-adviser', ['tab' => 'saved']))
            ->assertOk()
            ->assertSee('100000000002')
            ->assertDontSee('100000000001');
    }

    /** @return array<string, mixed> */
    private function adviserSession(string $grade, string $section): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Test Adviser',
            'active_username' => 'adviser1',
            'active_institution_id' => $this->school->id,
            'active_school_name' => $this->school->name,
            'assigned_school_name' => $this->school->name,
            'assigned_grade_level' => $grade,
            'assigned_section' => $section,
        ];
    }

    private function enrol(string $grade, string $section, string $lastName, string $lrn): void
    {
        $this->flushSession()
            ->withSession($this->adviserSession($grade, $section))
            ->post(route('adviser.store'), [
                'last_name' => $lastName,
                'first_name' => 'Juan',
                'middle_name' => 'A',
                'lrn' => $lrn,
                'birth_month' => 6,
                'birth_day' => 1,
                'birth_year' => 2012,
                'birthplace' => 'Davao City',
                'parent_guardian' => 'Maria '.$lastName,
                'address' => '123 Mabini St., Davao City',
                'telephone_no' => '09171234567',
                'gender' => 'Male',
                'height_cm' => 150,
                'weight_kg' => 40,
                'grade_level' => $grade,
                'section' => $section,
            ])
            ->assertRedirect(route('dashboard.class-adviser'));
    }
}
