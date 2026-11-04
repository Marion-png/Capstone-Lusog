<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A signed-in person reads their own role's pages and nobody else's.
 *
 * EnsureActiveSession only asks whether somebody is signed in, so each page has
 * to check who. Several did not: any account could open the Feeding Coordinator's
 * dashboard and read the whole school's flagged learners, and the demo sessions
 * every other test runs under hid it, because they are re-seeded as whichever
 * role a URL belongs to. So these sessions are a real sign-in's.
 */
class RoleBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES = ['school_nurse', 'clinic_staff', 'clinic_teacher', 'class_adviser', 'school_head', 'feeding_coor', 'nutricor', 'system_admin'];

    /** @var array<string, list<string>> Page => the only roles it renders for. */
    private const PAGES = [
        '/dashboard/feedingcor-dashboard' => ['feeding_coor'],
        '/dashboard/feedingcor-health-records' => ['feeding_coor'],
        '/dashboard/feedingcor-attendance' => ['feeding_coor'],
        '/dashboard/feedingcor-at-risk' => ['feeding_coor'],
        '/dashboard/feedingcor-program/attendance/review' => ['feeding_coor'],
        '/dashboard/feedingcor-program/attendance/record' => ['feeding_coor'],
        '/dashboard/feedingcor-sbfp-forms' => ['feeding_coor', 'school_nurse', 'clinic_teacher'],
        '/dashboard/school-nurse/forms' => ['feeding_coor', 'school_nurse', 'clinic_teacher'],
        '/dashboard/clinic-teacher/forms' => ['feeding_coor', 'school_nurse', 'clinic_teacher'],
        '/dashboard/school-head' => ['school_head'],
        '/dashboard/school-head/reports' => ['school_head'],
        '/dashboard/school-head/health' => ['school_head'],
        '/dashboard/school-head/consent' => ['school_head'],
        '/dashboard/school-head/inventory' => ['school_head'],
        '/dashboard/school-head/program' => ['school_head'],
        '/dashboard/school-nurse/feeding-program' => ['school_nurse', 'clinic_teacher'],
        '/dashboard/clinic-teacher/feeding-program' => ['school_nurse', 'clinic_teacher'],
        '/dashboard/nutricor-dashboard' => ['nutricor'],
        '/dashboard/nutricor-beneficiaries' => ['nutricor'],
        '/dashboard/nutricor-atrisk' => ['nutricor'],
        '/dashboard/nutricor-consolidated' => ['nutricor'],
        '/dashboard/class-adviser' => ['class_adviser'],
        '/adviser/create' => ['class_adviser'],
        '/dashboard/system-admin' => ['system_admin'],
    ];

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    private function signedInAs(string $role): array
    {
        return [
            'active_role' => $role,
            'active_name' => 'Tala Tester',
            'active_username' => 'tala.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $role === 'system_admin' ? null : $this->school->id,
            'assigned_grade_level' => $role === 'class_adviser' ? 'Grade 7' : null,
            'assigned_section' => $role === 'class_adviser' ? 'Maayos' : null,
        ];
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function pages(): array
    {
        $cases = [];

        foreach (self::PAGES as $page => $allowed) {
            $cases[$page] = [$page, $allowed];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('pages')]
    public function a_page_renders_for_its_own_roles_and_no_others(string $page, array $allowed): void
    {
        foreach (self::ROLES as $role) {
            $status = $this->withSession($this->signedInAs($role))->get($page)->getStatusCode();

            if (in_array($role, $allowed, true)) {
                $this->assertSame(200, $status, "{$role} should read {$page}.");
            } else {
                // A clean refusal, never a page and never a server error.
                $this->assertContains($status, [302, 403], "{$role} must be refused {$page}, got {$status}.");
            }

            $this->flushSession();
        }
    }

    #[Test]
    public function another_role_never_sees_a_learner_on_the_coordinators_dashboard(): void
    {
        StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Dimaculangan, Tala',
            'student_id' => '100000000401',
            'school_name' => 'Test School',
            'section' => 'Grade 8 / Canary',
            'weight' => 30,
            'bmi_value' => 14.5,
            'nutritional_status' => 'Severely Wasted',
            'baseline_nutritional_status' => 'Severely Wasted',
            'feeding_enrolled_at' => now(),
            'student_details' => ['gender' => 'Female'],
        ]);

        $this->withSession($this->signedInAs('class_adviser'))
            ->get('/dashboard/feedingcor-dashboard')
            ->assertRedirect(route('login'))
            ->assertDontSee('Dimaculangan');

        $this->withSession($this->signedInAs('feeding_coor'))
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->assertSee('Dimaculangan');
    }

    /** @return array<string, array{0: string}> */
    public static function rolesThatMayNotEnrol(): array
    {
        return [
            'nurse' => ['school_nurse'],
            'clinic staff' => ['clinic_staff'],
            'clinic teacher' => ['clinic_teacher'],
            'feeding coordinator' => ['feeding_coor'],
            'nutrition coordinator' => ['nutricor'],
            'system admin' => ['system_admin'],
        ];
    }

    /**
     * For anybody but an adviser there was no assignment to apply, so a posted
     * grade and section decided the record: any account could rewrite a learner.
     */
    #[Test]
    #[DataProvider('rolesThatMayNotEnrol')]
    public function only_an_adviser_can_write_a_learner_through_the_enrolment_routes(string $role): void
    {
        $record = StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Dimaculangan, Tala',
            'student_id' => '100000000402',
            'school_name' => 'Test School',
            'section' => 'Grade 7 / Maayos',
            'weight' => 30,
            'bmi_value' => 14.5,
            'nutritional_status' => 'Severely Wasted',
            'student_details' => ['gender' => 'Female'],
        ]);

        $posted = [
            'last_name' => 'Rewritten', 'first_name' => 'Name', 'lrn' => '100000000402',
            'birth_month' => 1, 'birth_day' => 2, 'birth_year' => 2013, 'birthplace' => 'Davao',
            'parent_guardian' => 'Parent', 'address' => 'Street', 'telephone_no' => '0917',
            'height_cm' => 150, 'weight_kg' => 90, 'grade_level' => 'Grade 7', 'section' => 'Maayos',
        ];

        $this->withSession($this->signedInAs($role))->post('/adviser/store', $posted)->assertForbidden();
        $this->withSession($this->signedInAs($role))->post('/adviser/add-to-section', ['add_lrn' => '100000000499'])->assertForbidden();
        $this->withSession($this->signedInAs($role))->post('/adviser/import/confirm', ['token' => 'x'])->assertForbidden();
        $this->withSession($this->signedInAs($role))->post('/adviser/import', [])->assertForbidden();

        $this->assertSame('Dimaculangan, Tala', $record->fresh()->student_name);
        $this->assertSame(1, StudentHealthRecord::count());
    }

    #[Test]
    public function an_adviser_still_enrols_through_them(): void
    {
        DB::table('accounts')->insert([
            'name' => 'Tala Tester', 'username' => 'tala.test', 'password_hash' => Hash::make('password1'),
            'role' => 'class_adviser', 'institution_id' => $this->school->id, 'school_name' => 'Test School',
            'assigned_grade_level' => 'Grade 7', 'assigned_section' => 'Maayos', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withSession($this->signedInAs('class_adviser'))->post('/adviser/store', [
            'last_name' => 'Batumbakal', 'first_name' => 'Bituin', 'lrn' => '100000000403',
            'birth_month' => 1, 'birth_day' => 2, 'birth_year' => 2013, 'birthplace' => 'Davao',
            'parent_guardian' => 'Parent', 'address' => 'Street', 'telephone_no' => '0917',
            'height_cm' => 150, 'weight_kg' => 40, 'grade_level' => 'Grade 7', 'section' => 'Maayos',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('student_health_records', ['student_id' => '100000000403', 'section' => 'Grade 7 / Maayos']);
    }
}
