<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The learner search is on every nurse tab, not only the Dashboard.
 *
 * A nurse who has to navigate Home before they can look somebody up will
 * stop using the search, so "consistent" means present everywhere — the
 * same control, in the same place, whichever tab is open.
 *
 * The adviser side already worked this way because its topbar is one shared
 * partial. The nurse's tabs each hand-roll their own header, which is how
 * the search ended up on one of them, so these tests name every tab on the
 * rail and fail if a new one is added without it.
 */
class NurseSearchOnEveryTabTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    /** Every destination on partials/nurse-lusog-sidebar, bar Logout. */
    public static function nurseTabs(): array
    {
        return [
            'Dashboard' => ['dashboard.school-nurse'],
            'Health Records' => ['dashboard.student-health-records'],
            'Consultation Log' => ['dashboard.consultation-log'],
            'Review Queue' => ['nurse.index'],
            'Medicine Inventory' => ['dashboard.medicine-inventory'],
            'Dispensing Log' => ['dashboard.dispensing-log'],
            'Feeding Program' => ['dashboard.school-nurse.feeding-program'],
            'Data Visualization' => ['dashboard.data-visualization'],
            'Consent Forms' => ['consent-forms.nurse-index'],
            'Health Assessments' => ['health-assessments.nurse-index'],
        ];
    }

    private function nurseSession(array $roster = []): array
    {
        return [
            'active_role' => 'school_nurse',
            'active_name' => 'Nurse Cruz',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
            'school_health_card_records' => $roster,
        ];
    }

    private function learner(): void
    {
        StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'student_id' => '500000000001',
            'student_name' => 'Cruz, Juan',
            'school_name' => 'Sta. Ana NHS',
            'grade_level' => 'Grade 10',
            'section' => 'Grade 10 / Dalton',
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'weight' => '40',
            'bmi_value' => '18',
            'nutritional_status' => 'Normal',
        ]);
    }

    #[DataProvider('nurseTabs')]
    #[Test]
    public function the_search_is_on_this_tab(string $route): void
    {
        $html = $this->withSession($this->nurseSession())
            ->get(route($route))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="lsearchInput"', $html);
        $this->assertStringContainsString('id="lsearchResults"', $html);
        $this->assertStringContainsString('placeholder="Search students..."', $html);
    }

    /** And it is the same control the adviser uses, not a copy. */
    #[DataProvider('nurseTabs')]
    #[Test]
    public function it_is_the_shared_control_on_this_tab(string $route): void
    {
        $html = $this->withSession($this->nurseSession())
            ->get(route($route))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('.lsearch-avatar {', $html);
        $this->assertSame(1, substr_count($html, 'id="lsearchInput"'));
    }

    /**
     * The roster is built by the partial, so a tab whose controller knows
     * nothing about searching still gets the school's learners.
     */
    #[DataProvider('nurseTabs')]
    #[Test]
    public function the_roster_reaches_this_tab(string $route): void
    {
        $this->learner();

        $html = $this->withSession($this->nurseSession())
            ->get(route($route))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('500000000001', $html);
        $this->assertStringContainsString('Cruz, Juan', $html);
    }

    /** Every tab searches to the same place. */
    #[DataProvider('nurseTabs')]
    #[Test]
    public function every_tab_searches_to_the_health_records_page(string $route): void
    {
        $html = $this->withSession($this->nurseSession())
            ->get(route($route))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            json_encode(route('dashboard.student-health-records').'?open={lrn}'),
            $html
        );
    }

    /**
     * Three of those pages are shared with Clinic Staff, whose rail carries
     * no search. Handing a role an affordance its navigation does not have
     * is not consistency.
     */
    #[Test]
    public function clinic_staff_do_not_get_the_nurses_search(): void
    {
        foreach (['dashboard.student-health-records', 'dashboard.consultation-log', 'dashboard.medicine-inventory'] as $route) {
            $html = $this->withSession([
                'active_role' => 'clinic_staff',
                'active_name' => 'Clinic Staff',
                'active_school_name' => 'Sta. Ana NHS',
                'active_institution_id' => $this->school->id,
            ])->get(route($route))->assertOk()->getContent();

            $this->assertStringNotContainsString('id="lsearchInput"', $html, "$route leaked the nurse search.");
        }
    }

    /** Another school's learner is never in the embedded roster. */
    #[Test]
    public function the_roster_never_carries_another_schools_learner(): void
    {
        $other = Institution::create(['name' => 'Wireless ES', 'status' => 'active']);

        StudentHealthRecord::create([
            'institution_id' => $other->id,
            'student_id' => '500000000009',
            'student_name' => 'Other, Learner',
            'school_name' => 'Wireless ES',
            'grade_level' => 'Grade 7',
            'section' => 'Grade 7 / Rizal',
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'weight' => '35',
            'bmi_value' => '17',
            'nutritional_status' => 'Normal',
        ]);

        $html = $this->withSession($this->nurseSession())
            ->get(route('dashboard.school-nurse'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Other, Learner', $html);
        $this->assertStringNotContainsString('500000000009', $html);
    }
}
