<?php

namespace Tests\Feature;

use App\Models\FeedingAttendance;
use App\Models\FeedingFollowUp;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\FeedingAtRiskRule;
use App\Support\FeedingRiskSeverity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the Feeding Coordinator's At-Risk Students tab.
 *
 * The tab answers who is below the school's threshold, why, and who needs
 * following up. These tests hold it to the three things it must never do:
 * count a Watch learner as at risk, treat an unconfirmed mark as an absence,
 * or offer a second way to change a learner's enrolment or attendance.
 */
class FeedingAtRiskTabTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    private function coordinatorSession(): array
    {
        return [
            'active_role' => 'feeding_coor',
            'active_name' => 'Test Coordinator',
            'active_username' => 'feedcor.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->institution->id,
        ];
    }

    private function makeStudent(array $attributes = []): StudentHealthRecord
    {
        return StudentHealthRecord::create(array_merge([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Learner '.random_int(1000, 9999),
            'student_id' => 'LRN'.random_int(100000, 999999),
            'school_name' => 'Test School',
            'section' => 'Grade 7 / Maabilidad',
            'weight' => 20.0,
            'bmi_value' => 13.5,
            'nutritional_status' => 'Wasted',
            'baseline_nutritional_status' => 'Wasted',
            'student_details' => ['gender' => 'Male'],
            'feeding_enrolled_at' => now(),
        ], $attributes));
    }

    /**
     * Marks a run of sessions, oldest first: true present, false absent, null
     * an unconfirmed scanned mark.
     *
     * @param  list<bool|null>  $marks
     */
    private function markRun(StudentHealthRecord $record, array $marks): void
    {
        foreach (array_values($marks) as $index => $mark) {
            FeedingAttendance::create([
                'student_health_record_id' => $record->id,
                'session_date' => now()->subDays(count($marks) - $index)->toDateString(),
                'is_present' => $mark,
                'needs_review' => $mark === null,
                'source' => 'manual_entry',
            ]);
        }
    }

    /**
     * A run at a given rate with the absences spread evenly through it.
     *
     * Evenly, deliberately: a block of absences at the end is a *declining*
     * run, which the severity rule lifts to Critical on purpose. These fixtures
     * are about the rate, so the sequence must carry no direction of its own.
     *
     * @return list<bool>
     */
    private function marksAtRate(int $present, int $absent): array
    {
        $total = $present + $absent;
        $marks = [];
        $emitted = 0;

        for ($i = 1; $i <= $total; $i++) {
            $due = (int) round($absent * $i / $total);
            $marks[] = ! ($due > $emitted);
            $emitted = max($emitted, $due);
        }

        return $marks;
    }

    private function open(string $query = '')
    {
        return $this->withSession($this->coordinatorSession())->get('/dashboard/feedingcor-at-risk'.$query);
    }

    #[Test]
    public function the_header_carries_the_program_the_year_and_the_configured_threshold(): void
    {
        $record = $this->makeStudent();
        $this->markRun($record, $this->marksAtRate(3, 7));

        $response = $this->open()->assertOk();

        $response->assertSee('SBFP <span>At-Risk Students</span>', false);
        $response->assertSee('S.Y. '.str_replace('-', '&ndash;', StudentHealthRecord::currentSchoolYear()), false);
        $response->assertSee('Active Program:');
        $response->assertSee('School-Based Feeding Program');
        $response->assertSee('At-Risk Students');
        $response->assertSee('Total Beneficiaries');
        $response->assertSee('At-Risk Rate');
        $response->assertSee('Attendance Threshold');
        $response->assertSee('Export At-Risk List');
        $response->assertSee('Print At-Risk List');
    }

    #[Test]
    public function the_threshold_shown_is_the_schools_own_not_a_hardcoded_eighty(): void
    {
        // The System Admin has set this school to 90%.
        DB::table('institutions')->where('id', $this->institution->id)->update(['feeding_at_risk_threshold' => 90]);

        $record = $this->makeStudent();
        // 85% — comfortably clear of the default 80, flagged by this school's 90.
        $this->markRun($record, $this->marksAtRate(17, 3));

        $response = $this->open()->assertOk();

        $response->assertSee('90%');
        $response->assertSee('at-risk threshold 90%');
        $response->assertSee($record->student_name);
        $response->assertSee('At Risk');
    }

    #[Test]
    public function only_learners_below_the_threshold_are_counted_at_risk_while_watch_learners_are_listed(): void
    {
        $atRisk = $this->makeStudent(['student_name' => 'Below Threshold']);
        $watch = $this->makeStudent(['student_name' => 'Inside Watch Band']);
        $steady = $this->makeStudent(['student_name' => 'Well Above']);

        $this->markRun($atRisk, $this->marksAtRate(7, 3));    // 70%
        $this->markRun($watch, $this->marksAtRate(41, 9));    // 82%
        $this->markRun($steady, $this->marksAtRate(19, 1));   // 95%

        $response = $this->open()->assertOk();

        // The card counts the business rule alone: one learner below 80%.
        $response->assertSee('1 of 3 beneficiaries');
        $response->assertSee('0 critical &middot; 1 on watch', false);

        // The list is who needs attention — at risk and watch, never the
        // learner comfortably above the threshold.
        $response->assertSee('Below Threshold');
        $response->assertSee('Inside Watch Band');
        $response->assertDontSee('Well Above');
    }

    #[Test]
    public function an_unconfirmed_mark_is_never_counted_as_an_absence(): void
    {
        $record = $this->makeStudent(['student_name' => 'Unread Sheet']);
        // Four present and four unreadable: 100% of what was confirmed.
        $this->markRun($record, [true, null, true, null, true, null, true, null]);

        $response = $this->open()->assertOk();

        // Nobody is at risk, and the learner is not on the follow-up list.
        $response->assertSee('0 of 1 beneficiaries');
        $response->assertDontSee('Unread Sheet');
    }

    #[Test]
    public function a_learner_with_no_confirmed_session_is_never_flagged_or_listed(): void
    {
        $record = $this->makeStudent(['student_name' => 'Never Recorded']);

        $response = $this->open()->assertOk();

        $response->assertSee('0 of 1 beneficiaries');
        $response->assertDontSee('Never Recorded');
    }

    #[Test]
    public function a_row_explains_why_the_learner_is_at_risk(): void
    {
        $record = $this->makeStudent(['student_name' => 'Needs Follow Up']);
        $this->markRun($record, $this->marksAtRate(7, 5)); // 58.3% — critical

        $response = $this->open()->assertOk();

        $response->assertSee('Why this student is at risk');
        $response->assertSee('Risk reason:');
        $response->assertSee('far below the configured 80% threshold');
        $response->assertSee('Attendance trend');
        $response->assertSee('Recent absences');
        $response->assertSee('Follow-up');
        $response->assertSee('Critical');
    }

    #[Test]
    public function the_record_opens_in_a_dialog_rendered_with_its_own_row(): void
    {
        $record = $this->makeStudent(['student_name' => 'Opens In Dialog']);
        $this->markRun($record, $this->marksAtRate(6, 4));

        $response = $this->open()->assertOk();

        // The learner's name is the way in — the whole cell, as on the
        // Beneficiaries tab — and the row carries the identity the dialog's
        // head shows.
        $response->assertSee('<td class="ar-name is-link">', false);
        $response->assertSee('class="ar-namebtn" data-detail-open="'.$record->id.'"', false);
        $response->assertSee('aria-haspopup="dialog"', false);
        $response->assertSee('data-standing="Critical"', false);

        // No action column: it was a tenth of the table's width spent on a
        // control the name already is, and it pushed the list into a
        // side-scroll.
        $response->assertDontSee('ar-open-col');
        $response->assertDontSee('>Details</span>', false);

        // The record itself is a template belonging to that row — rendered with
        // it, so the dialog can never show a figure the row has moved past.
        $response->assertSee('<template class="ar-detail-source" data-detail-for="'.$record->id.'">', false);
        $response->assertSee('id="detailBackdrop"', false);

        // …and no longer an expanding row inside the table.
        $response->assertDontSee('ar-detailrow');
    }

    #[Test]
    public function the_filters_narrow_the_list(): void
    {
        $critical = $this->makeStudent(['student_name' => 'Critical Learner']);
        $watch = $this->makeStudent(['student_name' => 'Watch Learner', 'section' => 'Grade 8 / Maaasahan']);

        $this->markRun($critical, $this->marksAtRate(4, 6));  // 40%
        $this->markRun($watch, $this->marksAtRate(41, 9));    // 82%

        $this->open('?risk=critical')->assertOk()
            ->assertSee('Critical Learner')
            ->assertDontSee('Watch Learner');

        $this->open('?risk=watch')->assertOk()
            ->assertSee('Watch Learner')
            ->assertDontSee('Critical Learner');

        $this->open('?grade=Grade+8')->assertOk()
            ->assertSee('Watch Learner')
            ->assertDontSee('Critical Learner');

        $this->open('?attendance=below_50')->assertOk()
            ->assertSee('Critical Learner')
            ->assertDontSee('Watch Learner');

        $this->open('?follow_up=none')->assertOk()
            ->assertSee('Critical Learner')
            ->assertSee('Watch Learner');
    }

    #[Test]
    public function a_follow_up_is_recorded_scoped_encrypted_and_audited(): void
    {
        $record = $this->makeStudent(['student_name' => 'Followed Up']);
        $this->markRun($record, $this->marksAtRate(5, 5));

        $response = $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-at-risk/follow-up', [
                'record_id' => $record->id,
                'followed_up_on' => now()->toDateString(),
                'status' => FeedingFollowUp::STATUS_MONITORING,
                'person_contacted' => 'Parent/Guardian',
                'action_taken' => 'Discussed repeated absences',
                'reason' => 'Repeated absences over the last two weeks',
                'remarks' => 'Guardian will accompany the learner',
            ]);

        $response->assertRedirect();

        $followUp = FeedingFollowUp::query()->firstOrFail();
        $this->assertSame($record->id, (int) $followUp->student_health_record_id);
        $this->assertSame($this->institution->id, (int) $followUp->institution_id);
        $this->assertSame('Discussed repeated absences', $followUp->action_taken);
        $this->assertSame('Test Coordinator', $followUp->recorded_by_name);

        // The note is personal information about a named child: it is never at
        // rest in plaintext.
        $raw = DB::table('feeding_follow_ups')->where('id', $followUp->id)->first();
        $this->assertNotSame('Discussed repeated absences', $raw->action_taken);
        $this->assertNotSame('Parent/Guardian', $raw->person_contacted);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'feeding_follow_up_recorded',
            'subject_type' => 'StudentHealthRecord',
            'subject_id' => $record->id,
        ]);

        // The status the coordinator recorded is what the list reports.
        $this->open()->assertOk()->assertSee('Monitoring');
    }

    #[Test]
    public function a_follow_up_never_touches_enrolment(): void
    {
        $record = $this->makeStudent();
        $this->markRun($record, $this->marksAtRate(5, 5));
        $enrolledAt = $record->feeding_enrolled_at;

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-at-risk/follow-up', [
                'record_id' => $record->id,
                'followed_up_on' => now()->toDateString(),
                'status' => FeedingFollowUp::STATUS_RESOLVED,
            ])->assertRedirect();

        // "Resolved" means the attendance concern was addressed — the learner is
        // still a beneficiary.
        $this->assertNotNull($record->fresh()->feeding_enrolled_at);
        $this->assertEquals($enrolledAt->toDateString(), $record->fresh()->feeding_enrolled_at->toDateString());
    }

    #[Test]
    public function a_follow_up_cannot_be_recorded_for_another_schools_learner(): void
    {
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $stranger = $this->makeStudent(['institution_id' => $other->id, 'school_name' => 'Other School']);

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-at-risk/follow-up', [
                'record_id' => $stranger->id,
                'followed_up_on' => now()->toDateString(),
                'status' => FeedingFollowUp::STATUS_MONITORING,
            ])
            ->assertRedirect(route('dashboard.feedingcor-at-risk'));

        $this->assertSame(0, FeedingFollowUp::query()->count());
    }

    #[Test]
    public function another_schools_beneficiaries_never_appear_on_the_list(): void
    {
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $stranger = $this->makeStudent([
            'institution_id' => $other->id,
            'school_name' => 'Other School',
            'student_name' => 'Someone Elses Learner',
        ]);
        $this->markRun($stranger, $this->marksAtRate(1, 9));

        $mine = $this->makeStudent(['student_name' => 'Our Learner']);
        $this->markRun($mine, $this->marksAtRate(6, 4));

        $response = $this->open()->assertOk();

        $response->assertSee('Our Learner');
        $response->assertDontSee('Someone Elses Learner');
        $response->assertSee('1 of 1 beneficiaries');
    }

    #[Test]
    public function the_tab_offers_no_way_to_change_a_mark_or_an_enrolment(): void
    {
        $record = $this->makeStudent();
        $this->markRun($record, $this->marksAtRate(5, 5));

        $response = $this->open()->assertOk();

        // Attendance is written on the Attendance tab and nowhere else.
        $response->assertDontSee(route('feedingcor-program.attendance.record.store'));
        $response->assertDontSee('Mark All Present');
        // Being below the threshold is never a reason to drop a beneficiary.
        $response->assertDontSee(route('feedingcor-program.enrollment.store'));
        $response->assertDontSee('Remove Beneficiary');
    }

    #[Test]
    public function only_the_feeding_coordinator_can_open_the_tab(): void
    {
        $this->withSession(['active_role' => 'class_adviser', 'active_name' => 'Adviser'])
            ->get('/dashboard/feedingcor-at-risk')
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function the_export_carries_the_list_as_it_stands(): void
    {
        $record = $this->makeStudent();
        $this->markRun($record, $this->marksAtRate(5, 5));

        $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-at-risk/export')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    #[Test]
    public function the_severity_bands_sit_around_the_schools_own_threshold(): void
    {
        $rule = FeedingAtRiskRule::forInstitution(null);

        // 60% — far below 80, so Critical rather than merely At Risk.
        $critical = FeedingRiskSeverity::evaluate($this->marksAtRate(6, 4), $rule);
        $this->assertSame(FeedingRiskSeverity::CRITICAL, $critical['severity']);
        $this->assertTrue($critical['at_risk']);

        // 75% — below the threshold but not far below it.
        $atRisk = FeedingRiskSeverity::evaluate($this->marksAtRate(15, 5), $rule);
        $this->assertSame(FeedingRiskSeverity::AT_RISK, $atRisk['severity']);
        $this->assertTrue($atRisk['at_risk']);

        // 82% — above the threshold, inside the watch band. Watch is a
        // monitoring aid and is never at risk.
        $watch = FeedingRiskSeverity::evaluate($this->marksAtRate(41, 9), $rule);
        $this->assertSame(FeedingRiskSeverity::WATCH, $watch['severity']);
        $this->assertFalse($watch['at_risk']);

        // 95% — nothing to follow up.
        $steady = FeedingRiskSeverity::evaluate($this->marksAtRate(19, 1), $rule);
        $this->assertSame(FeedingRiskSeverity::STEADY, $steady['severity']);
        $this->assertFalse($steady['at_risk']);

        // 25% over four sessions — under every threshold on arithmetic, and
        // still not a standing: the observation window outranks the bands, so
        // this is Observing rather than Critical or (absurdly) Watch.
        $early = FeedingRiskSeverity::evaluate([true, false, false, false], $rule);
        $this->assertSame(FeedingRiskSeverity::OBSERVING, $early['severity']);
        $this->assertFalse($early['at_risk']);
        $this->assertTrue($early['observing']);
        $this->assertSame(25.0, $early['rate']);
        $this->assertSame(FeedingRiskSeverity::PRIORITY_NONE, $early['priority']);

        // No confirmed session is no evidence — never a standing.
        $none = FeedingRiskSeverity::evaluate([null, null], $rule);
        $this->assertSame(FeedingRiskSeverity::OBSERVING, $none['severity']);
        $this->assertNull($none['rate']);
    }

    #[Test]
    public function a_learner_inside_the_observation_window_is_never_listed_for_follow_up(): void
    {
        // Four sessions, one attended. On the arithmetic alone this learner is
        // the worst in the school; a follow-up on four sheets is still
        // premature, so the tab neither counts nor lists them.
        $early = $this->makeStudent(['student_name' => 'Newly Enrolled']);
        $this->markRun($early, [true, false, false, false]);

        $response = $this->open()->assertOk();

        $this->assertSame(0, $response->viewData('cards')['at_risk']);
        $this->assertSame(1, $response->viewData('cards')['observing']);
        $this->assertCount(0, $response->viewData('rows'));
        $response->assertDontSee('Newly Enrolled');
        // The card still says how many the window is holding back, so nobody
        // reads an empty list as "the programme has no problems".
        $response->assertSee('in early monitoring');
    }

    #[Test]
    public function a_declining_run_reads_as_declining_and_lifts_the_learner_to_critical(): void
    {
        $rule = FeedingAtRiskRule::forInstitution(null);

        // Started well, stopped coming: 75% overall, but the recent half is far
        // worse than the first — a coordinator needs to see that now.
        $marks = [true, true, true, true, true, true, true, true, true, false, false, false];
        $standing = FeedingRiskSeverity::evaluate($marks, $rule);

        $this->assertSame('declining', $standing['trend']);
        $this->assertSame(FeedingRiskSeverity::CRITICAL, $standing['severity']);
        $this->assertStringContainsString('fallen sharply', $standing['reason']);
    }

    #[Test]
    public function nothing_on_the_at_risk_tab_is_underlined(): void
    {
        $record = $this->makeStudent();
        $this->markRun($record, $this->marksAtRate(5, 5));

        $response = $this->open()->assertOk();

        $this->assertStringNotContainsString('text-decoration: underline', $response->getContent());
        $this->assertStringNotContainsString('text-decoration:underline', $response->getContent());
    }
}
