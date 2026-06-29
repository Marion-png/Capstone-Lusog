<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ParentalConsentFormTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

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

    private function nurseSession(): array
    {
        return [
            'active_role'           => 'school_nurse',
            'active_name'           => 'Test Nurse',
            'active_institution_id' => $this->institution->id,
        ];
    }

    private function makeRecord(string $lrn, string $section = 'Grade 1 / Sampaguita'): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'student_name'       => 'Test Student',
            'student_id'         => $lrn,
            'section'            => $section,
            'weight'             => 30.0,
            'bmi_value'          => 16.5,
            'nutritional_status' => 'Normal',
        ]);
    }

    private function makeConsent(StudentHealthRecord $record, ?string $schoolYear = null, string $consentType = 'full'): ParentalConsentForm
    {
        return ParentalConsentForm::create([
            'student_health_record_id' => $record->id,
            'program_type'             => 'Deworming',
            'school_year'              => $schoolYear ?? ParentalConsentForm::currentSchoolYear(),
            'consent_type'             => $consentType,
            'file_path'                => 'parental-consents/' . $record->id . '/fake.pdf',
            'file_original_name'       => 'consent.pdf',
            'uploaded_by_name'         => 'Test Adviser',
        ]);
    }

    private function nurseExamSession(string $lrn, string $name = 'Test Student'): array
    {
        return [
            'active_role'                => 'school_nurse',
            'active_institution_id'      => $this->institution->id,
            'school_health_card_records' => [
                0 => [
                    'lrn'        => $lrn,
                    'first_name' => $name,
                    'last_name'  => 'Student',
                    'grade_level'=> 'Grade 1',
                    'examination'=> [],
                ],
            ],
        ];
    }

    // ── upload: role restrictions ─────────────────────────────────────────────

    /** @test */
    public function class_adviser_can_upload_consent_for_student_in_their_class(): void
    {
        $this->makeRecord('LRN001');

        $response = $this->withSession($this->adviserSession())
            ->post(route('parental-consent.store'), [
                'lrn'          => 'LRN001',
                'consent_type' => 'full',
                'consent'      => UploadedFile::fake()->create('consent.pdf', 100, 'application/pdf'),
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('consent_success');
        $this->assertDatabaseHas('parental_consent_forms', [
            'program_type'     => 'Deworming',
            'uploaded_by_name' => 'Test Adviser',
            'consent_type'     => 'full',
        ]);
        Storage::disk('local')->assertExists(ParentalConsentForm::first()->file_path);
    }

    /** @test */
    public function class_adviser_cannot_upload_consent_for_student_outside_their_class(): void
    {
        $this->makeRecord('LRN002', 'Grade 2 / Rosal');

        $response = $this->withSession($this->adviserSession('Grade 1', 'Sampaguita'))
            ->post(route('parental-consent.store'), [
                'lrn'          => 'LRN002',
                'consent_type' => 'full',
                'consent'      => UploadedFile::fake()->create('consent.pdf', 100, 'application/pdf'),
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('parental_consent_forms', 0);
    }

    /** @test */
    public function class_adviser_without_assigned_section_is_rejected(): void
    {
        $this->makeRecord('LRN001');

        $response = $this->withSession([
                'active_role'           => 'class_adviser',
                'assigned_grade_level'  => '',
                'assigned_section'      => '',
                'active_institution_id' => $this->institution->id,
            ])
            ->post(route('parental-consent.store'), [
                'lrn'          => 'LRN001',
                'consent_type' => 'full',
                'consent'      => UploadedFile::fake()->create('consent.pdf', 100, 'application/pdf'),
            ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function school_nurse_cannot_upload_consent_forms(): void
    {
        $this->makeRecord('LRN001');

        $this->withSession($this->nurseSession())
            ->post(route('parental-consent.store'), [
                'lrn'          => 'LRN001',
                'consent_type' => 'full',
                'consent'      => UploadedFile::fake()->create('consent.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(403);
    }

    /** @test */
    public function unauthenticated_request_cannot_upload_consent_forms(): void
    {
        $this->makeRecord('LRN001');

        // Explicitly clear the role so no session bleeding from prior test methods can pass the gate
        $this->withSession(['active_role' => ''])
            ->post(route('parental-consent.store'), [
                'lrn'          => 'LRN001',
                'consent_type' => 'full',
                'consent'      => UploadedFile::fake()->create('consent.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(403);
    }

    // ── upload: file validation ───────────────────────────────────────────────

    /** @test */
    public function unsupported_file_types_are_rejected(): void
    {
        $this->makeRecord('LRN001');

        $response = $this->withSession($this->adviserSession())
            ->post(route('parental-consent.store'), [
                'lrn'          => 'LRN001',
                'consent_type' => 'full',
                'consent'      => UploadedFile::fake()->create('form.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            ]);

        $response->assertSessionHasErrors('consent');
        $this->assertDatabaseCount('parental_consent_forms', 0);
    }

    /** @test */
    public function exe_file_is_rejected(): void
    {
        $this->makeRecord('LRN001');

        $this->withSession($this->adviserSession())
            ->post(route('parental-consent.store'), [
                'lrn'          => 'LRN001',
                'consent_type' => 'full',
                'consent'      => UploadedFile::fake()->create('virus.exe', 100, 'application/x-msdownload'),
            ])
            ->assertSessionHasErrors('consent');
    }

    /** @test */
    public function files_over_5mb_are_rejected(): void
    {
        $this->makeRecord('LRN001');

        $this->withSession($this->adviserSession())
            ->post(route('parental-consent.store'), [
                'lrn'          => 'LRN001',
                'consent_type' => 'full',
                'consent'      => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf'),
            ])
            ->assertSessionHasErrors('consent');
    }

    /** @test */
    public function pdf_jpg_and_png_files_are_accepted(): void
    {
        foreach (['form.pdf', 'scan.jpg', 'photo.png'] as $i => $filename) {
            $lrn  = "LRN_CONSENT_{$i}";
            $mime = match (true) {
                str_ends_with($filename, '.pdf') => 'application/pdf',
                str_ends_with($filename, '.jpg') => 'image/jpeg',
                default                          => 'image/png',
            };

            $this->makeRecord($lrn);

            $this->withSession($this->adviserSession())
                ->post(route('parental-consent.store'), [
                    'lrn'          => $lrn,
                    'consent_type' => 'full',
                    'consent'      => UploadedFile::fake()->create($filename, 100, $mime),
                ])
                ->assertSessionMissing('errors');
        }
    }

    // ── deworming gate ────────────────────────────────────────────────────────

    /** @test */
    public function deworming_is_blocked_when_no_consent_is_on_file(): void
    {
        $record = $this->makeRecord('LRN001');

        // No consent form created — gate must reject
        $response = $this->withSession($this->nurseExamSession('LRN001'))
            ->post(route('nurse.examine.save', 0), ['deworming' => 'V']);

        $response->assertSessionHasErrors('deworming');
        // Session record should not have been updated with deworming = V
        $saved = session('school_health_card_records')[0]['examination']['deworming'] ?? null;
        $this->assertNotEquals('V', $saved);
    }

    /** @test */
    public function deworming_is_blocked_via_direct_api_call_when_no_consent(): void
    {
        $this->makeRecord('LRN001');

        // Simulate a direct POST without going through the UI
        $response = $this->withSession($this->nurseExamSession('LRN001'))
            ->post('/nurse/0/examine', ['deworming' => 'V']);

        $response->assertSessionHasErrors('deworming');
    }

    /** @test */
    public function deworming_succeeds_when_valid_consent_is_on_file(): void
    {
        $record = $this->makeRecord('LRN001');
        $this->makeConsent($record); // consent for current school year

        $response = $this->withSession($this->nurseExamSession('LRN001'))
            ->post(route('nurse.examine.save', 0), ['deworming' => 'V']);

        $response->assertRedirect(route('dashboard.student-health-records'));
        $response->assertSessionHas('success');
    }

    /** @test */
    public function deworming_not_given_is_always_allowed_without_consent(): void
    {
        $this->makeRecord('LRN001');

        // 'X' = Not Given — no consent check needed
        $response = $this->withSession($this->nurseExamSession('LRN001'))
            ->post(route('nurse.examine.save', 0), ['deworming' => 'X']);

        $response->assertRedirect(route('dashboard.student-health-records'));
    }

    /** @test */
    public function deworming_blank_is_always_allowed_without_consent(): void
    {
        $this->makeRecord('LRN001');

        $response = $this->withSession($this->nurseExamSession('LRN001'))
            ->post(route('nurse.examine.save', 0), ['deworming' => '']);

        $response->assertRedirect(route('dashboard.student-health-records'));
    }

    /** @test */
    public function consent_from_a_different_school_year_does_not_satisfy_gate(): void
    {
        $record = $this->makeRecord('LRN001');
        // Force a consent record from the previous school year
        $this->makeConsent($record, '2023-2024');

        $response = $this->withSession($this->nurseExamSession('LRN001'))
            ->post(route('nurse.examine.save', 0), ['deworming' => 'V']);

        $response->assertSessionHasErrors('deworming');
    }

    /** @test */
    public function consent_gate_is_per_student_other_students_with_consent_proceed_normally(): void
    {
        $recordA = $this->makeRecord('LRN_A');
        $this->makeRecord('LRN_B');
        $this->makeConsent($recordA); // only LRN_A has consent

        // LRN_A: should pass
        $this->withSession($this->nurseExamSession('LRN_A'))
            ->post(route('nurse.examine.save', 0), ['deworming' => 'V'])
            ->assertRedirect(route('dashboard.student-health-records'));

        // LRN_B: should be blocked
        $this->withSession($this->nurseExamSession('LRN_B'))
            ->post(route('nurse.examine.save', 0), ['deworming' => 'V'])
            ->assertSessionHasErrors('deworming');
    }

    // ── download: role restrictions ───────────────────────────────────────────

    /** @test */
    public function school_nurse_can_download_a_consent_form(): void
    {
        $record = $this->makeRecord('LRN001');
        Storage::disk('local')->put('parental-consents/1/consent.pdf', 'PDF content');
        $form = $this->makeConsent($record);
        // Update file_path to match what we put in storage
        $form->update(['file_path' => 'parental-consents/1/consent.pdf']);

        $this->withSession($this->nurseSession())
            ->get(route('parental-consent.download', $form->id))
            ->assertOk()
            ->assertHeader('Content-Disposition');
    }

    /** @test */
    public function clinic_staff_can_download_a_consent_form(): void
    {
        $record = $this->makeRecord('LRN001');
        Storage::disk('local')->put('parental-consents/1/consent.pdf', 'PDF content');
        $form = $this->makeConsent($record);
        $form->update(['file_path' => 'parental-consents/1/consent.pdf']);

        $this->withSession(['active_role' => 'clinic_staff', 'active_institution_id' => $this->institution->id])
            ->get(route('parental-consent.download', $form->id))
            ->assertOk();
    }

    /** @test */
    public function class_adviser_cannot_download_consent_forms(): void
    {
        $this->withSession($this->adviserSession())
            ->get(route('parental-consent.download', 999))
            ->assertStatus(403);
    }

    /** @test */
    public function unauthenticated_user_cannot_download_consent_forms(): void
    {
        $this->get(route('parental-consent.download', 999))
            ->assertStatus(403);
    }

    // ── API: consent status endpoint ──────────────────────────────────────────

    /** @test */
    public function consent_status_api_returns_true_when_consent_exists(): void
    {
        $record = $this->makeRecord('LRN001');
        $this->makeConsent($record);

        $response = $this->withSession($this->adviserSession())
            ->getJson(route('api.student-consent-status', ['lrn' => 'LRN001']));

        $response->assertOk()
            ->assertJsonPath('has_consent', true)
            ->assertJsonPath('school_year', ParentalConsentForm::currentSchoolYear());
    }

    /** @test */
    public function consent_status_api_returns_false_when_no_consent(): void
    {
        $this->makeRecord('LRN001');

        $response = $this->withSession($this->adviserSession())
            ->getJson(route('api.student-consent-status', ['lrn' => 'LRN001']));

        $response->assertOk()
            ->assertJsonPath('has_consent', false);
    }

    /** @test */
    public function consent_status_api_is_denied_for_unauthorized_roles(): void
    {
        foreach (['school_head', 'feeding_coor', 'nutricor'] as $role) {
            $this->withSession(['active_role' => $role])
                ->getJson(route('api.student-consent-status', ['lrn' => 'LRN001']))
                ->assertStatus(403);
        }
    }

    /** @test */
    public function consent_status_api_hides_other_classes_from_adviser(): void
    {
        $this->makeRecord('LRN_OTHER', 'Grade 2 / Rosal');
        $otherRecord = StudentHealthRecord::where('student_id', 'LRN_OTHER')->first();
        $this->makeConsent($otherRecord);

        // Adviser for Grade 1 / Sampaguita should get has_consent = false for a Grade 2 student
        $response = $this->withSession($this->adviserSession('Grade 1', 'Sampaguita'))
            ->getJson(route('api.student-consent-status', ['lrn' => 'LRN_OTHER']));

        $response->assertOk()
            ->assertJsonPath('has_consent', false);
    }
}
