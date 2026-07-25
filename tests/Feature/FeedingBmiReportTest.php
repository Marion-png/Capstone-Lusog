<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
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
}
