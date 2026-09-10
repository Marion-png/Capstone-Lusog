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
 * Incident Reports, charted in FDAR.
 *
 * **The nurse writes; the adviser reads.** Focus / Data / Action / Response is
 * clinical documentation — reading what happened to a learner, recording what
 * was done and whether it worked is the nurse's assessment to make. The class
 * adviser sees the same reports on their own profile, read-only, because an
 * incident involving a learner in their class is something they have to know
 * about.
 *
 * Everything a person typed here is personal information about a child, so it
 * is encrypted at rest and every write is audited. Both roles are scoped to
 * their school; the adviser is scoped a second time to their own class, and
 * the nurse holds no class, so the school is their whole scope.
 *
 * This replaces AdviserIncidentReportTest, which pinned the opposite
 * ownership — the adviser filing and the nurse locked out.
 */
class StudentIncidentReportTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function nurseSession(array $overrides = []): array
    {
        return array_merge([
            'active_role' => 'school_nurse',
            'active_name' => 'Nurse Reyes',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
        ], $overrides);
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

    /** A full FDAR chart: focus, data, action, response. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'occurred_at' => now()->subDay()->toDateString(),
            'severity' => 'moderate',
            'location' => 'Covered court',
            // F
            'category' => 'injury',
            // D
            'description' => 'Abrasion 2cm on the left knee after a fall during PE. Reports pain 3/10.',
            // A
            'action_taken' => 'Wound cleaned with sterile saline and dressed. Rested 15 minutes.',
            // R
            'response' => 'Pain settled, no further bleeding. Returned to class at 10:20.',
            'witnesses' => 'Ana Reyes',
            'guardian_notified' => '1',
        ], $overrides);
    }

    private function file(array $overrides = [], ?array $session = null)
    {
        return $this->withSession($session ?? $this->nurseSession())
            ->postJson(route('student-incidents.store', '900000000001'), $this->payload($overrides));
    }

    // ── FDAR ────────────────────────────────────────────────────────────────

    /**
     * The chart has four parts and the form names all four by their letter.
     * Focus stays the fixed catalogue — the list is filtered by it.
     */
    #[Test]
    public function the_form_is_charted_in_fdar(): void
    {
        $this->learner();

        $html = $this->withSession($this->nurseSession())
            ->get(route('dashboard.student-health-records'))
            ->assertOk()
            ->getContent();

        foreach (['>Focus<', '>Data<', '>Action<', '>Response<'] as $section) {
            $this->assertStringContainsString($section, $html, "The chart must carry {$section}.");
        }

        $this->assertStringContainsString('id="incidentCategory"', $html);
        $this->assertStringContainsString('id="incidentDescription"', $html);
        $this->assertStringContainsString('id="incidentAction"', $html);
        $this->assertStringContainsString('id="incidentResponse"', $html);
    }

    /** The Response is stored, encrypted, and read back. */
    #[Test]
    public function the_response_is_charted_and_returned(): void
    {
        $this->learner();

        $this->file()->assertCreated()->assertJsonPath(
            'report.response',
            'Pain settled, no further bleeding. Returned to class at 10:20.'
        );

        $report = StudentIncidentReport::firstOrFail();
        $this->assertSame('Pain settled, no further bleeding. Returned to class at 10:20.', $report->response);
    }

    /**
     * A report filed the moment something happens has no outcome yet. Refusing
     * it until one exists would lose the record of the incident itself.
     */
    #[Test]
    public function a_chart_with_no_response_yet_is_still_filed(): void
    {
        $this->learner();

        $this->file(['response' => '', 'action_taken' => ''])->assertCreated();

        $this->assertSame(1, StudentIncidentReport::count());
    }

    /** Data is the one part that cannot be left out. */
    #[Test]
    public function the_data_section_is_required(): void
    {
        $this->learner();

        $this->file(['description' => ''])->assertStatus(422)->assertJsonValidationErrors('description');

        $this->assertSame(0, StudentIncidentReport::count());
    }

    // ── Who writes ──────────────────────────────────────────────────────────

    #[Test]
    public function the_nurse_can_chart_an_incident(): void
    {
        $this->learner();

        $this->file()->assertCreated();

        $report = StudentIncidentReport::firstOrFail();
        $this->assertSame('900000000001', $report->student_lrn);
        $this->assertSame($this->school->id, $report->institution_id);
        $this->assertSame('injury', $report->category);
    }

    /**
     * The adviser reads and does not write. This is the ownership flip: they
     * used to be the only role that could file one.
     */
    #[Test]
    public function the_adviser_cannot_file_or_withdraw(): void
    {
        $this->learner();
        $this->file()->assertCreated();
        $report = StudentIncidentReport::firstOrFail();

        $this->withSession($this->adviserSession())
            ->postJson(route('student-incidents.store', '900000000001'), $this->payload())
            ->assertForbidden();

        $this->withSession($this->adviserSession())
            ->deleteJson(route('student-incidents.destroy', ['lrn' => '900000000001', 'id' => $report->id]))
            ->assertForbidden();

        $this->assertSame(1, StudentIncidentReport::count(), 'The adviser must not be able to file or withdraw.');
    }

    /** The adviser reads what the nurse charted, on their own profile. */
    #[Test]
    public function the_adviser_reads_the_nurses_chart(): void
    {
        $this->learner();
        $this->file()->assertCreated();

        $this->withSession($this->adviserSession())
            ->getJson(route('student-incidents.index', '900000000001'))
            ->assertOk()
            ->assertJsonPath('can_file', false)
            ->assertJsonPath('reports.0.category_label', 'Injury / Accident')
            ->assertJsonPath('reports.0.response', 'Pain settled, no further bleeding. Returned to class at 10:20.');
    }

    /**
     * A reader is drawn no form and no File button. A control the endpoint
     * would refuse is a control that should not be on the page.
     */
    #[Test]
    public function the_advisers_panel_renders_read_only(): void
    {
        $this->learner();

        $html = $this->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser.student-profile', '900000000001'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Incident Reports', $html);
        $this->assertStringNotContainsString('id="incidentForm"', $html);
        $this->assertStringNotContainsString('File an Incident Report', $html);
        $this->assertStringContainsString('id="incidentReadOnly"', $html);
    }

    /** The nurse's panel is the writing one. */
    #[Test]
    public function the_nurses_panel_carries_the_form(): void
    {
        $this->learner();

        $html = $this->withSession($this->nurseSession())
            ->get(route('dashboard.student-health-records'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="incidentForm"', $html);
        $this->assertStringContainsString('File an Incident Report', $html);
        $this->assertStringContainsString('data-panel="p-incidents"', $html);
    }

    #[Test]
    public function no_other_role_can_read_or_write(): void
    {
        $this->learner();

        foreach (['clinic_staff', 'school_head', 'feeding_coor', 'nutricor'] as $role) {
            $session = [
                'active_role' => $role,
                'active_name' => 'Someone',
                'active_institution_id' => $this->school->id,
            ];

            $this->withSession($session)
                ->getJson(route('student-incidents.index', '900000000001'))
                ->assertForbidden();

            $this->withSession($session)
                ->postJson(route('student-incidents.store', '900000000001'), $this->payload())
                ->assertForbidden();
        }

        $this->assertSame(0, StudentIncidentReport::count());
    }

    // ── Attribution, validation, ordering ───────────────────────────────────

    #[Test]
    public function attribution_comes_from_the_session_not_the_form(): void
    {
        $this->learner();

        $this->file(['reported_by_name' => 'Somebody Else'])->assertCreated();

        $report = StudentIncidentReport::firstOrFail();
        $this->assertSame('Nurse Reyes', $report->reported_by_name);
        $this->assertSame('school_nurse', $report->reported_by_role);
    }

    #[Test]
    public function a_future_incident_is_refused(): void
    {
        $this->learner();

        $this->file(['occurred_at' => now()->addDay()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('occurred_at');

        $this->assertSame(0, StudentIncidentReport::count());
    }

    #[Test]
    public function an_unknown_focus_or_severity_is_refused(): void
    {
        $this->learner();

        $this->file(['category' => 'not-a-focus'])->assertStatus(422)->assertJsonValidationErrors('category');
        $this->file(['severity' => 'catastrophic'])->assertStatus(422)->assertJsonValidationErrors('severity');

        $this->assertSame(0, StudentIncidentReport::count());
    }

    #[Test]
    public function the_list_is_newest_first(): void
    {
        $this->learner();

        $this->file(['occurred_at' => now()->subDays(5)->toDateString(), 'description' => 'Older'])->assertCreated();
        $this->file(['occurred_at' => now()->subDay()->toDateString(), 'description' => 'Newer'])->assertCreated();

        $this->withSession($this->nurseSession())
            ->getJson(route('student-incidents.index', '900000000001'))
            ->assertOk()
            ->assertJsonPath('reports.0.description', 'Newer')
            ->assertJsonPath('reports.1.description', 'Older');
    }

    // ── The invariants this record has to keep ──────────────────────────────

    /**
     * Everything a person wrote about a child is encrypted at rest — the new
     * Response column with the rest of them.
     */
    #[Test]
    public function what_a_person_wrote_is_encrypted_at_rest(): void
    {
        $this->learner();
        $this->file()->assertCreated();

        $row = DB::table('student_incident_reports')->first();

        foreach (['location', 'description', 'action_taken', 'response', 'witnesses', 'reported_by_name'] as $column) {
            $this->assertNotEmpty($row->{$column}, "{$column} must be stored.");
            $this->assertStringStartsWith(
                'eyJpdiI6',
                (string) $row->{$column},
                "{$column} must be encrypted at rest."
            );
        }

        // Lookup keys stay plain — the list is filtered and ordered on them.
        $this->assertSame('900000000001', $row->student_lrn);
        $this->assertSame('injury', $row->category);
    }

    #[Test]
    public function charting_a_report_is_audited(): void
    {
        $this->learner();
        $this->file()->assertCreated();

        $this->assertTrue(
            AuditLog::query()->where('subject_type', class_basename(StudentIncidentReport::class))->exists(),
            'Filing a report must leave an audit entry.'
        );
    }

    #[Test]
    public function a_report_can_be_withdrawn_by_the_nurse_and_the_withdrawal_is_audited(): void
    {
        $this->learner();
        $this->file()->assertCreated();
        $report = StudentIncidentReport::firstOrFail();

        $this->withSession($this->nurseSession())
            ->deleteJson(route('student-incidents.destroy', ['lrn' => '900000000001', 'id' => $report->id]))
            ->assertOk();

        $this->assertSame(0, StudentIncidentReport::count());
        $this->assertTrue(
            AuditLog::query()
                ->where('subject_type', class_basename(StudentIncidentReport::class))
                ->where('action', 'deleted')
                ->exists(),
            'A withdrawn report must leave a record that it existed.'
        );
    }

    // ── Scope ───────────────────────────────────────────────────────────────

    #[Test]
    public function another_schools_learner_is_refused(): void
    {
        $other = Institution::create(['name' => 'Other NHS', 'status' => 'active']);

        StudentHealthRecord::create([
            'institution_id' => $other->id,
            'student_id' => '900000000002',
            'student_name' => 'Reyes, Ana',
            'school_name' => 'Other NHS',
            'grade_level' => 'Grade 10',
            'section' => 'Grade 10 / Dalton',
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'weight' => '40',
            'bmi_value' => '18',
            'nutritional_status' => 'Normal',
        ]);

        $this->withSession($this->nurseSession())
            ->getJson(route('student-incidents.index', '900000000002'))
            ->assertForbidden();

        $this->withSession($this->nurseSession())
            ->postJson(route('student-incidents.store', '900000000002'), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, StudentIncidentReport::count());
    }

    /** The adviser's second lock: their own class, and no colleague's. */
    #[Test]
    public function another_advisers_class_is_refused(): void
    {
        $this->learner('900000000003', 'Grade 10 / Rizal');

        $this->withSession($this->adviserSession())
            ->getJson(route('student-incidents.index', '900000000003'))
            ->assertForbidden();
    }

    /**
     * The nurse holds no class, so the school is their whole scope — a learner
     * in any section of their own school is theirs to chart.
     */
    #[Test]
    public function the_nurse_is_scoped_to_the_school_not_to_a_class(): void
    {
        $this->learner('900000000004', 'Grade 7 / Rizal');

        $this->withSession($this->nurseSession())
            ->postJson(route('student-incidents.store', '900000000004'), $this->payload())
            ->assertCreated();

        $this->assertSame(1, StudentIncidentReport::count());
    }

    #[Test]
    public function a_report_from_another_school_cannot_be_withdrawn(): void
    {
        $other = Institution::create(['name' => 'Other NHS', 'status' => 'active']);
        $this->learner();

        $foreign = StudentIncidentReport::create([
            'institution_id' => $other->id,
            'student_lrn' => '900000000001',
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'occurred_at' => now()->subDay()->toDateString(),
            'category' => 'injury',
            'severity' => 'minor',
            'description' => 'Another school\'s report.',
        ]);

        $this->withSession($this->nurseSession())
            ->deleteJson(route('student-incidents.destroy', ['lrn' => '900000000001', 'id' => $foreign->id]))
            ->assertNotFound();

        $this->assertSame(1, StudentIncidentReport::count());
    }
}
