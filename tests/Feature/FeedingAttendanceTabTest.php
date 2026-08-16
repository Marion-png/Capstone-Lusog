<?php

namespace Tests\Feature;

use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the Feeding Coordinator's Attendance tab.
 *
 * The tab answers exactly one question — who → when → present or absent →
 * cumulative rate → at risk — and these tests hold it to that, including the
 * two things it must never do: treat an unmarked or unconfirmed learner as
 * absent, and duplicate the health data the Beneficiaries tab owns.
 */
class FeedingAttendanceTabTest extends TestCase
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

    private function makeStudent(array $attributes = []): StudentHealthRecord
    {
        return StudentHealthRecord::create(array_merge([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Learner '.random_int(1000, 9999),
            'student_id' => 'LRN'.random_int(100000, 999999),
            'school_name' => 'Test School',
            'section' => 'Grade 7 / Maabilidad',
            'weight' => 20.0,
            'bmi_value' => 13.5,
            'nutritional_status' => 'Wasted',
            'baseline_nutritional_status' => 'Wasted',
            'baseline_height_cm' => 118,
            'baseline_weight_kg' => 18.5,
            'baseline_bmi_value' => 13.3,
            'student_details' => ['gender' => 'Male', 'nutritional_status_height_for_age' => 'Stunted'],
            'feeding_enrolled_at' => now(),
        ], $attributes));
    }

    private function mark(StudentHealthRecord $record, string $date, ?bool $present, bool $needsReview = false, string $remarks = ''): void
    {
        FeedingAttendance::create([
            'student_health_record_id' => $record->id,
            'session_date' => $date,
            'is_present' => $present,
            'needs_review' => $needsReview,
            'remarks' => $remarks !== '' ? $remarks : null,
            'source' => 'manual_entry',
        ]);
    }

    private function open(string $query = '')
    {
        return $this->withSession($this->coordinatorSession())->get('/dashboard/feedingcor-attendance'.$query);
    }

    #[Test]
    public function the_header_carries_the_program_and_the_feeding_day(): void
    {
        $record = $this->makeStudent();
        $this->mark($record, now()->subDay()->toDateString(), true);

        $response = $this->open()->assertOk();

        $response->assertSee('SBFP <span>Feeding Attendance</span>', false);
        $response->assertSee('S.Y. '.str_replace('-', '&ndash;', StudentHealthRecord::currentSchoolYear()), false);
        $response->assertSee('Active Program:');
        $response->assertSee('School-Based Feeding Program');
        $response->assertSee('Feeding Day 2 of 120');
        $response->assertSee('Today: '.now()->format('F j, Y'));
        $response->assertSee('Record Today&rsquo;s Attendance', false);
        $response->assertSee('Export Attendance Sheet');
        $response->assertSee('Print Attendance Sheet');
        // History is a view on the rail, not a header action — one way in.
        $response->assertDontSee('View Attendance History');
        $response->assertSee('Attendance History');
    }

    #[Test]
    public function the_cards_report_the_sessions_own_figures_and_the_cumulative_rate(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $a = $this->makeStudent();
        $b = $this->makeStudent();
        $c = $this->makeStudent();

        // Yesterday: everyone present. Today: two of three.
        foreach ([$a, $b, $c] as $learner) {
            $this->mark($learner, $yesterday, true);
        }
        $this->mark($a, $today, true);
        $this->mark($b, $today, true);
        $this->mark($c, $today, false);

        $response = $this->open()->assertOk();

        $response->assertSee('Beneficiaries');
        $response->assertSee('Present Today');
        $response->assertSee('Absent Today');
        // Today's rate is 2/3; the programme's is 5/6 — two different figures,
        // and the card names which is which.
        $response->assertSee('66.7%');
        $response->assertSee('Cumulative: 83.3%');
    }

    #[Test]
    public function an_unmarked_beneficiary_is_never_counted_absent(): void
    {
        $a = $this->makeStudent();
        $this->makeStudent();
        $this->mark($a, now()->toDateString(), true);

        $response = $this->open()->assertOk();

        // One present, nobody absent, one still to record.
        $response->assertSee('Not yet recorded');
        $response->assertSee('1 not yet recorded');
        $response->assertSee('Attendance incomplete');
    }

    #[Test]
    public function a_session_nobody_recorded_says_so_instead_of_showing_a_blank_table(): void
    {
        $this->makeStudent();

        $this->open()->assertOk()->assertSee('Attendance has not been recorded for '.now()->format('F j, Y'));
    }

    #[Test]
    public function the_sheet_offers_a_toggle_and_bulk_marking(): void
    {
        $record = $this->makeStudent(['student_name' => 'John Dave A. Sumod-ong']);

        $response = $this->open()->assertOk();

        $response->assertSee('John Dave A. Sumod-ong');
        $response->assertSee('marks['.$record->id.']', false);
        $response->assertSee('Mark All Present');
        $response->assertSee('Save Attendance');
    }

    #[Test]
    public function saving_the_sheet_records_the_marks_and_returns_to_the_tab(): void
    {
        $present = $this->makeStudent();
        $absent = $this->makeStudent();
        $skipped = $this->makeStudent();
        $date = now()->toDateString();

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $date,
                'return_to' => 'attendance',
                'marks' => [$present->id => 'present', $absent->id => 'absent'],
                'remarks' => [$absent->id => 'Sick'],
            ])
            ->assertRedirect(route('dashboard.feedingcor-attendance', ['date' => $date]));

        // Who, when, present/absent, who recorded it, and when — the five facts
        // a feeding mark has to carry.
        $row = FeedingAttendance::query()->where('student_health_record_id', $absent->id)->first();
        $this->assertFalse((bool) $row->is_present);
        $this->assertSame('Sick', $row->remarks);
        $this->assertSame('Test Coordinator', $row->recorded_by_name);
        $this->assertNotNull($row->created_at);

        // A learner left unmarked writes no row at all — never an absence.
        $this->assertDatabaseCount('feeding_attendances', 2);
        $this->assertNull(FeedingAttendance::query()->where('student_health_record_id', $skipped->id)->first());
    }

    #[Test]
    public function a_date_outside_the_active_program_is_refused(): void
    {
        $record = $this->makeStudent();
        // The cycle starts at the first recorded session.
        $this->mark($record, now()->subDays(5)->toDateString(), true);

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => now()->subYear()->toDateString(),
                'return_to' => 'attendance',
                'marks' => [$record->id => 'present'],
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('feeding_attendances', 1);
    }

    /**
     * Once a learner's mark for a day is on file the sheet reports it and
     * offers no way to change it — no radio, no remark box. A disabled input
     * would still be a control the browser could re-enable and post; rendering
     * none at all cannot be.
     */
    #[Test]
    public function a_recorded_learner_is_read_only_on_the_sheet(): void
    {
        $recorded = $this->makeStudent(['student_name' => 'Already Recorded']);
        $open = $this->makeStudent(['student_name' => 'Not Yet Recorded']);
        $this->mark($recorded, now()->toDateString(), false, remarks: 'Sick');

        $response = $this->open()->assertOk();

        $response->assertDontSee('marks['.$recorded->id.']', false);
        $response->assertDontSee('remarks['.$recorded->id.']', false);
        $response->assertSee('Sick');
        $response->assertSee('already recorded for '.now()->format('F j, Y'));

        // The learner nobody has marked is still open, and so is the save bar.
        $response->assertSee('marks['.$open->id.']', false);
        $response->assertSee('Save Attendance');
    }

    #[Test]
    public function a_fully_recorded_session_offers_nothing_to_save(): void
    {
        $learner = $this->makeStudent();
        $this->mark($learner, now()->toDateString(), true);

        $response = $this->open()->assertOk();

        $response->assertDontSee('Save Attendance');
        // The button, not the phrase — the page's own script mentions it in a
        // comment, and that is not a control.
        $response->assertDontSee('data-mark-all="present"', false);
    }

    /**
     * An unconfirmed scanned mark is not a recorded one: nobody has read it,
     * and recording on site is exactly how it gets decided.
     */
    #[Test]
    public function an_unconfirmed_scanned_mark_stays_editable(): void
    {
        $learner = $this->makeStudent();
        $this->mark($learner, now()->toDateString(), null, needsReview: true);

        $response = $this->open()->assertOk();

        $response->assertSee('marks['.$learner->id.']', false);
        $response->assertSee('Unconfirmed');
    }

    #[Test]
    public function the_filters_narrow_the_sheet(): void
    {
        $this->makeStudent(['student_name' => 'Grade Seven Learner', 'section' => 'Grade 7 / Maabilidad']);
        $this->makeStudent(['student_name' => 'Grade Eight Learner', 'section' => 'Grade 8 / Matiyaga']);

        $response = $this->open('?grade='.urlencode('Grade 8'))->assertOk();

        $response->assertSee('Grade Eight Learner');
        $response->assertDontSee('Grade Seven Learner');
    }

    #[Test]
    public function the_attendance_filter_reads_this_sessions_marks(): void
    {
        $present = $this->makeStudent(['student_name' => 'Present Learner']);
        $absent = $this->makeStudent(['student_name' => 'Absent Learner']);
        $this->mark($present, now()->toDateString(), true);
        $this->mark($absent, now()->toDateString(), false);

        $response = $this->open('?status=absent')->assertOk();

        $response->assertSee('Absent Learner');
        $response->assertDontSee('Present Learner');
    }

    #[Test]
    public function the_history_view_lists_every_feeding_day(): void
    {
        $record = $this->makeStudent();
        $this->mark($record, now()->subDays(2)->toDateString(), true);
        $this->mark($record, now()->subDay()->toDateString(), false);

        $response = $this->open('?view=history')->assertOk();

        $response->assertSee('Feeding Day');
        $response->assertSee(now()->subDays(2)->format('M j, Y'));
        $response->assertSee(now()->subDay()->format('M j, Y'));
        $response->assertSee('100.0%');
        $response->assertSee('0.0%');
    }

    /**
     * A run of feeding days for one learner, present on the most recent
     * `$presentDays` of them.
     *
     * The threshold tests below run twelve days deep on purpose: that is past
     * the default ten-day observation window, so the threshold is actually
     * classifying. Four sessions would leave everyone in Early Monitoring,
     * which is its own test.
     */
    private function markRun(StudentHealthRecord $record, int $days, int $presentDays): void
    {
        foreach (range(1, $days) as $offset) {
            $this->mark($record, now()->subDays($offset)->toDateString(), $offset <= $presentDays);
        }
    }

    #[Test]
    public function the_by_beneficiary_view_applies_the_schools_threshold(): void
    {
        $good = $this->makeStudent(['student_name' => 'Reliable Learner']);
        $risky = $this->makeStudent(['student_name' => 'Missing Learner']);

        $this->markRun($good, 12, 12);
        $this->markRun($risky, 12, 3);   // 3 of 12 = 25%

        $response = $this->open('?view=beneficiary')->assertOk();

        $response->assertSee('Reliable Learner');
        $response->assertSee('Missing Learner');
        $response->assertSee('At Risk');
        $response->assertSee('Good');
        $response->assertSee('25.0%');
        // The threshold is the school's own, never written into the page.
        $response->assertSee('below the 80% attendance threshold');

        $this->institution->update(['feeding_at_risk_threshold' => 20]);
        $this->open('?view=beneficiary')->assertOk()->assertDontSee('attendance threshold');
    }

    #[Test]
    public function a_learner_inside_the_observation_window_is_not_flagged(): void
    {
        // Four feeding days in, 1 of 4 attended. The rate is 25% and fails the
        // 80% threshold on arithmetic alone — but four recorded sessions is not
        // enough history to put a child on a follow-up list, so the tab reports
        // them as unclassified rather than at risk.
        $learner = $this->makeStudent(['student_name' => 'Early Learner']);
        $this->markRun($learner, 4, 1);

        $response = $this->open('?view=beneficiary')->assertOk();

        $response->assertSee('Early Learner');
        $response->assertSee('Early Monitoring');
        // No at-risk badge on the row, and nothing counted by the figure. (The
        // words "At Risk" still appear as the card label and the filter option,
        // so the row itself is what this asserts.)
        $response->assertDontSee('badge badge-risk', false);
        $this->assertSame(0, $response->viewData('atRisk')['count']);
        $this->assertFalse($response->viewData('beneficiaryRows')[0]['at_risk']);

        // The window suppresses the verdict, never the figure.
        $response->assertSee('25.0%');
        // And the coordinator is told why nobody is flagged, in its own notice.
        $response->assertSee('currently under observation');
        $response->assertSee('At-risk classification begins after 10 recorded feeding days');
        $response->assertDontSee('below the 80% attendance threshold');
    }

    #[Test]
    public function the_observation_window_is_the_schools_own_setting(): void
    {
        // The same learner, at a school that classifies from the third session.
        $this->institution->update(['feeding_min_observation_days' => 3]);

        $learner = $this->makeStudent(['student_name' => 'Early Learner']);
        $this->markRun($learner, 4, 1);

        $response = $this->open('?view=beneficiary')->assertOk();

        $response->assertSee('badge badge-risk', false);
        $response->assertDontSee('currently under observation');
        $this->assertSame(1, $response->viewData('atRisk')['count']);
    }

    #[Test]
    public function the_early_monitoring_filter_shows_only_unclassified_beneficiaries(): void
    {
        $settled = $this->makeStudent(['student_name' => 'Settled Learner']);
        $newcomer = $this->makeStudent(['student_name' => 'Newly Enrolled']);

        $this->markRun($settled, 12, 12);
        $this->markRun($newcomer, 3, 3);

        $response = $this->open('?view=beneficiary&status=early_monitoring')->assertOk();

        $response->assertSee('Newly Enrolled');
        $response->assertDontSee('Settled Learner');
    }

    #[Test]
    public function a_feeding_day_nobody_marked_a_learner_on_is_reported_as_missing_not_absent(): void
    {
        $covered = $this->makeStudent(['student_name' => 'Covered Learner']);
        $skipped = $this->makeStudent(['student_name' => 'Skipped Learner']);

        // The school held twelve feeding days; one learner's sheets only
        // covered three of them. The other nine are a filing gap, and folding
        // them into absences would flag a child nobody observed missing.
        $this->markRun($covered, 12, 12);
        $this->markRun($skipped, 3, 3);

        $rows = collect($this->open('?view=beneficiary')->assertOk()->viewData('beneficiaryRows'))
            ->keyBy('name');

        $this->assertSame(0, $rows['Skipped Learner']['absent']);
        $this->assertSame(9, $rows['Skipped Learner']['not_marked']);
        $this->assertSame(100.0, $rows['Skipped Learner']['rate']);
        $this->assertFalse($rows['Skipped Learner']['at_risk']);
        $this->assertSame(0, $rows['Covered Learner']['not_marked']);
    }

    #[Test]
    public function the_at_risk_filter_shows_only_flagged_beneficiaries(): void
    {
        $good = $this->makeStudent(['student_name' => 'Reliable Learner']);
        $risky = $this->makeStudent(['student_name' => 'Missing Learner']);

        $this->markRun($good, 12, 12);
        $this->markRun($risky, 12, 0);

        $response = $this->open('?view=beneficiary&status=at_risk')->assertOk();

        $response->assertSee('Missing Learner');
        $response->assertDontSee('Reliable Learner');
    }

    #[Test]
    public function the_calendar_marks_complete_and_incomplete_feeding_days(): void
    {
        $a = $this->makeStudent();
        $b = $this->makeStudent();
        $complete = now()->subDays(2);
        $partial = now()->subDay();

        $this->mark($a, $complete->toDateString(), true);
        $this->mark($b, $complete->toDateString(), true);
        $this->mark($a, $partial->toDateString(), true);

        $response = $this->open('?view=calendar')->assertOk();

        $response->assertSee(now()->format('F Y'));
        $response->assertSee('fa-day-complete', false);
        $response->assertSee('fa-day-partial', false);
    }

    #[Test]
    public function the_tab_carries_no_health_profile_data(): void
    {
        $this->makeStudent(['student_name' => 'John Dave A. Sumod-ong']);

        $html = $this->open()->assertOk()->getContent();

        // Only what the coordinator reads: the inlined stylesheet and scripts
        // are full of words like "font-weight" that say nothing about the page.
        $visible = preg_replace('#<(style|script)\b[^>]*>.*?</\1>#is', '', $html) ?? '';

        // Baseline, BMI, height-for-age and endline belong to the Beneficiaries
        // tab. Two tabs owning the same data is how they start disagreeing.
        foreach (['Baseline', 'BMI', 'HFA', 'Endline', 'Height', 'Weight', 'Nutritional Status'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $visible, 'Attendance shows '.$forbidden);
        }
    }

    #[Test]
    public function the_export_produces_the_schools_xlsx_template(): void
    {
        $record = $this->makeStudent(['student_name' => 'John Dave A. Sumod-ong']);
        $this->mark($record, now()->subDay()->toDateString(), true);
        $this->mark($record, now()->toDateString(), false);

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-attendance/export')
            ->assertOk();

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('content-type')
        );
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('content-disposition'));
    }

    #[Test]
    public function the_metrics_endpoint_returns_the_same_partials_the_page_rendered(): void
    {
        $record = $this->makeStudent();
        $this->mark($record, now()->toDateString(), true);

        $payload = $this->withSession($this->coordinatorSession())
            ->getJson('/dashboard/feedingcor-attendance/metrics')
            ->assertOk()
            ->json();

        foreach (['cards', 'notices', 'history', 'beneficiary', 'calendar'] as $pane) {
            $this->assertArrayHasKey($pane, $payload['html']);
        }
        // The sheet holds unsaved marks, so it is never live-replaced.
        $this->assertArrayNotHasKey('sheet', $payload['html']);
    }

    #[Test]
    public function live_refreshed_panels_link_back_to_the_page_not_the_json_endpoint(): void
    {
        $record = $this->makeStudent();
        $this->mark($record, now()->toDateString(), false);

        $payload = $this->withSession($this->coordinatorSession())
            ->getJson('/dashboard/feedingcor-attendance/metrics?view=history')
            ->assertOk()
            ->json();

        $html = implode('', $payload['html']);

        // These partials are rendered by two endpoints. A link built from the
        // current URL would point a date or a tab at the metrics JSON.
        $this->assertStringNotContainsString('/metrics', $html);
        $this->assertStringContainsString(route('dashboard.feedingcor-attendance'), $html);
    }

    #[Test]
    public function another_schools_beneficiaries_are_never_listed(): void
    {
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        StudentHealthRecord::create([
            'institution_id' => $other->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Other School Learner',
            'student_id' => 'LRN999999',
            'school_name' => 'Other School',
            'section' => 'Grade 7 / Sampaguita',
            'weight' => 20.0,
            'bmi_value' => 13.5,
            'nutritional_status' => 'Wasted',
            'feeding_enrolled_at' => now(),
        ]);

        $this->open()->assertOk()->assertDontSee('Other School Learner');
    }

    #[Test]
    public function only_the_feeding_coordinator_may_open_the_tab(): void
    {
        $this->withSession([
            'active_role' => 'class_adviser',
            'active_name' => 'Test Adviser',
            'active_institution_id' => $this->institution->id,
        ])
            ->get('/dashboard/feedingcor-attendance')
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function the_sidebar_carries_the_attendance_tab(): void
    {
        $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->assertSee(route('dashboard.feedingcor-attendance'), false);
    }
}
