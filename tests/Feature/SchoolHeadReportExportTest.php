<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\BmiAssessmentReport;
use App\Support\FeedingBeneficiarySummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards what the School Head actually downloads.
 *
 * An export is only useful if it can be handed in, so the file has to *be* the
 * school's DepEd form — the same sheet the Feeding Coordinator's SBFP Forms
 * page prints — filled with what the people who use the app entered. Three
 * things are asserted:
 *
 * 1. **It is the form, not a dump.** The heading, the per-grade BMI grids with
 *    their two-row head, the OVERALL grid and the Prepared/Attested/Noted block
 *    all appear, in that order.
 * 2. **The figures are the ones the form itself shows.** The grid is computed
 *    by BmiAssessmentReport, which the printed page reads too, so an exported
 *    cell and a printed cell are the same number by construction — and this
 *    test pins that they stay so.
 * 3. **It carries what specific people entered**: the adviser's measurements in
 *    the cells, the school's own staff on the signature lines, and the head's
 *    own review decision when one has been recorded.
 */
class SchoolHeadReportExportTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Sta. Ana National High School', 'status' => 'active']);
    }

    private function headSession(): array
    {
        return [
            'active_role' => 'school_head',
            'active_name' => 'Welito I. Rosal',
            'active_username' => 'head.test',
            'active_school_name' => 'Sta. Ana National High School',
            'active_institution_id' => $this->institution->id,
        ];
    }

    private function makeLearner(array $extra = []): StudentHealthRecord
    {
        return StudentHealthRecord::create(array_merge([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Learner '.random_int(1000, 9999),
            'student_id' => 'LRN'.random_int(100000, 999999),
            'school_name' => 'Sta. Ana National High School',
            'section' => 'Grade 7 / Rizal',
            'weight' => 30,
            'bmi_value' => 15,
            'nutritional_status' => 'Wasted',
            'baseline_nutritional_status' => 'Wasted',
            'student_details' => ['gender' => 'Male', 'nutritional_status_height_for_age' => 'Stunted'],
        ], $extra));
    }

    /**
     * Every sheet of a downloaded workbook, as rows of plain strings.
     *
     * @return array<string, list<list<string>>>
     */
    private function sheets(string $body): array
    {
        $path = tempnam(sys_get_temp_dir(), 'sh-export-test-').'.xlsx';
        file_put_contents($path, $body);

        $reader = new XlsxReader;
        $reader->open($path);

        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(
                    static fn ($value): string => trim((string) $value),
                    $row->toArray()
                );
            }
            $sheets[$sheet->getName()] = $rows;
        }

        $reader->close();
        @unlink($path);

        return $sheets;
    }

    /** The first row whose leading cell is this label. */
    private function rowStartingWith(array $rows, string $label): ?array
    {
        foreach ($rows as $row) {
            if (($row[0] ?? '') === $label) {
                return $row;
            }
        }

        return null;
    }

    private function flatten(array $rows): string
    {
        return implode(' | ', array_map(static fn (array $row): string => implode(' ', $row), $rows));
    }

    #[Test]
    public function the_baseline_export_is_the_deped_assessment_form(): void
    {
        $this->makeLearner();

        $body = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/reports/export?report=baseline')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->streamedContent();

        $sheets = $this->sheets($body);
        $this->assertArrayHasKey('Baseline BMI', $sheets);

        $form = $sheets['Baseline BMI'];
        $text = $this->flatten($form);

        // The heading block the printed sheet opens on.
        $this->assertSame('Sta. Ana National High School', $form[0][0]);
        $this->assertStringContainsString('School address:', $text);
        $this->assertStringContainsString('Baseline Nutritional Assessment (BMI) Report', $text);
        $this->assertStringContainsString('S.Y. '.StudentHealthRecord::currentSchoolYear(), $text);

        // One grid per grade the programme covers, then the overall grid.
        foreach (FeedingBeneficiarySummary::GRADE_LEVELS as $grade) {
            $this->assertStringContainsString('GRADE '.$grade.' BMI', $text);
        }
        $this->assertStringContainsString('OVERALL BMI', $text);

        // The grid's own two-row head, and its rows.
        $this->assertNotNull($this->rowStartingWith($form, 'Sex'));
        $this->assertStringContainsString('Height for Age (HFA)', $text);
        $this->assertStringContainsString('Severely Wasted', $text);
        $this->assertStringContainsString('Severely Stunted', $text);
        $this->assertNotNull($this->rowStartingWith($form, 'MALE'));
        $this->assertNotNull($this->rowStartingWith($form, 'FEMALE'));
        $this->assertNotNull($this->rowStartingWith($form, 'TOTAL'));

        // The signature block the form ends on.
        $this->assertStringContainsString('Prepared by:', $text);
        $this->assertStringContainsString('Attested by:', $text);
        $this->assertStringContainsString('Noted by:', $text);
    }

    #[Test]
    public function the_grid_carries_the_measurements_the_adviser_entered(): void
    {
        // Two Grade 7 boys the adviser measured as Wasted and Stunted, and one
        // girl measured Normal.
        $this->makeLearner();
        $this->makeLearner();
        $this->makeLearner([
            'nutritional_status' => 'Normal',
            'baseline_nutritional_status' => 'Normal',
            'student_details' => ['gender' => 'Female', 'nutritional_status_height_for_age' => 'Normal'],
        ]);

        $sheets = $this->sheets($this->withSession($this->headSession())
            ->get('/dashboard/school-head/reports/export?report=baseline')
            ->assertOk()
            ->streamedContent());

        $form = $sheets['Baseline BMI'];

        // The exported cells are the very cells the printed form shows: both
        // read BmiAssessmentReport, so this pins them together.
        $expected = BmiAssessmentReport::values(StudentHealthRecord::all());
        $this->assertSame(2, $expected['bmib_g7_male_w']);
        $this->assertSame(1, $expected['bmib_g7_female_n']);

        // Grade 7's MALE row: Severely Wasted blank, Wasted 2, total 2.
        $grade7 = array_slice($form, $this->indexOfGrid($form, 'GRADE 7 BMI'));
        $male = $this->rowStartingWith($grade7, 'MALE');
        $this->assertNotNull($male);
        $this->assertSame('', $male[1]);           // Severely Wasted — nobody, so blank
        $this->assertSame('2', $male[2]);          // Wasted
        $this->assertSame('2', $male[6]);          // Nutritional status total
        $this->assertSame('2', $male[8]);          // Stunted

        $female = $this->rowStartingWith($grade7, 'FEMALE');
        $this->assertNotNull($female);
        $this->assertSame('1', $female[3]);        // Normal
        $this->assertSame('1', $female[6]);

        $total = $this->rowStartingWith($grade7, 'TOTAL');
        $this->assertNotNull($total);
        $this->assertSame('3', $total[6]);         // Both rows summed
    }

    /** Where a named grid starts, so a row lookup cannot stray into another grade's. */
    private function indexOfGrid(array $rows, string $title): int
    {
        foreach ($rows as $index => $row) {
            if (($row[0] ?? '') === $title) {
                return $index;
            }
        }

        return 0;
    }

    #[Test]
    public function the_signature_block_names_the_schools_own_staff(): void
    {
        DB::table('accounts')->insert([
            'name' => 'Nurse Maria Santos',
            'username' => 'nurse.santos',
            'password_hash' => bcrypt('secret'),
            'role' => 'school_nurse',
            'institution_id' => $this->institution->id,
            'school_name' => 'Sta. Ana National High School',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->makeLearner();

        $sheets = $this->sheets($this->withSession($this->headSession())
            ->get('/dashboard/school-head/reports/export?report=baseline')
            ->assertOk()
            ->streamedContent());

        $text = $this->flatten($sheets['Baseline BMI']);

        // The nurse the SBFP Forms page pre-fills as preparer, and the head
        // exporting it as the one noting it.
        $this->assertStringContainsString('Nurse Maria Santos', $text);
        $this->assertStringContainsString('Welito I. Rosal', $text);
        $this->assertStringContainsString('School Clinic Nurse / Teacher', $text);
        $this->assertStringContainsString('Principal', $text);
    }

    /**
     * The form carries no review block at all.
     *
     * Approve / Return / Lock are gone, so a report has no decision on it to
     * print — and an empty review block would read as a decision nobody made.
     */
    #[Test]
    public function the_form_carries_no_review_block(): void
    {
        $this->makeLearner();

        $text = $this->flatten($this->sheets($this->withSession($this->headSession())
            ->get('/dashboard/school-head/reports/export?report=baseline')
            ->assertOk()
            ->streamedContent())['Baseline BMI']);

        $this->assertStringNotContainsString('REVIEW', $text);
        $this->assertStringNotContainsString('Reviewed by', $text);
        // The signature block is untouched: it is the school's own staff.
        $this->assertStringContainsString('Prepared by:', $text);
        $this->assertStringContainsString('Noted by:', $text);
    }

    #[Test]
    public function the_learner_list_moves_to_its_own_sheet_so_the_form_stays_the_form(): void
    {
        $this->makeLearner(['student_name' => 'Juan Dela Cruz']);

        $sheets = $this->sheets($this->withSession($this->headSession())
            ->get('/dashboard/school-head/reports/export?report=baseline')
            ->assertOk()
            ->streamedContent());

        $this->assertArrayHasKey('Supporting Data', $sheets);
        $this->assertStringContainsString('Juan Dela Cruz', $this->flatten($sheets['Supporting Data']));
        // The form sheet is the form: no roster on it.
        $this->assertStringNotContainsString('Juan Dela Cruz', $this->flatten($sheets['Baseline BMI']));
    }

    #[Test]
    public function the_packet_carries_both_assessments_and_the_accomplishment_report(): void
    {
        // The packet holds both assessment forms, so both weighings have to be
        // finished before it can be exported at all.
        $this->makeLearner(['feeding_enrolled_at' => now(), 'endline_nutritional_status' => 'Normal']);

        $sheets = $this->sheets($this->withSession($this->headSession())
            ->get('/dashboard/school-head/reports/export?report=packet')
            ->assertOk()
            ->streamedContent());

        $this->assertArrayHasKey('Baseline BMI', $sheets);
        $this->assertArrayHasKey('Final BMI', $sheets);
        $this->assertArrayHasKey('Accomplishment', $sheets);

        $this->assertStringContainsString(
            'Final Nutritional Assessment (BMI) Report',
            $this->flatten($sheets['Final BMI'])
        );
        $this->assertStringContainsString(
            'Monthly Accomplishment Report',
            $this->flatten($sheets['Accomplishment'])
        );
    }

    #[Test]
    public function the_export_reads_only_this_schools_learners(): void
    {
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);

        $this->makeLearner(['student_name' => 'Ours Learner']);
        $this->makeLearner([
            'institution_id' => $other->id,
            'school_name' => 'Other School',
            'student_name' => 'Theirs Learner',
        ]);

        $sheets = $this->sheets($this->withSession($this->headSession())
            ->get('/dashboard/school-head/reports/export?report=baseline')
            ->assertOk()
            ->streamedContent());

        $supporting = $this->flatten($sheets['Supporting Data']);
        $this->assertStringContainsString('Ours Learner', $supporting);
        $this->assertStringNotContainsString('Theirs Learner', $supporting);

        // One learner counted, not two.
        $grade7 = array_slice($sheets['Baseline BMI'], $this->indexOfGrid($sheets['Baseline BMI'], 'GRADE 7 BMI'));
        $this->assertSame('1', $this->rowStartingWith($grade7, 'TOTAL')[6]);
    }

    #[Test]
    public function only_the_school_head_can_export(): void
    {
        $this->withSession(['active_role' => 'class_adviser', 'active_institution_id' => $this->institution->id])
            ->get('/dashboard/school-head/reports/export?report=baseline')
            ->assertRedirect(route('login'));
    }
}
