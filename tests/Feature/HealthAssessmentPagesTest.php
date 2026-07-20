<?php

namespace Tests\Feature;

use App\Models\HealthAssessment;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HealthAssessmentPagesTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function adviserSession(): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Maria Santos',
            'active_username' => 'maria.santos',
            'active_institution_id' => $this->institution->id,
            'assigned_grade_level' => 'Grade 7/SPED',
            'assigned_section' => 'SPED-A',
            'assigned_school_name' => 'Sta. Ana NHS',
            'school_health_card_records' => [
                [
                    'last_name' => 'Dela Cruz',
                    'first_name' => 'Juan',
                    'lrn' => '123456789012',
                    'grade_level' => 'Grade 7/SPED',
                    'section' => 'SPED-A',
                    'gender' => 'Male',
                    'age' => 12,
                    'birth_month' => '01',
                    'birth_day' => '15',
                    'birth_year' => '2014',
                ],
            ],
        ];
    }

    private function nurseSession(): array
    {
        return [
            'active_role' => 'school_nurse',
            'active_name' => 'Nurse Reyes',
            'active_institution_id' => $this->institution->id,
        ];
    }

    private function makeRecord(): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'institution_id' => $this->institution->id,
            'student_id' => '123456789012',
            'student_name' => 'Dela Cruz, Juan',
            'school_name' => 'Sta. Ana NHS',
            'section' => 'Grade 7/SPED / SPED-A',
            'weight' => 38.5,
            'bmi_value' => 16.2,
            'nutritional_status' => 'Normal',
        ]);
    }

    private function submitAssessment(): HealthAssessment
    {
        $this->withSession($this->adviserSession())->post(route('health-assessment.store'), [
            'lrn' => '123456789012',
            'date_of_assessment' => '2026-07-10',
            'assessed_by' => 'Maria Santos, Adviser',
            'med_asthma' => 1,
            'body_systems' => [
                'heent_eyes' => ['findings' => ['Redness'], 'notes' => 'Mild irritation'],
            ],
            'teeth_condition' => ['Fair', 'Dental Caries'],
            'immunization_status' => 'Complete',
            'summary_of_findings' => 'Generally healthy.',
        ]);

        return HealthAssessment::firstOrFail();
    }

    #[Test]
    public function adviser_index_lists_students_with_assessment_status(): void
    {
        $this->makeRecord();

        $this->withSession($this->adviserSession())
            ->get(route('health-assessments.index'))
            ->assertStatus(200)
            ->assertSee('Dela Cruz, Juan')
            ->assertSee('Not started');
    }

    #[Test]
    public function form_page_renders_prefilled_learner_information(): void
    {
        $this->makeRecord();

        $this->withSession($this->adviserSession())
            ->get(route('health-assessments.form', '123456789012'))
            ->assertStatus(200)
            ->assertSee('Learner Information')
            ->assertSee('Dela Cruz, Juan')
            ->assertSee('Evaluation of Body Systems');
    }

    #[Test]
    public function form_page_requires_a_health_card_record_first(): void
    {
        $this->withSession($this->adviserSession())
            ->get(route('health-assessments.form', '123456789012'))
            ->assertRedirect(route('health-assessments.index'));
    }

    #[Test]
    public function submitted_assessment_is_visible_on_the_adviser_show_page(): void
    {
        $this->makeRecord();
        $assessment = $this->submitAssessment();

        $this->withSession($this->adviserSession())
            ->get(route('health-assessments.show', $assessment))
            ->assertStatus(200)
            ->assertSee('Asthma')
            ->assertSee('Redness')
            ->assertSee('Dental Caries')
            ->assertSee('Generally healthy.');
    }

    #[Test]
    public function submitted_assessment_is_visible_to_the_nurse(): void
    {
        $this->makeRecord();
        $assessment = $this->submitAssessment();

        $this->withSession($this->nurseSession())
            ->get(route('health-assessments.nurse-index'))
            ->assertStatus(200)
            ->assertSee('Dela Cruz, Juan');

        $this->withSession($this->nurseSession())
            ->get(route('health-assessments.show', $assessment))
            ->assertStatus(200)
            ->assertSee('Generally healthy.');
    }

    #[Test]
    public function nurse_cannot_open_the_adviser_assessment_pages(): void
    {
        $this->makeRecord();

        $this->withSession($this->nurseSession())
            ->get(route('health-assessments.index'))
            ->assertRedirect(route('login'));

        $this->withSession($this->nurseSession())
            ->get(route('health-assessments.form', '123456789012'))
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function resubmitting_replaces_the_existing_assessment(): void
    {
        $this->makeRecord();
        $this->submitAssessment();

        $this->withSession($this->adviserSession())->post(route('health-assessment.store'), [
            'lrn' => '123456789012',
            'summary_of_findings' => 'Updated summary.',
        ]);

        $this->assertSame(1, HealthAssessment::count());
        $this->assertSame('Updated summary.', HealthAssessment::first()->summary_of_findings);
    }
}
