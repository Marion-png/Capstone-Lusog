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
 * "Priority" and listed in the Class Adviser's Priority Students table.
 *
 * The flag is derived from the adviser's own health assessment on every
 * read — never stored, never toggled by hand. Correcting the assessment
 * corrects the table, so there is no second copy of the truth to drift.
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

    /** A learner on this adviser's roster, with an optional assessment. */
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

    private function dashboard(array $roster): string
    {
        return $this->withSession($this->adviserSession($roster))
            ->get(route('dashboard.class-adviser'))
            ->assertOk()
            ->getContent();
    }

    /**
     * Just the Priority Students panel, so assertions cannot match elsewhere.
     *
     * Anchored on the heading markup, not the words: the page also inlines a
     * stylesheet whose comment reads "Priority Students table", and a plain
     * search finds that first.
     */
    private function priorityTable(string $html): string
    {
        $start = strpos($html, 'Priority Students</h3>');
        $this->assertNotFalse($start, 'The Priority Students panel is missing.');

        $end = strpos($html, 'Recent Activity</h3>', $start);

        return substr($html, $start, $end === false ? null : $end - $start);
    }

    #[Test]
    public function asthma_alone_makes_a_learner_priority(): void
    {
        $this->learner('100000000001', 'Cruz, Juan', ['med_asthma' => true]);

        $html = $this->dashboard([$this->rosterRow('100000000001', 'Juan', 'Cruz')]);

        $this->assertStringContainsString('Priority Students', $html);
        $this->assertStringContainsString('Cruz, Juan', $html);
        $this->assertStringContainsString('<span class="ps-chip">Asthma</span>', $html);
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

        $html = $this->dashboard([$this->rosterRow('100000000002', 'Ana', 'Reyes')]);

        $this->assertStringContainsString('0 Priority</span>', $html, 'The hero strip must show the priority count.');
        $this->assertStringContainsString('No learners are flagged as priority right now.', $html);
    }

    /** An unknown health profile is not the same as a clear one. */
    #[Test]
    public function a_learner_with_no_assessment_at_all_is_not_priority(): void
    {
        $this->learner('100000000003', 'Santos, Mia');

        $html = $this->dashboard([$this->rosterRow('100000000003', 'Mia', 'Santos')]);

        $this->assertStringContainsString('0 Priority</span>', $html, 'The hero strip must show the priority count.');
        $this->assertFalse(PriorityHealthRule::applies(null));
    }

    #[Test]
    public function every_condition_gets_its_own_chip_in_the_table(): void
    {
        $this->learner('100000000004', 'Lim, Paolo', [
            'med_asthma' => true,
            'med_diabetes' => true,
        ]);

        $html = $this->dashboard([$this->rosterRow('100000000004', 'Paolo', 'Lim')]);

        $this->assertStringContainsString('<span class="ps-chip">Asthma</span>', $html);
        $this->assertStringContainsString('<span class="ps-chip">Diabetes</span>', $html);
    }

    #[Test]
    public function the_priority_count_appears_on_the_dashboard(): void
    {
        $this->learner('100000000005', 'A, One', ['med_asthma' => true]);
        $this->learner('100000000006', 'B, Two', ['med_heart_condition' => true]);
        $this->learner('100000000007', 'C, Three', ['med_asthma' => false]);

        $html = $this->dashboard([
            $this->rosterRow('100000000005', 'One', 'A'),
            $this->rosterRow('100000000006', 'Two', 'B'),
            $this->rosterRow('100000000007', 'Three', 'C'),
        ]);

        $this->assertStringContainsString('2 Priority</span>', $html, 'The hero strip must show the priority count.');
    }

    /** Priority is a medical flag; at-risk is an attendance flag. */
    #[Test]
    public function nutritional_at_risk_does_not_make_a_learner_priority(): void
    {
        $record = $this->learner('100000000008', 'Dela Cruz, Nena');
        $record->update(['is_at_risk' => true, 'nutritional_status' => 'Severely Wasted']);

        $html = $this->dashboard([$this->rosterRow('100000000008', 'Nena', 'Dela Cruz')]);

        // At-risk by attendance counts toward follow-up but never puts a
        // learner in the Priority table.
        $this->assertStringContainsString('0 Priority</span>', $html, 'The hero strip must show the priority count.');
        $this->assertStringContainsString('No learners are flagged as priority right now.', $html);
    }

    /** Correcting the assessment clears the flag — nothing is stored. */
    #[Test]
    public function the_flag_follows_a_corrected_assessment(): void
    {
        $record = $this->learner('100000000009', 'Tan, Rico', ['med_asthma' => true]);
        $roster = [$this->rosterRow('100000000009', 'Rico', 'Tan')];

        $this->assertStringContainsString('<span class="ps-chip">Asthma</span>', $this->dashboard($roster));

        HealthAssessment::where('student_health_record_id', $record->id)->first()->update(['med_asthma' => false]);

        $this->assertStringContainsString(
            'No learners are flagged as priority right now.',
            $this->dashboard($roster)
        );
    }

    /** The table lists priority learners only — nobody else. */
    #[Test]
    public function only_priority_learners_appear_in_the_table(): void
    {
        // No assessment at all: needs paperwork, but is not a medical priority.
        $this->learner('100000000010', 'Zulu, Paper');
        $this->learner('100000000011', 'Alpha, Medical', ['med_asthma' => true]);

        $table = $this->priorityTable($this->dashboard([
            $this->rosterRow('100000000010', 'Paper', 'Zulu'),
            $this->rosterRow('100000000011', 'Medical', 'Alpha'),
        ]));

        $this->assertStringContainsString('Alpha, Medical', $table);
        $this->assertStringNotContainsString('Zulu, Paper', $table);
    }

    /** A learner with more conditions leads the table. */
    #[Test]
    public function the_most_affected_learner_is_listed_first(): void
    {
        $this->learner('100000000013', 'One, Single', ['med_asthma' => true]);
        $this->learner('100000000014', 'Many, Multiple', [
            'med_asthma' => true,
            'med_diabetes' => true,
            'med_heart_condition' => true,
        ]);

        $table = $this->priorityTable($this->dashboard([
            $this->rosterRow('100000000013', 'Single', 'One'),
            $this->rosterRow('100000000014', 'Multiple', 'Many'),
        ]));

        $this->assertLessThan(
            strpos($table, 'One, Single'),
            strpos($table, 'Many, Multiple'),
            'The learner with more conditions must be listed first.'
        );
    }

    /**
     * The table names each condition and the learner's LRN and section, so
     * it is readable without colour and identifies who is meant.
     */
    #[Test]
    public function the_table_names_the_condition_lrn_and_section(): void
    {
        $this->learner('100000000012', 'Ong, Kim', ['med_tuberculosis' => true]);

        $table = $this->priorityTable($this->dashboard([
            $this->rosterRow('100000000012', 'Kim', 'Ong'),
        ]));

        $this->assertStringContainsString('<span class="ps-chip">Tuberculosis</span>', $table);
        $this->assertStringContainsString('LRN 100000000012', $table);
        $this->assertStringContainsString('Grade 10-Dalton', $table);
    }
}
