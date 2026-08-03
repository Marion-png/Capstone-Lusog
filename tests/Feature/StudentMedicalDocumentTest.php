<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\MedicalCertificate;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Medical Documents on a learner's student profile. The class adviser, school
 * nurse, and clinic staff share one list: each uploads a document and sees the
 * upload history below it — including what the other desks filed — with
 * preview, download, and delete on every row.
 */
class StudentMedicalDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    private Institution $otherSchool;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->school = Institution::create(['name' => 'Test School', 'status' => 'active']);
        $this->otherSchool = Institution::create(['name' => 'Other School', 'status' => 'active']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function adviserSession(?Institution $school = null): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Test Adviser',
            'assigned_grade_level' => 'Grade 7',
            'assigned_section' => 'Sampaguita',
            'active_institution_id' => ($school ?? $this->school)->id,
        ];
    }

    /** A school nurse or clinic staff session — the other desks that file documents. */
    private function clinicSession(string $role = 'school_nurse', ?Institution $school = null): array
    {
        return [
            'active_role' => $role,
            'active_name' => $role === 'school_nurse' ? 'Ana Reyes, RN' : 'Jose Cruz',
            'active_institution_id' => ($school ?? $this->school)->id,
        ];
    }

    private function makeLearner(string $lrn, ?Institution $school = null): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'institution_id' => ($school ?? $this->school)->id,
            'student_name' => 'Gomez, Jose C.',
            'student_id' => $lrn,
            'section' => 'Grade 7 / Sampaguita',
            'weight' => 30.0,
            'bmi_value' => 16.5,
            'nutritional_status' => 'Normal',
        ]);
    }

    private function upload(string $lrn, ?UploadedFile $file = null, ?Institution $school = null)
    {
        return $this->withSession($this->adviserSession($school))->post(
            route('student-documents.store', ['lrn' => $lrn]),
            ['document' => $file ?? UploadedFile::fake()->createWithContent('clearance.pdf', 'SECRET-DIAGNOSIS')],
            ['Accept' => 'application/json'],
        );
    }

    private function makeDocument(string $lrn, Institution $school, string $name = 'cert.pdf'): MedicalCertificate
    {
        Storage::disk('local')->put("medical-documents/{$name}", 'ciphertext');

        return MedicalCertificate::create([
            'student_lrn' => $lrn,
            'institution_id' => $school->id,
            'file_path' => "medical-documents/{$name}",
            'file_original_name' => $name,
            'file_size' => 2048,
            'uploaded_by_name' => 'Test Adviser',
        ]);
    }

    // ── upload ──────────────────────────────────────────────────────────────

    #[Test]
    public function class_adviser_can_upload_a_document_for_a_learner_in_their_school(): void
    {
        $this->makeLearner('LRN001');

        $response = $this->upload('LRN001');

        $response->assertCreated()
            ->assertJsonPath('documents.0.file_name', 'clearance.pdf')
            ->assertJsonPath('documents.0.uploaded_by', 'Test Adviser');

        $document = MedicalCertificate::first();
        $this->assertSame('LRN001', $document->student_lrn);
        $this->assertSame($this->school->id, (int) $document->institution_id);
        $this->assertNull($document->student_health_condition_id);
        $this->assertSame(strlen('SECRET-DIAGNOSIS'), $document->file_size);
        Storage::disk('local')->assertExists($document->file_path);
    }

    #[Test]
    public function an_uploaded_document_is_encrypted_at_rest(): void
    {
        $this->makeLearner('LRN001');
        $this->upload('LRN001');

        $document = MedicalCertificate::first();

        $this->assertStringNotContainsString(
            'SECRET-DIAGNOSIS',
            Storage::disk('local')->get($document->file_path)
        );

        // The file name is sensitive too, so it never lands in the clear.
        $stored = (string) DB::table('medical_certificates')
            ->where('id', $document->id)
            ->value('file_original_name');
        $this->assertNotSame('clearance.pdf', $stored);
        $this->assertSame('clearance.pdf', $document->file_original_name);
    }

    #[Test]
    public function every_supported_format_is_accepted(): void
    {
        $this->makeLearner('LRN001');

        foreach (['scan.pdf', 'photo.jpg', 'photo.jpeg', 'x-ray.png', 'note.doc', 'note.docx', 'sheet.xls', 'sheet.xlsx'] as $name) {
            $this->upload('LRN001', UploadedFile::fake()->createWithContent($name, 'content'))
                ->assertCreated();
        }

        $this->assertSame(8, MedicalCertificate::count());
    }

    #[Test]
    public function unsupported_file_types_are_rejected(): void
    {
        $this->makeLearner('LRN001');

        $this->upload('LRN001', UploadedFile::fake()->createWithContent('virus.exe', 'MZ'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('document');

        $this->assertSame(0, MedicalCertificate::count());
    }

    #[Test]
    public function documents_over_10mb_are_rejected(): void
    {
        $this->makeLearner('LRN001');

        $this->upload('LRN001', UploadedFile::fake()->create('scan.pdf', 11000))
            ->assertStatus(422)
            ->assertJsonValidationErrors('document');

        $this->assertSame(0, MedicalCertificate::count());
    }

    #[Test]
    public function an_adviser_cannot_upload_for_a_learner_from_another_school(): void
    {
        $this->makeLearner('LRN900', $this->otherSchool);

        $this->upload('LRN900')->assertNotFound();

        $this->assertSame(0, MedicalCertificate::count());
    }

    #[Test]
    public function the_school_nurse_and_clinic_staff_upload_the_same_way_the_adviser_does(): void
    {
        $this->makeLearner('LRN001');

        foreach (['school_nurse', 'clinic_staff'] as $role) {
            $this->withSession($this->clinicSession($role))
                ->post(
                    route('student-documents.store', ['lrn' => 'LRN001']),
                    ['document' => UploadedFile::fake()->createWithContent("{$role}.pdf", 'content')],
                    ['Accept' => 'application/json'],
                )
                ->assertCreated();
        }

        $this->assertSame(
            ['clinic_staff', 'school_nurse'],
            MedicalCertificate::pluck('uploaded_by_role')->sort()->values()->all()
        );
    }

    #[Test]
    public function no_other_role_may_upload_documents(): void
    {
        $this->makeLearner('LRN001');

        foreach (['school_head', 'feeding_coor', 'nutricor', 'system_admin'] as $role) {
            $this->withSession(['active_role' => $role, 'active_institution_id' => $this->school->id])
                ->post(
                    route('student-documents.store', ['lrn' => 'LRN001']),
                    ['document' => UploadedFile::fake()->createWithContent('scan.pdf', 'content')],
                    ['Accept' => 'application/json'],
                )
                ->assertForbidden();
        }

        $this->assertSame(0, MedicalCertificate::count());
    }

    #[Test]
    public function an_upload_records_the_desk_that_filed_it(): void
    {
        $this->makeLearner('LRN001');

        $this->upload('LRN001')
            ->assertCreated()
            ->assertJsonPath('documents.0.uploaded_by_role', 'Class Adviser')
            ->assertJsonPath('documents.0.uploaded_by', 'Test Adviser');

        $this->assertSame('class_adviser', MedicalCertificate::first()->uploaded_by_role);
    }

    // ── one list, shared by the three desks ─────────────────────────────────

    #[Test]
    public function the_adviser_and_the_nurse_each_see_what_the_other_uploaded(): void
    {
        $this->makeLearner('LRN001');

        $this->upload('LRN001', UploadedFile::fake()->createWithContent('from-adviser.pdf', 'a'))
            ->assertCreated();

        $this->withSession($this->clinicSession())
            ->post(
                route('student-documents.store', ['lrn' => 'LRN001']),
                ['document' => UploadedFile::fake()->createWithContent('from-nurse.pdf', 'n')],
                ['Accept' => 'application/json'],
            )
            ->assertCreated();

        foreach ([$this->adviserSession(), $this->clinicSession(), $this->clinicSession('clinic_staff')] as $session) {
            $response = $this->withSession($session)
                ->getJson(route('student-documents.index', ['lrn' => 'LRN001']))
                ->assertOk()
                ->assertJsonCount(2, 'documents');

            $this->assertSame(
                ['from-adviser.pdf', 'from-nurse.pdf'],
                collect($response->json('documents'))->pluck('file_name')->sort()->values()->all()
            );
            $this->assertSame(
                ['Class Adviser', 'School Nurse'],
                collect($response->json('documents'))->pluck('uploaded_by_role')->sort()->values()->all()
            );
        }
    }

    // ── live updates between the desks ──────────────────────────────────────

    #[Test]
    public function the_nurses_open_panel_is_told_when_the_adviser_uploads(): void
    {
        $this->makeLearner('LRN001');

        // The nurse has the learner's panel open and takes a reading.
        $before = $this->withSession($this->clinicSession())
            ->getJson(route('student-documents.pulse', ['lrn' => 'LRN001']))
            ->assertOk()
            ->json('stamp');

        // Nothing changed yet, so the panel must not re-read the list.
        $this->assertSame(
            $before,
            $this->withSession($this->clinicSession())
                ->getJson(route('student-documents.pulse', ['lrn' => 'LRN001']))
                ->json('stamp')
        );

        $this->upload('LRN001')->assertCreated();

        $this->assertNotSame(
            $before,
            $this->withSession($this->clinicSession())
                ->getJson(route('student-documents.pulse', ['lrn' => 'LRN001']))
                ->json('stamp'),
            'The nurse must be told when the adviser files a document.'
        );
    }

    #[Test]
    public function the_advisers_open_panel_is_told_when_the_nurse_uploads_or_deletes(): void
    {
        $this->makeLearner('LRN001');

        $before = $this->withSession($this->adviserSession())
            ->getJson(route('student-documents.pulse', ['lrn' => 'LRN001']))
            ->json('stamp');

        $this->withSession($this->clinicSession())
            ->post(
                route('student-documents.store', ['lrn' => 'LRN001']),
                ['document' => UploadedFile::fake()->createWithContent('nurse.pdf', 'n')],
                ['Accept' => 'application/json'],
            )
            ->assertCreated();

        $afterUpload = $this->withSession($this->adviserSession())
            ->getJson(route('student-documents.pulse', ['lrn' => 'LRN001']))
            ->json('stamp');
        $this->assertNotSame($before, $afterUpload);

        $this->withSession($this->clinicSession())
            ->delete(
                route('student-documents.destroy', MedicalCertificate::first()->id),
                [],
                ['Accept' => 'application/json'],
            )
            ->assertOk();

        $this->assertNotSame(
            $afterUpload,
            $this->withSession($this->adviserSession())
                ->getJson(route('student-documents.pulse', ['lrn' => 'LRN001']))
                ->json('stamp'),
            'A deletion must move the signal too.'
        );
    }

    #[Test]
    public function a_document_list_carries_the_stamp_that_matches_it(): void
    {
        $this->makeLearner('LRN001');

        $created = $this->upload('LRN001')->assertCreated();
        $pulse = $this->withSession($this->adviserSession())
            ->getJson(route('student-documents.pulse', ['lrn' => 'LRN001']))
            ->json('stamp');

        // The uploader already knows the current signal, so it does not
        // immediately re-read the list it just rendered.
        $this->assertSame($pulse, $created->json('stamp'));
        $this->assertSame(
            $pulse,
            $this->withSession($this->adviserSession())
                ->getJson(route('student-documents.index', ['lrn' => 'LRN001']))
                ->json('stamp')
        );
    }

    #[Test]
    public function the_pulse_carries_no_personal_data_and_is_not_audited(): void
    {
        $this->makeLearner('LRN001');
        $this->upload('LRN001')->assertCreated();

        AuditLog::query()->delete();

        $response = $this->withSession($this->adviserSession())
            ->getJson(route('student-documents.pulse', ['lrn' => 'LRN001']))
            ->assertOk();

        $this->assertSame(['stamp'], array_keys($response->json()));
        $response->assertDontSee('clearance.pdf');
        $response->assertDontSee('Test Adviser');
        $this->assertSame(0, AuditLog::count(), 'The no-PII pulse must not write audit rows.');

        // The list itself does return names and file names, so it stays audited.
        $this->withSession($this->adviserSession())
            ->getJson(route('student-documents.index', ['lrn' => 'LRN001']))
            ->assertOk();
        $this->assertGreaterThan(0, AuditLog::count());
    }

    #[Test]
    public function the_pulse_is_closed_to_other_roles_and_other_schools(): void
    {
        $this->makeLearner('LRN001');
        $this->upload('LRN001')->assertCreated();

        foreach (['school_head', 'feeding_coor', 'nutricor', 'system_admin'] as $role) {
            $this->withSession(['active_role' => $role, 'active_institution_id' => $this->school->id])
                ->getJson(route('student-documents.pulse', ['lrn' => 'LRN001']))
                ->assertForbidden();
        }

        // Another school polling the same LRN learns nothing: it reads the same
        // stamp as a learner with no documents at all.
        $foreign = $this->withSession($this->adviserSession($this->otherSchool))
            ->getJson(route('student-documents.pulse', ['lrn' => 'LRN001']))
            ->assertOk()
            ->json('stamp');

        $empty = $this->withSession($this->adviserSession($this->otherSchool))
            ->getJson(route('student-documents.pulse', ['lrn' => 'NOBODY']))
            ->assertOk()
            ->json('stamp');

        $this->assertSame($empty, $foreign);
    }

    #[Test]
    public function the_nurse_can_open_and_delete_a_document_the_adviser_filed(): void
    {
        $this->makeLearner('LRN001');
        $this->upload('LRN001')->assertCreated();
        $document = MedicalCertificate::first();

        $this->withSession($this->clinicSession())
            ->get(route('student-documents.download', $document->id))
            ->assertOk();

        $this->withSession($this->clinicSession())
            ->delete(route('student-documents.destroy', $document->id), [], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonCount(0, 'documents');

        $this->assertSame(0, MedicalCertificate::count());
    }

    // ── listing ─────────────────────────────────────────────────────────────

    #[Test]
    public function the_list_returns_this_learners_documents_newest_first(): void
    {
        $this->makeLearner('LRN001');
        $this->makeLearner('LRN002');

        $older = $this->makeDocument('LRN001', $this->school, 'older.pdf');
        $older->forceFill(['created_at' => now()->subDay()])->saveQuietly();
        $this->makeDocument('LRN001', $this->school, 'newer.pdf');
        $this->makeDocument('LRN002', $this->school, 'other-learner.pdf');

        $this->withSession($this->adviserSession())
            ->getJson(route('student-documents.index', ['lrn' => 'LRN001']))
            ->assertOk()
            ->assertJsonCount(2, 'documents')
            ->assertJsonPath('documents.0.file_name', 'newer.pdf')
            ->assertJsonPath('documents.1.file_name', 'older.pdf');
    }

    #[Test]
    public function documents_from_another_school_are_never_listed(): void
    {
        $this->makeLearner('LRN001');
        $this->makeLearner('LRN001', $this->otherSchool);
        $this->makeDocument('LRN001', $this->otherSchool, 'other-school.pdf');

        $this->withSession($this->adviserSession())
            ->getJson(route('student-documents.index', ['lrn' => 'LRN001']))
            ->assertOk()
            ->assertJsonCount(0, 'documents');
    }

    #[Test]
    public function the_student_profile_page_renders_the_uploaded_documents(): void
    {
        $this->makeLearner('LRN001');
        $this->makeDocument('LRN001', $this->school, 'annual-physical.pdf');

        $this->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser.student-profile', ['lrn' => 'LRN001']))
            ->assertOk()
            ->assertSee('annual-physical.pdf')
            ->assertSee('Drag and drop medical documents here, or click to browse');
    }

    // ── preview / download ──────────────────────────────────────────────────

    #[Test]
    public function a_document_is_previewed_inline_and_downloaded_as_an_attachment(): void
    {
        $this->makeLearner('LRN001');
        $this->upload('LRN001');
        $document = MedicalCertificate::first();

        $view = $this->withSession($this->adviserSession())
            ->get(route('student-documents.view', $document->id));
        $view->assertOk();
        $this->assertStringStartsWith('inline;', $view->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $view->headers->get('X-Content-Type-Options'));
        $this->assertSame('SECRET-DIAGNOSIS', $view->getContent());

        $download = $this->withSession($this->adviserSession())
            ->get(route('student-documents.download', $document->id));
        $download->assertOk();
        $this->assertStringStartsWith('attachment;', $download->headers->get('Content-Disposition'));
        $this->assertSame('SECRET-DIAGNOSIS', $download->getContent());
    }

    #[Test]
    public function a_document_from_another_school_cannot_be_opened(): void
    {
        $document = $this->makeDocument('LRN900', $this->otherSchool);

        $this->withSession($this->adviserSession())
            ->get(route('student-documents.view', $document->id))
            ->assertNotFound();

        $this->withSession($this->adviserSession())
            ->get(route('student-documents.download', $document->id))
            ->assertNotFound();

        $this->withSession($this->adviserSession())
            ->delete(route('student-documents.destroy', $document->id), [], ['Accept' => 'application/json'])
            ->assertNotFound();

        $this->assertDatabaseCount('medical_certificates', 1);
    }

    #[Test]
    public function no_other_role_may_open_a_document(): void
    {
        $document = $this->makeDocument('LRN001', $this->school);

        foreach (['school_head', 'feeding_coor', 'nutricor', 'system_admin'] as $role) {
            $this->withSession(['active_role' => $role, 'active_institution_id' => $this->school->id])
                ->get(route('student-documents.download', $document->id))
                ->assertForbidden();
        }
    }

    // ── delete ──────────────────────────────────────────────────────────────

    #[Test]
    public function deleting_a_document_removes_the_row_and_the_stored_file(): void
    {
        $this->makeLearner('LRN001');
        $this->upload('LRN001');
        $document = MedicalCertificate::first();
        $path = $document->file_path;

        $this->withSession($this->adviserSession())
            ->delete(route('student-documents.destroy', $document->id), [], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonCount(0, 'documents');

        $this->assertDatabaseCount('medical_certificates', 0);
        Storage::disk('local')->assertMissing($path);
    }

    #[Test]
    public function a_deleted_document_leaves_an_audit_trail_entry(): void
    {
        $this->makeLearner('LRN001');
        $this->upload('LRN001');
        $document = MedicalCertificate::first();

        $this->withSession($this->adviserSession())
            ->delete(route('student-documents.destroy', $document->id), [], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'deleted',
            'subject_type' => 'MedicalCertificate',
            'subject_id' => $document->id,
        ]);
    }
}
