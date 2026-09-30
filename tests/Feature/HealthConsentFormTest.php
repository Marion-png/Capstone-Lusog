<?php

namespace Tests\Feature;

use App\Models\HealthConsentForm;
use App\Models\Institution;
use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    /**
     * The learner behind the scan: parental_consent_forms hangs off
     * student_health_records, so a scanned consent needs a record to hang on.
     */
    private function makeRecord(): StudentHealthRecord
    {
        $institution = Institution::create(['name' => 'Sta. Ana National High School', 'status' => 'active']);
        $this->assertSame(1, $institution->id, 'The sessions in this file are scoped to institution 1.');

        return StudentHealthRecord::create([
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'institution_id' => $institution->id,
            'student_id' => '123456789012',
            'student_name' => 'Dela Cruz, Juan',
            'school_name' => 'Sta. Ana National High School',
            'section' => 'Grade 7/SPED / SPED-A',
            'weight' => 38.5,
            'bmi_value' => 16.2,
            'nutritional_status' => 'Normal',
        ]);
    }

    /** The adviser's own path: a consent that came back on paper, scanned in. */
    private function uploadScan(array $fields = []): ParentalConsentForm
    {
        Storage::fake('local');

        $this->withSession($this->adviserSession())->post(route('parental-consent.store'), array_merge([
            'lrn' => '123456789012',
            'consent' => UploadedFile::fake()->create('sulat-pahibalo.jpg', 120, 'image/jpeg'),
        ], $fields));

        return ParentalConsentForm::latest('id')->firstOrFail();
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

    /**
     * The nurse narrows the list by what the parent answered.
     *
     * `consent_choice` is encrypted at rest, so the filter runs in PHP after
     * the fetch — never as a WHERE, which would match nothing against
     * ciphertext and quietly report that no parent consented.
     */
    #[Test]
    public function the_nurse_can_filter_the_list_by_the_parents_answer(): void
    {
        $agreed = $this->signAsParent($this->sendToParent($this->createDraft()));

        // A second learner on the same roll, whose parent refused.
        $session = $this->adviserSession();
        $session['school_health_card_records'][] = [
            'last_name' => 'Refuser',
            'first_name' => 'Rosa',
            'lrn' => '123456789013',
            'parent_guardian' => 'Rita Refuser',
            'address' => '9 Mabini St., Davao City',
            'division' => 'DAVAO CITY',
            'grade_level' => 'Grade 7/SPED',
            'section' => 'SPED-A',
        ];

        $this->withSession($session)->post(route('consent-forms.open'), ['lrn' => '123456789013']);
        $refused = HealthConsentForm::where('student_lrn', '123456789013')->firstOrFail();

        $this->withSession($session)
            ->post(route('consent-forms.send', $refused), ['services' => ['checkup']]);

        $this->post(route('consent-forms.parent-submit', $refused->fresh()->token), [
            'consent_choice' => 'deny',
            'refusal_reason' => 'Naa siyay sakit karon.',
            'signature' => self::SIGNATURE,
        ]);

        $agreedRow = route('consent-forms.nurse-show', $agreed);
        $refusedRow = route('consent-forms.nurse-show', $refused);

        // A row is on the list exactly when its own View link is. A learner's
        // name is not a safe marker here: the nurse topbar's learner search
        // embeds the whole roster, so every name is in the page whatever the
        // table holds.
        $all = $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-index'))->assertOk()->getContent();
        $this->assertStringContainsString($agreedRow, $all);
        $this->assertStringContainsString($refusedRow, $all);

        $consented = $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-index', ['consent' => HealthConsentForm::CONSENT_ALL]))
            ->assertOk()->getContent();
        $this->assertStringContainsString($agreedRow, $consented);
        $this->assertStringNotContainsString($refusedRow, $consented);

        $denied = $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-index', ['consent' => HealthConsentForm::CONSENT_DENY]))
            ->assertOk()->getContent();
        $this->assertStringContainsString($refusedRow, $denied);
        $this->assertStringNotContainsString($agreedRow, $denied);
    }

    /**
     * A value off the query string that is not one of the three answers
     * narrows nothing, rather than emptying the page and reading as "no
     * learner consented".
     */
    #[Test]
    public function an_unrecognised_consent_filter_is_dropped(): void
    {
        $form = $this->signAsParent($this->sendToParent($this->createDraft()));

        $html = $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-index', ['consent' => 'maybe']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('consent-forms.nurse-show', $form), $html);
    }

    /** An empty filtered list says which question it answered. */
    #[Test]
    public function an_empty_filtered_list_names_the_answer_it_looked_for(): void
    {
        $form = $this->signAsParent($this->sendToParent($this->createDraft()));

        $html = $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-index', ['consent' => HealthConsentForm::CONSENT_DENY]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("No learner's form reads", $html);
        $this->assertStringContainsString('Did not agree', $html);
        $this->assertStringNotContainsString(route('consent-forms.nurse-show', $form), $html);
    }

    /**
     * "Agreed" is one answer to the nurse's usual question, and it covers the
     * parent who agreed *except* for certain services — they did consent. The
     * precise answers stay on the list below it, because the next question
     * about an exception is always which services it covered.
     */
    #[Test]
    public function the_agreed_filter_covers_both_kinds_of_agreement(): void
    {
        $all = $this->signAsParent($this->sendToParent($this->createDraft()));

        $session = $this->adviserSession();
        $session['school_health_card_records'][] = [
            'last_name' => 'Partial', 'first_name' => 'Pia', 'lrn' => '123456789014',
            'parent_guardian' => 'Perla Partial', 'address' => '4 Rizal St., Davao City',
            'division' => 'DAVAO CITY', 'grade_level' => 'Grade 7/SPED', 'section' => 'SPED-A',
        ];

        $this->withSession($session)->post(route('consent-forms.open'), ['lrn' => '123456789014']);
        $partial = HealthConsentForm::where('student_lrn', '123456789014')->firstOrFail();
        $this->withSession($session)->post(route('consent-forms.send', $partial), ['services' => ['checkup']]);
        $this->post(route('consent-forms.parent-submit', $partial->fresh()->token), [
            'consent_choice' => 'specific',
            'consent_exceptions' => 'Dili ang bakuna.',
            'signature' => self::SIGNATURE,
        ]);

        $html = $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-index', ['consent' => 'agreed']))
            ->assertOk()
            ->getContent();

        // Both forms of agreement are on the list.
        $this->assertStringContainsString(route('consent-forms.nurse-show', $all), $html);
        $this->assertStringContainsString(route('consent-forms.nurse-show', $partial), $html);

        // And the dropdown offers the plain two-way split first.
        $this->assertStringContainsString('Agreed to the consent', $html);
        $this->assertStringContainsString('Did not agree', $html);
    }

    /**
     * A letter that is out but unanswered reaches the nurse too.
     *
     * The nurse plans a deworming round off this list, and "nobody has replied
     * for this learner yet" is exactly what decides whether a child can be
     * given a service — an absence the old list could not show, because it
     * carried only forms a parent had already answered. A draft is still not
     * on it: nothing has been sent, so there is nothing to wait for.
     */
    #[Test]
    public function a_sent_but_unanswered_form_is_on_the_nurses_list(): void
    {
        $sent = $this->sendToParent($this->createDraft());

        $html = $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('consent-forms.nurse-show', $sent), $html);

        // It says the parent has not answered — never an em dash, which would
        // read as "they answered something we cannot show".
        $this->assertStringContainsString('Awaiting response', $html);

        // And the filter can be used on exactly that state.
        $awaiting = $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-index', ['consent' => 'awaiting']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString(route('consent-forms.nurse-show', $sent), $awaiting);

        // It is not an answer, so no answer filter claims it.
        foreach (['agreed', HealthConsentForm::CONSENT_ALL, HealthConsentForm::CONSENT_DENY] as $choice) {
            $html = $this->withSession($this->nurseSession())
                ->get(route('consent-forms.nurse-index', ['consent' => $choice]))
                ->assertOk()
                ->getContent();

            $this->assertStringNotContainsString(
                route('consent-forms.nurse-show', $sent),
                $html,
                'An unanswered letter must not be listed under "'.$choice.'".'
            );
        }
    }

    /** Once the parent answers, the same form moves to its answer. */
    #[Test]
    public function an_answered_form_leaves_the_awaiting_list(): void
    {
        $form = $this->signAsParent($this->sendToParent($this->createDraft()));

        $awaiting = $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-index', ['consent' => 'awaiting']))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString(route('consent-forms.nurse-show', $form), $awaiting);

        $agreed = $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-index', ['consent' => 'agreed']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString(route('consent-forms.nurse-show', $form), $agreed);
    }

    /**
     * A consent that arrived as a scan is on the nurse's list too.
     *
     * A consent reaches this school two ways — the digital Sulat-Pahibalo the
     * adviser sends, and the signed form the adviser scans and uploads — and
     * the nurse's question is the same either way: may this child be given the
     * service? The upload used to appear on no nurse screen at all, so a
     * consent could be on file at the school and invisible to the person who
     * acts on it.
     */
    #[Test]
    public function a_scanned_consent_is_on_the_nurses_list(): void
    {
        $this->makeRecord();
        $upload = $this->uploadScan();

        $html = $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-index'))
            ->assertOk()
            ->assertSee('Dela Cruz, Juan')
            ->assertSee('Signed form on file')
            ->getContent();

        // The upload dialog no longer asks what the parent answered, so the
        // row says the answer is not recorded — never that consent was given.
        $this->assertStringContainsString('Answer not recorded', $html);
        $this->assertStringContainsString(
            e(route('parental-consent.download', $upload->id)),
            $html,
            'The scan itself is the record here, so the action opens it.'
        );
    }

    /** And the filter reaches it, under the answer it is actually carrying. */
    #[Test]
    public function a_scanned_consent_is_reachable_from_the_filter(): void
    {
        $this->makeRecord();
        $upload = $this->uploadScan();
        $link = e(route('parental-consent.download', $upload->id));

        $unrecorded = $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-index', ['consent' => 'unrecorded']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString($link, $unrecorded);

        // It is not an answer, so no answer filter claims it.
        foreach (['agreed', HealthConsentForm::CONSENT_DENY, 'awaiting'] as $choice) {
            $html = $this->withSession($this->nurseSession())
                ->get(route('consent-forms.nurse-index', ['consent' => $choice]))
                ->assertOk()
                ->getContent();

            $this->assertStringNotContainsString(
                $link,
                $html,
                'A scan with no answer keyed in must not be listed under "'.$choice.'".'
            );
        }
    }

    /** An answer the adviser did key in is the row's answer, and filters as one. */
    #[Test]
    public function a_scanned_consent_carrying_an_answer_reports_it(): void
    {
        $this->makeRecord();
        $upload = $this->uploadScan(['consent_type' => 'full']);
        $link = e(route('parental-consent.download', $upload->id));

        $html = $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-index', ['consent' => 'agreed']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($link, $html);
        $this->assertStringContainsString('Consented to all services', $html);
    }

    /**
     * One learner, one row: the row is the record that answers the question.
     *
     * A letter still out yields to a scan that has come back — the parent has
     * replied, and a row reading "Awaiting response" over a signed form on file
     * would send a nurse looking for a consent the school already holds.
     */
    #[Test]
    public function a_scan_answers_for_a_learner_whose_letter_is_still_out(): void
    {
        $this->makeRecord();
        $this->sendToParent($this->createDraft());
        $upload = $this->uploadScan(['consent_type' => 'full']);

        $html = $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'LRN 123456789012'), 'One learner, one row.');
        $this->assertStringContainsString(e(route('parental-consent.download', $upload->id)), $html);
        $this->assertStringContainsString('Consented to all services', $html);

        // "Awaiting response" is still an option in the filter above the table;
        // what must be gone is the row that reads it.
        $this->assertStringNotContainsString('>Awaiting response</span>', $html);
    }

    /**
     * An answered letter keeps its place, because it carries the parent's reply
     * service by service — which a single ticked box on a scan does not.
     */
    #[Test]
    public function an_answered_letter_outranks_a_scan_for_the_same_learner(): void
    {
        $this->makeRecord();
        $form = $this->signAsParent($this->sendToParent($this->createDraft()));
        $upload = $this->uploadScan();

        $html = $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'LRN 123456789012'), 'One learner, one row.');
        $this->assertStringContainsString(e(route('consent-forms.nurse-show', $form)), $html);
        $this->assertStringNotContainsString(e(route('parental-consent.download', $upload->id)), $html);
    }
}
