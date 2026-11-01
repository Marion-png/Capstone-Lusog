<?php

namespace Tests\Feature;

use App\Models\FeedingAttendance;
use App\Models\FeedingAttendanceConfirmation;
use App\Models\FeedingFollowUp;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\FeedingAtRiskRule;
use App\Support\SchemaCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the Feeding Coordinator's At-Risk Students tab and the attendance flow
 * the capstone adviser set:
 *
 *   day 3 of unexcused absence in a row → an attendance notice to verify the
 *                                          record; nobody is flagged
 *   day 7                                → awaiting the coordinator's
 *                                          confirmation; still nobody is flagged
 *   the coordinator confirms the record  → flagged for follow-up
 *   a Resolved follow-up                 → the flag closes
 *
 * and to the things it must never do: flag anybody without that confirmation,
 * treat an unconfirmed mark as an absence, or change a learner's enrolment.
 */
class FeedingAtRiskTabTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        // The deployment school's own rule: a run of unexcused absences, with
        // the adviser's notice at 3 and flag threshold at 7.
        $this->institution = Institution::create([
            'name' => 'Test School',
            'status' => 'active',
            'feeding_at_risk_mode' => FeedingAtRiskRule::MODE_UNEXCUSED_ABSENCE_DAYS,
        ]);
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

    private function markOn(StudentHealthRecord $record, int $daysAgo, ?bool $mark): void
    {
        FeedingAttendance::create([
            'student_health_record_id' => $record->id,
            'session_date' => now()->subDays($daysAgo)->toDateString(),
            'is_present' => $mark,
            'needs_review' => $mark === null,
            'source' => 'manual_entry',
        ]);
    }

    /** A learner who attended, then missed `$absences` sessions in a row. */
    private function absentInARow(string $name, int $absences, array $attributes = []): StudentHealthRecord
    {
        $record = $this->makeStudent(array_merge(['student_name' => $name], $attributes));
        $this->markRun($record, array_merge([true, true], array_fill(0, $absences, false)));

        return $record;
    }

    private function open(string $query = '')
    {
        return $this->withSession($this->coordinatorSession())->get('/dashboard/feedingcor-at-risk'.$query);
    }

    private function confirm(StudentHealthRecord $record, array $session = [])
    {
        return $this->withSession($session ?: $this->coordinatorSession())
            ->from('/dashboard/feedingcor-at-risk')
            ->post('/dashboard/feedingcor-at-risk/confirm', ['record_id' => $record->id]);
    }

    #[Test]
    public function the_header_carries_the_program_the_year_and_the_school_thresholds(): void
    {
        $this->absentInARow('Away Learner', 3);

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
        // The adviser's two days, read from the rule — never typed.
        $response->assertSee('7 days');
        $response->assertSee('Notice at 3 days');
    }

    #[Test]
    public function two_absences_in_a_row_raise_nothing(): void
    {
        $this->absentInARow('Two Days Away', 2);

        $response = $this->open()->assertOk();

        $response->assertSee('0 of 1 beneficiaries');
        $response->assertDontSee('Two Days Away');
    }

    #[Test]
    public function day_three_raises_an_attendance_notice_and_flags_nobody(): void
    {
        $record = $this->absentInARow('Three Days Away', 3);

        $response = $this->open()->assertOk();

        // Listed so the coordinator can verify the record…
        $response->assertSee('Three Days Away');
        $response->assertSee('Attendance Notice');
        $response->assertSee('1 attendance notice to verify');
        // …and counted nowhere as at risk.
        $response->assertSee('0 of 1 beneficiaries');
        $this->assertSame(0, $response->viewData('cards')['at_risk']);
        $this->assertSame(1, $response->viewData('cards')['notices']);
        $this->assertFalse((bool) $record->fresh()->is_at_risk);
    }

    #[Test]
    public function day_seven_awaits_confirmation_and_still_flags_nobody(): void
    {
        $this->absentInARow('Seven Days Away', 7);

        $response = $this->open()->assertOk();

        $response->assertSee('Seven Days Away');
        $response->assertSee('Awaiting Confirmation');
        $response->assertSee('1 attendance record awaiting confirmation');
        $response->assertSee('0 of 1 beneficiaries');
        $this->assertSame(0, $response->viewData('cards')['at_risk']);
        $this->assertSame(1, $response->viewData('cards')['awaiting']);
        // The dialog offers the confirmation — and the follow-up only once
        // there is a flag to follow up.
        $response->assertSee('id="detailConfirmForm"', false);
        $response->assertSee(route('feedingcor-at-risk.confirm'), false);
        $response->assertSee('data-confirm-stage="flag"', false);
        $response->assertSee('data-flagged="0"', false);
    }

    #[Test]
    public function confirming_the_record_at_day_seven_flags_the_learner_for_follow_up(): void
    {
        $record = $this->absentInARow('Confirmed Absent', 7);
        $enrolledAt = $record->feeding_enrolled_at->toDateString();
        $auditBaseline = (int) DB::table('audit_logs')->max('id');

        $this->confirm($record)->assertRedirect('/dashboard/feedingcor-at-risk')->assertSessionHas('success');

        $confirmation = FeedingAttendanceConfirmation::query()->sole();
        $this->assertSame(FeedingAttendanceConfirmation::STAGE_FLAG, $confirmation->stage);
        $this->assertSame(7, $confirmation->absences);
        $this->assertSame($this->institution->id, (int) $confirmation->institution_id);
        $this->assertSame('Test Coordinator', $confirmation->confirmed_by_name);

        // The staff name is personal information, never at rest in plaintext.
        $raw = DB::table('feeding_attendance_confirmations')->where('id', $confirmation->id)->first();
        $this->assertNotSame('Test Coordinator', $raw->confirmed_by_name);

        $this->assertTrue(
            DB::table('audit_logs')
                ->where('id', '>', $auditBaseline)
                ->where('action', 'feeding_attendance_confirmed')
                ->where('subject_id', $record->id)
                ->exists()
        );

        // Flagged — on this tab, and on the stored flag the clinic counts.
        $response = $this->open()->assertOk();
        $response->assertSee('1 of 1 beneficiaries');
        $response->assertSee('Flagged for Follow-Up');
        $response->assertSee('data-flagged="1"', false);
        $this->assertTrue((bool) $record->fresh()->is_at_risk);

        // No automatic change in enrolment, ever.
        $this->assertSame($enrolledAt, $record->fresh()->feeding_enrolled_at->toDateString());
        $this->assertNull($record->fresh()->feeding_removed_at);
    }

    #[Test]
    public function verifying_a_notice_acknowledges_it_and_flags_nobody(): void
    {
        $record = $this->absentInARow('Verified Notice', 3);

        $this->confirm($record)->assertSessionHas('success');

        $confirmation = FeedingAttendanceConfirmation::query()->sole();
        $this->assertSame(FeedingAttendanceConfirmation::STAGE_NOTICE, $confirmation->stage);
        $this->assertSame(3, $confirmation->absences);
        $this->assertFalse((bool) $record->fresh()->is_at_risk);

        $response = $this->open()->assertOk();
        // Still on the list as a notice, no longer asking to be verified.
        $response->assertSee('Verified Notice');
        $response->assertDontSee('attendance notice to verify');
        $this->assertSame(0, $response->viewData('cards')['at_risk']);
        $this->assertSame(0, $response->viewData('cards')['notices_unverified']);

        // A second post has nothing left to confirm.
        $this->confirm($record)->assertSessionHas('error');
        $this->assertSame(1, FeedingAttendanceConfirmation::query()->count());
    }

    #[Test]
    public function a_verified_notice_still_needs_the_confirmation_at_day_seven(): void
    {
        $record = $this->makeStudent(['student_name' => 'Verified Then Gone']);
        foreach ([12 => true, 11 => true, 10 => false, 9 => false, 8 => false] as $daysAgo => $mark) {
            $this->markOn($record, $daysAgo, $mark);
        }
        $this->confirm($record)->assertSessionHas('success');

        // Four more missed sessions after the verification: day 7 is reached,
        // and the notice's verification does not stand in for the confirmation.
        foreach ([7, 6, 5, 4] as $daysAgo) {
            $this->markOn($record, $daysAgo, false);
        }

        $response = $this->open()->assertOk();

        $this->assertSame(1, $response->viewData('cards')['awaiting']);
        $this->assertSame(0, $response->viewData('cards')['at_risk']);
        $this->assertFalse((bool) $record->fresh()->is_at_risk);
    }

    #[Test]
    public function nothing_is_confirmed_for_a_learner_with_nothing_to_confirm(): void
    {
        $record = $this->makeStudent(['student_name' => 'Always Here']);
        $this->markRun($record, [true, true, true, true]);

        $this->confirm($record)->assertSessionHas('error');

        $this->assertSame(0, FeedingAttendanceConfirmation::query()->count());
        $this->assertFalse((bool) $record->fresh()->is_at_risk);
    }

    #[Test]
    public function only_the_feeding_coordinator_can_confirm_and_only_for_their_school(): void
    {
        $record = $this->absentInARow('Not Yours To Confirm', 7);

        foreach (['school_nurse', 'clinic_teacher', 'class_adviser'] as $role) {
            $this->confirm($record, ['active_role' => $role, 'active_institution_id' => $this->institution->id])
                ->assertRedirect(route('login'));
        }

        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $stranger = $this->absentInARow('Someone Elses', 7, ['institution_id' => $other->id, 'school_name' => 'Other School']);
        $this->confirm($stranger)->assertRedirect(route('dashboard.feedingcor-at-risk'));

        $this->assertSame(0, FeedingAttendanceConfirmation::query()->count());
    }

    #[Test]
    public function a_follow_up_waits_for_the_confirmation(): void
    {
        $record = $this->absentInARow('Not Yet Confirmed', 7);

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-at-risk/follow-up', [
                'record_id' => $record->id,
                'followed_up_on' => now()->toDateString(),
                'status' => FeedingFollowUp::STATUS_FOLLOW_UP_REQUIRED,
            ])
            ->assertRedirect(route('dashboard.feedingcor-at-risk'))
            ->assertSessionHas('error');

        $this->assertSame(0, FeedingFollowUp::query()->count());
    }

    #[Test]
    public function a_follow_up_is_recorded_scoped_encrypted_and_audited(): void
    {
        $record = $this->absentInARow('Followed Up', 7);
        $this->confirm($record);

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

        // The status the coordinator recorded is what the list reports, and a
        // follow-up that is not Resolved leaves the flag where it is.
        $this->open()->assertOk()->assertSee('Monitoring');
        $this->assertTrue((bool) $record->fresh()->is_at_risk);
    }

    #[Test]
    public function a_resolved_follow_up_closes_the_flag_and_never_touches_enrolment(): void
    {
        $record = $this->absentInARow('Resolved Learner', 7);
        $enrolledAt = $record->feeding_enrolled_at->toDateString();
        $this->confirm($record);
        $this->assertTrue((bool) $record->fresh()->is_at_risk);

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-at-risk/follow-up', [
                'record_id' => $record->id,
                'followed_up_on' => now()->toDateString(),
                'status' => FeedingFollowUp::STATUS_RESOLVED,
            ])->assertRedirect();

        // The flag is closed — and the week it covered cannot flag them again.
        $this->assertFalse((bool) $record->fresh()->is_at_risk);
        $response = $this->open()->assertOk();
        $this->assertSame(0, $response->viewData('cards')['at_risk']);
        $this->assertSame(0, $response->viewData('cards')['awaiting']);

        // "Resolved" means the attendance concern was addressed — the learner
        // is still a beneficiary.
        $this->assertSame($enrolledAt, $record->fresh()->feeding_enrolled_at->toDateString());
        $this->assertNull($record->fresh()->feeding_removed_at);
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

    /**
     * Verifying a record can find it wrong — for today's absence. A day's
     * attendance closes when the day does, so an earlier absence is verified as
     * it stands: it carries no correction form, and the endpoint refuses one.
     */
    #[Test]
    public function only_todays_absence_under_verification_can_be_corrected(): void
    {
        // Six absences on days that have closed, and a seventh today — the one
        // that reaches the threshold, and the only one still open.
        $record = $this->absentInARow('Wrongly Marked', 6);
        $this->markOn($record, 0, false);

        $response = $this->open()->assertOk();
        $this->assertSame(1, $response->viewData('cards')['awaiting']);
        $response->assertSee('Absences to verify');
        // Exactly one correction form — today's — and the rest say why not.
        $response->assertSee(route('feedingcor-program.beneficiary.attendance.correct', $record->id), false);
        $this->assertSame(1, substr_count($response->getContent(), 'class="ar-correct"'));
        $response->assertSee('a mark can only be changed on its own day');

        // An earlier absence is refused at the endpoint, whatever a page drew.
        $earlier = FeedingAttendance::query()
            ->where('student_health_record_id', $record->id)
            ->where('is_present', false)
            ->whereDate('session_date', '<', now()->toDateString())
            ->orderByDesc('session_date')
            ->first()
            ->session_date
            ->toDateString();

        $this->withSession($this->coordinatorSession())
            ->from('/dashboard/feedingcor-at-risk')
            ->post(route('feedingcor-program.beneficiary.attendance.correct', $record->id), [
                'session_date' => $earlier,
                'mark' => 'present',
            ])
            ->assertRedirect('/dashboard/feedingcor-at-risk')
            ->assertSessionHas('error');

        $this->assertSame(1, $this->open()->viewData('cards')['awaiting']);

        // Today's absence was wrong: the learner was there. The run stops at
        // six, so day 7 was never reached.
        $this->withSession($this->coordinatorSession())
            ->from('/dashboard/feedingcor-at-risk')
            ->post(route('feedingcor-program.beneficiary.attendance.correct', $record->id), [
                'session_date' => now()->toDateString(),
                'mark' => 'present',
            ])
            ->assertRedirect('/dashboard/feedingcor-at-risk')
            ->assertSessionHas('success');

        $this->assertSame(0, $this->open()->viewData('cards')['awaiting']);
    }

    #[Test]
    public function a_flagged_learner_offers_no_correction_and_the_tab_no_enrolment_change(): void
    {
        $record = $this->absentInARow('Already Confirmed', 7);
        $this->confirm($record);

        $response = $this->open()->assertOk();

        // Confirmed means confirmed: the absences are a reading now.
        $response->assertDontSee(route('feedingcor-program.beneficiary.attendance.correct', $record->id), false);
        // Attendance is recorded on the Attendance tab and nowhere else.
        $response->assertDontSee(route('feedingcor-program.attendance.record.store'));
        $response->assertDontSee('Mark All Present');
        // A flag is never a reason to drop a beneficiary.
        $response->assertDontSee(route('feedingcor-program.enrollment.store'));
        $response->assertDontSee('Remove Beneficiary');
    }

    #[Test]
    public function an_unconfirmed_mark_is_never_counted_as_an_absence(): void
    {
        $record = $this->makeStudent(['student_name' => 'Unread Sheet']);
        // Present, then six sheets nobody could read: no run at all.
        $this->markRun($record, [true, null, null, null, null, null, null]);

        $response = $this->open()->assertOk();

        $response->assertSee('0 of 1 beneficiaries');
        $response->assertDontSee('Unread Sheet');
    }

    #[Test]
    public function an_excused_absence_neither_extends_nor_breaks_a_run(): void
    {
        $record = $this->makeStudent(['student_name' => 'Ramadan Buffer']);
        $this->markRun($record, [true, false, false, false, false, false, false]);
        // Two of those days were excused after all: the run is four, not six.
        FeedingAttendance::query()
            ->where('student_health_record_id', $record->id)
            ->where('is_present', false)
            ->orderBy('session_date')
            ->limit(2)
            ->get()
            ->each(fn (FeedingAttendance $row) => $row->update(['is_excused' => true]));

        $response = $this->open()->assertOk();

        $this->assertSame(0, $response->viewData('cards')['awaiting']);
        $this->assertSame(1, $response->viewData('cards')['notices']);
    }

    #[Test]
    public function a_learner_with_no_confirmed_session_is_never_flagged_or_listed(): void
    {
        $this->makeStudent(['student_name' => 'Never Recorded']);

        $response = $this->open()->assertOk();

        $response->assertSee('0 of 1 beneficiaries');
        $response->assertDontSee('Never Recorded');
    }

    #[Test]
    public function the_record_opens_in_a_dialog_rendered_with_its_own_row(): void
    {
        $record = $this->absentInARow('Opens In Dialog', 7);

        $response = $this->open()->assertOk();

        // The learner's name is the way in — the whole cell, as on the
        // Beneficiaries tab — and the row carries the identity the dialog's
        // head shows.
        $response->assertSee('<td class="ar-name is-link">', false);
        $response->assertSee('class="ar-namebtn" data-detail-open="'.$record->id.'"', false);
        $response->assertSee('aria-haspopup="dialog"', false);
        $response->assertSee('data-standing="Awaiting Confirmation"', false);

        // No action column: the name is the control.
        $response->assertDontSee('ar-open-col');
        $response->assertDontSee('>Details</span>', false);

        // The record itself is a template belonging to that row — rendered with
        // it, so the dialog can never show a figure the row has moved past.
        $response->assertSee('<template class="ar-detail-source" data-detail-for="'.$record->id.'">', false);
        $response->assertSee('id="detailBackdrop"', false);
        $response->assertSee('Attendance standing');
        $response->assertSee('Attendance trend');
        $response->assertSee('Follow-up');
        $response->assertDontSee('ar-detailrow');
    }

    #[Test]
    public function the_filters_narrow_the_list(): void
    {
        $this->absentInARow('Notice Learner', 3);
        $this->absentInARow('Awaiting Learner', 8, ['section' => 'Grade 8 / Maaasahan']);

        $this->open('?status=notice')->assertOk()
            ->assertSee('Notice Learner')
            ->assertDontSee('Awaiting Learner');

        $this->open('?status=awaiting_confirmation')->assertOk()
            ->assertSee('Awaiting Learner')
            ->assertDontSee('Notice Learner');

        $this->open('?grade=Grade+8')->assertOk()
            ->assertSee('Awaiting Learner')
            ->assertDontSee('Notice Learner');

        $this->open('?absences=3_6')->assertOk()
            ->assertSee('Notice Learner')
            ->assertDontSee('Awaiting Learner');

        $this->open('?absences=7_plus')->assertOk()
            ->assertSee('Awaiting Learner')
            ->assertDontSee('Notice Learner');

        // An unknown value off the wire is dropped rather than emptying the list.
        $this->open('?status=critical')->assertOk()
            ->assertSee('Notice Learner')
            ->assertSee('Awaiting Learner');
    }

    #[Test]
    public function a_rate_school_also_waits_for_the_coordinator_to_confirm(): void
    {
        DB::table('institutions')->where('id', $this->institution->id)->update([
            'feeding_at_risk_mode' => FeedingAtRiskRule::MODE_ATTENDANCE_RATE,
            'feeding_at_risk_threshold' => 90,
            'feeding_min_observation_days' => 0,
        ]);

        $record = $this->makeStudent(['student_name' => 'Below Ninety']);
        // 85%, scattered — below this school's 90, never three in a row.
        $this->markRun($record, [true, true, true, false, true, true, true, true, true, true, true, false, true, true, true, true, true, false, true, true]);

        $response = $this->open()->assertOk();
        $response->assertSee('90%');
        $this->assertSame(1, $response->viewData('cards')['awaiting']);
        $this->assertSame(0, $response->viewData('cards')['at_risk']);

        $this->confirm($record);
        $this->assertSame(1, $this->open()->viewData('cards')['at_risk']);
    }

    #[Test]
    public function another_schools_beneficiaries_never_appear_on_the_list(): void
    {
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $this->absentInARow('Someone Elses Learner', 9, ['institution_id' => $other->id, 'school_name' => 'Other School']);
        $this->absentInARow('Our Learner', 3);

        $response = $this->open()->assertOk();

        $response->assertSee('Our Learner');
        $response->assertDontSee('Someone Elses Learner');
        $response->assertSee('0 of 1 beneficiaries');
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
        $this->absentInARow('Exported', 7);

        $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-at-risk/export')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    /**
     * Migrations never run on deploy, so the deployed database can be a step
     * behind the code. Until the confirmations table exists nobody can be
     * flagged — and nothing may break while that is true.
     */
    #[Test]
    public function the_feeding_screens_still_work_before_the_confirmations_migration_has_run(): void
    {
        Schema::drop('feeding_attendance_confirmations');
        SchemaCache::flush();

        $record = $this->absentInARow('Not Yet Migrated', 7);

        $response = $this->open()->assertOk();
        $response->assertSee('Awaiting Confirmation');
        $response->assertDontSee('id="detailConfirmForm"', false);
        $this->assertSame(0, $response->viewData('cards')['at_risk']);

        $this->confirm($record)->assertSessionHas('error');
        $this->assertFalse((bool) $record->fresh()->is_at_risk);

        $this->withSession($this->coordinatorSession())->get('/dashboard/feedingcor-dashboard')->assertOk();
        $this->withSession($this->coordinatorSession())->get('/dashboard/feedingcor-attendance?view=beneficiary')->assertOk();
        $this->withSession($this->coordinatorSession())->get('/dashboard/feedingcor-health-records')->assertOk();
        $this->withSession($this->coordinatorSession())->get('/dashboard/feedingcor-program/beneficiary/'.$record->id)->assertOk();
        $this->withSession(['active_role' => 'school_nurse'] + $this->coordinatorSession())
            ->get('/dashboard/school-nurse/feeding-program')->assertOk();
        $this->withSession(['active_role' => 'school_head'] + $this->coordinatorSession())
            ->get('/dashboard/school-head')->assertOk();
    }

    #[Test]
    public function nothing_on_the_at_risk_tab_is_underlined(): void
    {
        $this->absentInARow('Underline Check', 7);

        $response = $this->open()->assertOk();

        $this->assertStringNotContainsString('text-decoration: underline', $response->getContent());
        $this->assertStringNotContainsString('text-decoration:underline', $response->getContent());
    }
}
