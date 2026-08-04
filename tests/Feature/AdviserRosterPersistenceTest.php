<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adviser-entered student data must survive session loss — expiry,
 * re-login, and server restarts. The database is the source of truth and
 * the session roster is rebuilt from it on every adviser page load.
 */
class AdviserRosterPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    private function adviserSession(): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Test Adviser',
            'active_username' => 'adviser1',
            'active_institution_id' => $this->institution->id,
            'active_school_name' => 'Test School',
            'assigned_school_name' => 'Test School',
            'assigned_grade_level' => 'Grade 1',
            'assigned_section' => 'Sampaguita',
        ];
    }

    private function submitStudent(): void
    {
        $this->withSession($this->adviserSession())
            ->post(route('adviser.store'), [
                'last_name' => 'Dela Cruz',
                'first_name' => 'Juan',
                'middle_name' => 'A',
                'lrn' => '123456789012',
                'birth_date' => '2015-06-01',
                'birthplace' => 'Davao City',
                'parent_guardian' => 'Maria Dela Cruz',
                'address' => '123 Mabini St., Davao City',
                'region' => 'XI',
                'division' => 'Davao City',
                'telephone_no' => '09171234567',
                'gender' => 'Male',
                'height_cm' => 110,
                'weight_kg' => 18.5,
                'grade_level' => 'Grade 1',
                'section' => 'Sampaguita',
            ])
            ->assertRedirect(route('dashboard.class-adviser'));
    }

    /** @test */
    public function the_full_student_entry_is_persisted_to_the_database(): void
    {
        $this->submitStudent();

        $record = StudentHealthRecord::where('student_id', '123456789012')->first();
        $this->assertNotNull($record);

        $details = $record->student_details;
        $this->assertSame('Dela Cruz', $details['last_name']);
        $this->assertSame('Maria Dela Cruz', $details['parent_guardian']);
        $this->assertSame('123 Mabini St., Davao City', $details['address']);
        $this->assertSame('09171234567', $details['telephone_no']);
        $this->assertSame('Male', $details['gender']);
    }

    /** @test */
    public function the_roster_reappears_after_the_session_is_lost(): void
    {
        $this->submitStudent();

        // Simulate a server restart / expired session: a brand-new session
        // containing only the login keys, no roster.
        $response = $this->flushSession()
            ->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser'));

        $response->assertOk();
        $response->assertSee('Dela Cruz');
        $response->assertSee('123456789012');

        // The rebuilt session row restores the full entry, not just the name.
        $roster = collect(session('school_health_card_records'));
        $row = $roster->first(fn ($r) => ($r['lrn'] ?? '') === '123456789012');
        $this->assertNotNull($row);
        $this->assertSame('Maria Dela Cruz', $row['parent_guardian']);
        $this->assertSame('09171234567', $row['telephone_no']);
    }

    /** @test */
    public function the_consent_form_student_list_also_survives_session_loss(): void
    {
        $this->submitStudent();

        $response = $this->flushSession()
            ->withSession($this->adviserSession())
            ->get(route('consent-forms.index'));

        $response->assertOk();
        $response->assertSee('123456789012');
    }

    /** @test */
    public function another_schools_adviser_does_not_receive_the_roster(): void
    {
        $this->submitStudent();

        $otherSchool = Institution::create(['name' => 'Other School', 'status' => 'active']);

        $this->flushSession()
            ->withSession(array_merge($this->adviserSession(), [
                'active_institution_id' => $otherSchool->id,
            ]))
            ->get(route('dashboard.class-adviser'));

        $this->assertSame([], session('school_health_card_records', []));
    }
}
