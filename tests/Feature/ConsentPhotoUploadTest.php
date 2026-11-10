<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;
use App\Support\ConsentFormScanner;
use App\Support\ConsentStanding;
use App\Support\SchemaCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConsentPhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->school = Institution::create(['name' => 'Test School', 'status' => 'active']);
        StudentHealthRecord::create([
            'student_id' => '123456789012',
            'institution_id' => $this->school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'section' => 'Grade 8 / Mahogany',
            'student_name' => 'Torres, Angel Grace',
            'student_details' => [
                'last_name' => 'Torres', 'first_name' => 'Angel Grace',
                'grade_level' => 'Grade 8', 'section' => 'Mahogany',
            ],
        ]);
    }

    private function adviserSession(): array
    {
        return [
            'active_role' => 'class_adviser', 'active_name' => 'Test Adviser',
            'active_username' => 'adviser1', 'active_institution_id' => $this->school->id,
            'active_school_name' => $this->school->name,
            'assigned_grade_level' => 'Grade 8', 'assigned_section' => 'Mahogany',
        ];
    }

    private function photo(string $extra = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('signed.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aS6sAAAAASUVORK5CYII='
        ).$extra);
    }

    private function draft(array $changes = []): array
    {
        return array_replace([
            'consent_choice' => 'specific', 'consent_reason' => '',
            'student_name' => 'Angel Grace Torres', 'parent_guardian_name' => 'Jocelyn Torres',
            'services' => array_fill_keys(array_keys(ConsentFormScanner::serviceKeys()), 'yes'),
            'written' => [
                'consent_exceptions' => ['text' => 'Deworming', 'readable' => true, 'confident' => true],
                'refusal_reason' => ['text' => '', 'readable' => true, 'confident' => false],
                'allergy_food' => ['text' => 'Peanuts', 'box' => 'yes', 'readable' => true, 'confident' => true],
                'allergy_medicine' => ['text' => '', 'box' => 'no', 'readable' => true, 'confident' => false],
                'prev_immunization' => ['text' => '', 'box' => 'no', 'readable' => true, 'confident' => false],
                'other_illness' => ['text' => 'Asthma', 'box' => 'yes', 'readable' => true, 'confident' => true],
            ],
            'field_notes' => [], 'signature_present' => true, 'learner_mismatch' => false,
            'parent_name_present' => true, 'form_complete' => true,
            'warnings' => [], 'unclear' => [], 'needs_review' => false, 'unreadable' => false,
        ], $changes);
    }

    private function scan(UploadedFile $photo, array $changes = []): array
    {
        $this->swap(ConsentFormScanner::class, new class($this->draft($changes)) extends ConsentFormScanner
        {
            public int $reads = 0;

            public function __construct(private array $draft) {}

            public function isAvailable(): bool
            {
                return true;
            }

            public function scan(UploadedFile $photo, array $closeups = []): array
            {
                $this->reads++;

                return $this->draft;
            }
        });

        return $this->withSession($this->adviserSession())->postJson(route('consent-forms.scan'), [
            'photo' => $photo, 'lrn' => '123456789012',
        ])->assertOk()->assertJsonPath('saved', false)->json();
    }

    private function upload(UploadedFile $photo, string $token, array $changes = [])
    {
        return $this->withSession($this->adviserSession())->from(route('consent-forms.index'))
            ->post(route('parental-consent.store'), array_replace([
                'lrn' => '123456789012', 'consent' => $photo, 'consent_type' => 'partial',
                'scan_token' => $token, 'answer_from_scan' => '1', 'answer_confirmed' => '1',
            ], $changes));
    }

    #[Test]
    public function the_confirmed_photo_reading_survives_upload_and_is_encrypted(): void
    {
        $photo = $this->photo();
        $read = $this->scan($photo);
        $this->assertCount(5, $read['summary']['groups']['consented']);
        $this->assertCount(3, $read['summary']['groups']['declined']);
        $this->assertCount(1, $read['summary']['groups']['not_applicable']);

        $this->upload($photo, $read['scan_token'])->assertSessionHasNoErrors()
            ->assertSessionHas('consent_detail_lrn', '123456789012');

        $upload = ParentalConsentForm::sole();
        $this->assertSame($read['draft'], $upload->scan_result);
        $this->assertSame('Deworming', $upload->partial_exception);
        $this->assertTrue($upload->allergy_food);
        $this->assertSame('Peanuts', $upload->allergy_food_detail);
        $this->assertTrue($upload->has_other_illness);
        $this->assertSame('Asthma', $upload->other_illness_detail);
        $raw = DB::table('parental_consent_forms')->value('scan_result');
        $this->assertStringNotContainsString('Peanuts', $raw);
        $this->assertSame($read['draft'], json_decode(Crypt::decryptString($raw), true));

        $response = $this->withSession($this->adviserSession())->get(route('consent-forms.index'))->assertOk();
        $row = $response->viewData('rows')->sole();
        $this->assertSame($read['summary'], $row['photo_summary']);
        $this->assertSame(route('parental-consent.download', $upload->id), $row['file_url']);
        $this->assertTrue($row['file_is_image']);
        $this->assertSame('signed.png', $row['file_name']);
        $this->assertSame('Test Adviser', $row['uploaded_by']);
        $this->assertSame($upload->created_at->format('M j, Y'), $row['uploaded_on']);
        $this->assertSame('Grade 8 – Mahogany', $row['class_label']);
        $response->assertDontSee('parental-consents/', false);

        $download = $this->withSession($this->adviserSession())->get($row['file_url'])->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        $this->assertStringStartsWith('inline;', $download->headers->get('Content-Disposition'));
        $this->assertSame(file_get_contents($photo->getRealPath()), $download->getContent());
        $this->assertSame(1, app(ConsentFormScanner::class)->reads, 'Opening a saved reading made another model call.');

        $otherSchool = Institution::create(['name' => 'Other school', 'status' => 'active']);
        $this->withSession(array_replace($this->adviserSession(), ['active_institution_id' => $otherSchool->id]))
            ->get($row['file_url'])->assertNotFound();
    }

    #[Test]
    public function replacing_the_photo_invalidates_its_saved_reading(): void
    {
        $read = $this->scan($this->photo());
        $this->upload($this->photo('different photo'), $read['scan_token'])->assertSessionHasErrors('scan_token');
        $this->assertSame(0, ParentalConsentForm::count());
    }

    #[Test]
    public function an_expired_or_modified_token_cannot_file_a_reading(): void
    {
        $photo = $this->photo();
        $read = $this->scan($photo);
        $this->upload($photo, $read['scan_token'].'changed')->assertSessionHasErrors('scan_token');
        $this->travel(61)->minutes();
        $this->upload($photo, $read['scan_token'])->assertSessionHasErrors('scan_token');
        $this->assertSame(0, ParentalConsentForm::count());
    }

    #[Test]
    public function a_form_naming_another_learner_cannot_store_its_reading(): void
    {
        $photo = $this->photo();
        $read = $this->scan($photo, ['student_name' => 'Juan Cruz']);
        $this->assertTrue($read['draft']['learner_mismatch']);
        $this->upload($photo, $read['scan_token'], ['consent_type' => null])->assertSessionHasErrors('consent');
        $this->assertSame(0, ParentalConsentForm::count());
    }

    #[Test]
    public function a_verified_reading_still_requires_confirmation_when_the_flag_is_removed(): void
    {
        $photo = $this->photo();
        $read = $this->scan($photo);
        $this->upload($photo, $read['scan_token'], ['answer_from_scan' => '0', 'answer_confirmed' => null])
            ->assertSessionHasErrors('answer_confirmed');
        $this->assertSame(0, ParentalConsentForm::count());
    }

    /**
     * Nothing is saved from the reader alone: the adviser may correct any of
     * it, the answer included. The correction is filed with the reading it
     * corrected, and the review log keeps both, for each service and field,
     * so edited fields can be counted later.
     */
    #[Test]
    public function an_answer_the_adviser_corrects_is_filed_and_the_review_logs_both_values(): void
    {
        $photo = $this->photo();
        $read = $this->scan($photo);
        $before = AuditLog::count();

        $this->upload($photo, $read['scan_token'], [
            'consent_type' => 'full', 'answer_from_scan' => '0',
            'services_confirmed' => '1', 'services' => ['checkup', 'dental'],
            'allergy_food_detail' => 'Peanuts, shrimp', 'form_complete' => '1',
        ])->assertSessionHasNoErrors();

        $upload = ParentalConsentForm::sole();
        $this->assertSame('full', $upload->consent_type);
        $this->assertSame($read['draft'], $upload->scan_result);
        $this->assertFalse((bool) $upload->incomplete);

        $review = $upload->scan_review;
        $this->assertSame(['vlm' => 'partial', 'final' => 'full', 'edited' => true], $review['fields']['consent_type']);
        // "Deworming" excepted: the reader started Checkup Consented and the
        // deworming rows Excluded; the adviser left Checkup and filed the
        // deworming rows Not offered.
        $this->assertSame(['vlm' => 'consented', 'final' => 'consented', 'edited' => false], $review['services']['checkup']);
        $this->assertSame(['vlm' => 'excluded', 'final' => 'not_offered', 'edited' => true], $review['services']['deworming_worms']);
        $this->assertSame(['vlm' => 'consented', 'final' => 'not_offered', 'edited' => true], $review['services']['vitamin_a']);
        // Grade 7 MR, Td is not a Grade 8 learner's: Not offered on both sides.
        $this->assertSame(['vlm' => 'not_offered', 'final' => 'not_offered', 'edited' => false], $review['services']['immunization_grade7']);
        $this->assertSame(['vlm' => 'Peanuts', 'final' => 'Peanuts, shrimp', 'edited' => true], $review['fields']['allergy_food_detail']);
        $this->assertSame(['vlm' => 'Asthma', 'final' => 'Asthma', 'edited' => false], $review['fields']['other_illness_detail']);
        // The exceptions belonged to the partial answer the adviser replaced.
        $this->assertSame(['vlm' => 'Deworming', 'final' => '', 'edited' => true], $review['fields']['partial_exception']);
        $this->assertSame(['vlm' => true, 'final' => true, 'edited' => false], $review['fields']['form_complete']);
        $this->assertSame($review['services_edited'] + $review['fields_edited'], $review['edited']);
        $this->assertSame(3, $review['fields_edited']);

        // Encrypted at rest: the parent's handwriting is in it.
        $this->assertStringNotContainsString('Peanuts', (string) DB::table('parental_consent_forms')->value('scan_review'));

        $this->assertTrue(AuditLog::where('id', '>', $before)
            ->get()
            ->contains(fn (AuditLog $log): bool => $log->description === 'Consent answer chosen by the class adviser in place of the photo reading'));
    }

    /** A reading the adviser filed unchanged logs no edit. */
    #[Test]
    public function a_reading_filed_as_read_logs_no_edit(): void
    {
        $photo = $this->photo();
        $read = $this->scan($photo);

        $this->upload($photo, $read['scan_token'], [
            'services_confirmed' => '1',
            'services' => $read['service_proposal']['partial']['approved'],
            'excepted_services' => $read['service_proposal']['partial']['excluded'],
            'form_complete' => '1',
        ])->assertSessionHasNoErrors();

        $review = ParentalConsentForm::sole()->scan_review;
        $this->assertSame(0, $review['edited']);
        $this->assertSame(['vlm' => 'partial', 'final' => 'partial', 'edited' => false], $review['fields']['consent_type']);
    }

    /**
     * Missing the parent's name or signature: the form is saved as incomplete,
     * its answer kept, and no service is given until a complete form is on
     * file — on the clinic's list, the adviser's badge and the deworming gate.
     */
    #[Test]
    public function a_form_missing_the_parents_name_or_signature_is_filed_as_incomplete_and_gives_no_service(): void
    {
        $photo = $this->photo();
        $read = $this->scan($photo, ['signature_present' => false, 'form_complete' => false]);
        $this->assertFalse($read['summary']['complete']);
        $this->assertContains(
            ['section' => 'signature', 'subject' => 'Parent\'s name and signature', 'message' => ConsentFormScanner::FORM_INCOMPLETE.'. No handwritten signature was found at the parent\'s signature line.'],
            $read['summary']['checks'],
        );

        $this->upload($photo, $read['scan_token'], [
            'services_confirmed' => '1',
            'services' => $read['service_proposal']['partial']['approved'],
            'excepted_services' => $read['service_proposal']['partial']['excluded'],
            'form_complete' => '0',
        ])->assertSessionHasNoErrors();

        $upload = ParentalConsentForm::sole();
        $this->assertTrue($upload->incomplete);
        $this->assertSame('partial', $upload->consent_type);
        $this->assertSame(['vlm' => false, 'final' => false, 'edited' => false], $upload->scan_review['fields']['form_complete']);

        $this->assertSame(ConsentStanding::PENDING, ConsentStanding::for(null, $upload));
        $this->assertSame(ConsentStanding::INCOMPLETE, ConsentStanding::badge(null, $upload));
        $answer = ConsentStanding::answer(null, $upload);
        $this->assertSame(ConsentStanding::SERVICE_INCOMPLETE, ConsentStanding::forService('checkup', 8, true, $answer));
        $this->assertSame(ConsentStanding::SERVICE_INCOMPLETE, ConsentStanding::overall(true, $answer));
        // A service the learner is never given still reads Not offered.
        $this->assertSame(ConsentStanding::SERVICE_NOT_APPLICABLE, ConsentStanding::forService('immunization_grade7', 8, true, $answer));

        $this->withSession($this->adviserSession())->getJson(route('api.student-consent-status', ['lrn' => '123456789012']))
            ->assertOk()->assertJsonPath('incomplete', true);
    }

    /** The adviser's tick overrules a reader that missed the signature. */
    #[Test]
    public function the_adviser_can_confirm_a_signature_the_reader_missed(): void
    {
        $photo = $this->photo();
        $read = $this->scan($photo, ['signature_present' => false, 'form_complete' => false]);

        $this->upload($photo, $read['scan_token'], ['form_complete' => '1'])->assertSessionHasNoErrors();

        $upload = ParentalConsentForm::sole();
        $this->assertFalse((bool) $upload->incomplete);
        $this->assertSame(['vlm' => false, 'final' => true, 'edited' => true], $upload->scan_review['fields']['form_complete']);
        $this->assertSame(ConsentStanding::PARTIAL, ConsentStanding::for(null, $upload));
    }

    #[Test]
    public function the_photo_reading_belongs_to_the_adviser_who_requested_it(): void
    {
        $photo = $this->photo();
        $read = $this->scan($photo);
        $this->withSession(array_replace($this->adviserSession(), ['active_username' => 'another_adviser']))
            ->post(route('parental-consent.store'), [
                'lrn' => '123456789012', 'consent' => $photo, 'consent_type' => 'partial',
                'scan_token' => $read['scan_token'], 'answer_confirmed' => '1',
            ])->assertSessionHasErrors('scan_token');
        $this->assertSame(0, ParentalConsentForm::count());
    }

    #[Test]
    public function scanned_health_details_are_preserved_without_truncation(): void
    {
        $photo = $this->photo();
        $written = $this->draft()['written'];
        $written['allergy_food']['text'] = str_repeat('Detailed parent history. ', 15).'Also allergic to peanuts.';
        $written['consent_exceptions']['text'] = str_repeat('Parent specifies the following exception. ', 15).'Deworming';
        $read = $this->scan($photo, ['written' => $written]);
        $this->upload($photo, $read['scan_token'])->assertSessionHasNoErrors();

        $upload = ParentalConsentForm::sole();
        $this->assertSame($written['allergy_food']['text'], $upload->allergy_food_detail);
        $this->assertSame($written['consent_exceptions']['text'], $upload->partial_exception);
    }

    #[Test]
    public function manual_uploads_still_work_before_the_scan_storage_update(): void
    {
        Schema::table('parental_consent_forms', fn ($table) => $table->dropColumn('scan_result'));
        SchemaCache::flush();

        $this->withSession($this->adviserSession())->from(route('consent-forms.index'))
            ->post(route('parental-consent.store'), [
                'lrn' => '123456789012',
                'consent' => $this->photo(), 'consent_type' => 'full',
            ])->assertRedirect(route('consent-forms.index'))->assertSessionHasNoErrors();

        $upload = ParentalConsentForm::sole();
        $this->assertSame(ParentalConsentForm::PROGRAM_HEALTH_SERVICES, $upload->program_type);
        $this->assertSame('full', $upload->consent_type);
        $this->assertNull($upload->scan_result);
        Storage::disk('local')->assertExists($upload->file_path);
    }

    #[Test]
    public function a_scan_storage_update_is_reported_before_any_uploaded_file_is_saved(): void
    {
        Schema::table('parental_consent_forms', fn ($table) => $table->dropColumn('scan_result'));
        SchemaCache::flush();
        $photo = $this->photo();
        $read = $this->scan($photo);

        $this->upload($photo, $read['scan_token'], ['medical_certificate' => $this->photo()])
            ->assertRedirect(route('consent-forms.index'))
            ->assertSessionHasErrors([
                'consent' => 'The consent photo reading cannot be saved yet. Please contact the system administrator to finish the consent update.',
            ]);

        $this->assertSame(0, ParentalConsentForm::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
