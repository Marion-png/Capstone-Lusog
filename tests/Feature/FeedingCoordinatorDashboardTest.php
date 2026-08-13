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

    /**
     * The status roll counts beneficiaries by the status they were enrolled on
     * and always sums to the Beneficiary card, so the two panels can never tell
     * different stories. The full scale is listed even where a row is zero.
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
     * Today's panel has four states, never two. An unread scanned mark and a
     * learner today's sheet never covered are each their own thing — reporting
     * either as an absence would claim an absence nobody witnessed.
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

    /** A day nobody has recorded reports no turnout, not a 0% one. */
    #[Test]
    public function today_reads_as_unrecorded_when_no_mark_exists(): void
    {
        $this->makeStudent('Grade 7 / Sampaguita', 'Wasted', 15.1);

        $panel = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->viewData('todayAttendance');

        $this->assertFalse($panel['recorded']);
        $this->assertNull($panel['percent']);
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
     * Awaiting Enrollment. "Beneficiary" is the learners the programme feeds
     * (wasted, severely wasted, underweight) — not everyone on file — and
     * "Awaiting Enrollment" is those of them no session has ever covered.
     */
    #[Test]
    public function the_headline_cards_count_beneficiaries_attendance_and_awaiting_enrollment(): void
    {
        $fed = $this->makeStudent('Grade 7 / Sampaguita', 'Wasted', 15.1);
        $this->makeStudent('Grade 11 / Ilang', 'Severely Wasted', 13.4);   // qualified, never fed
        $this->makeStudent('Grade 8 / Rosal', 'Normal', 20.5);             // not a beneficiary

        foreach ([true, true, true, false] as $index => $isPresent) {
            $this->markAttendance($fed, now()->subDays(4 - $index)->toDateString(), $isPresent);
        }

        $stats = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->assertSee('Beneficiary')
            ->assertSee('Awaiting Enrollment')
            ->viewData('dashboardStats');

        $this->assertSame(2, $stats['beneficiaries'], 'Only qualified learners are beneficiaries.');
        $this->assertSame(1, $stats['beneficiaries_jhs']);
        $this->assertSame(1, $stats['beneficiaries_shs']);
        $this->assertSame(4, $stats['attendance_sessions']);
        $this->assertSame(75, $stats['attendance_rate']);
        $this->assertSame(1, $stats['awaiting_enrollment'], 'A qualified learner with no session is awaiting enrollment.');
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
        $this->assertSame(0, $stats['awaiting_enrollment'], 'A learner with a scanned mark has been fed, unconfirmed or not.');
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
