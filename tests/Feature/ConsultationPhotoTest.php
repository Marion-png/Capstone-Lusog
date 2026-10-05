<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\ConsultationPhoto;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Photographs on a consultation — a cut, a rash, a swelling.
 *
 * The clinic takes them so an injury can be seen rather than described, and
 * the class adviser sees the ones the nurse chose to share.
 *
 * That last clause is the whole design. Consultation detail otherwise stops
 * at the clinic (ConsultationVisibility), and a photograph of a child's
 * injury is more revealing than the text beside it, not less — so a photo is
 * clinic-only until the nurse decides the teacher needs it, per photo, and
 * the decision is reversible.
 */
class ConsultationPhotoTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function sessionFor(string $role): array
    {
        $base = [
            'active_role' => $role,
            'active_name' => $role === 'school_nurse' ? 'Nurse Cruz' : 'Staff Member',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
            'school_health_card_records' => [[
                'lrn' => '130000000001',
                'first_name' => 'Juan',
                'last_name' => 'Cruz',
                'grade_level' => 'Grade 10',
                'section' => 'Dalton',
            ]],
        ];

        if ($role === 'class_adviser') {
            $base['assigned_grade_level'] = 'Grade 10';
            $base['assigned_section'] = 'Dalton';
        }

        return $base;
    }

    private function learner(): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'student_id' => '130000000001',
            'student_name' => 'Cruz, Juan',
            'school_name' => 'Sta. Ana NHS',
            'grade_level' => 'Grade 10',
            'section' => 'Grade 10 / Dalton',
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'weight' => '40',
            'bmi_value' => '18',
            'nutritional_status' => 'Normal',
            'student_details' => [
                'lrn' => '130000000001',
                'last_name' => 'Cruz',
                'first_name' => 'Juan',
                'grade_level' => 'Grade 10',
                'section' => 'Dalton',
            ],
        ]);
    }

    private function visit(?Institution $school = null): Consultation
    {
        return Consultation::create([
            'institution_id' => ($school ?? $this->school)->id,
            'student_name' => 'Cruz, Juan',
            'grade_section' => 'Grade 10 / Dalton',
            'condition' => 'Graze to the knee',
            'treatment_given' => 'Cleaned and dressed',
            'status' => 'treated',
            'consulted_at' => now()->subHour(),
        ]);
    }

    private function upload(Consultation $visit, array $extra = [], string $role = 'school_nurse')
    {
        return $this->withSession($this->sessionFor($role))
            ->post(route('consultation-photos.store', $visit), array_merge([
                // create(), not image(): generating a real JPEG needs the GD
                // extension, which is not installed here. An explicit mime is
                // what the `image` rule checks anyway.
                'photo' => UploadedFile::fake()->create('knee.jpg', 120, 'image/jpeg'),
            ], $extra), ['Accept' => 'application/json']);
    }

    // ── The clinic uploads ───────────────────────────────────────────

    #[Test]
    public function the_nurse_can_attach_a_photo_to_a_consultation(): void
    {
        $visit = $this->visit();

        $this->upload($visit, ['caption' => 'Graze to the left knee'])->assertCreated();

        $photo = ConsultationPhoto::first();

        $this->assertNotNull($photo);
        $this->assertSame($visit->id, $photo->consultation_id);
        $this->assertSame($this->school->id, $photo->institution_id);
        $this->assertSame('Graze to the left knee', $photo->caption);
        $this->assertSame('Nurse Cruz', $photo->uploaded_by_name);
    }

    /** Clinic staff run the clinic too. */
    #[Test]
    public function clinic_staff_can_attach_a_photo(): void
    {
        $this->upload($this->visit(), [], 'clinic_staff')->assertCreated();

        $this->assertSame(1, ConsultationPhoto::count());
    }

    /** Nothing readable lands on disk. */
    #[Test]
    public function the_image_is_encrypted_at_rest(): void
    {
        $this->upload($this->visit(), ['caption' => 'Graze to the left knee'])->assertCreated();

        $photo = ConsultationPhoto::first();

        // Laravel ciphertext is base64 JSON, so it begins "eyJ" — the file
        // on disk is not the bytes that were uploaded.
        $stored = Storage::disk('local')->get($photo->file_path);
        $this->assertStringStartsWith('eyJ', $stored, 'The image must be encrypted on disk.');
        $this->assertNotSame($photo->file_size, strlen($stored));

        // And the caption is ciphertext in the column.
        $raw = DB::table('consultation_photos')->first();
        $this->assertStringNotContainsString('Graze to the left knee', (string) $raw->caption);
        $this->assertStringNotContainsString('Nurse Cruz', (string) $raw->uploaded_by_name);
    }

    #[Test]
    public function attaching_a_photo_is_audited(): void
    {
        $this->upload($this->visit())->assertCreated();

        $this->assertTrue(AuditLog::where('subject_type', 'ConsultationPhoto')->exists());
    }

    #[Test]
    public function a_non_image_is_refused(): void
    {
        $visit = $this->visit();

        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('consultation-photos.store', $visit), [
                'photo' => UploadedFile::fake()->create('notes.pdf', 40, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame(0, ConsultationPhoto::count());
    }

    // ── Sharing is the nurse's decision ──────────────────────────────

    /** The default is clinic-only. */
    #[Test]
    public function a_photo_is_not_shared_unless_the_nurse_says_so(): void
    {
        $this->upload($this->visit())->assertCreated();

        $this->assertFalse(ConsultationPhoto::first()->shared_with_adviser);
    }

    #[Test]
    public function the_nurse_can_share_at_upload_or_afterwards(): void
    {
        $visit = $this->visit();

        $this->upload($visit, ['shared_with_adviser' => '1'])->assertCreated();
        $this->assertTrue(ConsultationPhoto::first()->shared_with_adviser);

        // …and take it back.
        $photo = ConsultationPhoto::first();
        $this->withSession($this->sessionFor('school_nurse'))
            ->postJson(route('consultation-photos.share', $photo), ['shared_with_adviser' => false])
            ->assertOk();

        $this->assertFalse($photo->fresh()->shared_with_adviser);
    }

    // ── The adviser sees only what was shared ────────────────────────

    #[Test]
    public function the_adviser_sees_a_shared_photo(): void
    {
        $this->learner();
        $visit = $this->visit();
        $this->upload($visit, ['shared_with_adviser' => '1', 'caption' => 'Graze to the knee'])->assertCreated();

        $photos = $this->withSession($this->sessionFor('class_adviser'))
            ->getJson(route('consultation-photos.index', $visit))
            ->assertOk()
            ->json('photos');

        $this->assertCount(1, $photos);
        $this->assertSame('Graze to the knee', $photos[0]['caption']);

        // The image itself opens.
        $photo = ConsultationPhoto::first();
        $this->withSession($this->sessionFor('class_adviser'))
            ->get(route('consultation-photos.view', $photo))
            ->assertOk();
    }

    #[Test]
    public function the_adviser_never_sees_an_unshared_photo(): void
    {
        $this->learner();
        $visit = $this->visit();
        $this->upload($visit)->assertCreated();

        $photos = $this->withSession($this->sessionFor('class_adviser'))
            ->getJson(route('consultation-photos.index', $visit))
            ->assertOk()
            ->json('photos');

        $this->assertSame([], $photos);
    }

    /**
     * And cannot open one by guessing its id. The list hiding it is
     * presentation; this is the guarantee.
     */
    #[Test]
    public function the_adviser_cannot_open_an_unshared_photo_directly(): void
    {
        $this->learner();
        $this->upload($this->visit())->assertCreated();

        $this->withSession($this->sessionFor('class_adviser'))
            ->get(route('consultation-photos.view', ConsultationPhoto::first()))
            ->assertForbidden();
    }

    /**
     * A shared photo does not drag the clinical narrative along with it. The
     * adviser gets the picture and the nurse's caption, not the diagnosis.
     */
    #[Test]
    public function a_shared_photo_carries_no_clinical_detail(): void
    {
        $this->learner();
        $visit = $this->visit();
        $this->upload($visit, ['shared_with_adviser' => '1'])->assertCreated();

        $row = $this->withSession($this->sessionFor('class_adviser'))
            ->getJson(route('consultation-photos.index', $visit))
            ->assertOk()
            ->json('photos.0');

        foreach (['uploaded_by', 'file_name', 'shared_with_adviser'] as $clinical) {
            $this->assertArrayNotHasKey($clinical, $row);
        }
    }

    /** An adviser who does not hold this learner's class gets nothing. */
    #[Test]
    public function another_advisers_class_is_refused(): void
    {
        $this->learner();
        $visit = $this->visit();
        $this->upload($visit, ['shared_with_adviser' => '1'])->assertCreated();

        $session = $this->sessionFor('class_adviser');
        $session['assigned_section'] = 'Rizal';

        $this->withSession($session)
            ->getJson(route('consultation-photos.index', $visit))
            ->assertForbidden();

        $this->withSession($session)
            ->get(route('consultation-photos.view', ConsultationPhoto::first()))
            ->assertForbidden();
    }

    // ── Nobody else ──────────────────────────────────────────────────

    #[Test]
    public function the_school_head_gets_nothing(): void
    {
        $this->learner();
        $visit = $this->visit();
        $this->upload($visit, ['shared_with_adviser' => '1'])->assertCreated();

        $head = [
            'active_role' => 'school_head',
            'active_name' => 'Head Reyes',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
        ];

        $this->withSession($head)->getJson(route('consultation-photos.index', $visit))->assertForbidden();
        $this->withSession($head)->get(route('consultation-photos.view', ConsultationPhoto::first()))->assertForbidden();
    }

    #[Test]
    public function only_the_clinic_may_upload_share_or_remove(): void
    {
        $this->learner();
        $visit = $this->visit();
        $this->upload($visit)->assertCreated();
        $photo = ConsultationPhoto::first();

        foreach (['class_adviser', 'school_head', 'feeding_coor'] as $role) {
            $this->upload($visit, [], $role)->assertForbidden();

            $this->withSession($this->sessionFor($role))
                ->postJson(route('consultation-photos.share', $photo), ['shared_with_adviser' => true])
                ->assertForbidden();

            $this->withSession($this->sessionFor($role))
                ->deleteJson(route('consultation-photos.destroy', $photo))
                ->assertForbidden();
        }

        $this->assertSame(1, ConsultationPhoto::count());
        $this->assertFalse($photo->fresh()->shared_with_adviser);
    }

    /** Another school's consultation is unreachable. */
    #[Test]
    public function another_schools_consultation_is_refused(): void
    {
        $other = Institution::create(['name' => 'Wireless ES', 'status' => 'active']);
        $foreign = $this->visit($other);

        $this->upload($foreign)->assertForbidden();

        $this->withSession($this->sessionFor('school_nurse'))
            ->getJson(route('consultation-photos.index', $foreign))
            ->assertForbidden();
    }

    // ── Removal ──────────────────────────────────────────────────────

    #[Test]
    public function the_clinic_can_remove_a_photo_and_the_file_goes_with_it(): void
    {
        $this->upload($this->visit())->assertCreated();
        $photo = ConsultationPhoto::first();
        $path = $photo->file_path;

        Storage::disk('local')->assertExists($path);

        $this->withSession($this->sessionFor('school_nurse'))
            ->deleteJson(route('consultation-photos.destroy', $photo))
            ->assertOk();

        $this->assertSame(0, ConsultationPhoto::count());
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(2, AuditLog::where('subject_type', 'ConsultationPhoto')->count());
    }

    // ── The nurse's page ─────────────────────────────────────────────

    #[Test]
    public function the_consultation_log_offers_the_photo_dialog(): void
    {
        $this->upload($this->visit())->assertCreated();

        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.consultation-log'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="cphotoBackdrop"', $html);
        $this->assertStringContainsString('data-photos-open=', $html);
        $this->assertStringContainsString("Share with the learner's class adviser", $html);
    }

    /**
     * The row says whether there is anything to see. A visit with photos
     * carries the eye, "View photos" and how many, and opens the dialog; a
     * visit the nurse took no picture of reads "No photos attached" as plain
     * text — not a button, since there is nothing to open.
     */
    #[Test]
    public function the_consultation_log_says_whether_a_visit_has_photos(): void
    {
        $withPhoto = $this->visit();
        $this->visit();
        $this->upload($withPhoto)->assertCreated();

        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.consultation-log'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('class="clog-photo-btn has-photos"', $html);
        $this->assertStringContainsString('View photos', $html);
        $this->assertStringContainsString('<span class="clog-photo-count" data-photos-count>1</span>', $html);

        $this->assertStringContainsString('<span class="clog-photo-none">', $html);
        $this->assertStringContainsString('No photos attached', $html);
        $this->assertStringNotContainsString('is-empty', $html);
        $this->assertSame(1, substr_count($html, 'data-photos-open='), 'Only the visit with photos opens the dialog.');
        $this->assertSame(1, substr_count($html, 'class="clog-photo-btn'), 'The empty state is not a button.');
    }

    // ── Photos at the point of recording ────────────────────────────

    /**
     * The New Consultation dialog takes the photos with the visit, and they
     * land through the same model and storage as the Photos dialog — one
     * record, whichever way the picture arrived.
     */
    #[Test]
    public function the_nurse_can_attach_photos_while_recording_the_visit(): void
    {
        $this->learner();

        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('consultations.store'), [
                'consulted_at' => now()->toDateString(),
                'student_name' => 'Cruz, Juan',
                'grade_section' => 'Grade 10 - Dalton',
                'condition' => 'Abrasion',
                'treatment_given' => 'Cleaned and dressed',
                'status' => 'treated',
                'photos' => [
                    UploadedFile::fake()->create('knee-1.jpg', 120, 'image/jpeg'),
                    UploadedFile::fake()->create('knee-2.png', 90, 'image/png'),
                ],
                'photo_caption' => 'Graze to the left knee',
            ])
            ->assertRedirect(route('dashboard.consultation-log'))
            ->assertSessionHas('success', fn (string $m) => str_contains($m, '2 photos attached'));

        $visit = Consultation::firstOrFail();
        $photos = ConsultationPhoto::where('consultation_id', $visit->id)->get();

        $this->assertCount(2, $photos);
        $this->assertSame($this->school->id, $photos[0]->institution_id);
        $this->assertSame('Graze to the left knee', $photos[0]->caption);
        $this->assertSame('Nurse Cruz', $photos[0]->uploaded_by_name);
        $this->assertFalse($photos[0]->shared_with_adviser, 'Not shared unless the nurse says so.');

        // Encrypted on disk, exactly as an upload through the dialog is.
        $this->assertStringStartsWith('eyJ', Storage::disk('local')->get($photos[0]->file_path));

        // The Photos dialog lists what was attached at recording.
        $this->withSession($this->sessionFor('school_nurse'))
            ->getJson(route('consultation-photos.index', $visit))
            ->assertOk()
            ->assertJsonCount(2, 'photos');
    }

    /** A bad file refuses the whole save — no visit lands without its evidence. */
    #[Test]
    public function a_non_image_attached_at_recording_refuses_the_visit(): void
    {
        $this->learner();

        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('consultations.store'), [
                'consulted_at' => now()->toDateString(),
                'student_name' => 'Cruz, Juan',
                'grade_section' => 'Grade 10 - Dalton',
                'condition' => 'Abrasion',
                'status' => 'treated',
                'photos' => [UploadedFile::fake()->create('notes.pdf', 40, 'application/pdf')],
            ])
            ->assertSessionHasErrorsIn('consultation', ['photos.0']);

        $this->assertSame(0, Consultation::count());
        $this->assertSame(0, ConsultationPhoto::count());
    }

    // ── Viewing a photo ─────────────────────────────────────────────

    /**
     * A WebP is an accepted upload, so it must come back as an image. It used
     * to be sent as octet-stream, which under nosniff an <img> will not draw
     * and a new tab downloads instead of showing.
     */
    #[Test]
    public function a_webp_photo_is_served_as_an_image(): void
    {
        $visit = $this->visit();
        $this->upload($visit, ['photo' => UploadedFile::fake()->create('rash.webp', 80, 'image/webp')])->assertCreated();

        $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('consultation-photos.view', ConsultationPhoto::firstOrFail()))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('Content-Disposition', 'inline; filename="rash.webp"');
    }

    /** A photo whose name says nothing (a camera's "image") is typed off its own bytes. */
    #[Test]
    public function a_photo_whose_name_hides_its_type_is_served_from_its_bytes(): void
    {
        $photo = $this->storedPhoto('image', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        ));

        $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('consultation-photos.view', $photo))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    /** Reading the bytes never promotes a file to a type that can run as script. */
    #[Test]
    public function bytes_that_are_not_an_image_are_never_served_as_one(): void
    {
        $photo = $this->storedPhoto('image', '<html><script>alert(1)</script></html>');

        $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('consultation-photos.view', $photo))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream');
    }

    /** The viewer opens on the newest photo, so a photo just added is the one shown. */
    #[Test]
    public function the_newest_photo_comes_first(): void
    {
        $visit = $this->visit();
        $this->upload($visit, ['caption' => 'First'])->assertCreated();
        $this->upload($visit, ['caption' => 'Replacement'])->assertCreated();

        $photos = $this->withSession($this->sessionFor('school_nurse'))
            ->getJson(route('consultation-photos.index', $visit))
            ->assertOk()
            ->json('photos');

        $this->assertSame('Replacement', $photos[0]['caption']);
        $this->assertSame(route('consultation-photos.view', $photos[0]['id']), $photos[0]['url']);
    }

    /**
     * View photos opens on the photo itself — a viewer with its loading, empty
     * and error states — not on a grid of links that leave the page.
     */
    #[Test]
    public function the_dialog_opens_on_the_photo_itself(): void
    {
        $this->upload($this->visit())->assertCreated();

        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.consultation-log'))
            ->assertOk()
            ->getContent();

        foreach (['cphotoStage', 'cphotoStageImg', 'cphotoStageLoading', 'cphotoStageError', 'cphotoRetry', 'cphotoPrev', 'cphotoNext'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html);
        }
        $this->assertStringContainsString('No photo uploaded for this consultation.', $html);
        // Beside the photo: the visit's notes, the photo's own comment with a
        // way to write or change it, then every photo with its own.
        $this->assertStringContainsString('<div class="cphoto-label">Visit notes / comments</div>', $html);
        $this->assertStringContainsString('<div class="cphoto-label">Photo comment</div>', $html);
        $this->assertStringContainsString('id="cphotoStageCaption"', $html);
        $this->assertStringContainsString('id="cphotoEditComment"', $html);
        $this->assertStringContainsString('id="cphotoEditText"', $html);
        $this->assertStringContainsString('id="cphotoAll"', $html);
        // A thumbnail puts its photo on the stage rather than opening a tab.
        $this->assertStringContainsString('thumb.dataset.select = String(photo.id)', $html);
        $this->assertStringNotContainsString("link.target = '_blank'", $html);
    }

    // ── Comments ─────────────────────────────────────────────────────

    /**
     * A comment could only be typed at upload. The clinic can now write one
     * on a photo already taken, or correct it — encrypted, and on the audit
     * trail with what it replaced.
     */
    #[Test]
    public function the_clinic_can_comment_on_a_photo_after_it_was_taken(): void
    {
        $visit = $this->visit();
        $this->upload($visit)->assertCreated();
        $photo = ConsultationPhoto::firstOrFail();
        $audited = AuditLog::where('subject_type', 'ConsultationPhoto')->count();

        foreach (['school_nurse', 'clinic_staff', 'clinic_teacher'] as $role) {
            $photos = $this->withSession($this->sessionFor($role))
                ->postJson(route('consultation-photos.caption', $photo), ['caption' => 'Swelling, left wrist ('.$role.')'])
                ->assertOk()
                ->json('photos');

            $this->assertSame('Swelling, left wrist ('.$role.')', $photos[0]['caption']);
        }

        $this->assertSame('Swelling, left wrist (clinic_teacher)', $photo->fresh()->caption);
        $this->assertStringNotContainsString(
            'Swelling',
            (string) DB::table('consultation_photos')->where('id', $photo->id)->value('caption'),
            'The comment is encrypted at rest.'
        );
        $this->assertSame($audited + 3, AuditLog::where('subject_type', 'ConsultationPhoto')->count());
    }

    #[Test]
    public function a_photo_comment_can_be_cleared(): void
    {
        $visit = $this->visit();
        $this->upload($visit, ['caption' => 'Wrong photo description'])->assertCreated();
        $photo = ConsultationPhoto::firstOrFail();

        $this->withSession($this->sessionFor('school_nurse'))
            ->postJson(route('consultation-photos.caption', $photo), ['caption' => '   '])
            ->assertOk();

        $this->assertNull($photo->fresh()->caption);
    }

    /** The same rule as at upload, so a comment means the same whenever it was written. */
    #[Test]
    public function a_photo_comment_is_held_to_the_upload_rule(): void
    {
        $visit = $this->visit();
        $this->upload($visit)->assertCreated();

        $this->withSession($this->sessionFor('school_nurse'))
            ->postJson(route('consultation-photos.caption', ConsultationPhoto::firstOrFail()), ['caption' => str_repeat('a', 501)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('caption');
    }

    #[Test]
    public function only_the_clinic_may_comment_on_a_photo(): void
    {
        $this->learner();
        $visit = $this->visit();
        $this->upload($visit, ['shared_with_adviser' => '1', 'caption' => 'Original'])->assertCreated();
        $photo = ConsultationPhoto::firstOrFail();

        foreach (['class_adviser', 'school_head', 'feeding_coor'] as $role) {
            $this->withSession($this->sessionFor($role))
                ->postJson(route('consultation-photos.caption', $photo), ['caption' => 'Changed'])
                ->assertForbidden();
        }

        // Another school's clinic cannot reach it either.
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $this->withSession(array_merge($this->sessionFor('school_nurse'), ['active_institution_id' => $other->id]))
            ->postJson(route('consultation-photos.caption', $photo), ['caption' => 'Changed'])
            ->assertForbidden();

        $this->assertSame('Original', $photo->fresh()->caption);
    }

    /**
     * The clinic reads the visit's own Notes / comments beside the photos. An
     * adviser reading a shared photo through the same endpoint does not: a
     * visit note is clinical text, and a shared photo is not a way round that.
     */
    #[Test]
    public function the_visit_notes_come_with_the_photos_for_the_clinic_only(): void
    {
        $this->learner();
        $visit = $this->visit();
        $visit->update(['notes' => 'Advised rest; recheck dressing tomorrow.']);
        $this->upload($visit, ['shared_with_adviser' => '1'])->assertCreated();

        $this->withSession($this->sessionFor('school_nurse'))
            ->getJson(route('consultation-photos.index', $visit))
            ->assertOk()
            ->assertJsonPath('visit.notes', 'Advised rest; recheck dressing tomorrow.')
            ->assertJsonPath('visit.notes_shared_with_adviser', false);

        $adviser = $this->withSession($this->sessionFor('class_adviser'))
            ->getJson(route('consultation-photos.index', $visit))
            ->assertOk();

        $this->assertCount(1, $adviser->json('photos'));
        $this->assertArrayNotHasKey('visit', $adviser->json());
        $this->assertStringNotContainsString('Advised rest', $adviser->getContent());
    }

    /** A photo row written straight to storage, under the name given. */
    private function storedPhoto(string $name, string $bytes): ConsultationPhoto
    {
        $visit = $this->visit();
        $path = 'consultation-photos/'.uniqid().'_'.$name;
        Storage::disk('local')->put($path, Crypt::encryptString($bytes));

        return ConsultationPhoto::create([
            'consultation_id' => $visit->id,
            'institution_id' => $visit->institution_id,
            'file_path' => $path,
            'file_original_name' => $name,
            'file_size' => strlen($bytes),
        ]);
    }

    /** The dialog offers the photo field, and the profile's log opens the photos too. */
    #[Test]
    public function the_dialog_and_the_profile_carry_the_photo_controls(): void
    {
        $this->learner();

        $log = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.consultation-log'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('enctype="multipart/form-data"', $log);
        $this->assertStringContainsString('name="photos[]"', $log);
        $this->assertStringContainsString('name="photo_caption"', $log);
        $this->assertStringContainsString('name="photo_shared_with_adviser"', $log);

        $profile = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.student-health-records'))
            ->assertOk()
            ->getContent();

        // The profile's Consultation Log tab opens the same dialog, and the
        // dialog brings its own styles wherever it is included.
        $this->assertStringContainsString('id="cphotoBackdrop"', $profile);
        $this->assertStringContainsString('photos.dataset.photosOpen = String(row.id)', $profile);
        $this->assertStringContainsString('.cphoto-backdrop{', $profile);
        $this->assertStringContainsString('.cphoto-backdrop{', $log);
    }
}
