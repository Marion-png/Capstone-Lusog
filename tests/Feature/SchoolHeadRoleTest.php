<?php

namespace Tests\Feature;

use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\ReportReview;
use App\Models\StudentHealthRecord;
use App\Support\SchoolHeadOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the School Head role's boundary and its three reading tabs.
 *
 * The invariant behind all of it: **the head reads and decides; other roles
 * write.** That is enforced server-side over every state-changing request, not
 * by hiding buttons — so a stale tab or a hand-made POST is refused the same
 * way the interface is.
 *
 * The rest is about honesty of measurement: a learner nobody weighed is never
 * reported as Normal, a rate with no confirmed session is an em dash rather
 * than 0%, and rehabilitation is never conflated with improvement.
 */
class SchoolHeadRoleTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private Institution $otherSchool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
        $this->otherSchool = Institution::create(['name' => 'Other School', 'status' => 'active']);
    }

    private function headSession(): array
    {
        return [
            'active_role' => 'school_head',
            'active_name' => 'Principal Reyes',
            'active_username' => 'head.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->institution->id,
        ];
    }

    private function makeLearner(array $attributes = [], ?Institution $school = null): StudentHealthRecord
    {
        $school ??= $this->institution;

        return StudentHealthRecord::create(array_merge([
            'institution_id' => $school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Learner '.random_int(1000, 9999),
            'student_id' => 'LRN'.random_int(100000, 999999),
            'school_name' => $school->name,
            'section' => 'Grade 7 / Rizal',
            'weight' => 30,
            'bmi_value' => 15,
            'nutritional_status' => 'Wasted',
            'baseline_nutritional_status' => 'Wasted',
        ], $attributes));
    }

    private function mark(StudentHealthRecord $learner, string $date, ?bool $present): void
    {
        FeedingAttendance::create([
            'student_health_record_id' => $learner->id,
            'session_date' => $date,
            'is_present' => $present,
            'needs_review' => $present === null,
        ]);
    }

    // ── The boundary ────────────────────────────────────────────────────

    #[Test]
    public function every_write_endpoint_outside_the_allowed_list_is_forbidden(): void
    {
        $learner = $this->makeLearner(['feeding_enrolled_at' => now()]);

        $blocked = [
            '/dashboard/feedingcor-program/enrollment' => ['record_ids' => [$learner->id]],
            '/dashboard/feedingcor-program/attendance/record' => ['session_date' => now()->toDateString()],
            '/announcements' => ['title' => 'Hi', 'body' => 'There'],
        ];

        foreach ($blocked as $url => $payload) {
            $this->withSession($this->headSession())
                ->post($url, $payload)
                ->assertForbidden();
        }

        // Nothing was written by any of them.
        $this->assertDatabaseCount('announcements', 0);
    }

    #[Test]
    public function the_refusal_is_a_403_for_json_callers_too(): void
    {
        $this->withSession($this->headSession())
            ->postJson('/dashboard/feedingcor-program/enrollment', ['record_ids' => [1]])
            ->assertForbidden()
            ->assertJsonStructure(['message']);
    }

    #[Test]
    public function other_roles_are_untouched_by_the_school_head_restriction(): void
    {
        // The same endpoint, from the role that owns it, is not blocked by the
        // middleware — whatever it answers, it is not a 403 from this rule.
        $response = $this->withSession([
            'active_role' => 'feeding_coor',
            'active_name' => 'Coordinator',
            'active_username' => 'coor.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->institution->id,
        ])->post('/dashboard/feedingcor-program/enrollment', ['record_ids' => []]);

        $this->assertNotSame(403, $response->getStatusCode());
    }

    /**
     * The one case that looks like a hole and is not.
     *
     * EnsureActiveSession's prototype behaviour deliberately re-seeds a *demo*
     * session for whichever role a URL belongs to, so any screen can be opened
     * by typing its address. A demo session that wanders onto a coordinator URL
     * therefore stops being a School Head before this middleware ever sees it —
     * it is not an authenticated head slipping past the rule, it is the
     * prototype handing over a different demo user.
     *
     * A real account (any username but 'prototype') is never re-seeded, so the
     * role survives the trip and the write is refused.
     */
    #[Test]
    public function a_real_account_keeps_its_role_across_another_roles_url_and_is_refused(): void
    {
        $response = $this->withSession($this->headSession())
            ->post('/dashboard/feedingcor-program/enrollment', ['record_ids' => []]);

        $response->assertForbidden();
        $this->assertSame('school_head', session('active_role'));
    }

    #[Test]
    public function signing_out_is_still_allowed(): void
    {
        $this->withSession($this->headSession())
            ->post('/logout')
            ->assertRedirect();
    }

    // ── Feeding Program tab ─────────────────────────────────────────────

    #[Test]
    public function the_feeding_program_tab_draws_the_cycle_from_recorded_sessions(): void
    {
        $learner = $this->makeLearner(['feeding_enrolled_at' => now()]);
        $this->mark($learner, now()->subDays(2)->toDateString(), true);
        $this->mark($learner, now()->subDay()->toDateString(), false);

        $response = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/program')
            ->assertOk();

        $stats = $response->viewData('stats');
        $this->assertSame(1, $stats['beneficiaries']);
        $this->assertSame(2, $stats['days_completed']);
        $this->assertSame(1, $stats['meals_served']);
        $this->assertSame(2, $stats['meals_planned']);

        // Day 1 was a full turnout, day 2 nobody came; the rest of the 120
        // cells are days the cycle has not reached.
        $grid = collect($response->viewData('grid'));
        $this->assertCount(120, $grid);
        $this->assertSame('fed', $grid[0]['state']);
        $this->assertSame('low', $grid[1]['state']);
        $this->assertSame('upcoming', $grid[2]['state']);
    }

    #[Test]
    public function the_baseline_and_latest_toggle_moves_the_chart_and_its_callout_together(): void
    {
        $this->makeLearner([
            'section' => 'Grade 8 / Bonifacio',
            'baseline_nutritional_status' => 'Severely Wasted',
            'nutritional_status' => 'Normal',
            'endline_nutritional_status' => 'Normal',
        ]);

        $baseline = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/program?phase=baseline')->assertOk();
        $this->assertSame(1, $baseline->viewData('chart')['totals']['Severely Wasted']);
        $this->assertSame(1, $baseline->viewData('callout')['undernourished']);
        $this->assertSame('Grade 8', $baseline->viewData('callout')['worst_grade']);

        $latest = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/program?phase=latest')->assertOk();
        $this->assertSame(1, $latest->viewData('chart')['totals']['Normal']);
        $this->assertSame(0, $latest->viewData('callout')['undernourished']);
        $this->assertNull($latest->viewData('callout')['worst_grade']);
    }

    #[Test]
    public function an_unconfirmed_mark_neither_feeds_nor_absents_a_learner(): void
    {
        $learner = $this->makeLearner(['feeding_enrolled_at' => now()]);
        $this->mark($learner, now()->subDay()->toDateString(), null);

        $response = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/program')->assertOk();

        // The day exists, but it has no turnout to report — never 0%.
        $grid = collect($response->viewData('grid'));
        $this->assertSame('review', $grid[0]['state']);
        $this->assertNull($response->viewData('stats')['turnout']);
        $this->assertSame(0, $response->viewData('stats')['meals_served']);
    }

    #[Test]
    public function the_feeding_program_tab_carries_no_activity_log(): void
    {
        $learner = $this->makeLearner(['feeding_enrolled_at' => now()]);
        $this->mark($learner, now()->toDateString(), true);

        $html = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/program')->assertOk()->getContent();

        $this->assertStringNotContainsString('Activity log', $html);
        $this->assertStringNotContainsString('sh-activity', $html);
    }

    #[Test]
    public function the_feeding_program_tab_offers_no_way_to_change_a_mark(): void
    {
        $learner = $this->makeLearner(['feeding_enrolled_at' => now()]);
        $this->mark($learner, now()->toDateString(), true);

        $html = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/program')->assertOk()->getContent();

        $this->assertStringNotContainsString('attendance/record', $html);
        $this->assertStringNotContainsString('attendance/review', $html);
    }

    // ── Reports tab ─────────────────────────────────────────────────────

    #[Test]
    public function rehabilitation_and_improvement_are_counted_separately(): void
    {
        // Rehabilitated: reached Normal.
        $this->makeLearner([
            'feeding_enrolled_at' => now(),
            'baseline_nutritional_status' => 'Wasted',
            'endline_nutritional_status' => 'Normal',
        ]);
        // Improved but NOT rehabilitated: climbed one rung, still wasted.
        $this->makeLearner([
            'feeding_enrolled_at' => now(),
            'baseline_nutritional_status' => 'Severely Wasted',
            'endline_nutritional_status' => 'Wasted',
        ]);
        // Not measured at endline at all.
        $this->makeLearner([
            'feeding_enrolled_at' => now(),
            'baseline_nutritional_status' => 'Wasted',
        ]);

        $outcome = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/reports')->assertOk()->viewData('outcome');

        $this->assertSame(3, $outcome['beneficiaries']);
        $this->assertSame(2, $outcome['measured']);
        $this->assertSame(1, $outcome['not_measured']);
        $this->assertSame(1, $outcome['rehabilitated']);
        $this->assertSame(2, $outcome['improved']);
        $this->assertSame(2, $outcome['still_undernourished']);
        // Denominator is every beneficiary, not only those measured.
        $this->assertEqualsWithDelta(33.3, $outcome['rate'], 0.1);
    }

    #[Test]
    public function a_rehabilitation_rate_with_no_beneficiaries_is_undefined_not_zero(): void
    {
        $outcome = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/reports')->assertOk()->viewData('outcome');

        $this->assertSame(0, $outcome['beneficiaries']);
        $this->assertNull($outcome['rate']);
    }

    #[Test]
    public function a_programme_with_no_endline_yet_reports_no_rate_rather_than_zero(): void
    {
        // Eight beneficiaries, none re-measured. "0% rehabilitated" would read
        // as "the feeding achieved nothing" when nobody has looked yet.
        foreach (range(1, 8) as $ignored) {
            $this->makeLearner(['feeding_enrolled_at' => now()]);
        }

        $outcome = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/reports')->assertOk()->viewData('outcome');

        $this->assertSame(8, $outcome['beneficiaries']);
        $this->assertSame(0, $outcome['measured']);
        $this->assertNull($outcome['rate']);

        $this->withSession($this->headSession())
            ->get('/dashboard/school-head')
            ->assertOk()
            ->assertSee('No endline measurement recorded yet');
    }

    #[Test]
    public function the_shift_chart_reads_against_a_clean_axis_and_names_the_change(): void
    {
        // Four learners, all Wasted at baseline; three reach Normal.
        foreach (range(1, 4) as $i) {
            $this->makeLearner([
                'feeding_enrolled_at' => now(),
                'baseline_nutritional_status' => 'Wasted',
                'endline_nutritional_status' => $i === 4 ? 'Wasted' : 'Normal',
            ]);
        }

        $shift = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/reports')->assertOk()->viewData('shift');

        $rows = collect($shift['rows'])->keyBy('label');
        $this->assertSame(4, $rows['Wasted']['baseline']);
        $this->assertSame(1, $rows['Wasted']['endline']);
        $this->assertSame(-3, $rows['Wasted']['change']);
        $this->assertSame(0, $rows['Normal']['baseline']);
        $this->assertSame(3, $rows['Normal']['endline']);
        $this->assertSame(3, $rows['Normal']['change']);

        // Four gridlines on whole learners — a count never lands between two.
        $this->assertSame(4, $shift['axis_max']);
        $this->assertSame([0, 1, 2, 3, 4], $shift['ticks']);
        $this->assertTrue($shift['has_endline']);
    }

    #[Test]
    public function the_shift_chart_draws_no_endline_series_before_an_endline_exists(): void
    {
        // A row of zeros would read as "every learner left the category",
        // which is the opposite of "nobody has been re-measured".
        $this->makeLearner(['baseline_nutritional_status' => 'Wasted']);

        $shift = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/reports')->assertOk()->viewData('shift');

        $this->assertFalse($shift['has_endline']);
        $this->assertSame(0, $shift['endline_measured']);
        $this->assertSame(1, $shift['baseline_measured']);

        $this->withSession($this->headSession())
            ->get('/dashboard/school-head/reports')
            ->assertOk()
            ->assertDontSee('sh-shift-bar is-endline', false);
    }

    #[Test]
    public function the_turnout_chart_runs_oldest_month_first_on_a_fixed_axis(): void
    {
        $learner = $this->makeLearner(['feeding_enrolled_at' => now()]);
        // Two months: one fully attended, one half.
        $this->mark($learner, now()->subMonth()->startOfMonth()->addDays(4)->toDateString(), true);
        $this->mark($learner, now()->startOfMonth()->addDays(2)->toDateString(), true);
        $this->mark($learner, now()->startOfMonth()->addDays(3)->toDateString(), false);

        $turnout = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/reports')->assertOk()->viewData('turnout');

        $this->assertCount(2, $turnout['columns']);
        // Time reads left to right, so the older month is the first column.
        $this->assertSame(
            now()->subMonth()->format('M'),
            $turnout['columns'][0]['label']
        );
        $this->assertSame(100.0, $turnout['columns'][0]['rate']);
        // A percentage sits on a 0–100 axis, never one scaled to the data.
        $this->assertSame([100, 75, 50, 25, 0], $turnout['ticks']);
        // The monitoring line comes from the shared constant, not a literal.
        $this->assertSame(SchoolHeadOverview::FULL_TURNOUT_PERCENT, $turnout['full_turnout']);
    }

    #[Test]
    public function a_month_with_no_confirmed_mark_draws_nothing_rather_than_zero(): void
    {
        $learner = $this->makeLearner(['feeding_enrolled_at' => now()]);
        $this->mark($learner, now()->toDateString(), null);

        $turnout = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/reports')->assertOk()->viewData('turnout');

        $this->assertNull($turnout['average']);
        foreach ($turnout['columns'] as $column) {
            $this->assertNull($column['rate']);
        }
    }

    #[Test]
    public function approving_a_report_stamps_the_approver_and_writes_an_audit_entry(): void
    {
        $this->makeLearner();

        $this->withSession($this->headSession())
            ->post('/dashboard/school-head/reports/review', [
                'report' => 'baseline',
                'decision' => 'approve',
                'school_year' => StudentHealthRecord::currentSchoolYear(),
            ])
            ->assertRedirect();

        $review = ReportReview::first();
        $this->assertNotNull($review);
        $this->assertSame(ReportReview::STATUS_APPROVED, $review->status);
        $this->assertSame('Principal Reyes', $review->reviewed_by_name);
        $this->assertNotNull($review->reviewed_at);
        $this->assertSame($this->institution->id, (int) $review->institution_id);

        $this->assertDatabaseHas('audit_logs', ['action' => 'report_review_recorded']);
    }

    #[Test]
    public function the_reviewers_name_is_encrypted_at_rest(): void
    {
        $this->withSession($this->headSession())
            ->post('/dashboard/school-head/reports/review', [
                'report' => 'baseline',
                'decision' => 'approve',
                'school_year' => StudentHealthRecord::currentSchoolYear(),
            ])
            ->assertRedirect();

        $raw = DB::table('report_reviews')->value('reviewed_by_name');
        $this->assertNotSame('Principal Reyes', $raw);
        $this->assertSame('Principal Reyes', ReportReview::first()->reviewed_by_name);
    }

    #[Test]
    public function returning_a_report_requires_a_remark(): void
    {
        $this->withSession($this->headSession())
            ->post('/dashboard/school-head/reports/review', [
                'report' => 'baseline',
                'decision' => 'return',
                'school_year' => StudentHealthRecord::currentSchoolYear(),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('report_reviews', 0);
    }

    #[Test]
    public function a_locked_report_cannot_be_changed_by_a_direct_request(): void
    {
        $year = StudentHealthRecord::currentSchoolYear();

        $this->withSession($this->headSession())
            ->post('/dashboard/school-head/reports/review', [
                'report' => 'endline', 'decision' => 'lock', 'school_year' => $year,
            ])->assertRedirect();

        $this->assertSame(ReportReview::STATUS_LOCKED, ReportReview::first()->status);

        // The UI hides the buttons; the endpoint refuses regardless.
        $this->withSession($this->headSession())
            ->post('/dashboard/school-head/reports/review', [
                'report' => 'endline', 'decision' => 'approve', 'school_year' => $year,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(ReportReview::STATUS_LOCKED, ReportReview::first()->fresh()->status);
    }

    #[Test]
    public function a_report_key_that_does_not_exist_is_refused(): void
    {
        $this->withSession($this->headSession())
            ->post('/dashboard/school-head/reports/review', [
                'report' => 'monthly:1999-01',
                'decision' => 'approve',
                'school_year' => StudentHealthRecord::currentSchoolYear(),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('report_reviews', 0);
    }

    #[Test]
    public function every_report_exports_as_a_workbook(): void
    {
        $learner = $this->makeLearner(['feeding_enrolled_at' => now()]);
        $this->mark($learner, now()->toDateString(), true);

        foreach (['baseline', 'endline', 'packet', 'monthly:'.now()->format('Y-m')] as $report) {
            $this->withSession($this->headSession())
                ->get('/dashboard/school-head/reports/export?report='.urlencode($report))
                ->assertOk()
                ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
    }

    // ── Masterlist tab ──────────────────────────────────────────────────

    #[Test]
    public function the_masterlist_shows_only_this_schools_learners(): void
    {
        $this->makeLearner(['student_name' => 'Ours Only']);
        $this->makeLearner(['student_name' => 'Theirs Only'], $this->otherSchool);

        $response = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/masterlist')->assertOk();

        $names = collect($response->viewData('rows'))->pluck('name');
        $this->assertContains('Ours Only', $names);
        $this->assertNotContains('Theirs Only', $names);
        $this->assertSame(1, $response->viewData('total'));
    }

    #[Test]
    public function the_masterlist_filters_combine(): void
    {
        $this->makeLearner([
            'student_name' => 'Wanted Learner',
            'section' => 'Grade 7 / Rizal',
            'student_details' => ['gender' => 'Female'],
            'baseline_nutritional_status' => 'Wasted',
        ]);
        $this->makeLearner([
            'student_name' => 'Wrong Sex',
            'section' => 'Grade 7 / Rizal',
            'student_details' => ['gender' => 'M'],
            'baseline_nutritional_status' => 'Wasted',
        ]);
        $this->makeLearner([
            'student_name' => 'Wrong Grade',
            'section' => 'Grade 9 / Luna',
            'student_details' => ['gender' => 'Female'],
            'baseline_nutritional_status' => 'Wasted',
        ]);
        $this->makeLearner([
            'student_name' => 'Wrong Status',
            'section' => 'Grade 7 / Rizal',
            'student_details' => ['gender' => 'Female'],
            'baseline_nutritional_status' => 'Normal',
            'nutritional_status' => 'Normal',
        ]);

        $rows = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/masterlist?grade=Grade+7&sex=Female&baseline=Wasted')
            ->assertOk()
            ->viewData('rows');

        $this->assertCount(1, $rows);
        $this->assertSame('Wanted Learner', $rows[0]['name']);
    }

    #[Test]
    public function not_measured_is_a_real_filter_answer_and_never_reads_as_normal(): void
    {
        $this->makeLearner([
            'student_name' => 'Unweighed',
            'nutritional_status' => '',
            'baseline_nutritional_status' => '',
        ]);
        $this->makeLearner(['student_name' => 'Weighed']);

        $rows = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/masterlist?baseline=not_measured')
            ->assertOk()
            ->viewData('rows');

        $this->assertCount(1, $rows);
        $this->assertSame('Unweighed', $rows[0]['name']);
        $this->assertSame('', $rows[0]['baseline']);
        $this->assertSame('unknown', $rows[0]['movement']);
    }

    #[Test]
    public function a_learner_with_no_confirmed_session_has_no_attendance_rate(): void
    {
        $learner = $this->makeLearner(['feeding_enrolled_at' => now()]);
        $this->mark($learner, now()->toDateString(), null);

        $rows = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/masterlist')->assertOk()->viewData('rows');

        $this->assertNull($rows[0]['rate']);
        $this->assertSame('no_sessions', $rows[0]['attendance']);
    }

    #[Test]
    public function the_masterlist_has_no_edit_control_anywhere(): void
    {
        $this->makeLearner(['feeding_enrolled_at' => now()]);

        $html = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/masterlist')->assertOk()->getContent();

        // The only POST on the page is the rail's sign-out, which is the head's
        // own session rather than anything about a learner.
        $this->assertSame(
            1,
            substr_count($html, 'method="POST"'),
            'The masterlist gained a write form; measurements and enrolment belong to other roles.'
        );
        $this->assertStringContainsString(route('logout'), $html);

        // None of the endpoints that would change a learner's record.
        foreach (['enrollment', 'attendance/record', 'storeBaseline', 'storeEndline'] as $writePath) {
            $this->assertStringNotContainsString($writePath, $html);
        }
    }

    #[Test]
    public function the_masterlist_exports_the_filtered_list_as_a_workbook(): void
    {
        $this->makeLearner(['section' => 'Grade 7 / Rizal']);
        $this->makeLearner(['section' => 'Grade 9 / Luna']);

        $this->withSession($this->headSession())
            ->get('/dashboard/school-head/masterlist/export?grade=Grade+7')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    // ── The shared reading ──────────────────────────────────────────────

    #[Test]
    public function every_tab_reports_the_same_programme_size(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->makeLearner(['feeding_enrolled_at' => now()]);
        }
        // Qualified but not enrolled: on the waiting list, never a beneficiary.
        $this->makeLearner();

        $session = $this->headSession();

        $dashboard = $this->withSession($session)->get('/dashboard/school-head')
            ->assertOk()->viewData('stats')['beneficiaries'];
        $program = $this->withSession($session)->get('/dashboard/school-head/program')
            ->assertOk()->viewData('stats')['beneficiaries'];
        $reports = $this->withSession($session)->get('/dashboard/school-head/reports')
            ->assertOk()->viewData('outcome')['beneficiaries'];
        $masterlist = collect($this->withSession($session)->get('/dashboard/school-head/masterlist')
            ->assertOk()->viewData('rows'))->where('standing', 'beneficiary')->count();

        $this->assertSame(3, $dashboard);
        $this->assertSame(3, $program);
        $this->assertSame(3, $reports);
        $this->assertSame(3, $masterlist);
    }

    #[Test]
    public function senior_high_learners_are_never_beneficiaries(): void
    {
        // Grade 11 sits outside FeedingBeneficiarySummary::GRADE_LEVELS, so an
        // enrolment stamp on the row must still not make them a beneficiary.
        $this->makeLearner(['section' => 'Grade 11 / STEM', 'feeding_enrolled_at' => now()]);

        $this->assertSame(0, $this->withSession($this->headSession())
            ->get('/dashboard/school-head/program')->assertOk()->viewData('stats')['beneficiaries']);
    }

    #[Test]
    public function the_status_scale_never_silently_files_an_unknown_reading_under_normal(): void
    {
        $this->assertSame('Wasted', SchoolHeadOverview::toScale('Underweight'));
        $this->assertSame('Severely Wasted', SchoolHeadOverview::toScale('severely wasted'));
        $this->assertSame('Normal', SchoolHeadOverview::toScale('Normal'));
        $this->assertSame('', SchoolHeadOverview::toScale(''));
        $this->assertSame('', SchoolHeadOverview::toScale('Tall'));
    }

    #[Test]
    public function the_reading_tabs_are_closed_to_other_roles(): void
    {
        $session = [
            'active_role' => 'class_adviser',
            'active_username' => 'adviser.test',
            'active_institution_id' => $this->institution->id,
        ];

        foreach ([
            '/dashboard/school-head/program',
            '/dashboard/school-head/reports',
            '/dashboard/school-head/masterlist',
        ] as $url) {
            $this->withSession($session)->get($url)->assertRedirect();
        }
    }
}
