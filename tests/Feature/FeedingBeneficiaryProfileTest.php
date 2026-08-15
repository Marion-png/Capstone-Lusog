<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards one beneficiary's own page — what clicking a learner's name on the
 * Beneficiaries tab opens.
 *
 * Everything on it is derived at read time from the same sources the roster
 * reads, so the two can never disagree about a learner: the enrolment stamp,
 * the adviser's baseline/endline measurements, and the confirmed attendance
 * marks judged by the school's own threshold. The invariants that matter here
 * are the feeding ones — an unconfirmed mark is never an absence, and the page
 * is keyed by record id, scoped to the coordinator's school.
 */
class FeedingBeneficiaryProfileTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    private function coordinatorSession(?int $institutionId = null): array
    {
        return [
            'active_role' => 'feeding_coor',
            'active_name' => 'Test Coordinator',
            'active_username' => 'feedcor.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $institutionId ?? $this->institution->id,
        ];
    }

    private function makeStudent(array $attributes = [], ?Institution $institution = null): StudentHealthRecord
    {
        return StudentHealthRecord::create(array_merge([
            'institution_id' => ($institution ?? $this->institution)->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'JOHN DAVE A. SUMOD-ONG',
            'student_id' => 'LRN'.random_int(100000, 999999),
            'school_name' => 'Test School',
            'section' => 'Grade 7 / Maabilidad',
            'weight' => 18.5,
            'bmi_value' => 13.3,
            'nutritional_status' => 'Severely Wasted',
            'baseline_age' => 12,
            'baseline_height_cm' => 118,
            'baseline_weight_kg' => 18.5,
            'baseline_bmi_value' => 13.3,
            'baseline_nutritional_status' => 'Severely Wasted',
            'baseline_recorded_at' => now()->subMonths(2)->toDateString(),
            'student_details' => ['gender' => 'Male', 'nutritional_status_height_for_age' => 'Stunted'],
            'feeding_enrolled_at' => now()->subMonth(),
        ], $attributes));
    }

    private function mark(StudentHealthRecord $record, string $date, ?bool $present, bool $needsReview = false): void
    {
        FeedingAttendance::create([
            'student_health_record_id' => $record->id,
            'session_date' => $date,
            'is_present' => $present,
            'needs_review' => $needsReview,
            'source' => 'manual_entry',
        ]);
    }

    private function openRecord(StudentHealthRecord $record)
    {
        return $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-program/beneficiary/'.$record->id);
    }

    #[Test]
    public function it_shows_the_learners_identity_baseline_and_program_figures(): void
    {
        $record = $this->makeStudent();

        $response = $this->openRecord($record)->assertOk();

        $response->assertSee('JOHN DAVE A. SUMOD-ONG');
        $response->assertSee('Grade 7 — Maabilidad', false);
        $response->assertSee('S.Y. '.str_replace('-', '&ndash;', StudentHealthRecord::currentSchoolYear()), false);
        $response->assertSee('Active Beneficiary');

        // Baseline: metres and kilogrammes as the DepEd sheet records them,
        // with both classifications the adviser's measurement produced.
        $response->assertSee('1.18 m');
        $response->assertSee('18.5 kg');
        $response->assertSee('13.3');
        $response->assertSee('Severely Wasted');
        $response->assertSee('Stunted');

        // The cycle length is the programme's, not a figure anyone types.
        $response->assertSee('Total Feeding Days');
        $response->assertSee('120');
        $response->assertSee($record->feeding_enrolled_at->format('F j, Y'));
    }

    #[Test]
    public function attendance_counts_only_confirmed_sessions(): void
    {
        $record = $this->makeStudent();

        $this->mark($record, now()->subDays(4)->toDateString(), true);
        $this->mark($record, now()->subDays(3)->toDateString(), true);
        $this->mark($record, now()->subDays(2)->toDateString(), true);
        $this->mark($record, now()->subDay()->toDateString(), false);
        // An unread scanned mark: it must move neither the rate nor the absences.
        $this->mark($record, now()->toDateString(), null, true);

        $response = $this->openRecord($record)->assertOk();

        // 3 of 4 confirmed sessions — the unconfirmed one is out of both the
        // numerator and the denominator, and is never counted as an absence.
        $response->assertSee('75.0%');
        $response->assertSee('3 / 4');
        $response->assertSee('days attended');
        $response->assertSee('Unconfirmed');
    }

    #[Test]
    public function a_learner_with_no_confirmed_session_has_no_rate(): void
    {
        $record = $this->makeStudent();
        $this->mark($record, now()->toDateString(), null, true);

        $response = $this->openRecord($record)->assertOk();

        // No evidence is not a turnout of nothing, so the rate is an em dash
        // and the learner is not flagged.
        $response->assertSee('0 / 0');
        $response->assertDontSee('0.0%');
        $response->assertDontSee('At Risk Beneficiary');
    }

    #[Test]
    public function a_learner_below_the_threshold_reads_as_at_risk(): void
    {
        $record = $this->makeStudent();

        $this->mark($record, now()->subDays(2)->toDateString(), true);
        $this->mark($record, now()->subDay()->toDateString(), false);
        $this->mark($record, now()->toDateString(), false);

        $this->openRecord($record)->assertOk()->assertSee('At Risk Beneficiary');
    }

    #[Test]
    public function the_schools_own_threshold_decides_the_flag(): void
    {
        $this->institution->update(['feeding_at_risk_threshold' => 30]);

        $record = $this->makeStudent();
        $this->mark($record, now()->subDay()->toDateString(), true);
        $this->mark($record, now()->toDateString(), false);

        // 50% clears a 30% threshold, so the same marks that would flag a
        // learner at the 80% default do not flag one here.
        $response = $this->openRecord($record)->assertOk();
        $response->assertSee('Active Beneficiary');
        $response->assertSee('At-risk threshold: <strong>30%</strong>', false);
    }

    #[Test]
    public function days_completed_counts_the_sessions_the_school_recorded(): void
    {
        $record = $this->makeStudent();
        $other = $this->makeStudent(['student_name' => 'Second Learner']);

        // Two learners, one session date shared between them: the school held
        // two feeding days, not three marks' worth.
        $this->mark($record, now()->subDay()->toDateString(), true);
        $this->mark($other, now()->subDay()->toDateString(), true);
        $this->mark($record, now()->toDateString(), true);

        $response = $this->openRecord($record)->assertOk();

        $response->assertSee('Days Completed');
        $response->assertSee('<dd>2</dd>', false);
    }

    #[Test]
    public function the_record_reads_in_three_tabs_rendered_in_one_response(): void
    {
        $record = $this->makeStudent();

        $response = $this->openRecord($record)->assertOk();

        // Every tab is in the first response, so the rail only chooses what is
        // on screen — no tab can show a figure the others have moved past.
        $response->assertSee('data-tab="overview"', false);
        $response->assertSee('data-tab="attendance"', false);
        $response->assertSee('data-tab="endline"', false);
        $response->assertSee('data-panel="overview"', false);
        $response->assertSee('data-panel="attendance"', false);
        $response->assertSee('data-panel="endline"', false);
    }

    #[Test]
    public function the_attendance_tab_lists_the_sessions_by_month(): void
    {
        $record = $this->makeStudent();

        $this->mark($record, '2026-08-10', true);
        $this->mark($record, '2026-08-11', false, false);
        $this->mark($record, '2026-08-12', null, true);

        $response = $this->openRecord($record)->assertOk();

        $response->assertSee('August 2026');
        $response->assertSee('Aug 12');
        $response->assertSee('Aug 11');
        $response->assertSee('Aug 10');
        // The unread scanned mark is shown as unconfirmed, never as an absence.
        $response->assertSee('Unconfirmed');
        $response->assertSee('Present');
        $response->assertSee('Absent');
        $response->assertSee('Recorded By');
    }

    #[Test]
    public function a_day_the_school_fed_but_no_sheet_covered_reads_as_not_marked(): void
    {
        $record = $this->makeStudent();
        $other = $this->makeStudent(['student_name' => 'Second Learner']);

        // The school held two feeding days; only one covered this learner.
        $this->mark($record, '2026-08-10', true);
        $this->mark($other, '2026-08-11', true);

        $response = $this->openRecord($record)->assertOk();

        // The calendar is the school's, so the gap is visible — and it is
        // never drawn as an absence.
        $response->assertSee('Aug 11');
        $response->assertSee('Not marked');
        // One confirmed session, one attended: the unmarked day is in neither
        // side of the fraction.
        $response->assertSee('1 / 1');
    }

    #[Test]
    public function a_mark_carries_who_recorded_it(): void
    {
        $record = $this->makeStudent();

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => '2026-08-10',
                'marks' => [$record->id => 'present'],
            ])
            ->assertRedirect();

        $this->openRecord($record)->assertOk()->assertSee('Test Coordinator');
    }

    #[Test]
    public function the_coordinator_can_correct_a_mark_and_the_change_is_audited(): void
    {
        $record = $this->makeStudent();
        $this->mark($record, '2026-08-10', false);

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/beneficiary/'.$record->id.'/attendance', [
                'session_date' => '2026-08-10',
                'mark' => 'present',
            ])
            ->assertRedirect();

        $this->assertTrue(
            (bool) FeedingAttendance::query()
                ->where('student_health_record_id', $record->id)
                ->whereDate('session_date', '2026-08-10')
                ->value('is_present')
        );

        // The audit entry keeps what the mark was, not only what it became:
        // the replaced value is the evidence someone may later need.
        $log = AuditLog::query()->where('subject_type', 'FeedingAttendance')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('corrected from absent to present', (string) $log->description);
        $this->assertStringContainsString('Test Coordinator', (string) $log->description);
        $this->assertSame('absent', data_get($log->details, 'old.is_present'));
        $this->assertSame('present', data_get($log->details, 'new.is_present'));
    }

    #[Test]
    public function a_correction_attributes_the_mark_and_confirms_it(): void
    {
        $record = $this->makeStudent();
        // An unread scanned mark: correcting it is also confirming it.
        $this->mark($record, '2026-08-10', null, true);

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/beneficiary/'.$record->id.'/attendance', [
                'session_date' => '2026-08-10',
                'mark' => 'absent',
                'remarks' => 'Sick',
            ])
            ->assertRedirect();

        $row = FeedingAttendance::query()->where('student_health_record_id', $record->id)->first();

        $this->assertFalse((bool) $row->is_present);
        $this->assertFalse((bool) $row->needs_review);
        $this->assertSame('Test Coordinator', $row->reviewed_by_name);
        $this->assertSame('Sick', $row->remarks);
        $this->assertNotNull($row->reviewed_at);

        // Reasons a named child missed a session stay encrypted at rest.
        $stored = DB::table('feeding_attendances')->where('id', $row->id)->value('remarks');
        $this->assertNotSame('Sick', $stored);
    }

    #[Test]
    public function a_correction_can_fill_a_mark_the_sheet_skipped(): void
    {
        $record = $this->makeStudent();
        $other = $this->makeStudent(['student_name' => 'Second Learner']);
        $this->mark($other, '2026-08-10', true);

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/beneficiary/'.$record->id.'/attendance', [
                'session_date' => '2026-08-10',
                'mark' => 'present',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('feeding_attendances', 2);
        $this->assertSame(
            'Test Coordinator',
            FeedingAttendance::query()->where('student_health_record_id', $record->id)->first()->recorded_by_name
        );
    }

    #[Test]
    public function a_correction_cannot_invent_a_feeding_day(): void
    {
        $record = $this->makeStudent();
        $this->mark($record, '2026-08-10', true);

        // No session was held on the 11th, so there is no mark to correct and
        // no denominator to quietly enlarge.
        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/beneficiary/'.$record->id.'/attendance', [
                'session_date' => '2026-08-11',
                'mark' => 'absent',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('feeding_attendances', 1);
    }

    #[Test]
    public function a_correction_is_refused_for_another_schools_learner(): void
    {
        $otherSchool = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $record = $this->makeStudent(['student_name' => 'Other School Learner'], $otherSchool);
        $this->mark($record, '2026-08-10', true);

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/beneficiary/'.$record->id.'/attendance', [
                'session_date' => '2026-08-10',
                'mark' => 'absent',
            ])
            ->assertRedirect(route('dashboard.feedingcor-health-records'));

        $this->assertTrue((bool) FeedingAttendance::query()->where('student_health_record_id', $record->id)->value('is_present'));
    }

    #[Test]
    public function only_the_feeding_coordinator_may_correct_a_mark(): void
    {
        $record = $this->makeStudent();
        $this->mark($record, '2026-08-10', true);

        $this->withSession([
            'active_role' => 'class_adviser',
            'active_name' => 'Test Adviser',
            'active_institution_id' => $this->institution->id,
        ])
            ->post('/dashboard/feedingcor-program/beneficiary/'.$record->id.'/attendance', [
                'session_date' => '2026-08-10',
                'mark' => 'absent',
            ])
            ->assertRedirect(route('login'));

        $this->assertTrue((bool) FeedingAttendance::query()->where('student_health_record_id', $record->id)->value('is_present'));
    }

    #[Test]
    public function a_correction_moves_the_at_risk_flag(): void
    {
        $record = $this->makeStudent();
        $this->mark($record, '2026-08-10', true);
        $this->mark($record, '2026-08-11', false);
        $this->mark($record, '2026-08-12', false);

        // 33% — under the 80% default.
        $this->openRecord($record)->assertOk()->assertSee('At Risk Beneficiary');

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/beneficiary/'.$record->id.'/attendance', [
                'session_date' => '2026-08-11',
                'mark' => 'present',
            ]);
        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/beneficiary/'.$record->id.'/attendance', [
                'session_date' => '2026-08-12',
                'mark' => 'present',
            ]);

        // The flag is computed from the marks, so correcting them clears it
        // without anyone touching a stored flag.
        $this->openRecord($record)->assertOk()->assertSee('Active Beneficiary');
    }

    #[Test]
    public function an_unmeasured_endline_reads_as_not_yet_recorded(): void
    {
        $record = $this->makeStudent();

        $this->openRecord($record)->assertOk()->assertSee('Not yet recorded');
    }

    #[Test]
    public function a_measured_endline_is_reported(): void
    {
        $record = $this->makeStudent([
            'endline_age' => 12,
            'endline_height_cm' => 121,
            'endline_weight_kg' => 22.4,
            'endline_bmi_value' => 15.3,
            'endline_nutritional_status' => 'Wasted',
            'endline_recorded_at' => now()->toDateString(),
        ]);

        $response = $this->openRecord($record)->assertOk();

        $response->assertDontSee('Not yet recorded');
        $response->assertSee('1.21 m');
        $response->assertSee('22.4 kg');
        $response->assertSee('15.3');
    }

    #[Test]
    public function a_qualified_learner_nobody_enrolled_is_pending(): void
    {
        $record = $this->makeStudent(['feeding_enrolled_at' => null]);

        $response = $this->openRecord($record)->assertOk();

        $response->assertSee('Pending Enrollment');
        $response->assertSee('Not enrolled');
        // Enrolling is the coordinator's decision, so the page carries it —
        // through the same endpoint the dialog and the waiting list post to.
        $response->assertSee('Enroll Beneficiary');
        $response->assertSee(route('feedingcor-program.enrollment.store'), false);
    }

    #[Test]
    public function the_beneficiaries_tab_links_each_name_to_its_record(): void
    {
        $record = $this->makeStudent();

        $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records')
            ->assertOk()
            ->assertSee(route('feedingcor-program.beneficiary', $record->id), false);
    }

    #[Test]
    public function another_schools_learner_is_not_served(): void
    {
        $otherSchool = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $record = $this->makeStudent([
            'school_name' => 'Other School',
            'student_name' => 'Other School Learner',
        ], $otherSchool);

        $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-program/beneficiary/'.$record->id)
            ->assertRedirect(route('dashboard.feedingcor-health-records'));
    }

    #[Test]
    public function only_the_feeding_coordinator_may_open_it(): void
    {
        $record = $this->makeStudent();

        $this->withSession([
            'active_role' => 'class_adviser',
            'active_name' => 'Test Adviser',
            'active_institution_id' => $this->institution->id,
        ])
            ->get('/dashboard/feedingcor-program/beneficiary/'.$record->id)
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function an_earlier_school_year_is_still_readable(): void
    {
        // The Beneficiaries tab lets the coordinator read a past year, so the
        // record page must open one. The institution is the boundary here, not
        // the school year.
        $record = $this->makeStudent(['school_year' => '2024-2025']);

        $this->openRecord($record)->assertOk()->assertSee('SBFP 2024&ndash;2025', false);
    }
}
