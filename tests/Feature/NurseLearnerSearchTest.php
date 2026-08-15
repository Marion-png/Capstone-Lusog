<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The nurse can go from the dashboard to a learner's profile to a
 * consultation without hunting through a list.
 *
 * Dashboard search -> profile -> "New Consultation", pre-filled.
 *
 * The consultation link carries the LRN only. Names are encrypted and URLs
 * are logged and shared, so the form looks the learner up server-side
 * rather than trusting a name passed through the query string — which also
 * means the pre-fill cannot be spoofed by editing the URL.
 */
class NurseLearnerSearchTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
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

    private function learner(string $lrn = '500000000001', string $name = 'Cruz, Juan'): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'student_id' => $lrn,
            'student_name' => $name,
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
    public function the_dashboard_offers_a_learner_search(): void
    {
        $html = $this->withSession($this->nurseSession())
            ->get(route('dashboard.school-nurse'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="learnerFind"', $html);
        $this->assertStringContainsString('id="learnerFindResults"', $html);

        // It sits in the topbar with a dropdown, like the adviser's.
        $this->assertStringContainsString('id="nurseSearchBox"', $html);
        $this->assertStringContainsString('placeholder="Search students..."', $html);

        $topbarEnd = strpos($html, '</header>');
        $searchAt = strpos($html, 'id="nurseSearchBox"');
        $this->assertNotFalse($topbarEnd);
        $this->assertLessThan($topbarEnd, $searchAt, 'The search belongs in the topbar, not the page body.');
    }

    /** The roster is embedded, because encrypted names cannot be searched in SQL. */
    #[Test]
    public function the_search_embeds_the_schools_roster(): void
    {
        $this->learner('500000000001', 'Cruz, Juan');

        $html = $this->withSession($this->nurseSession([[
            'lrn' => '500000000001',
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'grade_level' => 'Grade 10',
            'section' => 'Dalton',
        ]]))->get(route('dashboard.school-nurse'))->assertOk()->getContent();

        $this->assertStringContainsString('Cruz, Juan', $html);
        $this->assertStringContainsString('500000000001', $html);
    }

    #[Test]
    public function the_profile_offers_a_new_consultation_button(): void
    {
        $html = $this->withSession($this->nurseSession())
            ->get(route('dashboard.student-health-records'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="profileConsultLink"', $html);
        $this->assertStringContainsString('New Consultation', $html);

        // It opens the consultation dialog over the profile rather than
        // navigating away, with the learner already filled in.
        $this->assertStringContainsString('id="consultModal"', $html);
        $this->assertStringContainsString('window.openConsultationFor(name, section)', $html);
    }

    /** Arriving with ?open=<lrn> opens that learner straight away. */
    #[Test]
    public function the_records_page_can_open_a_learner_directly(): void
    {
        $this->learner('500000000001', 'Cruz, Juan');

        // A row must actually render for the match to have anything to find.
        $html = $this->withSession($this->nurseSession([[
            'lrn' => '500000000001',
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'grade_level' => 'Grade 10',
            'section' => 'Dalton',
        ]]))->get(route('dashboard.student-health-records'))->assertOk()->getContent();

        $this->assertStringContainsString("new URLSearchParams(window.location.search).get('open')", $html);
        $this->assertStringContainsString('data-lrn="500000000001"', $html);
    }

    #[Test]
    public function the_consultation_form_is_prefilled_from_the_lrn(): void
    {
        $this->learner('500000000001', 'Cruz, Juan');

        $html = $this->withSession($this->nurseSession())
            ->get(route('consultations.create', ['lrn' => '500000000001']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="Cruz, Juan"', $html);
        $this->assertStringContainsString('value="Grade 10 / Dalton"', $html);
    }

    /** Without an LRN the form is simply blank, not broken. */
    #[Test]
    public function the_consultation_form_still_opens_with_no_learner(): void
    {
        $this->withSession($this->nurseSession())
            ->get(route('consultations.create'))
            ->assertOk()
            ->assertSee('Student Name');
    }

    /** An unknown LRN pre-fills nothing rather than echoing the query back. */
    #[Test]
    public function an_unknown_lrn_prefills_nothing(): void
    {
        $html = $this->withSession($this->nurseSession())
            ->get(route('consultations.create', ['lrn' => '<script>alert(1)</script>']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('name="student_name" value=""', $html);
    }

    /** A learner at another school is never pre-filled. */
    #[Test]
    public function another_schools_learner_is_not_prefilled(): void
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
            ->get(route('consultations.create', ['lrn' => '500000000009']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Other, Learner', $html);
    }
}
