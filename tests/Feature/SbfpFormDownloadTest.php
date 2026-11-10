<?php

namespace Tests\Feature;

use App\Http\Controllers\FeedingMasterlistExportController;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\NarrativeReportPdf;
use App\Support\SchoolLetterhead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * Guards the SBFP Forms page's Download Excel and Download PDF.
 *
 * The file is the form on screen, so four things are asserted:
 *
 * 1. **It is laid out as the form.** The letterhead, the banner, the grid's
 *    two-row head merged as the printed table spans it, the master list ruled
 *    to twenty lines, the signature block.
 * 2. **Its figures and learners are the server's, never the request's.** A
 *    number or a name posted with the form is ignored; the grid is the
 *    page's own BmiAssessmentReport set, scoped to the reader's school.
 * 3. **It follows the page's controls** — weighing, population, grade and
 *    section — and the words typed around the tables.
 * 4. **Each reader downloads under their own path**, and nobody else can.
 */
class SbfpFormDownloadTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    private function readerSession(string $role = 'feeding_coor'): array
    {
        return [
            'active_role' => $role,
            'active_name' => 'Test Reader',
            'active_username' => 'reader.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->institution->id,
        ];
    }

    private function learner(string $name, string $section, string $status = 'Wasted', array $extra = []): StudentHealthRecord
    {
        return StudentHealthRecord::create(array_merge([
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
            'student_details' => [
                'gender' => 'Male',
                'height_cm' => 140,
                'weight_kg' => 30,
                'nutritional_status_height_for_age' => 'Stunted',
            ],
        ], $extra));
    }

    private function download(array $payload, string $role = 'feeding_coor', string $url = '/dashboard/feedingcor-sbfp-forms/download')
    {
        return $this->withSession($this->readerSession($role))->post($url, $payload);
    }

    /** @return array{sheets: array<string, list<list<string>>>, merges: list<string>} */
    private function workbook(string $body): array
    {
        $path = tempnam(sys_get_temp_dir(), 'sbfp-dl-test-').'.xlsx';
        file_put_contents($path, $body);

        $reader = new XlsxReader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(static fn ($value): string => trim((string) $value), $row->toArray());
            }
            $sheets[$sheet->getName()] = $rows;
        }
        $reader->close();

        $zip = new ZipArchive;
        $zip->open($path);
        preg_match_all('/<mergeCell ref="([A-Z0-9:]+)"/', (string) $zip->getFromName('xl/worksheets/sheet1.xml'), $merges);
        $zip->close();
        @unlink($path);

        return ['sheets' => $sheets, 'merges' => $merges[1]];
    }

    private function flatten(array $rows): string
    {
        return implode(' | ', array_map(static fn (array $row): string => implode(' ', $row), $rows));
    }

    private function rowAfter(array $rows, string $title, string $label): ?array
    {
        $seen = false;
        foreach ($rows as $row) {
            if (($row[0] ?? '') === $title) {
                $seen = true;
            }
            if ($seen && ($row[0] ?? '') === $label) {
                return $row;
            }
        }

        return null;
    }

    #[Test]
    public function the_bmi_report_downloads_as_the_form(): void
    {
        $this->learner('Ana Cruz', 'Grade 7 / Rosal');

        $response = $this->download(['template' => 'bmi-report', 'phase' => 'baseline'])
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('BMI-Report-Baseline-', (string) $response->headers->get('content-disposition'));

        $book = $this->workbook($response->streamedContent());
        $this->assertSame(['Baseline BMI'], array_keys($book['sheets']));
        $form = $book['sheets']['Baseline BMI'];
        $text = $this->flatten($form);

        $this->assertSame('Republic of the Philippines', $form[0][0]);
        $this->assertStringContainsString('TEST SCHOOL', $text);
        $this->assertStringContainsString('BASELINE NUTRITIONAL ASSESSMENT (BMI) REPORT', $text);
        $this->assertStringContainsString('GRADE 7 BMI', $text);
        $this->assertStringContainsString('OVERALL BMI', $text);
        $this->assertStringContainsString('INSIGHT', $text);
        $this->assertStringContainsString('PREPARED BY:', $text);

        // The figure the page shows: one wasted, stunted male in Grade 7.
        $male = $this->rowAfter($form, 'GRADE 7 BMI', 'MALE');
        $this->assertSame('1', $male[2]);   // Wasted
        $this->assertSame('1', $male[6]);   // Nutritional status total
        $this->assertSame('1', $male[8]);   // Stunted
        $this->assertSame('0', $male[1]);   // A zero prints as a zero

        // The grid's head is merged as the printed table spans it: Sex down
        // both rows, Nutritional Status over six columns, HFA over five.
        $sexHead = collect($form)->search(fn (array $row): bool => ($row[0] ?? '') === 'SEX') + 1;
        $this->assertContains('A'.$sexHead.':A'.($sexHead + 1), $book['merges']);
        $this->assertContains('B'.$sexHead.':G'.$sexHead, $book['merges']);
        $this->assertContains('H'.$sexHead.':L'.$sexHead, $book['merges']);
    }

    #[Test]
    public function figures_posted_with_the_form_are_ignored(): void
    {
        $this->learner('Ana Cruz', 'Grade 7 / Rosal');

        $form = $this->workbook($this->download([
            'template' => 'bmi-report',
            'fields' => ['bmib_g7_male_w' => '99', 'bmib_prepared_name' => 'Nurse Joy Reyes', 'bmib_school_address' => 'Typed Street, Davao City'],
        ])->assertOk()->streamedContent())['sheets']['Baseline BMI'];

        $this->assertSame('1', $this->rowAfter($form, 'GRADE 7 BMI', 'MALE')[2]);
        $text = $this->flatten($form);
        $this->assertStringNotContainsString('99', $text);
        // The words typed around the grid are the form's own.
        $this->assertStringContainsString('NURSE JOY REYES', $text);
        $this->assertStringContainsString('Typed Street, Davao City', $text);
    }

    #[Test]
    public function both_weighings_download_as_two_sheets(): void
    {
        $this->learner('Ana Cruz', 'Grade 7 / Rosal');

        $sheets = $this->workbook($this->download(['template' => 'bmi-report', 'phase' => 'both'])
            ->assertOk()->streamedContent())['sheets'];

        $this->assertSame(['Baseline BMI', 'Final BMI'], array_keys($sheets));
        $this->assertStringContainsString('FINAL NUTRITIONAL ASSESSMENT (BMI) REPORT', $this->flatten($sheets['Final BMI']));
    }

    /**
     * Every grid carries its chart, as on the form: a native Excel column
     * chart whose MALE / FEMALE / TOTAL series are formulas over that grid's
     * own rows, so the picture and the table cannot disagree.
     */
    #[Test]
    public function every_grid_downloads_with_its_chart(): void
    {
        $this->learner('Ana Cruz', 'Grade 7 / Rosal');

        $body = $this->download(['template' => 'bmi-report', 'phase' => 'both'])->assertOk()->streamedContent();
        $form = $this->workbook($body)['sheets']['Baseline BMI'];
        $this->assertStringContainsString('DATA VISUALIZATION — BASELINE NUTRITIONAL ASSESSMENT', $this->flatten($form));

        $path = tempnam(sys_get_temp_dir(), 'sbfp-chart-test-').'.xlsx';
        file_put_contents($path, $body);
        $zip = new ZipArchive;
        $zip->open($path);

        // Five grids a sheet (Grades 7-10 and Overall), two sheets.
        $types = (string) $zip->getFromName('[Content_Types].xml');
        $this->assertSame(10, substr_count($types, 'drawingml.chart+xml'));
        $this->assertSame(5, substr_count((string) $zip->getFromName('xl/drawings/drawing1.xml'), '<xdr:graphicFrame'));
        $this->assertSame(5, substr_count((string) $zip->getFromName('xl/drawings/drawing2.xml'), '<xdr:graphicFrame'));
        $this->assertStringContainsString('<drawing r:id="rIdDrawing1"/>', (string) $zip->getFromName('xl/worksheets/sheet1.xml'));

        // The Grade 7 chart reads the Grade 7 grid: its column heads and its
        // MALE, FEMALE and TOTAL rows, on this sheet.
        $chart = (string) $zip->getFromName('xl/charts/chart1.xml');
        $headRow = collect($form)->search(fn (array $row): bool => ($row[0] ?? '') === 'SEX') + 2;
        $this->assertStringContainsString('<c:barChart><c:barDir val="col"/><c:grouping val="clustered"/>', $chart);
        $this->assertStringContainsString('GRADE 7 BMI', $chart);
        $this->assertStringContainsString("&apos;Baseline BMI&apos;!\$B\${$headRow}:\$L\${$headRow}", $chart);
        foreach ([1 => 'MALE', 2 => 'FEMALE', 3 => 'TOTAL'] as $offset => $sex) {
            $this->assertSame($sex, $form[$headRow + $offset - 1][0]);
            $this->assertStringContainsString('&apos;Baseline BMI&apos;!$B$'.($headRow + $offset).':$L$'.($headRow + $offset), $chart);
        }
        // The colours the form validated, and a zero printed as nothing.
        foreach (['2F6FB3', 'D0741C', '4B5A52'] as $colour) {
            $this->assertStringContainsString('<a:srgbClr val="'.$colour.'"/>', $chart);
        }
        $this->assertStringContainsString('formatCode="0;;;"', $chart);
        $this->assertStringContainsString(
            'DATA VISUALIZATION — ENDLINE NUTRITIONAL ASSESSMENT',
            $this->flatten($this->workbook($body)['sheets']['Final BMI'])
        );
        $this->assertStringContainsString('&apos;Final BMI&apos;!', (string) $zip->getFromName('xl/charts/chart6.xml'));

        // No grid, chart or signature block is cut across two printed pages.
        $this->assertStringContainsString('<rowBreaks', (string) $zip->getFromName('xl/worksheets/sheet1.xml'));

        $zip->close();
        @unlink($path);
    }

    #[Test]
    public function a_grade_and_section_narrow_the_grid_as_on_the_page(): void
    {
        $this->learner('Ana Cruz', 'Grade 7 / Rosal');
        $this->learner('Ben Diaz', 'Grade 7 / Sampaguita');
        $this->learner('Cara Lim', 'Grade 8 / Ilang');

        $form = $this->workbook($this->download([
            'template' => 'bmi-report', 'grade' => 'Grade 7', 'section' => 'Rosal',
        ])->assertOk()->streamedContent())['sheets']['Baseline BMI'];
        $text = $this->flatten($form);

        // The chosen grade's grid alone, counted over that section alone.
        $this->assertStringContainsString('GRADE 7 BMI', $text);
        $this->assertStringNotContainsString('GRADE 8 BMI', $text);
        $this->assertStringNotContainsString('OVERALL BMI', $text);
        $this->assertStringContainsString('GRADE 7 · ROSAL', $text);
        $this->assertSame('1', $this->rowAfter($form, 'GRADE 7 BMI', 'TOTAL')[6]);

        // A section the grade does not run reads as the whole grade.
        $form = $this->workbook($this->download([
            'template' => 'bmi-report', 'grade' => 'Grade 7', 'section' => 'Ilang',
        ])->assertOk()->streamedContent())['sheets']['Baseline BMI'];
        $this->assertSame('2', $this->rowAfter($form, 'GRADE 7 BMI', 'TOTAL')[6]);
    }

    #[Test]
    public function only_beneficiaries_counts_and_signs_as_the_feeding_programme(): void
    {
        $this->learner('Ana Cruz', 'Grade 7 / Rosal', 'Wasted', ['feeding_enrolled_at' => now()]);
        $this->learner('Ben Diaz', 'Grade 7 / Rosal');

        $form = $this->workbook($this->download(['template' => 'bmi-report', 'population' => 'beneficiaries'])
            ->assertOk()->streamedContent())['sheets']['Baseline BMI'];
        $text = $this->flatten($form);

        $this->assertSame('1', $this->rowAfter($form, 'GRADE 7 BMI', 'TOTAL')[6]);
        $this->assertStringContainsString('ONLY BENEFICIARIES · ALL GRADE LEVELS · ALL SECTIONS', $text);
        $this->assertStringContainsString('Feeding Coordinator', $text);
        $this->assertStringContainsString('School Clinic Nurse/Teacher', $text);
    }

    #[Test]
    public function a_master_list_names_the_enrolled_roll_ruled_to_twenty_lines(): void
    {
        $this->learner('Ana Cruz', 'Grade 7 / Rosal', 'Wasted', ['feeding_enrolled_at' => now()]);
        $this->learner('Ben Diaz', 'Grade 7 / Rosal');

        $response = $this->download([
            'template' => 'feeding-beneficiaries',
            'fields' => ['mlb_noted_name' => 'Principal Typed', 'mlb_school_year' => '2026-2027'],
        ])->assertOk();
        $this->assertStringContainsString('Master-List-of-Beneficiaries-', (string) $response->headers->get('content-disposition'));

        $book = $this->workbook($response->streamedContent());
        $form = $book['sheets']['Beneficiaries'];
        $text = $this->flatten($form);

        $this->assertStringContainsString(mb_strtoupper(FeedingMasterlistExportController::BENEFICIARY_LIST_TITLE), $text);
        $this->assertStringContainsString('Ana Cruz', $text);
        // Qualifying is not enrolment.
        $this->assertStringNotContainsString('Ben Diaz', $text);
        $this->assertStringContainsString('PRINCIPAL TYPED', $text);

        $head = collect($form)->search(fn (array $row): bool => ($row[0] ?? '') === 'NO.');
        $this->assertSame(['NO.', 'NAME', 'GRADE', 'SECTION'], array_slice($form[$head], 0, 4));
        $this->assertSame(['1', 'Ana Cruz', 'Grade 7', 'Rosal'], array_slice($form[$head + 1], 0, 4));
        $this->assertSame('20', $form[$head + 20][0]);
        // Section is one column on paper across two in the sheet.
        $this->assertContains('D'.($head + 2).':E'.($head + 2), $book['merges']);
    }

    #[Test]
    public function the_qualified_recipients_list_carries_its_own_title(): void
    {
        $this->learner('Ana Cruz', 'Grade 7 / Rosal', 'Wasted', ['feeding_enrolled_at' => now()]);

        $text = $this->flatten($this->workbook($this->download(['template' => 'feeding-masterlist'])
            ->assertOk()->streamedContent())['sheets']['Masterlist']);

        $this->assertStringContainsString(mb_strtoupper(FeedingMasterlistExportController::LISTS['beneficiaries'][0]), $text);
        $this->assertStringContainsString('Ana Cruz', $text);
    }

    #[Test]
    public function only_this_schools_learners_reach_the_file(): void
    {
        $this->learner('Ours Learner', 'Grade 7 / Rosal', 'Wasted', ['feeding_enrolled_at' => now()]);
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $this->learner('Theirs Learner', 'Grade 7 / Rosal', 'Wasted', ['feeding_enrolled_at' => now(), 'institution_id' => $other->id]);

        $text = $this->flatten($this->workbook($this->download(['template' => 'feeding-masterlist'])
            ->assertOk()->streamedContent())['sheets']['Masterlist']);

        $this->assertStringContainsString('Ours Learner', $text);
        $this->assertStringNotContainsString('Theirs Learner', $text);
    }

    #[Test]
    public function the_clinic_downloads_under_its_own_paths(): void
    {
        $this->learner('Ana Cruz', 'Grade 7 / Rosal');

        $this->download(['template' => 'bmi-report'], 'school_nurse', '/dashboard/school-nurse/forms/download')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->download(['template' => 'feeding-masterlist'], 'clinic_teacher', '/dashboard/clinic-teacher/forms/download')
            ->assertOk();
    }

    #[Test]
    public function nobody_else_can_download_a_form(): void
    {
        foreach (['class_adviser', 'nutricor', 'clinic_staff'] as $role) {
            $this->download(['template' => 'bmi-report'], $role)->assertRedirect(route('login'));
        }

        // The School Head writes nothing, and this is a POST.
        $this->assertNotSame(200, $this->download(['template' => 'bmi-report'], 'school_head')->status());
        // An unknown template is not a form.
        $this->download(['template' => 'feeding-narrative'])->assertRedirect();
    }

    #[Test]
    public function the_page_offers_excel_on_every_form_and_pdf_on_the_narrative(): void
    {
        $page = $this->withSession($this->readerSession())->get('/dashboard/feedingcor-sbfp-forms')->assertOk();
        $this->assertSame(4, substr_count($page->getContent(), 'data-download-excel>'));
        $page->assertSee('Download PDF');
        $page->assertSee(json_encode(route('dashboard.feedingcor-sbfp-forms.download')), false);

        // The clinic's copy carries no narrative, so no PDF either.
        $nurse = $this->withSession($this->readerSession('school_nurse'))->get('/dashboard/school-nurse/forms')->assertOk();
        $this->assertSame(4, substr_count($nurse->getContent(), 'data-download-excel>'));
        $nurse->assertDontSee('Download PDF');
        $nurse->assertSee(json_encode(route('dashboard.school-nurse.forms.download')), false);
    }

    #[Test]
    public function the_narrative_downloads_as_a_pdf_for_the_coordinator_only(): void
    {
        $response = $this->withSession($this->readerSession())->post('/dashboard/feedingcor-sbfp-forms/narrative-pdf', [
            'fields' => ['narr_introduction' => "First paragraph.\nSecond line.", 'narr_prepared_name' => 'Test Coordinator'],
        ])->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertStringContainsString('SBFP-Narrative-Report-', (string) $response->headers->get('content-disposition'));

        $this->withSession($this->readerSession('school_nurse'))
            ->post('/dashboard/feedingcor-sbfp-forms/narrative-pdf', [])
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function the_narrative_pdf_sets_the_coordinators_own_words(): void
    {
        $html = view('feedingcor-dashboard.narrative-pdf', [
            'letterhead' => SchoolLetterhead::for($this->institution->id, 'Test School'),
            'funder' => 'Typed Funder',
            'sections' => ['introduction' => "Line one\n<b>not markup</b>"],
            'headings' => NarrativeReportPdf::SECTIONS,
            'seals' => ['deped' => null, 'school' => null],
            'preparedName' => 'Test Coordinator',
            'notedName' => '',
            'notedRole' => 'Principal II',
        ])->render();

        $this->assertStringContainsString('Typed Funder', $html);
        $this->assertStringContainsString('Line one<br />', $html);
        $this->assertStringContainsString('&lt;b&gt;not markup&lt;/b&gt;', $html);
        $this->assertStringContainsString('Background and Rationale', $html);
        $this->assertStringContainsString('Principal II', $html);
    }
}
