<?php

namespace Tests\Feature;

use App\Models\HealthConsentForm;
use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Recording a consent that came back on paper.
 *
 * Most consents arrive through the parent's own link, signed on a screen. Some
 * arrive the way they always have — in ink, in a child's bag — and the consent
 * half of the document is editable only through that link, so a paper form had
 * no way into the system at all.
 *
 * The rule the whole screen exists to keep: **the reader proposes, the adviser
 * records**. The scan endpoint writes nothing, this endpoint reads its values
 * from the submitted form rather than from any scan, and it refuses to save
 * without the photograph and without the adviser saying the answers match the
 * paper. A parent's consent authorises medical procedures on a child.
 */
class ConsentPaperFormTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function adviserSession(?Institution $school = null): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Maria Santos',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => ($school ?? $this->school)->id,
            'assigned_grade_level' => 'Grade 10',
            'assigned_section' => 'Dalton',
        ];
    }

    private function form(string $status = HealthConsentForm::STATUS_SENT, ?Institution $school = null, string $lrn = '130000000001'): HealthConsentForm
    {
        return HealthConsentForm::create([
            'token' => str()->uuid()->toString(),
            'school_year' => '2026-2027',
            'institution_id' => ($school ?? $this->school)->id,
            'division' => 'Davao City',
            'school_name' => 'Sta. Ana NHS',
            'school_address' => 'Davao City',
            'student_address' => 'Davao City',
            'student_lrn' => $lrn,
            'student_name' => 'Cruz, Juan',
            'grade_level' => 'Grade 10',
            'section' => 'Dalton',
            'parent_guardian_name' => 'Maria Cruz',
            'services' => ['checkup'],
            'status' => $status,
        ]);
    }

    /** Explicit mime rather than fake()->image(): the latter needs GD. */
    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->create('consent.jpg', 120, 'image/jpeg');
    }

    /** @param  array<string, mixed>  $overrides */
    private function answers(array $overrides = []): array
    {
        return array_merge([
            'consent_choice' => HealthConsentForm::CONSENT_SPECIFIC,
            'consent_exceptions' => 'Dili ang bakuna',
            'allergy_food' => 'Lamang dagat',
            'parent_guardian_name' => 'Maria Cruz',
            'paper_form' => $this->photo(),
            'confirmed' => '1',
        ], $overrides);
    }

    // ── The review screen ────────────────────────────────────────────

    #[Test]
    public function the_adviser_is_offered_the_paper_form_screen_before_anything_saves(): void
    {
        $form = $this->form();

        $html = $this->withSession($this->adviserSession())
            ->get(route('consent-forms.paper', $form))
            ->assertOk()
            ->getContent();

        // The photograph, the answers, and the attestation — in that order.
        $this->assertStringContainsString('Record the Signed Paper Form', $html);
        $this->assertStringContainsString('name="paper_form"', $html);
        $this->assertStringContainsString('name="consent_choice"', $html);
        $this->assertStringContainsString('name="confirmed"', $html);
        $this->assertStringContainsString('I have read the paper form and these are the answers on it.', $html);

        // Every handwritten blank on the paper has a field to hold it.
        foreach (['consent_exceptions', 'refusal_reason', 'allergy_food', 'allergy_medicine', 'prev_immunization', 'other_illness'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html);
        }
    }

    /** The entry point is on the form itself, and only while it is open. */
    #[Test]
    public function the_button_is_offered_until_the_form_carries_an_answer(): void
    {
        $open = $this->form();
        $html = $this->withSession($this->adviserSession())->get(route('consent-forms.show', $open))->assertOk()->getContent();
        $this->assertStringContainsString(route('consent-forms.paper', $open), $html);

        $signed = $this->form(HealthConsentForm::STATUS_SIGNED, null, '130000000002');
        $html = $this->withSession($this->adviserSession())->get(route('consent-forms.show', $signed))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('consent-forms.paper', $signed), $html);
    }

    // ── The write ────────────────────────────────────────────────────

    #[Test]
    public function a_confirmed_paper_form_is_recorded_with_its_photograph(): void
    {
        $form = $this->form();

        $this->withSession($this->adviserSession())
            ->post(route('consent-forms.paper.store', $form), $this->answers())
            ->assertRedirect(route('consent-forms.show', $form));

        $form->refresh();

        $this->assertSame(HealthConsentForm::STATUS_SIGNED, $form->status);
        $this->assertSame(HealthConsentForm::CONSENT_SPECIFIC, $form->consent_choice);
        $this->assertSame('Dili ang bakuna', $form->consent_exceptions);
        $this->assertSame('Lamang dagat', $form->allergy_food);
        $this->assertNotNull($form->signed_at);

        // The photograph is the evidence: there is no drawn signature on a
        // paper consent, so the sheet has to be retrievable from the record.
        $this->assertNotEmpty($form->paper_form_path);
        Storage::disk('local')->assertExists($form->paper_form_path);

        // And the record says it was transcribed, not signed on a screen.
        $actions = array_column($form->audit ?? [], 'action');
        $this->assertContains('Recorded from the signed paper form', $actions);
    }

    /** A blank the choice does not call for is not carried over. */
    #[Test]
    public function only_the_blank_belonging_to_the_choice_is_kept(): void
    {
        $form = $this->form();

        $this->withSession($this->adviserSession())
            ->post(route('consent-forms.paper.store', $form), $this->answers([
                'consent_choice' => HealthConsentForm::CONSENT_ALL,
                'consent_exceptions' => 'typed then unticked',
            ]))
            ->assertRedirect();

        $this->assertNull($form->refresh()->consent_exceptions);
    }

    // ── What it refuses ──────────────────────────────────────────────

    /**
     * The attestation is the whole safety mechanism: without it there is
     * nothing separating a machine's reading of a photograph from a parent's
     * consent to a medical procedure.
     */
    #[Test]
    public function it_refuses_to_save_without_the_advisers_confirmation(): void
    {
        $form = $this->form();

        $this->withSession($this->adviserSession())
            ->post(route('consent-forms.paper.store', $form), $this->answers(['confirmed' => null]))
            ->assertSessionHasErrors('confirmed');

        $this->assertSame(HealthConsentForm::STATUS_SENT, $form->refresh()->status);
    }

    #[Test]
    public function it_refuses_to_save_without_the_photograph(): void
    {
        $form = $this->form();

        $this->withSession($this->adviserSession())
            ->post(route('consent-forms.paper.store', $form), $this->answers(['paper_form' => null]))
            ->assertSessionHasErrors('paper_form');

        $this->assertSame(HealthConsentForm::STATUS_SENT, $form->refresh()->status);
    }

    /** The blanks the choice requires are required. */
    #[Test]
    public function a_refusal_must_carry_the_reason_the_parent_gave(): void
    {
        $form = $this->form();

        $this->withSession($this->adviserSession())
            ->post(route('consent-forms.paper.store', $form), $this->answers([
                'consent_choice' => HealthConsentForm::CONSENT_DENY,
                'consent_exceptions' => null,
                'refusal_reason' => null,
            ]))
            ->assertSessionHasErrors('refusal_reason');
    }

    /**
     * A form that already carries the parent's answers is not re-recorded: a
     * second reading would overwrite the first with nobody told which one the
     * parent actually signed.
     */
    #[Test]
    public function a_form_already_answered_cannot_be_recorded_again(): void
    {
        $form = $this->form(HealthConsentForm::STATUS_SIGNED);

        $this->withSession($this->adviserSession())
            ->get(route('consent-forms.paper', $form))
            ->assertRedirect(route('consent-forms.show', $form));

        $this->withSession($this->adviserSession())
            ->post(route('consent-forms.paper.store', $form), $this->answers(['consent_choice' => HealthConsentForm::CONSENT_DENY, 'refusal_reason' => 'x']))
            ->assertRedirect(route('consent-forms.show', $form));

        $this->assertNull($form->refresh()->consent_choice);
    }

    // ── Who may reach it ─────────────────────────────────────────────

    #[Test]
    public function only_the_class_adviser_records_a_paper_form(): void
    {
        $form = $this->form();

        foreach (['school_nurse', 'feeding_coor'] as $role) {
            $this->withSession(array_merge($this->adviserSession(), ['active_role' => $role]))
                ->post(route('consent-forms.paper.store', $form), $this->answers())
                ->assertRedirect(route('login'));
        }

        // The School Head is refused a step earlier, by RestrictSchoolHeadWrites:
        // the role writes nothing at all, and a new write route is denied until
        // somebody adds it to that list deliberately. A 403, not a redirect.
        $this->withSession(array_merge($this->adviserSession(), ['active_role' => 'school_head']))
            ->post(route('consent-forms.paper.store', $form), $this->answers())
            ->assertForbidden();

        $this->assertSame(HealthConsentForm::STATUS_SENT, $form->refresh()->status);
    }

    /** The photograph is scoped exactly as the form it belongs to is. */
    #[Test]
    public function another_schools_paper_form_is_not_served(): void
    {
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $form = $this->form(HealthConsentForm::STATUS_SENT, $other);

        $this->withSession($this->adviserSession($other))
            ->post(route('consent-forms.paper.store', $form), $this->answers())
            ->assertRedirect();

        $this->assertNotEmpty($form->refresh()->paper_form_path);

        // A neighbouring school's adviser cannot open it.
        $this->withSession($this->adviserSession())
            ->get(route('consent-forms.paper.image', $form))
            ->assertNotFound();
    }

    /** A form with no photograph on file has nothing to serve. */
    #[Test]
    public function a_form_with_no_photograph_serves_nothing(): void
    {
        $this->withSession($this->adviserSession())
            ->get(route('consent-forms.paper.image', $this->form()))
            ->assertNotFound();
    }

    // ── The desks that act on it see the paper ───────────────────────

    private function readerSession(string $role, ?Institution $school = null): array
    {
        return [
            'active_role' => $role,
            'active_name' => 'Ana Reyes',
            'active_username' => 'reader1',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => ($school ?? $this->school)->id,
        ];
    }

    private function recordedOnPaper(): HealthConsentForm
    {
        $form = $this->form();

        $this->withSession($this->adviserSession())
            ->post(route('consent-forms.paper.store', $form), $this->answers())
            ->assertRedirect();

        return $form->refresh();
    }

    /**
     * The answers on a paper consent were typed by the adviser, so the clinic
     * that acts on them must be able to check them against the parent's own
     * marks — the photograph is the only signature such a consent has.
     */
    #[Test]
    public function the_clinic_can_open_the_photograph_a_paper_consent_was_recorded_from(): void
    {
        $form = $this->recordedOnPaper();

        foreach (['school_nurse', 'clinic_staff', 'clinic_teacher'] as $role) {
            $html = $this->withSession($this->readerSession($role))
                ->get(route('consent-forms.nurse-show', $form))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString('View Signed Paper Form', $html, $role.' is not offered the photograph.');
            $this->assertStringContainsString(route('consent-forms.paper.image', $form), $html);
            $this->assertStringContainsString('entered by the class adviser', $html);

            $this->withSession($this->readerSession($role))
                ->get(route('consent-forms.paper.image', $form))
                ->assertOk();
        }

        // Still scoped to the school the form belongs to.
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $this->withSession($this->readerSession('school_nurse', $other))
            ->get(route('consent-forms.paper.image', $form))
            ->assertNotFound();
    }

    /**
     * EnsureActiveSession re-seeds a demo session for whichever role a URL
     * belongs to, so a photograph served from an adviser URL would turn the
     * nurse who opened it into the adviser.
     */
    #[Test]
    public function opening_the_photograph_does_not_change_a_demo_nurses_role(): void
    {
        $form = $this->recordedOnPaper();

        $this->assertStringNotContainsString('/class-adviser/', route('consent-forms.paper.image', $form));

        $this->withSession(array_merge($this->readerSession('school_nurse'), ['active_username' => 'prototype']))
            ->get(route('consent-forms.paper.image', $form))
            ->assertOk()
            ->assertSessionHas('active_role', 'school_nurse');
    }

    #[Test]
    public function the_school_head_reads_the_photograph_under_their_own_path(): void
    {
        $form = $this->recordedOnPaper();

        $html = $this->withSession($this->readerSession('school_head'))
            ->get(route('consent-forms.head-show', $form))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('consent-forms.head-paper-image', $form), $html);

        $this->withSession($this->readerSession('school_head'))
            ->get(route('consent-forms.head-paper-image', $form))
            ->assertOk();
    }

    /** A consent the parent signed on screen has no photograph to offer. */
    #[Test]
    public function a_consent_signed_on_screen_offers_no_photograph(): void
    {
        $form = $this->form(HealthConsentForm::STATUS_SIGNED);

        $html = $this->withSession($this->readerSession('school_nurse'))
            ->get(route('consent-forms.nurse-show', $form))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('View Signed Paper Form', $html);
        $this->assertStringNotContainsString('entered by the class adviser', $html);
    }
}
