<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\ClassMasterlistTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The School Nurse and the Clinic Teacher download any class's CLASS
 * MASTERLIST from Forms & Templates, filled from their own school's roster.
 */
class ClinicClassMasterlistTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private Institution $otherSchool;

    private const ROUTES = [
        'school_nurse' => ['dashboard.school-nurse.forms', 'dashboard.school-nurse.class-masterlist', 'dashboard.school-nurse.class-masterlist.sheet'],
        'clinic_teacher' => ['dashboard.clinic-teacher.forms', 'dashboard.clinic-teacher.class-masterlist', 'dashboard.clinic-teacher.class-masterlist.sheet'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
        $this->otherSchool = Institution::create(['name' => 'Other School', 'status' => 'active']);
    }

    /** @return array<string, mixed> */
    private function readerSession(string $role, ?Institution $school = null): array
    {
        $school ??= $this->institution;

        return [
            'active_role' => $role,
            'active_name' => 'Test Reader',
            'active_username' => 'reader.'.$role,
            'active_school_name' => $school->name,
            'active_institution_id' => $school->id,
        ];
    }

    private function learner(string $last, string $first, string $section, string $gender, string $lrn, array $extra = [], ?Institution $school = null): StudentHealthRecord
    {
        $school ??= $this->institution;

        return StudentHealthRecord::create(array_merge([
            'institution_id' => $school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => $last.', '.$first,
            'student_id' => $lrn,
            'school_name' => $school->name,
            'section' => $section,
            'student_details' => ['gender' => $gender, 'last_name' => $last, 'first_name' => $first, 'middle_name' => 'Santos'],
        ], $extra));
    }

    private function adviser(string $name, string $grade, string $section): void
    {
        DB::table('accounts')->insert([
            'name' => $name,
            'username' => 'adviser.'.random_int(1000, 9999),
            'password_hash' => Hash::make('secret123'),
            'role' => 'class_adviser',
            'institution_id' => $this->institution->id,
            'school_name' => 'Test School',
            'assigned_grade_level' => $grade,
            'assigned_section' => $section,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<list<string>> */
    private function rows(string $body): array
    {
        $path = tempnam(sys_get_temp_dir(), 'class-ml-test-').'.xlsx';
        file_put_contents($path, $body);

        $reader = new XlsxReader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(static fn ($value): string => trim((string) $value), $row->toArray());
            }
        }
        $reader->close();
        @unlink($path);

        return $rows;
    }

    #[Test]
    public function both_clinic_roles_are_offered_every_class_on_the_roll(): void
    {
        $this->learner('Reyes', 'Ana', 'Grade 7 / Matiyaga', 'Female', '100000000001');
        // Senior High too: the class masterlist is not an SBFP form.
        $this->learner('Gomez', 'Jose', 'Grade 11 / Rizal', 'Male', '100000000002');
        $this->learner('Lopez', 'Rina', 'Grade 9 / Hidden', 'Female', '100000000003', [], $this->otherSchool);

        foreach (self::ROUTES as $role => [$page, , $sheet]) {
            $html = $this->withSession($this->readerSession($role))->get(route($page))->assertOk()->getContent();

            // The picker, the panel its sheet opens in, and this role's own sheet path.
            $this->assertStringContainsString('id="classMasterlistSelect"', $html, $role);
            $this->assertStringContainsString('id="classMasterlistPanel" data-template="class-masterlist"', $html, $role);
            $this->assertStringContainsString(json_encode(route($sheet)), $html, $role);
            $this->assertStringContainsString('<option value="Grade 7 / Matiyaga">Grade 7 - Matiyaga</option>', $html, $role);
            $this->assertStringContainsString('<option value="Grade 11 / Rizal">Grade 11 - Rizal</option>', $html, $role);
            $this->assertStringNotContainsString('Grade 9 / Hidden', $html, "{$role} must not see another school's class.");
        }
    }

    #[Test]
    public function the_download_lists_the_class_by_band_with_its_adviser(): void
    {
        $this->adviser('Maria Dela Cruz', 'Grade 7', 'Matiyaga');
        $this->learner('Zamora', 'Carlo', 'Grade 7 / Matiyaga', 'Male', '100000000011');
        $this->learner('Abad', 'Ben', 'Grade 7 / Matiyaga', 'M', '100000000012');
        $this->learner('Reyes', 'Ana', 'Grade 7 / Matiyaga', 'Female', '100000000013');
        $this->learner('Cruz', 'Lia', 'Grade 7 / Matiyaga', '', '100000000014');
        // Not on this class's list: archived, another class, another school.
        $this->learner('Archived', 'Kid', 'Grade 7 / Matiyaga', 'Male', '100000000015', ['archived_at' => now()]);
        $this->learner('Other', 'Class', 'Grade 7 / Masipag', 'Male', '100000000016');
        $this->learner('Other', 'School', 'Grade 7 / Matiyaga', 'Male', '100000000017', [], $this->otherSchool);

        foreach (self::ROUTES as $role => [, $download]) {
            $response = $this->withSession($this->readerSession($role))
                ->get(route($download, ['class' => 'Grade 7 / Matiyaga']))
                ->assertOk()
                ->assertDownload('CLASS-MASTERLIST-GRADE-7-MATIYAGA-'.StudentHealthRecord::currentSchoolYear().'.xlsx');

            $rows = $this->rows($response->getFile()->getContent());
            $firsts = array_column($rows, 0);

            $this->assertContains(ClassMasterlistTemplate::TITLE, $firsts);
            $this->assertContains('GRADE 7 - MATIYAGA', $firsts);
            $this->assertContains('MARIA DELA CRUZ', $firsts);

            $male = array_search('MALE', $firsts, true);
            $female = array_search('FEMALE', $firsts, true);
            $unrecorded = array_search(ClassMasterlistTemplate::UNRECORDED_BAND, $firsts, true);

            // Boys under MALE in name order, girls under FEMALE, nobody dropped.
            $this->assertSame(['1', 'Abad', 'Ben', 'Santos', '100000000012', ''], array_pad($rows[$male + 1], 6, ''));
            $this->assertSame(['2', 'Zamora', 'Carlo', 'Santos', '100000000011', ''], array_pad($rows[$male + 2], 6, ''));
            $this->assertSame($female, $male + 3);
            $this->assertSame(['1', 'Reyes', 'Ana', 'Santos', '100000000013', ''], array_pad($rows[$female + 1], 6, ''));
            $this->assertSame('Cruz', $rows[$unrecorded + 1][1]);

            $flat = json_encode($rows);
            $this->assertStringNotContainsString('100000000015', $flat, 'An archived learner is not on the list.');
            $this->assertStringNotContainsString('100000000016', $flat);
            $this->assertStringNotContainsString('100000000017', $flat);
        }

        $this->assertTrue(AuditLog::query()->where('action', 'downloaded')->exists(), 'The download is on the audit trail.');
    }

    #[Test]
    public function a_class_the_school_does_not_hold_is_refused(): void
    {
        $this->learner('Lopez', 'Rina', 'Grade 9 / Hidden', 'Female', '100000000021', [], $this->otherSchool);

        foreach (self::ROUTES as $role => [$page, $download]) {
            foreach (['Grade 9 / Hidden', '', 'Grade 7'] as $class) {
                $this->withSession($this->readerSession($role))
                    ->from(route($page))
                    ->get(route($download, ['class' => $class]))
                    ->assertRedirect(route($page))
                    ->assertSessionHas('error');
            }
        }
    }

    #[Test]
    public function the_sheet_shows_the_chosen_class_as_the_workbook_lays_it_out(): void
    {
        $this->adviser('Maria Dela Cruz', 'Grade 7', 'Matiyaga');
        $this->learner('Abad', 'Ben', 'Grade 7 / Matiyaga', 'Male', '100000000041');
        $this->learner('Reyes', 'Ana', 'Grade 7 / Matiyaga', 'Female', '100000000042');
        $this->learner('Archived', 'Kid', 'Grade 7 / Matiyaga', 'Male', '100000000043', ['archived_at' => now()]);
        $this->learner('Other', 'Class', 'Grade 7 / Masipag', 'Male', '100000000044');

        foreach (self::ROUTES as $role => [, $download, $sheet]) {
            $html = $this->withSession($this->readerSession($role))
                ->get(route($sheet, ['class' => 'Grade 7 / Matiyaga']))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString('class="cm-table"', $html, $role);
            // The heading every form template on the page opens under.
            $this->assertStringContainsString('class="lh-block"', $html, $role);
            $this->assertStringContainsString('<div class="report-title-lines">'.ClassMasterlistTemplate::TITLE.'</div>', $html);
            $this->assertStringContainsString('<div class="report-scope">GRADE 7 - MATIYAGA</div>', $html);
            $this->assertStringContainsString('<div class="report-school">Test School</div>', $html);
            $this->assertStringContainsString(ClassMasterlistTemplate::TITLE, $html);
            $this->assertStringContainsString('GRADE 7 - MATIYAGA', $html);
            $this->assertStringContainsString('MARIA DELA CRUZ', $html);
            $this->assertStringContainsString('100000000041', $html);
            $this->assertStringContainsString('100000000042', $html);
            $this->assertStringNotContainsString('100000000043', $html, 'An archived learner is not on the sheet.');
            $this->assertStringNotContainsString('100000000044', $html);

            // MALE then FEMALE, each learner under their own band.
            $this->assertLessThan(strpos($html, 'FEMALE'), strpos($html, '100000000041'));
            $this->assertLessThan(strpos($html, '100000000042'), strpos($html, 'FEMALE'));

            // Its Download Excel is this class's workbook, under this role's path.
            $this->assertStringContainsString('href="'.e(route($download, ['class' => 'Grade 7 / Matiyaga'])).'"', $html);
            $this->assertStringContainsString('data-print-class-masterlist', $html);
        }
    }

    #[Test]
    public function the_sheet_refuses_a_class_the_school_does_not_hold_and_other_roles(): void
    {
        $this->learner('Reyes', 'Ana', 'Grade 7 / Matiyaga', 'Female', '100000000051');
        $this->learner('Lopez', 'Rina', 'Grade 9 / Hidden', 'Female', '100000000052', [], $this->otherSchool);

        foreach (self::ROUTES as $role => [, , $sheet]) {
            foreach (['Grade 9 / Hidden', '', 'Grade 7'] as $class) {
                $this->withSession($this->readerSession($role))
                    ->get(route($sheet, ['class' => $class]))
                    ->assertNotFound();
            }
        }

        foreach (['school_head', 'class_adviser', 'feeding_coor', 'clinic_staff'] as $role) {
            $this->withSession($this->readerSession($role))
                ->get(route('dashboard.school-nurse.class-masterlist.sheet', ['class' => 'Grade 7 / Matiyaga']))
                ->assertForbidden();
        }
    }

    #[Test]
    public function no_other_role_can_download_it(): void
    {
        $this->learner('Reyes', 'Ana', 'Grade 7 / Matiyaga', 'Female', '100000000031');

        foreach (['school_head', 'class_adviser', 'feeding_coor', 'clinic_staff'] as $role) {
            $this->withSession($this->readerSession($role))
                ->get(route('dashboard.school-nurse.class-masterlist', ['class' => 'Grade 7 / Matiyaga']))
                ->assertRedirect(route('login'));
        }

        // The coordinator's own copy of the forms page carries no such control.
        $this->withSession($this->readerSession('feeding_coor'))
            ->get('/dashboard/feedingcor-sbfp-forms')
            ->assertOk()
            ->assertDontSee('id="classMasterlistSelect"', false)
            ->assertDontSee('id="classMasterlistPanel"', false);
    }
}
