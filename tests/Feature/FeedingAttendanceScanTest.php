<?php

namespace Tests\Feature;

use App\Models\AttendanceImport;
use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\AttendanceSheetScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * End-to-end guard for the photographed-sheet flow. The scanner itself is
 * substituted so no request ever reaches the Claude API from the test suite.
 *
 * What these pin down: an unreadable mark is stored as NULL and queued for a
 * human rather than counted as an absence; a failed scan writes nothing at all;
 * confirming a mark is attributed and recomputes the flag; and the photo is
 * purged once nothing is left to review.
 */
class FeedingAttendanceScanTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
        config()->set('services.anthropic.key', 'test-key-not-used');
        config()->set('feeding.scanning.enabled', true);
        Storage::fake('local');
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

    private function makeStudent(string $name, string $section = 'Grade 7 / Rizal'): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => $name,
            'student_id' => 'LRN'.random_int(100000, 999999),
            'school_name' => 'Test School',
            'section' => $section,
            'weight' => 30,
            'bmi_value' => 15.2,
            'nutritional_status' => 'Wasted',
            'baseline_nutritional_status' => 'Wasted',
            'student_details' => ['gender' => 'Male'],
        ]);
    }

    /** Swaps in a scanner that returns fixed marks without calling the API. */
    private function fakeScanner(array $marksByIndex, bool $unreadable = false, string $note = ''): void
    {
        $this->swap(AttendanceSheetScanner::class, new class($marksByIndex, $unreadable, $note) extends AttendanceSheetScanner
        {
            public function __construct(
                private readonly array $marksByIndex,
                private readonly bool $unreadable,
                private readonly string $note,
            ) {
                parent::__construct();
            }

            public function scan(UploadedFile $photo, array $roster, string $sessionDate): array
            {
                return [
                    'marks' => $this->marksByIndex,
                    'unreadable' => $this->unreadable,
                    'note' => $this->note,
                ];
            }
        });
    }

    private function failingScanner(string $message = 'network down'): void
    {
        $this->swap(AttendanceSheetScanner::class, new class($message) extends AttendanceSheetScanner
        {
            public function __construct(private readonly string $message)
            {
                parent::__construct();
            }

            public function scan(UploadedFile $photo, array $roster, string $sessionDate): array
            {
                throw new \RuntimeException($this->message);
            }
        });
    }

    /**
     * Explicit mime rather than fake()->image(): the latter needs the GD
     * extension, which the validation path here does not (Laravel's `image`
     * rule checks the mime type, not the pixels).
     */
    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->create('sheet.jpg', 120, 'image/jpeg');
    }

    private function postScan(string $date = '2026-08-05')
    {
        return $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/scan', [
                'attendance_photo' => $this->photo(),
                'session_date' => $date,
            ]);
    }

    #[Test]
    public function a_scanned_sheet_records_present_and_absent_marks(): void
    {
        $alpha = $this->makeStudent('Alpha Learner');
        $bravo = $this->makeStudent('Bravo Learner');

        $this->fakeScanner([1 => 'P', 2 => 'A']);
        $this->postScan()->assertRedirect();

        $this->assertTrue(
            FeedingAttendance::where('student_health_record_id', $alpha->id)->first()->is_present
        );
        $this->assertFalse(
            FeedingAttendance::where('student_health_record_id', $bravo->id)->first()->is_present
        );
        $this->assertSame(0, FeedingAttendance::awaitingReview()->count());
    }

    #[Test]
    public function an_unclear_mark_is_stored_as_null_and_queued_for_review(): void
    {
        $alpha = $this->makeStudent('Alpha Learner');

        $this->fakeScanner([1 => '?']);
        $this->postScan()->assertRedirect();

        $row = FeedingAttendance::where('student_health_record_id', $alpha->id)->first();

        // The whole point: not false. An unread mark is not an absence.
        $this->assertNull($row->is_present);
        $this->assertTrue($row->needs_review);
        $this->assertSame(FeedingAttendance::SOURCE_PHOTO_SCAN, $row->source);
    }

    #[Test]
    public function an_unclear_mark_does_not_flag_the_learner_at_risk(): void
    {
        $alpha = $this->makeStudent('Alpha Learner');

        $this->fakeScanner([1 => '?']);
        $this->postScan();

        $this->assertFalse((bool) $alpha->fresh()->is_at_risk);
    }

    #[Test]
    public function an_unreadable_photo_queues_every_mark_and_writes_no_attendance_values(): void
    {
        $this->makeStudent('Alpha Learner');
        $this->makeStudent('Bravo Learner');

        // The model claims marks but also reports the sheet unreadable — the
        // unreadable verdict wins, so nothing is trusted.
        $this->fakeScanner([1 => 'P', 2 => 'A'], unreadable: true, note: 'Photo is blurred.');
        $this->postScan();

        $this->assertSame(2, FeedingAttendance::awaitingReview()->count());
        $this->assertSame(0, FeedingAttendance::whereNotNull('is_present')->count());
    }

    #[Test]
    public function a_failed_scan_writes_nothing(): void
    {
        $this->makeStudent('Alpha Learner');

        $this->failingScanner();
        $this->postScan()->assertRedirect();

        $this->assertSame(0, FeedingAttendance::count());
        $this->assertSame(0, AttendanceImport::count());
    }

    #[Test]
    public function confirming_a_mark_records_who_decided_and_recomputes_the_flag(): void
    {
        $alpha = $this->makeStudent('Alpha Learner');

        // Three confirmed absences plus one unclear: 0% of 3 confirmed → flagged.
        $this->fakeScanner([1 => 'A']);
        $this->postScan('2026-08-01');
        $this->postScan('2026-08-02');
        $this->postScan('2026-08-03');

        $this->fakeScanner([1 => '?']);
        $this->postScan('2026-08-04');

        $pending = FeedingAttendance::awaitingReview()->first();
        $this->assertNotNull($pending);

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/review/'.$pending->id, ['mark' => 'present'])
            ->assertRedirect();

        $resolved = $pending->fresh();
        $this->assertTrue($resolved->is_present);
        $this->assertFalse($resolved->needs_review);
        $this->assertSame('Test Coordinator', (string) $resolved->reviewed_by_name);
        $this->assertNotNull($resolved->reviewed_at);
        $this->assertSame(FeedingAttendance::SOURCE_MANUAL_REVIEW, $resolved->source);

        // 1 of 4 = 25%, still under 75.
        $this->assertTrue((bool) $alpha->fresh()->is_at_risk);
    }

    #[Test]
    public function the_photo_is_purged_once_every_mark_is_confirmed(): void
    {
        $this->makeStudent('Alpha Learner');

        $this->fakeScanner([1 => '?']);
        $this->postScan();

        $import = AttendanceImport::first();
        $this->assertNotNull($import->stored_path);
        $this->assertNull($import->photo_purged_at);

        $pending = FeedingAttendance::awaitingReview()->first();
        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/review/'.$pending->id, ['mark' => 'absent']);

        $import->refresh();
        $this->assertNull($import->stored_path);
        $this->assertNotNull($import->photo_purged_at);
    }

    #[Test]
    public function a_fully_readable_scan_purges_the_photo_immediately(): void
    {
        $this->makeStudent('Alpha Learner');

        $this->fakeScanner([1 => 'P']);
        $this->postScan();

        // Nothing to review means nothing to look at the photo for.
        $this->assertNotNull(AttendanceImport::first()->photo_purged_at);
    }

    #[Test]
    public function only_the_feeding_coordinator_may_scan(): void
    {
        $this->makeStudent('Alpha Learner');
        $this->fakeScanner([1 => 'P']);

        $this->withSession(['active_role' => 'class_adviser', 'active_institution_id' => $this->institution->id])
            ->post('/dashboard/feedingcor-program/attendance/scan', [
                'attendance_photo' => $this->photo(),
                'session_date' => '2026-08-05',
            ])
            ->assertRedirect(route('login'));

        $this->assertSame(0, FeedingAttendance::count());
    }

    #[Test]
    public function the_review_queue_is_scoped_to_the_active_school(): void
    {
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $this->makeStudent('Alpha Learner');

        $outsider = StudentHealthRecord::create([
            'institution_id' => $other->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Outsider Learner',
            'student_id' => 'LRN999999',
            'school_name' => 'Other School',
            'section' => 'Grade 7 / Rizal',
            'weight' => 30,
            'bmi_value' => 15.2,
            'nutritional_status' => 'Wasted',
            'student_details' => ['gender' => 'Male'],
        ]);

        FeedingAttendance::create([
            'student_health_record_id' => $outsider->id,
            'session_date' => '2026-08-05',
            'is_present' => null,
            'needs_review' => true,
            'source' => FeedingAttendance::SOURCE_PHOTO_SCAN,
        ]);

        $this->fakeScanner([1 => '?']);
        $this->postScan();

        $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-program/attendance/review')
            ->assertOk()
            ->assertSee('Alpha Learner')
            ->assertDontSee('Outsider Learner');
    }

    #[Test]
    public function a_coordinator_cannot_resolve_another_schools_mark(): void
    {
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $outsider = StudentHealthRecord::create([
            'institution_id' => $other->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Outsider Learner',
            'student_id' => 'LRN999998',
            'school_name' => 'Other School',
            'section' => 'Grade 7 / Rizal',
            'weight' => 30,
            'bmi_value' => 15.2,
            'nutritional_status' => 'Wasted',
            'student_details' => ['gender' => 'Male'],
        ]);

        $mark = FeedingAttendance::create([
            'student_health_record_id' => $outsider->id,
            'session_date' => '2026-08-05',
            'is_present' => null,
            'needs_review' => true,
            'source' => FeedingAttendance::SOURCE_PHOTO_SCAN,
        ]);

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/review/'.$mark->id, ['mark' => 'present']);

        $this->assertTrue($mark->fresh()->needs_review);
        $this->assertNull($mark->fresh()->is_present);
    }

    #[Test]
    public function a_re_uploaded_spreadsheet_supersedes_a_pending_scanned_mark(): void
    {
        $this->makeStudent('Alpha Learner');

        $this->fakeScanner([1 => '?']);
        $this->postScan();
        $this->assertSame(1, FeedingAttendance::awaitingReview()->count());

        $csv = "NAME,GRADE,SECTION,2026-08-05\nAlpha Learner,Grade 7,Rizal,P\n";
        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/import', [
                'attendance_file' => UploadedFile::fake()->createWithContent('sheet.csv', $csv),
            ]);

        $this->assertSame(0, FeedingAttendance::awaitingReview()->count());
    }
}
