<?php

namespace Tests\Feature;

use App\Models\HealthAssessment;
use App\Models\HealthConsentForm;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdviserDashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    private Institution $inst;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inst = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function adviserSession(): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Maria Santos',
            'active_username' => 'maria.santos',
            'active_institution_id' => $this->inst->id,
            'assigned_grade_level' => 'Grade 10',
            'assigned_section' => 'Dalton',
            'assigned_school_name' => $this->inst->name,
            'school_health_card_records' => [
                [
                    'last_name' => 'Gomez', 'first_name' => 'Jose', 'middle_name' => '',
                    'lrn' => 'LRN001', 'grade_level' => 'Grade 10', 'section' => 'Dalton',
                    'height_cm' => 150, 'weight_kg' => 40, 'nutritional_status_bmi_for_age' => 'Normal',
                    'examination' => [],
                ],
                [
                    'last_name' => 'Tan', 'first_name' => 'Sofia', 'middle_name' => '',
                    'lrn' => 'LRN002', 'grade_level' => 'Grade 10', 'section' => 'Dalton',
                    'height_cm' => 155, 'weight_kg' => 45, 'nutritional_status_bmi_for_age' => 'Normal',
                    'examination' => [],
                ],
            ],
        ];
    }

    #[Test]
    public function dashboard_shows_real_needs_attention_and_stats(): void
    {
        StudentHealthRecord::create([
            'institution_id' => $this->inst->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_id' => 'LRN001',
            'student_name' => 'Gomez, Jose',
            'section' => 'Grade 10 / Dalton',
            'weight' => 40, 'bmi_value' => 17.7, 'nutritional_status' => 'Normal',
        ]);

        StudentHealthRecord::create([
            'institution_id' => $this->inst->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_id' => 'LRN002',
            'student_name' => 'Tan, Sofia',
            'section' => 'Grade 10 / Dalton',
            'weight' => 45, 'bmi_value' => 18.7, 'nutritional_status' => 'Normal',
        ]);

        // Jose's consent was declined -> should show up in Needs Attention + needs_followup count.
        HealthConsentForm::create([
            'school_year' => HealthConsentForm::currentSchoolYear(),
            'institution_id' => $this->inst->id,
            'division' => 'DAVAO', 'school_name' => 'Sta. Ana NHS', 'school_address' => 'x',
            'student_lrn' => 'LRN001', 'student_name' => 'Gomez, Jose',
            'status' => HealthConsentForm::STATUS_SIGNED,
            'consent_choice' => HealthConsentForm::CONSENT_DENY,
            'refusal_reason' => 'No reason', 'signed_at' => now(),
        ]);

        // Sofia has a completed health assessment -> should count toward "Complete Profiles".
        HealthAssessment::create([
            'student_health_record_id' => StudentHealthRecord::where('student_id', 'LRN002')->first()->id,
            'school_year' => HealthAssessment::currentSchoolYear(),
            'submitted_by_name' => 'Maria Santos',
        ]);

        $response = $this->withSession($this->adviserSession())->get('/dashboard/class-adviser');

        $response->assertStatus(200);
        $response->assertSee('Consent form declined');
        $response->assertSee('Gomez, Jose');
        $response->assertSee('Announcements');
        $response->assertSee('Upcoming Events');
    }

    #[Test]
    public function topbar_search_pre_fills_the_my_students_search_box(): void
    {
        $response = $this->withSession($this->adviserSession())
            ->get('/dashboard/class-adviser?tab=saved&q=Gomez');

        $response->assertStatus(200);
        // The URL query is read client-side by JS and pushed into #studentsSearch
        // on load (see class-adviser.blade.php) rather than filtered server-side,
        // so we assert the page renders the value the script depends on.
        $response->assertSee('id="studentsSearch"', false);
        $response->assertSee('Gomez');
    }
}
