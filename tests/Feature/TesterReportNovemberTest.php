<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Institution;
use App\Models\Medicine;
use App\Models\StudentHealthRecord;
use App\Support\BmiAssessmentReport;
use App\Support\BmiReportInsight;
use App\Support\HealthHistory;
use App\Support\NutritionalHealthStatus;
use App\Support\SchoolClock;
use App\Support\SchoolHeadOverview;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Testers' report, November 2026: #7 (an endline nobody weighed), #8 (health
 * history boxes), #9 (height-for-age read two ways), #10 (UTC times), and the
 * data check that lists and repairs what those left behind.
 */
class TesterReportNovemberTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-09 10:34:00', 'Asia/Manila'));
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    /** @param  array<string, mixed>  $extra */
    private function learner(array $extra = []): StudentHealthRecord
    {
        static $n = 0;
        $n++;

        return StudentHealthRecord::create(array_merge([
            'institution_id' => $this->school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Learner '.$n,
            'student_id' => '70000000020'.$n,
            'school_name' => 'Sta. Ana NHS',
            'section' => 'Grade 9 / Rizal',
            'weight' => 38, 'bmi_value' => 16.2, 'nutritional_status' => 'Wasted',
            'baseline_age' => 15, 'baseline_height_cm' => 153, 'baseline_weight_kg' => 38,
            'baseline_bmi_value' => 16.2, 'baseline_nutritional_status' => 'Wasted',
            'baseline_recorded_at' => '2026-07-01',
            'student_details' => ['gender' => 'Male', 'birth_year' => 2011, 'birth_month' => 5, 'birth_day' => 2],
        ], $extra));
    }

    // ── #7 ─────────────────────────────────────────────────────────────

    /**
     * A status stamped on the endline with no weighing behind it reads "Not
     * measured" everywhere, and no report counts it.
     */
    #[Test]
    public function an_endline_status_with_no_weighing_is_not_measured_anywhere(): void
    {
        $stamped = $this->learner(['endline_nutritional_status' => 'Wasted', 'endline_bmi_value' => 16.2]);

        $this->assertNotNull($stamped->getRawOriginal('endline_nutritional_status'), 'The stamp is still on the row.');
        $this->assertFalse($stamped->hasEndlineMeasurement());
        $this->assertNull($stamped->endline_nutritional_status);
        $this->assertNull($stamped->endline_bmi_value);

        $growth = NutritionalHealthStatus::forRecord($stamped);
        $this->assertFalse($growth['endline']['measured']);
        $this->assertSame('', $growth['endline']['bmi_status']);

        $overview = SchoolHeadOverview::for($this->school->id, StudentHealthRecord::currentSchoolYear());
        $this->assertSame(1, $overview->statusCounts('endline')[SchoolHeadOverview::NOT_MEASURED]);
        $this->assertSame(0, $overview->statusCounts('endline')['Wasted']);
        $this->assertSame('Not measured', SchoolHeadOverview::movementLabel(SchoolHeadOverview::movement('Wasted', '')));

        $values = BmiAssessmentReport::values(collect([$stamped]));
        $this->assertSame(0, $values['bmif_overall_total_nst'] ?? 0, 'The Final BMI grid counts nobody.');

        // Weighed, it counts.
        $stamped->update(['endline_height_cm' => 155, 'endline_weight_kg' => 44, 'endline_recorded_at' => '2027-03-01']);
        $this->assertSame('Wasted', $stamped->fresh()->endline_nutritional_status);
    }

    /** The feeding roster says "Not measured" for a learner nobody re-weighed. */
    #[Test]
    public function the_feeding_roster_reads_not_measured_not_no_change(): void
    {
        $this->learner(['feeding_enrolled_at' => now()->subMonth()]);

        $page = $this->withSession([
            'active_role' => 'school_nurse',
            'active_name' => 'Nurse',
            'active_institution_id' => $this->school->id,
            'active_school_name' => 'Sta. Ana NHS',
        ])->get(route('dashboard.school-nurse.feeding-program'))->assertOk();

        $row = $page->viewData('attendanceRoster')->first();
        $this->assertNull($row['current_weight']);
        $this->assertNull($row['weight_change']);
        $page->assertSee('Not measured');
        $page->assertDontSee('>No change<', false);
    }

    // ── #9 ─────────────────────────────────────────────────────────────

    /**
     * A stored height-for-age that disagrees with the height on file (here
     * "Normal" beside 153 cm for a boy of 15) no longer splits the report:
     * the grid, its Insight and the learner list all read the height.
     */
    #[Test]
    public function the_baseline_report_and_its_list_read_height_for_age_one_way(): void
    {
        $learner = $this->learner(['student_details' => [
            'gender' => 'Male', 'birth_year' => 2011, 'birth_month' => 5, 'birth_day' => 2,
            'nutritional_status_height_for_age' => 'Normal Height-for-Age',
        ]]);

        $list = NutritionalHealthStatus::forRecord($learner)['baseline']['hfa_status'];
        $this->assertSame('Stunted', $list);

        $values = BmiAssessmentReport::values(collect([$learner]));
        $this->assertSame(1, $values['bmib_g9_male_st']);
        $this->assertSame(0, $values['bmib_g9_male_hn']);

        $insight = implode(' ', BmiReportInsight::sentences($values, 'baseline', 'overall', 'All Grade Levels'));
        $this->assertStringContainsString('stunted', $insight);
    }

    // ── #8 ─────────────────────────────────────────────────────────────

    /**
     * Every box on Sheet 1's history survives the save and reads back
     * ticked; a detail written under an unticked box ticks it; "wala" does
     * not.
     */
    #[Test]
    public function health_history_boxes_are_saved_and_a_written_detail_ticks_its_box(): void
    {
        $session = [
            'active_role' => 'class_adviser', 'active_name' => 'Adviser', 'active_username' => 'adviser1',
            'active_institution_id' => $this->school->id, 'active_school_name' => 'Sta. Ana NHS',
            'assigned_grade_level' => 'Grade 7', 'assigned_section' => 'Rizal',
        ];
        $flags = ['fam_hypertension', 'fam_diabetes', 'fam_heart', 'fam_cancer', 'fam_mental'];

        $this->withSession($session)->post(route('adviser.store'), [
            'last_name' => 'Dela Cruz', 'first_name' => 'Ana', 'lrn' => '123456789012',
            'birth_date' => '2013-08-22', 'birthplace' => 'Davao', 'parent_guardian' => 'Maria',
            'address' => '123 St', 'telephone_no' => '09171234567', 'gender' => 'Female',
            'height_m' => 1.50, 'weight_kg' => 42, 'grade_level' => 'Grade 7', 'section' => 'Rizal',
            'health_history' => array_fill_keys($flags, '1') + [
                'med_allergies' => '1', 'allergies_detail' => 'Peanuts',
                // Written, box left unticked.
                'hospitalization_detail' => 'Appendectomy 2020',
            ],
        ])->assertSessionHasNoErrors();

        $history = StudentHealthRecord::where('student_id', '123456789012')->firstOrFail()->student_details['health_history'];
        foreach ([...$flags, 'med_allergies', 'med_hospitalization'] as $flag) {
            $this->assertTrue($history[$flag], $flag.' is saved ticked.');
        }
        $this->assertSame('Appendectomy 2020', $history['hospitalization_detail']);

        $this->assertFalse(HealthHistory::normalize(['allergies_detail' => 'wala', 'med_allergies' => false])['med_allergies']);
    }

    // ── #10 ────────────────────────────────────────────────────────────

    /** A stamp the app writes in UTC reads on the school's clock. */
    #[Test]
    public function stored_utc_times_read_in_philippine_time(): void
    {
        $this->assertSame('10:34 AM', SchoolClock::local(Carbon::parse('2026-11-09 02:34:00', 'UTC'))->format('g:i A'));
        $this->assertSame('10:34 AM', SchoolClock::local('2026-11-09 02:34:00')->format('g:i A'));

        // Latest Visits: a visit logged at 10:34 Philippine time reads 10:34 AM.
        Consultation::create([
            'institution_id' => $this->school->id,
            'consulted_at' => SchoolClock::now()->format('Y-m-d H:i:s'),
            'student_name' => 'Learner', 'grade_section' => 'Grade 9 / Rizal',
            'condition' => 'Headache', 'status' => 'treated',
        ]);

        $this->withSession([
            'active_role' => 'school_head', 'active_name' => 'Head', 'active_username' => 'head',
            'active_institution_id' => $this->school->id, 'active_school_name' => 'Sta. Ana NHS',
        ])->get(route('dashboard.school-head.health'))->assertOk()->assertSee('10:34 AM');
    }

    // ── The data check ─────────────────────────────────────────────────

    #[Test]
    public function the_data_check_lists_first_and_repairs_with_fix(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $stamped = $this->learner(['endline_nutritional_status' => 'Normal']);
        $history = $this->learner(['student_details' => ['gender' => 'Male', 'health_history' => [
            'med_allergies' => false, 'allergies_detail' => 'Amoxicillin',
        ]]]);
        $impossible = $this->learner(['baseline_height_cm' => 187, 'baseline_weight_kg' => 8.8]);
        $paracetamol = Medicine::create(['institution_id' => $this->school->id, 'name' => 'Paracetamol 500mg tablet', 'generic_name' => 'Generic name', 'stock_quantity' => 10, 'minimum_threshold' => 5, 'unit' => 'tablets']);
        $antifungal = Medicine::create(['institution_id' => $this->school->id, 'name' => 'Antifungal cream', 'generic_name' => 'Povidone-iodine', 'stock_quantity' => 3, 'minimum_threshold' => 2, 'unit' => 'tubes']);
        $utcVisit = Consultation::create([
            'institution_id' => $this->school->id,
            'consulted_at' => now()->format('Y-m-d H:i:s'), // the old default: the UTC moment
            'student_name' => 'Learner', 'grade_section' => 'Grade 9', 'condition' => 'Fever', 'status' => 'treated',
        ]);

        $this->artisan('data:tester-checks', ['--institution' => $this->school->id])
            ->expectsOutputToContain('[DRY RUN]')
            ->expectsOutputToContain('#6 Measurements no learner could have (re-measure, then correct on the learner\'s record): 1')
            ->expectsOutputToContain('#7 Endline status stamped with no endline weighing (--fix clears it): 1')
            ->expectsOutputToContain('#17 Generic names this kind of item cannot have')
            ->assertSuccessful();
        $this->assertNotNull($stamped->fresh()->getRawOriginal('endline_nutritional_status'), 'A dry run changes nothing.');

        $this->artisan('data:tester-checks', [
            '--institution' => $this->school->id,
            '--fix' => true,
            '--fix-visit-times' => true,
            '--generic' => ['Antifungal cream=Clotrimazole'],
        ])->assertSuccessful();

        $this->assertNull($stamped->fresh()->getRawOriginal('endline_nutritional_status'));
        $this->assertTrue($history->fresh()->student_details['health_history']['med_allergies']);
        $this->assertSame('Paracetamol', $paracetamol->fresh()->generic_name);
        $this->assertSame('Clotrimazole', $antifungal->fresh()->generic_name);
        $this->assertSame('10:34 AM', $utcVisit->fresh()->consulted_at->format('g:i A'));
        $this->assertEqualsWithDelta(187, (float) $impossible->fresh()->baseline_height_cm, 0.01, 'An impossible reading is listed, never changed.');
    }
}
