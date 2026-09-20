<?php

namespace Tests\Feature;

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

    /** Both profiles offer the download. */
    #[Test]
    public function both_profiles_offer_the_download(): void
    {
        $this->learner();

        $this->withSession(array_merge($this->sessionFor('school_nurse'), ['school_health_card_records' => [[
            'lrn' => '109876543201', 'last_name' => 'Penduko', 'first_name' => 'Pedro', 'grade_level' => 'Grade 8', 'section' => 'Sampaguita',
        ]]]))
            ->get(route('dashboard.student-health-records'))
            ->assertOk()
            ->assertSee('id="profileDownloadMlat"', false)
            ->assertSee('Download MLAT');

        $this->withSession(array_merge($this->sessionFor('class_adviser'), ['school_health_card_records' => [[
            'lrn' => '109876543201', 'last_name' => 'Penduko', 'first_name' => 'Pedro', 'grade_level' => 'Grade 8', 'section' => 'Sampaguita',
        ]]]))
            ->get(route('dashboard.class-adviser.student-profile', '109876543201'))
            ->assertOk()
            ->assertSee(route('student-mlat.download', '109876543201'))
            ->assertSee('Download MLAT');
    }
}
