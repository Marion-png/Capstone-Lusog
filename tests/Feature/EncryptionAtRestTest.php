<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\HealthConsentForm;
use App\Models\Institution;
use App\Models\StudentHealthCondition;
use App\Models\StudentHealthRecord;
use App\Support\AccountName;
use App\Support\DecryptedValues;
use App\Support\EncryptedFileStorage;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Personal and sensitive personal information must be unreadable in the raw
 * database and on the raw filesystem, while remaining transparent to the app.
 */
class EncryptionAtRestTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function student_health_data_is_ciphertext_in_the_database(): void
    {
        $institution = Institution::create(['name' => 'Test School', 'status' => 'active']);

        $record = StudentHealthRecord::create([
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'institution_id' => $institution->id,
            'student_id' => 'LRN001',
            'student_name' => 'Dela Cruz, Juan A.',
            'section' => 'Grade 1 / Sampaguita',
            'weight' => 30.5,
            'bmi_value' => 16.2,
            'nutritional_status' => 'Wasted',
            'examination' => ['deworming' => 'V', 'others' => 'asthma noted'],
        ]);

        $raw = DB::table('student_health_records')->where('id', $record->id)->first();

        // Raw column values must not contain the plaintext.
        $this->assertStringNotContainsString('Dela Cruz', (string) $raw->student_name);
        $this->assertStringNotContainsString('Wasted', (string) $raw->nutritional_status);
        $this->assertStringNotContainsString('asthma', (string) $raw->examination);

        // The model decrypts transparently.
        $fresh = StudentHealthRecord::find($record->id);
        $this->assertSame('Dela Cruz, Juan A.', $fresh->student_name);
        $this->assertSame('Wasted', $fresh->nutritional_status);
        $this->assertSame('asthma noted', $fresh->examination['others']);
    }

    /** @test */
    public function consultation_details_and_diagnoses_are_ciphertext(): void
    {
        $consultation = Consultation::create([
            'consulted_at' => now(),
            'student_name' => 'Reyes, Maria',
            'grade_section' => 'Grade 3 - Rosal',
            'condition' => 'Tuberculosis',
            'status' => 'referred',
        ]);

        $record = StudentHealthRecord::create([
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_id' => 'LRN002',
            'student_name' => 'Reyes, Maria',
            'section' => 'Grade 3 / Rosal',
            'weight' => 25,
            'bmi_value' => 15,
            'nutritional_status' => 'Normal',
        ]);
        $condition = StudentHealthCondition::create([
            'student_lrn' => $record->student_id,
            'institution_id' => $record->institution_id,
            'condition_name' => 'Epilepsy',
        ]);

        $rawConsultation = DB::table('consultations')->where('id', $consultation->id)->first();
        $rawCondition = DB::table('student_health_conditions')->where('id', $condition->id)->first();

        $this->assertStringNotContainsString('Tuberculosis', (string) $rawConsultation->condition);
        $this->assertStringNotContainsString('Reyes', (string) $rawConsultation->student_name);
        $this->assertStringNotContainsString('Epilepsy', (string) $rawCondition->condition_name);

        $this->assertSame('Tuberculosis', $consultation->fresh()->condition);
        $this->assertSame('Epilepsy', $condition->fresh()->condition_name);
    }

    /** @test */
    public function consent_form_identity_and_signature_are_ciphertext(): void
    {
        $form = HealthConsentForm::create([
            'school_year' => HealthConsentForm::currentSchoolYear(),
            'division' => 'DAVAO CITY',
            'school_name' => 'Test School',
            'school_address' => 'Test Address',
            'student_lrn' => 'LRN003',
            'student_name' => 'Santos, Pedro',
            'student_address' => '123 Mabini St., Davao City',
            'parent_guardian_name' => 'Santos, Rosa',
            'services' => ['checkup', 'deworming'],
            'status' => HealthConsentForm::STATUS_DRAFT,
            'signature' => 'data:image/png;base64,iVBORw0KGgoAAAANS',
        ]);

        $raw = DB::table('health_consent_forms')->where('id', $form->id)->first();

        $this->assertStringNotContainsString('Santos', (string) $raw->student_name);
        $this->assertStringNotContainsString('Mabini', (string) $raw->student_address);
        $this->assertStringNotContainsString('Rosa', (string) $raw->parent_guardian_name);
        $this->assertStringNotContainsString('iVBORw0KGgo', (string) $raw->signature);
        $this->assertStringNotContainsString('checkup', (string) $raw->services);

        $fresh = $form->fresh();
        $this->assertSame('Santos, Pedro', $fresh->student_name);
        $this->assertSame(['checkup', 'deworming'], $fresh->services);
    }

    /** @test */
    public function legacy_plaintext_values_are_readable_without_errors(): void
    {
        // Simulate rows written before encryption at rest was introduced
        // (or empty strings the data migration skipped) — reading them must
        // never throw, only re-saving encrypts them.
        DB::table('health_consent_forms')->insert([
            'school_year' => HealthConsentForm::currentSchoolYear(),
            'division' => 'DAVAO CITY',
            'school_name' => 'Test School',
            'school_address' => 'Test Address',
            'student_lrn' => 'LRN010',
            'student_name' => 'Plain, Legacy P.',
            'student_address' => '',
            'parent_guardian_name' => '',
            'services' => '["checkup"]',
            'status' => HealthConsentForm::STATUS_DRAFT,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $form = HealthConsentForm::where('student_lrn', 'LRN010')->first();

        $this->assertSame('Plain, Legacy P.', $form->student_name);
        $this->assertSame('', $form->student_address);
        $this->assertSame(['checkup'], $form->services);
    }

    /** @test */
    public function uploaded_files_are_stored_encrypted_and_served_decrypted(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->createWithContent('certificate.pdf', '%PDF-1.4 fake medical certificate body');
        $path = EncryptedFileStorage::store($file, 'medical-certificates/1');

        // On disk: ciphertext only.
        $onDisk = Storage::disk('local')->get($path);
        $this->assertStringNotContainsString('fake medical certificate', $onDisk);

        // Served response: decrypted, correct type.
        $response = EncryptedFileStorage::response($path, 'certificate.pdf');
        $this->assertSame('%PDF-1.4 fake medical certificate body', $response->getContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    /**
     * Ciphertext this key cannot open reads as empty, never as base64.
     *
     * A record written under a different APP_KEY cannot be decrypted, and the
     * fallback that keeps legacy plaintext readable used to hand the raw value
     * back — printing a wall of base64 into a learner's name column. That is
     * not a value: nobody can read it, it is not what anyone typed, and it
     * makes the screen unusable while saying nothing.
     *
     * Legacy plaintext is a different case and still comes back as it stands.
     */
    /** @test */
    public function ciphertext_from_another_key_reads_as_empty_not_as_base64(): void
    {
        // Encrypt under a key this app does not have.
        $foreign = new Encrypter(random_bytes(32), 'AES-256-CBC');
        $unreadable = $foreign->encryptString('Dela Cruz, Juan');

        $this->assertTrue(
            DecryptedValues::looksEncrypted($unreadable),
            'The fixture must be Laravel ciphertext by shape.'
        );
        $this->assertFalse(DecryptedValues::isEncrypted($unreadable));
        $this->assertSame('', DecryptedValues::plaintext($unreadable));

        // …and it reaches a model's attribute as empty too, rather than base64.
        $school = Institution::create(['name' => 'Key Test School', 'status' => 'active']);

        $record = StudentHealthRecord::create([
            'institution_id' => $school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_id' => '130000009999',
            'student_name' => 'placeholder',
            'section' => 'Grade 10 / Dalton',
        ]);
        DB::table('student_health_records')
            ->where('id', $record->id)
            ->update(['student_name' => $unreadable]);

        $this->assertSame('', (string) $record->fresh()->student_name);
    }

    /** Legacy plaintext is not ciphertext, and is still handed back readable. */
    /** @test */
    public function legacy_plaintext_is_still_returned_as_it_stands(): void
    {
        $this->assertFalse(DecryptedValues::looksEncrypted('Dela Cruz, Juan'));
        $this->assertSame('Dela Cruz, Juan', DecryptedValues::plaintext('Dela Cruz, Juan'));
    }

    /**
     * A staff member's full name is personal information too: ciphertext on
     * the request and on the account it becomes, readable on every screen.
     * The username stays plain, because sign-in matches on it in SQL.
     *
     * @test
     */
    public function staff_account_names_are_ciphertext_from_request_to_sign_in(): void
    {
        $school = Institution::create(['name' => Institution::REGISTRATION_SCHOOL, 'status' => 'active']);

        $this->post('/account-request', [
            'name' => 'Rosalinda Q. Magbanua',
            'username' => 'nurse.magbanua',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'role' => 'school_nurse',
            'institution_id' => $school->id,
        ])->assertSessionHasNoErrors();

        $requestRow = DB::table('account_requests')->where('username', 'nurse.magbanua')->first();
        $this->assertStringNotContainsString('Magbanua', (string) $requestRow->name);
        $this->assertTrue(DecryptedValues::isEncrypted((string) $requestRow->name));

        $admin = ['active_role' => 'system_admin', 'active_name' => 'Test Admin', 'active_username' => 'systemadmin'];

        // The admin reads the request by name, then approves it.
        $this->withSession($admin)->get('/dashboard/system-admin')
            ->assertOk()
            ->assertSee('Rosalinda Q. Magbanua');
        $this->withSession($admin)->post(route('dashboard.system-admin.requests.approve', $requestRow->id));

        $accountRow = DB::table('accounts')->where('username', 'nurse.magbanua')->first();
        $this->assertNotNull($accountRow);
        $this->assertSame('nurse.magbanua', $accountRow->username);
        $this->assertStringNotContainsString('Magbanua', (string) $accountRow->name);
        $this->assertTrue(DecryptedValues::isEncrypted((string) $accountRow->name));

        // Signing in greets them by the name they typed.
        $this->flushSession();
        $this->post('/login', ['email' => 'nurse.magbanua', 'password' => 'password1'])
            ->assertSessionHas('active_name', 'Rosalinda Q. Magbanua');
    }

    /**
     * Names written before this was encrypted are sealed in place by the
     * migration, and anything already ciphertext is left untouched.
     *
     * @test
     */
    public function the_migration_seals_legacy_plaintext_account_names(): void
    {
        $school = Institution::create(['name' => 'Legacy Name School', 'status' => 'active']);
        $alreadySealed = AccountName::seal('Teodoro B. Lacson');

        DB::table('accounts')->insert([
            ['name' => 'Marites P. Villanueva', 'username' => 'legacy.one', 'role' => 'class_adviser',
                'institution_id' => $school->id, 'created_at' => now(), 'updated_at' => now()],
            ['name' => $alreadySealed, 'username' => 'legacy.two', 'role' => 'school_head',
                'institution_id' => $school->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        (require database_path('migrations/2026_11_10_000004_encrypt_account_names.php'))->up();

        $one = (string) DB::table('accounts')->where('username', 'legacy.one')->value('name');
        $this->assertStringNotContainsString('Villanueva', $one);
        $this->assertSame('Marites P. Villanueva', AccountName::read($one));

        $this->assertSame($alreadySealed, DB::table('accounts')->where('username', 'legacy.two')->value('name'));
    }
}
