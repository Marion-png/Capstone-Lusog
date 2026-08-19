<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Models\StudentIncidentReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Incident Report tab on the class adviser's student profile.
 *
 * It is the one tab on that page the adviser writes: they are the person in
 * the room when something happens. Clinic notes, consultations and Sheet 2
 * are other desks' records and stay read-only.
 *
 * Everything a person typed here is personal information about a child, so
 * it is encrypted at rest and every write is audited, and the tab is scoped
 * twice like every other adviser surface — to the school, and within it to
 * the adviser's own class.
 */
class AdviserIncidentReportTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function adviserSession(array $overrides = []): array
    {
        return array_merge([
            'active_role' => 'class_adviser',
            'active_name' => 'Maria Santos',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
            'assigned_grade_level' => 'Grade 10',
            'assigned_section' => 'Dalton',
            'school_health_card_records' => [[
                'lrn' => '900000000001',
                'first_name' => 'Juan',
                'last_name' => 'Cruz',
                'grade_level' => 'Grade 10',
                'section' => 'Dalton',
            ]],
        ], $overrides);
    }

    private function learner(string $lrn = '900000000001', string $section = 'Grade 10 / Dalton'): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'student_id' => $lrn,
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

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'occurred_at' => now()->subDay()->toDateString(),
            'category' => 'injury',
            'severity' => 'moderate',
            'location' => 'Covered court',
            'description' => 'Fell during PE and grazed the left knee.',
            'action_taken' => 'Sent to the clinic; guardian called.',
            'witnesses' => 'Ana Reyes',
            'guardian_notified' => '1',
        ], $overrides);
    }

    private function file(array $overrides = [], ?array $session = null)
    {
        return $this->withSession($session ?? $this->adviserSession())
            ->postJson(route('student-incidents.store', '900000000001'), $this->payload($overrides));
    }

    #[Test]
    public function the_profile_carries_an_incident_report_tab(): void
    {
        $this->learner();

        $html = $this->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser.student-profile', '900000000001'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-panel="vpTabIncidents"', $html);
        $this->assertStringContainsString('id="vpTabIncidents"', $html);
        $this->assertStringContainsString('File an Incident Report', $html);
        $this->assertStringContainsString('id="incidentForm"', $html);
    }

    #[Test]
    public function an_adviser_can_file_a_report(): void
    {
        $this->learner();

        $this->file()->assertCreated();

        $report = StudentIncidentReport::first();

        $this->assertNotNull($report);
        $this->assertSame('900000000001', $report->student_lrn);
        $this->assertSame($this->school->id, $report->institution_id);
        $this->assertSame('injury', $report->category);
        $this->assertSame('moderate', $report->severity);
        $this->assertSame('Fell during PE and grazed the left knee.', $report->description);
        $this->assertTrue($report->guardian_notified);
    }

    /** A filer cannot sign somebody else's name to a report about a child. */
    #[Test]
    public function attribution_comes_from_the_session_not_the_form(): void
    {
        $this->learner();

        $this->file(['reported_by_name' => 'Someone Else', 'reported_by_role' => 'school_head'])
            ->assertCreated();

        $report = StudentIncidentReport::first();

        $this->assertSame('Maria Santos', $report->reported_by_name);
        $this->assertSame('class_adviser', $report->reported_by_role);
    }

    /** An incident is something that already happened. */
    #[Test]
    public function a_future_incident_is_refused(): void
    {
        $this->learner();

        $this->file(['occurred_at' => now()->addWeek()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('occurred_at');

        $this->assertSame(0, StudentIncidentReport::count());
    }

    /** The catalogue is fixed, so a report cannot be filed under nothing. */
    #[Test]
    public function an_unknown_category_or_severity_is_refused(): void
    {
        $this->learner();

        $this->file(['category' => 'whatever'])->assertStatus(422);
        $this->file(['severity' => 'catastrophic'])->assertStatus(422);

        $this->assertSame(0, StudentIncidentReport::count());
    }

    #[Test]
    public function the_description_is_required(): void
    {
        $this->learner();

        $this->file(['description' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('description');
    }

    /** Newest incident first, so the history reads the way it is asked about. */
    #[Test]
    public function the_list_is_newest_first(): void
    {
        $this->learner();

        $this->file(['occurred_at' => now()->subDays(9)->toDateString(), 'description' => 'Oldest'])->assertCreated();
        $this->file(['occurred_at' => now()->subDay()->toDateString(), 'description' => 'Newest'])->assertCreated();
        $this->file(['occurred_at' => now()->subDays(4)->toDateString(), 'description' => 'Middle'])->assertCreated();

        $reports = $this->withSession($this->adviserSession())
            ->getJson(route('student-incidents.index', '900000000001'))
            ->assertOk()
            ->json('reports');

        $this->assertSame(['Newest', 'Middle', 'Oldest'], array_column($reports, 'description'));
    }

    /** Personal information about a child is never readable on disk. */
    #[Test]
    public function what_a_person_wrote_is_encrypted_at_rest(): void
    {
        $this->learner();
        $this->file()->assertCreated();

        $raw = DB::table('student_incident_reports')->first();

        foreach (['description', 'action_taken', 'location', 'witnesses', 'reported_by_name'] as $column) {
            $this->assertNotEmpty($raw->{$column});
        }

        $this->assertStringNotContainsString('grazed the left knee', (string) $raw->description);
        $this->assertStringNotContainsString('Sent to the clinic', (string) $raw->action_taken);
        $this->assertStringNotContainsString('Covered court', (string) $raw->location);
        $this->assertStringNotContainsString('Ana Reyes', (string) $raw->witnesses);
        $this->assertStringNotContainsString('Maria Santos', (string) $raw->reported_by_name);

        // The lookup keys stay plain, or nothing could find the row.
        $this->assertSame('900000000001', $raw->student_lrn);
        $this->assertSame('injury', $raw->category);
    }

    #[Test]
    public function filing_a_report_is_audited(): void
    {
        $this->learner();
        $this->file()->assertCreated();

        $this->assertTrue(
            AuditLog::where('subject_type', 'StudentIncidentReport')->exists(),
            'Filing an incident report must leave an audit entry.'
        );
    }

    /**
     * Withdrawing is for a report filed by mistake, and the delete is audited
     * — an incident log you can silently empty is not one.
     */
    #[Test]
    public function a_report_can_be_withdrawn_and_the_withdrawal_is_audited(): void
    {
        $this->learner();
        $this->file()->assertCreated();

        $report = StudentIncidentReport::first();

        $this->withSession($this->adviserSession())
            ->deleteJson(route('student-incidents.destroy', ['lrn' => '900000000001', 'id' => $report->id]))
            ->assertOk()
            ->assertJsonCount(0, 'reports');

        $this->assertSame(0, StudentIncidentReport::count());
        $this->assertSame(
            2,
            AuditLog::where('subject_type', 'StudentIncidentReport')->count(),
            'The filing and the withdrawal are both on the record.'
        );
    }

    /** Another school's learner is not reachable. */
    #[Test]
    public function another_schools_learner_is_refused(): void
    {
        $other = Institution::create(['name' => 'Wireless ES', 'status' => 'active']);

        StudentHealthRecord::create([
            'institution_id' => $other->id,
            'student_id' => '900000000009',
            'student_name' => 'Other, Learner',
            'school_name' => 'Wireless ES',
            'grade_level' => 'Grade 7',
            'section' => 'Grade 7 / Rizal',
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'weight' => '35',
            'bmi_value' => '17',
            'nutritional_status' => 'Normal',
        ]);

        $this->withSession($this->adviserSession())
            ->postJson(route('student-incidents.store', '900000000009'), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, StudentIncidentReport::count());
    }

    /** And neither is a colleague's class in the same school. */
    #[Test]
    public function another_advisers_class_is_refused(): void
    {
        $this->learner('900000000001', 'Grade 10 / Rizal');

        $this->withSession($this->adviserSession())
            ->postJson(route('student-incidents.store', '900000000001'), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, StudentIncidentReport::count());
    }

    /**
     * Only the adviser files these. The nurse and clinic staff have their own
     * logs, and this endpoint is not a second way into a learner's record.
     */
    #[Test]
    public function another_role_cannot_file_or_read(): void
    {
        $this->learner();

        foreach (['school_nurse', 'clinic_staff', 'school_head', 'feeding_coor'] as $role) {
            $session = [
                'active_role' => $role,
                'active_name' => 'Someone',
                'active_school_name' => 'Sta. Ana NHS',
                'active_institution_id' => $this->school->id,
            ];

            $this->withSession($session)
                ->postJson(route('student-incidents.store', '900000000001'), $this->payload())
                ->assertForbidden();

            $this->withSession($session)
                ->getJson(route('student-incidents.index', '900000000001'))
                ->assertForbidden();
        }

        $this->assertSame(0, StudentIncidentReport::count());
    }

    /** A withdrawal cannot reach across schools by id. */
    #[Test]
    public function a_report_from_another_school_cannot_be_withdrawn(): void
    {
        $other = Institution::create(['name' => 'Wireless ES', 'status' => 'active']);

        $foreign = StudentIncidentReport::create([
            'institution_id' => $other->id,
            'student_lrn' => '900000000001',
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'occurred_at' => now()->subDay()->toDateString(),
            'category' => 'injury',
            'severity' => 'minor',
            'description' => 'Another school\'s report.',
        ]);

        $this->learner();

        $this->withSession($this->adviserSession())
            ->deleteJson(route('student-incidents.destroy', ['lrn' => '900000000001', 'id' => $foreign->id]))
            ->assertNotFound();

        $this->assertSame(1, StudentIncidentReport::count());
    }
}
