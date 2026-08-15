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
 * Ticking a chronic condition while editing a student profile flags them.
 *
 * There are two places a class adviser can record a chronic condition, and
 * both must reach the Priority Students table:
 *
 *  1. the Health Assessment form           -> health_assessments columns
 *  2. the student profile's Health History -> student_details['health_history']
 *
 * The second is the older form and names three fields differently
 * (med_seizure, med_heart). It used to be ignored entirely, so an adviser
 * could tick "Asthma" while editing a profile and see nothing happen.
 * PriorityFromMedicalHistoryTest covers route 1; this covers route 2 and the
 * overlap between them.
 */
class PriorityFromStudentProfileTest extends TestCase
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

    private function rosterRow(string $lrn = '300000000001'): array
    {
        return [
            'lrn' => $lrn,
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'grade_level' => 'Grade 10',
            'section' => 'Dalton',
        ];
    }

    /** A learner whose profile carries the given Health History checklist. */
    private function learnerWithHistory(array $history, string $lrn = '300000000001'): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'student_id' => $lrn,
            'student_name' => 'Cruz, Juan',
            'school_name' => 'Sta. Ana NHS',
            'grade_level' => 'Grade 10',
            'section' => 'Grade 10 / Dalton',
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'weight' => '40',
            'bmi_value' => '18',
            'nutritional_status' => 'Normal',
            'student_details' => ['health_history' => $history],
        ]);
    }

    private function dashboard(array $roster): string
    {
        return $this->withSession($this->adviserSession($roster))
            ->get(route('dashboard.class-adviser'))
            ->assertOk()
            ->getContent();
    }

    #[Test]
    public function ticking_asthma_on_the_student_profile_flags_the_learner(): void
    {
        $this->learnerWithHistory(['med_asthma' => '1']);

        $html = $this->dashboard([$this->rosterRow()]);

        $this->assertStringContainsString('Cruz, Juan', $html);
        $this->assertStringContainsString('<span class="ps-chip">Asthma</span>', $html);
        $this->assertStringContainsString('1 Priority</span>', $html);
    }

    /**
     * The profile form's own field names must all work.
     *
     * med_seizure and med_heart are the ones that differ from the Health
     * Assessment form; they are the reason this test enumerates every field
     * rather than spot-checking one.
     */
    #[Test]
    public function every_profile_field_name_raises_the_flag(): void
    {
        $expected = [
            'med_asthma' => 'Asthma',
            'med_diabetes' => 'Diabetes',
            'med_seizure' => 'Seizure disorder',
            'med_heart' => 'Heart condition',
            'med_tuberculosis' => 'Tuberculosis',
            'med_allergies' => 'Severe allergies',
        ];

        $this->assertSame($expected, PriorityHealthRule::profileConditions());

        foreach ($expected as $field => $label) {
            StudentHealthRecord::query()->delete();
            $this->learnerWithHistory([$field => '1']);

            $this->assertStringContainsString(
                '<span class="ps-chip">'.$label.'</span>',
                $this->dashboard([$this->rosterRow()]),
                "Ticking {$field} on the student profile must flag the learner."
            );
        }
    }

    /** Unticked answers must not flag anyone. */
    #[Test]
    public function an_unticked_checklist_does_not_flag_the_learner(): void
    {
        $this->learnerWithHistory([
            'med_asthma' => '0',
            'med_diabetes' => '',
            'med_heart' => false,
        ]);

        $html = $this->dashboard([$this->rosterRow()]);

        $this->assertStringContainsString('No learners are flagged as priority right now.', $html);
        $this->assertStringContainsString('0 Priority</span>', $html);
    }

    /**
     * The checklist round-trips through session and an encrypted JSON column,
     * so the same "yes" can arrive as a bool, an int or a string.
     */
    #[Test]
    public function a_ticked_box_counts_however_it_was_serialised(): void
    {
        foreach ([true, 1, '1', 'true', 'on'] as $index => $ticked) {
            StudentHealthRecord::query()->delete();
            $this->learnerWithHistory(['med_asthma' => $ticked]);

            $this->assertStringContainsString(
                '<span class="ps-chip">Asthma</span>',
                $this->dashboard([$this->rosterRow()]),
                'A ticked box serialised as '.var_export($ticked, true).' must count.'
            );
        }
    }

    /** Non-chronic profile answers stay out of the table. */
    #[Test]
    public function non_chronic_profile_answers_do_not_flag_the_learner(): void
    {
        $this->learnerWithHistory([
            'med_hospitalization' => '1',
            'med_infections' => '1',
            'fam_diabetes' => '1',
        ]);

        $this->assertStringContainsString(
            'No learners are flagged as priority right now.',
            $this->dashboard([$this->rosterRow()])
        );
    }

    /** Family history is about a relative, not the learner. */
    #[Test]
    public function family_history_never_flags_the_learner(): void
    {
        $this->learnerWithHistory([
            'fam_hypertension' => '1',
            'fam_heart' => '1',
            'fam_cancer' => '1',
        ]);

        $this->assertStringContainsString(
            'No learners are flagged as priority right now.',
            $this->dashboard([$this->rosterRow()])
        );
    }

    /** Recorded on both forms, the learner is listed once with one chip. */
    #[Test]
    public function a_condition_on_both_forms_is_not_duplicated(): void
    {
        $record = $this->learnerWithHistory(['med_asthma' => '1']);

        HealthAssessment::create([
            'student_health_record_id' => $record->id,
            'school_year' => HealthAssessment::currentSchoolYear(),
            'med_asthma' => true,
        ]);

        $html = $this->dashboard([$this->rosterRow()]);

        $this->assertSame(
            1,
            substr_count($html, '<span class="ps-chip">Asthma</span>'),
            'A condition recorded on both forms must yield one chip.'
        );
        $this->assertStringContainsString('1 Priority</span>', $html);
    }

    /** Different conditions from each form are both reported. */
    #[Test]
    public function conditions_from_both_forms_are_combined(): void
    {
        $record = $this->learnerWithHistory(['med_heart' => '1']);

        HealthAssessment::create([
            'student_health_record_id' => $record->id,
            'school_year' => HealthAssessment::currentSchoolYear(),
            'med_diabetes' => true,
        ]);

        $html = $this->dashboard([$this->rosterRow()]);

        $this->assertStringContainsString('<span class="ps-chip">Heart condition</span>', $html);
        $this->assertStringContainsString('<span class="ps-chip">Diabetes</span>', $html);
        // Still one learner.
        $this->assertStringContainsString('1 Priority</span>', $html);
    }

    /** A learner with no details blob at all must not error. */
    #[Test]
    public function a_learner_with_no_recorded_history_is_not_priority(): void
    {
        StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'student_id' => '300000000002',
            'student_name' => 'Reyes, Ana',
            'school_name' => 'Sta. Ana NHS',
            'grade_level' => 'Grade 10',
            'section' => 'Grade 10 / Dalton',
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'weight' => '40',
            'bmi_value' => '18',
            'nutritional_status' => 'Normal',
        ]);

        $this->assertStringContainsString(
            'No learners are flagged as priority right now.',
            $this->dashboard([$this->rosterRow('300000000002')])
        );
    }
}
