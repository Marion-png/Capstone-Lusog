<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\MeasurementPlausibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A height and weight no learner could have is refused at entry.
 *
 * A tester saved a learner at 187 cm with a BMI of 2.52, and the app filed
 * them Severely Wasted — a status that qualifies a child for the feeding
 * programme and is counted on the DepEd grid. The limits are WHO's own flags
 * for biologically implausible readings (BMI-for-age beyond ±5 SD,
 * height-for-age beyond ±6 SD), so a real severely wasted child is never
 * refused.
 */
class MeasurementPlausibilityTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now('Asia/Manila')->setDate(2026, 11, 9)->setTime(10, 0));
        $this->school = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    private function adviserSession(): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Test Adviser',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->school->id,
            'assigned_grade_level' => 'Grade 8',
            'assigned_section' => 'Rizal',
        ];
    }

    private function enrol(array $overrides = [])
    {
        return $this->withSession($this->adviserSession())
            ->from(route('dashboard.class-adviser'))
            ->post(route('adviser.store'), array_merge([
                'last_name' => 'Testcase',
                'first_name' => 'Rafael',
                'lrn' => '400000000201',
                'birth_month' => 3,
                'birth_day' => 14,
                'birth_year' => 2012,
                'gender' => 'Male',
                'birthplace' => 'Davao City',
                'parent_guardian' => 'Parent Testcase',
                'address' => '1 Example St.',
                'telephone_no' => '09170000000',
                'grade_level' => 'Grade 8',
                'section' => 'Rizal',
                'weight_kg' => 38,
                'height_m' => 1.52,
            ], $overrides));
    }

    #[Test]
    public function the_testers_impossible_reading_is_refused(): void
    {
        // 187 cm and 8.8 kg: a BMI of 2.5, which no learner can have.
        $problems = MeasurementPlausibility::problems(187, 8.8, 14 * 12 + 7, 'Male');

        $this->assertArrayHasKey('weight_kg', $problems);
        $this->assertStringContainsString('BMI of 2.5', $problems['weight_kg']);

        $this->enrol(['weight_kg' => 8.8, 'height_m' => 1.87])
            ->assertRedirect(route('dashboard.class-adviser'))
            ->assertSessionHasErrors('weight_kg');

        $this->assertSame(0, StudentHealthRecord::count(), 'Nothing is written for a reading nobody could have.');
    }

    #[Test]
    public function a_real_severely_wasted_learner_is_never_refused(): void
    {
        // BMI 12.6 at 14 years: about −4 SD, Severely Wasted, and real.
        $this->assertSame([], MeasurementPlausibility::problems(152, 29.2, 14 * 12 + 7, 'Male'));

        // The tester's four learners all save.
        $this->assertSame([], MeasurementPlausibility::problems(152, 38, 175.8, 'Male'));
        $this->assertSame([], MeasurementPlausibility::problems(158, 38, 186.0, 'Male'));
        $this->assertSame([], MeasurementPlausibility::problems(148, 52, 153.6, 'Female'));
        $this->assertSame([], MeasurementPlausibility::problems(145, 38, 170.1, 'Male'));

        $this->enrol(['weight_kg' => 29.2])->assertSessionHasNoErrors();
        $this->assertSame(1, StudentHealthRecord::count());
    }

    #[Test]
    public function an_impossible_height_for_the_age_is_refused(): void
    {
        // 90 cm at 14 years is beyond −6 SD on the boys' height-for-age table.
        $problems = MeasurementPlausibility::problems(90, 13, 14 * 12, 'Male');

        $this->assertArrayHasKey('height_cm', $problems);
        $this->assertStringContainsString('14 years', $problems['height_cm']);
    }

    #[Test]
    public function without_sex_or_age_only_a_bmi_no_child_could_have_is_refused(): void
    {
        $this->assertArrayHasKey('weight_kg', MeasurementPlausibility::problems(187, 8.8, null, null));
        $this->assertSame([], MeasurementPlausibility::problems(150, 40, null, null));
        $this->assertSame([], MeasurementPlausibility::problems(null, null, 170.0, 'Male'), 'An unmeasured learner has nothing to check.');
    }

    #[Test]
    public function the_baseline_endpoint_refuses_it_too(): void
    {
        $this->withSession($this->adviserSession())
            ->from(route('dashboard.class-adviser'))
            ->post(route('class-adviser.health-records.baseline.store'), [
                'student_name' => 'Testcase, Rafael',
                'student_id' => '400000000202',
                'section' => 'Grade 8 / Rizal',
                'age' => 14,
                'height_cm' => 187,
                'weight_kg' => 8.8,
            ])
            ->assertSessionHasErrors('weight_kg');

        $this->assertSame(0, StudentHealthRecord::count());
    }

    #[Test]
    public function the_forms_check_before_saving_with_the_same_limits(): void
    {
        $html = $this->withSession($this->adviserSession())->get(route('dashboard.class-adviser'))->assertOk()->getContent();

        $this->assertStringContainsString('window.WhoGrowth.problems(', $html);
        $this->assertStringContainsString('setCustomValidity(found.weight)', $html);
        $this->assertStringContainsString('"bmiZ":'.json_encode(MeasurementPlausibility::BMI_Z_LIMIT), $html);
    }
}
