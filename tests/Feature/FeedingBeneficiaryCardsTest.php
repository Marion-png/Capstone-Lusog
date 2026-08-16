<?php

namespace Tests\Feature;

use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the Feeding Coordinator's Beneficiaries tab: the SBFP header, the five
 * headline cards, and the promise that every figure on them is derived from the
 * data rather than stored or hand-entered.
 *
 * The cards read App\Support\FeedingBeneficiarySummary, which the Dashboard
 * reads too — so the last test here is the one that matters most: the two tabs
 * must never report a different programme.
 */
class FeedingBeneficiaryCardsTest extends TestCase
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

    private function makeStudent(
        string $section,
        string $status,
        bool $enrolled = true,
        string $sex = 'Male',
        ?string $endline = null,
        ?string $schoolYear = null,
        ?string $name = null,
    ): StudentHealthRecord {
        return StudentHealthRecord::create([
            'institution_id' => $this->institution->id,
            'school_year' => $schoolYear ?? StudentHealthRecord::currentSchoolYear(),
            'student_name' => $name ?? 'Learner '.random_int(1000, 9999),
            'student_id' => 'LRN'.random_int(100000, 999999),
            'school_name' => 'Test School',
            'section' => $section,
            'weight' => 30,
            'bmi_value' => 15.0,
            'nutritional_status' => $status,
            'baseline_nutritional_status' => $status,
            'endline_nutritional_status' => $endline,
            'student_details' => ['gender' => $sex],
            'feeding_enrolled_at' => $enrolled ? now() : null,
        ]);
    }

    /**
     * The table body alone. "Active Program: School-Based Feeding Program" sits
     * in the page header, so a bare assertDontSee('Active') would always fail —
     * status assertions have to look at the rows.
     */
    private function tableBody(string $query = ''): string
    {
        $html = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records'.$query)
            ->assertOk()
            ->getContent();

        return substr($html, strpos($html, '<tbody>'), strpos($html, '</tbody>') - strpos($html, '<tbody>'));
    }

    /** @return list<string> */
    private function namesShown(string $query): array
    {
        $rows = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records'.$query)
            ->assertOk()
            ->viewData('records');

        return collect($rows)->pluck('student_name')->sort()->values()->all();
    }

    private function markAttendance(StudentHealthRecord $record, string $date, ?bool $isPresent, bool $needsReview = false): void
    {
        FeedingAttendance::create([
            'student_health_record_id' => $record->id,
            'session_date' => $date,
            'is_present' => $isPresent,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'source' => $needsReview ? FeedingAttendance::SOURCE_PHOTO_SCAN : FeedingAttendance::SOURCE_SPREADSHEET,
            'needs_review' => $needsReview,
        ]);
    }

    /** The header names the programme in the shared two-voice title style. */
    #[Test]
    public function the_header_carries_the_programme_and_the_school_year(): void
    {
        $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records')
            ->assertOk()
            ->assertSee('<h1 class="page-title">SBFP <span>Beneficiaries</span></h1>', false)
            ->assertSee('Active Program:')
            ->assertSee('School-Based Feeding Program')
            ->assertSee('S.Y.');
    }

    /**
     * The five cards, and the numbers behind them. Underweight is folded into
     * Wasted exactly as the DepEd sheets do, so the two status cards plus the
     * rest of the scale always sum to the beneficiary total.
     */
    #[Test]
    public function the_five_cards_count_the_enrolled_roll_by_status_attendance_and_risk(): void
    {
        $severe = $this->makeStudent('Grade 7 / Sampaguita', 'Severely Wasted');
        $wasted = $this->makeStudent('Grade 8 / Rosal', 'Wasted');
        $this->makeStudent('Grade 9 / Ilang', 'Underweight');           // counted as Wasted
        $this->makeStudent('Grade 10 / Narra', 'Normal');               // enrolled but not qualified
        $this->makeStudent('Grade 7 / Rosal', 'Wasted', enrolled: false); // waiting, not a beneficiary

        // Twelve sessions — past the rule's ten-day observation window, so the
        // threshold is actually classifying. One learner turns up for eight of
        // them (67%), the other never.
        foreach (range(1, 12) as $day) {
            $this->markAttendance($severe, now()->subDays(13 - $day)->toDateString(), $day <= 8);
            $this->markAttendance($wasted, now()->subDays(13 - $day)->toDateString(), false);
        }

        $summary = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records')
            ->assertOk()
            ->assertSee('Total Beneficiaries')
            ->assertSee('Severely Wasted')
            ->assertSee('Wasted')
            ->assertSee('At Risk')
            ->assertSee('Currently Attending')
            ->viewData('beneficiarySummary');

        $this->assertSame(3, $summary['beneficiaries'], 'Only qualified AND enrolled learners are beneficiaries.');
        $this->assertSame(1, $summary['severely_wasted']);
        $this->assertSame(2, $summary['wasted'], 'Underweight is folded into Wasted.');
        $this->assertSame(24, $summary['attendance_sessions']);
        $this->assertSame(33, $summary['attendance_rate'], '8 present of 24 confirmed marks.');
        $this->assertSame(2, $summary['at_risk'], 'Both fed learners are under the 80% threshold.');
    }

    /**
     * The unconfirmed-mark invariant: a scanned mark nobody has reviewed is
     * NULL, and NULL votes neither way. Counting it as an absence would report
     * a turnout no human has read.
     */
    #[Test]
    public function an_unconfirmed_mark_changes_neither_the_rate_nor_the_session_count(): void
    {
        $learner = $this->makeStudent('Grade 7 / Sampaguita', 'Wasted');
        $this->markAttendance($learner, now()->subDays(2)->toDateString(), true);
        $this->markAttendance($learner, now()->subDay()->toDateString(), null, needsReview: true);

        $summary = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records')
            ->assertOk()
            ->viewData('beneficiarySummary');

        $this->assertSame(1, $summary['attendance_sessions']);
        $this->assertSame(100, $summary['attendance_rate']);
    }

    /** No confirmed session is not a 0% turnout — there is nothing to report. */
    #[Test]
    public function attendance_reads_as_nothing_rather_than_zero_before_the_first_session(): void
    {
        $this->makeStudent('Grade 7 / Sampaguita', 'Wasted');

        $summary = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records')
            ->assertOk()
            ->assertSee('—')
            ->viewData('beneficiarySummary');

        $this->assertNull($summary['attendance_rate']);
    }

    /** The cards follow the tab's own grade and section filter. */
    #[Test]
    public function the_grade_and_section_filter_scopes_the_cards(): void
    {
        $this->makeStudent('Grade 7 / Sampaguita', 'Severely Wasted');
        $this->makeStudent('Grade 7 / Rosal', 'Wasted');
        $this->makeStudent('Grade 8 / Ilang', 'Wasted');

        $summary = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records?grade_level=Grade+7&section=Rosal')
            ->assertOk()
            ->viewData('beneficiarySummary');

        $this->assertSame(1, $summary['beneficiaries']);
        $this->assertSame(0, $summary['severely_wasted']);
        $this->assertSame(1, $summary['wasted']);
    }

    /** Another school's learners are never counted. */
    #[Test]
    public function the_cards_are_scoped_to_the_coordinators_school(): void
    {
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $this->makeStudent('Grade 7 / Sampaguita', 'Wasted');
        $this->makeStudent('Grade 7 / Rosal', 'Wasted')->update(['institution_id' => $other->id]);

        $summary = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records')
            ->assertOk()
            ->viewData('beneficiarySummary');

        $this->assertSame(1, $summary['beneficiaries']);
    }

    /**
     * The cards are re-read live from the same partial the first paint used,
     * and the refresh carries the tab's own filter.
     */
    #[Test]
    public function the_live_endpoint_returns_the_same_cards_and_honours_the_filter(): void
    {
        $this->makeStudent('Grade 7 / Sampaguita', 'Severely Wasted');
        $this->makeStudent('Grade 8 / Rosal', 'Wasted');

        $payload = $this->withSession($this->coordinatorSession())
            ->getJson('/dashboard/feedingcor-health-records/cards?grade_level=Grade+7')
            ->assertOk()
            ->json();

        $this->assertNotEmpty($payload['stamp']);
        $this->assertStringContainsString('Total Beneficiaries', $payload['html']['cards']);
        $this->assertStringContainsString('Currently Attending', $payload['html']['cards']);
        // One learner in Grade 7, and they are the severely wasted one.
        $this->assertMatchesRegularExpression('/Total Beneficiaries.*?kpi-value">1</s', $payload['html']['cards']);

        // The tabs travel with the cards, counting the same filtered scope.
        $this->assertStringContainsString('All beneficiaries', $payload['html']['tabs']);
        $this->assertMatchesRegularExpression('/All beneficiaries.*?seg-tab-count">1</s', $payload['html']['tabs']);
    }

    /** No other role may read the cards endpoint. */
    #[Test]
    public function the_live_endpoint_is_closed_to_other_roles(): void
    {
        $this->withSession(['active_role' => 'class_adviser', 'active_institution_id' => $this->institution->id])
            ->getJson('/dashboard/feedingcor-health-records/cards')
            ->assertForbidden();
    }

    /** The tab's three primary actions, with enrolment leading. */
    #[Test]
    public function the_tab_offers_enrol_export_and_print(): void
    {
        $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records')
            ->assertOk()
            ->assertSee('Enroll Beneficiaries')
            ->assertSee('Export Masterlist')
            ->assertSee('Print Masterlist')
            ->assertSee('id="exportMasterlistBtn"', false)
            ->assertSee('id="printMasterlistBtn"', false);
    }

    /**
     * The masterlist leaves as the school's own DepEd form in a real workbook —
     * not a comma-separated dump of the table. A .xlsx is a spreadsheet by
     * format, so it opens in Excel wherever it lands; which program opens a
     * .csv is a setting on the reader's own machine.
     */
    #[Test]
    public function the_masterlist_exports_as_the_deped_form_in_a_workbook(): void
    {
        $learner = $this->makeStudent('Grade 7 / Sampaguita', 'Wasted', name: 'Maria Clara Santos');

        $response = $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-health-records/masterlist', ['record_ids' => [$learner->id]])
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // The workbook holds the form's heading, its column heads and the
        // learner — read out of the sheet XML inside the .xlsx.
        $sheet = $this->readSheetXml($response->streamedContent());

        $this->assertStringContainsString('Masterlists of Identified Severely Wasted and Wasted Students', $sheet);
        $this->assertStringContainsString('Test School', $sheet);
        $this->assertStringContainsString('Maria Clara Santos', $sheet);
        $this->assertStringContainsString('Sampaguita', $sheet);
        $this->assertStringContainsString('Prepared by:', $sheet);
        $this->assertStringContainsString('Noted by:', $sheet);
    }

    /** Ids off the wire decide nothing: another school's learner never lands in the file. */
    #[Test]
    public function the_masterlist_export_never_carries_another_schools_learner(): void
    {
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $outsider = StudentHealthRecord::create([
            'institution_id' => $other->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Outsider Learner',
            'student_id' => 'LRN999999',
            'school_name' => 'Other School',
            'section' => 'Grade 7 / Sampaguita',
            'weight' => 30,
            'bmi_value' => 15.0,
            'nutritional_status' => 'Wasted',
            'student_details' => ['gender' => 'Male'],
            'feeding_enrolled_at' => now(),
        ]);

        $response = $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-health-records/masterlist', ['record_ids' => [$outsider->id]])
            ->assertOk();

        $this->assertStringNotContainsString('Outsider Learner', $this->readSheetXml($response->streamedContent()));
    }

    #[Test]
    public function only_the_feeding_coordinator_may_export_the_masterlist(): void
    {
        $this->withSession(['active_role' => 'class_adviser', 'active_institution_id' => $this->institution->id])
            ->post('/dashboard/feedingcor-health-records/masterlist', ['record_ids' => []])
            ->assertRedirect(route('login'));
    }

    /** The first worksheet's XML, out of the .xlsx zip. */
    private function readSheetXml(string $binary): string
    {
        $path = tempnam(sys_get_temp_dir(), 'masterlist-test-');
        file_put_contents($path, $binary);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The export is a real .xlsx archive.');
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        return $xml;
    }

    /**
     * Enrolling from this tab is the very dialog the Dashboard opens — one
     * partial, one script, one set of endpoints. A second copy would be a
     * second thing to keep in step, and enrolment is not a place to discover
     * that two screens drifted.
     */
    #[Test]
    public function the_enrolment_dialog_is_the_same_one_the_dashboard_opens(): void
    {
        $session = $this->coordinatorSession();

        $tab = $this->withSession($session)->get('/dashboard/feedingcor-health-records')->assertOk();
        $dashboard = $this->withSession($session)->get('/dashboard/feedingcor-dashboard')->assertOk();

        foreach ([$tab, $dashboard] as $response) {
            $response
                ->assertSee('id="enrollBackdrop"', false)
                ->assertSee('data-enroll-open', false)
                ->assertSee('Qualified learners')
                ->assertSee('Enroll selected')
                // The endpoints live on the dialog, not on whichever button
                // opened it, so both pages post to the same place.
                ->assertSee(route('feedingcor-program.enrollment.candidates'), false)
                ->assertSee(route('feedingcor-program.enrollment.store'), false);
        }
    }

    /**
     * The three views under the cards, each carrying its own size — and each
     * count matching the rows that view actually lists. A tab that promised a
     * number the table then failed to show would be worse than no tab.
     */
    #[Test]
    public function the_view_tabs_count_exactly_what_each_view_lists(): void
    {
        $flagged = $this->makeStudent('Grade 7 / Sampaguita', 'Severely Wasted');
        $this->makeStudent('Grade 8 / Rosal', 'Wasted');                    // enrolled, never fed
        $this->makeStudent('Grade 9 / Ilang', 'Wasted', enrolled: false);   // waiting
        $this->makeStudent('Grade 9 / Narra', 'Underweight', enrolled: false);
        $this->makeStudent('Grade 10 / Acacia', 'Normal');                  // neither

        // Twelve sessions, three attended: 25%, and deep enough into the
        // programme for the threshold to have classified them.
        foreach (range(1, 12) as $day) {
            $this->markAttendance($flagged, now()->subDays(13 - $day)->toDateString(), $day <= 3);
        }

        $expected = ['all' => 2, 'pending' => 2, 'at_risk' => 1];

        $counts = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records')
            ->assertOk()
            ->assertSee('All beneficiaries')
            ->assertSee('Pending enrollment')
            ->assertSee('At risk')
            ->viewData('segmentCounts');

        $this->assertSame($expected, $counts);

        // Every tab lists exactly what it counted.
        foreach ($expected as $view => $count) {
            $rows = $this->withSession($this->coordinatorSession())
                ->get('/dashboard/feedingcor-health-records?view='.$view)
                ->assertOk()
                ->viewData('records');

            $this->assertCount($count, $rows, "The {$view} view lists what its tab counts.");
        }
    }

    /** An unknown view falls back to the roll rather than emptying the page. */
    #[Test]
    public function the_default_view_is_the_enrolled_roll(): void
    {
        $this->makeStudent('Grade 7 / Sampaguita', 'Wasted');
        $this->makeStudent('Grade 8 / Rosal', 'Wasted', enrolled: false);

        foreach (['', '?view=nonsense'] as $query) {
            $response = $this->withSession($this->coordinatorSession())
                ->get('/dashboard/feedingcor-health-records'.$query)
                ->assertOk();

            $this->assertSame('all', $response->viewData('segmentView'));
            $this->assertCount(1, $response->viewData('records'));
        }
    }

    /** Switching views keeps the grade and section already chosen. */
    #[Test]
    public function the_tabs_and_the_filters_keep_each_others_choices(): void
    {
        $this->makeStudent('Grade 7 / Sampaguita', 'Wasted');
        $this->makeStudent('Grade 8 / Rosal', 'Wasted', enrolled: false);
        $this->makeStudent('Grade 8 / Ilang', 'Wasted', enrolled: false);

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records?view=pending&grade_level=Grade+8')
            ->assertOk();

        $this->assertSame('pending', $response->viewData('segmentView'));
        $this->assertCount(2, $response->viewData('records'));
        // The tabs carry the grade forward, and the filter form carries the view.
        $response->assertSee('grade_level=Grade+8', false)
            ->assertSee('<input type="hidden" name="view" value="pending">', false);
    }

    /**
     * The table is the coordinator's working list: who the learner is, why
     * they qualify, how they are turning up, and where they stand. The BMI
     * figures and the baseline-to-endline delta are gone on purpose — that is
     * a health profile, and this screen is not where it belongs.
     */
    #[Test]
    public function the_table_carries_the_working_columns_and_no_medical_detail(): void
    {
        $learner = $this->makeStudent('Grade 7 / Maabilidad', 'Severely Wasted', sex: 'Male', name: 'John Dave A. Sumod-ong');

        foreach ([true, true, true, false] as $index => $isPresent) {
            $this->markAttendance($learner, now()->subDays(4 - $index)->toDateString(), $isPresent);
        }

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records')
            ->assertOk();

        $response
            ->assertSee('John Dave A. Sumod-ong')
            ->assertSee('<td>7</td>', false)           // grade, without repeating the word
            ->assertSee('<td>Maabilidad</td>', false)
            ->assertSee('<td>M</td>', false)           // sex
            ->assertSee('Severely Wasted')             // the reason they qualify
            ->assertSee('75%')                         // 3 present of 4 confirmed
            ->assertSee('Active');

        // The health profile is the nurse's screen, not this one.
        $response
            ->assertDontSee('Baseline BMI')
            ->assertDontSee('Endline BMI')
            ->assertDontSee('Status Change');
    }

    /** The flag and the figure beside it are one reading of one set of marks. */
    #[Test]
    public function an_at_risk_learner_is_marked_and_shows_the_rate_that_flagged_them(): void
    {
        $learner = $this->makeStudent('Grade 7 / Maabilidad', 'Wasted', name: 'Jeb Sean Cachuela');

        // Six of twelve: well under the 80% threshold, and past the rule's
        // observation window so the shortfall is a verdict.
        foreach (range(1, 12) as $day) {
            $this->markAttendance($learner, now()->subDays(13 - $day)->toDateString(), $day % 2 === 0);
        }

        $body = $this->tableBody();

        $this->assertStringContainsString('At Risk', $body);
        $this->assertStringContainsString('50%', $body, 'The rate printed is the one that decided the flag.');
        $this->assertStringNotContainsString('Active', $body);
    }

    /**
     * The Pending Enrollment tab is the waiting list with the decision on it:
     * who is waiting, why they qualify, and the action that enrols them. There
     * is no Status column on purpose — a learner nobody has enrolled has no
     * attendance standing to report.
     */
    #[Test]
    public function the_pending_tab_is_a_waiting_list_with_the_enrol_action_on_it(): void
    {
        $waiting = $this->makeStudent('Grade 8 / Maaasahan', 'Wasted', enrolled: false, name: 'Student B');

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records?view=pending')
            ->assertOk();

        $response
            ->assertSee('Pending Enrollment')
            ->assertSee('Baseline Status')
            ->assertSee('Student B')
            ->assertSee('<td>8</td>', false)
            ->assertSee('<td>Maaasahan</td>', false)
            ->assertSee('Wasted')
            // The row's own action, and the bulk one above the table.
            ->assertSee('data-enroll="'.$waiting->id.'"', false)
            ->assertSee('data-select="'.$waiting->id.'"', false)
            ->assertSee('Enroll selected')
            // It posts where the dialog posts: one path into the database.
            ->assertSee(route('feedingcor-program.enrollment.store'), false);

        $body = $this->tableBody('?view=pending');
        $this->assertStringNotContainsString('Active', $body);
        $this->assertStringNotContainsString('At Risk', $body, 'A learner nobody enrolled is not at risk of anything yet.');
    }

    /** The other two views keep the roll, with no enrol controls on it. */
    #[Test]
    public function the_enrol_controls_belong_to_the_pending_tab_alone(): void
    {
        $this->makeStudent('Grade 7 / Maabilidad', 'Wasted', name: 'Enrolled Learner');
        $this->makeStudent('Grade 8 / Maaasahan', 'Wasted', enrolled: false, name: 'Student B');

        // Ids, not class names: the page inlines its stylesheet, so every
        // class it defines appears in the source whichever view is rendered.
        // The enrolment dialog is on every view and has its own "Enroll
        // selected" too, so the markers here are the tab's own controls.
        // The heading names the view, so each carries its own.
        $titles = [
            '' => 'Per Beneficiary Comparison',
            '?view=all' => 'Per Beneficiary Comparison',
            '?view=at_risk' => 'Attendance At Risk',
        ];

        foreach ($titles as $query => $title) {
            $this->withSession($this->coordinatorSession())
                ->get('/dashboard/feedingcor-health-records'.$query)
                ->assertOk()
                ->assertSee($title)
                ->assertSee('<th class="bnf-idx">#</th>', false)
                ->assertDontSee('id="pendingBulk"', false)
                ->assertDontSee('id="pendingCheckAll"', false);
        }

        $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records?view=pending')
            ->assertOk()
            ->assertSee('id="pendingBulk"', false)
            ->assertSee('id="pendingCheckAll"', false)
            ->assertDontSee('<th class="bnf-idx">#</th>', false);
    }

    /** All eight filters are offered, each with its own options. */
    #[Test]
    public function the_filter_bar_offers_all_eight_filters(): void
    {
        $this->makeStudent('Grade 7 / Sampaguita', 'Wasted');

        $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records')
            ->assertOk()
            ->assertSee('School Year')
            ->assertSee('Grade Level')
            ->assertSee('Section')
            ->assertSee('Sex')
            ->assertSee('Baseline Nutritional Status')
            ->assertSee('Attendance Status')
            ->assertSee('Beneficiary Status')
            ->assertSee('Endline Status')
            ->assertSee('Not yet measured');
    }

    /**
     * The five narrowing filters, each on its own. Every one reads a column
     * that is encrypted at rest, so all of them are applied in PHP after fetch
     * — a WHERE on any of these would match nothing.
     */
    #[Test]
    public function each_narrowing_filter_keeps_only_the_learners_it_names(): void
    {
        $this->makeStudent('Grade 7 / Sampaguita', 'Severely Wasted', sex: 'Male', endline: 'Normal', name: 'Ada');
        $this->makeStudent('Grade 7 / Rosal', 'Wasted', sex: 'Female', endline: null, name: 'Bea');
        $this->makeStudent('Grade 8 / Ilang', 'Wasted', enrolled: false, sex: 'Female', name: 'Cid');

        $this->assertSame(['Bea'], $this->namesShown('?sex=Female'));
        $this->assertSame(['Ada'], $this->namesShown('?baseline_status=Severely+Wasted'));
        $this->assertSame(['Ada'], $this->namesShown('?endline_status=Normal'));
        $this->assertSame(['Bea'], $this->namesShown('?endline_status=not_measured'));
        $this->assertSame(['Ada', 'Bea'], $this->namesShown('?beneficiary_status=enrolled'));
        // Beneficiary Status composes with the view rather than fighting it.
        $this->assertSame(['Cid'], $this->namesShown('?view=pending&beneficiary_status=pending'));
    }

    /** Attendance Status reads today's session, and NULL is never an absence. */
    #[Test]
    public function the_attendance_filter_reads_todays_session(): void
    {
        $present = $this->makeStudent('Grade 7 / Sampaguita', 'Wasted', name: 'Present One');
        $absent = $this->makeStudent('Grade 7 / Rosal', 'Wasted', name: 'Absent One');
        $unclear = $this->makeStudent('Grade 8 / Ilang', 'Wasted', name: 'Unclear One');
        $this->makeStudent('Grade 9 / Narra', 'Wasted', name: 'Uncovered One');

        $today = now()->toDateString();
        $this->markAttendance($present, $today, true);
        $this->markAttendance($absent, $today, false);
        $this->markAttendance($unclear, $today, null, needsReview: true);

        $this->assertSame(['Present One'], $this->namesShown('?attendance=present'));
        $this->assertSame(['Absent One'], $this->namesShown('?attendance=absent'));
        // A scanned mark no human has read and a learner no sheet covered are
        // both unmarked — neither is reported as an absence.
        $this->assertSame(['Unclear One', 'Uncovered One'], $this->namesShown('?attendance=unmarked'));
    }

    /** The school year is the period every other filter sits inside. */
    #[Test]
    public function the_school_year_filter_moves_the_whole_page(): void
    {
        $this->makeStudent('Grade 7 / Sampaguita', 'Wasted', name: 'This Year');
        $this->makeStudent('Grade 7 / Sampaguita', 'Wasted', schoolYear: '2019-2020', name: 'Last Year');

        $this->assertSame(['This Year'], $this->namesShown(''));

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records?school_year=2019-2020')
            ->assertOk();

        $this->assertSame(['Last Year'], collect($response->viewData('records'))->pluck('student_name')->all());
        // The year is scope, so the cards and the tabs move with it.
        $this->assertSame(1, $response->viewData('beneficiarySummary')['beneficiaries']);
        $this->assertSame(1, $response->viewData('segmentCounts')['all']);
        $this->assertSame('2019-2020', $response->viewData('filters')['school_year']);
    }

    /**
     * Scope and narrowing are different jobs: year, grade and section move the
     * cards and the tabs with them; the other five narrow the list alone, so
     * the tab above the table keeps reporting the size of the view.
     */
    #[Test]
    public function narrowing_filters_leave_the_cards_and_tabs_alone(): void
    {
        $this->makeStudent('Grade 7 / Sampaguita', 'Wasted', sex: 'Male');
        $this->makeStudent('Grade 7 / Rosal', 'Wasted', sex: 'Female');

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records?sex=Female')
            ->assertOk();

        $this->assertCount(1, $response->viewData('records'), 'The list narrows.');
        $this->assertSame(2, $response->viewData('segmentCounts')['all'], 'The tab still counts the view.');
        $this->assertSame(2, $response->viewData('beneficiarySummary')['beneficiaries'], 'The card still counts the roll.');
    }

    /** An unknown filter value is ignored rather than emptying the page. */
    #[Test]
    public function unknown_filter_values_are_ignored(): void
    {
        $this->makeStudent('Grade 7 / Sampaguita', 'Wasted');

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records?sex=Other&baseline_status=Nonsense&attendance=maybe&beneficiary_status=x&endline_status=y')
            ->assertOk();

        $this->assertCount(1, $response->viewData('records'));
        foreach (['sex', 'baseline_status', 'attendance', 'beneficiary_status', 'endline_status'] as $key) {
            $this->assertSame('', $response->viewData('filters')[$key]);
        }
    }

    /**
     * The invariant this whole class exists for: the Beneficiaries tab and the
     * Dashboard count one programme. Both read FeedingBeneficiarySummary, so a
     * change to who qualifies, who is enrolled, or how attendance is counted
     * moves both screens together or neither.
     */
    #[Test]
    public function the_beneficiaries_tab_and_the_dashboard_report_the_same_programme(): void
    {
        $fed = $this->makeStudent('Grade 7 / Sampaguita', 'Severely Wasted');
        $this->makeStudent('Grade 11 / Ilang', 'Wasted');
        $this->makeStudent('Grade 9 / Narra', 'Underweight');
        $this->makeStudent('Grade 8 / Rosal', 'Wasted', enrolled: false);

        foreach ([true, false, true, true] as $index => $isPresent) {
            $this->markAttendance($fed, now()->subDays(4 - $index)->toDateString(), $isPresent);
        }

        $cards = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records')
            ->assertOk()
            ->viewData('beneficiarySummary');

        $dashboard = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk();

        $stats = $dashboard->viewData('dashboardStats');
        $panel = collect($dashboard->viewData('nutritionStatus')['rows'])->pluck('count', 'label');

        $this->assertSame($stats['beneficiaries'], $cards['beneficiaries']);
        $this->assertSame($stats['at_risk'], $cards['at_risk']);
        $this->assertSame($stats['attendance_rate'], $cards['attendance_rate']);
        $this->assertSame($stats['attendance_sessions'], $cards['attendance_sessions']);
        $this->assertSame($panel['Severely Wasted'], $cards['severely_wasted']);
        $this->assertSame($panel['Wasted'], $cards['wasted']);
    }
}
