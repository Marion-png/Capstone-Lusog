<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\MasterlistSheet;
use App\Support\NutritionalHealthStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Options as XlsxReaderOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the Nutritional Health Status list's Print list.
 *
 * It downloads the school's masterlist form — the Feeding Coordinator's
 * Export Masterlist layout, written through the one MasterlistSheet — holding
 * the rows the reader had on screen: the query-string filters as the page
 * applies them, and the browser's search and sort as an ordered list of ids
 * that can only choose among rows the page already allowed.
 */
class NutritionalStatusPrintListTest extends TestCase
{
    use RefreshDatabase;

    private const HEAD_PRINT = '/dashboard/school-head/masterlist/print';

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    private function sessionFor(string $role = 'school_head', string $name = 'Principal Reyes'): array
    {
        return [
            'active_role' => $role,
            'active_name' => $name,
            'active_username' => $role.'.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->institution->id,
        ];
    }

    private function makeLearner(array $attributes = [], ?Institution $school = null): StudentHealthRecord
    {
        $school ??= $this->institution;

        return StudentHealthRecord::create(array_merge([
            'institution_id' => $school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Learner '.random_int(1000, 9999),
            'student_id' => (string) random_int(100000000000, 999999999999),
            'school_name' => $school->name,
            'section' => 'Grade 7 / Rizal',
            'weight' => 30,
            'bmi_value' => 15,
            'nutritional_status' => 'Wasted',
            'baseline_nutritional_status' => 'Wasted',
            'student_details' => ['gender' => 'Female'],
        ], $attributes));
    }

    #[Test]
    public function print_list_is_the_coordinators_masterlist_form(): void
    {
        $this->requiresOpenSpout();

        $this->makeLearner([
            'student_name' => 'Maria Clara Santos',
            'student_id' => '109876543201',
            'student_details' => ['gender' => 'Female', 'age' => 12, 'nutritional_status_height_for_age' => 'Stunted'],
            'baseline_weight_kg' => 38, 'baseline_height_cm' => 150,
            'baseline_bmi_value' => 16.9, 'baseline_nutritional_status' => 'Wasted',
            'endline_weight_kg' => 47, 'endline_height_cm' => 155, 'endline_age' => 13,
            'endline_bmi_value' => 19.6, 'endline_nutritional_status' => 'Normal',
        ]);

        $response = $this->withSession($this->sessionFor())->get(self::HEAD_PRINT)
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertMatchesRegularExpression(
            '/filename="?Nutritional-Health-Status-\d{4}-\d{4}-\d{8}\.xlsx/',
            (string) $response->headers->get('content-disposition')
        );

        $rows = $this->workbook($response)['rows'];
        $head = $this->headerIndex($rows);

        $this->assertSame('Test School', $rows[$head - 5][0]);
        $this->assertSame('Nutritional Health Status', $rows[$head - 3][0]);
        $this->assertSame('S.Y. '.StudentHealthRecord::currentSchoolYear(), $rows[$head - 2][0]);

        // The table's own columns, with Grade and Section split and named as
        // the coordinator's form names them.
        $this->assertSame([
            'No.', 'LRN', 'Name', 'Grade', 'Section', 'Gender', 'Age',
            'Baseline Weight (kg)', 'Baseline Height (cm)', 'Baseline BMI', 'Baseline Status', 'Baseline Height-for-Age',
            'Endline Weight (kg)', 'Endline Height (cm)', 'Endline BMI', 'Latest Status', 'Endline Height-for-Age', 'Change',
        ], $rows[$head]);

        $learner = $rows[$head + 1];
        // The LRN stays text: as a number Excel would print it in scientific notation.
        $this->assertSame('109876543201', $learner[1]);
        $this->assertSame(['Maria Clara Santos', '7', 'Rizal', 'Female'], array_slice($learner, 2, 4));
        // Age is the latest one on file (the endline's), as on screen. Baseline
        // height-for-age is the adviser's stored reading; the endline one is
        // computed from the endline height and age.
        $this->assertEquals(
            [13, 38, 150, 16.9, 'Wasted', 'Stunted', 47, 155, 19.6, 'Normal', 'Normal Height-for-Age', 'Improved'],
            array_slice($learner, 6)
        );

        // The form's twenty ruled lines, numbered, the learner on the first.
        foreach (range(1, MasterlistSheet::MIN_ROWS) as $number) {
            $this->assertEquals($number, $rows[$head + $number][0]);
        }

        // Signed by the reader, noted by the principal — the coordinator's block.
        $tail = array_slice($rows, -3);
        $this->assertSame(['Prepared by:', '', 'Noted by:'], $tail[0]);
        $this->assertSame('Principal Reyes', $tail[1][0]);
        $this->assertSame(['School Head', '', 'Principal'], $tail[2]);
    }

