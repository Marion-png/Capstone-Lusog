<?php

namespace Tests\Feature;

use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\FeedingProgramCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Endline measurement on the adviser's student profile.
 *
 * Height and weight entered when a learner is added become the BASELINE.
 * Endline is the closing half of that pair, so it only opens once the
 * 120-day feeding programme has run its full length — a mid-cycle endline
 * would compare a learner against themselves halfway through and report it
 * as an outcome.
 *
 * The form disables itself, but the rule lives in the controller: these
 * tests check the server refuses an early endline even when the form is
 * bypassed.
 */
class AdviserEndlineMeasurementTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
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
            'school_health_card_records' => [[
                'lrn' => '700000000001',
                'first_name' => 'Juan',
                'last_name' => 'Cruz',
                'grade_level' => 'Grade 10',
                'section' => 'Dalton',
            ]],
        ];
    }

    private function learner(bool $withBaseline = true): StudentHealthRecord
    {
        return StudentHealthRecord::create(array_merge([
            'institution_id' => $this->school->id,
            'student_id' => '700000000001',
            'student_name' => 'Cruz, Juan',
            'school_name' => 'Sta. Ana NHS',
            'grade_level' => 'Grade 10',
            'section' => 'Grade 10 / Dalton',
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'weight' => '40',
            'bmi_value' => '20.41',
            'nutritional_status' => 'Normal',
        ], $withBaseline ? [
            'baseline_age' => 14,
            'baseline_height_cm' => '140',
            'baseline_weight_kg' => '40',
            'baseline_bmi_value' => '20.41',
            'baseline_nutritional_status' => 'Normal',
        ] : []));
    }

    /** Start the cycle far enough back that it has finished. */
    private function completeTheCycle(StudentHealthRecord $record): void
    {
        // The cycle is 120 *feeding* days, and a week holds five of them, so a
        // finished cycle is about 168 calendar days back — not 120. Derived from
        // the constant rather than typed, so the fixture cannot drift from it.
        $calendarDays = (int) ceil(FeedingProgramCycle::DURATION_DAYS / 5 * 7) + 7;

        FeedingAttendance::create([
            'institution_id' => $this->school->id,
            'student_health_record_id' => $record->id,
            'session_date' => now()->subDays($calendarDays)->toDateString(),
            'is_present' => true,
        ]);
    }

    private function startTheCycleRecently(StudentHealthRecord $record): void
    {
        FeedingAttendance::create([
            'institution_id' => $this->school->id,
            'student_health_record_id' => $record->id,
            'session_date' => now()->subDays(10)->toDateString(),
            'is_present' => true,
        ]);
    }

    private function profile(): string
    {
        return $this->withSession($this->adviserSession())
            ->get('/dashboard/class-adviser?tab=saved&edit=700000000001')
            ->assertOk()
            ->getContent();
    }

    private function postEndline(StudentHealthRecord $record): TestResponse
    {
        return $this->withSession($this->adviserSession())
            ->from('/dashboard/class-adviser?tab=saved&edit=700000000001')
            ->post(route('class-adviser.health-records.endline.store', $record), [
                'age' => 15,
                'height_cm' => 145,
                'weight_kg' => 46,
            ]);
    }

    #[Test]
    public function the_profile_shows_an_endline_section_with_the_baseline_beside_it(): void
    {
        $this->learner();

        $html = $this->profile();

        $this->assertStringContainsString('Endline Measurement', $html);
        $this->assertStringContainsString('id="endlineHeight"', $html);
        $this->assertStringContainsString('id="endlineWeight"', $html);
        $this->assertStringContainsString('id="endlineBmi"', $html);

        // The baseline it will be compared against is shown too.
        $this->assertStringContainsString('endline-baseline', $html);
        $this->assertStringContainsString('140 cm', $html);
        $this->assertStringContainsString('40 kg', $html);
    }

    /** BMI is worked out, never typed. */
    #[Test]
    public function endline_bmi_is_not_an_input(): void
    {
        $this->learner();

        $html = $this->profile();

        $this->assertStringNotContainsString('name="bmi_value"', $html);
        $this->assertStringContainsString('<div class="endline-bmi"', $html);
    }

    #[Test]
    public function the_form_is_disabled_before_the_cycle_finishes(): void
    {
        $record = $this->learner();
        $this->startTheCycleRecently($record);

        $html = $this->profile();

        $this->assertStringContainsString('Not yet open —', $html);
        $this->assertMatchesRegularExpression('/<fieldset class="endline-fieldset"[^>]*disabled/', $html);
    }

    #[Test]
    public function the_form_opens_once_the_cycle_is_complete(): void
    {
        $record = $this->learner();
        $this->completeTheCycle($record);

        $html = $this->profile();

        $this->assertStringNotContainsString('Not yet open —', $html);
        $this->assertDoesNotMatchRegularExpression('/<fieldset class="endline-fieldset"[^>]*disabled/', $html);
    }

    /** The rule is the server's, not the form's. */
    #[Test]
    public function an_early_endline_is_refused_even_if_the_form_is_bypassed(): void
    {
        $record = $this->learner();
        $this->startTheCycleRecently($record);

        $this->postEndline($record)->assertRedirect();

        $this->assertNull($record->fresh()->endline_bmi_value);
    }

    #[Test]
    public function an_endline_saves_once_the_cycle_is_complete(): void
    {
        $record = $this->learner();
        $this->completeTheCycle($record);

        $this->postEndline($record)->assertRedirect();

        $fresh = $record->fresh();

        $this->assertSame(15, (int) $fresh->endline_age);
        $this->assertEqualsWithDelta(145, (float) $fresh->endline_height_cm, 0.01);
        $this->assertEqualsWithDelta(46, (float) $fresh->endline_weight_kg, 0.01);
        // 46 / 1.45^2 = 21.88 — computed server-side.
        $this->assertEqualsWithDelta(21.88, (float) $fresh->endline_bmi_value, 0.05);
        $this->assertNotNull($fresh->endline_nutritional_status);
    }

    /** Endline compares against a baseline, so it needs one first. */
    #[Test]
    public function endline_is_refused_without_a_baseline(): void
    {
        $record = $this->learner(withBaseline: false);
        $this->completeTheCycle($record);

        $this->postEndline($record)->assertRedirect();

        $this->assertNull($record->fresh()->endline_bmi_value);
    }

    /** Another school's learner is never writable. */
    #[Test]
    public function another_schools_learner_cannot_be_measured(): void
    {
        $other = Institution::create(['name' => 'Wireless ES', 'status' => 'active']);

        $record = StudentHealthRecord::create([
            'institution_id' => $other->id,
            'student_id' => '700000000009',
            'student_name' => 'Other, Learner',
            'school_name' => 'Wireless ES',
            'grade_level' => 'Grade 7',
            'section' => 'Grade 7 / Rizal',
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'weight' => '35',
            'bmi_value' => '17',
            'nutritional_status' => 'Normal',
            'baseline_bmi_value' => '17',
        ]);

        $this->withSession($this->adviserSession())
            ->post(route('class-adviser.health-records.endline.store', $record), [
                'age' => 13, 'height_cm' => 140, 'weight_kg' => 38,
            ])->assertForbidden();
    }
}
