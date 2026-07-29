<?php

namespace Tests\Feature;

use App\Http\Controllers\FeedingCoordinatorController;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the Feeding Coordinator landing page. The dashboard renders a BMI
 * trend chart built in PHP (buildBmiChart), whose per-month closure needs the
 * healthy-range thresholds imported into scope — a missing `use` there made
 * every coordinator login 500 with "Undefined variable $underweight", so the
 * page is exercised end-to-end here rather than only through its helpers.
 */
class FeedingCoordinatorDashboardTest extends TestCase
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

    private function makeStudent(string $section, string $status, float $bmi): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Learner '.random_int(1000, 9999),
            'student_id' => 'LRN'.random_int(100000, 999999),
            'school_name' => 'Test School',
            'section' => $section,
            'weight' => 30,
            'bmi_value' => $bmi,
            'nutritional_status' => $status,
            'baseline_nutritional_status' => $status,
            'student_details' => ['gender' => 'Male'],
        ]);
    }

    #[Test]
    public function dashboard_renders_with_no_learners(): void
    {
        $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk();
    }

    #[Test]
    public function dashboard_renders_with_learners(): void
    {
        $this->makeStudent('Grade 7 / Sampaguita', 'Severely Wasted', 14.0);
        $this->makeStudent('Grade 7 / Rosal', 'Normal', 20.5);
        $this->makeStudent('Grade 8 / Ilang', 'Overweight', 27.2);

        $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk();
    }

    #[Test]
    public function bmi_chart_labels_each_month_with_its_healthy_range_band(): void
    {
        $this->makeStudent('Grade 7 / Sampaguita', 'Normal', 21.0);

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard');
        $response->assertOk();

        $months = $response->viewData('bmiChart')['months'];

        $this->assertCount(6, $months);

        // The band label is what the missing `use` broke: every month must
        // carry one, and a 21.0 average sits inside the healthy 18.5-25 range.
        foreach ($months as $month) {
            $this->assertArrayHasKey('band', $month);
            $this->assertSame('Healthy range', $month['band']);
        }
    }

    /**
     * The BMI line uses monotone cubic (Fritsch-Carlson) interpolation, not a
     * Catmull-Rom / cardinal spline. The difference only shows on flat or
     * turning data: a cardinal spline bulges past the readings there, drawing a
     * dip or peak nobody measured. Guard the two shapes where that happens.
     */
    #[Test]
    public function the_bmi_spline_never_overshoots_the_readings(): void
    {
        $method = new \ReflectionMethod(FeedingCoordinatorController::class, 'smoothPath');
        $method->setAccessible(true);
        $controller = (new \ReflectionClass(FeedingCoordinatorController::class))->newInstanceWithoutConstructor();

        // A flat run followed by a drop must stay perfectly flat while flat.
        $flatThenDrop = $method->invoke($controller, [[0.0, 100.0], [100.0, 100.0], [200.0, 100.0], [300.0, 50.0]]);
        $this->assertStringContainsString('C 33.3 100, 66.7 100, 100 100', $flatThenDrop);
        $this->assertStringContainsString('C 133.3 100, 166.7 100, 200 100', $flatThenDrop);

        // Around a peak, no control point may pass the peak itself (y = 40).
        $peak = $method->invoke($controller, [[0.0, 100.0], [100.0, 40.0], [200.0, 100.0]]);
        preg_match_all('/-?\d+(?:\.\d+)?/', substr($peak, 1), $numbers);
        $ys = array_values(array_filter($numbers[0], fn ($n, $i) => $i % 2 === 1, ARRAY_FILTER_USE_BOTH));
        foreach ($ys as $value) {
            $this->assertGreaterThanOrEqual(40.0, (float) $value, 'Spline overshot above the peak');
            $this->assertLessThanOrEqual(100.0, (float) $value, 'Spline overshot below the endpoints');
        }
    }
}