    /**
     * One template, not two: the same styles, the same letterhead rows and the
     * same signature block as the coordinator's Export Masterlist.
     */
    #[Test]
    public function print_list_and_the_coordinators_export_share_one_template(): void
    {
        $this->requiresOpenSpout();

        $learner = $this->makeLearner(['feeding_enrolled_at' => now()]);

        $coordinator = $this->workbook(
            $this->withSession($this->sessionFor('feeding_coor', 'Coordinator Cruz'))
                ->post('/dashboard/feedingcor-health-records/masterlist', ['record_ids' => [$learner->id]])
                ->assertOk()
        );
        $printList = $this->workbook($this->withSession($this->sessionFor())->get(self::HEAD_PRINT)->assertOk());

        // Fonts, sizes, borders and alignment are the stylesheet inside the
        // .xlsx; one writer produces the same one.
        $this->assertSame($coordinator['styles'], $printList['styles']);

        $coordinatorHead = $this->headerIndex($coordinator['rows']);
        $printHead = $this->headerIndex($printList['rows']);
        $this->assertSame($coordinatorHead, $printHead, 'The heading block is the same height.');

        // Letterhead, school and address are identical; the title is each list's own.
        $this->assertSame(
            array_slice($coordinator['rows'], 0, $coordinatorHead - 3),
            array_slice($printList['rows'], 0, $printHead - 3)
        );
        $this->assertSame($coordinator['rows'][$coordinatorHead - 2], $printList['rows'][$printHead - 2]);
        $this->assertSame(['No.', 'Name', 'Grade', 'Section'], $coordinator['rows'][$coordinatorHead]);

        $this->assertSame(array_slice($coordinator['rows'], -3, 1), array_slice($printList['rows'], -3, 1));
    }

    #[Test]
    public function print_list_follows_the_filters_on_the_query_string(): void
    {
        $this->requiresOpenSpout();

        $this->makeLearner(['student_name' => 'Seventh Grader', 'section' => 'Grade 7 / Rizal']);
        $this->makeLearner(['student_name' => 'Ninth Grader', 'section' => 'Grade 9 / Luna']);

        $response = $this->withSession($this->sessionFor())
            ->get(self::HEAD_PRINT.'?grade=Grade+7&section=Rizal')
            ->assertOk();

        $this->assertStringContainsString('Nutritional-Health-Status-Grade-7-Rizal-', (string) $response->headers->get('content-disposition'));

        $rows = $this->workbook($response)['rows'];
        $head = $this->headerIndex($rows);

        $this->assertSame(['Seventh Grader'], $this->names($rows));
        // A filed copy says which learners it counted.
        $this->assertSame('S.Y. '.StudentHealthRecord::currentSchoolYear().' · Grade 7 · Rizal', $rows[$head - 2][0]);
    }

    #[Test]
    public function print_list_holds_the_rows_on_screen_in_their_order(): void
    {
        $this->requiresOpenSpout();

        $first = $this->makeLearner(['student_name' => 'Ana Abad']);
        $this->makeLearner(['student_name' => 'Ben Bautista']);
        $third = $this->makeLearner(['student_name' => 'Carla Cruz']);

        $rows = $this->workbook(
            $this->withSession($this->sessionFor())->get(self::HEAD_PRINT.'?ids='.$third->id.','.$first->id)->assertOk()
        )['rows'];

        $this->assertSame(['Carla Cruz', 'Ana Abad'], $this->names($rows));
    }

    /** Ids choose among the rows the page allowed; they never add one. */
    #[Test]
    public function ids_off_the_wire_add_nobody(): void
    {
        $this->requiresOpenSpout();

        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $outsider = $this->makeLearner(['student_name' => 'Outsider Learner'], $other);
        $filteredOut = $this->makeLearner(['student_name' => 'Ninth Grader', 'section' => 'Grade 9 / Luna']);
        $kept = $this->makeLearner(['student_name' => 'Seventh Grader']);

        $rows = $this->workbook(
            $this->withSession($this->sessionFor())
                ->get(self::HEAD_PRINT.'?grade=Grade+7&ids='.implode(',', [$outsider->id, $filteredOut->id, $kept->id]))
                ->assertOk()
        )['rows'];

        $this->assertSame(['Seventh Grader'], $this->names($rows));
    }

    /** A search nobody matched prints the empty form, never the whole school. */
    #[Test]
    public function a_search_nobody_matched_prints_the_empty_form(): void
    {
        $this->requiresOpenSpout();

        $this->makeLearner(['student_name' => 'Ana Abad']);

        $rows = $this->workbook($this->withSession($this->sessionFor())->get(self::HEAD_PRINT.'?ids=')->assertOk())['rows'];
        $head = $this->headerIndex($rows);

        $this->assertSame([], $this->names($rows));
        $this->assertEquals([1], $rows[$head + 1]);
        $this->assertEquals([MasterlistSheet::MIN_ROWS], $rows[$head + MasterlistSheet::MIN_ROWS]);
    }

