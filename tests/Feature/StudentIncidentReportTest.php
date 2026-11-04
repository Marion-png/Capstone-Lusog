<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Models\StudentIncidentReport;
use App\Support\SchemaCache;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Incident Reports, charted in FDAR.
 *
 * **The clinic writes; the adviser reads.** Focus / Data / Action / Response is
 * clinical documentation — reading what happened to a learner, recording what
 * was done and whether it worked is the clinic's assessment to make: the
 * nurse's, or the clinic teacher's while they cover. Withdrawing a report stays
 * the nurse's. Clinic staff read the same reports, read-only. The class adviser
 * sees them on their own profile, read-only, because an incident involving a
 * learner in their class is something they have to know about.
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

        // The sheet's own first column and the nurse's focus statement, and
        // the D / A / R rows headed as the Progress Notes column they are.
        $this->assertStringContainsString('id="incidentDate"', $html);
        $this->assertStringContainsString('id="incidentTime"', $html);
        $this->assertStringContainsString('name="occurred_time"', $html);
        $this->assertStringContainsString('id="incidentFocus"', $html);
        $this->assertStringContainsString('name="focus"', $html);
        $this->assertStringContainsString('>Date and time<', $html);
        $this->assertStringContainsString('Progress Notes', $html);
    }

    /**
     * F-DAR's first column is the date AND the time, and the Focus is the
     * nurse's own statement — a sign, a behaviour, a treatment event — with
     * the kind of incident as the fallback where none was written.
     */
    #[Test]
    public function the_time_and_the_focus_statement_are_charted_and_read_back(): void
    {
        $this->learner();

        $this->file(['occurred_time' => '10:20', 'focus' => 'Abrasion, left knee'])
            ->assertCreated()
            ->assertJsonPath('report.occurred_time', '10:20')
            ->assertJsonPath('report.occurred_time_label', '10:20 AM')
            ->assertJsonPath('report.focus', 'Abrasion, left knee')
            ->assertJsonPath('report.focus_label', 'Abrasion, left knee')
            ->assertJsonPath('report.category_label', 'Injury / Accident')
            ->assertJsonPath('report.reported_by_title', 'School Nurse');

        // Neither is required: a note with no clock reading and no statement
        // still files, prints no invented time, and is focused on its kind.
        $this->file(['occurred_time' => '', 'focus' => ''])
            ->assertCreated()
            ->assertJsonPath('report.occurred_time', '')
            ->assertJsonPath('report.occurred_time_label', '')
            ->assertJsonPath('report.focus', '')
            ->assertJsonPath('report.focus_label', 'Injury / Accident');
    }

    /** A time still ahead of the clock on today's date is the same typo as a future date. */
    #[Test]
    public function a_time_later_than_now_today_is_refused(): void
    {
        // 9:00 in the morning on the school's clock.
        $this->travelTo(Carbon::parse('2026-10-27 09:00', 'Asia/Manila'));
        $this->learner();

        $this->file(['occurred_at' => '2026-10-27', 'occurred_time' => '11:30'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('occurred_time');

        // The same time yesterday, or an earlier time today, is fine.
        $this->file(['occurred_at' => '2026-10-26', 'occurred_time' => '11:30'])->assertCreated();
        $this->file(['occurred_at' => '2026-10-27', 'occurred_time' => '08:15'])->assertCreated();

        $this->file(['occurred_time' => 'ten past'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('occurred_time');
    }

    /**
     * The clock is the school's, not the server's. The server keeps UTC,
     * eight hours behind Davao: at 12:14 PM there it reads 04:14, and every
     * time charted after four in the morning used to be refused as "later than
     * now" — and before eight, today's date was refused as a future one.
     */
    #[Test]
    public function the_time_is_judged_on_the_schools_clock_not_the_servers(): void
    {
        // 12:14 PM on 27 Oct in Davao.
        $this->travelTo(Carbon::parse('2026-10-27 04:14:00', 'UTC'));
        $this->learner();

        $this->file(['occurred_at' => '2026-10-27', 'occurred_time' => '11:30'])
            ->assertCreated()
            ->assertJsonPath('report.filed_label', '27 Oct 2026, 12:14 PM');

        $this->file(['occurred_at' => '2026-10-27', 'occurred_time' => '12:30'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('occurred_time');

        // 6:00 AM on 27 Oct in Davao, still the 26th on the server.
        $this->travelTo(Carbon::parse('2026-10-26 22:00:00', 'UTC'));

        $this->file(['occurred_at' => '2026-10-27', 'occurred_time' => '05:30'])->assertCreated();
        $this->file(['occurred_at' => '2026-10-28'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('occurred_at');

        // And the date picker offers today, not yesterday.
        $this->withSession($this->nurseSession())
            ->get(route('dashboard.student-health-records'))
            ->assertOk()
            ->assertSee('max="2026-10-27"', false);
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
        $this->assertStringContainsString('data-can-withdraw="1"', $html);
    }

    // ── The clinic teacher ──────────────────────────────────────────────────

    private function clinicTeacherSession(): array
    {
        return [
            'active_role' => 'clinic_teacher',
            'active_name' => 'Teacher Lim',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
        ];
    }

    /**
     * A clinic teacher covering the clinic charts an incident, and the report
     * is signed as theirs — not the nurse's — so whoever reads it later knows
     * who was there.
     */
    #[Test]
    public function the_clinic_teacher_can_chart_an_incident(): void
    {
        $this->learner();

        $this->file([], $this->clinicTeacherSession())
            ->assertCreated()
            ->assertJsonPath('report.reported_by', 'Teacher Lim')
            ->assertJsonPath('report.reported_by_title', 'Clinic Teacher');

        $this->assertSame('clinic_teacher', StudentIncidentReport::firstOrFail()->reported_by_role);
    }

    /** They read the learner's every report, the nurse's included. */
    #[Test]
    public function the_clinic_teacher_reads_every_report(): void
    {
        $this->learner();
        $this->file()->assertCreated();

        $this->withSession($this->clinicTeacherSession())
            ->getJson(route('student-incidents.index', '900000000001'))
            ->assertOk()
            ->assertJsonPath('can_file', true)
            ->assertJsonPath('can_withdraw', false)
            ->assertJsonPath('reports.0.reported_by', 'Nurse Reyes')
            ->assertJsonPath('reports.0.response', 'Pain settled, no further bleeding. Returned to class at 10:20.');
    }

    /**
     * Withdrawing takes a clinical note off a child's record, so it stays the
     * nurse's: a clinic teacher can chart while covering, not remove what the
     * nurse charted — nor their own, which the nurse can withdraw if it was a
     * mistake.
     */
    #[Test]
    public function the_clinic_teacher_cannot_withdraw_a_report(): void
    {
        $this->learner();
        $this->file()->assertCreated();
        $this->file([], $this->clinicTeacherSession())->assertCreated();

        foreach (StudentIncidentReport::all() as $report) {
            $this->withSession($this->clinicTeacherSession())
                ->deleteJson(route('student-incidents.destroy', ['lrn' => '900000000001', 'id' => $report->id]))
                ->assertForbidden();
        }

        $this->assertSame(2, StudentIncidentReport::count());

        // The nurse still can.
        $this->withSession($this->nurseSession())
            ->deleteJson(route('student-incidents.destroy', ['lrn' => '900000000001', 'id' => StudentIncidentReport::max('id')]))
            ->assertOk();
        $this->assertSame(1, StudentIncidentReport::count());
    }

    /** Their panel carries the form, and draws no Withdraw button. */
    #[Test]
    public function the_clinic_teachers_panel_carries_the_form_without_withdraw(): void
    {
        $this->learner();

        $html = $this->withSession($this->clinicTeacherSession())
            ->get(route('dashboard.student-health-records'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="incidentForm"', $html);
        $this->assertStringContainsString('File an Incident Report', $html);
        $this->assertStringContainsString('data-can-withdraw="0"', $html);
        $this->assertStringContainsString('Withdrawing a report is the school nurse\'s.', $html);
    }

    // ── Editing ─────────────────────────────────────────────────────────────

    private function edit(int $id, array $overrides = [], ?array $session = null, string $lrn = '900000000001')
    {
        return $this->withSession($session ?? $this->nurseSession())
            ->postJson(route('student-incidents.update', ['lrn' => $lrn, 'id' => $id]), $this->payload($overrides));
    }

    private function filedReport(?array $session = null): StudentIncidentReport
    {
        $this->learner();
        $this->file([], $session)->assertCreated();

        return StudentIncidentReport::query()->latest('id')->firstOrFail();
    }

    #[Test]
    public function the_nurse_can_edit_every_part_of_the_chart(): void
    {
        $report = $this->filedReport();

        $this->edit($report->id, [
            'occurred_at' => now()->subDays(3)->toDateString(),
            'occurred_time' => '08:45',
            'category' => 'illness',
            'severity' => 'serious',
            'focus' => 'Fainting at flag ceremony',
            'description' => 'Corrected: fainted during the flag ceremony.',
            'action_taken' => 'Corrected: laid flat, legs raised.',
            'response' => 'Corrected: alert after five minutes.',
            'location' => 'Quadrangle',
            'witnesses' => 'Corrected witness',
            'guardian_notified' => '0',
        ])
            ->assertOk()
            ->assertJsonPath('report.description', 'Corrected: fainted during the flag ceremony.')
            ->assertJsonPath('reports.0.focus', 'Fainting at flag ceremony');

        $report->refresh();

        $this->assertSame(now()->subDays(3)->toDateString(), $report->occurred_at->toDateString());
        $this->assertSame('08:45', substr((string) $report->occurred_time, 0, 5));
        $this->assertSame('illness', $report->category);
        $this->assertSame('serious', $report->severity);
        $this->assertSame('Corrected: laid flat, legs raised.', $report->action_taken);
        $this->assertSame('Corrected: alert after five minutes.', $report->response);
        $this->assertSame('Quadrangle', $report->location);
        $this->assertSame('Corrected witness', $report->witnesses);
        $this->assertFalse($report->guardian_notified);
        $this->assertSame(1, StudentIncidentReport::count(), 'An edit is never a second report.');
    }

    /**
     * Any report on the profile, the nurse's included. The signature stands, and
     * the stamp says who changed it, so a teacher's words never read as the nurse's.
     */
    #[Test]
    public function the_clinic_teacher_can_edit_the_nurses_report_and_is_named_for_it(): void
    {
        $report = $this->filedReport();

        $this->edit($report->id, ['description' => 'Corrected by the teacher.'], $this->clinicTeacherSession())
            ->assertOk()
            ->assertJsonPath('report.description', 'Corrected by the teacher.')
            ->assertJsonPath('report.reported_by', 'Nurse Reyes')
            ->assertJsonPath('report.reported_by_title', 'School Nurse')
            ->assertJsonPath('report.edited_by', 'Teacher Lim')
            ->assertJsonPath('report.edited_by_title', 'Clinic Teacher');

        $this->assertNotSame('', $this->edit($report->id, ['description' => 'Corrected by the teacher.'], $this->clinicTeacherSession())->json('report.edited_label'));
    }

    #[Test]
    public function the_nurse_can_edit_the_clinic_teachers_report(): void
    {
        $report = $this->filedReport($this->clinicTeacherSession());

        $this->edit($report->id, ['description' => 'Corrected by the nurse.'])
            ->assertOk()
            ->assertJsonPath('report.reported_by', 'Teacher Lim')
            ->assertJsonPath('report.edited_by', 'Nurse Reyes');
    }

    #[Test]
    public function no_other_role_can_edit(): void
    {
        $report = $this->filedReport();
        $before = $report->description;

        $sessions = [
            $this->clinicStaffSession(),
            $this->adviserSession(),
        ];

        foreach (['school_head', 'feeding_coor', 'nutricor'] as $role) {
            $sessions[] = ['active_role' => $role, 'active_name' => 'Other', 'active_school_name' => 'Sta. Ana NHS', 'active_institution_id' => $this->school->id];
        }

        foreach ($sessions as $session) {
            $this->edit($report->id, ['description' => 'Should not land.'], $session)->assertForbidden();
            $this->assertSame($before, $report->fresh()->description, $session['active_role'].' changed a report.');
        }
    }

    /** The panel is told, by the server, who gets the button. */
    #[Test]
    public function the_list_says_who_may_edit(): void
    {
        $this->learner();

        foreach ([
            [$this->nurseSession(), true],
            [$this->clinicTeacherSession(), true],
            [$this->clinicStaffSession(), false],
            [$this->adviserSession(), false],
        ] as [$session, $expected]) {
            $this->withSession($session)
                ->getJson(route('student-incidents.index', '900000000001'))
                ->assertOk()
                ->assertJsonPath('can_edit', $expected);
        }
    }

    #[Test]
    public function the_panel_draws_edit_for_the_nurse_and_the_clinic_teacher_only(): void
    {
        $this->learner();

        foreach ([
            [$this->nurseSession(), '1'],
            [$this->clinicTeacherSession(), '1'],
            [$this->clinicStaffSession(), '0'],
        ] as [$session, $flag]) {
            $this->withSession($session)
                ->get(route('dashboard.student-health-records'))
                ->assertOk()
                ->assertSee('data-can-edit="'.$flag.'"', false);
        }
    }

    /** An edit is held to the rules filing is. */
    #[Test]
    public function an_edit_cannot_break_the_chart(): void
    {
        $report = $this->filedReport();
        $before = $report->description;

        $this->edit($report->id, ['description' => ''])->assertUnprocessable()->assertJsonValidationErrors('description');
        $this->edit($report->id, ['occurred_at' => now()->addDays(2)->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('occurred_at');
        $this->edit($report->id, ['category' => 'nonsense'])->assertUnprocessable()->assertJsonValidationErrors('category');

        $this->assertSame($before, $report->fresh()->description);
    }

    /** Who filed it, the learner and the school are never read off the request. */
    #[Test]
    public function an_edit_cannot_move_or_resign_a_report(): void
    {
        $report = $this->filedReport();

        $this->edit($report->id, [
            'reported_by_name' => 'Somebody Else',
            'reported_by_role' => 'class_adviser',
            'student_lrn' => '900000000099',
            'institution_id' => 999,
            'school_year' => '1999-2000',
            'description' => 'Corrected.',
        ])->assertOk();

        $report->refresh();

        $this->assertSame('Nurse Reyes', $report->reported_by_name);
        $this->assertSame('school_nurse', $report->reported_by_role);
        $this->assertSame('900000000001', $report->student_lrn);
        $this->assertSame($this->school->id, $report->institution_id);
        $this->assertSame(StudentHealthRecord::currentSchoolYear(), $report->school_year);
    }

    #[Test]
    public function a_report_of_another_learner_or_school_cannot_be_edited(): void
    {
        $report = $this->filedReport();
        $this->learner('900000000002');

        // Another learner in the URL.
        $this->edit($report->id, ['description' => 'Wrong learner.'], null, '900000000002')->assertNotFound();

        // Another school's nurse, whose own school holds this learner too.
        $other = Institution::create(['name' => 'Other NHS', 'status' => 'active']);
        StudentHealthRecord::create([
            'institution_id' => $other->id, 'student_id' => '900000000001', 'student_name' => 'Cruz, Juan',
            'school_name' => 'Other NHS', 'section' => 'Grade 10 / Dalton', 'school_year' => StudentHealthRecord::currentSchoolYear(),
            'weight' => '40', 'bmi_value' => '18', 'nutritional_status' => 'Normal',
        ]);

        $this->edit($report->id, ['description' => 'Wrong school.'], $this->nurseSession(['active_institution_id' => $other->id]))->assertNotFound();

        $this->assertStringNotContainsString('Wrong', (string) $report->fresh()->description);
    }

    #[Test]
    public function an_edit_is_audited_with_the_old_and_new_values(): void
    {
        $report = $this->filedReport();
        $baseline = (int) AuditLog::max('id');
        $before = $report->description;

        $this->edit($report->id, ['description' => 'Corrected description.'], $this->clinicTeacherSession())->assertOk();

        $entry = AuditLog::query()
            ->where('id', '>', $baseline)
            ->where('subject_type', 'StudentIncidentReport')
            ->where('subject_id', $report->id)
            ->where('action', 'updated')
            ->firstOrFail();

        $this->assertSame('clinic_teacher', $entry->actor_role);
        $this->assertSame('Teacher Lim', $entry->actor_name);
        $this->assertSame($before, $entry->details['changes']['description']['from']);
        $this->assertSame('Corrected description.', $entry->details['changes']['description']['to']);
        $this->assertArrayNotHasKey('severity', $entry->details['changes'], 'A field nobody changed is not named.');
        $this->assertArrayNotHasKey('action_taken', $entry->details['changes'], 'A field nobody changed is not named.');
    }

    /** Saving without changing anything is not an edit. */
    #[Test]
    public function saving_nothing_new_stamps_nothing(): void
    {
        $report = $this->filedReport();
        $baseline = (int) AuditLog::max('id');

        $this->edit($report->id)->assertOk()->assertJsonPath('report.edited_label', '');

        $this->assertNull($report->fresh()->edited_at);
        $this->assertSame(0, AuditLog::query()
            ->where('id', '>', $baseline)
            ->where('subject_type', 'StudentIncidentReport')
            ->where('action', 'updated')
            ->count());
    }

    #[Test]
    public function what_an_edit_writes_is_encrypted_at_rest(): void
    {
        $report = $this->filedReport();

        $this->edit($report->id, ['description' => 'Secret corrected note.'], $this->clinicTeacherSession())->assertOk();

        $row = DB::table('student_incident_reports')->where('id', $report->id)->first();

        $this->assertStringNotContainsString('Secret corrected note.', (string) $row->description);
        $this->assertStringNotContainsString('Teacher Lim', (string) $row->edited_by_name);
    }

    /** Until the migration runs an edit still saves; the sheet just names nobody. */
    #[Test]
    public function an_edit_saves_before_the_stamp_columns_are_migrated(): void
    {
        $report = $this->filedReport();

        Schema::table('student_incident_reports', function (Blueprint $table) {
            $table->dropColumn(['edited_by_name', 'edited_by_role', 'edited_at']);
        });
        SchemaCache::flush();

        $this->edit($report->id, ['description' => 'Corrected before the migration.'])
            ->assertOk()
            ->assertJsonPath('report.description', 'Corrected before the migration.')
            ->assertJsonPath('report.edited_label', '');
    }

    #[Test]
    public function an_edit_route_is_refused_for_the_school_heads_session(): void
    {
        $report = $this->filedReport();

        $this->withSession(['active_role' => 'school_head', 'active_name' => 'Principal', 'active_institution_id' => $this->school->id])
            ->postJson(route('student-incidents.update', ['lrn' => '900000000001', 'id' => $report->id]), $this->payload(['description' => 'No.']))
            ->assertForbidden();

        $this->assertNotSame('No.', $report->fresh()->description);
    }

    // ── Clinic staff ────────────────────────────────────────────────────────

    private function clinicStaffSession(): array
    {
        return [
            'active_role' => 'clinic_staff',
            'active_name' => 'Staff Dizon',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
        ];
    }

    /**
     * Clinic staff chart an incident and read every report, the nurse's
     * included — but withdrawing one stays the nurse's.
     */
    #[Test]
    public function clinic_staff_file_a_report_and_cannot_withdraw(): void
    {
        $this->learner();
        $this->file()->assertCreated();
        $nursesReport = StudentIncidentReport::firstOrFail();

        $this->withSession($this->clinicStaffSession())
            ->postJson(route('student-incidents.store', '900000000001'), $this->payload())
            ->assertCreated();

        $this->withSession($this->clinicStaffSession())
            ->getJson(route('student-incidents.index', '900000000001'))
            ->assertOk()
            ->assertJsonPath('can_file', true)
            ->assertJsonPath('can_withdraw', false)
            ->assertJsonCount(2, 'reports')
            ->assertJsonFragment(['reported_by' => 'Staff Dizon', 'reported_by_title' => 'Clinic Staff'])
            ->assertJsonFragment(['reported_by' => 'Nurse Reyes', 'reported_by_title' => 'School Nurse']);

        $this->withSession($this->clinicStaffSession())
            ->deleteJson(route('student-incidents.destroy', ['lrn' => '900000000001', 'id' => $nursesReport->id]))
            ->assertForbidden();

        $this->assertSame(2, StudentIncidentReport::count());
    }

    /** Their panel carries the form, and draws no Withdraw button. */
    #[Test]
    public function clinic_staffs_panel_carries_the_form_without_withdraw(): void
    {
        $this->learner();

        $html = $this->withSession($this->clinicStaffSession())
            ->get(route('dashboard.student-health-records'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-panel="p-incidents"', $html);
        $this->assertStringContainsString('id="incidentForm"', $html);
        $this->assertStringContainsString('File an Incident Report', $html);
        $this->assertStringContainsString('data-can-withdraw="0"', $html);
    }

    #[Test]
    public function no_other_role_can_read_or_write(): void
    {
        $this->learner();

        foreach (['school_head', 'feeding_coor', 'nutricor'] as $role) {
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
        $this->file(['focus' => 'Abrasion, left knee', 'occurred_time' => '10:20'])->assertCreated();

        $row = DB::table('student_incident_reports')->first();

        foreach (['focus', 'location', 'description', 'action_taken', 'response', 'witnesses', 'reported_by_name'] as $column) {
            $this->assertNotEmpty($row->{$column}, "{$column} must be stored.");
            $this->assertStringStartsWith(
                'eyJpdiI6',
                (string) $row->{$column},
                "{$column} must be encrypted at rest."
            );
        }

        // Lookup keys stay plain — the list is filtered and ordered on them —
        // and so does the time: a clock reading is no more personal than the
        // plain date beside it.
        $this->assertSame('900000000001', $row->student_lrn);
        $this->assertSame('injury', $row->category);
        $this->assertStringStartsWith('10:20', (string) $row->occurred_time);
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
