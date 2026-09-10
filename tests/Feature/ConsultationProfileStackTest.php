<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The consultation dialog stacks over the learner's profile; it does not
 * replace it.
 *
 * A nurse searches for a learner, opens their profile and logs a visit from
 * it. Closing the dialog has to return them to that learner — the one they
 * deliberately opened — and only the profile's own Back button leaves it. The
 * dialog used to close the profile first, so dismissing it dropped the nurse
 * back on the list with the learner gone and nothing to say why.
 *
 * Stacking is not free, and both costs are paid here rather than by closing
 * the panel underneath:
 *
 *   - `.profile-backdrop` is z-index 1400 and `.bmodal` is 900, so an
 *     unstacked dialog opens *behind* the profile and is invisible.
 *   - Two scrims dim one page twice, and a profile behind two of them reads
 *     as dismissed.
 *
 * One body class answers both, and Escape has to belong to the topmost dialog
 * or one press closes both.
 */
class ConsultationProfileStackTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function nurseSession(): array
    {
        return [
            'active_role' => 'school_nurse',
            'active_name' => 'Nurse Cruz',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
        ];
    }

    private function learner(): void
    {
        StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'student_id' => '500000000001',
            'student_name' => 'Cruz, Juan',
            'school_name' => 'Sta. Ana NHS',
            'grade_level' => 'Grade 10',
            'section' => 'Grade 10 / Dalton',
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'weight' => '40',
            'bmi_value' => '18',
            'nutritional_status' => 'Normal',
        ]);
    }

    private function recordsPage(): string
    {
        $this->learner();

        return $this->withSession($this->nurseSession())
            ->get(route('dashboard.student-health-records'))
            ->assertOk()
            ->getContent();
    }

    /**
     * The whole point: opening the dialog must not dismiss the profile the
     * nurse chose. This is the line that used to do it.
     */
    #[Test]
    public function opening_the_consultation_dialog_does_not_close_the_profile(): void
    {
        $html = $this->recordsPage();

        // The consult handler stacks rather than closing.
        $this->assertStringContainsString('setStacked(true);', $html);

        // Every surviving closeProfile() call belongs to a control that is
        // meant to leave the profile — Back, the backdrop, Escape — and none
        // of them is the New Consultation button.
        $consultHandler = $this->between(
            $html,
            "consultLink.addEventListener('click'",
            '});'
        );

        $this->assertNotSame('', $consultHandler, 'The New Consultation handler must render.');
        $this->assertStringNotContainsString(
            'closeProfile()',
            $consultHandler,
            'New Consultation must leave the profile open behind the dialog.'
        );
    }

    /** Back still closes it — that is the one control that should. */
    #[Test]
    public function the_back_button_still_closes_the_profile(): void
    {
        $html = $this->recordsPage();

        $this->assertStringContainsString('id="profileClose"', $html);
        $this->assertStringContainsString("closeBtn.addEventListener('click', closeProfile);", $html);
    }

    /**
     * Escape belongs to the topmost dialog. Both handlers listen on document,
     * so without this guard one press dismisses the dialog and the profile
     * under it together.
     */
    #[Test]
    public function escape_closes_only_the_dialog_while_it_is_stacked(): void
    {
        $html = $this->recordsPage();

        $this->assertStringContainsString('const consultIsOpen = () =>', $html);
        $this->assertStringContainsString('if (consultIsOpen()) return;', $html);
    }

    /** A click on the dialog's backdrop must not reach the profile's. */
    #[Test]
    public function a_backdrop_click_does_not_close_the_profile_under_the_dialog(): void
    {
        $this->assertStringContainsString(
            'if (event.target === backdrop && !consultIsOpen()) {',
            $this->recordsPage()
        );
    }

    /**
     * Closing the dialog by any route unstacks. The shared dialog controller
     * announces none of its three close paths, so the class it toggles is what
     * gets watched.
     */
    #[Test]
    public function closing_the_dialog_unstacks_and_returns_focus_to_the_profile(): void
    {
        $html = $this->recordsPage();

        $this->assertStringContainsString('new MutationObserver(', $html);
        $this->assertStringContainsString("attributeFilter: ['class']", $html);
        $this->assertStringContainsString('setStacked(false);', $html);
        $this->assertStringContainsString('consultLink.focus();', $html);
    }

    /**
     * The dialog is z-index 900 and the profile backdrop 1400, so the stack
     * only reads if the dialog is lifted over it. Without this the nurse
     * presses New Consultation and nothing appears to happen.
     */
    #[Test]
    public function the_stacked_dialog_is_lifted_above_the_profile_backdrop(): void
    {
        $html = $this->recordsPage();

        $this->assertStringContainsString('body.sn-profile-stacked .bmodal { z-index: 1500; }', $html);
        $this->assertStringContainsString(
            'body.sn-profile-stacked .profile-backdrop.open { background: transparent; }',
            $html
        );
    }

    /**
     * The learner the nurse opened is still the learner the dialog files
     * against — the lock this stack was built around stays in place.
     */
    #[Test]
    public function the_learner_is_still_filled_in_and_locked(): void
    {
        $html = $this->recordsPage();

        $this->assertStringContainsString('window.openConsultationFor(name, section);', $html);
        $this->assertStringContainsString('const lockLearner = (name, section) =>', $html);
        $this->assertStringContainsString('field.readOnly = locked;', $html);
    }

    /** The slice of a script between a marker and the next `$end`. */
    private function between(string $haystack, string $start, string $end): string
    {
        $from = strpos($haystack, $start);

        if ($from === false) {
            return '';
        }

        $to = strpos($haystack, $end, $from);

        return $to === false
            ? substr($haystack, $from)
            : substr($haystack, $from, $to - $from);
    }
}
