<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\BmiClassifier;
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

    /** A bad row is skipped and named; the good rows are still enrolled. */
    #[Test]
    public function a_row_that_fails_a_check_is_skipped_and_reported_by_line(): void
    {
        $file = $this->csv([
            '100000000001,Dela Cruz,Juan,,2012-06-15,Davao City,Male,Maria Dela Cruz,123 Mabini St.,09171234567,142,35.5',
            '100000000002,Reyes,Ana,,not-a-date,Davao City,Female,Jose Reyes,45 Rizal Ave,09181234567,138,28',
            '100000000003,Santos,Ben,,2012-01-01,Davao City,Male,,45 Rizal Ave,09181234567,140,30',
            '100000000001,Dela Cruz,Juan,,2012-06-15,Davao City,Male,Maria Dela Cruz,123 Mabini St.,09171234567,142,35.5',
        ]);

        $response = $this->import($file)
            ->assertRedirect(route('dashboard.class-adviser', ['tab' => 'form']));

        $report = session('import_report');
        $this->assertSame(1, $report['created']);
        $this->assertSame(0, $report['updated']);
        $this->assertSame([3, 4, 5], array_column($report['errors'], 'line'));
        $this->assertStringContainsString('appears more than once', $report['errors'][2]['message']);

        $this->assertSame(1, StudentHealthRecord::count());
        $this->assertSame('100000000001', StudentHealthRecord::firstOrFail()->student_id);
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
}
