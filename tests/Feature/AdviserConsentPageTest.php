<?php

namespace Tests\Feature;

use App\Models\HealthConsentForm;
use App\Models\Institution;
use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The adviser's Parent's Consent page: per-learner status derived from the
 * e-signature workflow with an uploaded scan as fallback, plus the upload
 * form that surfaces the previously UI-less parental-consent endpoint.
 */
class AdviserConsentPageTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    private function adviserSession(): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Test Adviser',
            'active_username' => 'adviser1',
            'active_institution_id' => $this->institution->id,
            'active_school_name' => 'Test School',
            'assigned_school_name' => 'Test School',
            'assigned_grade_level' => 'Grade 1',
            'assigned_section' => 'Sampaguita',
        ];
    }

    private function enrol(string $lrn = '123456789012', string $lastName = 'Dela Cruz'): void
    {
        $this->withSession($this->adviserSession())
            ->post(route('adviser.store'), [
                'last_name' => $lastName, 'first_name' => 'Juan', 'middle_name' => 'A',
                'lrn' => $lrn, 'birth_date' => '2015-06-01', 'birthplace' => 'Davao City',
                'parent_guardian' => 'Maria Dela Cruz', 'address' => '123 Mabini St.',
                'region' => 'XI', 'division' => 'Davao City',
                'telephone_no' => '09171234567', 'gender' => 'Male',
                'height_cm' => 110, 'weight_kg' => 18.5,
                'grade_level' => 'Grade 1', 'section' => 'Sampaguita',
            ])->assertRedirect();
    }

    /** @test */
    public function the_page_shows_the_banner_stats_and_records_table(): void
    {
        $this->enrol();

        $response = $this->flushSession()
            ->withSession($this->adviserSession())
            ->get(route('consent-forms.index'));

        $response->assertOk();
        $response->assertSee('School Year');
        $response->assertSee('Student Consent Records');
        $response->assertSee('Upload Consent');
        $response->assertSee('123456789012');
        $response->assertSee('Dela Cruz');

        // No consent yet, so the learner counts as pending and the rate is 0%.
        $response->assertSee('ms-consent-pending', false);
        $response->assertSee('0%');

        // Rows carry what the client-side search and filter read.
        $response->assertSee('data-status="pending"', false);
        $response->assertSee('data-lrn="123456789012"', false);
    }

    /** @test */
    public function a_signed_online_form_drives_the_consent_status(): void
    {
        $this->enrol();

        HealthConsentForm::create([
            'institution_id' => $this->institution->id,
            'student_lrn' => '123456789012',
            'school_year' => HealthConsentForm::currentSchoolYear(),
            'student_name' => 'Dela Cruz, Juan A.',
            'division' => HealthConsentForm::DEFAULT_DIVISION,
            'school_name' => 'Test School',
            'school_address' => HealthConsentForm::DEFAULT_SCHOOL_ADDRESS,
            'status' => HealthConsentForm::STATUS_SIGNED,
            'consent_choice' => HealthConsentForm::CONSENT_SPECIFIC,
            'services' => [],
            'signed_at' => now(),
        ]);

        $response = $this->flushSession()
            ->withSession($this->adviserSession())
            ->get(route('consent-forms.index'));

        $response->assertOk();
        $response->assertSee('data-status="partial"', false);
        $response->assertSee('100%'); // partial still counts as consented
    }

    /** @test */
    public function an_uploaded_scan_stands_in_when_no_online_form_is_signed(): void
    {
        $this->enrol();

        $record = StudentHealthRecord::where('student_id', '123456789012')->first();
        ParentalConsentForm::create([
            'student_health_record_id' => $record->id,
            'program_type' => 'Deworming',
            'school_year' => ParentalConsentForm::currentSchoolYear(),
            'consent_type' => 'refused',
            'medical_cert_attached' => true,
            'file_path' => 'parental-consents/'.$record->id.'/scan.pdf',
            'uploaded_by_name' => 'Test Adviser',
        ]);

        $response = $this->flushSession()
            ->withSession($this->adviserSession())
            ->get(route('consent-forms.index'));

        $response->assertOk();
        $response->assertSee('data-status="declined"', false);
        $response->assertSee('pc-tick-on', false);
    }

    /** @test */
    public function the_upload_form_posts_a_consent_scan_for_the_learner(): void
    {
        $this->enrol();

        $this->withSession($this->adviserSession())
            ->post(route('parental-consent.store'), [
                'lrn' => '123456789012',
                'consent_type' => 'full',
                'medical_cert_attached' => '1',
                'consent' => UploadedFile::fake()->create('sulat-pahibalo.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $record = StudentHealthRecord::where('student_id', '123456789012')->first();
        $upload = ParentalConsentForm::where('student_health_record_id', $record->id)->first();

        $this->assertNotNull($upload);
        $this->assertSame('full', $upload->consent_type);
        $this->assertTrue($upload->medical_cert_attached);
        $this->assertNotNull($upload->file_path);

        // That upload now drives the page's status column.
        $this->flushSession()
            ->withSession($this->adviserSession())
            ->get(route('consent-forms.index'))
            ->assertOk()
            ->assertSee('data-status="approved"', false);
    }

    /** @test */
    public function each_row_offers_only_view_details_and_upload_form(): void
    {
        $this->enrol();

        $html = $this->flushSession()
            ->withSession($this->adviserSession())
            ->get(route('consent-forms.index'))
            ->assertOk()
            ->getContent();

        $start = strpos($html, 'js-consent-row');
        $end = strpos($html, 'js-consent-nomatch', $start);
        $actions = substr($html, $start, $end - $start);

        $this->assertStringContainsString('title="View Details"', $actions);
        $this->assertStringContainsString('title="Upload Form"', $actions);
        // Trailing space so the .ms-actions wrapper is not counted as a button.
        $this->assertSame(2, substr_count($actions, 'class="ms-act '));

        // The pencil action that opened the online form is gone from this page.
        $this->assertStringNotContainsString(route('consent-forms.open'), $actions);
    }

    /** @test */
    public function the_details_view_carries_the_upload_metadata(): void
    {
        $this->enrol();

        $record = StudentHealthRecord::where('student_id', '123456789012')->first();
        ParentalConsentForm::create([
            'student_health_record_id' => $record->id,
            'program_type' => 'Deworming',
            'school_year' => ParentalConsentForm::currentSchoolYear(),
            'consent_type' => 'partial',
            'partial_exception' => 'No deworming',
            'medical_cert_attached' => true,
            'file_path' => 'parental-consents/'.$record->id.'/scan.pdf',
            'file_original_name' => 'sulat_pahibalo_dela_cruz.pdf',
            'uploaded_by_name' => 'Test Adviser',
        ]);

        $response = $this->flushSession()
            ->withSession($this->adviserSession())
            ->get(route('consent-forms.index'));

        $response->assertOk();
        $panel = $this->viewPanel($response->getContent(), '123456789012');

        // The form drawn back as the paper carries it, in its own words.
        $this->assertBox($panel, 'yes', HealthConsentForm::CONSENT_TEXT[HealthConsentForm::CONSENT_SPECIFIC]);
        $this->assertBox($panel, 'no', HealthConsentForm::CONSENT_TEXT[HealthConsentForm::CONSENT_ALL]);
        $this->assertStringContainsString('<span class="pcopy-written">No deworming</span>', $panel);
        $this->assertStringContainsString('<dt>Medical certificate</dt><dd>Attached</dd>', $panel);
        $this->assertStringContainsString('Uploaded by Test Adviser on', $panel);

        // The scan opens through the one audited download, which serves the
        // learner's own adviser (see the_learners_own_adviser_can_open_the_
        // uploaded_form) — never a path on disk. A PDF is shown in a frame.
        $upload = ParentalConsentForm::latest('id')->firstOrFail();
        $this->assertStringContainsString('<iframe class="pc-view-doc" src="'.e(route('parental-consent.download', $upload->id)).'"', $panel);
        $response->assertDontSee('parental-consents/', false);
    }

    /**
     * View Details reads the form back as the paper was signed: the photo
     * beside every service's standing, the allergies and who filed it.
     *
     * @test
     */
    public function the_details_view_reads_back_the_uploaded_photo(): void
    {
        $this->enrol();

        $this->flushSession()->withSession($this->adviserSession())
            ->post(route('parental-consent.store'), [
                'lrn' => '123456789012',
                'consent_type' => 'full',
                'services' => array_keys(HealthConsentForm::PLANNED_SERVICES),
                'allergy_food_detail' => 'Shrimp',
                'allergy_medicine_detail' => 'wala',
                'consent' => UploadedFile::fake()->create('sulat-pahibalo.jpg', 120, 'image/jpeg'),
            ])
            ->assertSessionHasNoErrors();

        $upload = ParentalConsentForm::latest('id')->firstOrFail();
        $this->assertSame(array_keys(HealthConsentForm::PLANNED_SERVICES), $upload->services);
        $this->assertTrue($upload->allergy_food);
        // "Wala" is the parent saying there is none, not an allergy.
        $this->assertFalse($upload->allergy_medicine);

        $html = $this->flushSession()->withSession($this->adviserSession())
            ->get(route('consent-forms.index'))
            ->assertOk()
            ->getContent();
        $panel = $this->viewPanel($html, '123456789012');

        $this->assertStringContainsString('Dela Cruz, Juan A', $panel);
        $this->assertStringContainsString('Grade 1 – Sampaguita', $panel);
        $this->assertStringContainsString('Adviser: Test Adviser', $panel);
        $this->assertStringContainsString('<img src="'.e(route('parental-consent.download', $upload->id)).'"', $panel);
        $this->assertStringContainsString('Read from the uploaded photo', $panel);
        $this->assertStringContainsString('All required fields completed', $panel);

        // A Grade 1 learner: the Grade 7 immunization does not apply to them,
        // and every other service on the letter is consented.
        $this->assertBox($panel, 'yes', HealthConsentForm::CONSENT_TEXT[HealthConsentForm::CONSENT_ALL]);
        // One row per box on the paper: nine services, three consent boxes,
        // the allergy box with its three kinds, and the illness box.
        // Only the nine services carry the service marker.
        $this->assertSame(9 + 3 + 5, substr_count($panel, '<li class="pcopy-row '));
        $this->assertSame(9, substr_count($panel, ' is-service"><span class="pcopy-label"><span class="pcopy-mark"'));
        $this->assertSame(8, substr_count($panel, '>Consented</span></li>'));
        $this->assertStringContainsString('>Not applicable</span></li>', $panel);

        // Each service by the letter's own printed name, never the English
        // short name, with its standing beside it.
        $this->assertService($panel, HealthConsentForm::SERVICES['checkup']['label'], 'consented', 'Consented');
        $this->assertService($panel, 'Pag Bitok / Worms', 'consented', 'Consented');
        $this->assertService($panel, HealthConsentForm::SERVICES['immunization']['children']['immunization_grade7'], 'not_applicable', 'Not applicable');
        foreach (HealthConsentForm::PLANNED_SERVICES as $english) {
            $this->assertStringNotContainsString('>'.e($english).'<', $panel);
        }

        // The allergy lines as written: "wala" stays on its line, unticked.
        $this->assertBox($panel, 'yes', HealthConsentForm::ALLERGY_TEXT['allergy_food']);
        $this->assertStringContainsString('<span class="pcopy-written">Shrimp</span>', $panel);
        $this->assertBox($panel, 'no', HealthConsentForm::ALLERGY_TEXT['allergy_medicine']);
        $this->assertStringContainsString('<span class="pcopy-written">wala</span>', $panel);
        $this->assertBox($panel, 'no', HealthConsentForm::ALLERGY_TEXT['other_illness']);
        $this->assertStringContainsString('Uploaded by Test Adviser on', $panel);
        $this->assertStringContainsString('Valid for all service cycles in SY '.ParentalConsentForm::currentSchoolYear(), $panel);
    }

    /** @test */
    public function the_upload_keeps_only_the_line_its_answer_calls_for(): void
    {
        $this->enrol();

        $this->withSession($this->adviserSession())
            ->post(route('parental-consent.store'), [
                'lrn' => '123456789012',
                'consent_type' => 'partial',
                'services' => ['checkup', 'dental'],
                'partial_exception' => 'Dental',
                'refused_reason' => 'Left over from another answer',
                'consent' => UploadedFile::fake()->create('sulat-pahibalo.jpg', 120, 'image/jpeg'),
            ])
            ->assertSessionHasNoErrors();

        $upload = ParentalConsentForm::latest('id')->firstOrFail();
        $this->assertSame('Dental', $upload->partial_exception);
        $this->assertNull($upload->refused_reason);

        // A service the letter does not list is refused.
        $this->withSession($this->adviserSession())
            ->post(route('parental-consent.store'), [
                'lrn' => '123456789012',
                'services' => ['not-a-service'],
                'consent' => UploadedFile::fake()->create('sulat-pahibalo.jpg', 120, 'image/jpeg'),
            ])
            ->assertSessionHasErrors('services.0');
        $this->assertSame(1, ParentalConsentForm::count());
    }

    /** A box on the drawn form, in the state given, with the printed words beside it. */
    private function assertBox(string $html, string $state, string $label): void
    {
        $this->assertMatchesRegularExpression(
            '/<span class="pcopy-text" lang="ceb">'.preg_quote(e($label), '/').'(?:<span class="pcopy-written">[^<]*<\/span>)?<\/span><\/span>'
                .'<span class="pcopy-std is-'.['yes' => 'ticked', 'no' => 'not_ticked', 'unclear' => 'unclear'][$state].'">/u',
            $html,
            'Expected "'.$label.'" to read '.$state.'.',
        );
    }

    /** A service row on the drawn form: marked as a service, its printed name, its standing. */
    private function assertService(string $html, string $label, string $standing, string $pill): void
    {
        $this->assertMatchesRegularExpression(
            '/<li class="pcopy-row is-'.$standing.' is-service"><span class="pcopy-label"><span class="pcopy-mark"[^>]*>.*?<\/span>'
                .'<span class="pcopy-text" lang="ceb">'.preg_quote(e($label), '/').'<\/span><\/span>'
                .'<span class="pcopy-std is-'.$standing.'">'.preg_quote(e($pill), '/').'<\/span><\/li>/u',
            $html,
            'Expected the service "'.$label.'" to read '.$pill.'.',
        );
    }

    /** One learner's View Details panel, as rendered into its template. */
    private function viewPanel(string $html, string $lrn): string
    {
        $this->assertSame(
            1,
            preg_match('#<template class="pc-view-source" data-view-for="'.$lrn.'">(.*?)</template>#s', $html, $m),
            'No View Details panel for '.$lrn.'.'
        );

        return $m[1];
    }

    /** @test */
    public function the_upload_modal_orders_medical_certificate_after_the_signed_form(): void
    {
        $this->enrol();

        $html = $this->flushSession()
            ->withSession($this->adviserSession())
            ->get(route('consent-forms.index'))
            ->assertOk()
            ->getContent();

        $student = strpos($html, 'id="pc_lrn"');
        $year = strpos($html, 'id="pc_school_year"');
        $answer = strpos($html, 'id="pc_consent_type"');
        $signedForm = strpos($html, 'id="pc_file"');
        $medCert = strpos($html, 'id="pc_med_cert"');
        $notes = strpos($html, 'id="pc_notes"');

        foreach ([$student, $year, $answer, $signedForm, $medCert, $notes] as $position) {
            $this->assertNotFalse($position);
        }

        // The dialog asks what the guardian answered again, after a spell
        // when it did not — without that, a filed form read as PENDING for
        // ever and the school could see a consent had come back but never
        // whether it said yes or no. It is **one optional select**, not the
        // old radio group.
        $this->assertStringContainsString('name="consent_type"', $html);
        $this->assertStringContainsString('Not recorded yet', $html);
        $this->assertStringNotContainsString('name="consent_type" required', $html);

        // The rest of the paper follows it — the services the letter lists,
        // the exception or reason line the answer calls for (shown by the
        // answer, so hidden until one is chosen) and the allergy lines — so
        // View Details can read the form back as it was signed.
        $services = strpos($html, '<input type="checkbox" name="services[]"');
        $partial = strpos($html, 'id="pcPartialField" hidden');
        $refused = strpos($html, 'id="pcRefusedField" hidden');
        $allergies = strpos($html, 'name="allergy_food_detail"');
        $this->assertSame(count(HealthConsentForm::PLANNED_SERVICES), substr_count($html, '<input type="checkbox" name="services[]"'));

        $this->assertTrue($student < $year, 'Select Student comes first.');
        $this->assertTrue($year < $signedForm, 'School Year precedes the signed form upload.');
        // The answer is read off the photo — by the adviser, or by the consent
        // reader where one is set up — so the photo is attached first.
        $this->assertTrue($signedForm < $answer, 'The signed form precedes the guardian answer.');
        $this->assertTrue($answer < $services && $services < $partial && $partial < $refused && $refused < $allergies, 'What the paper says follows the answer.');
        $this->assertTrue($allergies < $medCert, 'Medical Certificate follows what the paper says.');
        $this->assertTrue($medCert < $notes, 'Additional Notes come last.');

        $this->assertStringContainsString('Click to upload Medical Certificate', $html);
        $this->assertStringContainsString('Any additional notes about this consent form', $html);
    }

    /** @test */
    public function a_medical_certificate_and_notes_are_stored_with_the_consent(): void
    {
        $this->enrol();

        $this->withSession($this->adviserSession())
            ->post(route('parental-consent.store'), [
                'lrn' => '123456789012',
                'consent_type' => 'full',
                'notes' => 'Form signed by mother',
                'consent' => UploadedFile::fake()->create('sulat-pahibalo.pdf', 40, 'application/pdf'),
                'medical_certificate' => UploadedFile::fake()->create('med-cert.pdf', 30, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $record = StudentHealthRecord::where('student_id', '123456789012')->first();
        $upload = ParentalConsentForm::where('student_health_record_id', $record->id)->first();

        $this->assertNotNull($upload->med_cert_path);
        $this->assertSame('med-cert.pdf', $upload->med_cert_original_name);
        $this->assertSame('Form signed by mother', $upload->notes);

        // Uploading a certificate is itself proof one was attached.
        $this->assertTrue($upload->medical_cert_attached);

        // The two files are stored separately, never overwriting each other.
        $this->assertNotSame($upload->file_path, $upload->med_cert_path);

        $this->flushSession()
            ->withSession($this->adviserSession())
            ->get(route('consent-forms.index'))
            ->assertOk()
            ->assertSee('Form signed by mother', false);
    }

    /** @test */
    public function a_medical_certificate_of_the_wrong_type_is_rejected(): void
    {
        $this->enrol();

        $this->withSession($this->adviserSession())
            ->post(route('parental-consent.store'), [
                'lrn' => '123456789012',
                'consent_type' => 'full',
                'consent' => UploadedFile::fake()->create('sulat-pahibalo.pdf', 40, 'application/pdf'),
                'medical_certificate' => UploadedFile::fake()->create('virus.exe', 20, 'application/x-msdownload'),
            ])
            ->assertSessionHasErrors('medical_certificate');

        $record = StudentHealthRecord::where('student_id', '123456789012')->first();
        $this->assertNull(ParentalConsentForm::where('student_health_record_id', $record->id)->first());
    }

    /** @test */
    public function a_recorded_answer_with_no_signed_form_is_not_reported_as_consented(): void
    {
        $this->enrol();

        // `consent` is nullable on upload, so a record can carry an answer with
        // no scan behind it. That must not read as Approved.
        $record = StudentHealthRecord::where('student_id', '123456789012')->first();
        ParentalConsentForm::create([
            'student_health_record_id' => $record->id,
            'program_type' => 'Deworming',
            'school_year' => ParentalConsentForm::currentSchoolYear(),
            'consent_type' => 'full',
            'file_path' => null,
            'uploaded_by_name' => 'Test Adviser',
        ]);

        $response = $this->flushSession()
            ->withSession($this->adviserSession())
            ->get(route('consent-forms.index'));

        $response->assertOk();
        $response->assertSee('data-status="pending"', false);
        $response->assertDontSee('data-status="approved"', false);

        // The rate must not count it, and it belongs in Pending Upload.
        $response->assertSee('0%');

        // The recorded answer is still surfaced, just never as consent.
        $panel = $this->viewPanel($response->getContent(), '123456789012');
        $this->assertStringContainsString('<b>No form yet.</b>', $panel);
        $this->assertStringContainsString('Recorded as <b>Full Consent</b>, but the signed form has not been uploaded.', $panel);
    }

    /** @test */
    public function a_declined_answer_with_no_signed_form_is_also_pending(): void
    {
        $this->enrol();

        $record = StudentHealthRecord::where('student_id', '123456789012')->first();
        ParentalConsentForm::create([
            'student_health_record_id' => $record->id,
            'program_type' => 'Deworming',
            'school_year' => ParentalConsentForm::currentSchoolYear(),
            'consent_type' => 'refused',
            'file_path' => null,
            'uploaded_by_name' => 'Test Adviser',
        ]);

        $this->flushSession()
            ->withSession($this->adviserSession())
            ->get(route('consent-forms.index'))
            ->assertOk()
            ->assertSee('data-status="pending"', false)
            ->assertDontSee('data-status="declined"', false);
    }

    /** @test */
    public function a_parent_signed_online_form_needs_no_scan_to_count(): void
    {
        $this->enrol();

        // The e-signature is itself the document, so this one has no upload
        // and must still report as consented.
        HealthConsentForm::create([
            'institution_id' => $this->institution->id,
            'student_lrn' => '123456789012',
            'school_year' => HealthConsentForm::currentSchoolYear(),
            'student_name' => 'Dela Cruz, Juan A.',
            'division' => HealthConsentForm::DEFAULT_DIVISION,
            'school_name' => 'Test School',
            'school_address' => HealthConsentForm::DEFAULT_SCHOOL_ADDRESS,
            'status' => HealthConsentForm::STATUS_SIGNED,
            'consent_choice' => HealthConsentForm::CONSENT_ALL,
            'services' => [],
            'signed_at' => now(),
        ]);

        $this->flushSession()
            ->withSession($this->adviserSession())
            ->get(route('consent-forms.index'))
            ->assertOk()
            ->assertSee('data-status="approved"', false)
            ->assertSee('100%');
    }

    /** @test */
    public function the_page_only_lists_the_advisers_own_class(): void
    {
        $this->enrol();

        $otherSchool = Institution::create(['name' => 'Other School', 'status' => 'active']);

        $this->flushSession()
            ->withSession(array_merge($this->adviserSession(), [
                'active_institution_id' => $otherSchool->id,
            ]))
            ->get(route('consent-forms.index'))
            ->assertOk()
            ->assertDontSee('123456789012');
    }

    /** @test */
    public function the_consent_page_uses_the_shared_adviser_sidebar(): void
    {
        $response = $this->withSession($this->adviserSession())
            ->get(route('consent-forms.index'));

        $response->assertOk();
        $response->assertSee('asb-sidebar', false);
        $response->assertSee('My Students');
        $response->assertSee('Feeding Status');

        // Same page header as Feeding Status: class + school year.
        $response->assertSee('ms-page-title', false);
        $response->assertSee('Grade 1 / Sampaguita &middot; School Year '.HealthConsentForm::currentSchoolYear(), false);
    }

    /**
     * The adviser filters by the parent's answer, in the nurse's words.
     *
     * The same two questions both desks ask first — agreed, or did not — so
     * one answer does not read as two different things on two screens.
     * "Agreed" covers the parent who agreed *except* for certain services,
     * and the precise answers stay below it. "Awaiting response" is the
     * adviser's own: their list is one row per learner, so the ones still to
     * reply are the chase list, and the nurse never sees those at all.
     */
    #[Test]
    public function the_adviser_filters_the_list_by_the_parents_answer(): void
    {
        $this->enrol();

        $html = $this->flushSession()
            ->withSession($this->adviserSession())
            ->get(route('consent-forms.index'))
            ->assertOk()
            ->getContent();

        foreach ([
            'all' => 'All students',
            'agreed' => 'Agreed to the consent',
            'declined' => 'Did not agree',
            'approved' => 'Agreed to all services',
            'partial' => 'Agreed, with exceptions',
            'pending' => 'Awaiting response',
        ] as $value => $label) {
            $this->assertStringContainsString('<option value="'.$value.'"', $html);
            $this->assertStringContainsString($label, $html);
        }

        // "Agreed" is a list of standings, not a fourth standing of its own —
        // one line matches the grouped choice and the precise ones alike.
        $this->assertStringContainsString("['approved', 'partial']", $html);

        // Each row carries the standing the filter reads.
        $this->assertStringContainsString('data-status=', $html);
    }

    /**
     * Uploading the guardian's signed form shows on the learner's own profile.
     *
     * The profile's Consent tab read the digital Sulat-Pahibalo alone, so an
     * adviser who had just filed the guardian's filled-in paper form was told
     * the consent was still Pending on that very learner's page — the one place
     * they would go to check.
     */
    /** @test */
    public function an_uploaded_consent_shows_on_the_students_profile(): void
    {
        $this->enrol();

        $this->flushSession()->withSession($this->adviserSession())
            ->post(route('parental-consent.store'), [
                'lrn' => '123456789012',
                'consent' => UploadedFile::fake()->create('sulat-pahibalo.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $html = $this->flushSession()->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser.student-profile', '123456789012'))
            ->assertOk()
            ->getContent();

        $meta = $this->profileMeta($html);

        // The row payload the Consent tab renders from carries the return.
        $this->assertTrue($meta['consent_detail']['returned']);
        $this->assertStringContainsString('Signed form returned by guardian', $html);
        $this->assertStringContainsString($this->today(), $html);
        $this->assertStringContainsString('Test Adviser', $html);

        // The dialog says so in words, and says the answer is not keyed in —
        // the upload dialog no longer asks for it.
        $this->assertStringContainsString('has filled up and returned this consent', $html);
        $this->assertFalse($meta['consent_detail']['answer_recorded']);

        // And the badge reads Returned, not Pending.
        $this->assertSame('returned', $meta['consent_badge']);
    }

    /**
     * Returned is not approved: an answer nobody has read authorises nothing,
     * so the standing a gate reads stays pending while the badge says Returned.
     */
    /** @test */
    public function a_returned_form_with_no_recorded_answer_authorises_nothing(): void
    {
        $this->enrol();

        $this->flushSession()->withSession($this->adviserSession())
            ->post(route('parental-consent.store'), [
                'lrn' => '123456789012',
                'consent' => UploadedFile::fake()->create('sulat-pahibalo.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $html = $this->flushSession()->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser.student-profile', '123456789012'))
            ->assertOk()
            ->getContent();

        $meta = $this->profileMeta($html);

        $this->assertSame('pending', $meta['consent']);
        $this->assertSame('returned', $meta['consent_badge']);
    }

    /**
     * An answer the adviser did key in is the answer, and the profile reports it
     * rather than the returned-but-unread wording.
     */
    /** @test */
    public function a_returned_form_carrying_an_answer_reports_it_on_the_profile(): void
    {
        $this->enrol();

        $this->flushSession()->withSession($this->adviserSession())
            ->post(route('parental-consent.store'), [
                'lrn' => '123456789012',
                'consent_type' => 'full',
                'consent' => UploadedFile::fake()->create('sulat-pahibalo.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $html = $this->flushSession()->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser.student-profile', '123456789012'))
            ->assertOk()
            ->getContent();

        $meta = $this->profileMeta($html);

        $this->assertStringContainsString('Consented to all health services', $html);
        $this->assertTrue($meta['consent_detail']['answer_recorded']);
        $this->assertSame('approved', $meta['consent']);
        $this->assertSame('approved', $meta['consent_badge']);
    }

    /**
     * My Students prints the same answer as the profile does.
     *
     * A column reading Pending beside a profile reading Returned is two screens
     * disagreeing about one child, so both read `consent_badge` and both take
     * their wording from ConsentStanding::LABELS.
     */
    /** @test */
    public function the_my_students_column_agrees_with_the_profile(): void
    {
        $this->enrol();

        $this->flushSession()->withSession($this->adviserSession())
            ->post(route('parental-consent.store'), [
                'lrn' => '123456789012',
                'consent' => UploadedFile::fake()->create('sulat-pahibalo.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $this->flushSession()->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser', ['tab' => 'saved']))
            ->assertOk()
            ->assertSee('<span class="ms-badge ms-consent-returned">Returned</span>', false)
            ->assertDontSee('<span class="ms-badge ms-consent-pending">', false);
    }

    /** With nothing uploaded the profile says so, rather than leaving a dash. */
    /** @test */
    public function a_learner_with_no_uploaded_form_reports_none(): void
    {
        $this->enrol();

        $html = $this->flushSession()->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser.student-profile', '123456789012'))
            ->assertOk()
            ->getContent();

        $meta = $this->profileMeta($html);

        $this->assertFalse($meta['consent_detail']['returned']);
        $this->assertSame('pending', $meta['consent_badge']);
        $this->assertStringContainsString('Not received', $html);
    }

    /** The date the roster meta stamps an upload with. */
    private function today(): string
    {
        return now()->toDateString();
    }

    /**
     * The payload the profile's Consent tab renders from.
     *
     * The page hands its whole roster-meta entry to the browser as JSON, so the
     * test reads the values the tab reads rather than matching the markup the
     * script writes out of them.
     *
     * @return array<string, mixed>
     */
    private function profileMeta(string $html): array
    {
        $this->assertSame(
            1,
            preg_match('/const STUDENT_PROFILE_META = (.+);$/m', $html, $m),
            'The profile must hand its meta to the browser.'
        );

        $meta = json_decode(trim($m[1]), true);
        $this->assertIsArray($meta, 'The meta payload must be valid JSON.');

        return $meta;
    }

    /**
     * The uploaded form is on the learner's profile, not just described there.
     *
     * The Consent tab said a form had been returned and offered nothing to
     * open: the meta carried the date and the uploader but never the document.
     * It is read off the same row the upload wrote — no second copy and no
     * duplicated path.
     */
    /** @test */
    public function the_uploaded_consent_form_is_shown_on_the_students_profile(): void
    {
        $this->enrol();

        $this->flushSession()->withSession($this->adviserSession())
            ->post(route('parental-consent.store'), [
                'lrn' => '123456789012',
                'consent' => UploadedFile::fake()->create('sulat-pahibalo.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $upload = ParentalConsentForm::latest('id')->firstOrFail();

        $html = $this->flushSession()->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser.student-profile', '123456789012'))
            ->assertOk()
            ->getContent();

        $meta = $this->profileMeta($html);

        // The filed name and the one audited route that serves it.
        $this->assertSame('sulat-pahibalo.pdf', $meta['consent_detail']['file_name']);
        $this->assertSame(
            route('parental-consent.download', $upload->id),
            $meta['consent_detail']['file_url']
        );

        // And the panel has somewhere to put them.
        $this->assertStringContainsString('id="vpConsentFile"', $html);
    }

    /** With nothing uploaded there is no document, and no link to a missing one. */
    /** @test */
    public function a_learner_with_no_uploaded_form_offers_no_document(): void
    {
        $this->enrol();

        $html = $this->flushSession()->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser.student-profile', '123456789012'))
            ->assertOk()
            ->getContent();

        $meta = $this->profileMeta($html);
        $this->assertNull($meta['consent_detail']['file_name']);
        $this->assertNull($meta['consent_detail']['file_url']);
    }

    /**
     * The adviser who filed the form can open it again.
     *
     * The download was clinic-only, so the one desk that scans a guardian's
     * form and uploads it could never check its own filing — a link on their
     * profile would have 403'd.
     */
    /** @test */
    public function the_learners_own_adviser_can_open_the_uploaded_form(): void
    {
        $this->enrol();

        $this->flushSession()->withSession($this->adviserSession())
            ->post(route('parental-consent.store'), [
                'lrn' => '123456789012',
                'consent' => UploadedFile::fake()->create('sulat-pahibalo.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $upload = ParentalConsentForm::latest('id')->firstOrFail();

        $this->flushSession()->withSession($this->adviserSession())
            ->get(route('parental-consent.download', $upload->id))
            ->assertOk();

        // The clinic still reads it, as before.
        $this->flushSession()->withSession([
            'active_role' => 'school_nurse',
            'active_name' => 'Ana Reyes',
            'active_institution_id' => $this->institution->id,
        ])->get(route('parental-consent.download', $upload->id))->assertOk();
    }

    /**
     * An adviser is scoped twice, and the second lock holds here too: another
     * class's consent form is a document about a child they do not teach.
     */
    /** @test */
    public function another_classes_consent_form_is_not_served_to_an_adviser(): void
    {
        $this->enrol();

        $this->flushSession()->withSession($this->adviserSession())
            ->post(route('parental-consent.store'), [
                'lrn' => '123456789012',
                'consent' => UploadedFile::fake()->create('sulat-pahibalo.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $upload = ParentalConsentForm::latest('id')->firstOrFail();

        $other = $this->adviserSession();
        $other['assigned_section'] = 'Rosal';

        $this->flushSession()->withSession($other)
            ->get(route('parental-consent.download', $upload->id))
            ->assertNotFound();

        // And a role with no business here is still refused outright.
        $this->flushSession()->withSession([
            'active_role' => 'feeding_coor',
            'active_name' => 'Coordinator',
            'active_institution_id' => $this->institution->id,
        ])->get(route('parental-consent.download', $upload->id))->assertForbidden();
    }

    /** A re-upload shows the latest form, and the rows are kept as history. */
    /** @test */
    public function a_reupload_shows_the_latest_form(): void
    {
        $this->enrol();

        foreach (['first-try.pdf', 'corrected.pdf'] as $name) {
            $this->flushSession()->withSession($this->adviserSession())
                ->post(route('parental-consent.store'), [
                    'lrn' => '123456789012',
                    'consent' => UploadedFile::fake()->create($name, 40, 'application/pdf'),
                ])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(2, ParentalConsentForm::count(), 'Both uploads are kept.');

        $html = $this->flushSession()->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser.student-profile', '123456789012'))
            ->assertOk()
            ->getContent();

        $meta = $this->profileMeta($html);
        $this->assertSame('corrected.pdf', $meta['consent_detail']['file_name']);
    }
}
