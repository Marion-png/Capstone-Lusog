<?php

namespace Tests\Feature;

use App\Http\Controllers\MlatExportController;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\MlatWorkbook;
use App\Support\Sheet2Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Mandatory Learner's Health Assessment Tool as a download, in the
 * clinic's own two-sheet layout.
 *
 * The form is built as a model (MlatWorkbook) and written through OpenSpout,
 * so the cells are asserted on the model — every label in its section, every
 * value read from the record, nothing invented for a blank — and the
 * workbook itself is read back where the library is installed.
 */
class MlatExportTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'San Antonio National High School', 'status' => 'active']);
    }

    private function sessionFor(string $role): array
    {
        $base = [
            'active_role' => $role,
            'active_name' => 'Nurse Joy',
            'active_school_name' => 'San Antonio National High School',
            'active_institution_id' => $this->school->id,
        ];

        if ($role === 'class_adviser') {
            $base['assigned_grade_level'] = 'Grade 8';
            $base['assigned_section'] = 'Sampaguita';
        }

        return $base;
    }

    private function learner(array $overrides = []): StudentHealthRecord
    {
        return StudentHealthRecord::create(array_merge([
            'institution_id' => $this->school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_id' => '109876543201',
            'student_name' => 'Penduko, Pedro',
            'school_name' => 'San Antonio National High School',
            'section' => 'Grade 8 / Sampaguita',
            'weight' => 35,
            'bmi_value' => 15.56,
            'nutritional_status' => 'Severely Wasted',
            'baseline_height_cm' => 150,
            'baseline_weight_kg' => 35,
            'baseline_bmi_value' => 15.56,
            'baseline_nutritional_status' => 'Severely Wasted',
            'baseline_recorded_at' => '2026-08-02',
            'endline_height_cm' => 152,
            'endline_weight_kg' => 45,
            'endline_bmi_value' => 19.48,
            'endline_nutritional_status' => 'Normal',
            'endline_recorded_at' => '2026-08-02',
            'examination' => [
                'date_of_examination' => '2026-08-02',
                'examined_by' => 'Nurse Joy / School Nurse',
                'vision_screening' => 'Pass',
                'auditory_screening' => 'Passed Both',
                'skin_scalp' => 'No lesions',
            ],
            'student_details' => [
                'first_name' => 'Pedro', 'last_name' => 'Penduko', 'middle_name' => '',
                'grade_level' => 'Grade 8', 'section' => 'Sampaguita',
                'birth_year' => 2011, 'birth_month' => 8, 'birth_day' => 20, 'age' => 14,
                'gender' => 'Male',
                'temperature_c' => '36.5', 'pulse_bpm' => null, 'blood_pressure' => '110/70',
                'vitals_recorded_by' => 'Nurse Joy', 'vitals_recorded_at' => '2026-08-02 09:00:00',
                'health_history' => [
                    'med_asthma' => true, 'med_allergies' => true, 'allergies_detail' => 'Peanuts',
                    'fam_diabetes' => true,
                    'current_medications' => 'Salbutamol',
                    'consciousness' => 'Alert', 'posture' => 'Good', 'hygiene' => 'Adequate',
                ],
                'systems_review' => [
                    'skin_normal' => true, 'heent_normal' => true, 'resp_clear' => true,
                    'cardio_regular' => true, 'abdo_soft' => true, 'neuro_alert' => true,
                    'dental_good' => true, 'immun_complete' => true,
                    'right_eye' => '20/20', 'left_eye' => '20/20', 'immun_date' => '2026-08-02',
                    'summary' => 'Controlled bronchial asthma.',
                    'recommendations' => 'Keep inhaler accessible during PE classes.',
                    'examiner_name' => 'Nurse Joy, RN', 'examiner_date' => '2026-08-02',
                ],
            ],
        ], $overrides));
    }

    /** @return list<list<string>> */
    private function cells(array $sheet): array
    {
        return array_map(fn (array $row) => $row['cells'], $sheet['rows']);
    }

    /** The row whose first cell starts with the label. */
    private function rowStarting(array $sheet, string $label): array
    {
        foreach ($this->cells($sheet) as $cells) {
            if (str_starts_with($cells[0] ?? '', $label)) {
                return $cells;
            }
        }

        $this->fail("No row starts with '{$label}'.");
    }

    // ── Sheet 1 ─────────────────────────────────────────────────────

    #[Test]
    public function sheet_one_is_laid_out_as_the_clinics_form(): void
    {
        $workbook = MlatWorkbook::build($this->learner(), 'San Antonio National High School');
        $sheet = $workbook['sheets'][0];

        $this->assertSame('Sheet 1', $sheet['name']);
        $this->assertSame('MLAT-Pedro-Penduko.xlsx', $workbook['file_name']);

        // Title and subtitle are full-width bands.
        $this->assertSame([MlatWorkbook::TITLE], $sheet['rows'][0]['cells']);
        $this->assertSame(MlatWorkbook::R_TITLE, $sheet['rows'][0]['register']);
        $this->assertTrue($sheet['rows'][0]['merge']);
        $this->assertSame([MlatWorkbook::SHEET_ONE], $sheet['rows'][1]['cells']);

        // The five lettered sections, in the form's order.
        $bands = array_values(array_map(
            fn (array $row) => $row['cells'][0],
            array_filter($sheet['rows'], fn (array $row) => $row['register'] === MlatWorkbook::R_BAND)
        ));
        $this->assertSame([
            'A. LEARNER INFORMATION',
            'B. MEDICAL HISTORY (Check all that apply)',
            'C. FAMILY HISTORY',
            'D. GENERAL APPEARANCE',
            'E. VITAL SIGNS & ANTHROPOMETRICS (BASELINE & ENDLINE)',
        ], $bands);

        // A. Learner information — labels and values where the form puts them.
        $this->assertSame(['Name of Learner:', 'Pedro Penduko', '', 'Learner ID / Grade & Section:', '109876543201 / Grade 8 - Sampaguita'], $this->rowStarting($sheet, 'Name of Learner'));
        $this->assertSame(['Date of Birth:', '2011-08-20', 'Age: 14', 'School:', 'San Antonio National High School'], $this->rowStarting($sheet, 'Date of Birth'));
        $this->assertSame(['Sex:', '[ ✓ ] Male   [   ] Female   [   ] Other', '', 'Date of Assessment:', '2026-08-02'], $this->rowStarting($sheet, 'Sex:'));
        $this->assertSame(['Assessed by (Name/Title):', 'Nurse Joy / School Nurse'], $this->rowStarting($sheet, 'Assessed by'));

        // B. Medical history — ticked where the record says so, detail beside it.
        $this->assertSame('[ ✓ ] Asthma', $this->rowStarting($sheet, '[ ✓ ] Asthma')[0]);
        $this->assertSame('Allergies: Peanuts', $this->rowStarting($sheet, '[ ✓ ] Asthma')[3]);
        $this->assertSame('[   ] Diabetes', $this->rowStarting($sheet, '[   ] Diabetes')[0]);
        $this->assertSame('Hospitalization/Surgery: None', $this->rowStarting($sheet, '[   ] Frequent Infections')[3]);
        $this->assertSame(['Current Medications: Salbutamol', '', '', 'Other Conditions: None'], $this->rowStarting($sheet, 'Current Medications'));

        // C. Family history on one line, as the form has it.
        $this->assertSame('[   ] Hypertension   [ ✓ ] Diabetes   [   ] Heart Disease   [   ] Cancer   [   ] Mental Health', $this->rowStarting($sheet, '[   ] Hypertension')[0]);
        $this->assertSame('Genetic/Hereditary Disorders: None', $this->rowStarting($sheet, 'Genetic')[0]);

        // D. General appearance.
        $this->assertSame(['Level of Consciousness:', 'Alert'], $this->rowStarting($sheet, 'Level of Consciousness'));
        $this->assertSame(['Posture/Gait:', 'Good'], $this->rowStarting($sheet, 'Posture/Gait'));
        $this->assertSame(['Hygiene/Grooming:', 'Adequate'], $this->rowStarting($sheet, 'Hygiene'));

        // E. The measurements table: head row, then baseline and endline.
        $this->assertSame(
            ['Evaluation Period', 'Height (cm)', 'Weight (kg)', 'BMI & Category', 'Temp (°C)', 'Pulse (bpm)', 'BP (mmHg)', 'Recorded Date'],
            $this->rowStarting($sheet, 'Evaluation Period')
        );
        $this->assertSame(
            ['Baseline Input', '150', '35', '15.56 (Severely Wasted)', '', '', '', '2026-08-02'],
            $this->rowStarting($sheet, 'Baseline Input')
        );
        // The nurse's one reading sits on the period it was taken in — a pulse
        // nobody took is an empty cell, not a number.
        $this->assertSame(
            ['Endline Input', '152', '45', '19.48 (Normal)', '36.5', '', '110/70', '2026-08-02'],
            $this->rowStarting($sheet, 'Endline Input')
        );
    }

    // ── Sheet 2 ─────────────────────────────────────────────────────

    #[Test]
    public function sheet_two_is_laid_out_as_the_clinics_form(): void
    {
        $sheet = MlatWorkbook::build($this->learner())['sheets'][1];

        $this->assertSame('Sheet 2', $sheet['name']);
        $this->assertSame([MlatWorkbook::SHEET_TWO], $sheet['rows'][1]['cells']);

        $bands = array_values(array_map(
            fn (array $row) => $row['cells'][0],
            array_filter($sheet['rows'], fn (array $row) => $row['register'] === MlatWorkbook::R_BAND)
        ));
        $this->assertSame([
            'F. EVALUATION OF BODY SYSTEMS',
            'G. VISION AND HEARING SCREENING',
            'H. ORAL HEALTH EXAMINATION',
            'I. IMMUNIZATION STATUS',
            'J. ASSESSMENT SUMMARY AND RECOMMENDATIONS',
        ], $bands);

        // F. Twelve body systems under the form's own heading row.
        $this->assertSame(['Body System', 'Findings (Check / Status)', 'Notes / Details'], $this->rowStarting($sheet, 'Body System'));
        $systems = ['Integumentary', 'HEENT – Head/Scalp', 'HEENT – Eyes', 'HEENT – Ears', 'HEENT – Nose', 'HEENT – Throat', 'Respiratory', 'Cardiovascular', 'Gastrointestinal', 'Genitourinary', 'Musculoskeletal', 'Neurological'];
        foreach ($systems as $system) {
            $this->rowStarting($sheet, $system);
        }
        $this->assertSame(['Integumentary', 'Normal', 'No lesions'], $this->rowStarting($sheet, 'Integumentary'));
        $this->assertSame(['Genitourinary', '', ''], $this->rowStarting($sheet, 'Genitourinary'), 'Nothing on file prints nothing.');

        // G, H, I, J.
        $this->assertSame(['Vision:', 'Right Eye: 20/20   Left Eye: 20/20', '', 'Result: Pass'], $this->rowStarting($sheet, 'Vision:'));
        $this->assertSame(['Hearing:', 'Result: Passed Both'], $this->rowStarting($sheet, 'Hearing:'));
        $this->assertSame(['Teeth Condition: Good', '', '', 'Last Dental Visit: N/A'], $this->rowStarting($sheet, 'Teeth Condition'));
        $this->assertSame(['Referral: No referral required'], $this->rowStarting($sheet, 'Referral:'));
        $this->assertSame(['Status: Complete', '', 'Missing/Needed Vaccines: None', '', 'Date Record Reviewed: 2026-08-02'], $this->rowStarting($sheet, 'Status:'));
        $this->assertSame(['Summary of Findings:', 'Controlled bronchial asthma.'], $this->rowStarting($sheet, 'Summary of Findings'));
        $this->assertSame(['Recommendations / Referrals:', 'Keep inhaler accessible during PE classes.'], $this->rowStarting($sheet, 'Recommendations'));
        $this->assertSame(['Examiner Signature / Name:', 'Nurse Joy, RN', '', 'Date:', '2026-08-02'], $this->rowStarting($sheet, 'Examiner Signature'));
    }

    /** Once the nurse has filled Sheet 2 on Fill Medical Record, the export writes her sheet. */
    #[Test]
    public function sheet_two_writes_the_nurses_own_sheet_once_filled(): void
    {
        $record = $this->learner();
        $exam = $record->examination;
        $exam[Sheet2Review::KEY] = Sheet2Review::fromInput([
            'systems' => ['respiratory' => ['finding' => 'Abnormal', 'notes' => 'Wheeze on exertion']],
            'vision_right' => '20/30', 'vision_left' => '20/30', 'vision_result' => 'Refer',
            'hearing_result' => 'Passed Both',
            'teeth_condition' => 'Fair', 'last_dental_visit' => '2026-03', 'dental_referral' => 'Referred for dental care',
            'immunization_status' => 'Incomplete', 'missing_vaccines' => 'HPV', 'immunization_reviewed_at' => '2026-08-05',
            'summary_findings' => 'Asthma, poorly controlled.', 'recommendations' => 'Refer to physician.',
            'examiner' => 'Nurse Joy', 'examiner_date' => '2026-08-05',
        ]);
        $record->forceFill(['examination' => $exam])->save();

        $sheet = MlatWorkbook::build($record->fresh())['sheets'][1];

        $this->assertSame(['Respiratory', 'Abnormal', 'Wheeze on exertion'], $this->rowStarting($sheet, 'Respiratory'));
        $this->assertSame(['Integumentary', '', ''], $this->rowStarting($sheet, 'Integumentary'), 'Not back-filled from the adviser once the nurse\'s sheet exists.');
        $this->assertSame(['Vision:', 'Right Eye: 20/30   Left Eye: 20/30', '', 'Result: Refer'], $this->rowStarting($sheet, 'Vision:'));
        $this->assertSame(['Teeth Condition: Fair', '', '', 'Last Dental Visit: 2026-03'], $this->rowStarting($sheet, 'Teeth Condition'));
        $this->assertSame(['Status: Incomplete', '', 'Missing/Needed Vaccines: HPV', '', 'Date Record Reviewed: 2026-08-05'], $this->rowStarting($sheet, 'Status:'));
        $this->assertSame(['Examiner Signature / Name:', 'Nurse Joy', '', 'Date:', '2026-08-05'], $this->rowStarting($sheet, 'Examiner Signature'));
    }

    /** An older record with nothing but the nutrition columns still yields a form. */
    #[Test]
    public function a_bare_record_yields_a_form_with_blanks_not_guesses(): void
    {
        $record = $this->learner(['student_details' => [], 'examination' => [], 'endline_height_cm' => null, 'endline_weight_kg' => null, 'endline_bmi_value' => null, 'endline_nutritional_status' => null, 'endline_recorded_at' => null]);

        $sheet = MlatWorkbook::build($record)['sheets'][0];

        $this->assertSame('Penduko, Pedro', $this->rowStarting($sheet, 'Name of Learner')[1]);
        $this->assertSame('', $this->rowStarting($sheet, 'Date of Birth')[1]);
        $this->assertSame('[   ] Male   [   ] Female   [   ] Other', $this->rowStarting($sheet, 'Sex:')[1]);
        $this->assertSame(['Endline Input', '', '', '', '', '', '', ''], $this->rowStarting($sheet, 'Endline Input'));
        $this->assertSame('Current Medications: None', $this->rowStarting($sheet, 'Current Medications')[0]);
    }

    // ── The download ────────────────────────────────────────────────

    /** Nurse, clinic staff and the learner's own adviser may download; nobody else. */
    #[Test]
    public function the_download_is_scoped_to_the_clinic_and_the_learners_adviser(): void
    {
        $this->learner();

        $this->withSession($this->sessionFor('school_head'))
            ->get(route('student-mlat.download', '109876543201'))
            ->assertForbidden();

        $this->withSession(array_merge($this->sessionFor('class_adviser'), ['assigned_section' => 'Rizal']))
            ->get(route('student-mlat.download', '109876543201'))
            ->assertForbidden();

        $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('student-mlat.download', '999999999999'))
            ->assertNotFound();

        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $this->withSession(array_merge($this->sessionFor('school_nurse'), ['active_institution_id' => $other->id]))
            ->get(route('student-mlat.download', '109876543201'))
            ->assertNotFound();
    }

    /** The workbook, read back: two sheets, the form's bands, the learner's figures. */
    #[Test]
    public function the_workbook_downloads_with_both_sheets(): void
    {
        if (! class_exists(XlsxWriter::class)) {
            $this->markTestSkipped('OpenSpout is not installed on this machine (composer.lock pins it to PHP 8.4).');
        }

        $this->learner();

        foreach (['school_nurse', 'clinic_staff', 'class_adviser'] as $role) {
            $response = $this->withSession($this->sessionFor($role))
                ->get(route('student-mlat.download', '109876543201'))
                ->assertOk()
                ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

            $this->assertStringContainsString('MLAT-Pedro-Penduko.xlsx', (string) $response->headers->get('Content-Disposition'));
        }

        $this->assertTrue(AuditLog::query()->where('action', 'downloaded')->exists(), 'The download is on the audit trail.');

        $response = $this->withSession($this->sessionFor('school_nurse'))->get(route('student-mlat.download', '109876543201'));
        $path = tempnam(sys_get_temp_dir(), 'mlat-read-').'.xlsx';
        file_put_contents($path, $response->getFile()->getContent());

        $reader = new XlsxReader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(fn ($v) => trim((string) $v), $row->toArray());
            }
            $sheets[$sheet->getName()] = $rows;
        }
        $reader->close();
        @unlink($path);

        $this->assertSame(['Sheet 1', 'Sheet 2'], array_keys($sheets));
        $this->assertSame(MlatWorkbook::TITLE, $sheets['Sheet 1'][0][0]);
        $this->assertSame(MlatWorkbook::SHEET_TWO, $sheets['Sheet 2'][1][0]);

        $flat = implode("\n", array_map(fn ($r) => implode('|', $r), $sheets['Sheet 1']));
        $this->assertStringContainsString('Pedro Penduko', $flat);
        $this->assertStringContainsString('15.56 (Severely Wasted)', $flat);
        $this->assertStringContainsString('E. VITAL SIGNS & ANTHROPOMETRICS', $flat);
    }

    /** Both profiles offer Print and Download as a menu of the two sheets. */
    #[Test]
    public function both_profiles_offer_print_and_download_per_sheet(): void
    {
        $this->learner();

        // The nurse's profile is one dialog reused for every learner, so its
        // menus carry the LRN as a placeholder until a profile opens.
        $nurse = $this->withSession(array_merge($this->sessionFor('school_nurse'), ['school_health_card_records' => [[
            'lrn' => '109876543201', 'last_name' => 'Penduko', 'first_name' => 'Pedro', 'grade_level' => 'Grade 8', 'section' => 'Sampaguita',
        ]]]))
            ->get(route('dashboard.student-health-records'))
            ->assertOk()
            ->assertSee('id="profileMlatMenus"', false)
            ->assertSeeInOrder(['Print', 'MLAT Sheet 1', 'MLAT Sheet 2', 'Download', 'MLAT Sheet 1', 'MLAT Sheet 2']);
        foreach ([1, 2] as $sheet) {
            $nurse->assertSee(route('student-mlat.print', ['lrn' => '__LRN__', 'sheet' => $sheet]), false);
            $nurse->assertSee(route('student-mlat.download', ['lrn' => '__LRN__', 'sheet' => $sheet]), false);
        }

        $adviser = $this->withSession(array_merge($this->sessionFor('class_adviser'), ['school_health_card_records' => [[
            'lrn' => '109876543201', 'last_name' => 'Penduko', 'first_name' => 'Pedro', 'grade_level' => 'Grade 8', 'section' => 'Sampaguita',
        ]]]))
            ->get(route('dashboard.class-adviser.student-profile', '109876543201'))
            ->assertOk()
            ->assertSee('id="vpMlatMenus"', false)
            ->assertSeeInOrder(['Print', 'MLAT Sheet 1', 'MLAT Sheet 2', 'Download', 'MLAT Sheet 1', 'MLAT Sheet 2']);
        foreach ([1, 2] as $sheet) {
            $adviser->assertSee(route('student-mlat.print', ['lrn' => '109876543201', 'sheet' => $sheet]), false);
            $adviser->assertSee(route('student-mlat.download', ['lrn' => '109876543201', 'sheet' => $sheet]), false);
        }
    }

    // ── One sheet at a time ─────────────────────────────────────────

    /** Sheet 1's layout is the one the clinic approved: no in-row merges, rules or wrapping were added to it. */
    #[Test]
    public function sheet_one_keeps_its_layout(): void
    {
        $sheet = MlatWorkbook::build($this->learner())['sheets'][0];

        foreach ($sheet['rows'] as $row) {
            $this->assertSame([], $row['merges']);
            $this->assertFalse($row['ruled']);
            $this->assertFalse($row['wrap']);
        }
        $this->assertSame([40, 34, 22, 26, 26, 14, 14, 16], $sheet['widths']);
        $this->assertSame('landscape', $sheet['orientation']);
    }

    /**
     * Sheet 2 in the form's format: the body-systems table ruled and as wide
     * as the form, the notes and the nurse's prose wrapping inside it.
     */
    #[Test]
    public function sheet_two_is_formatted_as_a_form(): void
    {
        $sheet = MlatWorkbook::build($this->learner())['sheets'][1];
        $row = fn (string $label): array => collect($sheet['rows'])->first(fn ($r) => str_starts_with($r['cells'][0] ?? '', $label));

        $this->assertSame('portrait', $sheet['orientation']);
        $this->assertSame([34, 38, 34, 26, 26, 12], $sheet['widths']);

        // F. A ruled table whose notes column runs C to F.
        $head = $row('Body System');
        $this->assertSame(MlatWorkbook::R_HEAD, $head['register']);
        $this->assertTrue($head['ruled']);
        $this->assertSame([[2, 5]], $head['merges']);
        foreach (array_values(Sheet2Review::SYSTEMS) as $system) {
            $this->assertTrue($row($system)['ruled'], $system.' is a line of the ruled table.');
            $this->assertTrue($row($system)['wrap']);
            $this->assertSame([[2, 5]], $row($system)['merges']);
        }

        // I. and J. wrap inside the form's width.
        $this->assertSame([[2, 3], [4, 5]], $row('Status:')['merges']);
        $this->assertSame([[1, 5]], $row('Summary of Findings')['merges']);
        $this->assertTrue($row('Summary of Findings')['wrap']);
        $this->assertSame([[1, 5]], $row('Recommendations')['merges']);
    }

    private function readWorkbook(string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'mlat-read-').'.xlsx';
        file_put_contents($path, $contents);

        $reader = new XlsxReader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(fn ($v) => trim((string) $v), $row->toArray());
            }
            $sheets[$sheet->getName()] = $rows;
        }
        $reader->close();

        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        return [$sheets, $xml];
    }

    /** Each sheet downloads on its own, named for the sheet, in its own columns. */
    #[Test]
    public function each_sheet_downloads_on_its_own(): void
    {
        if (! class_exists(XlsxWriter::class)) {
            $this->markTestSkipped('OpenSpout is not installed on this machine (composer.lock pins it to PHP 8.4).');
        }

        // A summary long enough to need several lines.
        $record = $this->learner();
        $details = $record->student_details;
        $details['systems_review']['summary'] = str_repeat('Controlled bronchial asthma with seasonal flare-ups. ', 6);
        $record->forceFill(['student_details' => $details])->save();

        $one = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('student-mlat.download', ['lrn' => '109876543201', 'sheet' => 1]))
            ->assertOk();
        $this->assertStringContainsString('MLAT-Pedro-Penduko-Sheet-1.xlsx', (string) $one->headers->get('Content-Disposition'));
        [$sheets, $xml] = $this->readWorkbook($one->getFile()->getContent());
        $this->assertSame(['Sheet 1'], array_keys($sheets));
        $this->assertSame(MlatWorkbook::SHEET_ONE, $sheets['Sheet 1'][1][0]);
        $this->assertStringContainsString('<col min="1" max="1" width="40"', $xml);
        $this->assertStringContainsString('orientation="landscape"', $xml);

        $two = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('student-mlat.download', ['lrn' => '109876543201', 'sheet' => 2]))
            ->assertOk();
        $this->assertStringContainsString('MLAT-Pedro-Penduko-Sheet-2.xlsx', (string) $two->headers->get('Content-Disposition'));
        [$sheets, $xml] = $this->readWorkbook($two->getFile()->getContent());
        $this->assertSame(['Sheet 2'], array_keys($sheets));
        $this->assertSame(MlatWorkbook::SHEET_TWO, $sheets['Sheet 2'][1][0]);

        // Its own columns — it used to be written in Sheet 1's.
        $this->assertStringContainsString('<col min="1" max="1" width="34"', $xml);
        $this->assertStringContainsString('<col min="2" max="2" width="38"', $xml);
        $this->assertStringContainsString('orientation="portrait"', $xml);
        // The notes column merged C–F on the heading row (row 5).
        $this->assertStringContainsString('<mergeCell ref="C5:F5"/>', $xml);
        // The summary wraps, and its merged row is tall enough to show it.
        // (The reader skips blank rows, so the row number comes from the model.)
        $summaryRow = collect(MlatWorkbook::build($record->fresh())['sheets'][1]['rows'])
            ->search(fn ($r) => ($r['cells'][0] ?? '') === 'Summary of Findings:') + 1;
        $this->assertSame(1, preg_match('/<row r="'.$summaryRow.'"[^>]* ht="(\d+)/', $xml, $height));
        $this->assertGreaterThan(16, (int) $height[1]);
        $this->assertStringContainsString('<mergeCell ref="B'.$summaryRow.':F'.$summaryRow.'"/>', $xml);

        $this->assertTrue(
            AuditLog::query()->where('action', 'downloaded')->get()->contains(fn ($log) => str_contains((string) $log->description, 'MLAT Sheet 2')),
            'The audit trail says which sheet left the school.'
        );
    }

    #[Test]
    public function an_unknown_sheet_is_not_found(): void
    {
        $this->learner();

        foreach (['3', '0', 'x'] as $sheet) {
            $this->withSession($this->sessionFor('school_nurse'))
                ->get(route('student-mlat.download', ['lrn' => '109876543201', 'sheet' => $sheet]))
                ->assertNotFound();
            $this->withSession($this->sessionFor('school_nurse'))
                ->get(route('student-mlat.print', ['lrn' => '109876543201', 'sheet' => $sheet]))
                ->assertNotFound();
        }

        // Printing is always one sheet.
        $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('student-mlat.print', '109876543201'))
            ->assertNotFound();
    }

    // ── Print ───────────────────────────────────────────────────────

    /** The printed sheet is laid out from the same model as the file, in the same registers. */
    #[Test]
    public function each_sheet_prints_as_the_form(): void
    {
        $this->learner();

        $one = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('student-mlat.print', ['lrn' => '109876543201', 'sheet' => 1]))
            ->assertOk()
            ->assertSee('data-mlat-sheet="MLAT Sheet 1"', false)
            ->assertSee('size: landscape', false)
            ->assertSee(MlatWorkbook::TITLE)
            ->assertSee(MlatWorkbook::SHEET_ONE)
            ->assertSee('E. VITAL SIGNS &amp; ANTHROPOMETRICS', false)
            ->assertSee('Pedro Penduko')
            ->assertSee('15.56 (Severely Wasted)');
        $this->assertSame(8, substr_count($one->getContent(), '<col style='), 'Sheet 1 prints in its eight columns.');

        $two = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('student-mlat.print', ['lrn' => '109876543201', 'sheet' => 2]))
            ->assertOk()
            ->assertSee('data-mlat-sheet="MLAT Sheet 2"', false)
            ->assertSee('size: portrait', false)
            ->assertSee(MlatWorkbook::SHEET_TWO)
            ->assertDontSee(MlatWorkbook::SHEET_ONE)
            ->assertSee('Controlled bronchial asthma.');
        $this->assertSame(6, substr_count($two->getContent(), '<col style='), 'Sheet 2 prints in its six columns.');
        $this->assertStringContainsString('class="r-head is-ruled"', $two->getContent());

        $this->assertTrue(AuditLog::query()->where('action', 'printed')->exists(), 'Printing is on the audit trail.');
    }

    /** Whatever was typed about the child prints as text, and a date never splits across lines. */
    #[Test]
    public function the_printed_sheet_escapes_what_was_typed(): void
    {
        $record = $this->learner();
        $details = $record->student_details;
        $details['systems_review']['summary'] = '<script>alert(1)</script> & <b>bold</b>';
        $record->forceFill(['student_details' => $details])->save();

        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('student-mlat.print', ['lrn' => '109876543201', 'sheet' => 2]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<b>bold</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &lt;b&gt;bold&lt;/b&gt;', $html);
        $this->assertStringContainsString('Date Record Reviewed: <span class="mlat-date">2026-08-02</span>', $html);
    }

    /** Print is guarded exactly as the download is. */
    #[Test]
    public function the_print_is_scoped_to_the_clinic_and_the_learners_adviser(): void
    {
        $this->learner();
        $url = route('student-mlat.print', ['lrn' => '109876543201', 'sheet' => 1]);

        $this->withSession($this->sessionFor('school_head'))->get($url)->assertForbidden();
        $this->withSession(array_merge($this->sessionFor('class_adviser'), ['assigned_section' => 'Rizal']))->get($url)->assertForbidden();
        $this->withSession($this->sessionFor('class_adviser'))->get($url)->assertOk();

        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $this->withSession(array_merge($this->sessionFor('school_nurse'), ['active_institution_id' => $other->id]))
            ->get($url)
            ->assertNotFound();
    }

    /**
     * Where the file has no merge, the printed sheet lets text run on over
     * the empty cells beside it as the spreadsheet does — and keeps every
     * column its own where the form rules a table or shades a heading.
     */
    #[Test]
    public function the_printed_layout_follows_the_spreadsheet(): void
    {
        $workbook = MlatWorkbook::build($this->learner(), 'San Antonio National High School');
        $byFirst = fn (array $rows, string $label): array => collect($rows)->first(fn ($r) => ($r['cells'][0]['text'] ?? '') === $label);

        $one = MlatExportController::printRows($workbook['sheets'][0]);
        $this->assertSame([['text' => MlatWorkbook::TITLE, 'span' => 8]], $one[0]['cells']);
        // "Pedro Penduko" runs on over the empty third column.
        $this->assertSame(
            [['text' => 'Name of Learner:', 'span' => 1], ['text' => 'Pedro Penduko', 'span' => 2], ['text' => 'Learner ID / Grade & Section:', 'span' => 1], ['text' => '109876543201 / Grade 8 - Sampaguita', 'span' => 4]],
            $byFirst($one, 'Name of Learner:')['cells']
        );
        // The measurements heading keeps all eight of its columns.
        $this->assertCount(8, $byFirst($one, 'Evaluation Period')['cells']);

        $two = MlatExportController::printRows($workbook['sheets'][1]);
        $this->assertSame(
            [['text' => 'Genitourinary', 'span' => 1], ['text' => '', 'span' => 1], ['text' => '', 'span' => 4]],
            $byFirst($two, 'Genitourinary')['cells'],
            'A blank line of the ruled table stays three ruled cells.'
        );
        $this->assertSame(
            [['text' => 'Summary of Findings:', 'span' => 1], ['text' => 'Controlled bronchial asthma.', 'span' => 5]],
            $byFirst($two, 'Summary of Findings:')['cells']
        );
    }
}
