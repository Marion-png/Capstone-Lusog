<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Opened from a learner's profile, the New Consultation dialog fills the
 * grade and section from that learner's record and locks the field.
 *
 * The pair is already on the profile, and a consultation typed against a
 * section the record does not have is a consultation filed under the wrong
 * class — the clinic log matches a visit to a learner by name and section,
 * so a mistyped one silently drops out of the school head's grade figures.
 *
 * Read-only, never disabled: a disabled input posts nothing, so the section
 * would arrive empty and fail its own required rule.
 *
 * Opened cold from the Consultation Log there is no record to read, so the
 * nurse still types it — and the same dialog serves both, so the lock has
 * to clear between opens.
 */
class ConsultationSectionLockTest extends TestCase
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
        return $this->withSession($this->nurseSession())
            ->get(route('dashboard.student-health-records'))
            ->assertOk()
            ->getContent();
    }

    /** The dialog can lock the field, and knows which one. */
    #[Test]
    public function the_dialog_carries_a_lock_for_the_section_field(): void
    {
        $html = $this->recordsPage();

        $this->assertStringContainsString('id="cm_grade_section_field"', $html);
        $this->assertStringContainsString('const lockSection = (locked) =>', $html);
        $this->assertStringContainsString('sectionField.readOnly = locked;', $html);
    }

    /**
     * Disabled would be the obvious way to do it and is the wrong one: the
     * browser omits a disabled control from the submission, so the required
     * grade_section rule would reject a save the nurse never had a way to
     * satisfy.
     */
    #[Test]
    public function the_field_is_never_disabled(): void
    {
        $html = $this->recordsPage();

        $this->assertStringNotContainsString('sectionField.disabled', $html);
        $this->assertStringNotContainsString("sectionField.setAttribute('disabled'", $html);
    }

    /** Opening for a learner locks it. */
    #[Test]
    public function opening_for_a_learner_locks_the_field(): void
    {
        $html = $this->recordsPage();

        $this->assertStringContainsString(
            "lockSection(String(section || '').trim() !== '');",
            $html
        );
    }

    /**
     * A learner whose record carries no grade or section is still a learner
     * the nurse must be able to log a visit for, so the lock follows what
     * the record actually gave — including the panel's placeholder dash,
     * which is not a section.
     */
    #[Test]
    public function a_learner_with_no_section_on_file_is_still_typeable(): void
    {
        $html = $this->recordsPage();

        $this->assertStringContainsString("const section = shown === '-' ? '' : shown;", $html);
    }

    /** Opened cold, the field is the nurse's to type. */
    #[Test]
    public function the_consultation_log_dialog_opens_unlocked(): void
    {
        $html = $this->withSession($this->nurseSession())
            ->get(route('dashboard.consultation-log'))
            ->assertOk()
            ->getContent();

        // No lock is applied at rest — the input renders plain.
        $this->assertMatchesRegularExpression(
            '/<input id="cm_grade_section"(?![^>]*readonly)[^>]*>/',
            $html
        );

        // And a lock left over from a previous open is cleared.
        $this->assertStringContainsString('lockSection(false);', $html);
    }

    /**
     * The profile borrows the Consultation Log's own trigger to open the
     * dialog. Without the flag, that trigger's missing data-consult-section
     * would read as "opened cold" and unlock what was just filled in.
     */
    #[Test]
    public function borrowing_the_cold_trigger_does_not_clear_the_lock(): void
    {
        $html = $this->recordsPage();

        $this->assertStringContainsString('window.__consultPrefilled = true;', $html);
        $this->assertStringContainsString('} else if (!window.__consultPrefilled) {', $html);
    }

    /** A locked field says why, and where to change it. */
    #[Test]
    public function the_lock_explains_itself(): void
    {
        $html = $this->recordsPage();

        $this->assertStringContainsString("From the learner's record. Correct it on their profile.", $html);
        $this->assertStringContainsString('.bmodal-field.is-locked .bmodal-note', $html);
    }

    /** A read-only field still posts its value, so the save still works. */
    #[Test]
    public function a_consultation_saves_with_the_section_the_dialog_filled_in(): void
    {
        $this->learner();

        $this->withSession($this->nurseSession())
            ->post(route('consultations.store'), [
                'consulted_at' => now()->toDateString(),
                'student_name' => 'Cruz, Juan',
                'grade_section' => 'Grade 10 - Dalton',
                'condition' => 'Headache',
                'status' => 'treated',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('consultations', 1);

        $consultation = Consultation::first();
        $this->assertSame('Grade 10 - Dalton', $consultation->grade_section);
        $this->assertSame('Cruz, Juan', $consultation->student_name);
    }
}
