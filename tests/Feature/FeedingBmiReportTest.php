<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\BmiAssessmentReport;
use App\Support\FeedingBeneficiarySummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the auto-filled SBFP BMI reports: adviser-entered learners are
 * tabulated (grade x sex x nutritional-status / height-for-age) into the
 * Baseline (bmib_*) and Final (bmif_*) grids the coordinator prints.
 */
class FeedingBmiReportTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    private function coordinatorSession(): array
    {
        return [
            'active_role' => 'feeding_coor',
            'active_name' => 'Test Coordinator',
            'active_username' => 'feedcor.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->institution->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeStudent(string $section, string $gender, string $baselineStatus, string $hfa, array $attributes = []): StudentHealthRecord
    {
        return StudentHealthRecord::create(array_merge([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Learner '.random_int(1000, 9999),
            'student_id' => 'LRN'.random_int(100000, 999999),
            'school_name' => 'Test School',
            'section' => $section,
            'weight' => 30,
            'bmi_value' => 15.0,
            'nutritional_status' => $baselineStatus,
            'baseline_nutritional_status' => $baselineStatus,
            'student_details' => [
                'gender' => $gender,
                'nutritional_status_height_for_age' => $hfa,
            ],
        ], $attributes));
    }

    private function bmiValues(): array
    {
        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-sbfp-forms');
        $response->assertOk();

        return $response->viewData('bmiValues');
    }

    #[Test]
    public function baseline_report_counts_by_grade_sex_and_status(): void
    {
        $this->makeStudent('Grade 7 / Sampaguita', 'Male', 'Severely Wasted', 'Stunted');
        $this->makeStudent('Grade 7 / Sampaguita', 'Male', 'Normal', 'Severely Stunted');
        $this->makeStudent('Grade 7 / Rosal', 'Female', 'Wasted', 'Normal Height-for-Age');
        $this->makeStudent('Grade 8 / Ilang', 'Female', 'Overweight', 'Normal Height-for-Age');

        $v = $this->bmiValues();

        // Nutritional status placement.
        $this->assertSame(1, $v['bmib_g7_male_sw']);
        $this->assertSame(1, $v['bmib_g7_male_n']);
        $this->assertSame(1, $v['bmib_g7_female_w']);
        $this->assertSame(1, $v['bmib_g8_female_ow']);

        // Height-for-age placement.
        $this->assertSame(1, $v['bmib_g7_male_st']);
        $this->assertSame(1, $v['bmib_g7_male_ss']);

        // Row / column / TOTAL-row rollups.
        $this->assertSame(2, $v['bmib_g7_male_nst']);   // sw + normal
        $this->assertSame(1, $v['bmib_g7_female_nst']);
        $this->assertSame(3, $v['bmib_g7_total_nst']);  // 2 male + 1 female
        $this->assertSame(1, $v['bmib_g7_total_sw']);

        // Overall = sum across grades (3 in G7 + 1 in G8).
        $this->assertSame(4, $v['bmib_overall_total_nst']);

        // Empty data cells render blank (not 0) so the sheet reads like the blank form.
        $this->assertSame('', $v['bmib_g7_female_sw']);
        $this->assertSame(0, $v['bmib_g7_total_ob']);   // TOTAL row still shows an integer
    }

    #[Test]
    public function senior_high_is_outside_the_programme_and_off_the_report(): void
    {
        // The programme covers Junior High. A Senior High learner is neither a
        // beneficiary nor a row on the DepEd grids, and neither is a grade that
        // does not exist — the report cannot print a grid it has no column for.
        $this->makeStudent('Grade 11 / Humss', 'Male', 'Wasted', 'Stunted');
        $this->makeStudent('Grade 12 / Stem', 'Female', 'Normal', 'Normal Height-for-Age');
        $this->makeStudent('Grade 13 / Ghost', 'Male', 'Wasted', 'Stunted');
        // One Junior High learner, so the Overall figure has something true in it.
        $this->makeStudent('Grade 10 / Narra', 'Male', 'Wasted', 'Stunted');

        $v = $this->bmiValues();

        $this->assertArrayNotHasKey('bmib_g11_male_w', $v);
        $this->assertArrayNotHasKey('bmib_g12_female_n', $v);
        $this->assertSame(1, $v['bmib_g10_male_w']);
        $this->assertSame(1, $v['bmib_overall_total_nst'], 'Only the Junior High learner is counted.');
    }

    #[Test]
    public function both_reports_render_a_grid_for_every_junior_high_grade(): void
    {
        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-sbfp-forms');
        $response->assertOk();

        foreach (FeedingBeneficiarySummary::GRADE_LEVELS as $grade) {
            $response->assertSee('GRADE '.$grade.' BMI', false);
            // One cell from each phase is enough to prove the grid was built.
            $response->assertSee('bmib_g'.$grade.'_male_sw', false);
            $response->assertSee('bmif_g'.$grade.'_male_sw', false);
        }

        // And no Senior High grid at all.
        foreach ([11, 12] as $grade) {
            $response->assertDontSee('GRADE '.$grade.' BMI', false);
            $response->assertDontSee('bmib_g'.$grade.'_male_sw', false);
        }
    }

    /**
     * The grids were Junior High only, but the page around them was not.
     *
     * The Grade Level control offered whatever grades happened to be on the
     * roll, so a coordinator could pick Grade 11 and auto-fill a Masterlist of
     * Qualified Recipients for learners the programme cannot feed — a form that
     * looks official and names children who are not beneficiaries. Every form on
     * this page is an SBFP form, so the whole page is narrowed to the covered
     * grades, not just the tables that already dropped them.
     */
    #[Test]
    public function the_grade_level_control_offers_only_the_grades_the_programme_covers(): void
    {
        $this->makeStudent('Grade 7 / Matiyaga', 'Male', 'Wasted', 'Stunted');
        $this->makeStudent('Grade 10 / Narra', 'Female', 'Severely Wasted', 'Stunted');
        $this->makeStudent('Grade 11 / Humss', 'Male', 'Wasted', 'Stunted');
        $this->makeStudent('Grade 12 / Stem', 'Female', 'Underweight', 'Stunted');
        $this->makeStudent('Grade 6 / Sampaguita', 'Male', 'Wasted', 'Stunted');
        $this->makeStudent('', 'Male', 'Wasted', 'Stunted');

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-sbfp-forms');
        $response->assertOk();

        $this->assertSame(['Grade 7', 'Grade 10'], $response->viewData('gradeOptions'));

        // And no form on the page can be auto-filled with one of them.
        $this->assertSame(
            ['Grade 7', 'Grade 10'],
            array_keys($response->viewData('studentsByGrade'))
        );
    }

    #[Test]
    public function a_senior_high_learner_never_reaches_the_masterlist_autofill(): void
    {
        $this->makeStudent('Grade 11 / Humss', 'Male', 'Severely Wasted', 'Stunted');

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-sbfp-forms');

        $response->assertOk();
        // Qualifying on status is not enough — the grade decides too.
        $this->assertSame([], $response->viewData('studentsByGrade'));
        $response->assertDontSee('Grade 11', false);
    }

    /** A label typed by hand is a copy of the range that stops changing with it. */
    #[Test]
    public function the_bmi_report_options_name_the_range_they_actually_print(): void
    {
        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-sbfp-forms');

        $response->assertOk();
        $response->assertSee(FeedingBeneficiarySummary::gradeRangeLabel(), false);
        $response->assertSee('Grades 7-10', false);
        $response->assertDontSee('Grades 7-12', false);
    }

    #[Test]
    public function underweight_is_grouped_under_wasted(): void
    {
        // The adviser classifier emits "Underweight", which the DepEd sheet lacks.
        $this->makeStudent('Grade 9 / Adelfa', 'Male', 'Underweight', 'Normal Height-for-Age');

        $v = $this->bmiValues();

        $this->assertSame(1, $v['bmib_g9_male_w']);
        $this->assertSame(1, $v['bmib_g9_male_nst']);
    }

    #[Test]
    public function final_report_only_counts_learners_with_endline_data(): void
    {
        // Baseline only — must not appear in the Final grid.
        $this->makeStudent('Grade 7 / Sampaguita', 'Male', 'Wasted', 'Stunted');

        // Endline recorded — appears in the Final grid; HFA recomputed from
        // endline height/age (age 13 => normal height >= 135cm; 140cm is Normal).
        $this->makeStudent('Grade 9 / Adelfa', 'Male', 'Wasted', 'Stunted', [
            'endline_nutritional_status' => 'Normal',
            'endline_height_cm' => 140,
            'endline_age' => 13,
        ]);

        $v = $this->bmiValues();

        // Final grid picks up only the endline learner.
        $this->assertSame(1, $v['bmif_g9_male_n']);
        $this->assertSame(1, $v['bmif_g9_male_hn']);
        $this->assertSame(1, $v['bmif_g9_male_nst']);
        $this->assertSame(1, $v['bmif_overall_total_nst']);

        // The baseline-only learner contributes nothing to the Final grid...
        $this->assertSame(0, $v['bmif_g7_total_nst']);
        // ...but still shows in the Baseline grid.
        $this->assertSame(1, $v['bmib_g7_male_w']);
    }

    #[Test]
    public function reports_prefill_prepared_by_with_the_same_schools_nurse(): void
    {
        DB::table('accounts')->insert([
            'name' => 'Jacqueline L. Tenizo',
            'username' => 'nurse.thisschool',
            'role' => 'school_nurse',
            'institution_id' => $this->institution->id,
            'school_name' => 'Test School',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // A nurse at a different school must never be pulled in.
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        DB::table('accounts')->insert([
            'name' => 'Wrong School Nurse',
            'username' => 'nurse.other',
            'role' => 'school_nurse',
            'institution_id' => $other->id,
            'school_name' => 'Other School',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-sbfp-forms');
        $response->assertOk();

        $this->assertSame('Jacqueline L. Tenizo', $response->viewData('nurseName'));
        $response->assertSee('value="Jacqueline L. Tenizo"', false);
        $response->assertDontSee('Wrong School Nurse');
    }

    /**
     * Every form on this page opens under the DepEd heading, and every form
     * reads it from one place — four copies of a letterhead are four chances
     * for the same school to be headed four different ways.
     */
    #[Test]
    public function every_form_opens_under_the_deped_letterhead(): void
    {
        $this->institution->update(['address' => 'Damaso Suazo St., Brgy. 28-C, Davao City']);

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-sbfp-forms');

        $response->assertOk();
        $response->assertSee('Republic of the Philippines');
        $response->assertSee('Department of Education');
        $response->assertSee('Region XI');
        $response->assertSee('Schools Division of Davao City');
        // The school's own address, from institutions.address.
        $response->assertSee('value="Damaso Suazo St., Brgy. 28-C, Davao City"', false);

        // Two seal slots per form — drawn as placeholders until the school
        // drops its files in, so the heading keeps its shape either way.
        $letterhead = $response->viewData('letterhead');
        $this->assertSame('Test School', $letterhead['school']);
        $this->assertArrayHasKey('deped', $response->viewData('seals'));
        $this->assertArrayHasKey('school', $response->viewData('seals'));
    }

    /**
     * A school with no address on file gets an empty line to write on. Printing
     * a neighbouring school's street onto a submitted government form is the
     * error the letterhead exists to avoid.
     */
    #[Test]
    public function a_school_with_no_address_prints_a_blank_line(): void
    {
        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-sbfp-forms');

        $response->assertOk();
        $this->assertSame('', $response->viewData('letterhead')['address']);
    }

    /**
     * The grid counted again per section, so choosing one narrows the report to
     * the learners in it — and it is the same computation the grade grid uses,
     * so a section and its grade can never disagree about the same children.
     */
    #[Test]
    public function the_grid_is_counted_per_section_as_well_as_per_grade(): void
    {
        $this->makeStudent('Grade 7 / Matiyaga', 'Male', 'Wasted', 'Stunted');
        $this->makeStudent('Grade 7 / Matiyaga', 'Female', 'Severely Wasted', 'Stunted');
        $this->makeStudent('Grade 7 / Masigla', 'Male', 'Normal', 'Normal Height-for-Age');

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-sbfp-forms');
        $response->assertOk();

        $sets = $response->viewData('bmiValueSets');

        // The whole school.
        $this->assertSame(3, $sets['']['bmib_g7_total_nst']);

        // One section on its own.
        $this->assertArrayHasKey('Grade 7 / Matiyaga', $sets);
        $this->assertSame(2, $sets['Grade 7 / Matiyaga']['bmib_g7_total_nst']);
        $this->assertSame(1, $sets['Grade 7 / Matiyaga']['bmib_g7_total_sw']);
        $this->assertSame(1, $sets['Grade 7 / Masigla']['bmib_g7_total_n']);

        // And the control offers each grade its own sections, never the whole
        // school's list — a section belongs to one grade.
        $this->assertSame(['Masigla', 'Matiyaga'], $response->viewData('sectionsByGrade')['Grade 7']);
    }

    /**
     * Each grid gets a clustered column chart under it, at the foot of the
     * form — and it prints with the form, because that is what it is for.
     */
    #[Test]
    public function each_grid_carries_a_bar_chart_at_the_foot_of_the_form(): void
    {
        $this->makeStudent('Grade 7 / Matiyaga', 'Male', 'Wasted', 'Stunted');
        $this->makeStudent('Grade 7 / Matiyaga', 'Female', 'Normal', 'Normal Height-for-Age');

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-sbfp-forms');

        $response->assertOk();
        $response->assertSee('Data Visualization &mdash; Baseline Nutritional Assessment', false);
        $response->assertSee('Data Visualization &mdash; Endline Nutritional Assessment', false);

        // One chart per grid, keyed to the grid it pictures, so the grade
        // filter moves a chart and its table together.
        foreach (FeedingBeneficiarySummary::GRADE_LEVELS as $grade) {
            $response->assertSee('data-chart-grade="g'.$grade.'"', false);
        }
        $response->assertSee('data-chart-grade="overall"', false);

        // Three series and eleven columns — the grid's own rows and columns.
        foreach (['male', 'female', 'total'] as $sex) {
            $response->assertSee('data-chart-col="'.$sex.'"', false);
        }
        foreach (array_keys(BmiAssessmentReport::chartColumns()) as $column) {
            $response->assertSee('data-chart-group="'.$column.'"', false);
        }

        // Identity is never colour-alone, and the value is on the column.
        $response->assertSee('chart-legend', false);
        $response->assertSee('MALE');
        $response->assertSee('FEMALE');
        $response->assertSee('TOTAL');

        // Server-rendered heights, so the printed copy is right without JS.
        $response->assertSee('class="chart-col is-male" data-chart-col="male"', false);

        // The chart is part of the form now: print no longer drops it.
        $response->assertDontSee('.bmi-viz { display: none !important; }', false);
    }

    /**
     * Every gridline has to be a whole multiple of one step, or the axis is
     * labelled 5, 4, 3, 1, 0 — four gaps of three different sizes, which is
     * worse than drawing no axis at all. The same stepping is mirrored in the
     * page's repaint script.
     */
    #[Test]
    public function the_chart_axis_steps_to_even_gridlines(): void
    {
        $scale = fn (int $peak): array => BmiAssessmentReport::axisScale($peak);

        // A chart of nothing still draws a baseline to sit on.
        $this->assertSame([1, 0], $scale(0)['ticks']);
        $this->assertSame([4, 3, 2, 1, 0], $scale(4)['ticks']);
        $this->assertSame([8, 6, 4, 2, 0], $scale(7)['ticks']);
        $this->assertSame([15, 10, 5, 0], $scale(11)['ticks']);
        $this->assertSame([200, 150, 100, 50, 0], $scale(180)['ticks']);
        $this->assertSame([2000, 1500, 1000, 500, 0], $scale(1700)['ticks']);

        foreach ([0, 1, 3, 4, 7, 11, 26, 99, 180, 412, 1700, 9001] as $peak) {
            $axis = $scale($peak);
            $this->assertGreaterThanOrEqual($peak, $axis['max'], "axis tops out below the tallest column ($peak)");
            $this->assertLessThanOrEqual(5, count($axis['ticks']), "too many gridlines for $peak");

            // Even gaps, all the way down.
            $gaps = [];
            for ($i = 1; $i < count($axis['ticks']); $i++) {
                $gaps[] = $axis['ticks'][$i - 1] - $axis['ticks'][$i];
            }
            $this->assertSame([$axis['step']], array_values(array_unique($gaps)), "uneven gridlines for $peak");
        }
    }

    /**
     * Which weighing the grid reports is a filter, so a coordinator who wants
     * both does not have to open the page twice.
     */
    #[Test]
    public function the_nutritional_report_filter_offers_baseline_endline_and_both(): void
    {
        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-sbfp-forms');

        $response->assertOk();
        $response->assertSee('Nutritional Report');
        $response->assertSee('<option value="baseline">Baseline</option>', false);
        $response->assertSee('<option value="endline">Endline</option>', false);
        $response->assertSee('<option value="both">Both Baseline and Endline</option>', false);

        // Both panels are always in the document; the filter only decides which
        // is on screen, so a chart and a grid can never be built from a
        // different reading than the one beside it.
        $response->assertSee('id="bmiBaselinePanel"', false);
        $response->assertSee('id="bmiFinalPanel"', false);
    }
}
