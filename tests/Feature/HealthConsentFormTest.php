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
     * Another learner on the roll at the school these sessions are scoped to.
     * The clinic's list is the year's roll, so a learner with no record there
     * is not on it — whatever forms carry their LRN.
     */
    private function learnerRecord(string $lrn, string $name): StudentHealthRecord
    {
        $institution = Institution::find(1)
            ?? Institution::create(['name' => 'Sta. Ana National High School', 'status' => 'active']);

        return StudentHealthRecord::create([
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'institution_id' => $institution->id,
            'student_id' => $lrn,
            'student_name' => $name,
            'school_name' => 'Sta. Ana National High School',
            'section' => 'Grade 7/SPED / SPED-A',
        ]);
    }

    /**
     * A learner is on the list exactly when their own row link is. A name is
     * not a safe marker: the nurse topbar's learner search embeds the whole
     * roster, so every name is in the page whatever the table holds.
     */
    private function rowLink(StudentHealthRecord $record): string
    {
        return e(route('consent-forms.nurse-learner', $record));
    }

    private function nurseList(array $query = []): string
    {
        return $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-index', $query))
            ->assertOk()
            ->getContent();
    }

    /**
     * The nurse narrows the list by the parent's answer.
     *
     * `consent_choice` is encrypted at rest, so the filter runs in PHP after
     * the fetch — never as a WHERE, which would match nothing against
     * ciphertext and quietly report that no parent consented.
     */
    #[Test]
    public function the_nurse_can_filter_the_list_by_the_parents_answer(): void
    {
        $juan = $this->makeRecord();
        $this->signAsParent($this->sendToParent($this->createDraft()));

        // A second learner on the same roll, whose parent refused.
        $rosa = $this->learnerRecord('123456789013', 'Refuser, Rosa');
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

        $all = $this->nurseList();
        $this->assertStringContainsString($this->rowLink($juan), $all);
        $this->assertStringContainsString($this->rowLink($rosa), $all);

        $consented = $this->nurseList(['status' => ConsentStanding::SERVICE_CONSENTED]);
        $this->assertStringContainsString($this->rowLink($juan), $consented);
        $this->assertStringNotContainsString($this->rowLink($rosa), $consented);

        $declined = $this->nurseList(['status' => ConsentStanding::SERVICE_DECLINED]);
        $this->assertStringContainsString($this->rowLink($rosa), $declined);
        $this->assertStringNotContainsString($this->rowLink($juan), $declined);
    }

    /**
     * A value off the query string that is not one of the statuses narrows
     * nothing, rather than emptying the page and reading as "no learner
     * consented".
     */
    #[Test]
    public function an_unrecognised_consent_filter_is_dropped(): void
    {
        $juan = $this->makeRecord();
        $this->signAsParent($this->sendToParent($this->createDraft()));

        $this->assertStringContainsString($this->rowLink($juan), $this->nurseList(['status' => 'maybe']));
    }

    /** An empty filtered list says so, and offers the way back. */
    #[Test]
    public function an_empty_filtered_list_offers_to_clear_the_filters(): void
    {
        $juan = $this->makeRecord();
        $this->signAsParent($this->sendToParent($this->createDraft()));

        $html = $this->nurseList(['status' => ConsentStanding::SERVICE_DECLINED]);

        $this->assertStringContainsString('No learners match these filters.', $html);
        $this->assertStringContainsString('Clear filters', $html);
        $this->assertStringNotContainsString($this->rowLink($juan), $html);
    }

    /**
     * With every service, "Consented" covers the parent who agreed *except*
     * for certain services — they did consent — and the row still says which
     * kind it was, because the next question about an exception is always
     * which services it covered.
     */
    #[Test]
    public function the_agreed_filter_covers_both_kinds_of_agreement(): void
    {
        $juan = $this->makeRecord();
        $this->signAsParent($this->sendToParent($this->createDraft()));

        $pia = $this->learnerRecord('123456789014', 'Partial, Pia');
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

        $html = $this->nurseList(['status' => ConsentStanding::SERVICE_CONSENTED]);

        $this->assertStringContainsString($this->rowLink($juan), $html);
        $this->assertStringContainsString($this->rowLink($pia), $html);
        $this->assertStringContainsString('>Consented with exceptions</span>', $html);
        $this->assertStringContainsString('1 with exceptions', $html);
    }

    /**
     * A letter that is out but unanswered is a learner with no form yet.
     *
     * The nurse plans a deworming round off this list, and "nobody has replied
     * for this learner yet" is exactly what decides whether a child can be
     * given a service. The learner's own page says the letter went out, so the
     * nurse knows the follow-up is a reminder rather than a first request.
     */
    #[Test]
    public function a_sent_but_unanswered_form_is_on_the_nurses_list(): void
    {
        $juan = $this->makeRecord();
        $this->sendToParent($this->createDraft());

        $html = $this->nurseList();
        $this->assertStringContainsString($this->rowLink($juan), $html);
        $this->assertStringContainsString('>No form yet</span>', $html);

        $this->assertStringContainsString($this->rowLink($juan), $this->nurseList(['status' => ConsentStanding::SERVICE_NO_FORM]));

        // It is not an answer, so no answer filter claims it.
        foreach ([ConsentStanding::SERVICE_CONSENTED, ConsentStanding::SERVICE_DECLINED] as $status) {
            $this->assertStringNotContainsString(
                $this->rowLink($juan),
                $this->nurseList(['status' => $status]),
                'An unanswered letter must not be listed under "'.$status.'".'
            );
        }

        $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-learner', $juan))
            ->assertOk()
            ->assertSee('No form yet.')
            ->assertSee('A letter was sent to the parent on');
    }

    /** Once the parent answers, the learner moves to their answer. */
    #[Test]
    public function an_answered_form_leaves_no_form_yet(): void
    {
        $juan = $this->makeRecord();
        $this->signAsParent($this->sendToParent($this->createDraft()));

        $this->assertStringNotContainsString($this->rowLink($juan), $this->nurseList(['status' => ConsentStanding::SERVICE_NO_FORM]));
        $this->assertStringContainsString($this->rowLink($juan), $this->nurseList(['status' => ConsentStanding::SERVICE_CONSENTED]));
    }

    /**
     * A consent that arrived as a scan is on the nurse's list too.
     *
     * The upload dialog no longer asks what the parent answered, so a scan
     * usually carries none: the learner reads "No form yet" — never consented
     * — and their page shows the scan and says its answer is unrecorded.
     */
    #[Test]
    public function a_scanned_consent_is_on_the_nurses_list(): void
    {
        $juan = $this->makeRecord();
        $upload = $this->uploadScan();

        $html = $this->nurseList();
        $this->assertStringContainsString($this->rowLink($juan), $html);
        $this->assertStringContainsString('>No form yet</span>', $html);

        $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-learner', $juan))
            ->assertOk()
            ->assertSee(e(route('parental-consent.download', $upload->id)), false)
            ->assertSee('has not been recorded');
    }

    /** And the filter reaches it, under the standing it actually has. */
    #[Test]
    public function a_scanned_consent_is_reachable_from_the_filter(): void
    {
        $juan = $this->makeRecord();
        $this->uploadScan();

        $this->assertStringContainsString($this->rowLink($juan), $this->nurseList(['status' => ConsentStanding::SERVICE_NO_FORM]));

        foreach ([ConsentStanding::SERVICE_CONSENTED, ConsentStanding::SERVICE_DECLINED] as $status) {
            $this->assertStringNotContainsString(
                $this->rowLink($juan),
                $this->nurseList(['status' => $status]),
                'A scan with no answer keyed in must not be listed under "'.$status.'".'
            );
        }
    }

    /** An answer the adviser did key in is the row's answer, and filters as one. */
    #[Test]
    public function a_scanned_consent_carrying_an_answer_reports_it(): void
    {
        $juan = $this->makeRecord();
        $this->uploadScan(['consent_type' => 'full']);

        $html = $this->nurseList(['status' => ConsentStanding::SERVICE_CONSENTED]);

        $this->assertStringContainsString($this->rowLink($juan), $html);
        $this->assertStringContainsString('>Consented</span>', $html);
    }

    /**
     * One learner, one row: the row is the record that answers the question.
     *
     * A letter still out yields to a scan that has come back — the parent has
     * replied, and a row reading "No form yet" over a signed form on file
     * would send a nurse looking for a consent the school already holds.
     */
    #[Test]
    public function a_scan_answers_for_a_learner_whose_letter_is_still_out(): void
    {
        $juan = $this->makeRecord();
        $this->sendToParent($this->createDraft());
        $upload = $this->uploadScan(['consent_type' => 'full']);

        $html = $this->nurseList();

        $this->assertSame(1, substr_count($html, $this->rowLink($juan)), 'One learner, one row.');
        $this->assertStringContainsString('>Consented</span>', $html);
        $this->assertStringNotContainsString('>No form yet</span>', $html);

        $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-learner', $juan))
            ->assertOk()
            ->assertSee(e(route('parental-consent.download', $upload->id)), false);
    }

    /**
     * An answered letter keeps its place, because it carries the parent's reply
     * service by service — which a single ticked box on a scan does not.
     */
    #[Test]
    public function an_answered_letter_outranks_a_scan_for_the_same_learner(): void
    {
        $juan = $this->makeRecord();
        $form = $this->signAsParent($this->sendToParent($this->createDraft()));
        $upload = $this->uploadScan();

        $this->assertSame(1, substr_count($this->nurseList(), $this->rowLink($juan)), 'One learner, one row.');

        $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-learner', $juan))
            ->assertOk()
            ->assertSee(e(route('consent-forms.nurse-show', $form)), false)
            ->assertDontSee(e(route('parental-consent.download', $upload->id)), false);
    }
}
