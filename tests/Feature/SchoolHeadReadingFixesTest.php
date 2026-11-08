<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\BmiAssessmentReport;
use App\Support\SchoolHeadOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The School Head's reading tabs, as testers found them on 8 November 2026:
 *
 * - every tab opened on 2028–2029 because a row had been filed under that
 *   year (#4);
 * - the Nutritional Health Status list printed an endline status, and
 *   "No change", for learners nobody had re-weighed (#7);
 * - the Grade Level Snapshot printed a share of the measured learners beside
 *   a count of the whole roll (#18);
 * - the Clinic Notes card printed today's consultations under its figure (#19);
 * - "Medicines in Stock" counted Low Stock items as in stock (#20);
 * - the DepEd grid left a zero MALE/FEMALE cell blank beside a TOTAL of 0 (#21).
 */
class SchoolHeadReadingFixesTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 11, 9)->setTime(10, 0));
        $this->school = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    private function headSession(): array
    {
        return [
            'active_role' => 'school_head',
            'active_name' => 'Test Head',
            'active_username' => 'head.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->school->id,
        ];
    }

    private function learner(array $extra = []): StudentHealthRecord
    {
        static $n = 0;
        $n++;

        return StudentHealthRecord::create(array_merge([
            'institution_id' => $this->school->id,
            'school_year' => '2026-2027',
            'student_name' => 'Learner '.$n,
            'student_id' => '4000000003'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'school_name' => 'Test School',
            'section' => 'Grade 7 / Rizal',
            'student_details' => ['gender' => 'Male'],
        ], $extra));
    }

    #[Test]
    public function every_tab_opens_on_the_current_school_year_not_the_newest_on_record(): void
    {
        $this->learner();
        $this->learner(['school_year' => '2028-2029']);

        $years = SchoolHeadOverview::schoolYears($this->school->id);
        $this->assertSame(['2028-2029', '2026-2027'], $years, 'A future year is still on the list to choose.');
        $this->assertSame('2026-2027', SchoolHeadOverview::defaultYear($years));

        foreach ([
            '/dashboard/school-head',
            '/dashboard/school-head/health',
            '/dashboard/school-head/consent',
            '/dashboard/school-head/inventory',
            '/dashboard/school-head/reports',
            '/dashboard/school-head/masterlist',
        ] as $url) {
            $this->assertSame(
                '2026-2027',
                $this->withSession($this->headSession())->get($url)->assertOk()->viewData('schoolYear'),
                $url
            );
        }

        // A year asked for by name is still honoured.
        $this->assertSame('2028-2029', $this->withSession($this->headSession())
            ->get('/dashboard/school-head/consent?school_year=2028-2029')->viewData('schoolYear'));
    }

    #[Test]
    public function an_endline_nobody_weighed_reads_not_measured(): void
    {
        // A status stamped on the endline columns with no endline weighing —
        // what the old Fill Medical Record left behind.
        $stamped = $this->learner([
            'baseline_nutritional_status' => 'Severely Wasted',
            'nutritional_status' => 'Severely Wasted',
            'baseline_weight_kg' => 30,
            'baseline_height_cm' => 150,
            'endline_nutritional_status' => 'Severely Wasted',
        ]);
        $plain = $this->learner([
            'baseline_nutritional_status' => 'Normal',
            'nutritional_status' => 'Normal',
            'baseline_weight_kg' => 40,
            'baseline_height_cm' => 150,
        ]);
        $weighed = $this->learner([
            'baseline_nutritional_status' => 'Wasted',
            'nutritional_status' => 'Normal',
            'baseline_weight_kg' => 33,
            'baseline_height_cm' => 150,
            'endline_weight_kg' => 40,
            'endline_height_cm' => 151,
            'endline_bmi_value' => 17.5,
            'endline_nutritional_status' => 'Normal',
        ]);

        $response = $this->withSession($this->headSession())->get('/dashboard/school-head/masterlist')->assertOk();
        $rows = collect($response->viewData('rows'))->keyBy('id');

        foreach ([$stamped, $plain] as $record) {
            $this->assertSame('', $rows[$record->id]['endline'], 'No endline weighing, no endline status.');
            $this->assertSame('Not measured', SchoolHeadOverview::movementLabel($rows[$record->id]['movement']));
        }

        $this->assertSame('Normal', $rows[$weighed->id]['endline']);
        $this->assertSame('Improved', SchoolHeadOverview::movementLabel($rows[$weighed->id]['movement']));

        $html = $response->getContent();
        $this->assertStringNotContainsString('No change', $html);
        $this->assertStringContainsString('data-endline="Not measured"', $html);
    }

    #[Test]
    public function the_grade_snapshot_counts_against_the_learners_measured(): void
    {
        $this->learner(['nutritional_status' => 'Wasted', 'baseline_nutritional_status' => 'Wasted']);
        $this->learner(['nutritional_status' => 'Normal', 'baseline_nutritional_status' => 'Normal']);
        $this->learner();

        $html = $this->withSession($this->headSession())->get('/dashboard/school-head')->assertOk()->getContent();

        $this->assertStringContainsString('1 of 2 measured', $html);
        $this->assertStringNotContainsString('1 / 3', $html);
    }

    #[Test]
    public function the_clinic_notes_card_describes_its_own_figure(): void
    {
        $html = $this->withSession($this->headSession())->get('/dashboard/school-head/health')->assertOk()->getContent();

        $this->assertStringContainsString('Written in S.Y. 2026-2027', $html);
        $this->assertDoesNotMatchRegularExpression('/consultations? today/', $html);
    }

    #[Test]
    public function the_stock_card_says_above_reorder_level(): void
    {
        $html = $this->withSession($this->headSession())->get('/dashboard/school-head/inventory')->assertOk()->getContent();

        $this->assertStringContainsString('Above Reorder Level', $html);
        $this->assertStringNotContainsString('Medicines in Stock', $html);
    }

    #[Test]
    public function every_cell_of_the_grid_prints_a_count_zero_included(): void
    {
        $record = $this->learner([
            'baseline_nutritional_status' => 'Normal',
            'student_details' => ['gender' => 'Male', 'nutritional_status_height_for_age' => 'Normal Height-for-Age'],
        ]);

        $values = BmiAssessmentReport::values(collect([$record]));

        $this->assertSame(1, $values['bmib_g7_male_n']);
        $this->assertSame(0, $values['bmib_g7_male_w'], 'A zero is a count, not a blank.');
        $this->assertSame(0, $values['bmib_g7_female_n']);
        $this->assertSame(0, $values['bmib_g7_female_st']);
        $this->assertSame(0, $values['bmib_g7_total_w']);
    }
}
