<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\SchoolSignatories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The learner's class adviser is named where the nurse, the clinic teacher and
 * the feeding coordinator read a learner: the student profile, the beneficiary
 * record, and the SBFP Forms page's two master lists. The adviser is whoever
 * holds the learner's grade and section at the learner's own school.
 */
class ClassAdviserVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    private function adviser(string $name, string $grade, string $section, ?int $institutionId = null): void
    {
        DB::table('accounts')->insert([
            'name' => $name,
            'username' => 'adviser.'.strtolower(str_replace(' ', '', $section)).random_int(100, 999),
            'password_hash' => Hash::make('secret123'),
            'role' => 'class_adviser',
            'institution_id' => $institutionId ?? $this->institution->id,
            'school_name' => 'Test School',
            'assigned_grade_level' => $grade,
            'assigned_section' => $section,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function learner(string $section = 'Grade 7 / Maabilidad'): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Dela Cruz, Juan M.',
            'student_id' => 'LRN'.random_int(100000, 999999),
            'school_name' => 'Test School',
            'section' => $section,
            'weight' => 18.5,
            'bmi_value' => 13.3,
            'nutritional_status' => 'Severely Wasted',
            'baseline_nutritional_status' => 'Severely Wasted',
            'student_details' => ['gender' => 'Male', 'grade_level' => 'Grade 7', 'section' => 'Maabilidad'],
            'feeding_enrolled_at' => now()->subMonth(),
        ]);
    }

    private function sessionFor(string $role): array
    {
        return [
            'active_role' => $role,
            'active_name' => 'Test User',
            'active_username' => 'test.user',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->institution->id,
        ];
    }

    #[Test]
    public function the_adviser_is_matched_by_class_and_school(): void
    {
        $this->adviser('Maria Adviser', 'Grade 7', 'Maabilidad');
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $this->adviser('Other Adviser', 'Grade 8', 'Matiyaga', $other->id);

        $this->assertSame('Maria Adviser', SchoolSignatories::adviserOf($this->institution->id, 'Grade 7 / Maabilidad'));
        $this->assertSame('Maria Adviser', SchoolSignatories::classAdviser($this->institution->id, 'grade 7', ' MAABILIDAD '));
        // Another school's adviser never answers for this one's class.
        $this->assertSame('', SchoolSignatories::adviserOf($this->institution->id, 'Grade 8 / Matiyaga'));
        $this->assertSame('', SchoolSignatories::adviserOf(null, 'Grade 7 / Maabilidad'));
    }

    #[Test]
    public function the_beneficiary_record_names_the_class_adviser(): void
    {
        $this->adviser('Maria Adviser', 'Grade 7', 'Maabilidad');
        $learner = $this->learner();

        $this->withSession($this->sessionFor('feeding_coor'))
            ->get(route('feedingcor-program.beneficiary', $learner->id))
            ->assertOk()
            ->assertSee('Class Adviser:')
            ->assertSee('Maria Adviser');
    }

    #[Test]
    public function a_class_nobody_holds_reads_not_assigned(): void
    {
        $learner = $this->learner();

        $this->withSession($this->sessionFor('feeding_coor'))
            ->get(route('feedingcor-program.beneficiary', $learner->id))
            ->assertOk()
            ->assertSee('Not assigned');
    }

    #[Test]
    public function the_nurse_and_clinic_teacher_profile_names_the_class_adviser(): void
    {
        $this->adviser('Maria Adviser', 'Grade 7', 'Maabilidad');
        $this->learner();

        foreach (['school_nurse', 'clinic_teacher'] as $role) {
            $this->withSession($this->sessionFor($role))
                ->get('/dashboard/student-health-records')
                ->assertOk()
                ->assertSee('id="pAdviser"', false)
                ->assertSee('Maria Adviser');
        }
    }

    #[Test]
    public function both_master_lists_carry_the_class_adviser(): void
    {
        $this->adviser('Maria Adviser', 'Grade 7', 'Maabilidad');
        $this->learner();

        $response = $this->withSession($this->sessionFor('feeding_coor'))
            ->get('/dashboard/feedingcor-sbfp-forms')
            ->assertOk();

        $this->assertSame(2, substr_count($response->getContent(), '<th class="adviser-col">Class Adviser</th>'));
        // One adviser cell on each of the twenty ruled lines of both lists.
        $this->assertSame(40, substr_count($response->getContent(), 'data-ml="adviser"'));

        $student = collect($response->viewData('studentsByGrade'))->flatten(1)->first();
        $this->assertSame('Maria Adviser', $student['adviser']);
    }
}
