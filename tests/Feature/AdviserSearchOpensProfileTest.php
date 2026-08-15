<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Picking a learner from the adviser's topbar search opens their profile.
 *
 * The results used to link to the My Students list filtered to that LRN, so
 * choosing a named student only got you a one-row list you had to click
 * again. Typing a term and pressing Enter still searches the list — that is
 * the case where several learners may match.
 */
class AdviserSearchOpensProfileTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function roster(): array
    {
        return [[
            'lrn' => '600000000001',
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'grade_level' => 'Grade 10',
            'section' => 'Dalton',
        ]];
    }

    private function adviserSession(): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Maria Santos',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
            'assigned_grade_level' => 'Grade 10',
            'assigned_section' => 'Dalton',
            'school_health_card_records' => $this->roster(),
        ];
    }

    private function learner(): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'student_id' => '600000000001',
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

    #[Test]
    public function a_search_result_links_to_the_student_profile(): void
    {
        $html = $this->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser'))
            ->assertOk()
            ->getContent();

        // The Blade expression is rendered server-side, so assert on the
        // resolved URL rather than the source it was written as.
        $this->assertStringContainsString(
            url('dashboard/class-adviser/students').'/${encodeURIComponent(lrn)}',
            $html
        );
        $this->assertStringContainsString('${studentsUrl(s.lrn)}', $html);

        // …not the filtered list it used to open.
        $this->assertStringNotContainsString('?tab=saved&q=${encodeURIComponent(lrn)}', $html);
    }

    #[Test]
    public function the_embedded_roster_carries_the_lrn_the_link_needs(): void
    {
        $html = $this->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('600000000001', $html);
        $this->assertStringContainsString('Cruz, Juan', $html);
    }

    /** The link the dropdown builds actually resolves to the profile. */
    #[Test]
    public function that_link_opens_the_learners_profile(): void
    {
        $this->learner();

        $this->withSession($this->adviserSession())
            ->get('/dashboard/class-adviser/students/600000000001')
            ->assertOk()
            ->assertSee('Cruz');
    }

    /** Pressing Enter still searches the list, where several may match. */
    #[Test]
    public function submitting_the_search_form_still_opens_the_filtered_list(): void
    {
        $html = $this->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="asbSearchForm"', $html);
        $this->assertStringContainsString('<input type="hidden" name="tab" value="saved">', $html);
    }

    /** A learner outside this adviser's class is turned away. */
    #[Test]
    public function another_advisers_learner_is_not_reachable(): void
    {
        $this->learner();

        $this->withSession($this->adviserSession())
            ->get('/dashboard/class-adviser/students/999999999999')
            ->assertRedirect(route('dashboard.class-adviser', ['tab' => 'saved']));
    }
}
