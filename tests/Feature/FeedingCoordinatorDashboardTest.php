<?php

namespace Tests\Feature;

use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the Feeding Coordinator landing page: the headline cards, the
 * nutritional-status roll, today's attendance panel, and the live refresh that
 * keeps all three current without a reload.
 *
 * Everything the page shows is computed in PHP (the columns it reads are
 * encrypted at rest), so the page is exercised end-to-end here rather than only
 * through its helpers.
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

    private function makeStudent(string $section, string $status, float $bmi, ?string $endlineStatus = null): StudentHealthRecord
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
            'endline_nutritional_status' => $endlineStatus,
            'student_details' => ['gender' => 'Male'],
            // Already in the programme: qualifying is the adviser's measurement,
            // enrolling is the coordinator's decision, and these learners have both.
            'feeding_enrolled_at' => now(),
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

    /**
     * The status roll counts beneficiaries by the status they were enrolled on
     * and always sums to the Beneficiary card, so the two panels can never tell
     * different stories. Rows are listed even where the count is zero.
     */
    #[Test]
    public function the_nutritional_status_panel_breaks_beneficiaries_down_by_baseline_status(): void
    {
        $this->makeStudent('Grade 7 / Sampaguita', 'Severely Wasted', 13.2);
        $this->makeStudent('Grade 7 / Rosal', 'Wasted', 15.1);
        $this->makeStudent('Grade 8 / Ilang', 'Wasted', 15.4);
        $this->makeStudent('Grade 8 / Rosal', 'Normal', 20.5);      // not a beneficiary

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->assertSee('Nutritional Status')
            ->assertSee('Total Beneficiaries');

        $panel = $response->viewData('nutritionStatus');
        $counts = collect($panel['rows'])->pluck('count', 'label')->all();

        $this->assertSame(3, $panel['total']);
        $this->assertSame(1, $counts['Severely Wasted']);
        $this->assertSame(2, $counts['Wasted']);
        $this->assertSame(0, $counts['Normal'], 'A Normal learner is not a beneficiary.');
        $this->assertSame(0, $counts['Obese']);
        $this->assertSame($panel['total'], array_sum($counts), 'The breakdown must sum to the total.');
        $this->assertSame($response->viewData('dashboardStats')['beneficiaries'], $panel['total']);
    }

    /**
     * Underweight and Overweight are off the scale: "Underweight" is this app's
     * label for a learner the DepEd sheet counts as Wasted, so those learners
     * are folded there rather than dropped — otherwise the breakdown would stop
     * summing to the total.
     */
    #[Test]
    public function underweight_is_counted_under_wasted_and_overweight_is_not_listed(): void
    {
        $this->makeStudent('Grade 7 / Sampaguita', 'Underweight', 16.1);
        $this->makeStudent('Grade 7 / Rosal', 'Wasted', 15.1);

        $panel = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->assertDontSee('Underweight')
            ->assertDontSee('Overweight')
            ->viewData('nutritionStatus');

        $labels = collect($panel['rows'])->pluck('label')->all();
        $counts = collect($panel['rows'])->pluck('count', 'label')->all();

        $this->assertSame(['Severely Wasted', 'Wasted', 'Normal', 'Obese'], $labels);
        $this->assertSame(2, $counts['Wasted']);
        $this->assertSame($panel['total'], array_sum($counts));
    }

    /**
     * Grade and section scope every panel; the nutritional and attendance
     * filters narrow the roll alone, so the headline keeps counting everyone
     * expected today rather than only what is on screen.
     */
    #[Test]
    public function grade_and_section_scope_the_cards_and_both_panels(): void
    {
        $this->makeStudent('Grade 7 / Sampaguita', 'Wasted', 15.1);
        $this->makeStudent('Grade 7 / Rosal', 'Wasted', 15.2);
        $this->makeStudent('Grade 8 / Ilang', 'Severely Wasted', 13.1);

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard?grade=Grade+7&section=Rosal')
            ->assertOk();

        $this->assertSame(1, $response->viewData('dashboardStats')['beneficiaries']);
        $this->assertSame(1, $response->viewData('nutritionStatus')['total']);
        $this->assertSame(1, $response->viewData('todayAttendance')['expected']);

        // Section options follow the chosen grade — Grade 8's are not offered.
        $this->assertSame(['Rosal', 'Sampaguita'], $response->viewData('filterOptions')['sections']);
        $this->assertSame(['Grade 7', 'Grade 8'], $response->viewData('filterOptions')['grades']);
    }

    #[Test]
    public function the_attendance_filter_narrows_the_roll_but_not_the_headline(): void
    {
        $present = $this->makeStudent('Grade 7 / Sampaguita', 'Wasted', 15.1);
        $absent = $this->makeStudent('Grade 7 / Rosal', 'Wasted', 15.2);
        $this->makeStudent('Grade 8 / Ilang', 'Severely Wasted', 13.1);

        $today = now()->toDateString();
        $this->markAttendance($present, $today, true);
        $this->markAttendance($absent, $today, false);

        $panel = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard?attendance=absent')
            ->assertOk()
            ->viewData('todayAttendance');

        $this->assertCount(1, $panel['rows']);
        $this->assertSame('absent', $panel['rows'][0]['status']);
        $this->assertSame(3, $panel['expected'], 'The headline still counts everyone expected.');
        $this->assertSame(1, $panel['present']);
        $this->assertTrue($panel['filtered']);

        $unmarked = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard?attendance=unmarked')
            ->assertOk()
            ->viewData('todayAttendance');

        $this->assertCount(1, $unmarked['rows']);
        $this->assertSame('unrecorded', $unmarked['rows'][0]['status']);
    }

    #[Test]
    public function the_school_year_filter_selects_which_year_the_panels_report(): void
    {
        $this->makeStudent('Grade 7 / Sampaguita', 'Wasted', 15.1);

        $previous = StudentHealthRecord::create([
            'institution_id' => $this->institution->id,
            'school_year' => '2020-2021',
            'student_name' => 'Older Learner',
            'student_id' => 'LRN000111',
            'school_name' => 'Test School',
            'section' => 'Grade 9 / Narra',
            'weight' => 30,
            'bmi_value' => 15.0,
            'nutritional_status' => 'Wasted',
            'baseline_nutritional_status' => 'Wasted',
            'student_details' => ['gender' => 'Male'],
            // Already in the programme: qualifying is the adviser's measurement,
            // enrolling is the coordinator's decision, and these learners have both.
            'feeding_enrolled_at' => now(),
        ]);

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard?school_year=2020-2021')
            ->assertOk()
            ->assertSee($previous->student_name);

        $this->assertSame(1, $response->viewData('dashboardStats')['beneficiaries']);
        $this->assertSame(['Grade 9'], $response->viewData('filterOptions')['grades']);
        $this->assertContains('2020-2021', $response->viewData('filterOptions')['school_years']);
    }

    /** A filtered page must refresh into the same filtered view, not a bare one. */
    #[Test]
    public function the_metrics_endpoint_honours_the_pages_filters(): void
    {
        $this->makeStudent('Grade 7 / Sampaguita', 'Wasted', 15.1);
        $other = $this->makeStudent('Grade 8 / Ilang', 'Wasted', 15.2);

        $html = $this->withSession($this->coordinatorSession())
            ->getJson('/dashboard/feedingcor-dashboard/metrics?grade=Grade+7')
            ->assertOk()
            ->json('html.attendance');

        $this->assertStringNotContainsString($other->student_name, $html);
    }

    /**
     * A recorded session leaves each learner Present or Absent. An unread
     * scanned mark and a learner today's sheet never covered are neither —
     * reporting either as an absence would claim one nobody witnessed.
     */
    #[Test]
    public function the_attendance_panel_separates_present_absent_unconfirmed_and_unrecorded(): void
    {
        $present = $this->makeStudent('Grade 7 / Sampaguita', 'Wasted', 15.1);
        $absent = $this->makeStudent('Grade 7 / Rosal', 'Wasted', 15.2);
        $unclear = $this->makeStudent('Grade 8 / Ilang', 'Severely Wasted', 13.1);
        $this->makeStudent('Grade 8 / Rosal', 'Wasted', 15.3);      // no mark at all

        $today = now()->toDateString();
        $this->markAttendance($present, $today, true);
        $this->markAttendance($absent, $today, false);
        $this->markAttendance($unclear, $today, null, true);

        $panel = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->assertSee('Attendance Monitoring')
            ->assertSee('Record Today&rsquo;s Attendance', false)
            ->viewData('todayAttendance');

        $this->assertSame(4, $panel['expected']);
        $this->assertSame(1, $panel['present']);
        $this->assertSame(1, $panel['absent']);
        $this->assertSame(1, $panel['unconfirmed']);
        $this->assertSame(1, $panel['unrecorded']);
        $this->assertSame(25.0, $panel['percent']);
        $this->assertCount(4, $panel['rows']);
    }

    /**
     * The headline is always a count out of the expected headcount, so it reads
     * the same shape before and after the session is recorded. `recorded` still
     * says which it is — the panel greys the figure and shows an Unmarked chip
     * rather than claiming a measured 0% turnout.
     */
    #[Test]
    public function today_reads_as_a_count_before_anything_is_recorded(): void
    {
        $this->makeStudent('Grade 7 / Sampaguita', 'Wasted', 15.1);

        $panel = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->assertSee('0 / 1')
            ->viewData('todayAttendance');

        $this->assertFalse($panel['recorded']);
        $this->assertSame(0.0, $panel['percent']);
        $this->assertSame(1, $panel['unrecorded']);
    }

    /**
     * The live refresh renders the same partials the first paint used, so a
     * watched screen can never drift from a reloaded one. The pulse it polls
     * carries a stamp and nothing else.
     */
    #[Test]
    public function the_metrics_endpoint_returns_the_panels_and_the_pulse_only_a_stamp(): void
    {
        $learner = $this->makeStudent('Grade 7 / Sampaguita', 'Wasted', 15.1);

        $metrics = $this->withSession($this->coordinatorSession())
            ->getJson('/dashboard/feedingcor-dashboard/metrics')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('cards', $metrics['html']);
        $this->assertArrayHasKey('attendance', $metrics['html']);
        $this->assertArrayHasKey('nutrition', $metrics['html']);
        $this->assertStringContainsString('Total Beneficiaries', $metrics['html']['nutrition']);

        $pulse = $this->withSession($this->coordinatorSession())
            ->getJson('/dashboard/feedingcor-dashboard/metrics/pulse')
            ->assertOk()
            ->json();

        $this->assertSame(['stamp'], array_keys($pulse), 'The pulse must carry no data beyond its stamp.');
        $this->assertSame($metrics['stamp'], $pulse['stamp']);

        // Recording a mark must move the stamp, or the panel would never refresh.
        $this->markAttendance($learner, now()->toDateString(), true);

        $this->assertNotSame(
            $pulse['stamp'],
            $this->withSession($this->coordinatorSession())
                ->getJson('/dashboard/feedingcor-dashboard/metrics/pulse')
                ->json('stamp')
        );
    }

    /** Another school's coordinator gets nothing from the live endpoints. */
    #[Test]
    public function the_live_endpoints_are_closed_to_other_roles(): void
    {
        $this->withSession(['active_role' => 'class_adviser', 'active_institution_id' => $this->institution->id])
            ->getJson('/dashboard/feedingcor-dashboard/metrics')
            ->assertForbidden();

        $this->withSession(['active_role' => 'class_adviser', 'active_institution_id' => $this->institution->id])
            ->getJson('/dashboard/feedingcor-dashboard/metrics/pulse')
            ->assertForbidden();
    }

    /**
     * The four headline cards are Beneficiary / Attendance / At-Risk /
     * Awaiting Enrollment. "Beneficiary" is the learners the coordinator has
     * enrolled — qualifying alone does not feed anyone — and "Awaiting
     * Enrollment" is those who qualify but have not been enrolled yet.
     */
    #[Test]
    public function the_headline_cards_count_beneficiaries_attendance_and_awaiting_enrollment(): void
    {
        $fed = $this->makeStudent('Grade 7 / Sampaguita', 'Wasted', 15.1);
        $this->makeStudent('Grade 11 / Ilang', 'Severely Wasted', 13.4);   // enrolled, never fed
        $this->makeStudent('Grade 8 / Rosal', 'Normal', 20.5);             // does not qualify

        // Qualified but not enrolled: waiting, and not a beneficiary yet.
        $this->makeStudent('Grade 9 / Narra', 'Wasted', 15.5)
            ->update(['feeding_enrolled_at' => null]);

        foreach ([true, true, true, false] as $index => $isPresent) {
            $this->markAttendance($fed, now()->subDays(4 - $index)->toDateString(), $isPresent);
        }

        $stats = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->assertSee('Beneficiary')
            ->assertSee('Awaiting Enrollment')
            ->viewData('dashboardStats');

        $this->assertSame(2, $stats['beneficiaries'], 'Only enrolled learners are beneficiaries.');
        $this->assertSame(1, $stats['beneficiaries_jhs']);
        $this->assertSame(1, $stats['beneficiaries_shs']);
        $this->assertSame(4, $stats['attendance_sessions']);
        $this->assertSame(75, $stats['attendance_rate']);
        $this->assertSame(1, $stats['awaiting_enrollment'], 'A qualified learner nobody enrolled is waiting.');
    }

    /**
     * The unconfirmed-mark invariant, on the dashboard: a scanned mark nobody
     * has reviewed is NULL, and NULL votes neither way. Counting it as an
     * absence would report a turnout no human has read.
     */
    #[Test]
    public function an_unconfirmed_mark_changes_neither_the_rate_nor_the_session_count(): void
    {
        $learner = $this->makeStudent('Grade 7 / Sampaguita', 'Wasted', 15.1);
        $this->markAttendance($learner, now()->subDays(2)->toDateString(), true);
        $this->markAttendance($learner, now()->subDay()->toDateString(), null, true);

        $stats = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->viewData('dashboardStats');

        $this->assertSame(1, $stats['attendance_sessions']);
        $this->assertSame(100, $stats['attendance_rate']);
        $this->assertSame(0, $stats['awaiting_enrollment'], 'An enrolled learner is not waiting to be enrolled.');
    }

    /** No confirmed session is not a 0% turnout — the card shows a dash. */
    #[Test]
    public function attendance_is_null_until_a_session_is_confirmed(): void
    {
        $this->makeStudent('Grade 7 / Sampaguita', 'Wasted', 15.1);

        $stats = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->viewData('dashboardStats');

        $this->assertNull($stats['attendance_rate']);
    }

    /**
     * The page is titled like every other tab: subject upright, section in the
     * italic emerald span. "SBFP" alone did not say what the page was.
     */
    #[Test]
    public function the_header_carries_the_full_programme_name_in_the_shared_title_style(): void
    {
        $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->assertSee('<h1 class="page-title">School-Based Feeding Program <span>Dashboard</span></h1>', false)
            ->assertSee('S.Y.');
    }

    /** Choosing a filter applies it — there is no Apply button to press. */
    #[Test]
    public function the_filter_bar_has_no_apply_button(): void
    {
        $html = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->getContent();

        // The only Apply left is the no-JS fallback inside <noscript>.
        $withoutNoscript = preg_replace('#<noscript>.*?</noscript>#s', '', $html);

        $this->assertStringNotContainsString('>Apply<', (string) $withoutNoscript);
        $this->assertStringContainsString('<noscript><button type="submit" class="btn btn-primary">Apply</button></noscript>', $html);
    }

    /** Day 1 is the first recorded session, so the header and the bar agree with it. */
    #[Test]
    public function the_header_counts_the_feeding_day_from_the_first_session(): void
    {
        $learner = $this->makeStudent('Grade 7 / Sampaguita', 'Wasted', 15.1);
        $this->markAttendance($learner, now()->subDays(10)->toDateString(), true);

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->assertSee('SBFP')
            ->assertSee('Feeding day');

        $cycle = $response->viewData('programCycle');

        $this->assertTrue($cycle['started']);
        $this->assertSame(11, $cycle['day']);
        $this->assertSame(120, $cycle['duration']);
        $this->assertSame(109, $cycle['days_remaining']);
        $this->assertEqualsWithDelta(9.2, $cycle['percent'], 0.05);
        $this->assertSame(now()->subDays(10)->toDateString(), $cycle['start_date']);
    }

    /** A school that has never fed anyone has no cycle — not a day 1 nothing supports. */
    #[Test]
    public function the_cycle_has_not_started_before_the_first_session(): void
    {
        $this->makeStudent('Grade 7 / Sampaguita', 'Wasted', 15.1);

        $cycle = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->viewData('programCycle');

        $this->assertFalse($cycle['started']);
        $this->assertSame(0, $cycle['day']);
        $this->assertNull($cycle['start_date']);
    }

    /**
     * The at-risk panel is computed from the school's threshold, and the
     * fraction it prints is the one the rule judged — a learner cannot read as
     * "3/4" on screen while being flagged on some other denominator.
     */
    #[Test]
    public function the_attendance_risk_panel_lists_learners_under_the_threshold(): void
    {
        $failing = $this->makeStudent('Grade 7 / Sampaguita', 'Wasted', 15.1);
        $passing = $this->makeStudent('Grade 7 / Rosal', 'Wasted', 15.2);

        // 3 of 4 = 75%, below the 80% default.
        foreach ([true, true, true, false] as $index => $present) {
            $this->markAttendance($failing, now()->subDays(5 - $index)->toDateString(), $present);
        }
        foreach ([true, true, true, true] as $index => $present) {
            $this->markAttendance($passing, now()->subDays(5 - $index)->toDateString(), $present);
        }

        $panel = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->assertSee('Attendance At Risk')
            ->assertSee('At-risk threshold:')
            ->assertSee('View At-Risk List')
            ->viewData('attendanceRisk');

        $this->assertSame(80.0, $panel['threshold']);
        $this->assertSame(1, $panel['count']);
        $this->assertSame($failing->student_name, $panel['rows'][0]['name']);
        $this->assertSame(75.0, $panel['rows'][0]['rate']);
        $this->assertSame(3, $panel['rows'][0]['present']);
        $this->assertSame(4, $panel['rows'][0]['sessions']);
    }

    /**
     * The threshold is school-configurable, and the dashboard must answer to
     * the school's own figure — not the platform default, and not a stale
     * is_at_risk flag written under the old number.
     */
    #[Test]
    public function a_school_set_threshold_decides_who_is_flagged(): void
    {
        $learner = $this->makeStudent('Grade 7 / Sampaguita', 'Wasted', 15.1);
        foreach ([true, true, true, false] as $index => $present) {   // 75%
            $this->markAttendance($learner, now()->subDays(5 - $index)->toDateString(), $present);
        }

        // At the 80% default the learner is flagged.
        $this->assertSame(
            1,
            $this->withSession($this->coordinatorSession())
                ->get('/dashboard/feedingcor-dashboard')
                ->viewData('attendanceRisk')['count']
        );

        $this->institution->update(['feeding_at_risk_threshold' => 70]);

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->assertSee('At-risk threshold: <strong>70%</strong>', false);

        $this->assertSame(0, $response->viewData('attendanceRisk')['count']);
        $this->assertSame(0, $response->viewData('dashboardStats')['at_risk'], 'The card must agree with the panel.');
    }

    /**
     * Baseline against endline, with the improvement figure computed in the
     * feeding business logic rather than entered by hand.
     */
    #[Test]
    public function the_nutrition_progress_panel_measures_improvement(): void
    {
        // Severely Wasted -> Wasted: climbed a rung.
        $this->makeStudent('Grade 7 / Sampaguita', 'Severely Wasted', 13.1, 'Wasted');
        // Wasted -> Normal: climbed a rung.
        $this->makeStudent('Grade 7 / Rosal', 'Wasted', 15.1, 'Normal');
        // Wasted -> Wasted: measured, unchanged.
        $this->makeStudent('Grade 8 / Ilang', 'Wasted', 15.2, 'Wasted');
        // No endline reading at all.
        $this->makeStudent('Grade 8 / Narra', 'Wasted', 15.3);

        $panel = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->assertSee('Nutritional Progress')
            ->assertSee('Baseline')
            ->assertSee('Endline')
            ->viewData('nutritionProgress');

        $this->assertSame(4, $panel['total']);
        $this->assertSame(3, $panel['measured']);
        $this->assertSame(2, $panel['improved']);
        $this->assertSame(1, $panel['unchanged']);
        $this->assertSame(0, $panel['declined']);
        // 2 of 4 beneficiaries, not 2 of the 3 measured.
        $this->assertSame(50.0, $panel['rate']);

        $rows = collect($panel['rows'])->keyBy('label');
        $this->assertSame(1, $rows['Severely Wasted']['baseline']);
        $this->assertSame(3, $rows['Wasted']['baseline']);
        $this->assertSame(2, $rows['Wasted']['endline']);
        $this->assertSame(1, $rows['Normal']['endline']);
    }

    /** Leaving wasting for obesity is not the programme succeeding. */
    #[Test]
    public function overshooting_past_normal_is_not_counted_as_improvement(): void
    {
        $this->makeStudent('Grade 7 / Sampaguita', 'Wasted', 15.1, 'Obese');

        $panel = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->viewData('nutritionProgress');

        $this->assertSame(1, $panel['measured']);
        $this->assertSame(0, $panel['improved']);
        $this->assertSame(0.0, $panel['rate']);
        $this->assertSame(1, collect($panel['rows'])->keyBy('label')['Obese']['endline']);
    }

    /** The Weight & BMI Log it replaced is gone, not merely hidden. */
    #[Test]
    public function the_weight_and_bmi_log_is_no_longer_rendered(): void
    {
        $this->makeStudent('Grade 7 / Sampaguita', 'Wasted', 15.1);

        $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->assertDontSee('Weight &amp; BMI Log', false)
            ->assertDontSee('checkins-table', false);
    }

    private function markAttendance(StudentHealthRecord $record, string $date, ?bool $isPresent, bool $needsReview = false): void
    {
        FeedingAttendance::create([
            'student_health_record_id' => $record->id,
            'session_date' => $date,
            'is_present' => $isPresent,
            'needs_review' => $needsReview,
            'source' => $needsReview ? FeedingAttendance::SOURCE_PHOTO_SCAN : FeedingAttendance::SOURCE_SPREADSHEET,
        ]);
    }
}
