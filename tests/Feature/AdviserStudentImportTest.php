<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\BmiClassifier;
use App\Support\FeedingBeneficiarySummary;
use App\Support\StudentDataCompleteness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Enrolling a class from a spreadsheet.
 *
 * The whole class in one file instead of one Sheet 1 per learner — and
 * deliberately not a second way of writing a learner: every row is validated
 * by the enrolment form's own rules and written by the same path, so it is
 * classified, scoped to the adviser's class and persisted exactly as a typed
 * enrolment is. A row that fails is skipped and named by its line; the rest
 * are enrolled.
 */
class AdviserStudentImportTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    private function adviserSession(array $overrides = []): array
    {
        return array_merge([
            'active_role' => 'class_adviser',
            'active_name' => 'Test Adviser',
            'active_username' => 'adviser1',
            'active_institution_id' => $this->institution->id,
            'active_school_name' => 'Test School',
            'assigned_school_name' => 'Test School',
            'assigned_grade_level' => 'Grade 7',
            'assigned_section' => 'Sampaguita',
        ], $overrides);
    }

    private function csv(array $rows, ?string $header = null): UploadedFile
    {
        $header ??= 'LRN,Last Name,First Name,Middle Name,Birth Date (YYYY-MM-DD),Birthplace,Gender,Parent/Guardian,Address,Contact No.,Height (cm),Weight (kg)';
        $content = $header."\r\n".implode("\r\n", $rows)."\r\n";

        return UploadedFile::fake()->createWithContent('class-list.csv', $content);
    }

    private function import(UploadedFile $file, array $session = [])
    {
        return $this->withSession($this->adviserSession($session))
            ->post(route('adviser.import'), ['students_file' => $file]);
    }

    /** One file, a class enrolled — classified, scoped and persisted like the form. */
    #[Test]
    public function a_spreadsheet_enrols_every_valid_row(): void
    {
        $file = $this->csv([
            '100000000001,Dela Cruz,Juan,Santos,2012-06-15,Davao City,Male,Maria Dela Cruz,"123 Mabini St., Davao City",09171234567,142,35.5',
            '100000000002,Reyes,Ana,,2012-03-02,Davao City,Female,Jose Reyes,45 Rizal Ave,09181234567,1.38,28',
        ]);

        $this->import($file)
            ->assertRedirect(route('dashboard.class-adviser', ['tab' => 'saved']))
            ->assertSessionHas('import_report', fn (array $r) => $r['created'] === 2 && $r['updated'] === 0 && $r['errors'] === []);

        $this->assertSame(2, StudentHealthRecord::count());

        $juan = StudentHealthRecord::where('student_id', '100000000001')->firstOrFail();
        $this->assertSame('Dela Cruz, Juan S.', $juan->student_name);
        $this->assertSame('Grade 7 / Sampaguita', $juan->section, 'Scoped to the adviser\'s own class.');
        $this->assertSame($this->institution->id, $juan->institution_id);
        $this->assertSame('Maria Dela Cruz', $juan->student_details['parent_guardian']);
        $this->assertSame('123 Mabini St., Davao City', $juan->student_details['address']);
        $this->assertSame(2012, $juan->student_details['birth_year']);
        $this->assertSame(6, $juan->student_details['birth_month']);
        $this->assertSame(15, $juan->student_details['birth_day']);
        // Classified on the way in, by the one classifier the form uses.
        $this->assertSame(BmiClassifier::WASTED, $juan->baseline_nutritional_status);

        // A height typed in metres in the centimetre column is read as metres.
        $ana = StudentHealthRecord::where('student_id', '100000000002')->firstOrFail();
        $this->assertEquals(138, $ana->baseline_height_cm);

        // The roster in the session carries both, so My Students lists them at once.
        $roster = collect(session('school_health_card_records'));
        $this->assertSame(['100000000001', '100000000002'], $roster->pluck('lrn')->sort()->values()->all());

        $this->assertTrue(
            AuditLog::query()->where('action', 'imported')->exists(),
            'An import is on the audit trail.'
        );
    }

    /**
     * A bad row is skipped and named; the good rows are still enrolled.
     *
     * "Bad" means unreadable or contradictory, not incomplete. Since the
     * import accepts a class masterlist — a roster of names and LRNs and
     * nothing else — a blank guardian, address or measurement is data entry
     * still to do rather than a reason to refuse the learner, and the
     * adviser's dashboard already counts those. What still fails is a value
     * the sheet gave that cannot be read (line 3's birth date), and an LRN the
     * sheet gives twice (line 5), which is a contradiction no rule can settle.
     */
    #[Test]
    public function a_row_that_fails_a_check_is_skipped_and_reported_by_line(): void
    {
        $file = $this->csv([
            '100000000001,Dela Cruz,Juan,,2012-06-15,Davao City,Male,Maria Dela Cruz,123 Mabini St.,09171234567,142,35.5',
            '100000000002,Reyes,Ana,,not-a-date,Davao City,Female,Jose Reyes,45 Rizal Ave,09181234567,138,28',
            '100000000003,Santos,Ben,,2012-01-01,Davao City,Male,,45 Rizal Ave,09181234567,140,30',
            '100000000001,Dela Cruz,Juan,,2012-06-15,Davao City,Male,Maria Dela Cruz,123 Mabini St.,09171234567,142,35.5',
        ]);

        $this->import($file)
            ->assertRedirect(route('dashboard.class-adviser', ['tab' => 'form']));

        $report = session('import_report');
        $this->assertSame(2, $report['created']);
        $this->assertSame(0, $report['updated']);
        $this->assertSame([3, 5], array_column($report['errors'], 'line'));
        $this->assertStringContainsString('appears more than once', $report['errors'][1]['message']);

        // Line 4 enrolled without a guardian, and says so rather than being
        // dropped: the learner is on the roll and the field is outstanding.
        $ben = StudentHealthRecord::where('student_id', '100000000003')->firstOrFail();
        $this->assertSame('', trim((string) ($ben->student_details['parent_guardian'] ?? '')));
        $this->assertContains('Parent / guardian', StudentDataCompleteness::missingFor($ben->student_details));

        $this->assertSame(2, StudentHealthRecord::count());
    }

    /** A learner already on the roll is updated, and one in another class is refused. */
    #[Test]
    public function an_existing_learner_is_updated_and_a_colleagues_learner_is_refused(): void
    {
        $mine = StudentHealthRecord::create([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Dela Cruz, Juan',
            'student_id' => '100000000001',
            'school_name' => 'Test School',
            'section' => 'Grade 7 / Sampaguita',
            'weight' => 30,
            'bmi_value' => 15,
            'nutritional_status' => 'Wasted',
            'student_details' => ['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'health_history' => ['med_asthma' => true]],
            'examination' => ['deworming' => 'V'],
        ]);
        StudentHealthRecord::create([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Other, Learner',
            'student_id' => '100000000009',
            'school_name' => 'Test School',
            'section' => 'Grade 8 / Matiyaga',
            'weight' => 30,
            'bmi_value' => 15,
            'nutritional_status' => 'Wasted',
        ]);

        $file = $this->csv([
            '100000000001,Dela Cruz,Juan,,2012-06-15,Davao City,Male,Maria Dela Cruz,New Address,09170000000,150,40',
            '100000000009,Other,Learner,,2011-06-15,Davao City,Male,Guardian,Somewhere,09170000000,150,40',
        ]);

        $this->import($file);

        $report = session('import_report');
        $this->assertSame(0, $report['created']);
        $this->assertSame(1, $report['updated']);
        $this->assertCount(1, $report['errors']);
        $this->assertStringContainsString('another class', $report['errors'][0]['message']);

        $mine->refresh();
        $this->assertSame('New Address', $mine->student_details['address']);
        // What the sheet does not carry is kept, not blanked.
        $this->assertTrue($mine->student_details['health_history']['med_asthma']);
        $this->assertSame(['deworming' => 'V'], $mine->examination, 'The nurse\'s examination is untouched.');

        $this->assertSame(
            'Grade 8 / Matiyaga',
            StudentHealthRecord::where('student_id', '100000000009')->firstOrFail()->section,
            'The colleague\'s learner was not moved.'
        );
    }

    /** No LRN column, no file, or the wrong kind of file: refused before anything is written. */
    #[Test]
    public function a_sheet_without_the_required_columns_is_refused(): void
    {
        $this->import($this->csv(['Juan,Dela Cruz'], 'First Name,Last Name'))
            ->assertRedirect(route('dashboard.class-adviser', ['tab' => 'form']))
            ->assertSessionHas('error');

        $this->withSession($this->adviserSession())
            ->post(route('adviser.import'), ['students_file' => UploadedFile::fake()->create('photo.png', 10, 'image/png')])
            ->assertSessionHasErrors('students_file');

        $this->assertSame(0, StudentHealthRecord::count());
    }

    /** The panel offers the import, the template, and still the single-learner form. */
    #[Test]
    public function the_enrol_panel_offers_the_import_and_the_template(): void
    {
        $this->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser', ['tab' => 'form']))
            ->assertOk()
            ->assertSee('Enroll from a spreadsheet')
            ->assertSee('name="students_file"', false)
            ->assertSee(route('adviser.import'))
            ->assertSee(route('adviser.import.template'))
            ->assertSee('Or enroll one student manually')
            ->assertSee('id="studentForm"', false);

        $this->withSession($this->adviserSession())
            ->get(route('adviser.import.template'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertSee('LRN,Last Name,First Name');
    }

    // ── The school's own class masterlist ────────────────────────────

    /**
     * A DepEd CLASS MASTERLIST, cell for cell.
     *
     * Six rows of letterhead, a header whose three name columns are one merged
     * caption, the list split into MALE and FEMALE bands with the numbering
     * restarting at each, and the adviser's signature underneath. This is the
     * document a school actually holds, and it is not the shape of the
     * template this page offers to download.
     */
    private function masterlist(): UploadedFile
    {
        $caption = "Student's Name (Last Name, First Name Middle Initial)";

        $rows = [
            ['Sta. Ana National High School', '', '', '', '', ''],
            ['D. Suazo St., Davao City', '', '', '', '', ''],
            ['CLASS MASTERLIST', '', '', '', '', ''],
            ['GRADE 7 - MATATAG', '', '', '', '', ''],
            ['MASTERLIST', '', '', '', '', ''],
            ['School Year: 2026 - 2027', '', '', '', '', ''],
            // A merged cell hands its value to its top-left position only.
            ['NO', $caption, '', '', 'LRN', 'REMARKS'],
            ['MALE', '', '', '', '', ''],
            ['1', 'ACALA', 'ZAIREL', 'G.', '129708190295', ''],
            ['2', 'BASTATAS', 'KAILE', 'F.', '129643190096', ''],
            ['FEMALE', '', '', '', '', ''],
            ['1', 'ALFORNON', 'MARIA RAINGIELYN', 'A.', '129697190076', ''],
            // A learner with no middle initial on file.
            ['2', 'DABUAN', 'DESIREE', '', '129513190082', ''],
            ['', '', '', '', '', ''],
            ['BEBERLY REAL PANERIO', '', '', '', '', ''],
            ['ADVISER', '', '', '', '', ''],
        ];

        return $this->sheet($rows);
    }

    /** @param  list<list<string>>  $rows */
    private function sheet(array $rows): UploadedFile
    {
        $csv = implode("\r\n", array_map(
            fn (array $row): string => implode(',', array_map(
                fn (string $cell): string => '"'.str_replace('"', '""', $cell).'"',
                $row
            )),
            $rows
        ))."\r\n";

        return UploadedFile::fake()->createWithContent('MASTERLIST.csv', $csv);
    }

    /**
     * The one sheet every school already has must upload without erroring.
     *
     * Before this it was refused outright — "the spreadsheet has no Last Name,
     * First Name column" — because the three name columns are headed by a
     * single merged caption rather than by three separate headings.
     */
    #[Test]
    public function a_class_masterlist_enrols_its_learners(): void
    {
        $this->import($this->masterlist(), ['assigned_section' => 'MATATAG'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(4, StudentHealthRecord::count());

        $report = session('import_report');
        $this->assertSame(4, $report['created']);
        $this->assertSame([], $report['errors']);

        // The letterhead, the band rows, the spacer and the adviser's own
        // signature are not learners.
        $this->assertNull(
            StudentHealthRecord::query()->get()->first(
                fn (StudentHealthRecord $r): bool => str_contains((string) $r->student_name, 'PANERIO')
            )
        );
    }

    /**
     * The bands are where the sheet records sex — it has no column for it, and
     * every DepEd BMI grid counts by it.
     */
    #[Test]
    public function the_male_and_female_bands_give_each_learner_their_sex(): void
    {
        $this->import($this->masterlist(), ['assigned_section' => 'MATATAG']);

        $sexes = StudentHealthRecord::query()->get()->mapWithKeys(
            fn (StudentHealthRecord $r): array => [
                (string) $r->student_id => (string) ($r->student_details['gender'] ?? ''),
            ]
        );

        $this->assertSame('Male', $sexes['129708190295']);
        $this->assertSame('Male', $sexes['129643190096']);
        $this->assertSame('Female', $sexes['129697190076']);
        $this->assertSame('Female', $sexes['129513190082']);
    }

    /**
     * A masterlist carries no measurement, and a learner nobody has weighed
     * has no weight — not a zero.
     *
     * A fabricated figure does not stay in the column: it becomes a BMI, then
     * a nutritional status, then a learner the feeding programme qualifies,
     * then a cell in the grid the School Head exports.
     */
    #[Test]
    public function an_unmeasured_learner_is_stored_unmeasured(): void
    {
        $this->import($this->masterlist(), ['assigned_section' => 'MATATAG']);

        $learner = StudentHealthRecord::query()->where('student_id', '129708190295')->firstOrFail();

        $this->assertNull($learner->weight);
        $this->assertNull($learner->bmi_value);
        $this->assertNull($learner->nutritional_status);
        $this->assertNull($learner->baseline_weight_kg);

        // And therefore not eligible for feeding: the programme feeds the
        // wasted, and nobody has established that this learner is. A zero
        // weight would have made them Severely Wasted and fed them on it.
        $this->assertFalse(FeedingBeneficiarySummary::isEligible($learner));
    }

    /** The sheet's own title row decides nothing; the adviser's class does. */
    #[Test]
    public function the_sheets_grade_title_never_overrides_the_advisers_own_class(): void
    {
        // The sheet is headed GRADE 7 - MATATAG; this adviser teaches another.
        $this->import($this->masterlist(), [
            'assigned_grade_level' => 'Grade 8',
            'assigned_section' => 'Sampaguita',
        ])->assertSessionHas('success');

        $sections = StudentHealthRecord::query()->get()->pluck('section')->unique()->values();

        $this->assertSame(['Grade 8 / Sampaguita'], $sections->all());
    }

    /**
     * Relaxing completeness must not relax identity: a row with no LRN is
     * still refused, and reported by the line it sat on.
     */
    #[Test]
    public function a_masterlist_row_with_no_lrn_is_still_refused(): void
    {
        $file = $this->sheet([
            ['NO', "Student's Name (Last Name, First Name Middle Initial)", '', '', 'LRN', 'REMARKS'],
            ['MALE', '', '', '', '', ''],
            ['1', 'ACALA', 'ZAIREL', 'G.', '129708190295', ''],
            ['2', 'NOLRN', 'MISSING', 'X.', '', ''],
        ]);

        $this->import($file, ['assigned_section' => 'MATATAG']);

        $report = session('import_report');

        $this->assertSame(1, $report['created']);
        $this->assertCount(1, $report['errors']);
        $this->assertSame(4, $report['errors'][0]['line']);
    }

    /**
     * The same sheet as a real .xlsx, with a real merged cell.
     *
     * The masterlist a school uploads is a workbook, not a CSV, and its three
     * name columns are genuinely merged — which in the file means the cells
     * for the second and third columns of the span **do not exist at all**.
     * Everything the reader does with that header rests on OpenSpout handing
     * the row back with those positions preserved and empty rather than
     * closing the gap, because a closed gap would slide LRN two columns left
     * and the importer would read every learner's first name as their LRN.
     *
     * So the file is built here by hand, merge and absent cells and all,
     * rather than approximated with blank strings a writer would emit.
     */
    #[Test]
    public function the_masterlist_imports_as_a_real_xlsx_with_a_merged_header(): void
    {
        $path = $this->masterlistWorkbook();

        try {
            $this->import(
                new UploadedFile($path, 'MASTERLIST.xlsx', null, null, true),
                ['assigned_section' => 'MATATAG']
            )->assertSessionHas('success');

            $this->assertSame(3, StudentHealthRecord::count());

            $acala = StudentHealthRecord::query()->where('student_id', '129708190295')->firstOrFail();
            $this->assertSame('ACALA, ZAIREL G.', $acala->student_name);
            $this->assertSame('Male', $acala->student_details['gender']);

            $alfornon = StudentHealthRecord::query()->where('student_id', '129697190076')->firstOrFail();
            $this->assertSame('Female', $alfornon->student_details['gender']);
        } finally {
            @unlink($path);
        }
    }

    /**
     * A minimal workbook laid out like the school's masterlist: B7 carries the
     * name caption and C7/D7 are absent, which is how a merge is stored.
     */
    private function masterlistWorkbook(): string
    {
        $rows = [
            1 => ['A' => 'Sta. Ana National High School'],
            2 => ['A' => 'D. Suazo St., Davao City'],
            3 => ['A' => 'CLASS MASTERLIST'],
            4 => ['A' => 'GRADE 7 - MATATAG'],
            5 => ['A' => 'MASTERLIST'],
            6 => ['A' => 'School Year: 2026 - 2027'],
            7 => ['A' => 'NO', 'B' => "Student's Name (Last Name, First Name Middle Initial)", 'E' => 'LRN', 'F' => 'REMARKS'],
            8 => ['A' => 'MALE'],
            9 => ['A' => '1', 'B' => 'ACALA', 'C' => 'ZAIREL', 'D' => 'G.', 'E' => '129708190295'],
            10 => ['A' => '2', 'B' => 'BASTATAS', 'C' => 'KAILE', 'D' => 'F.', 'E' => '129643190096'],
            11 => ['A' => 'FEMALE'],
            12 => ['A' => '1', 'B' => 'ALFORNON', 'C' => 'MARIA RAINGIELYN', 'D' => 'A.', 'E' => '129697190076'],
            13 => ['A' => 'BEBERLY REAL PANERIO'],
        ];

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach ($rows as $number => $cells) {
            $sheet .= '<row r="'.$number.'">';
            foreach ($cells as $column => $value) {
                $sheet .= '<c r="'.$column.$number.'" t="inlineStr"><is><t xml:space="preserve">'
                    .htmlspecialchars($value, ENT_XML1).'</t></is></c>';
            }
            $sheet .= '</row>';
        }

        $sheet .= '</sheetData><mergeCells count="1"><mergeCell ref="B7:D7"/></mergeCells></worksheet>';

        $path = tempnam(sys_get_temp_dir(), 'ml').'.xlsx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="MASTERLIST" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        return $path;
    }
}
