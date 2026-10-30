<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;
use App\Support\ConsentFormScanner;
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

            public function scan(UploadedFile $photo): array
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

    #[Test]
    public function a_manual_answer_cannot_retain_a_conflicting_scan_summary(): void
    {
        $photo = $this->photo();
        $read = $this->scan($photo);
        $this->upload($photo, $read['scan_token'], ['consent_type' => 'full'])->assertSessionHasErrors('scan_token');
        $this->assertSame(0, ParentalConsentForm::count());
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
    public function manual_and_feeding_uploads_still_work_before_the_scan_storage_update(): void
    {
        Schema::table('parental_consent_forms', fn ($table) => $table->dropColumn('scan_result'));
        SchemaCache::flush();

        foreach ([ParentalConsentForm::PROGRAM_HEALTH_SERVICES, ParentalConsentForm::PROGRAM_FEEDING] as $program) {
            $this->withSession($this->adviserSession())->from(route('consent-forms.index'))
                ->post(route('parental-consent.store'), [
                    'lrn' => '123456789012', 'program' => $program,
                    'consent' => $this->photo(), 'consent_type' => 'full',
                ])->assertRedirect(route('consent-forms.index'))->assertSessionHasNoErrors();

            $upload = ParentalConsentForm::where('program_type', $program)->sole();
            $this->assertSame('full', $upload->consent_type);
            $this->assertNull($upload->scan_result);
            Storage::disk('local')->assertExists($upload->file_path);
        }

        $this->assertSame(2, ParentalConsentForm::count());
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
