<?php

namespace Tests\Feature;

use App\Models\AttendanceImport;
use App\Models\AuditLog;
use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The bulk record-attendance screen: one list, one tap per learner, one save.
 *
 * The invariant it must not break is the same one the scanner respects — an
 * unmarked learner is not an absence. The coordinator may simply have skipped
 * the row, and writing `false` there would push someone toward the at-risk flag
 * on evidence nobody produced.
 */
class FeedingRecordAttendanceTest extends TestCase
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

    private function makeStudent(string $status = 'Wasted', ?int $institutionId = null, string $school = 'Test School'): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $institutionId ?? $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Learner '.random_int(1000, 9999),
            'student_id' => 'LRN'.random_int(100000, 999999),
            'school_name' => $school,
            'section' => 'Grade 7 / Sampaguita',
            'weight' => 30,
            'bmi_value' => 15.1,
            'nutritional_status' => $status,
            'baseline_nutritional_status' => $status,
            'student_details' => ['gender' => 'Male'],
            // Already in the programme: qualifying is the adviser's measurement,
            // enrolling is the coordinator's decision, and these learners have both.
            'feeding_enrolled_at' => now(),
        ]);
    }

    #[Test]
    public function the_screen_lists_only_this_schools_beneficiaries(): void
    {
        $beneficiary = $this->makeStudent('Wasted');
        $normal = $this->makeStudent('Normal');

        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $outsider = $this->makeStudent('Wasted', $other->id, 'Other School');

        $rows = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-program/attendance/record')
            ->assertOk()
            ->assertSee($beneficiary->student_name)
            ->assertDontSee($normal->student_name)
            ->assertDontSee($outsider->student_name)
            ->viewData('rows');

        $this->assertCount(1, $rows);
    }

    /**
     * The rail must point at the tab that owns the thing on screen.
     *
     * Recording a mark belongs to Attendance. This screen used to highlight
     * Feeding Program — the tab that reads the marks and deliberately offers no
     * way to change one — so the rail named the one tab that cannot do what the
     * screen is for.
     */
    #[Test]
    public function recording_a_mark_sits_under_the_attendance_tab_not_feeding_program(): void
    {
        $this->makeStudent();

        $html = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-program/attendance/record')
            ->assertOk()
            ->getContent();

        $attendanceLink = route('dashboard.feedingcor-attendance');
        $programLink = route('dashboard.feedingcor-program');

        $this->assertMatchesRegularExpression(
            '~<a href="'.preg_quote($attendanceLink, '~').'" class="asb-link active"~',
            $html,
            'The Attendance rail item should be the active one.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '~<a href="'.preg_quote($programLink, '~').'" class="asb-link active"~',
            $html,
            'Feeding Program should not be highlighted while recording a mark.'
        );
    }

    /**
     * A session can be changed until its day ends and never after, so the save
     * is read back before it is made — every learner named, with the mark
     * entered for them.
     */
    #[Test]
    public function the_marks_are_confirmed_before_they_are_saved(): void
    {
        $learner = $this->makeStudent();

        foreach (['/dashboard/feedingcor-program/attendance/record'] as $url) {
            $response = $this->withSession($this->coordinatorSession())->get($url)->assertOk();

            $response->assertSee('id="confirmBackdrop"', false);
            $response->assertSee('Confirm Attendance');
            $response->assertSee('data-confirm-submit', false);
            // The dialog is built from the form, so the row carries the learner
            // and their class for it to read back.
            $response->assertSee('data-name="'.$learner->student_name.'"', false);
        }
    }

    /**
     * Nothing to read back on a day nobody can record: once a day has ended the
     * confirmation is not drawn, exactly as the record form is not. Today's
     * session, recorded or not, is still a form — for late arrivals — and
     * comes back with its marks already selected.
     */
    #[Test]
    public function only_a_closed_day_offers_no_confirmation_dialog(): void
    {
        $learner = $this->makeStudent();
        $yesterday = $this->yesterday();

        foreach ([now()->toDateString(), $yesterday] as $date) {
            FeedingAttendance::create([
                'student_health_record_id' => $learner->id,
                'session_date' => $date,
                'is_present' => true,
                'needs_review' => false,
                'source' => 'manual_entry',
            ]);
        }

        $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-program/attendance/record')
            ->assertOk()
            ->assertSee('id="confirmBackdrop"', false)
            ->assertSee('name="marks['.$learner->id.']" value="present" checked', false)
            ->assertSee('You can still change a mark until the end of the day');

        $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-program/attendance/record?date='.$yesterday)
            ->assertOk()
            ->assertDontSee('id="confirmBackdrop"', false)
            ->assertDontSee('name="marks['.$learner->id.']"', false)
            ->assertSee('is closed');
    }

    /** The weekday before today's, so the test never lands on a weekend. */
    private function yesterday(): string
    {
        $day = now()->subDay();

        while ($day->isWeekend()) {
            $day->subDay();
        }

        return $day->toDateString();
    }

    #[Test]
    public function saving_writes_one_confirmed_mark_per_learner_and_gates_the_period(): void
    {
        $present = $this->makeStudent();
        $absent = $this->makeStudent();
        $today = now()->toDateString();

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $today,
                'marks' => [$present->id => 'present', $absent->id => 'absent'],
            ])
            ->assertRedirect(route('dashboard.feedingcor-dashboard'));

        $this->assertTrue(FeedingAttendance::where('student_health_record_id', $present->id)->first()->is_present);
        $this->assertFalse(FeedingAttendance::where('student_health_record_id', $absent->id)->first()->is_present);

        // Hand-recorded marks are confirmed on arrival — nothing to review.
        $this->assertSame(0, FeedingAttendance::where('needs_review', true)->count());
        $this->assertSame(
            FeedingAttendance::SOURCE_MANUAL_ENTRY,
            FeedingAttendance::where('student_health_record_id', $present->id)->first()->source
        );

        // A session recorded on site gates the workflow exactly as an upload does.
        $this->assertTrue(AttendanceImport::existsForPeriod($this->institution->id));
        $this->assertSame(AttendanceImport::KIND_MANUAL, AttendanceImport::latestForPeriod($this->institution->id)->kind);

        $this->assertSame(1, $present->fresh()->attendance_sessions_count);
        $this->assertSame(0, $absent->fresh()->attendance_sessions_count);
    }

    /** The load-bearing rule: skipping a row records nothing, never an absence. */
    #[Test]
    public function an_unmarked_learner_is_not_written_as_absent(): void
    {
        $marked = $this->makeStudent();
        $skipped = $this->makeStudent();

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => now()->toDateString(),
                'marks' => [$marked->id => 'present', $skipped->id => ''],
            ])
            ->assertRedirect(route('dashboard.feedingcor-dashboard'));

        $this->assertSame(1, FeedingAttendance::count());
        $this->assertSame(0, FeedingAttendance::where('student_health_record_id', $skipped->id)->count());
        $this->assertFalse((bool) $skipped->fresh()->is_at_risk, 'A skipped row must not flag a learner.');
    }

    /**
     * A learner who arrives late is marked on the same day. The edit posts the
     * whole roll pre-filled, and only what changed is written: the late
     * arrival's mark, under the name of whoever changed it, while every other
     * mark keeps the name of the person who took it. It adds no second batch.
     */
    #[Test]
    public function a_late_arrival_is_marked_present_on_the_same_day(): void
    {
        $onTime = $this->makeStudent();
        $late = $this->makeStudent();
        $skipped = $this->makeStudent();
        $today = now()->toDateString();

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $today,
                'marks' => [$onTime->id => 'present', $late->id => 'absent'],
                'remarks' => [$late->id => 'Not yet in'],
            ])
            ->assertSessionHas('success');

        $editor = ['active_name' => 'Second Coordinator'] + $this->coordinatorSession();
        $before = (int) AuditLog::max('id');

        $this->withSession($editor)
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $today,
                'marks' => [$onTime->id => 'present', $late->id => 'present', $skipped->id => 'absent'],
                'remarks' => [$late->id => 'Not yet in'],
                'return_to' => 'attendance',
            ])
            ->assertRedirect(route('dashboard.feedingcor-attendance', ['date' => $today]))
            ->assertSessionHas('success', '2 mark(s) updated for '.now()->format('M d, Y').'.');

        $mark = fn (StudentHealthRecord $learner) => FeedingAttendance::where('student_health_record_id', $learner->id)->firstOrFail();

        $this->assertTrue((bool) $mark($late)->is_present);
        $this->assertSame('', trim((string) $mark($late)->remarks), 'A present learner keeps no reason for an absence.');
        $this->assertSame('Second Coordinator', $mark($late)->recorded_by_name);
        $this->assertFalse((bool) $mark($skipped)->is_present, 'A learner the first pass missed can be added.');
        $this->assertSame('Test Coordinator', $mark($onTime)->recorded_by_name, 'An unchanged mark keeps who took it.');

        $this->assertSame(1, AttendanceImport::count(), 'An edit belongs to the session already filed.');
        $this->assertTrue(AuditLog::where('id', '>', $before)
            ->where('action', 'updated')->where('subject_type', 'FeedingAttendance')->exists());

        // Saving the same marks again changes nothing.
        $this->withSession($editor)
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $today,
                'marks' => [$onTime->id => 'present', $late->id => 'present'],
            ])
            ->assertSessionHas('success', 'No mark was changed for '.now()->format('M d, Y').'.');
    }

    /**
     * Within the day a recorded mark can be changed: saving the session again
     * rewrites it in place rather than adding a second row, and the audit entry
     * keeps what it was changed from — the value replaced is the evidence
     * somebody may later need.
     */
    #[Test]
    public function a_recorded_mark_can_be_changed_the_same_day(): void
    {
        $learner = $this->makeStudent();
        $today = now()->toDateString();

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $today,
                'marks' => [$learner->id => 'present'],
            ])
            ->assertSessionHas('success');

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $today,
                'marks' => [$learner->id => 'absent'],
                'remarks' => [$learner->id => 'Went home sick before lunch'],
            ])
            ->assertSessionHas('success');

        $rows = FeedingAttendance::where('student_health_record_id', $learner->id)->get();
        $this->assertCount(1, $rows, 'The second save changes the mark, never duplicates it.');
        $this->assertFalse((bool) $rows->first()->is_present);
        $this->assertSame('Went home sick before lunch', $rows->first()->remarks);

        $log = AuditLog::query()->where('subject_type', 'FeedingAttendance')->latest('id')->first();
        $this->assertSame('updated', $log->action);
        $this->assertStringContainsString('changed on site by Test Coordinator', (string) $log->description);
        $this->assertSame('present', data_get($log->details, 'changed.'.$learner->id.'.old'));
        $this->assertSame('absent', data_get($log->details, 'changed.'.$learner->id.'.new'));
        // The audit keeps the change, not the reason a named child was away.
        $this->assertStringNotContainsString('Went home sick', json_encode($log->details));
    }

    /**
     * A second save the same day can also mark a learner the first one missed —
     * the session is one record of the day, and the day is not over.
     */
    #[Test]
    public function a_second_save_the_same_day_changes_and_adds_marks(): void
    {
        $first = $this->makeStudent();
        $second = $this->makeStudent();
        $today = now()->toDateString();

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $today,
                'marks' => [$first->id => 'present'],
            ]);

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $today,
                'marks' => [$first->id => 'absent', $second->id => 'excused'],
            ])
            ->assertSessionHas('success');

        $this->assertFalse((bool) FeedingAttendance::where('student_health_record_id', $first->id)->first()->is_present);
        $this->assertTrue((bool) FeedingAttendance::where('student_health_record_id', $second->id)->first()->is_excused);
        $this->assertSame(2, FeedingAttendance::count());
    }

    /**
     * Once a day has ended its attendance is closed — recorded or not. A stale
     * tab or a replayed form reaches the endpoint all the same, so the refusal
     * lives on the server: nothing is written for a day that never had a mark,
     * and nothing is changed on one that did.
     */
    #[Test]
    public function a_past_day_is_closed_whether_or_not_it_was_recorded(): void
    {
        $recorded = $this->makeStudent();
        $skipped = $this->makeStudent();
        $yesterday = $this->yesterday();

        FeedingAttendance::create([
            'student_health_record_id' => $recorded->id,
            'session_date' => $yesterday,
            'is_present' => true,
            'needs_review' => false,
            'source' => FeedingAttendance::SOURCE_MANUAL_ENTRY,
        ]);

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $yesterday,
                'marks' => [$recorded->id => 'absent', $skipped->id => 'present'],
            ])
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'can only be recorded or changed on that day'));

        $this->assertTrue((bool) FeedingAttendance::where('student_health_record_id', $recorded->id)->first()->is_present);
        $this->assertNull(FeedingAttendance::where('student_health_record_id', $skipped->id)->first());
    }

    /**
     * "The end of the day" is the school's, not the server's.
     *
     * The server runs on UTC, eight hours behind Davao. At 11:30 PM in Davao the
     * session is still open; half an hour later it has closed, although the
     * server's own clock still reads the same date.
     */
    #[Test]
    public function the_day_ends_at_midnight_on_the_schools_clock(): void
    {
        config(['app.school_timezone' => 'Asia/Manila']);

        $learner = $this->makeStudent();
        // The suite's clock is Friday 09:00 UTC — 5 PM in Davao.
        $today = now()->toDateString();

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $today,
                'marks' => [$learner->id => 'present'],
            ])
            ->assertSessionHas('success');

        // 11:30 PM in Davao: still the same day there.
        $this->travelTo(now()->setTime(15, 30));
        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $today,
                'marks' => [$learner->id => 'absent'],
            ])
            ->assertSessionHas('success');

        // 12:30 AM in Davao. The server's date has not moved; the school's has.
        $this->travelTo(now()->setTime(16, 30));
        $this->assertSame($today, now()->toDateString());

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $today,
                'marks' => [$learner->id => 'present'],
            ])
            ->assertSessionHas('error');

        $this->assertFalse((bool) FeedingAttendance::where('student_health_record_id', $learner->id)->first()->is_present);

        $this->travelBack();
    }

    /** A weekend is not a feeding day, so it can never become one. */
    #[Test]
    public function a_weekend_session_is_refused(): void
    {
        $learner = $this->makeStudent();
        $saturday = now()->startOfWeek(Carbon::MONDAY)->subDays(2)->toDateString();

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $saturday,
                'marks' => [$learner->id => 'present'],
            ])
            ->assertSessionHas('error');

        $this->assertDatabaseCount('feeding_attendances', 0);
    }

    /** Saving is what confirms an unread scanned mark — and it clears the queue. */
    #[Test]
    public function saving_supersedes_an_unconfirmed_scanned_mark(): void
    {
        $learner = $this->makeStudent();
        $today = now()->toDateString();

        FeedingAttendance::create([
            'student_health_record_id' => $learner->id,
            'session_date' => $today,
            'is_present' => null,
            'needs_review' => true,
            'source' => FeedingAttendance::SOURCE_PHOTO_SCAN,
        ]);

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $today,
                'marks' => [$learner->id => 'present'],
            ]);

        $mark = FeedingAttendance::where('student_health_record_id', $learner->id)->first();

        $this->assertTrue($mark->is_present);
        $this->assertFalse($mark->needs_review);
    }

    /**
     * A remark explains an absence, so it is stored only for a learner marked
     * absent and never for one marked present — a stale reason must not survive
     * the correction.
     */
    #[Test]
    public function a_remark_is_kept_for_an_absence_and_dropped_for_a_present_learner(): void
    {
        $absent = $this->makeStudent();
        $present = $this->makeStudent();

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => now()->toDateString(),
                'marks' => [$absent->id => 'absent', $present->id => 'present'],
                'remarks' => [$absent->id => 'Sick with fever', $present->id => 'ignored'],
            ])
            ->assertRedirect(route('dashboard.feedingcor-dashboard'));

        $this->assertSame(
            'Sick with fever',
            FeedingAttendance::where('student_health_record_id', $absent->id)->first()->remarks
        );
        $this->assertNull(FeedingAttendance::where('student_health_record_id', $present->id)->first()->remarks);
    }

    /**
     * The bulk write is an upsert, which bypasses the model's casts — so the
     * remark has to be encrypted on the way in or it would land in the column
     * as readable plaintext about a named child.
     */
    #[Test]
    public function a_remark_is_encrypted_at_rest(): void
    {
        $learner = $this->makeStudent();

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => now()->toDateString(),
                'marks' => [$learner->id => 'absent'],
                'remarks' => [$learner->id => 'Sick with fever'],
            ]);

        $raw = (string) DB::table('feeding_attendances')
            ->where('student_health_record_id', $learner->id)
            ->value('remarks');

        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('Sick with fever', $raw);
        $this->assertSame('Sick with fever', FeedingAttendance::first()->remarks);
    }

    /** Re-opening the screen brings the reason back with the mark. */
    #[Test]
    public function an_existing_remark_comes_back_with_the_row(): void
    {
        $learner = $this->makeStudent();

        FeedingAttendance::create([
            'student_health_record_id' => $learner->id,
            'session_date' => now()->toDateString(),
            'is_present' => false,
            'needs_review' => false,
            'source' => FeedingAttendance::SOURCE_MANUAL_ENTRY,
            'remarks' => 'Family emergency',
        ]);

        $rows = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-program/attendance/record')
            ->assertOk()
            ->viewData('rows');

        $this->assertSame('Family emergency', $rows->first()['remarks']);
    }

    #[Test]
    public function a_coordinator_cannot_record_for_another_schools_learner(): void
    {
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $outsider = $this->makeStudent('Wasted', $other->id, 'Other School');

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => now()->toDateString(),
                'marks' => [$outsider->id => 'present'],
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, FeedingAttendance::count());
    }

    #[Test]
    public function a_future_session_is_rejected(): void
    {
        $learner = $this->makeStudent();

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => now()->addDay()->toDateString(),
                'marks' => [$learner->id => 'present'],
            ])
            ->assertSessionHasErrors('session_date');

        $this->assertSame(0, FeedingAttendance::count());
    }

    #[Test]
    public function only_the_coordinator_may_open_or_save_the_screen(): void
    {
        $learner = $this->makeStudent();

        $this->withSession(['active_role' => 'class_adviser', 'active_institution_id' => $this->institution->id])
            ->get('/dashboard/feedingcor-program/attendance/record')
            ->assertRedirect(route('login'));

        $this->withSession(['active_role' => 'class_adviser', 'active_institution_id' => $this->institution->id])
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => now()->toDateString(),
                'marks' => [$learner->id => 'present'],
            ])
            ->assertRedirect(route('login'));

        $this->assertSame(0, FeedingAttendance::count());
    }

    /** Re-opening the screen shows what is already on file for that date. */
    #[Test]
    public function existing_marks_come_back_preselected(): void
    {
        $learner = $this->makeStudent();
        $today = now()->toDateString();

        FeedingAttendance::create([
            'student_health_record_id' => $learner->id,
            'session_date' => $today,
            'is_present' => false,
            'needs_review' => false,
            'source' => FeedingAttendance::SOURCE_MANUAL_ENTRY,
        ]);

        $rows = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-program/attendance/record')
            ->assertOk()
            ->viewData('rows');

        $this->assertSame('absent', $rows->first()['mark']);
    }

    // ── School Form 2 layout ────────────────────────────────────────────

    private function sf2Learner(string $sex, string $name): StudentHealthRecord
    {
        $learner = $this->makeStudent();
        $learner->update([
            'student_name' => $name,
            'student_details' => ['gender' => $sex],
            'feeding_enrolled_at' => Carbon::parse('2026-10-01 01:00:00'),
        ]);

        return $learner->fresh();
    }

    private function markOn(StudentHealthRecord $learner, string $date, string $state): void
    {
        FeedingAttendance::create([
            'student_health_record_id' => $learner->id,
            'session_date' => $date,
            'is_present' => $state === 'present',
            'is_excused' => $state === 'excused',
            'needs_review' => false,
            'source' => FeedingAttendance::SOURCE_MANUAL_ENTRY,
        ]);
    }

    /**
     * The page is the SF2 grid: a column per weekday of the month, males and
     * females in their own blocks, and only today's column takes a mark —
     * every earlier day is a record of what is on file.
     */
    #[Test]
    public function the_form_is_a_month_of_weekdays_with_only_today_writable(): void
    {
        // Wednesday 11 November 2026, 10 AM in Davao.
        $this->travelTo(Carbon::parse('2026-11-11 02:00:00'));

        $boy = $this->sf2Learner('Male', 'Boy Learner');
        $girl = $this->sf2Learner('Female', 'Girl Learner');
        $this->markOn($boy, '2026-11-02', 'present');
        $this->markOn($girl, '2026-11-02', 'absent');

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-program/attendance/record')
            ->assertOk();

        $days = collect($response->viewData('days'));
        $this->assertSame('2026-11-02', $days->first()['date']);
        $this->assertSame('M', $days->first()['weekday']);
        $this->assertSame('2026-11-30', $days->last()['date']);
        $this->assertCount(21, $days, 'November 2026 has 21 weekdays.');
        $this->assertSame(['2026-11-11'], $days->where('editable', true)->pluck('date')->values()->all());

        $html = $response->getContent();
        $this->assertStringContainsString('scope="rowgroup">Male</th>', $html);
        $this->assertStringContainsString('scope="rowgroup">Female</th>', $html);
        // One set of fields per learner: today's. A past day is never a field.
        $this->assertSame(1, substr_count($html, 'name="marks['.$boy->id.']" value="present"'));
        $this->assertSame('present', $response->viewData('rows')->firstWhere('id', $boy->id)['marks']['2026-11-02']);

        $this->travelBack();
    }

    /**
     * The SF2 summary is computed, never typed: enrolment at the start of the
     * month, learners still registered at its end, average daily attendance
     * over the days somebody was recorded on, and a learner absent five
     * recorded days running.
     */
    #[Test]
    public function the_summary_is_computed_from_the_marks_and_the_enrolment(): void
    {
        $this->travelTo(Carbon::parse('2026-11-11 02:00:00'));

        $boy = $this->sf2Learner('Male', 'Boy Learner');
        $girl = $this->sf2Learner('Female', 'Girl Learner');
        $left = $this->sf2Learner('Female', 'Left Learner');
        $left->update(['feeding_removed_at' => Carbon::parse('2026-11-05 01:00:00')]);

        foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'] as $date) {
            $this->markOn($boy, $date, 'present');
            $this->markOn($girl, $date, 'absent');
        }

        $summary = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-program/attendance/record')
            ->assertOk()
            ->viewData('summary');

        $this->assertSame(5, $summary['feeding_days']);
        $this->assertSame(['Male' => 1, 'Female' => 2, 'Total' => 3], $summary['enrolment_start']);
        $this->assertSame(['Male' => 1, 'Female' => 1, 'Total' => 2], $summary['registered']);
        $this->assertSame(['Male' => 0, 'Female' => 1, 'Total' => 1], $summary['dropped']);
        $this->assertSame(['Male' => 0, 'Female' => 0, 'Total' => 0], $summary['late_enrolment']);
        $this->assertEquals(50.0, $summary['percent_enrolment']['Female']);
        // Five presents over five recorded days.
        $this->assertEquals(1.0, $summary['ada']['Total']);
        $this->assertEquals(50.0, $summary['percent_attendance']['Total']);
        $this->assertSame(['Male' => 0, 'Female' => 1, 'Total' => 1], $summary['consecutive_absent']);
        // The base is the programme's first feeding day, as SF2's is one cut-off.
        $this->assertSame('2026-11-02', $summary['cutoff']);

        $this->travelBack();
    }

    /**
     * A learner enrolled after the cut-off is a late enrollee: not in the base,
     * counted in the month they joined, and among those registered at its end —
     * so the percentage of enrollment reads registered over the cut-off base.
     */
    #[Test]
    public function a_learner_enrolled_after_the_cutoff_is_late_enrollment(): void
    {
        $this->travelTo(Carbon::parse('2026-11-11 02:00:00'));

        $first = $this->sf2Learner('Male', 'First Learner');
        $this->markOn($first, '2026-11-02', 'present');
        $late = $this->sf2Learner('Male', 'Late Learner');
        $late->update(['feeding_enrolled_at' => Carbon::parse('2026-11-09 01:00:00')]);

        $summary = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-program/attendance/record')
            ->assertOk()
            ->assertSee('Enrollment as of November 2, 2026')
            ->assertSee('Late enrollment during the month')
            ->assertDontSee('Enrolment')
            ->viewData('summary');

        $this->assertSame(1, $summary['enrolment_start']['Male']);
        $this->assertSame(1, $summary['late_enrolment']['Male']);
        $this->assertSame(2, $summary['registered']['Male']);
        $this->assertEquals(200.0, $summary['percent_enrolment']['Male']);

        // Read in October, before the programme began: nobody is late against
        // a cut-off that had not happened.
        $october = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-program/attendance/record?month=2026-10')
            ->viewData('summary');
        $this->assertSame('2026-10-31', $october['cutoff']);
        $this->assertSame(0, $october['late_enrolment']['Total']);

        $this->travelBack();
    }

    /** An earlier month is a record: no field, no save, no read-back. */
    #[Test]
    public function an_earlier_month_is_read_only(): void
    {
        $this->travelTo(Carbon::parse('2026-11-11 02:00:00'));

        $learner = $this->sf2Learner('Male', 'Boy Learner');

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-program/attendance/record?month=2026-10')
            ->assertOk()
            ->assertSee('October 2026')
            ->assertDontSee('name="marks['.$learner->id.']"', false)
            ->assertDontSee('id="confirmBackdrop"', false);

        $this->assertNull($response->viewData('sessionDate'));
        $this->assertSame('2026-11', $response->viewData('nextMonth'));

        // A month still ahead is not offered.
        $this->assertNull($this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-program/attendance/record')
            ->viewData('nextMonth'));

        $this->travelBack();
    }

    /** Saving from the form returns to it, on the class it was narrowed to. */
    #[Test]
    public function saving_returns_to_the_form_on_the_same_class(): void
    {
        $this->travelTo(Carbon::parse('2026-11-11 02:00:00'));

        $learner = $this->makeStudent();
        $today = '2026-11-11';

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $today,
                'return_to' => 'record',
                'grade' => 'Grade 7',
                'section' => 'Sampaguita',
                'marks' => [$learner->id => 'present'],
            ])
            ->assertRedirect(route('feedingcor-program.attendance.record', [
                'date' => $today, 'grade' => 'Grade 7', 'section' => 'Sampaguita',
            ]));

        $this->assertSame(1, FeedingAttendance::count());
    }
}
