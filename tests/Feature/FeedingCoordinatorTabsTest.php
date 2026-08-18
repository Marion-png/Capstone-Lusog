<?php

namespace Tests\Feature;

use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\FeedingProgramCycle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards three changes to the Feeding Coordinator's tabs:
 *
 * - **A weekend is not a feeding day.** Nobody is fed on a Saturday or a Sunday,
 *   so a weekend is not a session the school missed — it is not a session, and
 *   counting it stretched every "day N of 120" past where the programme was.
 * - **The Nutritional Status panel reports a chosen population.** Beneficiaries
 *   is the programme; All Students is the whole roll, which is the only place a
 *   Normal or Obese learner is counted.
 * - **There is no Feeding Program tab.** The cycle, the at-risk list and the
 *   roll live on tabs that own them; a fourth rendering of all three is how two
 *   screens start reporting different numbers for one programme.
 */
class FeedingCoordinatorTabsTest extends TestCase
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

    private function makeStudent(string $status = 'Wasted', bool $enrolled = true, string $section = 'Grade 7 / Sampaguita', ?string $endline = null): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Learner '.random_int(1000, 9999),
            'student_id' => 'LRN'.random_int(100000, 999999),
            'school_name' => 'Test School',
            'section' => $section,
            'weight' => 30,
            'bmi_value' => 15.0,
            'nutritional_status' => $status,
            'baseline_nutritional_status' => $status,
            'endline_nutritional_status' => $endline,
            'student_details' => ['gender' => 'Male'],
            'feeding_enrolled_at' => $enrolled ? now() : null,
        ]);
    }

    // ── A weekend is not a feeding day ──────────────────────────────────

    #[Test]
    public function the_cycle_counts_school_days_not_calendar_days(): void
    {
        $monday = Carbon::now()->startOfWeek(Carbon::MONDAY);

        // Monday to the Friday of the next week: 12 calendar days, 10 school
        // days, because one weekend sits inside the span.
        $this->assertSame(10, FeedingProgramCycle::countFeedingDays($monday, $monday->copy()->addDays(11)));
        // Monday to Sunday is one school week, not seven days.
        $this->assertSame(5, FeedingProgramCycle::countFeedingDays($monday, $monday->copy()->addDays(6)));
        // A single weekday is one day; a single weekend day is none.
        $this->assertSame(1, FeedingProgramCycle::countFeedingDays($monday, $monday));
        $this->assertSame(0, FeedingProgramCycle::countFeedingDays($monday->copy()->addDays(5), $monday->copy()->addDays(6)));
    }

    #[Test]
    public function saturday_and_sunday_are_not_feeding_days(): void
    {
        $monday = Carbon::now()->startOfWeek(Carbon::MONDAY);

        foreach ([0, 1, 2, 3, 4] as $offset) {
            $this->assertTrue(FeedingProgramCycle::isFeedingDay($monday->copy()->addDays($offset)));
        }
        foreach ([5, 6] as $offset) {
            $this->assertFalse(FeedingProgramCycle::isFeedingDay($monday->copy()->addDays($offset)));
        }
    }

    /**
     * A cycle whose first mark landed on a weekend (data written before the
     * guard existed) reads as starting the Monday after — never as day 0 for a
     * programme that has demonstrably started.
     */
    #[Test]
    public function a_legacy_weekend_start_is_read_as_the_following_monday(): void
    {
        $learner = $this->makeStudent();
        $saturday = Carbon::now()->startOfWeek(Carbon::MONDAY)->subDays(2);

        FeedingAttendance::create([
            'student_health_record_id' => $learner->id,
            'session_date' => $saturday->toDateString(),
            'is_present' => true,
            'needs_review' => false,
            'source' => 'manual_entry',
        ]);

        $cycle = FeedingProgramCycle::forInstitution($this->institution->id);

        $this->assertTrue($cycle->hasStarted());
        $this->assertSame($saturday->copy()->addDays(2)->toDateString(), $cycle->startDateIso());
        // Monday through today (the Friday of the same week) is five days.
        $this->assertSame(5, $cycle->day());
    }

    /** 120 school days is about 24 calendar weeks, not 120 calendar days. */
    #[Test]
    public function the_cycle_window_closes_on_the_hundred_and_twentieth_school_day(): void
    {
        $learner = $this->makeStudent();
        $start = Carbon::now()->startOfWeek(Carbon::MONDAY);

        FeedingAttendance::create([
            'student_health_record_id' => $learner->id,
            'session_date' => $start->toDateString(),
            'is_present' => true,
            'needs_review' => false,
            'source' => 'manual_entry',
        ]);

        $end = Carbon::parse(FeedingProgramCycle::forInstitution($this->institution->id)->endDateIso());

        $this->assertTrue(FeedingProgramCycle::isFeedingDay($end));
        $this->assertSame(
            FeedingProgramCycle::DURATION_DAYS,
            FeedingProgramCycle::countFeedingDays($start, $end)
        );
        $this->assertGreaterThan(FeedingProgramCycle::DURATION_DAYS, (int) $start->diffInDays($end));
    }

    // ── Nutritional Status: population and endline ──────────────────────

    #[Test]
    public function the_nutritional_status_panel_reports_beneficiaries_by_default(): void
    {
        $this->makeStudent('Severely Wasted');
        $this->makeStudent('Normal', enrolled: false);

        $panel = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->assertSee('Beneficiaries')
            ->assertSee('All Students')
            ->viewData('nutritionStatus');

        $this->assertSame('beneficiaries', $panel['population']);
        $this->assertSame('Total Beneficiaries', $panel['total_label']);
        $this->assertSame(1, $panel['total']);
    }

    /**
     * All Students is where a learner the programme will never feed is counted:
     * Normal and Obese are not beneficiary statuses, and those children are
     * still on the school's roll.
     */
    #[Test]
    public function the_all_students_population_counts_learners_the_programme_never_feeds(): void
    {
        $this->makeStudent('Severely Wasted');
        $this->makeStudent('Normal', enrolled: false);
        $this->makeStudent('Obese', enrolled: false);
        // Senior High is outside the programme, so outside this roll too.
        $this->makeStudent('Normal', enrolled: false, section: 'Grade 11 / Humss');

        $panel = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard?population=all_students')
            ->assertOk()
            ->viewData('nutritionStatus');

        $counts = collect($panel['rows'])->pluck('count', 'label')->all();

        $this->assertSame('all_students', $panel['population']);
        $this->assertSame('Total Students', $panel['total_label']);
        $this->assertSame(3, $panel['total']);
        $this->assertSame(1, $counts['Severely Wasted']);
        $this->assertSame(1, $counts['Normal']);
        $this->assertSame(1, $counts['Obese']);
        $this->assertSame($panel['total'], array_sum($counts), 'The breakdown must sum to the total.');
    }

    /** A learner nobody weighed is "Not measured", never Normal. */
    #[Test]
    public function an_unmeasured_learner_is_never_reported_as_normal(): void
    {
        $this->makeStudent('');

        $panel = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard?population=all_students')
            ->assertOk()
            ->viewData('nutritionStatus');

        $counts = collect($panel['rows'])->pluck('count', 'label')->all();

        $this->assertSame(1, $counts['Not measured']);
        $this->assertSame(0, $counts['Normal']);
    }

    /**
     * Endline sits beside baseline and is never merged with it. It counts only
     * learners who have actually been re-measured, so it may sum to less than
     * the total — an unmeasured learner is not "unchanged".
     */
    #[Test]
    public function the_panel_reports_the_endline_beside_the_baseline(): void
    {
        $this->makeStudent('Severely Wasted', endline: 'Wasted');
        $this->makeStudent('Wasted');

        $panel = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->viewData('nutritionStatus');

        $rows = collect($panel['rows'])->keyBy('label');

        $this->assertSame(1, $rows['Severely Wasted']['count']);
        $this->assertSame(0, $rows['Severely Wasted']['endline']);
        $this->assertSame(1, $rows['Wasted']['count']);
        $this->assertSame(1, $rows['Wasted']['endline'], 'The learner who climbed a rung is counted at the endline.');
        $this->assertSame(1, $panel['endline_measured']);
        $this->assertSame(1, $panel['endline_pending']);
    }

    /** An unrecognised population is dropped rather than emptying the panel. */
    #[Test]
    public function an_unknown_population_falls_back_to_beneficiaries(): void
    {
        $this->makeStudent('Wasted');

        $panel = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard?population=everyone')
            ->assertOk()
            ->viewData('nutritionStatus');

        $this->assertSame('beneficiaries', $panel['population']);
        $this->assertSame(1, $panel['total']);
    }

    /** Widening the breakdown must not move the cards above it. */
    #[Test]
    public function the_population_switch_moves_the_panel_alone(): void
    {
        $this->makeStudent('Wasted');
        $this->makeStudent('Normal', enrolled: false);

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard?population=all_students')
            ->assertOk();

        $this->assertSame(2, $response->viewData('nutritionStatus')['total']);
        $this->assertSame(1, $response->viewData('dashboardStats')['beneficiaries']);
    }

    // ── No Feeding Program tab ──────────────────────────────────────────

    #[Test]
    public function the_coordinator_rail_carries_no_feeding_program_tab(): void
    {
        $html = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<span class="asb-link-text">Feeding Program</span>', $html);

        // The four tabs that replaced it are all still on the rail.
        foreach (['Dashboard', 'Beneficiaries', 'Attendance', 'At-Risk Students', 'SBFP Forms'] as $tab) {
            $this->assertStringContainsString('<span class="asb-link-text">'.$tab.'</span>', $html);
        }
    }

    /** An old link to the retired page lands on Attendance, not a 404. */
    #[Test]
    public function the_retired_page_redirects_rather_than_breaking(): void
    {
        $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-program')
            ->assertRedirect(route('dashboard.feedingcor-attendance'));
    }

    /** The School Nurse keeps their own read-only Feeding Program page. */
    #[Test]
    public function the_nurse_still_has_a_feeding_program_page(): void
    {
        $this->withSession(['active_role' => 'school_nurse'] + $this->coordinatorSession())
            ->get('/dashboard/school-nurse/feeding-program')
            ->assertOk();
    }
}
