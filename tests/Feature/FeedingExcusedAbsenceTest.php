<?php

namespace Tests\Feature;

use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\FeedingAtRiskRule;
use App\Support\FeedingAttendanceMark;
use App\Support\FeedingBeneficiarySummary;
use App\Support\FeedingProgramCycle;
use App\Support\FeedingReportNarrative;
use App\Support\SchemaCache;
use App\Support\SchoolHeadOverview;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The three things the Sta. Ana field work changed about how a beneficiary is
 * judged, and one thing it exposed:
 *
 * 1. **An excused absence is not held against a learner.** The coordinator
 *    keeps a "buffer" — fasting during Ramadan, illness, a family emergency —
 *    and only unexcused absence accumulates. Attendance therefore has three
 *    confirmed states, not two.
 * 2. **The school's rule is time-based, not a percentage.** A beneficiary is
 *    flagged after one week of unexcused absence, whatever their cumulative
 *    rate says.
 * 3. **Removal is a separate, later, human decision.** After roughly a second
 *    week and the class adviser's confirmation the learner is not returning,
 *    the place is released and somebody on the waiting list takes it. Nothing
 *    automatic ever removes a child.
 * 4. **The cycle length is the Division's, not the application's** — 120 days
 *    in policy, 90 under discussion.
 */
