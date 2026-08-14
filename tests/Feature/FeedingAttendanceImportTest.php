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

    private const PROGRAM_ROUTE = '/dashboard/feedingcor-program';

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

        $csv = <<<'CSV'
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
        // A clean import says nothing — the page it lands on is the report.
        $response->assertSessionMissing('success');

        // 3 learners × 3 sessions.
        $this->assertSame(9, FeedingAttendance::count());

        // At-risk is decided purely by attendance, against the school's
        // threshold (the app default, 80%, for a school that has set none).
        $this->assertTrue($bautista->fresh()->is_at_risk, '0/3 present should be at-risk');
        $this->assertFalse($cruz->fresh()->is_at_risk, '3/3 present should not be at-risk');
        $this->assertTrue($delos->fresh()->is_at_risk, '2/3 = 66% should be at-risk');
    }

    #[Test]
    public function a_learner_is_listed_once_before_the_upload_and_once_after(): void
    {
        $this->makeStudent('Bautista, Andrei M.');

        // Listed as a beneficiary from the start — being on no sheet yet is not
        // a fact about the learner — but with no attendance claimed for them.
        $this->assertSame(1, $this->rosterRowsFor('Bautista, Andrei M.'));

        $csv = <<<'CSV'
        No.,NAME,GRADE,SECTION,"Oct 8, 2025","Oct 9, 2025","Oct 10, 2025","Oct 13, 2025"
        1,"Bautista, Andrei M.",7,Sampaguita,P,P,P,A
        CSV;

        $this->withSession($this->coordinatorSession())
            ->post(self::IMPORT_ROUTE, ['attendance_file' => $this->csvFile($csv), 'grade' => 'all'])
            ->assertRedirect();

        // Still one row — the upload fills the learner's attendance in, it does
        // not add a second listing of them.
        $this->assertSame(1, $this->rosterRowsFor('Bautista, Andrei M.'));

        $this->withSession($this->coordinatorSession())
            ->get(self::PROGRAM_ROUTE)
            ->assertOk()
            ->assertSee('75%'); // 3 of 4 sessions attended.
    }

    #[Test]
    public function the_totals_add_up_to_the_uploaded_sheet(): void
    {
        $bautista = $this->makeStudent('Bautista, Andrei M.');

        // A sheet covering three feeding days, two of them still ahead of today.
        $future = now()->addDays(2)->toDateString();
        $csv = <<<CSV
        No.,NAME,GRADE,SECTION,"{$this->today()}","{$this->tomorrow()}","{$future}"
        1,"Bautista, Andrei M.",7,Sampaguita,A,A,A
        CSV;

        $this->withSession($this->coordinatorSession())
            ->post(self::IMPORT_ROUTE, ['attendance_file' => $this->csvFile($csv), 'grade' => 'all'])
            ->assertRedirect();

        $this->assertSame(3, FeedingAttendance::where('student_health_record_id', $bautista->id)->count());

        // The table reports the sheet: three absences, not just the one whose
        // day has already come around. Present, Absent and Attendance sit next
        // to each other, so the triple is asserted as a whole.
        $row = $this->rosterRowText('Bautista, Andrei M.');
        $this->assertMatchesRegularExpression(
            '/\b0 3 0%/',
            $row,
            'Present/Absent/Attendance do not add up to the uploaded sheet: '.$row
        );

        // The at-risk flag still weighs only sessions that have happened.
        $this->assertTrue($bautista->fresh()->is_at_risk);
    }

    private function today(): string
    {
        return now()->toDateString();
    }

    private function tomorrow(): string
    {
        return now()->addDay()->toDateString();
    }

    /**
     * This learner's row in the beneficiaries table, as plain text.
     *
     * Scoped to that table on purpose: an at-risk learner also appears in the
     * At-Risk Beneficiaries alert above it, and that row carries different
     * figures.
     */
    private function rosterRowText(string $name): string
    {
        $html = $this->withSession($this->coordinatorSession())
            ->get(self::PROGRAM_ROUTE)
            ->assertOk()
            ->getContent();

        $table = str_contains($html, 'id="rosterTable"')
            ? substr($html, strpos($html, 'id="rosterTable"'))
            : $html;
        $table = substr($table, 0, strpos($table, '</table>') ?: strlen($table));

        preg_match_all('#<tr[^>]*>.*?</tr>#s', $table, $rows);

        $row = collect($rows[0])->first(fn (string $row): bool => str_contains($row, e($name))) ?? '';

        return trim(preg_replace('/\s+/', ' ', strip_tags($row)) ?? '');
    }

    #[Test]
    public function the_page_lists_every_beneficiary_exactly_once(): void
    {
        // The page used to draw two tables from the same learners — a
        // beneficiaries table and an attendance table — so each one appeared
        // twice, with grade, section and attendance repeated between them.
        $this->makeStudent('Bautista, Andrei M.');
        $this->makeStudent('Cruz, Bianca L.');
        $this->makeStudent('Delos Reyes, Carlo P.');

        foreach (['Bautista, Andrei M.', 'Cruz, Bianca L.', 'Delos Reyes, Carlo P.'] as $name) {
            $this->assertSame(1, $this->rosterRowsFor($name), $name.' is listed more than once.');
        }
    }

    /**
     * How many rows of the beneficiaries roster name this learner.
     *
     * Scoped to the roster table on purpose: a flagged learner also appears in
     * the At-Risk Beneficiaries table further down the same page, and that
     * second listing is the feature working, not a duplicate.
     */
    private function rosterRowsFor(string $name): int
    {
        $html = $this->withSession($this->coordinatorSession())
            ->get(self::PROGRAM_ROUTE)
            ->assertOk()
            ->getContent();

        $roster = preg_match('#<table id="rosterTable".*?</table>#s', $html, $match) ? $match[0] : '';

        preg_match_all('#<tr[^>]*>.*?</tr>#s', $roster, $rows);

        return collect($rows[0])
            ->filter(fn (string $row): bool => str_contains($row, e($name)))
            ->count();
    }

    #[Test]
    public function unconfirmed_marks_are_held_out_of_the_roster_rate(): void
    {
        // The NULL invariant: a scanned mark nobody has confirmed counts neither
        // as attendance nor as an absence, so it cannot move the rate.
        $student = $this->makeStudent('Cruz, Bianca L.');

        $csv = <<<'CSV'
        No.,NAME,GRADE,SECTION,"Oct 8, 2025","Oct 9, 2025"
        1,"Cruz, Bianca L.",7,Sampaguita,P,P
        CSV;

        $this->withSession($this->coordinatorSession())
            ->post(self::IMPORT_ROUTE, ['attendance_file' => $this->csvFile($csv), 'grade' => 'all'])
            ->assertRedirect();

        FeedingAttendance::create([
            'student_health_record_id' => $student->id,
            'session_date' => '2025-10-10',
            'is_present' => null,
            'needs_review' => true,
            'source' => FeedingAttendance::SOURCE_PHOTO_SCAN,
        ]);

        $this->withSession($this->coordinatorSession())
            ->get(self::PROGRAM_ROUTE)
            ->assertOk()
            ->assertSee('100%')          // 2 of 2 confirmed, not 2 of 3.
            ->assertSee('1 awaiting review');
    }

    #[Test]
    public function a_wasted_learner_is_not_at_risk_without_poor_attendance(): void
    {
        // Proves nutritional status alone never flags a learner.
        $student = $this->makeStudent('Santos, Maria L.', 'Severely Wasted');

        $csv = <<<'CSV'
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
        $csv = <<<'CSV'
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
    public function a_name_the_adviser_never_registered_is_skipped_and_never_rendered(): void
    {
        $bautista = $this->makeStudent('Bautista, Andrei M.');

        $csv = <<<'CSV'
        No.,NAME,GRADE,SECTION,"Oct 8, 2025","Oct 9, 2025"
        1,"Bautista, Andrei M.",7,Sampaguita,P,P
        2,"Villanueva, Ghostwriter Q.",7,Sampaguita,P,P
        CSV;

        $response = $this->withSession($this->coordinatorSession())
            ->post(self::IMPORT_ROUTE, ['attendance_file' => $this->csvFile($csv), 'grade' => 'all']);

        // The registered learner is recorded; the unknown row creates nothing.
        $this->assertSame(2, FeedingAttendance::count());
        $this->assertSame(2, FeedingAttendance::where('student_health_record_id', $bautista->id)->count());
        $this->assertSame(1, StudentHealthRecord::count());

        // The skipped name is reported as a count, never echoed back as a learner.
        $response->assertSessionHas('error');
        $this->assertStringNotContainsString('Ghostwriter', (string) session('error'));

        $this->withSession($this->coordinatorSession())
            ->get(self::PROGRAM_ROUTE)
            ->assertOk()
            ->assertSee('Bautista, Andrei M.')
            ->assertDontSee('Ghostwriter');
    }

    #[Test]
    public function a_near_miss_name_is_not_attached_to_a_different_learner(): void
    {
        // Same surname and first name, different middle name — two children, not
        // one. A partial overlap must not be read as a match.
        $paolo = $this->makeStudent('Cruz, Juan Paolo');

        $csv = <<<'CSV'
        No.,NAME,GRADE,SECTION,"Oct 8, 2025"
        1,"Cruz, Juan Miguel",7,Sampaguita,P
        CSV;

        $this->withSession($this->coordinatorSession())
            ->post(self::IMPORT_ROUTE, ['attendance_file' => $this->csvFile($csv), 'grade' => 'all'])
            ->assertSessionHas('error');

        $this->assertSame(0, FeedingAttendance::where('student_health_record_id', $paolo->id)->count());
        $this->assertSame(0, FeedingAttendance::count());
    }

    #[Test]
    public function two_namesakes_with_no_section_to_tell_them_apart_are_both_left_alone(): void
    {
        $sampaguita = $this->makeStudent('Santos, Maria L.', section: 'Grade 7 / Sampaguita');
        $rosal = $this->makeStudent('Santos, Maria L.', section: 'Grade 7 / Rosal');

        // The row names one of them but says which only by a blank section.
        $csv = <<<'CSV'
        No.,NAME,GRADE,SECTION,"Oct 8, 2025"
        1,"Santos, Maria L.",7,,P
        CSV;

        $this->withSession($this->coordinatorSession())
            ->post(self::IMPORT_ROUTE, ['attendance_file' => $this->csvFile($csv), 'grade' => 'all'])
            ->assertSessionHas('error');

        // Guessing would have put another child's attendance on this record.
        $this->assertSame(0, FeedingAttendance::whereIn('student_health_record_id', [$sampaguita->id, $rosal->id])->count());
    }

    #[Test]
    public function a_section_still_separates_two_learners_with_the_same_name(): void
    {
        $sampaguita = $this->makeStudent('Santos, Maria L.', section: 'Grade 7 / Sampaguita');
        $rosal = $this->makeStudent('Santos, Maria L.', section: 'Grade 7 / Rosal');

        $csv = <<<'CSV'
        No.,NAME,GRADE,SECTION,"Oct 8, 2025"
        1,"Santos, Maria L.",7,Rosal,P
        CSV;

        $this->withSession($this->coordinatorSession())
            ->post(self::IMPORT_ROUTE, ['attendance_file' => $this->csvFile($csv), 'grade' => 'all'])
            ->assertRedirect();

        $this->assertSame(1, FeedingAttendance::where('student_health_record_id', $rosal->id)->count());
        $this->assertSame(0, FeedingAttendance::where('student_health_record_id', $sampaguita->id)->count());
    }

    #[Test]
    public function unmatched_rows_are_reported_back(): void
    {
        $this->makeStudent('Bautista, Andrei M.');

        $csv = <<<'CSV'
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

        $csv = <<<'CSV'
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

        $csv = <<<'CSV'
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
        $csv = <<<'CSV'
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
        $csv = <<<'CSV'
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
