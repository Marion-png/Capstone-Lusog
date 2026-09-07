<?php

namespace Tests\Feature;

use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The adviser reviews the baseline before it is submitted.
 *
 * Confirmed requirement: an adviser reads back what they typed and confirms
 * it, rather than submitting blind. The reason is that this one save decides
 * a great deal downstream — the height and weight entered here become the
 * learner's BMI, their nutritional status, and therefore whether they qualify
 * for the feeding programme. A mistyped weight is not a typo, it is a child
 * on or off a feeding list.
 *
 * The flow is: Review & Submit → validate → summary dialog → Edit, or
 * Confirm & Submit. Nothing posts until the confirm.
 *
 * These tests exist because the step is easy to lose. It is entirely
 * client-side, so removing it breaks no server assertion — wiring the review
 * button straight to the form would silently delete the requirement.
 */
class AdviserReviewBeforeSubmitTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function form(): string
    {
        return $this->withSession([
            'active_role' => 'class_adviser',
            'active_name' => 'Maria Santos',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
            'assigned_grade_level' => 'Grade 10',
            'assigned_section' => 'Dalton',
            'school_health_card_records' => [],
        ])->get(route('dashboard.class-adviser', ['tab' => 'form']))
            ->assertOk()
            ->getContent();
    }

    #[Test]
    public function the_form_offers_review_rather_than_a_bare_submit(): void
    {
        $html = $this->form();

        $this->assertStringContainsString('id="reviewSubmitBtn"', $html);
        $this->assertStringContainsString('Review &amp; Submit', $html);
    }

    #[Test]
    public function a_confirmation_dialog_reads_the_entry_back(): void
    {
        $html = $this->form();

        $this->assertStringContainsString('id="confirmationModal"', $html);
        $this->assertStringContainsString('id="summaryContainer"', $html);
        $this->assertStringContainsString('Please review the information before submitting.', $html);
    }

    /** Two ways out of the dialog: go back and fix it, or commit. */
    #[Test]
    public function the_dialog_offers_both_edit_and_confirm(): void
    {
        $html = $this->form();

        $this->assertStringContainsString('id="confirmEditBtn"', $html);
        $this->assertStringContainsString('id="confirmSubmitBtn"', $html);
        $this->assertStringContainsString('Confirm &amp; Submit', $html);
    }

    /**
     * The review button must not post. It validates, builds the summary and
     * opens the dialog; only Confirm & Submit calls requestSubmit().
     *
     * This is the assertion that actually protects the requirement — wiring
     * the review button straight to the form is the change that would quietly
     * remove the step.
     */
    #[Test]
    public function only_the_confirm_button_submits_the_form(): void
    {
        $html = $this->form();

        $this->assertStringContainsString(
            "submitBtn.addEventListener('click', () => {",
            $html
        );
        $this->assertStringContainsString('form.requestSubmit();', $html);

        // Exactly one submit call in the whole page.
        $this->assertSame(1, substr_count($html, 'form.requestSubmit()'));

        // And the review handler opens the dialog instead of submitting.
        $this->assertStringContainsString('buildSummary();', $html);
        $this->assertStringContainsString('openModal();', $html);
    }

    /**
     * The summary reads back the figures that decide the learner's
     * nutritional status, not just their name — those are the ones worth
     * checking twice.
     */
    #[Test]
    public function the_summary_reads_back_the_baseline_measurements(): void
    {
        $html = $this->form();

        foreach (['Measurements', 'Weight', 'Height', 'BMI', 'Nutritional Status'] as $row) {
            $this->assertStringContainsString($row, $html, "The summary must read back $row.");
        }
    }

    /**
     * Vital signs are no longer on this form, so they must not be read back
     * as though the adviser had entered them.
     */
    #[Test]
    public function the_summary_does_not_read_back_the_nurses_fields(): void
    {
        $html = $this->form();

        $this->assertStringNotContainsString("title: 'Vital Signs'", $html);
        $this->assertStringNotContainsString("byId('temperature')", $html);
        $this->assertStringNotContainsString("byId('bloodPressure')", $html);
    }

    /**
     * A required field on the hidden sheet would otherwise fail validation
     * with no visible message — the browser cannot focus a control it is not
     * showing. The handler reveals that sheet first.
     */
    #[Test]
    public function validation_reveals_the_sheet_holding_the_first_invalid_field(): void
    {
        $html = $this->form();

        $this->assertStringContainsString("form.querySelector(':invalid')", $html);
        $this->assertStringContainsString('window.showAdviserSheet?.(invalidSheet.id);', $html);
        $this->assertStringContainsString('if (!form.reportValidity()) {', $html);
    }
}