class FeedingExcusedAbsenceTest extends TestCase
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

    private function makeStudent(string $status = 'Wasted', bool $enrolled = true): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Learner '.random_int(1000, 9999),
            'student_id' => 'LRN'.random_int(100000, 999999),
            'school_name' => 'Test School',
            'section' => 'Grade 7 / Sampaguita',
            'weight' => 30,
            'bmi_value' => 15.1,
            'nutritional_status' => $status,
            'baseline_nutritional_status' => $status,
            'student_details' => ['gender' => 'Male'],
            'feeding_enrolled_at' => $enrolled ? now() : null,
        ]);
    }

    /** The most recent weekday, so a recorded session never lands on a weekend. */
    private function feedingDay(int $backFromToday = 0): string
    {
        $date = Carbon::today();

        for ($stepped = 0; $stepped < $backFromToday || ! FeedingProgramCycle::isFeedingDay($date);) {
            $date->subDay();
            if (FeedingProgramCycle::isFeedingDay($date)) {
                $stepped++;
            }
        }

        return $date->toDateString();
    }

    // ── The buffer ──────────────────────────────────────────────────────

    #[Test]
    public function the_coordinator_can_record_an_excused_absence(): void
    {
        $learner = $this->makeStudent();

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $this->feedingDay(),
                'marks' => [$learner->id => 'excused'],
                'remarks' => [$learner->id => 'Fasting for Ramadan'],
            ])
            ->assertRedirect();

        $mark = FeedingAttendance::where('student_health_record_id', $learner->id)->firstOrFail();

        // Still an absence on the sheet — the learner was not fed — but marked
        // as one the school accepted.
        $this->assertFalse($mark->is_present);
        $this->assertTrue($mark->is_excused);
        $this->assertSame('Fasting for Ramadan', $mark->remarks);
        $this->assertSame(FeedingAttendanceMark::EXCUSED, FeedingAttendanceMark::state($mark));
    }

    /**
     * The whole point of the buffer: an excused absence must not push anybody
     * toward a flag, and must not lift anybody above one either. It votes
     * neither way, exactly as an unconfirmed scanned mark does.
     */
    #[Test]
    public function an_excused_absence_is_excluded_from_the_rate_on_both_sides(): void
    {
        $rule = new FeedingAtRiskRule(FeedingAtRiskRule::MODE_ATTENDANCE_RATE, 80, 3, 0);

        $present = (object) ['is_present' => true, 'needs_review' => false, 'is_excused' => false];
        $absent = (object) ['is_present' => false, 'needs_review' => false, 'is_excused' => false];
        $excused = (object) ['is_present' => false, 'needs_review' => false, 'is_excused' => true];

        $marks = array_map(
            static fn (object $row): ?bool => FeedingAttendanceMark::forRule($row),
            [$present, $present, $present, $excused, $excused]
        );

        // Three of three attended, not three of five: the two excused sessions
        // are out of the denominator as well as the numerator.
        $this->assertSame(100.0, $rule->attendanceRate($marks));
        $this->assertFalse($rule->isAtRisk($marks));

        $withAbsence = array_map(
            static fn (object $row): ?bool => FeedingAttendanceMark::forRule($row),
            [$present, $absent, $absent, $excused]
        );

        $this->assertSame(33.3, $rule->attendanceRate($withAbsence));
    }

    #[Test]
    public function a_mark_can_be_corrected_to_excused_on_the_beneficiary_record(): void
    {
        $learner = $this->makeStudent();
        $date = $this->feedingDay();

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $date,
                'marks' => [$learner->id => 'absent'],
            ])
            ->assertRedirect();

        $this->withSession($this->coordinatorSession())
            ->post("/dashboard/feedingcor-program/beneficiary/{$learner->id}/attendance", [
                'session_date' => $date,
                'mark' => 'excused',
                'remarks' => 'Confined at hospital',
            ])
            ->assertRedirect();

        $mark = FeedingAttendance::where('student_health_record_id', $learner->id)->firstOrFail();

        $this->assertTrue($mark->is_excused);
        $this->assertFalse($mark->is_present);
        $this->assertSame('Confined at hospital', $mark->remarks);
    }

    /** A learner marked present carries no reason, whichever screen wrote it. */
    #[Test]
    public function a_present_learner_keeps_no_remark(): void
    {
        $learner = $this->makeStudent();

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $this->feedingDay(),
                'marks' => [$learner->id => 'present'],
                'remarks' => [$learner->id => 'should be dropped'],
            ])
            ->assertRedirect();

        $this->assertNull(FeedingAttendance::where('student_health_record_id', $learner->id)->first()->remarks);
    }

    /**
     * A database without the is_excused column refuses the excuse; it never
     * quietly records an unexcused absence instead.
     *
     * `is_present` is false for both kinds of absence, so on a deployment whose
     * migrations have not caught up an excused mark lands as an unexcused one:
     * it reads "Absent" everywhere, the Excused filter finds nothing, and the
     * at-risk rule counts it against the learner — the single thing the buffer
     * exists to prevent. This is not a hypothetical: it is what a pending
     * migration on the live database actually did.
     */
    #[Test]
    public function an_excuse_this_database_cannot_store_is_refused_not_downgraded(): void
    {
        $learner = $this->makeStudent();
        $date = $this->feedingDay();

        // Exactly the state a lagging deployment is in.
        Schema::table('feeding_attendances', function ($table): void {
            $table->dropColumn('is_excused');
        });
        SchemaCache::flush();

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $date,
                'marks' => [$learner->id => 'excused'],
                'remarks' => [$learner->id => 'Fasting for Ramadan'],
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        // Nothing was written at all — and above all, not an absence.
        $this->assertSame(0, FeedingAttendance::where('student_health_record_id', $learner->id)->count());

        // The correction path refuses on the same terms. It needs a session on
        // file to correct, so record a plain absence first.
        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $date,
                'marks' => [$learner->id => 'absent'],
            ])
            ->assertRedirect();

        $this->withSession($this->coordinatorSession())
            ->post("/dashboard/feedingcor-program/beneficiary/{$learner->id}/attendance", [
                'session_date' => $date,
                'mark' => 'excused',
                'remarks' => 'Fasting for Ramadan',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $mark = FeedingAttendance::where('student_health_record_id', $learner->id)->first();
        $this->assertFalse((bool) $mark->is_present);
        // Still the plain absence it was recorded as, never relabelled.
        $this->assertSame(FeedingAttendanceMark::ABSENT, FeedingAttendanceMark::state($mark));
    }

    /**
     * Every Attendance Status control in the role can ask for the excused, and
     * every one of them answers with the reason.
     *
     * Filtering to "Excused" and reading a list of names is only half an
     * answer: an excused absence is defined by the reason the school accepted,
     * so a screen that prints the mark and not the reason has shown half of
     * it. Both halves are asserted together here, because a filter that works
     * without its remark is exactly the state this test exists to prevent
     * coming back.
     */
    #[Test]
    public function every_attendance_status_filter_finds_the_excused_and_says_why(): void
    {
        $excused = $this->makeStudent();
        $present = $this->makeStudent();
        $date = $this->feedingDay();

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $date,
                'marks' => [$excused->id => 'excused', $present->id => 'present'],
                'remarks' => [$excused->id => 'Fasting for Ramadan'],
            ])
            ->assertRedirect();

        $session = $this->coordinatorSession();

        // The surfaces that print the mark itself also print the reason beside
        // it: an excused absence read without its reason is indistinguishable
        // from one nobody explained.
        foreach ([
            '/dashboard/feedingcor-attendance?status=excused&date='.$date,
            '/dashboard/feedingcor-attendance?view=beneficiary&status=excused&date='.$date,
        ] as $url) {
            $this->withSession($session)->get($url)
                ->assertOk()
                ->assertSee($excused->student_name)
                ->assertDontSee($present->student_name)
                ->assertSee('Fasting for Ramadan');
        }

        // The Dashboard's roll narrows the same way. It is read off the panel
        // rather than off the page, because the page also carries the Record
        // Attendance dialog, which lists the whole enrolled roll on purpose —
        // recording through a filtered list would close the day on the learners
        // the filter was hiding.
        $panel = $this->withSession($session)
            ->get('/dashboard/feedingcor-dashboard?attendance=excused')
            ->assertOk()
            ->assertSee('Fasting for Ramadan')
            ->viewData('todayAttendance');

        $this->assertSame([$excused->student_name], array_column($panel['rows'], 'name'));

        // The Beneficiaries tab narrows to the same learner but deliberately
        // prints no mark and no reason. Attendance in its table is the
        // cumulative rate — a different question — so the tab keeps its
        // standing anatomy and the reason is read where the mark is.
        $this->withSession($session)->get('/dashboard/feedingcor-health-records?attendance=excused')
            ->assertOk()
            ->assertSee($excused->student_name)
            ->assertDontSee($present->student_name);

        // The history is a list of days, not of learners, so it answers the
        // same question by keeping the session that carried an excused absence.
        $this->withSession($session)->get('/dashboard/feedingcor-attendance?view=history&status=excused')
            ->assertOk()
            ->assertSee(Carbon::parse($date)->format('M j, Y'));

        // And the filter is exclusive both ways: asking who came must not
        // return the learner who was excused, nor leak their reason.
        foreach ([
            '/dashboard/feedingcor-health-records?attendance=present',
            '/dashboard/feedingcor-attendance?view=beneficiary&status=present&date='.$date,
        ] as $url) {
            $this->withSession($session)->get($url)
                ->assertOk()
                ->assertSee($present->student_name)
                ->assertDontSee($excused->student_name)
                ->assertDontSee('Fasting for Ramadan');
        }

        // Read off the panel again, for the same reason: the Record Attendance
        // dialog beside it lists the whole enrolled roll by design.
        $present_only = $this->withSession($session)
            ->get('/dashboard/feedingcor-dashboard?attendance=present')
            ->assertOk()
            ->viewData('todayAttendance');

        $this->assertSame([$present->student_name], array_column($present_only['rows'], 'name'));
    }

    /**
     * The Beneficiaries table keeps its nine columns whatever the attendance
     * filter is set to.
     *
     * The filter narrows the list and nothing else. Attendance in this table is
     * the cumulative rate, and a second attendance column carrying today's mark
     * would put two different questions under one heading — so the reason an
     * absence was excused is read on the Attendance tab and on the learner's
     * own record, where the mark itself lives.
     */
    #[Test]
    public function the_beneficiaries_table_keeps_its_columns_under_every_attendance_filter(): void
    {
        $learner = $this->makeStudent();

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $this->feedingDay(),
                'marks' => [$learner->id => 'excused'],
                'remarks' => [$learner->id => 'Confined at hospital'],
            ])
            ->assertRedirect();

        $session = $this->coordinatorSession();

        foreach (['', '?attendance=excused', '?attendance=present'] as $query) {
            $body = $this->withSession($session)
                ->get('/dashboard/feedingcor-health-records'.$query)
                ->assertOk()
                ->assertDontSee('Confined at hospital')
                ->getContent();

            // No "Today" column, however the filter is set.
            $this->assertStringNotContainsString('>Today', $body);
        }
    }

    /**
     * Every coordinator surface has to survive an excused mark and name it.
     * The state was added to the model, the rule and five panels at once, and a
     * page that still knew only two answers would either miscount it as an
     * absence or fail on a missing array key.
     */
    #[Test]
    public function every_coordinator_surface_renders_an_excused_mark(): void
    {
        $learner = $this->makeStudent();
        $date = $this->feedingDay();

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $date,
                'marks' => [$learner->id => 'excused'],
                'remarks' => [$learner->id => 'Fasting for Ramadan'],
            ])
            ->assertRedirect();

        $session = $this->coordinatorSession();

        $this->withSession($session)->get('/dashboard/feedingcor-dashboard')->assertOk()->assertSee('Excused');
        $this->withSession($session)->get('/dashboard/feedingcor-attendance?date='.$date)->assertOk()->assertSee('Excused');
        $this->withSession($session)->get('/dashboard/feedingcor-attendance?view=beneficiary&date='.$date)->assertOk()->assertSee('Excused');
        $this->withSession($session)->get('/dashboard/feedingcor-attendance?view=history')->assertOk()->assertSee('Excused');
        $this->withSession($session)->get('/dashboard/feedingcor-at-risk')->assertOk();
        $this->withSession($session)->get('/dashboard/feedingcor-health-records')->assertOk();
        $this->withSession($session)->get("/dashboard/feedingcor-program/beneficiary/{$learner->id}")->assertOk()->assertSee('Excused');
    }

    /** The School Head reads the same marks, and must not read an excuse as an absence. */
    #[Test]
    public function the_school_head_reads_an_excused_mark_as_neither(): void
    {
        $learner = $this->makeStudent();

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $this->feedingDay(),
                'marks' => [$learner->id => 'excused'],
            ])
            ->assertRedirect();

        $overview = SchoolHeadOverview::for(
            $this->institution->id,
            StudentHealthRecord::currentSchoolYear()
        );

        $session = $overview->sessions()[0];

        $this->assertSame(1, $session['excused']);
        $this->assertSame(0, $session['absent']);
        // No confirmed mark either way, so the day has no turnout to report.
        $this->assertNull($session['rate']);
    }

    // ── The school's own rule ───────────────────────────────────────────

    #[Test]
    public function one_week_of_unexcused_absence_flags_a_learner(): void
    {
        // Four feeding sessions is one week: Monday to Thursday is what the
        // budget currently covers.
        $rule = new FeedingAtRiskRule(FeedingAtRiskRule::MODE_UNEXCUSED_ABSENCE_DAYS, 80, 3, 10, 4, 8);

        $this->assertFalse($rule->isAtRisk([false, false, false]));
        $this->assertTrue($rule->isAtRisk([true, true, false, false, false, false]));
    }

    /**
     * The rule the coordinator described is not a percentage, and this is the
     * case that shows why: a learner who attended for two months and then
     * disappeared for a week is still above 80% and needs chasing today.
     */
    #[Test]
    public function the_time_based_rule_flags_a_learner_a_percentage_would_miss(): void
    {
        $marks = array_merge(array_fill(0, 40, true), array_fill(0, 4, false));

        $rate = new FeedingAtRiskRule(FeedingAtRiskRule::MODE_ATTENDANCE_RATE, 80, 3, 10);
        $timeBased = new FeedingAtRiskRule(FeedingAtRiskRule::MODE_UNEXCUSED_ABSENCE_DAYS, 80, 3, 10, 4, 8);

        $this->assertFalse($rate->isAtRisk($marks), '40 of 44 is over 90%, so the rate rule sees nothing.');
        $this->assertTrue($timeBased->isAtRisk($marks));
    }

    /** An excused absence does not build a run — that is what the buffer means. */
    #[Test]
    public function excused_absences_never_build_toward_the_flag(): void
    {
        $rule = new FeedingAtRiskRule(FeedingAtRiskRule::MODE_UNEXCUSED_ABSENCE_DAYS, 80, 3, 10, 4, 8);

        $excused = (object) ['is_present' => false, 'needs_review' => false, 'is_excused' => true];
        $marks = array_map(
            static fn (object $row): ?bool => FeedingAttendanceMark::forRule($row),
            array_fill(0, 6, $excused)
        );

        $this->assertFalse($rule->isAtRisk($marks));
        $this->assertSame(0, $rule->currentAbsenceRun($marks));
    }

    /**
     * The window would otherwise hold the flag back for another six sessions —
     * a fortnight after the child stopped coming, which is the delay the rule
     * exists to remove.
     */
    #[Test]
    public function the_time_based_rule_classifies_as_soon_as_the_run_is_complete(): void
    {
        $rule = new FeedingAtRiskRule(FeedingAtRiskRule::MODE_UNEXCUSED_ABSENCE_DAYS, 80, 3, 10, 4, 8);

        $this->assertTrue($rule->hasEnoughObservation([false, false, false, false]));
        $this->assertFalse($rule->hasEnoughObservation([false, false, false]));
    }

    #[Test]
    public function the_removal_review_opens_at_two_weeks_and_removes_nobody(): void
    {
        $rule = new FeedingAtRiskRule(FeedingAtRiskRule::MODE_UNEXCUSED_ABSENCE_DAYS, 80, 3, 10, 4, 8);

        $oneWeek = array_fill(0, 4, false);
        $twoWeeks = array_fill(0, 8, false);

        $this->assertFalse($rule->needsRemovalReview($oneWeek));
        $this->assertTrue($rule->needsRemovalReview($twoWeeks));

        // A learner the rule says is worth reviewing is still enrolled: nothing
        // in this application takes a child off the feeding line on a count.
        $learner = $this->makeStudent();
        $this->assertTrue(FeedingBeneficiarySummary::isEnrolled($learner->fresh()));
    }

    #[Test]
    public function a_school_runs_the_rule_it_was_set_to(): void
    {
        $this->institution->update([
            'feeding_at_risk_mode' => FeedingAtRiskRule::MODE_UNEXCUSED_ABSENCE_DAYS,
            'feeding_absence_flag_days' => 4,
            'feeding_absence_removal_days' => 8,
        ]);

        $rule = FeedingAtRiskRule::forInstitution($this->institution->id);

        $this->assertSame(FeedingAtRiskRule::MODE_UNEXCUSED_ABSENCE_DAYS, $rule->mode());
        $this->assertSame(4, $rule->absenceFlagDays());
        $this->assertSame(8, $rule->absenceRemovalDays());

        // A school that has chosen nothing keeps the platform default rather
        // than being switched to somebody else's policy.
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $this->assertSame(
            FeedingAtRiskRule::MODE_ATTENDANCE_RATE,
            FeedingAtRiskRule::forInstitution($other->id)->mode()
        );
    }

    // ── Removal and substitution ────────────────────────────────────────

    #[Test]
    public function removing_a_beneficiary_takes_them_off_the_active_list_with_a_reason(): void
    {
        $learner = $this->makeStudent();

        $this->withSession($this->coordinatorSession())
            ->post("/dashboard/feedingcor-program/enrollment/{$learner->id}/remove", [
                'reason' => 'Transferred to another school',
            ])
            ->assertRedirect();

        $learner->refresh();

        $this->assertNotNull($learner->feeding_removed_at);
        $this->assertSame('Transferred to another school', $learner->feeding_removal_reason);
        $this->assertSame('Test Coordinator', $learner->feeding_removed_by);

        // The enrolment stamp survives: the learner WAS fed, and the record has
        // to keep saying so.
        $this->assertNotNull($learner->feeding_enrolled_at);
        $this->assertFalse(FeedingBeneficiarySummary::isEnrolled($learner));
        $this->assertFalse(FeedingBeneficiarySummary::isBeneficiary($learner));
    }

    #[Test]
    public function a_removal_needs_a_reason(): void
    {
        $learner = $this->makeStudent();

        $this->withSession($this->coordinatorSession())
            ->post("/dashboard/feedingcor-program/enrollment/{$learner->id}/remove", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertNull($learner->fresh()->feeding_removed_at);
    }

    #[Test]
    public function a_removed_learner_returns_to_the_waiting_list_and_can_be_re_enrolled(): void
    {
        $learner = $this->makeStudent();

        $this->withSession($this->coordinatorSession())
            ->post("/dashboard/feedingcor-program/enrollment/{$learner->id}/remove", ['reason' => 'Moved away'])
            ->assertRedirect();

        // Back on the buffer — which is exactly where the substitute for a
        // vacated slot comes from.
        $candidates = $this->withSession($this->coordinatorSession())
            ->getJson('/dashboard/feedingcor-program/enrollment/candidates')
            ->assertOk()
            ->json();

        $this->assertSame(1, $candidates['waiting']);
        $this->assertSame($learner->id, $candidates['rows'][0]['id']);

        // Re-enrolling clears the removal rather than leaving a learner both
        // enrolled and removed.
        $this->withSession($this->coordinatorSession())
            ->postJson('/dashboard/feedingcor-program/enrollment', ['record_ids' => [$learner->id]])
            ->assertOk();

        $learner->refresh();
        $this->assertNull($learner->feeding_removed_at);
        $this->assertTrue(FeedingBeneficiarySummary::isEnrolled($learner));
    }

    #[Test]
    public function another_schools_learner_can_never_be_removed(): void
    {
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $outsider = StudentHealthRecord::create([
            'institution_id' => $other->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Outsider',
            'student_id' => 'LRN999999',
            'school_name' => 'Other School',
            'section' => 'Grade 7 / Sampaguita',
            'weight' => 30,
            'bmi_value' => 15.1,
            'nutritional_status' => 'Wasted',
            'feeding_enrolled_at' => now(),
        ]);

        $this->withSession($this->coordinatorSession())
            ->post("/dashboard/feedingcor-program/enrollment/{$outsider->id}/remove", ['reason' => 'Not mine to touch'])
            ->assertRedirect();

        $this->assertNull($outsider->fresh()->feeding_removed_at);
    }

    // ── The cycle length ────────────────────────────────────────────────

    #[Test]
    public function a_school_runs_the_cycle_length_its_division_set(): void
    {
        $this->assertSame(120, FeedingProgramCycle::durationForInstitution($this->institution->id));

        $this->institution->update(['feeding_cycle_days' => 90]);

        $this->assertSame(90, FeedingProgramCycle::durationForInstitution($this->institution->id));
        $this->assertSame(90, FeedingProgramCycle::forInstitution($this->institution->id)->durationDays());

        // Clearing it returns the school to the app default rather than pinning
        // it to today's number.
        $this->institution->update(['feeding_cycle_days' => null]);
        $this->assertSame(120, FeedingProgramCycle::durationForInstitution($this->institution->id));
    }

    // ── The derived narrative ───────────────────────────────────────────

    /**
     * The coordinator writes "X% increase from baseline to endline" by hand
     * every cycle, off the very figures this application already computes. The
     * generated draft has to report exactly those figures — and, where a
     * weighing has not happened, has to say so rather than reporting zero.
     */
    #[Test]
    public function the_narrative_reports_a_missing_endline_as_missing_not_as_zero(): void
    {
        $this->makeStudent('Severely Wasted');
        $this->makeStudent('Wasted');

        $narrative = FeedingReportNarrative::build(
            StudentHealthRecord::query()->where('institution_id', $this->institution->id)->get(),
            'Test School',
            StudentHealthRecord::currentSchoolYear(),
            FeedingProgramCycle::forInstitution($this->institution->id),
            FeedingAtRiskRule::forInstitution($this->institution->id),
        );

        $this->assertStringContainsString('2 enrolled beneficiaries', $narrative['narr_introduction']);
        $this->assertStringContainsString('has not been recorded', $narrative['narr_results']);
        $this->assertStringNotContainsString('0% improved', $narrative['narr_results']);

        // Never an empty section: a blank conclusion reads as an omission.
        $this->assertNotSame('', trim($narrative['narr_conclusion']));
    }

    #[Test]
    public function the_narrative_counts_the_improvement_the_progress_panel_counts(): void
    {
        $improved = $this->makeStudent('Severely Wasted');
        $improved->update(['endline_nutritional_status' => 'Normal']);
        $this->makeStudent('Wasted');

        $narrative = FeedingReportNarrative::build(
            StudentHealthRecord::query()->where('institution_id', $this->institution->id)->get(),
            'Test School',
            StudentHealthRecord::currentSchoolYear(),
            FeedingProgramCycle::forInstitution($this->institution->id),
            FeedingAtRiskRule::forInstitution($this->institution->id),
        );

        // One of two beneficiaries improved — 50%, taken over EVERY beneficiary
        // rather than only the one who was re-measured, so the headline cannot
        // creep up simply because few endline readings exist.
        $this->assertStringContainsString('1 improved on the wasting scale', $narrative['narr_results']);
        $this->assertStringContainsString('50% of all enrolled beneficiaries', $narrative['narr_results']);
    }

    #[Test]
    public function the_system_admin_sets_the_rule_and_the_cycle_length(): void
    {
        $admin = ['active_role' => 'system_admin', 'active_name' => 'Admin', 'active_username' => 'systemadmin'];

        $this->withSession($admin)
            ->post("/dashboard/system-admin/institutions/{$this->institution->id}/at-risk-threshold", [
                'at_risk_mode' => FeedingAtRiskRule::MODE_UNEXCUSED_ABSENCE_DAYS,
                'absence_flag_days' => 4,
                'absence_removal_days' => 8,
                'cycle_days' => 90,
            ])
            ->assertRedirect();

        $school = $this->institution->fresh();

        $this->assertSame(FeedingAtRiskRule::MODE_UNEXCUSED_ABSENCE_DAYS, $school->feeding_at_risk_mode);
        $this->assertSame(4, $school->feeding_absence_flag_days);
        $this->assertSame(8, $school->feeding_absence_removal_days);
        $this->assertSame(90, $school->feeding_cycle_days);
    }
}
