<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\FeedingNutritionProgress;
use App\Support\FeedingProgramForecast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reporting & analytics: the outcome split and the programme outlook.
 *
 *   1. Improved / remained wasted / regressed, as shares of the whole roll,
 *      from one computation the coordinator's panel and the head's reports
 *      both read. The learners nobody has re-measured are their own segment,
 *      never dropped, so the shares cannot creep up off a handful of endlines.
 *   2. Predictive analytics, drawn only once at least two prior cycles have
 *      an endline on record. Below that the outlook says how many more it
 *      needs rather than fitting a line through one point.
 */
class FeedingOutcomeAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    private function headSession(): array
    {
        return [
            'active_role' => 'school_head',
            'active_name' => 'Principal Reyes',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->institution->id,
        ];
    }

    private function coordinatorSession(): array
    {
        return [
            'active_role' => 'feeding_coor',
            'active_name' => 'Coordinator Cruz',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->institution->id,
        ];
    }

    private function beneficiary(string $baseline, ?string $endline, ?string $schoolYear = null): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->institution->id,
            'school_year' => $schoolYear ?? StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Learner '.random_int(1000, 9999),
            'student_id' => 'LRN'.random_int(100000, 999999),
            'school_name' => 'Test School',
            'section' => 'Grade 7 / Rizal',
            'weight' => 30,
            'bmi_value' => 15,
            'nutritional_status' => $baseline,
            'baseline_nutritional_status' => $baseline,
            'endline_nutritional_status' => $endline,
            'feeding_enrolled_at' => now(),
        ]);
    }

    // ── Outcome split ──────────────────────────────────────────────────

    /** Five segments that sum to the roll, every share over the roll. */
    #[Test]
    public function the_split_sums_to_every_beneficiary(): void
    {
        $split = FeedingNutritionProgress::split(total: 10, measured: 6, improved: 3, unchanged: 1, declined: 1);

        $bySegment = collect($split['segments'])->keyBy('key');

        $this->assertSame(3, $bySegment['improved']['count']);
        $this->assertSame(30.0, $bySegment['improved']['pct']);
        $this->assertSame(1, $bySegment['unchanged']['count']);
        $this->assertSame('Remained wasted', $bySegment['unchanged']['label']);
        $this->assertSame(1, $bySegment['declined']['count']);
        $this->assertSame('Regressed', $bySegment['declined']['label']);
        // Measured but above Normal: no rung to compare, never "improved".
        $this->assertSame(1, $bySegment['off_scale']['count']);
        $this->assertSame(4, $bySegment['unmeasured']['count']);
        $this->assertSame(40.0, $bySegment['unmeasured']['pct']);

        $this->assertSame(10, collect($split['segments'])->sum('count'));
        $this->assertSame(0, collect(FeedingNutritionProgress::split(0, 0, 0, 0, 0)['segments'])->sum('count'));
    }

    /** The coordinator's panel and the head's reports print the same split. */
    #[Test]
    public function both_roles_read_one_split(): void
    {
        $this->beneficiary('Severely Wasted', 'Wasted');   // improved
        $this->beneficiary('Wasted', 'Normal');             // improved
        $this->beneficiary('Wasted', 'Wasted');             // remained wasted
        $this->beneficiary('Wasted', 'Severely Wasted');    // regressed
        $this->beneficiary('Wasted', null);                 // not yet measured

        $dashboard = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk();
        $reports = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/reports')
            ->assertOk();

        $expected = ['improved' => 2, 'unchanged' => 1, 'declined' => 1, 'off_scale' => 0, 'unmeasured' => 1];

        $fromDashboard = collect($dashboard->viewData('nutritionProgress')['split']['segments'])->pluck('count', 'key')->all();
        $fromReports = collect($reports->viewData('outcome')['split']['segments'])->pluck('count', 'key')->all();

        $this->assertSame($expected, $fromDashboard);
        $this->assertSame($expected, $fromReports);

        foreach ([$dashboard, $reports] as $response) {
            $response->assertSee('Remained wasted');
            $response->assertSee('Regressed');
            $response->assertSee('Not yet measured');
            $response->assertSee('data-outcome="declined"', false);
            // 2 of 5 improved: 40%.
            $response->assertSee('2 <small>&middot; 40%</small>', false);
        }
    }

    // ── Programme outlook ──────────────────────────────────────────────

    /** One completed cycle is not a trend; the panel says what it needs. */
    #[Test]
    public function the_outlook_waits_for_two_completed_cycles(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 17));

        // The running year, plus one prior year with an endline.
        $this->beneficiary('Wasted', 'Normal');
        $this->beneficiary('Wasted', 'Normal', '2025-2026');
        // A prior year with NO endline is not a completed cycle.
        $this->beneficiary('Wasted', null, '2024-2025');

        $outlook = FeedingProgramForecast::for($this->institution->id);

        $this->assertFalse($outlook['ready']);
        $this->assertSame(1, $outlook['completed']);
        $this->assertSame(1, $outlook['needed']);
        $this->assertNull($outlook['projection']);

        $this->withSession($this->headSession())
            ->get('/dashboard/school-head/reports')
            ->assertOk()
            ->assertSee('Programme Outlook')
            ->assertSee('Predictive analytics will open after 2 completed cycles')
            ->assertSee('1 on record so far')
            ->assertSee('No endline on record')
            ->assertSee('In progress')
            ->assertDontSee('Projected beneficiaries');
    }

    /** With history behind it, the next cycle is projected on a straight line through the completed ones. */
    #[Test]
    public function the_outlook_projects_the_next_cycle_from_completed_ones(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 17));

        // 2023-2024: 4 beneficiaries, 1 improved (25%).
        $this->beneficiary('Wasted', 'Normal', '2023-2024');
        foreach (range(1, 3) as $_) {
            $this->beneficiary('Wasted', 'Wasted', '2023-2024');
        }
        // 2024-2025: 6 beneficiaries, 3 improved (50%).
        foreach (range(1, 3) as $_) {
            $this->beneficiary('Wasted', 'Normal', '2024-2025');
            $this->beneficiary('Wasted', 'Wasted', '2024-2025');
        }
        // The running year is listed but never fitted.
        $this->beneficiary('Wasted', null);

        $outlook = FeedingProgramForecast::for($this->institution->id);

        $this->assertTrue($outlook['ready']);
        $this->assertSame(2, $outlook['completed']);

        $projection = $outlook['projection'];
        $this->assertSame('2027-2028', $projection['school_year']);
        $this->assertSame(8, $projection['beneficiaries'], '4 → 6 → 8 on the line.');
        $this->assertSame(75.0, $projection['improved_rate'], '25% → 50% → 75% on the line.');
        $this->assertSame('rising', $projection['beneficiaries_trend']);
        $this->assertSame('rising', $projection['improvement_trend']);
        $this->assertSame(8 * $projection['feeding_days'], $projection['meals']);

        $this->withSession($this->headSession())
            ->get('/dashboard/school-head/reports')
            ->assertOk()
            ->assertSee('Projected beneficiaries, S.Y. 2027-2028')
            ->assertSee('Projected feeding days to fund')
            ->assertSee('Projected improvement rate')
            ->assertSee('Completed cycle')
            ->assertSee('>Projection<', false)
            ->assertDontSee('Predictive analytics will open');
    }

    /** Another school's cycles are never this school's history. */
    #[Test]
    public function the_outlook_is_scoped_to_the_school(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 17));

        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        foreach (['2023-2024', '2024-2025'] as $year) {
            StudentHealthRecord::create([
                'institution_id' => $other->id,
                'school_year' => $year,
                'student_name' => 'Other Learner',
                'student_id' => 'LRN'.random_int(100000, 999999),
                'school_name' => 'Other School',
                'section' => 'Grade 7 / Rizal',
                'weight' => 30,
                'bmi_value' => 15,
                'nutritional_status' => 'Wasted',
                'baseline_nutritional_status' => 'Wasted',
                'endline_nutritional_status' => 'Normal',
                'feeding_enrolled_at' => now(),
            ]);
        }

        $this->assertFalse(FeedingProgramForecast::for($this->institution->id)['ready']);
        $this->assertSame(0, FeedingProgramForecast::for($this->institution->id)['completed']);
    }
}
