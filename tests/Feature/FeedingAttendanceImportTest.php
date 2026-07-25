<?php

namespace Tests\Feature;

use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FeedingAttendanceImportTest extends TestCase
{
    use RefreshDatabase;

    private const IMPORT_ROUTE = '/dashboard/feedingcor-program/attendance/import';

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
            'baseline_nutritional_status' => $status,
        ]);
    }

    private function csvFile(string $content, string $name = 'attendance.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    #[Test]
    public function uploaded_sheet_flags_low_attendance_learners_and_records_sessions(): void
    {
        $bautista = $this->makeStudent('Bautista, Andrei M.');
        $cruz = $this->makeStudent('Cruz, Bianca L.');
        $delos = $this->makeStudent('Delos Reyes, Carlo P.');

        $csv = <<<CSV
        No.,NAME,GRADE,SECTION,"Oct 8, 2025","Oct 9, 2025","Oct 10, 2025"
        1,"Bautista, Andrei M.",7,Sampaguita,A,A,A
        2,"Cruz, Bianca L.",7,Sampaguita,P,P,P
        3,"Delos Reyes, Carlo P.",7,Sampaguita,P,P,A
        CSV;

        $response = $this->withSession($this->coordinatorSession())
            ->post(self::IMPORT_ROUTE, [
                'attendance_file' => $this->csvFile($csv),
                'grade' => 'Grade 7',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // 3 learners × 3 sessions.
        $this->assertSame(9, FeedingAttendance::count());

        // At-risk is decided purely by attendance (threshold 75%).
        $this->assertTrue($bautista->fresh()->is_at_risk, '0/3 present should be at-risk');
        $this->assertFalse($cruz->fresh()->is_at_risk, '3/3 present should not be at-risk');
        $this->assertTrue($delos->fresh()->is_at_risk, '2/3 = 66% should be at-risk');
    }

    #[Test]
    public function a_wasted_learner_is_not_at_risk_without_poor_attendance(): void
    {
        // Proves nutritional status alone never flags a learner.
        $student = $this->makeStudent('Santos, Maria L.', 'Severely Wasted');

        $csv = <<<CSV
        No.,NAME,GRADE,SECTION,"Oct 8, 2025","Oct 9, 2025"
        1,"Santos, Maria L.",7,Sampaguita,P,P
        CSV;

        $this->withSession($this->coordinatorSession())
            ->post(self::IMPORT_ROUTE, ['attendance_file' => $this->csvFile($csv), 'grade' => 'all'])
            ->assertRedirect();

        $this->assertFalse($student->fresh()->is_at_risk);
    }

    #[Test]
    public function rows_match_records_despite_reordered_name_format(): void
    {
        $student = $this->makeStudent('Bautista, Andrei M.');

        // Uploaded as "First Middle Last" — must still match "Last, First M.".
        $csv = <<<CSV
        No.,NAME,GRADE,SECTION,"Oct 8, 2025"
        1,Andrei Bautista,7,Sampaguita,A
        CSV;

        $this->withSession($this->coordinatorSession())
            ->post(self::IMPORT_ROUTE, ['attendance_file' => $this->csvFile($csv), 'grade' => 'all'])
            ->assertRedirect();

        $this->assertSame(1, FeedingAttendance::where('student_health_record_id', $student->id)->count());
        $this->assertTrue($student->fresh()->is_at_risk);
    }

    #[Test]
    public function unmatched_rows_are_reported_back(): void
    {
        $this->makeStudent('Bautista, Andrei M.');

        $csv = <<<CSV
        No.,NAME,GRADE,SECTION,"Oct 8, 2025"
        1,"Nonexistent, Person Q.",7,Sampaguita,A
        CSV;

        $response = $this->withSession($this->coordinatorSession())
            ->post(self::IMPORT_ROUTE, ['attendance_file' => $this->csvFile($csv), 'grade' => 'all']);

        // No match at all → error, nothing recorded.
        $response->assertSessionHas('error');
        $this->assertSame(0, FeedingAttendance::count());
    }

    #[Test]
    public function only_the_feeding_coordinator_may_import(): void
    {
        $csv = "No.,NAME,GRADE,SECTION,\"Oct 8, 2025\"\n1,\"Bautista, Andrei M.\",7,Sampaguita,A\n";

        $response = $this->withSession([
            'active_role' => 'school_nurse',
            'active_name' => 'Nurse',
            'active_username' => 'nurse.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->institution->id,
        ])->post(self::IMPORT_ROUTE, ['attendance_file' => $this->csvFile($csv), 'grade' => 'all']);

        $response->assertRedirect(route('login'));
        $this->assertSame(0, FeedingAttendance::count());
    }

    #[Test]
    public function session_columns_without_date_headers_still_count(): void
    {
        // Headers "1/2/3" are not dates, but the marks must still be read.
        $absent = $this->makeStudent('Bautista, Andrei M.');
        $present = $this->makeStudent('Cruz, Bianca L.');

        $csv = <<<CSV
        No.,NAME,GRADE,SECTION,1,2,3
        1,"Bautista, Andrei M.",7,Sampaguita,A,A,A
        2,"Cruz, Bianca L.",7,Sampaguita,P,P,P
        CSV;

        $this->withSession($this->coordinatorSession())
            ->post(self::IMPORT_ROUTE, ['attendance_file' => $this->csvFile($csv), 'grade' => 'all'])
            ->assertRedirect();

        $this->assertSame(6, FeedingAttendance::count()); // 2 learners × 3 sessions
        $this->assertTrue($absent->fresh()->is_at_risk);
        $this->assertFalse($present->fresh()->is_at_risk);
    }

    #[Test]
    public function a_blank_template_reports_no_marks(): void
    {
        $student = $this->makeStudent('Bautista, Andrei M.');

        $csv = <<<CSV
        No.,NAME,GRADE,SECTION,"Oct 8, 2025","Oct 9, 2025"
        1,"Bautista, Andrei M.",7,Sampaguita,,
        CSV;

        $response = $this->withSession($this->coordinatorSession())
            ->post(self::IMPORT_ROUTE, ['attendance_file' => $this->csvFile($csv), 'grade' => 'all']);

        $response->assertSessionHas('error');
        $this->assertSame(0, FeedingAttendance::count());
        $this->assertFalse($student->fresh()->is_at_risk);
    }

    #[Test]
    public function free_text_columns_are_not_treated_as_sessions(): void
    {
        $student = $this->makeStudent('Bautista, Andrei M.');

        // One real dated session plus a free-text "Remarks" column.
        $csv = <<<CSV
        No.,NAME,GRADE,SECTION,"Oct 8, 2025",Remarks
        1,"Bautista, Andrei M.",7,Sampaguita,P,Transferred
        CSV;

        $this->withSession($this->coordinatorSession())
            ->post(self::IMPORT_ROUTE, ['attendance_file' => $this->csvFile($csv), 'grade' => 'all'])
            ->assertRedirect();

        // Only the dated column is a session; the Remarks column is ignored.
        $this->assertSame(1, FeedingAttendance::where('student_health_record_id', $student->id)->count());
        $this->assertFalse($student->fresh()->is_at_risk); // 1/1 present
    }

    #[Test]
    public function signature_block_rows_are_excluded(): void
    {
        $this->makeStudent('Bautista, Andrei M.');

        // "Prepared by:" sits in the No. column, not under NAME.
        $csv = <<<CSV
        No.,NAME,GRADE,SECTION,"Oct 8, 2025"
        1,"Bautista, Andrei M.",7,Sampaguita,A
        Prepared by:,Vanessa Mae Villegas,,,
        CSV;

        $this->withSession($this->coordinatorSession())
            ->post(self::IMPORT_ROUTE, ['attendance_file' => $this->csvFile($csv), 'grade' => 'all'])
            ->assertRedirect();

        // Only the real learner is recorded; the signature row is skipped.
        $this->assertSame(1, FeedingAttendance::count());
    }
}
