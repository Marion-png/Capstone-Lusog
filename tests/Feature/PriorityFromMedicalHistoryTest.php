<?php

namespace Tests\Feature;

use App\Models\HealthAssessment;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Recording a chronic condition in a learner's medical history puts them on
 * the Priority table, with no second step.
 *
 * PriorityHealthFlagTest covers the rule by creating assessments directly.
 * This one goes the whole way instead — it POSTs the adviser's actual
 * Health Assessment form, the same request the browser sends, and then
 * reads the dashboard. That is what proves "tick asthma in the medical
 * history, see the learner under Priority" rather than proving the rule in
 * isolation and assuming the form reaches it.
 */
class PriorityFromMedicalHistoryTest extends TestCase
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
            'lrn' => '200000000001',
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
            'student_id' => '200000000001',
            'student_name' => 'Cruz, Juan',
            'school_name' => 'Sta. Ana NHS',
            'grade_level' => 'Grade 10',
            // HealthAssessmentController only accepts a submission when the
            // record's section matches "<grade> / <section>" exactly.
            'section' => 'Grade 10 / Dalton',
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'weight' => '40',
            'bmi_value' => '18',
            'nutritional_status' => 'Normal',
        ]);
    }

    /** Submit the medical-history form exactly as the browser would. */
    private function submitMedicalHistory(array $conditions): void
    {
        $this->withSession($this->adviserSession())
            ->post(route('health-assessment.store'), array_merge([
                'lrn' => '200000000001',
                'date_of_assessment' => now()->toDateString(),
                'assessed_by' => 'Maria Santos',
            ], $conditions))
            ->assertRedirect();
    }

    private function dashboard(): string
    {
        return $this->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser'))
            ->assertOk()
            ->getContent();
    }

    #[Test]
    public function ticking_asthma_in_the_medical_history_puts_the_learner_on_priority(): void
    {
        $this->learner();

        // Before: nothing recorded, nobody flagged.
        $this->assertStringContainsString('No learners are flagged as priority right now.', $this->dashboard());

        $this->submitMedicalHistory(['med_asthma' => 1]);

        $html = $this->dashboard();
        $this->assertStringContainsString('Cruz, Juan', $html);
        $this->assertStringContainsString('<span class="ps-chip">Asthma</span>', $html);
        $this->assertStringContainsString('1 Priority</span>', $html);
    }

    #[Test]
    public function each_chronic_condition_on_the_form_reaches_the_priority_table(): void
    {
        $cases = [
            'med_diabetes' => 'Diabetes',
            'med_seizure_disorder' => 'Seizure disorder',
            'med_heart_condition' => 'Heart condition',
            'med_tuberculosis' => 'Tuberculosis',
            'med_allergies' => 'Severe allergies',
        ];

        foreach ($cases as $field => $label) {
            HealthAssessment::query()->delete();
            StudentHealthRecord::query()->delete();
            $this->learner();

            $this->submitMedicalHistory([$field => 1]);

            $this->assertStringContainsString(
                '<span class="ps-chip">'.$label.'</span>',
                $this->dashboard(),
                "Recording {$field} in the medical history must flag the learner."
            );
        }
    }

    /** A history with nothing chronic ticked leaves the learner unflagged. */
    #[Test]
    public function a_clear_medical_history_does_not_flag_the_learner(): void
    {
        $this->learner();

        $this->submitMedicalHistory([
            'med_asthma' => 0,
            'med_diabetes' => 0,
            'med_current_medications' => 'None',
        ]);

        $html = $this->dashboard();
        $this->assertStringContainsString('No learners are flagged as priority right now.', $html);
        $this->assertStringContainsString('0 Priority</span>', $html);
    }

    /**
     * Fields that are not chronic diseases must not flag anyone.
     *
     * A past hospitalisation is an event, and frequent infections is a
     * symptom pattern — both are on the form, neither is a diagnosis, and
     * both are deliberately outside config/health.php's default set.
     */
    #[Test]
    public function non_chronic_answers_do_not_flag_the_learner(): void
    {
        $this->learner();

        $this->submitMedicalHistory([
            'med_hospitalization_surgery' => 1,
            'med_frequent_infections' => 1,
        ]);

        $this->assertStringContainsString(
            'No learners are flagged as priority right now.',
            $this->dashboard()
        );
    }

    /** Correcting the history removes the learner again. */
    #[Test]
    public function correcting_the_medical_history_clears_the_priority_flag(): void
    {
        $this->learner();

        $this->submitMedicalHistory(['med_asthma' => 1]);
        $this->assertStringContainsString('<span class="ps-chip">Asthma</span>', $this->dashboard());

        // Re-submitting the form with asthma unticked is the correction path.
        $this->submitMedicalHistory(['med_asthma' => 0]);

        $this->assertStringContainsString(
            'No learners are flagged as priority right now.',
            $this->dashboard()
        );
    }
}
