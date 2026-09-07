<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Models\StudentPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A learner's profile photograph.
 *
 * The class adviser sets it — they enrol the learner and know which face
 * belongs to which name. The nurse and clinic staff see it, because putting a
 * face to a name is the point of it when a child arrives at the clinic and
 * cannot explain who they are. Nobody else reads one: a photograph of a
 * child's face is the most identifying field in the record.
 *
 * Keyed by LRN + institution rather than by health record, so it survives
 * grade promotion — the same child in Grade 8 as in Grade 7.
 */
class StudentPhotoTest extends TestCase
{
    use RefreshDatabase;

    private const LRN = '140000000001';

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function sessionFor(string $role, array $overrides = []): array
    {
        $base = [
            'active_role' => $role,
            'active_name' => $role === 'class_adviser' ? 'Maria Santos' : 'Staff Member',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
        ];

        if ($role === 'class_adviser') {
            $base['assigned_grade_level'] = 'Grade 10';
            $base['assigned_section'] = 'Dalton';
            $base['school_health_card_records'] = [[
                'lrn' => self::LRN,
                'first_name' => 'Juan',
                'last_name' => 'Cruz',
                'grade_level' => 'Grade 10',
                'section' => 'Dalton',
            ]];
        }

        return array_merge($base, $overrides);
    }