    #[Test]
    public function the_weighing_shown_decides_the_columns(): void
    {
        $this->requiresOpenSpout();

        $this->makeLearner();

        $baseline = $this->workbook($this->withSession($this->sessionFor())->get(self::HEAD_PRINT.'?weighing=baseline')->assertOk())['rows'];
        $this->assertSame(
            ['No.', 'LRN', 'Name', 'Grade', 'Section', 'Gender', 'Age', 'Baseline Weight (kg)', 'Baseline Height (cm)', 'Baseline BMI', 'Baseline Status', 'Baseline Height-for-Age'],
            $baseline[$this->headerIndex($baseline)]
        );

        $endline = $this->workbook($this->withSession($this->sessionFor())->get(self::HEAD_PRINT.'?weighing=endline')->assertOk())['rows'];
        $this->assertSame(
            ['No.', 'LRN', 'Name', 'Grade', 'Section', 'Gender', 'Age', 'Endline Weight (kg)', 'Endline Height (cm)', 'Endline BMI', 'Latest Status', 'Endline Height-for-Age'],
            $endline[$this->headerIndex($endline)]
        );
    }

    /**
     * Height-for-age sits in each weighing's group, read where the student
     * profile reads it, and a weighing nobody took is a dash — never the
     * other weighing's classification.
     */
    #[Test]
    public function the_list_shows_height_for_age_for_each_weighing(): void
    {
        $measured = $this->makeLearner([
            'student_name' => 'Measured Twice',
            'student_details' => ['gender' => 'Male', 'age' => 12, 'nutritional_status_height_for_age' => 'Severely Stunted'],
            'baseline_height_cm' => 120, 'baseline_weight_kg' => 25,
            'endline_height_cm' => 140, 'endline_weight_kg' => 30, 'endline_age' => 13,
        ]);
        $this->makeLearner([
            'student_name' => 'Baseline Only',
            'student_details' => ['gender' => 'Female', 'age' => 12, 'nutritional_status_height_for_age' => 'Normal Height-for-Age'],
            'baseline_height_cm' => 150, 'baseline_weight_kg' => 38,
        ]);

        $response = $this->withSession($this->sessionFor())->get('/dashboard/school-head/masterlist')->assertOk();
        $rows = collect($response->viewData('rows'))->keyBy('name');

        $this->assertSame('Severely Stunted', $rows['Measured Twice']['baseline_hfa']);
        $this->assertSame('Normal Height-for-Age', $rows['Measured Twice']['endline_hfa']);
        $this->assertSame('Normal Height-for-Age', $rows['Baseline Only']['baseline_hfa']);
        $this->assertSame('', $rows['Baseline Only']['endline_hfa']);

        // The same reading the learner's own Nutritional Health Status tab renders.
        $profile = NutritionalHealthStatus::forRecord($measured->fresh());
        $this->assertSame($profile['baseline']['hfa_status'], $rows['Measured Twice']['baseline_hfa']);
        $this->assertSame($profile['endline']['hfa_status'], $rows['Measured Twice']['endline_hfa']);

        $response
            ->assertSee('<th data-sort="baselineHfa">Height-for-Age</th>', false)
            ->assertSee('<th data-sort="endlineHfa">Height-for-Age</th>', false)
            ->assertSee('<th colspan="5" class="sh-group sh-group-baseline">Baseline weighing</th>', false)
            ->assertSee('<span class="badge badge-critical">Severely Stunted</span>', false);

        // Choosing one weighing drops the other group's column with it.
        $this->withSession($this->sessionFor())->get('/dashboard/school-head/masterlist?weighing=baseline')
            ->assertOk()
            ->assertSee('data-sort="baselineHfa"', false)
            ->assertDontSee('data-sort="endlineHfa"', false);
    }

    /** Export list carries both weighings' height-for-age too. */
    #[Test]
    public function export_list_carries_height_for_age(): void
    {
        $this->requiresOpenSpout();

        $this->makeLearner([
            'student_name' => 'Ana Abad',
            'student_details' => ['gender' => 'Female', 'age' => 12, 'nutritional_status_height_for_age' => 'Stunted'],
            'baseline_height_cm' => 125, 'baseline_weight_kg' => 28,
        ]);

        $rows = $this->workbook(
            $this->withSession($this->sessionFor())->get('/dashboard/school-head/masterlist/export')->assertOk()
        )['rows'];

        $header = collect($rows)->first(fn (array $row): bool => ($row[0] ?? null) === 'NO.');
        $learner = collect($rows)->first(fn (array $row): bool => ($row[2] ?? null) === 'Ana Abad');

        $baselineAt = array_search('BASELINE HEIGHT-FOR-AGE', $header, true);
        $endlineAt = array_search('ENDLINE HEIGHT-FOR-AGE', $header, true);
        $this->assertNotFalse($baselineAt);
        $this->assertSame('Stunted', $learner[$baselineAt]);
        $this->assertSame('—', $learner[$endlineAt]);
    }

