<?php

namespace Tests\Feature;

use App\Models\HealthConsentForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HealthConsentFormTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private function adviserSession(): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Maria Santos',
            'active_username' => 'maria.santos',
            'active_school_name' => 'Sta. Ana National High School',
            'active_institution_id' => 1,
            'assigned_grade_level' => 'Grade 7/SPED',
            'assigned_section' => 'SPED-A',
            'assigned_school_name' => 'Sta. Ana National High School',
            'school_health_card_records' => [
                [
                    'last_name' => 'Dela Cruz',
                    'first_name' => 'Juan',
                    'middle_name' => 'R',
                    'lrn' => '123456789012',
                    'parent_guardian' => 'Pedro Dela Cruz',
                    'address' => '123 Damaso Suazo St., Davao City',
                    'division' => 'DAVAO CITY',
                    'grade_level' => 'Grade 7/SPED',
                    'section' => 'SPED-A',
                ],
            ],
        ];
    }

    private function nurseSession(): array
    {
        return [
            'active_role' => 'school_nurse',
            'active_name' => 'Nurse Reyes',
            'active_institution_id' => 1,
        ];
    }

    private function createDraft(): HealthConsentForm
    {
        $this->withSession($this->adviserSession())
            ->post(route('consent-forms.open'), ['lrn' => '123456789012']);

        return HealthConsentForm::firstOrFail();
    }

    private function sendToParent(HealthConsentForm $form): HealthConsentForm
    {
        $this->withSession($this->adviserSession())
            ->post(route('consent-forms.send', $form), ['services' => ['checkup', 'deworming', 'deworming_worms']]);

        return $form->fresh();
    }

    private function signAsParent(HealthConsentForm $form, array $overrides = []): HealthConsentForm
    {
        $this->post(route('consent-forms.parent-submit', $form->token), array_merge([
            'consent_choice' => 'all',
            'signature' => self::SIGNATURE,
        ], $overrides));

        return $form->fresh();
    }

    #[Test]
    public function adviser_creates_a_draft_prefilled_from_the_student_record(): void
    {
        $form = $this->createDraft();

        $this->assertSame(HealthConsentForm::STATUS_DRAFT, $form->status);
        $this->assertSame('Dela Cruz, Juan R', $form->student_name);
        $this->assertSame('Pedro Dela Cruz', $form->parent_guardian_name);
        $this->assertSame('DAVAO CITY', $form->division);
        $this->assertSame('Sta. Ana National High School', $form->school_name);
        $this->assertNotEmpty($form->audit);
    }

    #[Test]
    public function non_adviser_cannot_open_the_adviser_pages(): void
    {
        $form = $this->createDraft();

        $this->withSession($this->nurseSession())
            ->get(route('consent-forms.show', $form))
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function sending_requires_at_least_one_service(): void
    {
        $form = $this->createDraft();

        $this->withSession($this->adviserSession())
            ->post(route('consent-forms.send', $form), ['services' => []]);

        $this->assertSame(HealthConsentForm::STATUS_DRAFT, $form->fresh()->status);
    }

    #[Test]
    public function sending_locks_the_adviser_section_and_generates_a_parent_token(): void
    {
        $form = $this->sendToParent($this->createDraft());

        $this->assertSame(HealthConsentForm::STATUS_SENT, $form->status);
        $this->assertNotNull($form->token);
        $this->assertNotNull($form->sent_at);

        // Adviser can no longer change the services.
        $this->withSession($this->adviserSession())
            ->post(route('consent-forms.draft', $form), ['services' => ['dental']]);

        $this->assertSame(['checkup', 'deworming', 'deworming_worms'], $form->fresh()->services);
    }

    #[Test]
    public function parent_can_open_the_form_via_token_without_logging_in(): void
    {
        $form = $this->sendToParent($this->createDraft());

        $this->get(route('consent-forms.parent', $form->token))
            ->assertStatus(200)
            ->assertSee('SULAT-PAHIBALO')
            ->assertSee('Dela Cruz, Juan R');
    }

    #[Test]
    public function parent_submission_requires_consent_choice_and_signature(): void
    {
        $form = $this->sendToParent($this->createDraft());

        $this->post(route('consent-forms.parent-submit', $form->token), [])
            ->assertSessionHasErrors(['consent_choice', 'signature']);

        $this->assertSame(HealthConsentForm::STATUS_SENT, $form->fresh()->status);
    }

    #[Test]
    public function denying_consent_requires_a_reason(): void
    {
        $form = $this->sendToParent($this->createDraft());

        $this->post(route('consent-forms.parent-submit', $form->token), [
            'consent_choice' => 'deny',
            'signature' => self::SIGNATURE,
        ])->assertSessionHasErrors(['refusal_reason']);
    }

    #[Test]
    public function parent_submission_records_consent_and_locks_further_edits(): void
    {
        $form = $this->sendToParent($this->createDraft());
        $form = $this->signAsParent($form, [
            'allergy_food' => 'Peanuts',
            'other_illness' => 'Asthma',
        ]);

        $this->assertSame(HealthConsentForm::STATUS_SIGNED, $form->status);
        $this->assertSame('all', $form->consent_choice);
        $this->assertSame('Peanuts', $form->allergy_food);
        $this->assertNotNull($form->signed_at);
        $this->assertTrue($form->adviser_unread);

        // A second submission is rejected.
        $this->post(route('consent-forms.parent-submit', $form->token), [
            'consent_choice' => 'deny',
            'refusal_reason' => 'Changed my mind',
            'signature' => self::SIGNATURE,
        ]);

        $this->assertSame('all', $form->fresh()->consent_choice);
    }

    #[Test]
    public function adviser_can_mark_a_signed_form_as_reviewed(): void
    {
        $form = $this->signAsParent($this->sendToParent($this->createDraft()));

        $this->withSession($this->adviserSession())
            ->post(route('consent-forms.review', $form));

        $form = $form->fresh();
        $this->assertSame(HealthConsentForm::STATUS_REVIEWED, $form->status);
        $this->assertNotNull($form->reviewed_at);
        $this->assertFalse($form->adviser_unread);
    }

    #[Test]
    public function nurse_sees_signed_forms_but_not_drafts(): void
    {
        $signed = $this->signAsParent($this->sendToParent($this->createDraft()));

        $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-index'))
            ->assertStatus(200)
            ->assertSee('Dela Cruz, Juan R');

        $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-show', $signed))
            ->assertStatus(200)
            ->assertSee('SULAT-PAHIBALO');
    }

    #[Test]
    public function nurse_cannot_view_a_form_that_is_still_awaiting_the_parent(): void
    {
        $form = $this->sendToParent($this->createDraft());

        $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-show', $form))
            ->assertRedirect(route('consent-forms.nurse-index'));
    }

    #[Test]
    public function audit_trail_captures_the_full_workflow(): void
    {
        $form = $this->signAsParent($this->sendToParent($this->createDraft()));

        $this->withSession($this->adviserSession())->post(route('consent-forms.review', $form));
        $this->withSession($this->nurseSession())->get(route('consent-forms.nurse-show', $form));

        $actions = array_column($form->fresh()->audit, 'action');
        $this->assertSame([
            'Created draft',
            'Sent to parent',
            'Signed by parent/guardian',
            'Reviewed by adviser',
            'Viewed by School Nurse',
        ], $actions);
    }
}
