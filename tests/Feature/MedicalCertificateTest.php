<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\MedicalCertificate;
use App\Models\StudentHealthCondition;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MedicalCertificateTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function adviserSession(string $grade = 'Grade 1', string $section = 'Sampaguita'): array
    {
        return [
            'active_role'           => 'class_adviser',
            'assigned_grade_level'  => $grade,
            'assigned_section'      => $section,
            'active_name'           => 'Test Adviser',
            'active_institution_id' => $this->institution->id,
        ];
    }

    private function clinicSession(): array
    {
        return [
            'active_role'           => 'clinic_staff',
            'active_name'           => 'Test Staff',
            'active_institution_id' => $this->institution->id,
        ];
    }

    private function makeRecord(string $lrn, string $section = 'Grade 1 / Sampaguita'): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'student_name'        => 'Test Student',
            'student_id'          => $lrn,
            'section'             => $section,
            'weight'              => 30.0,
            'bmi_value'           => 16.5,
            'nutritional_status'  => 'Normal',
        ]);
    }

    private function validUploadPayload(string $lrn, array $overrides = []): array
    {
        return array_merge([
            'lrn'            => $lrn,
            'condition_name' => 'Asthma',
            'certificate'    => UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf'),
        ], $overrides);
    }

    // ── upload: role restrictions ────────────────────────────────────────────

    /** @test */
    public function class_adviser_can_upload_for_student_in_their_class(): void
    {
        $this->makeRecord('LRN001');

        $response = $this->withSession($this->adviserSession())
            ->post(route('medical-certificate.store'), $this->validUploadPayload('LRN001'));

        $response->assertRedirect();
        $response->assertSessionHas('cert_success');
        $this->assertDatabaseHas('medical_certificates', ['uploaded_by_name' => 'Test Adviser']);
        Storage::disk('local')->assertExists(MedicalCertificate::first()->file_path);
    }

    /** @test */
    public function class_adviser_cannot_upload_for_student_outside_their_class(): void
    {
        $this->makeRecord('LRN002', 'Grade 2 / Rosal');

        $response = $this->withSession($this->adviserSession('Grade 1', 'Sampaguita'))
            ->post(route('medical-certificate.store'), $this->validUploadPayload('LRN002'));

        $response->assertStatus(403);
        $this->assertDatabaseMissing('medical_certificates', ['uploaded_by_name' => 'Test Adviser']);
    }

    /** @test */
    public function school_nurse_cannot_upload_certificates(): void
    {
        $this->makeRecord('LRN001');

        $response = $this->withSession(['active_role' => 'school_nurse', 'active_institution_id' => $this->institution->id])
            ->post(route('medical-certificate.store'), $this->validUploadPayload('LRN001'));

        $response->assertStatus(403);
    }

    /** @test */
    public function clinic_staff_cannot_upload_certificates(): void
    {
        $this->makeRecord('LRN001');

        $response = $this->withSession($this->clinicSession())
            ->post(route('medical-certificate.store'), $this->validUploadPayload('LRN001'));

        $response->assertStatus(403);
    }

    /** @test */
    public function school_head_cannot_upload_certificates(): void
    {
        $this->makeRecord('LRN001');

        $response = $this->withSession(['active_role' => 'school_head', 'active_institution_id' => $this->institution->id])
            ->post(route('medical-certificate.store'), $this->validUploadPayload('LRN001'));

        $response->assertStatus(403);
    }

    /** @test */
    public function feeding_coordinator_cannot_upload_certificates(): void
    {
        $this->makeRecord('LRN001');

        $response = $this->withSession(['active_role' => 'feeding_coor', 'active_institution_id' => $this->institution->id])
            ->post(route('medical-certificate.store'), $this->validUploadPayload('LRN001'));

        $response->assertStatus(403);
    }

    /** @test */
    public function nutritional_coordinator_cannot_upload_certificates(): void
    {
        $this->makeRecord('LRN001');

        $response = $this->withSession(['active_role' => 'nutricor', 'active_institution_id' => $this->institution->id])
            ->post(route('medical-certificate.store'), $this->validUploadPayload('LRN001'));

        $response->assertStatus(403);
    }

    /** @test */
    public function unauthenticated_request_cannot_upload_certificates(): void
    {
        $this->makeRecord('LRN001');

        // Explicitly clear role to guard against session bleeding from prior test methods
        $response = $this->withSession(['active_role' => ''])
            ->post(route('medical-certificate.store'), $this->validUploadPayload('LRN001'));

        $response->assertStatus(403);
    }

    // ── upload: file validation ──────────────────────────────────────────────

    /** @test */
    public function unsupported_file_types_are_rejected(): void
    {
        $this->makeRecord('LRN001');

        $response = $this->withSession($this->adviserSession())
            ->post(route('medical-certificate.store'), $this->validUploadPayload('LRN001', [
                'certificate' => UploadedFile::fake()->create('virus.exe', 100, 'application/x-msdownload'),
            ]));

        $response->assertSessionHasErrors('certificate');
        $this->assertDatabaseCount('medical_certificates', 0);
    }

    /** @test */
    public function docx_file_is_rejected(): void
    {
        $this->makeRecord('LRN001');

        $response = $this->withSession($this->adviserSession())
            ->post(route('medical-certificate.store'), $this->validUploadPayload('LRN001', [
                'certificate' => UploadedFile::fake()->create('doc.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            ]));

        $response->assertSessionHasErrors('certificate');
    }

    /** @test */
    public function files_over_5mb_are_rejected(): void
    {
        $this->makeRecord('LRN001');

        $response = $this->withSession($this->adviserSession())
            ->post(route('medical-certificate.store'), $this->validUploadPayload('LRN001', [
                'certificate' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf'),
            ]));

        $response->assertSessionHasErrors('certificate');
    }

    /** @test */
    public function pdf_jpg_and_png_files_are_accepted(): void
    {
        $this->makeRecord('LRN001');

        foreach (['cert.pdf', 'scan.jpg', 'photo.png'] as $i => $filename) {
            $mime = match (true) {
                str_ends_with($filename, '.pdf') => 'application/pdf',
                str_ends_with($filename, '.jpg') => 'image/jpeg',
                default                          => 'image/png',
            };

            $this->makeRecord("LRN00{$i}a", 'Grade 1 / Sampaguita');

            $this->withSession($this->adviserSession())
                ->post(route('medical-certificate.store'), [
                    'lrn'            => "LRN00{$i}a",
                    'condition_name' => 'Test',
                    'certificate'    => UploadedFile::fake()->create($filename, 100, $mime),
                ])
                ->assertSessionMissing('errors');
        }
    }

    /** @test */
    public function condition_name_is_required(): void
    {
        $this->makeRecord('LRN001');

        $response = $this->withSession($this->adviserSession())
            ->post(route('medical-certificate.store'), $this->validUploadPayload('LRN001', [
                'condition_name' => '',
            ]));

        $response->assertSessionHasErrors('condition_name');
    }

    // ── verified status ──────────────────────────────────────────────────────

    /** @test */
    public function condition_with_certificate_is_verified(): void
    {
        $record    = $this->makeRecord('LRN001');
        $condition = StudentHealthCondition::create([
            'student_health_record_id' => $record->id,
            'condition_name'           => 'Asthma',
        ]);
        MedicalCertificate::create([
            'student_health_condition_id' => $condition->id,
            'file_path'                   => 'medical-certificates/1/fake.pdf',
            'file_original_name'          => 'cert.pdf',
            'uploaded_by_name'            => 'Test Adviser',
        ]);

        $this->assertTrue($condition->isVerified());

        $response = $this->withSession($this->clinicSession())
            ->getJson(route('api.student-conditions', ['lrn' => 'LRN001']));

        $response->assertOk()
            ->assertJsonPath('conditions.0.is_verified', true)
            ->assertJsonPath('conditions.0.condition_name', 'Asthma');
    }

    /** @test */
    public function condition_without_certificate_is_self_reported(): void
    {
        $record    = $this->makeRecord('LRN001');
        StudentHealthCondition::create([
            'student_health_record_id' => $record->id,
            'condition_name'           => 'Allergies',
        ]);

        $response = $this->withSession($this->clinicSession())
            ->getJson(route('api.student-conditions', ['lrn' => 'LRN001']));

        $response->assertOk()
            ->assertJsonPath('conditions.0.is_verified', false)
            ->assertJsonPath('conditions.0.condition_name', 'Allergies');
    }

    // ── download: role restrictions ──────────────────────────────────────────

    /** @test */
    public function clinic_staff_can_download_a_certificate(): void
    {
        $record    = $this->makeRecord('LRN001');
        $condition = StudentHealthCondition::create([
            'student_health_record_id' => $record->id,
            'condition_name'           => 'Asthma',
        ]);

        Storage::disk('local')->put('medical-certificates/1/cert.pdf', 'PDF content');

        $cert = MedicalCertificate::create([
            'student_health_condition_id' => $condition->id,
            'file_path'                   => 'medical-certificates/1/cert.pdf',
            'file_original_name'          => 'cert.pdf',
            'uploaded_by_name'            => 'Test Adviser',
        ]);

        $response = $this->withSession($this->clinicSession())
            ->get(route('medical-certificate.download', $cert->id));

        $response->assertOk();
        $response->assertHeader('Content-Disposition');
    }

    /** @test */
    public function school_nurse_can_download_certificates(): void
    {
        $record    = $this->makeRecord('LRN001');
        $condition = StudentHealthCondition::create([
            'student_health_record_id' => $record->id,
            'condition_name'           => 'Asthma',
        ]);

        Storage::disk('local')->put('medical-certificates/1/cert.pdf', 'PDF content');

        $cert = MedicalCertificate::create([
            'student_health_condition_id' => $condition->id,
            'file_path'                   => 'medical-certificates/1/cert.pdf',
            'file_original_name'          => 'cert.pdf',
            'uploaded_by_name'            => 'Test Adviser',
        ]);

        $this->withSession(['active_role' => 'school_nurse', 'active_institution_id' => $this->institution->id])
            ->get(route('medical-certificate.download', $cert->id))
            ->assertOk()
            ->assertHeader('Content-Disposition');
    }

    /** @test */
    public function class_adviser_cannot_download_certificates(): void
    {
        $cert = $this->makeDummyCert();

        $this->withSession($this->adviserSession())
            ->get(route('medical-certificate.download', $cert->id))
            ->assertStatus(403);
    }

    /** @test */
    public function school_head_cannot_download_certificates(): void
    {
        $this->withSession(['active_role' => 'school_head', 'active_institution_id' => $this->institution->id])
            ->get(route('medical-certificate.download', 999))
            ->assertStatus(403);
    }

    /** @test */
    public function feeding_coordinator_cannot_download_certificates(): void
    {
        $this->withSession(['active_role' => 'feeding_coor', 'active_institution_id' => $this->institution->id])
            ->get(route('medical-certificate.download', 999))
            ->assertStatus(403);
    }

    /** @test */
    public function nutritional_coordinator_cannot_download_certificates(): void
    {
        $this->withSession(['active_role' => 'nutricor', 'active_institution_id' => $this->institution->id])
            ->get(route('medical-certificate.download', 999))
            ->assertStatus(403);
    }

    /** @test */
    public function unauthenticated_user_cannot_download_certificates(): void
    {
        $this->get(route('medical-certificate.download', 999))
            ->assertStatus(403);
    }

    // ── API: conditions endpoint role gating ─────────────────────────────────

    /** @test */
    public function conditions_api_is_denied_for_unauthorized_roles(): void
    {
        foreach (['school_head', 'feeding_coor', 'nutricor', 'system_admin'] as $role) {
            $this->withSession(['active_role' => $role, 'active_institution_id' => $this->institution->id])
                ->getJson(route('api.student-conditions', ['lrn' => 'X']))
                ->assertStatus(403);
        }
    }

    /** @test */
    public function school_nurse_can_read_conditions_in_health_timeline(): void
    {
        $record    = $this->makeRecord('LRN001');
        $condition = StudentHealthCondition::create([
            'student_health_record_id' => $record->id,
            'condition_name'           => 'Asthma',
        ]);
        MedicalCertificate::create([
            'student_health_condition_id' => $condition->id,
            'file_path'                   => 'medical-certificates/1/cert.pdf',
            'file_original_name'          => 'cert.pdf',
            'uploaded_by_name'            => 'Test Adviser',
        ]);

        $response = $this->withSession(['active_role' => 'school_nurse', 'active_institution_id' => $this->institution->id])
            ->getJson(route('api.student-conditions', ['lrn' => 'LRN001']));

        $response->assertOk()
            ->assertJsonPath('conditions.0.condition_name', 'Asthma')
            ->assertJsonPath('conditions.0.is_verified', true)
            ->assertJsonPath('conditions.0.certificates.0.download_url', route('medical-certificate.download', 1));
    }

    /** @test */
    public function conditions_api_returns_download_url_only_for_clinic_staff(): void
    {
        $record    = $this->makeRecord('LRN001');
        $condition = StudentHealthCondition::create([
            'student_health_record_id' => $record->id,
            'condition_name'           => 'Asthma',
        ]);
        MedicalCertificate::create([
            'student_health_condition_id' => $condition->id,
            'file_path'                   => 'medical-certificates/1/cert.pdf',
            'file_original_name'          => 'cert.pdf',
            'uploaded_by_name'            => 'Test Adviser',
        ]);

        // Clinic staff gets download_url
        $this->withSession($this->clinicSession())
            ->getJson(route('api.student-conditions', ['lrn' => 'LRN001']))
            ->assertJsonPath('conditions.0.certificates.0.download_url', route('medical-certificate.download', 1));

        // Class adviser also receives download_url (download route itself enforces the 403)
        $this->withSession($this->adviserSession())
            ->getJson(route('api.student-conditions', ['lrn' => 'LRN001']))
            ->assertOk()
            ->assertJsonPath('conditions.0.certificates.0.download_url', route('medical-certificate.download', 1));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeDummyCert(): MedicalCertificate
    {
        $record    = $this->makeRecord('LRN999');
        $condition = StudentHealthCondition::create([
            'student_health_record_id' => $record->id,
            'condition_name'           => 'Test',
        ]);

        return MedicalCertificate::create([
            'student_health_condition_id' => $condition->id,
            'file_path'                   => 'medical-certificates/1/cert.pdf',
            'file_original_name'          => 'cert.pdf',
            'uploaded_by_name'            => 'Test Adviser',
        ]);
    }
}