    /** The clinic prints the same list under its own path, signed as itself. */
    #[Test]
    public function the_clinic_prints_the_list_under_its_own_path(): void
    {
        $this->requiresOpenSpout();

        $this->makeLearner(['student_name' => 'Ana Abad']);

        foreach ([
            ['school_nurse', '/dashboard/school-nurse/nutritional-status/print', 'School Nurse'],
            ['clinic_teacher', '/dashboard/clinic-teacher/nutritional-status/print', 'Clinic Teacher'],
        ] as [$role, $url, $label]) {
            $rows = $this->workbook($this->withSession($this->sessionFor($role, 'Clinic Reader'))->get($url)->assertOk())['rows'];

            $this->assertSame(['Ana Abad'], $this->names($rows));
            $this->assertSame([$label, '', 'Principal'], $rows[count($rows) - 1]);
        }
    }

    #[Test]
    public function a_role_without_the_list_cannot_print_it(): void
    {
        $this->makeLearner();

        foreach (['class_adviser', 'feeding_coor'] as $role) {
            $this->withSession($this->sessionFor($role))->get(self::HEAD_PRINT)
                ->assertRedirect(route('login'));

            // The page fetches the file, so a refusal comes back as words it
            // can show. These are the headers that fetch sends.
            $this->withSession($this->sessionFor($role))
                ->get(self::HEAD_PRINT, ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => '*/*'])
                ->assertForbidden()
                ->assertJsonPath('message', 'Only the School Head and the school clinic can print the nutritional health status list.');
        }
    }

    /** Print list is the workbook now, linked under the reader's own path. */
    #[Test]
    public function the_page_links_print_list_to_the_workbook(): void
    {
        $this->makeLearner();

        $this->withSession($this->sessionFor())->get('/dashboard/school-head/masterlist?grade=Grade+7')
            ->assertOk()
            ->assertSee('id="shPrint" href="'.route('dashboard.school-head.masterlist.print', ['grade' => 'Grade 7']).'"', false)
            ->assertSee('Print list')
            ->assertDontSee("addEventListener('click', () => window.print())", false);

        $this->withSession($this->sessionFor('school_nurse', 'Nurse Joy'))->get('/dashboard/school-nurse/nutritional-status')
            ->assertOk()
            ->assertSee('href="'.route('dashboard.school-nurse.nutritional-status.print').'"', false);
    }

    private function requiresOpenSpout(): void
    {
        if (! class_exists(XlsxReader::class)) {
            $this->markTestSkipped('OpenSpout is not installed on this machine (composer.lock pins it to PHP 8.4).');
        }
    }

    /**
     * The first sheet's rows, trailing empty cells dropped, and the stylesheet.
     *
     * @return array{rows: list<list<mixed>>, styles: string}
     */
    private function workbook(TestResponse $response): array
    {
        $path = tempnam(sys_get_temp_dir(), 'print-list-test-').'.xlsx';
        file_put_contents($path, $response->getFile()->getContent());

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The download is a real .xlsx archive.');
        $styles = (string) $zip->getFromName('xl/styles.xml');
        $zip->close();

        // Empty rows kept, so row positions are the sheet's own: the spacer
        // line and an unrecorded address are part of the form.
        $reader = new XlsxReader(new XlsxReaderOptions(SHOULD_PRESERVE_EMPTY_ROWS: true));
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = $row->toArray();
                while ($cells !== [] && end($cells) === '') {
                    array_pop($cells);
                }
                $rows[] = $cells;
            }
            break;
        }
        $reader->close();
        @unlink($path);

        return ['rows' => $rows, 'styles' => $styles];
    }

    /** @param  list<list<mixed>>  $rows */
    private function headerIndex(array $rows): int
    {
        foreach ($rows as $index => $row) {
            if (($row[0] ?? null) === 'No.') {
                return $index;
            }
        }

        $this->fail('The form has no ruled header row.');
    }

    /**
     * The learner names in the table, in order — the third column of every
     * numbered row that carries one.
     *
     * @param  list<list<mixed>>  $rows
     * @return list<string>
     */
    private function names(array $rows): array
    {
        $names = [];
        foreach (array_slice($rows, $this->headerIndex($rows) + 1) as $row) {
            if (! is_int($row[0] ?? null) && ! is_float($row[0] ?? null)) {
                break;
            }
            if (($row[2] ?? '') !== '') {
                $names[] = $row[2];
            }
        }

        return $names;
    }
}
