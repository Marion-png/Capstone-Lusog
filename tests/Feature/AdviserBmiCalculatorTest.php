<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The student form works BMI out from weight, height and date of birth as
 * the adviser types.
 *
 * The script that does this bails out unless every element it writes to is
 * present, and four of them were missing from the dashboard form — so the
 * calculator silently did nothing there for as long as it existed. The same
 * script also fills the hidden height_cm the server stores, which made those
 * elements load-bearing rather than decorative.
 *
 * These tests pin the wiring: the inputs it reads, the outputs it writes,
 * and the fact that nothing about BMI is typed by hand.
 */
class AdviserBmiCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function adviserSession(): array
    {
        $school = Institution::firstOrCreate(['name' => 'Sta. Ana NHS'], ['status' => 'active']);

        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Maria Santos',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $school->id,
            'assigned_grade_level' => 'Grade 10',
            'assigned_section' => 'Dalton',
        ];
    }

    private function dashboard(): string
    {
        return $this->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser'))
            ->assertOk()
            ->getContent();
    }

    private function addStudentPage(): string
    {
        return $this->withSession($this->adviserSession())
            ->get(route('adviser.create'))
            ->assertOk()
            ->getContent();
    }

    /**
     * Every element the dashboard calculator writes to must exist.
     *
     * Its guard returns early if any single one is missing, which is exactly
     * how it came to do nothing at all.
     */
    #[Test]
    public function the_dashboard_form_has_every_element_the_calculator_needs(): void
    {
        $html = $this->dashboard();

        // Inputs it reads.
        foreach (['id="weight"', 'id="height"', 'id="birthDate"', 'id="proto_height_cm"'] as $input) {
            $this->assertStringContainsString($input, $html, "The calculator reads {$input}.");
        }

        // Outputs it writes — the four that were missing.
        foreach (['id="heightSquared"', 'id="bmiDisplay"', 'id="nutriStatusDisplay"', 'id="hfaDisplay"'] as $output) {
            $this->assertStringContainsString($output, $html, "The calculator writes to {$output}; without it the whole script bails.");
        }
    }

    #[Test]
    public function the_dashboard_form_computes_bmi_rather_than_asking_for_it(): void
    {
        $html = $this->dashboard();

        // The formula is present…
        $this->assertStringContainsString('weightKg / heightSquared', $html);
        // …and recalculates as the adviser types.
        $this->assertStringContainsString("heightInput.addEventListener('input', recalc)", $html);
        $this->assertStringContainsString("weightInput.addEventListener('input', recalc)", $html);

        // There is no editable BMI field to type into.
        $this->assertStringNotContainsString('name="bmi_value"', $html);
    }

    /** The same script fills the height the server actually stores. */
    #[Test]
    public function the_calculator_fills_the_hidden_height_in_centimetres(): void
    {
        $html = $this->dashboard();

        $this->assertStringContainsString('heightCmHidden.value = heightCm.toFixed(2)', $html);
        $this->assertStringContainsString('name="height_cm" type="hidden"', $html);
    }

    /** The standalone Add Student page has its own working calculator. */
    #[Test]
    public function the_add_student_page_also_computes_bmi_live(): void
    {
        $html = $this->addStudentPage();

        $this->assertStringContainsString('id="proto_bmi_value"', $html);
        $this->assertStringContainsString('id="proto_nutritional_status_bmi_for_age"', $html);
        $this->assertStringContainsString('weightKg / Math.pow(heightCm / 100, 2)', $html);

        // Readonly, so the figure cannot be overtyped.
        $this->assertMatchesRegularExpression('/id="proto_bmi_value"[^>]*readonly/', $html);
    }

    /**
     * BMI is stored from the server's own calculation.
     *
     * The live figure is a preview; AdviserController recomputes it on submit,
     * so a tampered form field could never become the stored value.
     */
    #[Test]
    public function the_stored_bmi_is_computed_server_side(): void
    {
        $this->withSession($this->adviserSession())
            ->post(route('adviser.store'), [
                'last_name' => 'Cruz',
                'first_name' => 'Juan',
                'lrn' => '400000000001',
                'birth_month' => 5,
                'birth_day' => 12,
                'birth_year' => 2012,
                'birthplace' => 'Davao City',
                'parent_guardian' => 'Ana Cruz',
                'address' => '123 Sampaguita St.',
                'telephone_no' => '09171234567',
                'grade_level' => 'Grade 10',
                'section' => 'Dalton',
                'weight_kg' => 40,
                // Metres only, as the dashboard form posts: the controller
                // derives height_cm from it.
                'height_m' => 1.4,
                // A deliberately wrong BMI, as a tampered form would send.
                'bmi_value' => 999,
            ])->assertRedirect();

        $record = StudentHealthRecord::where('student_id', '400000000001')->first();

        $this->assertNotNull($record, 'The learner should have been stored.');
        // 40 / 1.4^2 = 20.41, not 999.
        $this->assertEqualsWithDelta(20.41, (float) $record->bmi_value, 0.05);
    }
}
