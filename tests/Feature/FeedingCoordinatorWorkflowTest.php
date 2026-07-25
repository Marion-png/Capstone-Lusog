<?php

namespace Tests\Feature;

use App\Models\AttendanceImport;
use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FeedingCoordinatorWorkflowTest extends TestCase
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

    private function makeStudent(string $name, string $status = 'Wasted', string $section = 'Grade 7 / Sampaguita'): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => $name,
            'student_id' => 'LRN'.random_int(100000, 999999),
            'school_name' => 'Test School',
            'section' => $section,
            'weight' => 30,
            'bmi_value' => 15.0,
            'nutritional_status' => $status,
        ]);
    }

    private function uploadAttendanceFor(string $csvName): void
    {
        $csv = <<<CSV
        No.,NAME,GRADE,SECTION,"Oct 8, 2025","Oct 9, 2025"
        1,"{$csvName}",7,Sampaguita,A,A
        CSV;

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/import', [
                'attendance_file' => UploadedFile::fake()->createWithContent('a.csv', $csv),
                'grade' => 'all',
            ])->assertRedirect();
    }

    // ── Attendance gate ────────────────────────────────────────────────────

    #[Test]
    public function reports_are_gated_until_attendance_is_uploaded(): void
    {
        $this->makeStudent('Bautista, Andrei M.');

        $response = $this->withSession($this->coordinatorSession())->get('/dashboard/feedingcor-reports');
        $response->assertOk();
        $response->assertViewHas('attendanceUploaded', false);
        $this->assertNull($response->viewData('report'));

        // Export is blocked too.
        $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-reports/export')
            ->assertRedirect(route('dashboard.feedingcor-reports', ['year' => StudentHealthRecord::currentSchoolYear()]));
    }

    #[Test]
    public function reports_unlock_after_attendance_upload(): void
    {
        $this->makeStudent('Bautista, Andrei M.');
        $this->uploadAttendanceFor('Bautista, Andrei M.');

        $response = $this->withSession($this->coordinatorSession())->get('/dashboard/feedingcor-reports');
        $response->assertOk();
        $response->assertViewHas('attendanceUploaded', true);
        $this->assertNotNull($response->viewData('report'));
    }

    // ── Import robustness ──────────────────────────────────────────────────

    #[Test]
    public function a_successful_import_records_a_batch(): void
    {
        $this->makeStudent('Bautista, Andrei M.');
        $this->uploadAttendanceFor('Bautista, Andrei M.');

        $this->assertSame(1, AttendanceImport::count());
        $import = AttendanceImport::first();
        $this->assertSame(1, $import->matched_count);
        $this->assertTrue(AttendanceImport::existsForPeriod($this->institution->id, StudentHealthRecord::currentSchoolYear()));
    }

    #[Test]
    public function a_fully_unmatched_import_writes_nothing(): void
    {
        $this->makeStudent('Bautista, Andrei M.');
        $this->uploadAttendanceFor('Nobody, Real Q.');

        // No match at all → error, and neither attendance nor a batch is written.
        $this->assertSame(0, FeedingAttendance::count());
        $this->assertSame(0, AttendanceImport::count());
        $this->assertFalse(AttendanceImport::existsForPeriod($this->institution->id));
    }

    // ── Baseline → Endline dependency ──────────────────────────────────────

    #[Test]
    public function endline_is_blocked_until_a_baseline_exists(): void
    {
        $student = $this->makeStudent('Bautista, Andrei M.');

        // Endline before baseline → rejected, nothing stored.
        $this->withSession($this->coordinatorSession())
            ->post(route('feedingcor.endline.store', $student->id), [
                'age' => 12, 'height_cm' => 150, 'weight_kg' => 34,
            ])->assertRedirect();
        $this->assertNull($student->fresh()->endline_bmi_value);

        // Save a baseline.
        $this->withSession($this->coordinatorSession())
            ->post(route('feedingcor.baseline.store'), [
                'student_id' => $student->student_id,
                'student_name' => 'Bautista, Andrei M.',
                'section' => 'Grade 7 / Sampaguita',
                'age' => 12, 'height_cm' => 148, 'weight_kg' => 30,
            ])->assertRedirect();
        $this->assertNotNull($student->fresh()->baseline_bmi_value);

        // Now endline is allowed.
        $this->withSession($this->coordinatorSession())
            ->post(route('feedingcor.endline.store', $student->id), [
                'age' => 12, 'height_cm' => 150, 'weight_kg' => 34,
            ])->assertRedirect();
        $this->assertNotNull($student->fresh()->endline_bmi_value);
    }

    // ── Report computation: complete vs incomplete ─────────────────────────

    #[Test]
    public function report_computes_counts_and_flags_missing_endline(): void
    {
        $withEndline = $this->makeStudent('Alpha, One A.');
        $baselineOnly = $this->makeStudent('Bravo, Two B.');
        $this->uploadAttendanceFor('Alpha, One A.');

        // Alpha: baseline + endline. Bravo: baseline only.
        foreach ([$withEndline, $baselineOnly] as $s) {
            $this->withSession($this->coordinatorSession())->post(route('feedingcor.baseline.store'), [
                'student_id' => $s->student_id, 'student_name' => (string) $s->student_name,
                'section' => 'Grade 7 / Sampaguita', 'age' => 12, 'height_cm' => 148, 'weight_kg' => 30,
            ])->assertRedirect();
        }
        $this->withSession($this->coordinatorSession())->post(route('feedingcor.endline.store', $withEndline->id), [
            'age' => 12, 'height_cm' => 150, 'weight_kg' => 34,
        ])->assertRedirect();

        $report = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-reports')
            ->viewData('report');

        $this->assertSame(2, $report['qualifiedCount']);
        $this->assertCount(1, $report['missingEndline']);      // Bravo pending endline
        $this->assertCount(0, $report['missingBaseline']);     // both have baseline
    }
}
