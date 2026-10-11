<?php

namespace Tests\Feature;

use App\Http\Controllers\DataVisualizationController;
use App\Models\Consultation;
use App\Models\Institution;
use App\Models\Medicine;
use App\Models\StudentHealthRecord;
use App\Support\ClinicVisualization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the clinic's Data Visualization page.
 *
 * It used to be a sample layout with every figure typed into the markup.
 * Every chart is now read from the school's own records, and the page keeps
 * itself current from the shared change stamp. Four things are pinned:
 *
 * 1. **The figures are the school's.** Each chart is computed from the live
 *    tables, for this school only, and nothing is a placeholder.
 * 2. **Nobody unweighed is Normal.** Shares are taken over the learners
 *    actually measured; the rest are counted as Not measured.
 * 3. **One reading.** The first paint and the live refresh render the same
 *    partials from the same reading, and a filter moves every chart.
 * 4. **Aggregates only.** The charts never carry a learner's name.
 */
class NurseDataVisualizationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    private Institution $otherSchool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::create(2026, 10, 11, 10, 0, 0));

        $this->school = Institution::create(['name' => 'Test School', 'status' => 'active']);
        $this->otherSchool = Institution::create(['name' => 'Other School', 'status' => 'active']);
    }

    private function nurseSession(?Institution $school = null): array
    {
        $school ??= $this->school;

        return [
            'active_role' => 'school_nurse',
            'active_name' => 'Nurse Cruz',
            'active_username' => 'nurse.cruz',
            'active_school_name' => $school->name,
            'active_institution_id' => $school->id,
        ];
    }

    private function learner(string $name, string $section, ?string $status, array $extra = [], ?Institution $school = null): StudentHealthRecord
    {
        $school ??= $this->school;

        return StudentHealthRecord::create(array_merge([
            'institution_id' => $school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => $name,
            'student_id' => (string) random_int(100000000000, 999999999999),
            'school_name' => $school->name,
            'section' => $section,
            'weight' => $status === null ? null : 40,
            'bmi_value' => $status === null ? null : 18,
            'nutritional_status' => $status,
            'baseline_nutritional_status' => $status,
        ], $extra));
    }

    private function consultation(string $condition, string $status = 'treated', array $extra = [], ?Institution $school = null): Consultation
    {
        $school ??= $this->school;

        return Consultation::create(array_merge([
            'institution_id' => $school->id,
            'consulted_at' => now(),
            'student_name' => 'Visitor '.random_int(1000, 9999),
            'grade_section' => 'Grade 7 - Rizal',
            'condition' => $condition,
            'treatment_given' => 'Rest',
            'status' => $status,
        ], $extra));
    }

    private function read(array $query = [], ?Institution $school = null): array
    {
        return ClinicVisualization::read(($school ?? $this->school)->id, $query);
    }

    #[Test]
    public function the_page_no_longer_carries_sample_figures(): void
    {
        $html = $this->withSession($this->nurseSession())
            ->get(route('dashboard.data-visualization'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Sample layout', $html);
        $this->assertStringNotContainsString('not live data', $html);
        // The old donut was a conic-gradient with fixed stops.
        $this->assertStringNotContainsString('conic-gradient', $html);
        foreach (array_keys(DataVisualizationController::PANES) as $pane) {
            $this->assertStringContainsString('data-pane="'.$pane.'"', $html, "The {$pane} panel is missing.");
        }
    }

    #[Test]
    public function the_charts_draw_this_schools_records(): void
    {
        $this->learner('Santos, Ana', 'Grade 7 / Rizal', 'Normal');
        $this->learner('Reyes, Ben', 'Grade 7 / Rizal', 'Wasted');
        $this->consultation('Toothache');
        Medicine::create(['institution_id' => $this->school->id, 'name' => 'Paracetamol', 'stock_quantity' => 5, 'minimum_threshold' => 20]);

        $this->learner('Elsewhere, Pat', 'Grade 7 / Rizal', 'Obese', [], $this->otherSchool);
        $this->consultation('Sprained ankle', 'treated', [], $this->otherSchool);
        Medicine::create(['institution_id' => $this->otherSchool->id, 'name' => 'Ibuprofen', 'stock_quantity' => 1, 'minimum_threshold' => 50]);

        $html = $this->withSession($this->nurseSession())
            ->get(route('dashboard.data-visualization'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Toothache', $html);
        $this->assertStringContainsString('Paracetamol', $html);
        $this->assertStringNotContainsString('Sprained ankle', $html);
        $this->assertStringNotContainsString('Ibuprofen', $html);

        $data = $this->read();
        $this->assertSame(2, $data['nutrition']['latest']['total']);
        $this->assertSame(0, $data['nutrition']['latest']['rows'][4]['count'], 'Another school\'s Obese learner was counted.');
        $this->assertSame(1, $data['kpis']['consultations']);
        $this->assertSame(1, $data['kpis']['stock_alerts']);
    }

    #[Test]
    public function an_unweighed_learner_is_not_measured_and_never_normal(): void
    {
        $this->learner('A', 'Grade 7 / Rizal', 'Normal');
        $this->learner('B', 'Grade 7 / Rizal', 'Severely Wasted');
        $this->learner('C', 'Grade 7 / Rizal', 'Wasted');
        $this->learner('D', 'Grade 7 / Rizal', 'Normal');
        $this->learner('E', 'Grade 7 / Rizal', null);

        $latest = $this->read()['nutrition']['latest'];
        $byStatus = collect($latest['rows'])->keyBy('status');

        $this->assertSame(5, $latest['total']);
        $this->assertSame(4, $latest['measured']);
        $this->assertSame(1, $latest['not_measured']);
        $this->assertSame(2, $byStatus['Normal']['count']);
        // Over the four measured, never the five on the roll.
        $this->assertSame(50.0, $byStatus['Normal']['share']);
        $this->assertSame(50.0, $latest['undernourished_share']);

        // The ring sums to the measured learners, slice by slice.
        $this->assertCount(3, $latest['ring']);
        $this->assertSame(4, array_sum(array_column($latest['ring'], 'count')));
    }

    #[Test]
    public function a_share_over_nobody_is_an_em_dash(): void
    {
        $this->learner('Unweighed', 'Grade 7 / Rizal', null);

        $latest = $this->read()['nutrition']['latest'];

        $this->assertNull($latest['undernourished_share']);
        $this->assertSame('—', ClinicVisualization::percent($latest['undernourished_share']));
        $this->assertSame([], $latest['ring']);
    }

    #[Test]
    public function the_weighing_control_reads_each_phase_separately(): void
    {
        $this->learner('Improved', 'Grade 8 / Mabini', 'Wasted', [
            'endline_nutritional_status' => 'Normal',
        ]);
        $this->learner('Not yet', 'Grade 8 / Mabini', 'Wasted');

        $data = $this->read(['phase' => 'endline']);

        $this->assertSame('endline', $data['filters']['phase']);
        $this->assertSame(2, collect($data['nutrition']['baseline']['rows'])->firstWhere('status', 'Wasted')['count']);
        $this->assertSame(1, $data['nutrition']['endline']['measured']);
        $this->assertSame(1, $data['nutrition']['endline']['not_measured']);
        // Latest is the endline where one was taken, the current reading otherwise.
        $this->assertSame(1, collect($data['nutrition']['latest']['rows'])->firstWhere('status', 'Normal')['count']);
        $this->assertSame(-1, $data['kpis']['phases']['latest']['change']);

        $this->assertSame(ClinicVisualization::DEFAULT_PHASE, $this->read(['phase' => 'nonsense'])['filters']['phase']);
    }

    #[Test]
    public function a_grade_filter_moves_every_chart(): void
    {
        $this->learner('Seven', 'Grade 7 / Rizal', 'Normal');
        $this->learner('Eight', 'Grade 8 / Mabini', 'Wasted');
        $this->consultation('Fever', 'treated', ['student_name' => 'Eight']);
        $this->consultation('Cough', 'treated', ['student_name' => 'Seven']);

        $all = $this->read();
        $eight = $this->read(['grade' => 'Grade 8']);

        $this->assertSame(['Grade 7', 'Grade 8'], $all['filterOptions']['grades']);
        $this->assertSame(2, $all['nutrition']['latest']['total']);
        $this->assertSame(2, $all['kpis']['consultations']);

        $this->assertSame('Grade 8', $eight['filters']['grade']);
        $this->assertSame(['Mabini'], $eight['filterOptions']['sections']);
        $this->assertSame(1, $eight['nutrition']['latest']['total']);
        $this->assertSame(1, $eight['kpis']['consultations']);
        $this->assertSame(['Fever'], array_column($eight['cases']['rows'], 'label'));

        // A section that is not in the chosen grade decides nothing.
        $this->assertSame('', $this->read(['grade' => 'Grade 8', 'section' => 'Rizal'])['filters']['section']);
        $this->assertSame('', $this->read(['grade' => 'Grade 99'])['filters']['grade']);
    }

    #[Test]
    public function complaints_typed_differently_are_one_complaint(): void
    {
        $this->consultation('headache', 'treated', ['consulted_at' => now()->subDays(3)]);
        $this->consultation('Headache ', 'treated', ['consulted_at' => now()->subDays(2)]);
        $this->consultation('Headache', 'referred', ['consulted_at' => now()->subDay()]);
        $this->consultation('Fever');

        $rows = collect($this->read()['cases']['rows'])->keyBy('label');

        $this->assertSame(3, $rows['Headache']['count']);
        $this->assertSame(1, $rows['Fever']['count']);
        $this->assertCount(2, $rows);
        $this->assertSame(100.0, $rows['Headache']['pct']);
    }

    #[Test]
    public function the_tail_of_the_complaints_folds_into_one_bar(): void
    {
        foreach (range(1, ClinicVisualization::TOP_COMPLAINTS + 3) as $i) {
            $this->consultation('Complaint '.$i);
        }

        $cases = $this->read()['cases'];
        $other = end($cases['rows']);

        $this->assertCount(ClinicVisualization::TOP_COMPLAINTS + 1, $cases['rows']);
        $this->assertTrue($other['is_other']);
        $this->assertSame(3, $other['count']);
        $this->assertSame(3, $other['kinds']);
        $this->assertSame($cases['total'], array_sum(array_column($cases['rows'], 'count')));
    }

    #[Test]
    public function the_trend_runs_month_by_month_from_june_and_keeps_empty_months(): void
    {
        $this->consultation('Fever', 'treated', ['consulted_at' => CarbonImmutable::create(2026, 7, 3, 9)]);
        $this->consultation('Fever', 'referred', ['consulted_at' => CarbonImmutable::create(2026, 7, 9, 9)]);
        $this->consultation('Cough', 'treated', ['consulted_at' => CarbonImmutable::create(2026, 10, 1, 9)]);
        // Last school year: outside the period.
        $this->consultation('Cough', 'treated', ['consulted_at' => CarbonImmutable::create(2026, 4, 1, 9)]);

        $trend = $this->read()['trend'];

        $this->assertSame(['Jun', 'Jul', 'Aug', 'Sep', 'Oct'], array_column($trend['points'], 'label'));
        $this->assertSame([0, 2, 0, 0, 1], array_column($trend['points'], 'total'));
        $this->assertSame([0, 1, 0, 0, 1], array_column($trend['points'], 'treated'));
        $this->assertSame([0, 1, 0, 0, 0], array_column($trend['points'], 'referred'));
        $this->assertSame(3, $trend['total']);
        $this->assertSame('July 2026', $trend['busiest']['full_label']);
        $this->assertSame(5, count(explode(' ', $trend['lines']['treated'])));
    }

    #[Test]
    public function stock_is_read_against_each_medicines_own_reorder_line(): void
    {
        Medicine::create(['institution_id' => $this->school->id, 'name' => 'Zinc', 'stock_quantity' => 100, 'minimum_threshold' => 10]);
        Medicine::create(['institution_id' => $this->school->id, 'name' => 'Amoxicillin', 'stock_quantity' => 5, 'minimum_threshold' => 20]);
        Medicine::create(['institution_id' => $this->school->id, 'name' => 'Bandage', 'stock_quantity' => 0, 'minimum_threshold' => 10]);

        $stock = $this->read()['stock'];
        $byName = collect($stock['rows'])->keyBy('name');

        // Worst first.
        $this->assertSame(['Bandage', 'Amoxicillin', 'Zinc'], array_column($stock['rows'], 'name'));
        $this->assertSame(['out' => 1, 'low' => 1, 'monitor' => 0, 'good' => 1], $stock['counts']);
        // Fill against its own scale, tick at its own line.
        $this->assertSame(12.5, $byName['Amoxicillin']['fill']);
        $this->assertSame(50.0, $byName['Amoxicillin']['tick']);
        $this->assertSame(100.0, $byName['Zinc']['fill']);
        $this->assertSame(10.0, $byName['Zinc']['tick']);
    }

    #[Test]
    public function the_endline_series_is_not_drawn_before_anyone_is_remeasured(): void
    {
        $this->learner('Fed One', 'Grade 7 / Rizal', 'Wasted', ['feeding_enrolled_at' => now()]);
        $this->learner('Fed Two', 'Grade 7 / Rizal', 'Severely Wasted', ['feeding_enrolled_at' => now()]);

        $feeding = $this->read()['feeding'];
        $this->assertSame(2, $feeding['beneficiaries']);
        $this->assertFalse($feeding['has_endline']);

        $html = $this->withSession($this->nurseSession())
            ->get(route('dashboard.data-visualization.data'))
            ->assertOk()
            ->json('html.feeding');

        $this->assertStringContainsString('nv-pair-bar is-baseline', $html);
        $this->assertStringNotContainsString('nv-pair-bar is-endline', $html);
    }

    #[Test]
    public function the_live_endpoint_re_renders_every_panel_for_the_query(): void
    {
        $this->learner('Seven', 'Grade 7 / Rizal', 'Normal');
        $this->learner('Eight', 'Grade 8 / Mabini', 'Wasted');

        $response = $this->withSession($this->nurseSession())
            ->getJson(route('dashboard.data-visualization.data', ['grade' => 'Grade 8', 'phase' => 'baseline']))
            ->assertOk();

        $this->assertSame(array_keys(DataVisualizationController::PANES), array_keys($response->json('html')));
        $this->assertSame('Grade 8', $response->json('filters.grade'));
        $this->assertSame('baseline', $response->json('filters.phase'));
        $this->assertSame(['Mabini'], $response->json('sections'));
        $this->assertNotEmpty($response->json('stamp'));
    }

    #[Test]
    public function the_charts_carry_no_learner_names(): void
    {
        $this->learner('Dela Cruz, Marisol', 'Grade 7 / Rizal', 'Wasted', ['feeding_enrolled_at' => now()]);
        $this->consultation('Headache', 'treated', ['student_name' => 'Dela Cruz, Marisol']);

        $panels = $this->withSession($this->nurseSession())
            ->getJson(route('dashboard.data-visualization.data'))
            ->assertOk()
            ->json('html');

        foreach ($panels as $pane => $html) {
            $this->assertStringNotContainsString('Marisol', $html, "The {$pane} panel names a learner.");
        }
    }

    #[Test]
    public function every_clinic_role_opens_it_and_others_cannot_read_it(): void
    {
        foreach (['school_nurse', 'clinic_staff', 'clinic_teacher'] as $role) {
            $session = ['active_role' => $role] + $this->nurseSession();
            $this->withSession($session)->get(route('dashboard.data-visualization'))->assertOk();
            $this->withSession($session)->getJson(route('dashboard.data-visualization.data'))->assertOk();
        }

        $adviser = ['active_role' => 'class_adviser'] + $this->nurseSession();
        $this->withSession($adviser)->getJson(route('dashboard.data-visualization.data'))->assertForbidden();
    }

    #[Test]
    public function the_page_polls_the_shared_stamp_and_re_reads_in_place(): void
    {
        $html = $this->withSession($this->nurseSession())
            ->get(route('dashboard.data-visualization'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-pulse="'.route('workspace.pulse').'"', $html);
        $this->assertStringContainsString('data-source="'.route('dashboard.data-visualization.data').'"', $html);
        // It redraws its own panels, so it does not also reload the document.
        $this->assertStringNotContainsString('id="wlNotice"', $html);
    }

    #[Test]
    public function without_a_school_it_reads_nothing(): void
    {
        $this->learner('Somebody', 'Grade 7 / Rizal', 'Wasted');
        $this->consultation('Fever');

        $data = ClinicVisualization::read(null, []);

        $this->assertSame(0, $data['nutrition']['latest']['total']);
        $this->assertSame(0, $data['kpis']['consultations']);
        $this->assertSame([], $data['stock']['rows']);
    }
}
