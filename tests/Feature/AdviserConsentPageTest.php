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
        $response->assertSee('sulat_pahibalo_dela_cruz.pdf', false);
        $response->assertSee('No deworming', false);
        $response->assertSee('status_label', false);

        // The scan itself is never linked — advisers cannot download consents.
        $response->assertDontSee('parental-consent/', false);
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
        $status = strpos($html, 'name="consent_type"');
        $signedForm = strpos($html, 'id="pc_file"');
        $medCert = strpos($html, 'id="pc_med_cert"');
        $notes = strpos($html, 'id="pc_notes"');

        foreach ([$student, $year, $status, $signedForm, $medCert, $notes] as $position) {
            $this->assertNotFalse($position);
        }

        $this->assertTrue($student < $year, 'Select Student comes first.');
        $this->assertTrue($year < $status, 'School Year precedes Consent Status.');
        $this->assertTrue($status < $signedForm, 'Consent Status precedes the signed form upload.');
        $this->assertTrue($signedForm < $medCert, 'Medical Certificate follows the signed form.');
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
        $response->assertSee('recorded_response', false);
        $response->assertSee('Full Consent', false);
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
}
