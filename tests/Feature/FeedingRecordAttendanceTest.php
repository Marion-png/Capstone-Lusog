<?php

namespace Tests\Feature;

use App\Models\AttendanceImport;
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
     * A recorded mark is settled. Re-posting the sheet cannot rewrite it — the
     * screen renders those rows without controls, but a stale tab or a replayed
     * form would still reach this endpoint, so the refusal lives on the server.
     * A genuine mistake is put right on the learner's beneficiary record, where
     * the correction is attributed and audited.
     */
    #[Test]
    public function a_recorded_mark_cannot_be_rewritten_by_saving_the_sheet_again(): void
    {
        $learner = $this->makeStudent();
        $today = now()->toDateString();

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $today,
                'marks' => [$learner->id => 'present'],
            ]);

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $today,
                'marks' => [$learner->id => 'absent'],
                'remarks' => [$learner->id => 'Changed my mind'],
            ])
            ->assertSessionHas('error');

        $rows = FeedingAttendance::where('student_health_record_id', $learner->id)->get();

        $this->assertCount(1, $rows, 'The second save writes nothing, not a duplicate.');
        $this->assertTrue((bool) $rows->first()->is_present, 'The mark on file is the one first recorded.');
        $this->assertSame('', trim((string) $rows->first()->remarks), 'And its remark is untouched too.');
    }

    /**
     * A recorded session is closed as a whole, not learner by learner.
     *
     * Recording is one act covering the entire roll, so the first confirmed mark
     * closes the day: a second pass cannot add a learner the first one missed,
     * and cannot rewrite one it recorded. That gap is filled on the learner's own
     * beneficiary record, where the change carries who made it and is audited —
     * rather than by two people writing the same day from two screens.
     */
    #[Test]
    public function a_recorded_session_is_closed_to_a_second_save(): void
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
                'marks' => [$first->id => 'absent', $second->id => 'absent'],
            ])
            ->assertSessionHas('error');

        // The first pass stands, and the second wrote nothing at all.
        $this->assertTrue((bool) FeedingAttendance::where('student_health_record_id', $first->id)->first()->is_present);
        $this->assertNull(FeedingAttendance::where('student_health_record_id', $second->id)->first());
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
}
