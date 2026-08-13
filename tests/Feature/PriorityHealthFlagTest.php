<?php

namespace Tests\Feature;

use App\Models\HealthAssessment;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\PriorityHealthRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A learner with a chronic or life-threatening condition is flagged
 * "Priority" on the Class Adviser dashboard.
 *
 * The flag is derived from the adviser's own health assessment on every
 * read — never stored, never toggled by hand. Correcting the assessment
 * corrects the flag, so there is no second copy of the truth to drift.
 *
 * It is deliberately NOT the feeding programme's at-risk flag: that one
 * comes from attendance and means something else. These tests pin both the
 * rule and the fact that the two stay separate.
 */
class PriorityHealthFlagTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function adviserSession(array $roster = []): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Maria Santos',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
            'assigned_grade_level' => 'Grade 10',
            'assigned_section' => 'Dalton',
            'school_health_card_records' => $roster,
        ];
    }

    /** A learner on this adviser's roster, with an assessment. */
    private function learner(string $lrn, string $name, array $assessmentFlags = []): StudentHealthRecord
    {
        $record = StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'student_id' => $lrn,
            'student_name' => $name,
            'school_name' => 'Sta. Ana NHS',
            'grade_level' => 'Grade 10',
            'section' => 'Dalton',
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'weight' => '40',
            'bmi_value' => '18',
            'nutritional_status' => 'Normal',
        ]);

        if ($assessmentFlags !== []) {
            HealthAssessment::create(array_merge([
                'student_health_record_id' => $record->id,
                'school_year' => HealthAssessment::currentSchoolYear(),
            ], $assessmentFlags));
        }

        return $record;
    }

    private function rosterRow(string $lrn, string $first, string $last): array
    {
        return [
            'lrn' => $lrn,
            'first_name' => $first,
            'last_name' => $last,
            'grade_level' => 'Grade 10',
            'section' => 'Dalton',
        ];
    }

    #[Test]
    public function asthma_alone_makes_a_learner_priority(): void
    {
        $this->learner('100000000001', 'Cruz, Juan', ['med_asthma' => true]);

        $html = $this->withSession($this->adviserSession([
            $this->rosterRow('100000000001', 'Juan', 'Cruz'),
        ]))->get(route('dashboard.class-adviser'))->assertOk()->getContent();

        $this->assertStringContainsString('Priority', $html);
        $this->assertStringContainsString('Priority — Asthma', $html);
    }

    #[Test]
    public function every_configured_chronic_condition_raises_the_flag(): void
    {
        foreach (array_keys(PriorityHealthRule::conditions()) as $field) {
            $assessment = new HealthAssessment([$field => true]);

            $this->assertTrue(
                PriorityHealthRule::applies($assessment),
                "{$field} should raise the Priority flag."
            );
        }
    }

    #[Test]
    public function a_learner_with_no_chronic_condition_is_not_priority(): void
    {
        $this->learner('100000000002', 'Reyes, Ana', ['med_asthma' => false, 'med_diabetes' => false]);

        $html = $this->withSession($this->adviserSession([
            $this->rosterRow('100000000002', 'Ana', 'Reyes'),
        ]))->get(route('dashboard.class-adviser'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Priority — ', $html);
        $this->assertStringContainsString('<b>0</b><span>Priority</span>', $html);
    }

    /** An unknown health profile is not the same as a clear one. */
    #[Test]
    public function a_learner_with_no_assessment_at_all_is_not_priority(): void
    {
        $this->learner('100000000003', 'Santos, Mia');

        $html = $this->withSession($this->adviserSession([
            $this->rosterRow('100000000003', 'Mia', 'Santos'),
        ]))->get(route('dashboard.class-adviser'))->assertOk()->getContent();

        $this->assertStringContainsString('<b>0</b><span>Priority</span>', $html);
        $this->assertFalse(PriorityHealthRule::applies(null));
    }

    #[Test]
    public function several_conditions_are_all_named_on_the_badge(): void
    {
        $this->learner('100000000004', 'Lim, Paolo', [
            'med_asthma' => true,
            'med_diabetes' => true,
        ]);

        $html = $this->withSession($this->adviserSession([
            $this->rosterRow('100000000004', 'Paolo', 'Lim'),
        ]))->get(route('dashboard.class-adviser'))->assertOk()->getContent();

        $this->assertStringContainsString('Priority — Asthma, Diabetes', $html);
    }

    #[Test]
    public function the_priority_count_appears_on_the_dashboard(): void
    {
        $this->learner('100000000005', 'A, One', ['med_asthma' => true]);
        $this->learner('100000000006', 'B, Two', ['med_heart_condition' => true]);
        $this->learner('100000000007', 'C, Three', ['med_asthma' => false]);

        $html = $this->withSession($this->adviserSession([
            $this->rosterRow('100000000005', 'One', 'A'),
            $this->rosterRow('100000000006', 'Two', 'B'),
            $this->rosterRow('100000000007', 'Three', 'C'),
        ]))->get(route('dashboard.class-adviser'))->assertOk()->getContent();

        $this->assertStringContainsString('<b>2</b><span>Priority</span>', $html);
    }

    /** Priority is a medical flag; at-risk is an attendance flag. */
    #[Test]
    public function nutritional_at_risk_does_not_make_a_learner_priority(): void
    {
        $record = $this->learner('100000000008', 'Dela Cruz, Nena');
        $record->update(['is_at_risk' => true, 'nutritional_status' => 'Severely Wasted']);

        $html = $this->withSession($this->adviserSession([
            $this->rosterRow('100000000008', 'Nena', 'Dela Cruz'),
        ]))->get(route('dashboard.class-adviser'))->assertOk()->getContent();

        // Flagged at-risk, but not Priority.
        $this->assertStringContainsString('Flagged at-risk', $html);
        $this->assertStringContainsString('<b>0</b><span>Priority</span>', $html);
    }

    /** Correcting the assessment clears the flag — nothing is stored. */
    #[Test]
    public function the_flag_follows_a_corrected_assessment(): void
    {
        $record = $this->learner('100000000009', 'Tan, Rico', ['med_asthma' => true]);
        $session = $this->adviserSession([$this->rosterRow('100000000009', 'Rico', 'Tan')]);

        $this->assertStringContainsString(
            'Priority — Asthma',
            $this->withSession($session)->get(route('dashboard.class-adviser'))->getContent()
        );

        HealthAssessment::where('student_health_record_id', $record->id)->first()->update(['med_asthma' => false]);

        $this->assertStringNotContainsString(
            'Priority — Asthma',
            $this->withSession($session)->get(route('dashboard.class-adviser'))->getContent()
        );
    }

    /** Priority cases sort above paperwork-only cases in the panel. */
    #[Test]
    public function priority_learners_lead_the_needs_attention_panel(): void
    {
        // No assessment at all -> "Health profile not started" only.
        $this->learner('100000000010', 'Zulu, Paper');
        $this->learner('100000000011', 'Alpha, Medical', ['med_asthma' => true]);

        $html = $this->withSession($this->adviserSession([
            $this->rosterRow('100000000010', 'Paper', 'Zulu'),
            $this->rosterRow('100000000011', 'Medical', 'Alpha'),
        ]))->get(route('dashboard.class-adviser'))->assertOk()->getContent();

        $priorityAt = strpos($html, 'na-badge-priority');
        $paperAt = strpos($html, 'Health profile not started');

        $this->assertNotFalse($priorityAt);
        $this->assertNotFalse($paperAt);
        $this->assertLessThan($paperAt, $priorityAt, 'A Priority learner must sort above a paperwork-only one.');
    }

    /**
     * Priority renders as a badge pill in the same row as the others, and
     * the badge names the conditions — so the flag is never colour alone.
     */
    #[Test]
    public function priority_renders_as_a_badge_naming_the_conditions(): void
    {
        $this->learner('100000000012', 'Ong, Kim', ['med_tuberculosis' => true]);

        $html = $this->withSession($this->adviserSession([
            $this->rosterRow('100000000012', 'Kim', 'Ong'),
        ]))->get(route('dashboard.class-adviser'))->assertOk()->getContent();

        $this->assertStringContainsString(
            '<span class="na-badge na-badge-priority">Priority — Tuberculosis</span>',
            $html
        );
    }
}
