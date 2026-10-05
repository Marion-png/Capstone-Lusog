<?php

namespace Tests\Feature;

use App\Models\HealthConsentForm;
use App\Models\Institution;
use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;
use App\Support\ConsentStanding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Telling apart the guardians who agreed from the ones who did not.
 *
 * `parental_consent_forms.consent_type` and every reader of it already
 * existed; the upload dialog simply never asked, so every filed form read as
 * PENDING for ever and the school could see that a consent had come back
 * without ever seeing whether it said yes or no.
 *
 * The test that matters is `an_attested_answer_outranks_the_uploaders_note`.
 * The field was once left out on the reasoning that two screens asking one
 * question is how they come to disagree — so what has to be pinned is that
 * they are not equals: the parent's own answer, or an attested paper record,
 * is read first and this one is only the fallback.
 */
class ConsentAnswerCaptureTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    private StudentHealthRecord $learner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);

        $this->learner = StudentHealthRecord::create([
            'student_id' => '123456789012',
            'institution_id' => $this->school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'section' => 'Grade 10 / Dalton',
            'school_name' => $this->school->name,
            'student_name' => 'Cruz, Juan',
        ]);
    }

    private function adviserSession(): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Maria Santos',
            'active_username' => 'adviser1',
            'active_school_name' => $this->school->name,
            'active_institution_id' => $this->school->id,
            'assigned_grade_level' => 'Grade 10',
            'assigned_section' => 'Dalton',
        ];
    }

    private function upload(?string $answer): ParentalConsentForm
    {
        $payload = [
            'lrn' => '123456789012',
            'consent' => UploadedFile::fake()->create('signed.jpg', 120, 'image/jpeg'),
        ];

        if ($answer !== null) {
            $payload['consent_type'] = $answer;
        }

        $this->withSession($this->adviserSession())
            ->from(route('consent-forms.index'))
            ->post(route('parental-consent.store'), $payload)
            ->assertRedirect();

        return ParentalConsentForm::latest('id')->firstOrFail();
    }

    // ── The field exists and is optional ────────────────────────────────

    /** The dialog asks, offering all three answers plus "not recorded yet". */
    #[Test]
    public function the_upload_dialog_asks_what_the_guardian_answered(): void
    {
        $html = $this->withSession($this->adviserSession())
            ->get(route('consent-forms.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="consent_type"', $html);
        $this->assertStringContainsString('Not recorded yet', $html);

        foreach (['value="full"', 'value="partial"', 'value="refused"'] as $option) {
            $this->assertStringContainsString($option, $html, $option.' is not offered.');
        }

        // Optional, so a form can still be filed before it has been read.
        $this->assertStringNotContainsString('name="consent_type" required', $html);
    }

    // ── The three answers are distinguished ────────────────────────────

    /** Each answer lands on the record and reads back as the right standing. */
    #[Test]
    public function each_answer_is_stored_and_read_back_as_its_own_standing(): void
    {
        foreach ([
            'full' => ConsentStanding::APPROVED,
            'partial' => ConsentStanding::PARTIAL,
            'refused' => ConsentStanding::DECLINED,
        ] as $answer => $expected) {
            ParentalConsentForm::query()->delete();

            $form = $this->upload($answer);

            $this->assertSame($answer, $form->consent_type, "The '{$answer}' answer was not stored.");
            $this->assertSame(
                $expected,
                ConsentStanding::for(null, $form),
                "A '{$answer}' upload does not read as {$expected}."
            );
            $this->assertSame($expected, ConsentStanding::badge(null, $form));
        }
    }

    /**
     * Agreed and did-not-agree are genuinely different outcomes, not two
     * labels on one state: only the first authorises a service.
     */
    #[Test]
    public function agreeing_and_declining_are_told_apart(): void
    {
        $agreed = $this->upload('full');
        $this->assertSame(ConsentStanding::APPROVED, ConsentStanding::for(null, $agreed));

        ParentalConsentForm::query()->delete();

        $declined = $this->upload('refused');
        $this->assertSame(ConsentStanding::DECLINED, ConsentStanding::for(null, $declined));

        $this->assertNotSame(
            ConsentStanding::for(null, $agreed),
            ConsentStanding::for(null, $declined),
            'A guardian who agreed and one who refused read the same.'
        );
    }

    /**
     * A form filed without reading it is still only "returned" — it says the
     * school holds the document, never that a consent was given.
     */
    #[Test]
    public function a_form_filed_without_an_answer_authorises_nothing(): void
    {
        $form = $this->upload(null);

        $this->assertNull($form->consent_type);
        $this->assertSame(ConsentStanding::PENDING, ConsentStanding::for(null, $form), 'An unread form authorised a service.');
        // But the adviser is told the guardian did return it.
        $this->assertSame(ConsentStanding::RETURNED, ConsentStanding::badge(null, $form));
    }

    // ── Precedence: the reason this is safe ────────────────────────────

    /**
     * The two records are not equals.
     *
     * `ConsentStanding::for()` reads an answered `health_consent_forms` row
     * first and returns from it, so the uploader's note can never contradict
     * the parent's own answer or an attested paper record — it fills the
     * fallback that used to be permanently empty.
     */
    #[Test]
    public function an_attested_answer_outranks_the_uploaders_note(): void
    {
        $upload = $this->upload('full');

        $attested = HealthConsentForm::create([
            'student_lrn' => '123456789012',
            'institution_id' => $this->school->id,
            'school_year' => HealthConsentForm::currentSchoolYear(),
            'division' => 'Davao City',
            'school_name' => $this->school->name,
            'school_address' => 'Sta. Ana, Davao City',
            'student_name' => 'Cruz, Juan',
            'status' => HealthConsentForm::STATUS_SIGNED,
            'consent_choice' => HealthConsentForm::CONSENT_DENY,
        ]);

        // The guardian's attested answer was a refusal, so that is the answer —
        // whatever the uploader keyed in beside the scan.
        $this->assertSame(
            ConsentStanding::DECLINED,
            ConsentStanding::for($attested, $upload),
            "The uploader's note overrode an attested refusal."
        );
    }

    /** The upload is consulted when there is no answered form. */
    #[Test]
    public function the_upload_answers_when_nothing_else_has(): void
    {
        $upload = $this->upload('refused');

        $draft = HealthConsentForm::create([
            'student_lrn' => '123456789012',
            'institution_id' => $this->school->id,
            'school_year' => HealthConsentForm::currentSchoolYear(),
            'division' => 'Davao City',
            'school_name' => $this->school->name,
            'school_address' => 'Sta. Ana, Davao City',
            'student_name' => 'Cruz, Juan',
            'status' => HealthConsentForm::STATUS_DRAFT,
        ]);

        // A draft nobody sent answers nothing, so the upload decides.
        $this->assertSame(ConsentStanding::DECLINED, ConsentStanding::for($draft, $upload));
    }

    // ── What the nurse sees ────────────────────────────────────────────

    /** The nurse's Consent Forms list prints the answer rather than "not recorded". */
    #[Test]
    public function the_nurses_list_shows_the_recorded_answer(): void
    {
        $this->upload('refused');

        $html = $this->withSession([
            'active_role' => 'school_nurse',
            'active_name' => 'Nurse Cruz',
            'active_username' => 'nurse1',
            'active_school_name' => $this->school->name,
            'active_institution_id' => $this->school->id,
        ])->get(route('consent-forms.nurse-index'))->assertOk()->getContent();

        // The row is there, and it is not reported as unanswered.
        $this->assertStringContainsString('Cruz, Juan', $html);
        $this->assertStringNotContainsString('Answer not recorded</span>', $html);
    }

    /** An answer off the wire that is not one of the three is refused. */
    #[Test]
    public function an_unrecognised_answer_is_refused(): void
    {
        $this->withSession($this->adviserSession())
            ->from(route('consent-forms.index'))
            ->post(route('parental-consent.store'), [
                'lrn' => '123456789012',
                'consent' => UploadedFile::fake()->create('signed.jpg', 120, 'image/jpeg'),
                'consent_type' => 'probably-yes',
            ])
            ->assertSessionHasErrors('consent_type');

        $this->assertSame(0, ParentalConsentForm::count(), 'A refused upload was stored anyway.');
    }
}
