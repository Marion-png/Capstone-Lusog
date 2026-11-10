<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;
use App\Support\SchoolYear;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Testers' report #4 (9 November 2026): Consent upload showed S.Y.
 * 2027–2028, and Consent Compliance and Health Reports opened on 2028–2029.
 *
 * The current school year is today's on the school's clock (Asia/Manila),
 * with DepEd's year starting in June — never the newest year on record and
 * never the year after. Every screen opens on it through one helper
 * (App\Support\SchoolYear), and no dropdown offers a year that has not begun
 * unless something is filed under it.
 */
class CurrentSchoolYearTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-09 10:00:00', 'Asia/Manila'));
        $this->school = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    private function learner(string $lrn, string $year, string $section = 'Grade 7 / RIZAL'): StudentHealthRecord
    {
        [$grade, $name] = array_map('trim', explode('/', $section));

        return StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'school_year' => $year,
            'student_name' => 'Dela Cruz, Juan',
            'student_id' => $lrn,
            'school_name' => 'Test School',
            'section' => $section,
            'student_details' => ['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'grade_level' => $grade, 'section' => $name, 'gender' => 'Male'],
        ]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function moments(): array
    {
        return [
            'the day testers reported it' => ['2026-11-09 10:00:00 Asia/Manila', '2026-2027'],
            'January belongs to the year that began in June' => ['2027-01-15 09:00:00 Asia/Manila', '2026-2027'],
            'the last evening of May' => ['2027-05-31 23:30:00 Asia/Manila', '2026-2027'],
            // Still 31 May on the server's UTC clock, already June in Davao.
            'the first hour of June in Manila' => ['2027-05-31 16:30:00 UTC', '2027-2028'],
            'June' => ['2027-06-01 08:00:00 Asia/Manila', '2027-2028'],
        ];
    }

    #[Test]
    #[DataProvider('moments')]
    public function the_current_school_year_is_todays_on_the_schools_clock(string $moment, string $year): void
    {
        $this->travelTo(Carbon::parse($moment));

        $this->assertSame($year, SchoolYear::calendar());
        $this->assertSame($year, ParentalConsentForm::calendarSchoolYear());
        $this->assertSame($year, StudentHealthRecord::currentSchoolYear());
    }

    /** Rows filed under a later year do not move the current one. */
    #[Test]
    public function rows_under_a_later_year_do_not_change_the_current_one(): void
    {
        $this->learner('400000000001', '2028-2029');

        $this->assertSame('2026-2027', SchoolYear::current());
        $this->assertTrue(SchoolYear::isFuture('2027-2028'));
        $this->assertFalse(SchoolYear::isFuture('2026-2027'));
        $this->assertFalse(SchoolYear::isFuture('2025-2026'));
    }

    /**
     * The three screens in the report open on the same year with rows filed
     * under 2027–2028 and 2028–2029: the adviser's Consent Forms page and its
     * upload dialog, the head's Consent Compliance, and Health Reports.
     */
    #[Test]
    public function consent_upload_compliance_and_reports_all_open_on_the_current_year(): void
    {
        $this->learner('400000000001', '2026-2027');
        $this->learner('400000000001', '2027-2028', 'Grade 8 / RIZAL');
        $this->learner('400000000001', '2028-2029', 'Grade 9 / RIZAL');

        $adviser = $this->withSession([
            'active_role' => 'class_adviser',
            'active_name' => 'Teacher Cruz',
            'active_username' => 'teacher.cruz',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->school->id,
            'assigned_grade_level' => 'Grade 7',
            'assigned_section' => 'RIZAL',
        ])->get(route('consent-forms.index'))->assertOk();

        $this->assertSame('2026-2027', $adviser->viewData('schoolYear'));
        $adviser->assertSee('value="2026-2027 (Current)"', false);
        $adviser->assertDontSee('value="2027-2028 (Current)"', false);

        $head = [
            'active_role' => 'school_head',
            'active_name' => 'Test Head',
            'active_username' => 'head.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->school->id,
        ];

        foreach (['dashboard.school-head.consent', 'dashboard.school-head.reports'] as $route) {
            $this->assertSame(
                '2026-2027',
                $this->withSession($head)->get(route($route))->assertOk()->viewData('schoolYear'),
                $route,
            );
        }
    }

    /**
     * On the deployed service the testing aid is off, so a teacher whose
     * account still carries a year they chose while it was on is back on the
     * calendar's year, upload dialog and all.
     */
    #[Test]
    public function a_year_chosen_with_the_testing_aid_is_ignored_once_it_is_off(): void
    {
        config(['app.school_year_testing' => false]);
        $this->learner('400000000001', '2026-2027');

        $response = $this->withSession([
            'active_role' => 'class_adviser',
            'active_name' => 'Teacher Cruz',
            'active_username' => 'teacher.cruz',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->school->id,
            'assigned_grade_level' => 'Grade 7',
            'assigned_section' => 'RIZAL',
            'assigned_school_year' => '2027-2028',
        ])->get(route('consent-forms.index'))->assertOk();

        $this->assertSame('2026-2027', $response->viewData('schoolYear'));
        $response->assertSee('value="2026-2027 (Current)"', false);
    }

    /**
     * A year that has not begun is offered only once something is filed under
     * it; asked for by name before then, the page reads the current year.
     */
    #[Test]
    public function a_dropdown_never_offers_a_future_year_with_nothing_in_it(): void
    {
        $this->assertSame(['2026-2027'], SchoolYear::options([]));
        $this->assertSame(['2026-2027', '2025-2026'], SchoolYear::options(['2025-2026', '', 'not a year']));
        $this->assertSame(['2028-2029', '2026-2027'], SchoolYear::options(['2028-2029']));
        $this->assertSame('2026-2027', SchoolYear::pick('2030-2031', SchoolYear::options([])));
        $this->assertSame('2025-2026', SchoolYear::pick('2025-2026', SchoolYear::options(['2025-2026'])));

        $this->learner('400000000001', '2026-2027');
        $this->learner('400000000002', '2025-2026');

        $coordinator = [
            'active_role' => 'feeding_coor',
            'active_name' => 'Test Coordinator',
            'active_username' => 'coor.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->school->id,
        ];

        foreach (['dashboard.feedingcor-dashboard', 'dashboard.feedingcor-health-records'] as $route) {
            $response = $this->withSession($coordinator)->get(route($route, ['school_year' => '2030-2031']))->assertOk();

            $this->assertSame('2026-2027', $response->viewData('filters')['school_year'], $route);
            $this->assertSame(['2026-2027', '2025-2026'], $response->viewData('filterOptions')['school_years'], $route);
        }

        $head = [
            'active_role' => 'school_head',
            'active_name' => 'Test Head',
            'active_username' => 'head.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->school->id,
        ];
        $consent = $this->withSession($head)->get(route('dashboard.school-head.consent', ['school_year' => '2030-2031']))->assertOk();
        $this->assertSame('2026-2027', $consent->viewData('schoolYear'));
        $this->assertSame(['2026-2027', '2025-2026'], $consent->viewData('schoolYears'));
    }
}
