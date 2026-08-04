<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ClinicNote;
use App\Models\Consultation;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Clinic Notes and Consultation tabs on the School Nurse's student
 * profile. Both are backed by real records, scoped to the viewer's school,
 * and the notes themselves are sensitive personal information — encrypted
 * at rest and audited like every other clinical record.
 */
class ClinicNotesAndConsultationsTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function nurseSession(?Institution $institution = null): array
    {
        return [
            'active_role' => 'school_nurse',
            'active_name' => 'Ana Reyes',
            'active_institution_id' => ($institution ?? $this->institution)->id,
            'active_school_name' => 'Sta. Ana NHS',
        ];
    }

    private function learner(string $lrn = 'LRN001', string $name = 'Dela Cruz, Juan', ?Institution $institution = null): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => ($institution ?? $this->institution)->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_id' => $lrn,
            'student_name' => $name,
            'section' => 'Grade 10 / Dalton',
            'weight' => 40, 'bmi_value' => 17.7, 'nutritional_status' => 'Normal',
        ]);
    }

    #[Test]
    public function a_nurse_can_record_and_read_back_a_clinic_note(): void
    {
        $this->learner();

        $this->withSession($this->nurseSession())
            ->postJson('/api/student-clinic-notes', [
                'lrn' => 'LRN001',
                'note' => 'Reports wheezing during PE. Advised inhaler as needed.',
                'follow_up_date' => now()->addWeek()->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('note.note', 'Reports wheezing during PE. Advised inhaler as needed.')
            ->assertJsonPath('note.author', 'Ana Reyes');

        $this->withSession($this->nurseSession())
            ->getJson('/api/student-clinic-notes?lrn=LRN001')
            ->assertOk()
            ->assertJsonCount(1, 'notes')
            ->assertJsonPath('notes.0.note', 'Reports wheezing during PE. Advised inhaler as needed.');
    }

    #[Test]
    public function clinic_notes_are_ciphertext_in_the_database(): void
    {
        $this->learner();

        $this->withSession($this->nurseSession())
            ->postJson('/api/student-clinic-notes', ['lrn' => 'LRN001', 'note' => 'Confidential observation'])
            ->assertCreated();

        $raw = DB::table('clinic_notes')->first();

        $this->assertNotSame('Confidential observation', $raw->note);
        $this->assertStringNotContainsString('Confidential', $raw->note);
        $this->assertNotSame('Ana Reyes', $raw->author_name);
        // Lookup columns stay plain so they can be queried.
        $this->assertSame('LRN001', $raw->student_lrn);

        $this->assertSame('Confidential observation', (string) ClinicNote::first()->note);
    }

    #[Test]
    public function writing_a_clinic_note_is_audited(): void
    {
        $this->learner();
        AuditLog::query()->delete();

        $this->withSession($this->nurseSession())
            ->postJson('/api/student-clinic-notes', ['lrn' => 'LRN001', 'note' => 'Observed and advised rest.'])
            ->assertCreated();

        $this->assertTrue(
            AuditLog::where('subject_type', 'ClinicNote')->where('action', 'created')->exists(),
            'Creating a clinic note must leave an audit entry.'
        );
    }

    #[Test]
    public function notes_and_consultations_are_scoped_to_the_viewers_school(): void
    {
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $this->learner('LRN999', 'Outsider, Nina', $other);

        ClinicNote::create([
            'institution_id' => $other->id,
            'student_lrn' => 'LRN999',
            'school_year' => ClinicNote::currentSchoolYear(),
            'note' => 'Another school note',
            'author_name' => 'Other Nurse',
        ]);

        // The learner belongs to another school, so this nurse resolves nothing.
        $this->withSession($this->nurseSession())
            ->getJson('/api/student-clinic-notes?lrn=LRN999')
            ->assertOk()
            ->assertJsonCount(0, 'notes');

        $this->withSession($this->nurseSession())
            ->postJson('/api/student-clinic-notes', ['lrn' => 'LRN999', 'note' => 'Should not attach'])
            ->assertNotFound();

        $this->withSession($this->nurseSession())
            ->getJson('/api/student-consultations?lrn=LRN999')
            ->assertOk()
            ->assertJsonCount(0, 'consultations');
    }

    #[Test]
    public function only_clinic_roles_may_read_or_write_clinic_notes(): void
    {
        $this->learner();

        foreach (['class_adviser', 'school_head', 'feeding_coor', 'nutricor'] as $role) {
            $session = array_merge($this->nurseSession(), ['active_role' => $role]);

            $this->withSession($session)->getJson('/api/student-clinic-notes?lrn=LRN001')->assertForbidden();
            $this->withSession($session)->getJson('/api/student-consultations?lrn=LRN001')->assertForbidden();
            $this->withSession($session)
                ->postJson('/api/student-clinic-notes', ['lrn' => 'LRN001', 'note' => 'nope'])
                ->assertForbidden();
        }

        $this->withSession(array_merge($this->nurseSession(), ['active_role' => 'clinic_staff']))
            ->getJson('/api/student-clinic-notes?lrn=LRN001')
            ->assertOk();
    }

    #[Test]
    public function the_consultation_log_returns_only_this_learners_visits(): void
    {
        $this->learner('LRN001', 'Dela Cruz, Juan');
        $this->learner('LRN002', 'Reyes, Maria');

        Consultation::create([
            'institution_id' => $this->institution->id,
            'consulted_at' => now()->subDay(),
            // Spacing and case differ from the stored record on purpose.
            'student_name' => 'dela cruz,  juan',
            'grade_section' => 'Grade 10 - Dalton',
            'condition' => 'Asthma',
            'treatment_given' => 'Salbutamol inhaler',
            'status' => 'referred',
        ]);

        Consultation::create([
            'institution_id' => $this->institution->id,
            'consulted_at' => now(),
            'student_name' => 'Reyes, Maria',
            'grade_section' => 'Grade 10 - Dalton',
            'condition' => 'Headache',
            'treatment_given' => 'Paracetamol',
            'status' => 'treated',
        ]);

        $response = $this->withSession($this->nurseSession())
            ->getJson('/api/student-consultations?lrn=LRN001')
            ->assertOk()
            ->assertJsonCount(1, 'consultations');

        $this->assertSame('Asthma', $response->json('consultations.0.condition'));
        $this->assertSame('referred', $response->json('consultations.0.status'));
        $response->assertDontSee('Headache');
    }

    #[Test]
    public function a_missing_or_unknown_lrn_returns_empty_rather_than_erroring(): void
    {
        foreach (['', 'NOT-A-LEARNER'] as $lrn) {
            $this->withSession($this->nurseSession())
                ->getJson('/api/student-clinic-notes?lrn='.$lrn)
                ->assertOk()
                ->assertJsonCount(0, 'notes');

            $this->withSession($this->nurseSession())
                ->getJson('/api/student-consultations?lrn='.$lrn)
                ->assertOk()
                ->assertJsonCount(0, 'consultations');
        }
    }

    #[Test]
    public function an_empty_note_is_rejected(): void
    {
        $this->learner();

        $this->withSession($this->nurseSession())
            ->postJson('/api/student-clinic-notes', ['lrn' => 'LRN001', 'note' => ''])
            ->assertStatus(422);

        $this->assertSame(0, ClinicNote::count());
    }
}
