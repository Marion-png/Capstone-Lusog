<?php

namespace Tests\Feature;

use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The photo-scan/review columns are additive, so a database that has not yet
 * run that migration must keep working exactly as it did before — the Feeding
 * Program page opened with a 500 on production for precisely this reason.
 *
 * These tests drop the new columns to simulate an un-migrated database and
 * assert the pages still render and the spreadsheet import still works, with
 * scanning simply unavailable.
 */
class FeedingPendingMigrationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);

        // Roll the schema back to its pre-scan shape (indexes first, as the
        // migration's own down() does).
        Schema::table('feeding_attendances', function ($table) {
            $table->dropIndex(['attendance_import_id']);
            $table->dropIndex(['source']);
            $table->dropIndex(['needs_review']);
        });

        Schema::table('feeding_attendances', function ($table) {
            $table->dropColumn(['attendance_import_id', 'source', 'needs_review', 'reviewed_by_name', 'reviewed_at']);
        });

        $this->assertFalse(Schema::hasColumn('feeding_attendances', 'needs_review'));
    }

    private function coordinatorSession(): array
    {
        return [
            'active_role' => 'feeding_coor',
            'active_name' => 'Test Coordinator',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->institution->id,
        ];
    }

    private function makeStudent(string $name): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => $name,
            'student_id' => 'LRN'.random_int(100000, 999999),
            'school_name' => 'Test School',
            'section' => 'Grade 7 / Rizal',
            'weight' => 30,
            'bmi_value' => 15.2,
            'nutritional_status' => 'Wasted',
            'baseline_nutritional_status' => 'Wasted',
            'student_details' => ['gender' => 'Male'],
        ]);
    }

    #[Test]
    public function the_feeding_program_page_opens_without_the_review_columns(): void
    {
        $this->makeStudent('Alpha Learner');

        $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-program')
            ->assertOk();
    }

    #[Test]
    public function the_review_queue_opens_and_is_empty_without_the_columns(): void
    {
        $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-program/attendance/review')
            ->assertOk()
            ->assertSee('Nothing awaiting review.');
    }

    #[Test]
    public function the_spreadsheet_import_still_records_attendance(): void
    {
        $alpha = $this->makeStudent('Alpha Learner');

        $csv = "NAME,GRADE,SECTION,2026-08-05\nAlpha Learner,Grade 7,Rizal,P\n";

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/import', [
                'attendance_file' => UploadedFile::fake()->createWithContent('sheet.csv', $csv),
            ])
            ->assertRedirect();

        $this->assertTrue(
            (bool) FeedingAttendance::where('student_health_record_id', $alpha->id)->first()->is_present
        );
    }

    #[Test]
    public function at_risk_still_recomputes_without_the_review_columns(): void
    {
        $alpha = $this->makeStudent('Alpha Learner');

        // One present, three absent = 25%, under the 75% default.
        $csv = "NAME,GRADE,SECTION,2026-08-01,2026-08-02,2026-08-03,2026-08-04\n"
            ."Alpha Learner,Grade 7,Rizal,P,A,A,A\n";

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/import', [
                'attendance_file' => UploadedFile::fake()->createWithContent('sheet.csv', $csv),
            ]);

        $this->assertTrue((bool) $alpha->fresh()->is_at_risk);
    }

    #[Test]
    public function scanning_refuses_cleanly_instead_of_erroring(): void
    {
        config()->set('services.anthropic.key', 'test-key-not-used');
        config()->set('feeding.scanning.enabled', true);
        $this->makeStudent('Alpha Learner');

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/scan', [
                'attendance_photo' => UploadedFile::fake()->create('sheet.jpg', 120, 'image/jpeg'),
                'session_date' => '2026-08-05',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, FeedingAttendance::count());
    }
}
