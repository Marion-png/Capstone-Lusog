<?php

namespace Tests\Feature;

use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * These pages name every learner at the school with their nutritional status.
 * They had no role check at all, so any signed-in teacher could read them.
 */
class NutritionCoordinatorAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = [
        '/dashboard/nutricor-dashboard',
        '/dashboard/nutricor-beneficiaries',
        '/dashboard/nutricor-analytics',
        '/dashboard/nutricor-atrisk',
        '/dashboard/nutricor-reports',
        '/dashboard/nutricor-comparison',
        '/dashboard/nutricor-reports/print/annex-a',
    ];

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    private function sessionFor(string $role, ?int $institutionId = null): array
    {
        return [
            'active_role' => $role,
            'active_name' => 'Tala Tester',
            'active_username' => 'tala.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $institutionId ?? $this->school->id,
            'assigned_grade_level' => 'Grade 7',
            'assigned_section' => 'Maayos',
        ];
    }

    private function learner(string $name, string $lrn, array $attributes = []): StudentHealthRecord
    {
        return StudentHealthRecord::create($attributes + [
            'institution_id' => $this->school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => $name,
            'student_id' => $lrn,
            'school_name' => 'Test School',
            'section' => 'Grade 7 / Maayos',
            'weight' => 30,
            'bmi_value' => 14.5,
            'nutritional_status' => 'Severely Wasted',
            'baseline_height_cm' => 144,
            'baseline_weight_kg' => 30,
            'baseline_bmi_value' => 14.5,
            'baseline_nutritional_status' => 'Severely Wasted',
            'baseline_recorded_at' => '2026-07-14',
            'feeding_enrolled_at' => '2026-07-20',
            'student_details' => ['gender' => 'Female'],
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function otherRoles(): array
    {
        return [
            'class adviser' => ['class_adviser'],
            'school nurse' => ['school_nurse'],
            'clinic staff' => ['clinic_staff'],
            'clinic teacher' => ['clinic_teacher'],
            'school head' => ['school_head'],
            'feeding coordinator' => ['feeding_coor'],
        ];
    }

    #[Test]
    #[DataProvider('otherRoles')]
    public function no_other_role_can_read_these_pages(string $role): void
    {
        $this->learner('Dimaculangan, Tala', '100000000301');

        foreach (self::PAGES as $page) {
            $this->withSession($this->sessionFor($role))->get($page)->assertForbidden();
        }
    }

    #[Test]
    public function the_nutrition_coordinator_reads_them(): void
    {
        $this->learner('Dimaculangan, Tala', '100000000302');

        foreach (self::PAGES as $page) {
            $this->withSession($this->sessionFor('nutricor'))->get($page)->assertOk();
        }
    }

    #[Test]
    public function no_school_reads_no_learners_not_every_school(): void
    {
        $this->learner('Dimaculangan, Tala', '100000000303');

        $this->withSession($this->sessionFor('nutricor') + ['active_institution_id' => null])
            ->withSession(['active_institution_id' => null, 'active_role' => 'nutricor', 'active_name' => 'Tala Tester'])
            ->get('/dashboard/nutricor-beneficiaries')
            ->assertDontSee('Dimaculangan');
    }

    #[Test]
    public function another_schools_learners_are_not_read(): void
    {
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $this->learner('Batumbakal, Bituin', '100000000304', ['institution_id' => $other->id]);
        $this->learner('Dimaculangan, Tala', '100000000305');

        $this->withSession($this->sessionFor('nutricor'))
            ->get('/dashboard/nutricor-beneficiaries')
            ->assertSee('Dimaculangan')
            ->assertDontSee('Batumbakal');
    }

    #[Test]
    public function every_report_button_leads_to_its_document(): void
    {
        $response = $this->withSession($this->sessionFor('nutricor'))->get('/dashboard/nutricor-reports')->assertOk();

        foreach (['annex-a', 'annex-b', 'terminal', 'monthly', 'analytics', 'at-risk'] as $report) {
            $response->assertSee(route('dashboard.nutricor-reports.print', $report), false);
        }

        $response->assertDontSee('<button class="btn"><i class="fas fa-download"></i>', false);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function reports(): array
    {
        return [
            'annex a' => ['annex-a', 'Master List of Beneficiaries'],
            'annex b' => ['annex-b', 'Summary by Grade Level'],
            'terminal' => ['terminal', 'Terminal Report'],
            'monthly' => ['monthly', 'Monthly Report'],
            'analytics' => ['analytics', 'Analytics Report'],
            'at risk' => ['at-risk', 'For Follow-up Report'],
        ];
    }

    #[Test]
    #[DataProvider('reports')]
    public function each_document_prints_with_and_without_learners(string $report, string $title): void
    {
        $this->withSession($this->sessionFor('nutricor'))
            ->get(route('dashboard.nutricor-reports.print', $report))
            ->assertOk()
            ->assertSee($title)
            ->assertSee('window.print()', false);

        $this->learner('Dimaculangan, Tala', '100000000306');

        $this->withSession($this->sessionFor('nutricor'))
            ->get(route('dashboard.nutricor-reports.print', $report))
            ->assertOk()
            ->assertSee($title);
    }

    #[Test]
    public function an_unknown_report_is_not_found(): void
    {
        $this->withSession($this->sessionFor('nutricor'))
            ->get(route('dashboard.nutricor-reports.print', 'nonsense'))
            ->assertNotFound();
    }

    #[Test]
    public function annex_a_lists_the_enrolled_roll_and_nobody_else(): void
    {
        $this->learner('Dimaculangan, Tala', '100000000307');
        $this->learner('Batumbakal, Bituin', '100000000308', ['feeding_enrolled_at' => null]);

        $this->withSession($this->sessionFor('nutricor'))
            ->get(route('dashboard.nutricor-reports.print', 'annex-a'))
            ->assertSee('Dimaculangan')
            ->assertDontSee('Batumbakal');
    }

    #[Test]
    public function the_monthly_report_counts_confirmed_marks_only(): void
    {
        $learner = $this->learner('Dimaculangan, Tala', '100000000309');

        FeedingAttendance::create(['student_health_record_id' => $learner->id, 'session_date' => '2026-08-03', 'is_present' => true, 'source' => 'manual_entry', 'needs_review' => false]);
        FeedingAttendance::create(['student_health_record_id' => $learner->id, 'session_date' => '2026-08-04', 'is_present' => false, 'source' => 'manual_entry', 'needs_review' => false]);
        FeedingAttendance::create(['student_health_record_id' => $learner->id, 'session_date' => '2026-08-05', 'is_present' => null, 'source' => 'photo_scan', 'needs_review' => true]);

        $this->withSession($this->sessionFor('nutricor'))
            ->get(route('dashboard.nutricor-reports.print', 'monthly'))
            ->assertSee('August 2026')
            ->assertSee('50.0%');
    }

    #[Test]
    public function a_rate_over_nobody_is_an_em_dash(): void
    {
        $this->learner('Dimaculangan, Tala', '100000000310');

        $this->withSession($this->sessionFor('nutricor'))
            ->get('/dashboard/nutricor-comparison')
            ->assertOk()
            ->assertSeeInOrder(['Improvement Rate', '—']);
    }
}
