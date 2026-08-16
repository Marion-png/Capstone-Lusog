<?php

namespace Tests\Feature;

use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the Feeding Program page's figures.
 *
 * Everything here used to be computed its own way — a hardcoded 75% threshold,
 * attendance divided by the calendar day of the cycle, at-risk read off a
 * stored flag, and a baseline weight invented as "current minus 0.7" whenever
 * the adviser had not measured one. Each of those made the page disagree with
 * the Dashboard, the Beneficiaries tab, or the truth. These tests keep the page
 * reading from the same rule and the same marks as everything else.
 */
class FeedingProgramPageTest extends TestCase
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
            'section' => 'Grade 7 / Sampaguita',
            'weight' => 20.0,
            'bmi_value' => 13.5,
            'nutritional_status' => 'Wasted',
            'feeding_enrolled_at' => now(),
        ], $attributes));
    }

    private function mark(StudentHealthRecord $record, string $date, ?bool $present): void
    {
        FeedingAttendance::create([
            'student_health_record_id' => $record->id,
            'session_date' => $date,
            'is_present' => $present,
            'needs_review' => false,
            'source' => 'manual_entry',
        ]);
    }

    private function page()
    {
        return $this->withSession($this->coordinatorSession())->get('/dashboard/feedingcor-program');
    }

    #[Test]
    public function attendance_is_counted_over_confirmed_sessions_not_the_cycle_length(): void
    {
        $record = $this->makeStudent();
        // Twelve feeding days, eight attended: deep enough for the rule's
        // observation window, so the learner reaches the at-risk list where
        // this fraction is printed.
        foreach (range(1, 12) as $offset) {
            $this->mark($record, now()->subDays($offset)->toDateString(), $offset <= 8);
        }

        $response = $this->page()->assertOk();

        // Eight of twelve sessions attended — not eight of the 120-day cycle,
        // and not eight of however many days have passed since day one.
        $response->assertSee('8/12 sessions');
        $response->assertSee('67%');
        $response->assertDontSee('8/120 days');
    }

    #[Test]
    public function a_learner_no_session_has_covered_claims_no_rate(): void
    {
        $this->makeStudent();

        $this->page()->assertOk()->assertSee('No session yet');
    }

    #[Test]
    public function the_at_risk_list_follows_the_schools_threshold_without_an_import(): void
    {
        $record = $this->makeStudent(['student_name' => 'Halfway Learner']);
        // Ten sessions, five attended: 50%, and past the observation window.
        foreach (range(1, 10) as $offset) {
            $this->mark($record, now()->subDays($offset)->toDateString(), $offset <= 5);
        }

        // 50% is under the 80% default, so the learner is flagged...
        $this->page()->assertOk()->assertSee('1 at-risk beneficiaries detected');

        // ...and lifting the school's threshold below their rate clears them at
        // once, rather than waiting for the next import to rewrite is_at_risk.
        $this->institution->update(['feeding_at_risk_threshold' => 40]);

        $response = $this->page()->assertOk();
        $response->assertDontSee('at-risk beneficiaries detected');
        // The page prints the school's rule, never a constant of its own.
        $response->assertDontSee('below 75%');
    }

    #[Test]
    public function the_alert_names_the_schools_own_rule(): void
    {
        $this->institution->update(['feeding_at_risk_threshold' => 90]);

        $record = $this->makeStudent();
        foreach (range(1, 10) as $offset) {
            $this->mark($record, now()->subDays($offset)->toDateString(), $offset <= 5);
        }

        $response = $this->page()->assertOk();

        $response->assertSee('Attendance below 90%');
        // And the second half of the rule, so nobody reads the threshold as
        // something that applies from the first sheet.
        $response->assertSee('after at least 10 recorded feeding days');
    }

    #[Test]
    public function a_learner_without_a_baseline_shows_no_baseline_rather_than_an_invented_one(): void
    {
        // 20.0 kg now, nothing measured at baseline. The page used to print
        // "19.3 kg" (current minus 0.7) and call it a +0.7 kg improvement.
        $this->makeStudent(['weight' => 20.0]);

        $response = $this->page()->assertOk();

        $response->assertSee('No baseline');
        $response->assertDontSee('19.3 kg');
        $response->assertDontSee('+0.7 kg');
    }

    #[Test]
    public function a_measured_baseline_still_reports_its_movement(): void
    {
        $this->makeStudent([
            'weight' => 20.0,
            'baseline_weight_kg' => 18.0,
            'baseline_bmi_value' => 12.5,
        ]);

        $response = $this->page()->assertOk();

        $response->assertSee('18.0 kg');
        $response->assertSee('+2.0 kg');
        $response->assertSee('Improved');
    }

    #[Test]
    public function the_page_offers_a_printable_report(): void
    {
        $this->makeStudent();

        $response = $this->page()->assertOk();

        $response->assertSee('Print Program Report');
        // Paper carries no sidebar and no clock, so the masthead says whose
        // programme this is and when it was printed.
        $response->assertSee('print-masthead', false);
        $response->assertSee('Test School');
    }

    #[Test]
    public function nothing_in_the_feeding_program_ui_is_underlined(): void
    {
        $record = $this->makeStudent();
        $this->mark($record, now()->toDateString(), true);

        foreach ([
            '/dashboard/feedingcor-program',
            '/dashboard/feedingcor-dashboard',
            '/dashboard/feedingcor-health-records',
            '/dashboard/feedingcor-program/beneficiary/'.$record->id,
        ] as $url) {
            $html = $this->withSession($this->coordinatorSession())->get($url)->assertOk()->getContent();

            // The DepEd form facsimile keeps its own rules; the app's own
            // chrome never underlines a word, hovered or at rest.
            $this->assertStringNotContainsString('text-decoration: underline', $html, $url.' underlines text');
            $this->assertStringNotContainsString('<u>', $html, $url.' underlines text');
        }
    }
}
