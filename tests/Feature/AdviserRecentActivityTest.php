<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\HealthAssessment;
use App\Models\HealthConsentForm;
use App\Models\Institution;
use App\Models\MedicalCertificate;
use App\Models\StudentHealthCondition;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The dashboard's Recent Activity panel is fed by a live endpoint. These
 * guard both halves of that: the events are complete and correctly dated
 * (accurate), and the panel can refresh itself without a page load
 * (real time) — without leaking another school's data or flooding the
 * append-only audit trail.
 */
class AdviserRecentActivityTest extends TestCase
{
    use RefreshDatabase;

    private Institution $inst;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inst = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    /** @param  list<array<string, mixed>>  $extraRoster */
    private function adviserSession(array $extraRoster = []): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Maria Santos',
            'active_username' => 'maria.santos',
            'active_institution_id' => $this->inst->id,
            'assigned_grade_level' => 'Grade 10',
            'assigned_section' => 'Dalton',
            'assigned_school_name' => $this->inst->name,
            'school_health_card_records' => array_merge([
                [
                    'last_name' => 'Gomez', 'first_name' => 'Jose', 'middle_name' => 'Cruz',
                    'lrn' => 'LRN001', 'grade_level' => 'Grade 10', 'section' => 'Dalton',
                ],
            ], $extraRoster),
        ];
    }

    private function record(string $lrn = 'LRN001', string $name = 'Gomez, Jose'): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->inst->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_id' => $lrn,
            'student_name' => $name,
            'section' => 'Grade 10 / Dalton',
            'weight' => 40, 'bmi_value' => 17.7, 'nutritional_status' => 'Normal',
        ]);
    }

    #[Test]
    public function a_signed_consent_stays_in_the_feed_after_the_adviser_reviews_it(): void
    {
        $this->record();

        // The parent signed, then the adviser opened it — the form is now
        // "reviewed", but the signature is still the real event.
        HealthConsentForm::create([
            'school_year' => HealthConsentForm::currentSchoolYear(),
            'institution_id' => $this->inst->id,
            'division' => 'DAVAO', 'school_name' => 'Sta. Ana NHS', 'school_address' => 'x',
            'student_lrn' => 'LRN001', 'student_name' => 'Gomez, Jose',
            'status' => HealthConsentForm::STATUS_REVIEWED,
            'consent_choice' => HealthConsentForm::CONSENT_ALL,
            'sent_at' => now()->subDays(3),
            'signed_at' => now()->subDays(2),
            'reviewed_at' => now()->subDay(),
        ]);

        $texts = $this->feedTexts();

        $this->assertContains('Consent form signed by guardian of Gomez, Jose C.', $texts);
        $this->assertContains('Consent form sent to the guardian of Gomez, Jose C.', $texts);
        $this->assertContains('Consent form reviewed for Gomez, Jose C.', $texts);
    }

    #[Test]
    public function a_declined_consent_is_labelled_as_declined(): void
    {
        $this->record();

        HealthConsentForm::create([
            'school_year' => HealthConsentForm::currentSchoolYear(),
            'institution_id' => $this->inst->id,
            'division' => 'DAVAO', 'school_name' => 'Sta. Ana NHS', 'school_address' => 'x',
            'student_lrn' => 'LRN001', 'student_name' => 'Gomez, Jose',
            'status' => HealthConsentForm::STATUS_REVIEWED,
            'consent_choice' => HealthConsentForm::CONSENT_DENY,
            'refusal_reason' => 'No reason',
            'signed_at' => now()->subHour(),
        ]);

        $items = $this->feedItems();
        $declined = collect($items)->firstWhere('badge', 'CONSENT');

        $this->assertSame('Consent form declined by guardian of Gomez, Jose C.', $declined['text']);
        $this->assertSame('declined', $declined['icon']);
    }

    #[Test]
    public function enrolment_profile_and_certificate_events_all_appear_newest_first(): void
    {
        $record = $this->record();
        $record->forceFill(['created_at' => now()->subDays(5), 'updated_at' => now()->subDays(5)])->saveQuietly();

        $assessment = HealthAssessment::create([
            'student_health_record_id' => $record->id,
            'school_year' => HealthAssessment::currentSchoolYear(),
            'submitted_by_name' => 'Maria Santos',
        ]);
        $assessment->forceFill(['created_at' => now()->subDays(4), 'updated_at' => now()->subDays(4)])->saveQuietly();

        $condition = StudentHealthCondition::create([
            'student_lrn' => 'LRN001',
            'institution_id' => $this->inst->id,
            'condition_name' => 'Asthma',
        ]);

        $certificate = MedicalCertificate::create([
            'student_health_condition_id' => $condition->id,
            'file_path' => 'private/cert.pdf',
            'file_original_name' => 'cert.pdf',
            'doctor_clinic' => 'Davao Clinic',
            'uploaded_by_name' => 'Maria Santos',
        ]);
        $certificate->forceFill(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()])->saveQuietly();

        $texts = $this->feedTexts();

        $this->assertSame([
            'Medical certificate uploaded for Gomez, Jose C.',
            'Health profile completed for Gomez, Jose C.',
            'Gomez, Jose C. was enrolled in your class',
        ], $texts);
    }

    #[Test]
    public function the_pulse_changes_only_when_the_class_data_changes(): void
    {
        $this->record();
        $session = $this->adviserSession();

        $first = $this->withSession($session)->getJson('/dashboard/class-adviser/activity/pulse')->json('stamp');
        $second = $this->withSession($session)->getJson('/dashboard/class-adviser/activity/pulse')->json('stamp');

        $this->assertSame($first, $second);

        HealthConsentForm::create([
            'school_year' => HealthConsentForm::currentSchoolYear(),
            'institution_id' => $this->inst->id,
            'division' => 'DAVAO', 'school_name' => 'Sta. Ana NHS', 'school_address' => 'x',
            'student_lrn' => 'LRN001', 'student_name' => 'Gomez, Jose',
            'status' => HealthConsentForm::STATUS_SIGNED,
            'consent_choice' => HealthConsentForm::CONSENT_ALL,
            'signed_at' => now(),
        ]);

        $third = $this->withSession($session)->getJson('/dashboard/class-adviser/activity/pulse')->json('stamp');

        $this->assertNotSame($first, $third);
    }

    #[Test]
    public function the_pulse_carries_no_personal_data_and_is_not_audited(): void
    {
        $this->record();

        AuditLog::query()->delete();

        $response = $this->withSession($this->adviserSession())
            ->getJson('/dashboard/class-adviser/activity/pulse')
            ->assertOk();

        $response->assertDontSee('Gomez');
        $response->assertDontSee('LRN001');
        $this->assertSame(['stamp'], array_keys($response->json()));
        $this->assertSame(0, AuditLog::count(), 'The no-PII pulse must not write audit rows.');

        // The feed itself does return names, so it stays audited.
        $this->withSession($this->adviserSession())
            ->getJson('/dashboard/class-adviser/activity')
            ->assertOk();

        $this->assertGreaterThan(0, AuditLog::count());
    }

    #[Test]
    public function the_feed_is_scoped_to_the_advisers_own_school_and_role(): void
    {
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);

        StudentHealthRecord::create([
            'institution_id' => $other->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_id' => 'LRN999',
            'student_name' => 'Outsider, Nina',
            'section' => 'Grade 10 / Dalton',
            'weight' => 40, 'bmi_value' => 17.7, 'nutritional_status' => 'Normal',
        ]);

        $session = $this->adviserSession([
            ['last_name' => 'Outsider', 'first_name' => 'Nina', 'lrn' => 'LRN999', 'grade_level' => 'Grade 10', 'section' => 'Dalton'],
        ]);

        $response = $this->withSession($session)->getJson('/dashboard/class-adviser/activity')->assertOk();
        $this->assertStringNotContainsString('Outsider', json_encode($response->json('items')));

        // Another role cannot read an adviser's class feed.
        $this->withSession(['active_role' => 'school_nurse', 'active_name' => 'Nurse', 'active_institution_id' => $this->inst->id])
            ->getJson('/dashboard/class-adviser/activity')
            ->assertForbidden();
    }

    #[Test]
    public function the_dashboard_panel_is_wired_to_the_live_endpoints(): void
    {
        $this->record();

        $this->withSession($this->adviserSession())
            ->get('/dashboard/class-adviser')
            ->assertOk()
            ->assertSee('id="recentActivityList"', false)
            ->assertSee('data-feed-url="'.route('dashboard.class-adviser.activity').'"', false)
            ->assertSee('data-pulse-url="'.route('dashboard.class-adviser.activity.pulse').'"', false)
            ->assertSee('was enrolled in your class');
    }

    /** @return list<array<string, mixed>> */
    private function feedItems(): array
    {
        return $this->withSession($this->adviserSession())
            ->getJson('/dashboard/class-adviser/activity')
            ->assertOk()
            ->json('items');
    }

    /** @return list<string> */
    private function feedTexts(): array
    {
        return array_column($this->feedItems(), 'text');
    }
}