    private function learner(string $section = 'Grade 10 / Dalton', ?Institution $school = null): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => ($school ?? $this->school)->id,
            'student_id' => self::LRN,
            'student_name' => 'Cruz, Juan',
            'school_name' => 'Sta. Ana NHS',
            'grade_level' => 'Grade 10',
            'section' => $section,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'weight' => '40',
            'bmi_value' => '18',
            'nutritional_status' => 'Normal',
        ]);
    }

    /** Explicit mime rather than fake()->image(): the latter needs GD. */
    private function upload(string $role = 'class_adviser', string $name = 'juan.jpg')
    {
        return $this->withSession($this->sessionFor($role))
            ->post(route('student-photo.store', self::LRN), [
                'photo' => UploadedFile::fake()->create($name, 90, 'image/jpeg'),
            ], ['Accept' => 'application/json']);
    }

    // ── The adviser sets it ──────────────────────────────────────────

    #[Test]
    public function the_adviser_can_set_a_learners_photo(): void
    {
        $this->learner();

        $this->upload()->assertCreated();

        $photo = StudentPhoto::first();

        $this->assertNotNull($photo);
        $this->assertSame(self::LRN, $photo->student_lrn);
        $this->assertSame($this->school->id, $photo->institution_id);
        $this->assertSame('Maria Santos', $photo->uploaded_by_name);
        Storage::disk('local')->assertExists($photo->file_path);
    }

    /** A photo file is very often named after the child. */
    #[Test]
    public function the_image_and_its_filename_are_encrypted_at_rest(): void
    {
        $this->learner();
        $this->upload(name: 'Juan Cruz Grade 10.jpg')->assertCreated();

        $photo = StudentPhoto::first();

        $stored = Storage::disk('local')->get($photo->file_path);
        $this->assertStringStartsWith('eyJ', $stored, 'The image must be encrypted on disk.');

        $raw = DB::table('student_photos')->first();
        $this->assertStringNotContainsString('Juan Cruz', (string) $raw->file_original_name);
        $this->assertStringNotContainsString('Maria Santos', (string) $raw->uploaded_by_name);

        // The lookup keys stay plain, or nothing could find the row.
        $this->assertSame(self::LRN, $raw->student_lrn);
    }

    #[Test]
    public function setting_a_photo_is_audited(): void
    {
        $this->learner();
        $this->upload()->assertCreated();

        $this->assertTrue(AuditLog::where('subject_type', 'StudentPhoto')->exists());
    }

    /** One photo per learner — re-uploading replaces, never accumulates. */
    #[Test]
    public function a_second_upload_replaces_the_first(): void
    {
        $this->learner();

        $this->upload()->assertCreated();
        $first = StudentPhoto::first()->file_path;

        $this->upload(name: 'newer.jpg')->assertCreated();

        $this->assertSame(1, StudentPhoto::count(), 'A learner has one current photo.');

        $second = StudentPhoto::first()->file_path;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
    }

    #[Test]
    public function the_adviser_can_remove_a_photo(): void
    {
        $this->learner();
        $this->upload()->assertCreated();
        $path = StudentPhoto::first()->file_path;

        $this->withSession($this->sessionFor('class_adviser'))
            ->deleteJson(route('student-photo.destroy', self::LRN))
            ->assertOk()
            ->assertJson(['has_photo' => false]);

        $this->assertSame(0, StudentPhoto::count());
        Storage::disk('local')->assertMissing($path);
    }

    #[Test]
    public function a_non_image_is_refused(): void
    {
        $this->learner();

        $this->withSession($this->sessionFor('class_adviser'))
            ->post(route('student-photo.store', self::LRN), [
                'photo' => UploadedFile::fake()->create('notes.pdf', 40, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame(0, StudentPhoto::count());
    }

    // ── The clinic looks ─────────────────────────────────────────────

    #[Test]
    public function the_nurse_and_clinic_staff_can_see_it_but_not_change_it(): void
    {
        $this->learner();
        $this->upload()->assertCreated();

        foreach (['school_nurse', 'clinic_staff'] as $role) {
            $status = $this->withSession($this->sessionFor($role))
                ->getJson(route('student-photo.status', self::LRN))
                ->assertOk()
                ->json();

            $this->assertTrue($status['has_photo']);
            $this->assertFalse($status['may_manage'], "$role must not be offered the control.");

            $this->withSession($this->sessionFor($role))
                ->get(route('student-photo.show', self::LRN))
                ->assertOk();

            // And the endpoint refuses, not just the UI.
            $this->upload($role)->assertForbidden();
        }
    }

    // ── Nobody else ──────────────────────────────────────────────────

    /**
     * A photograph of a child's face is the most identifying field in the
     * record. The roles that never meet the learner have no use for it.
     */
    #[Test]
    public function no_other_role_can_see_or_set_a_photo(): void
    {
        $this->learner();
        $this->upload()->assertCreated();

        foreach (['school_head', 'feeding_coor', 'nutricor'] as $role) {
            $this->withSession($this->sessionFor($role))
                ->getJson(route('student-photo.status', self::LRN))
                ->assertForbidden();

            $this->withSession($this->sessionFor($role))
                ->get(route('student-photo.show', self::LRN))
                ->assertForbidden();
        }
    }

    /** Writing needs the adviser's own class, not merely their school. */
    #[Test]
    public function another_advisers_class_cannot_be_changed(): void
    {
        $this->learner(section: 'Grade 10 / Rizal');

        $this->upload()->assertForbidden();

        $this->assertSame(0, StudentPhoto::count());
    }

    #[Test]
    public function another_schools_learner_is_unreachable(): void
    {
        $other = Institution::create(['name' => 'Wireless ES', 'status' => 'active']);
        $this->learner(school: $other);

        $this->upload()->assertForbidden();

        $this->withSession($this->sessionFor('school_nurse'))
            ->getJson(route('student-photo.status', self::LRN))
            ->assertForbidden();
    }

    /** A learner with no photo is a 404, not a broken image. */
    #[Test]
    public function a_learner_without_a_photo_reports_it_plainly(): void
    {
        $this->learner();

        $this->withSession($this->sessionFor('class_adviser'))
            ->getJson(route('student-photo.status', self::LRN))
            ->assertOk()
            ->assertJson(['has_photo' => false, 'url' => null]);

        $this->withSession($this->sessionFor('class_adviser'))
            ->get(route('student-photo.show', self::LRN))
            ->assertNotFound();
    }

    // ── The pages ────────────────────────────────────────────────────

    #[Test]
    public function the_advisers_profile_offers_the_control(): void
    {
        $this->learner();

        $html = $this->withSession($this->sessionFor('class_adviser'))
            ->get(route('dashboard.class-adviser.student-profile', self::LRN))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="vpPhoto"', $html);
        $this->assertStringContainsString('id="vpPhotoBtn"', $html);

        // The avatar is the photo. No initials: the learner's name is already
        // printed beside it, and two renderings of one identity in a single
        // header is one too many. A neutral silhouette holds the space until
        // a picture exists, so the header never collapses.
        $this->assertStringContainsString('id="vpPhotoPlaceholder"', $html);
        $this->assertStringNotContainsString('id="vpInitials"', $html);
    }

    #[Test]
    public function the_clinics_profile_panel_shows_it(): void
    {
        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.student-health-records'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="pAvatarPhoto"', $html);
        $this->assertStringContainsString('id="pAvatarPlaceholder"', $html);
        // Read-only: no camera button on this side.
        $this->assertStringNotContainsString('id="vpPhotoBtn"', $html);
    }
}
