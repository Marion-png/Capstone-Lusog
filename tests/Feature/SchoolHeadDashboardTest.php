<?php

namespace Tests\Feature;

use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;
use App\Support\SchoolHeadOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the School Head dashboard: its numbers come from the school's own
 * records (never a fixed schedule), the action queue is generated from live
 * conditions rather than a stored list, and the live-refresh endpoints stay
 * scoped and role-gated.
 */
class SchoolHeadDashboardTest extends TestCase
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
            'active_name' => 'Test Head',
            'active_username' => 'head.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->institution->id,
        ];
    }

    private function makeStudent(string $section, string $status, ?Institution $school = null, array $extra = []): StudentHealthRecord
    {
        $school ??= $this->institution;

        return StudentHealthRecord::create([
            'institution_id' => $school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Learner '.random_int(1000, 9999),
            'student_id' => 'LRN'.random_int(100000, 999999),
            'school_name' => $school->name,
            'section' => $section,
            'weight' => 30,
            'bmi_value' => 15,
            'nutritional_status' => $status,
            'baseline_nutritional_status' => $status,
        ] + $extra);
    }

    #[Test]
    public function dashboard_renders_with_no_learners(): void
    {
        $this->withSession($this->headSession())
            ->get('/dashboard/school-head')
            ->assertOk()
            ->assertSee('Nothing needs your attention right now.', false);
    }

    #[Test]
    public function dashboard_no_longer_shows_the_strategic_oversight_chip(): void
    {
        $this->withSession($this->headSession())
            ->get('/dashboard/school-head')
            ->assertOk()
            ->assertDontSee('Strategic Oversight');
    }

    #[Test]
    public function all_four_tabs_render_on_the_shared_role_sidebar(): void
    {
        $urls = [
            '/dashboard/school-head',
            '/dashboard/school-head/program',
            '/dashboard/school-head/reports',
            '/dashboard/school-head/masterlist',
        ];

        foreach ($urls as $url) {
            $response = $this->withSession($this->headSession())->get($url)->assertOk();

            // The shared panel, at one fixed width...
            $response->assertSee('asb-sidebar', false);
            // ...and none of the hover-driven collapse it replaced.
            $response->assertDontSee('sidebar:hover', false);
            $response->assertDontSee('sb-pin', false);

            // Every tab is reachable from every other one.
            $response->assertSee('Feeding Program', false);
            $response->assertSee('Masterlist', false);
        }
    }

    #[Test]
    public function the_grade_snapshot_counts_only_this_schools_learners(): void
    {
        $this->makeStudent('Grade 7 - Rizal', 'Normal');
        $this->makeStudent('Grade 7 - Rizal', 'Wasted');
        $this->makeStudent('Grade 8 - Bonifacio', 'Normal');
        $this->makeStudent('Grade 7 - Mabini', 'Normal', $this->otherSchool);

        $response = $this->withSession($this->headSession())->get('/dashboard/school-head')->assertOk();

        $snapshot = collect($response->viewData('gradeSnapshot'))->keyBy('label');
        $this->assertCount(2, $snapshot);
        $this->assertSame(2, $snapshot['Grade 7']['total']);
        $this->assertSame(1, $snapshot['Grade 7']['undernourished']);
        $this->assertSame(1, $snapshot['Grade 8']['total']);
        $this->assertSame(0, $snapshot['Grade 8']['undernourished']);

        $this->assertSame(3, $response->viewData('stats')['total_students']);
    }

    #[Test]
    public function an_unmeasured_learner_is_never_counted_as_normal(): void
    {
        // No baseline and no nutritional status: nobody classified this
        // learner, which is exactly the row a monitoring report must not file
        // under Normal.
        StudentHealthRecord::create([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Unweighed Learner',
            'student_id' => 'LRN000111',
            'school_name' => 'Test School',
            'section' => 'Grade 7 - Rizal',
            'weight' => 30,
            'bmi_value' => 15,
            'nutritional_status' => '',
        ]);

        $snapshot = collect($this->withSession($this->headSession())
            ->get('/dashboard/school-head')->assertOk()->viewData('gradeSnapshot'))
            ->keyBy('label');

        $this->assertSame(1, $snapshot['Grade 7']['total']);
        $this->assertSame(0, $snapshot['Grade 7']['measured']);
        $this->assertSame(0, $snapshot['Grade 7']['counts']['Normal']);
        $this->assertSame(1, $snapshot['Grade 7']['counts'][SchoolHeadOverview::NOT_MEASURED]);
        // A grade nobody measured has no undernourished share at all.
        $this->assertNull($snapshot['Grade 7']['bar']);
    }

    #[Test]
    public function the_action_queue_is_generated_from_live_conditions(): void
    {
        // Qualified by the adviser's measurement, not yet enrolled by the
        // coordinator: the waiting list.
        $this->makeStudent('Grade 7 / Rizal', 'Wasted');

        $queue = $this->withSession($this->headSession())
            ->get('/dashboard/school-head')->assertOk()->viewData('queue');

        $titles = collect($queue)->pluck('title')->implode(' | ');
        $this->assertStringContainsString('awaiting enrolment', $titles);

        // Enrol them and the item is gone — nothing about it was stored.
        StudentHealthRecord::query()->update(['feeding_enrolled_at' => now()]);

        $titles = collect($this->withSession($this->headSession())
            ->get('/dashboard/school-head')->assertOk()->viewData('queue'))
            ->pluck('title')->implode(' | ');
        $this->assertStringNotContainsString('awaiting enrolment', $titles);
    }

    #[Test]
    public function program_overview_reports_real_records_not_a_fixed_schedule(): void
    {
        $student = $this->makeStudent('Grade 7 - Rizal', 'Normal');
        $this->makeStudent('Grade 7 - Rizal', 'Wasted');

        FeedingAttendance::create([
            'student_health_record_id' => $student->id,
            'session_date' => now()->subDay()->toDateString(),
            'is_present' => true,
        ]);
        FeedingAttendance::create([
            'student_health_record_id' => $student->id,
            'session_date' => now()->toDateString(),
            'is_present' => true,
        ]);

        ParentalConsentForm::create([
            'student_health_record_id' => $student->id,
            'program_type' => 'Deworming',
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'uploaded_by_name' => 'Test Adviser',
        ]);

        $programs = collect($this->withSession($this->headSession())
            ->get('/dashboard/school-head')
            ->assertOk()
            ->viewData('programs'))
            ->keyBy('label');

        $this->assertStringContainsString('2 feeding days recorded', $programs['Feeding Program']['detail']);
        $this->assertSame('Active', $programs['Feeding Program']['status']);

        $this->assertSame('1 / 2 consent forms on file', $programs['Deworming']['detail']);
        $this->assertSame('In progress', $programs['Deworming']['status']);

        $this->assertSame('0 / 2 learners screened', $programs['Health Screening']['detail']);
        $this->assertSame('Not started', $programs['Health Screening']['status']);
    }

    #[Test]
    public function metrics_endpoint_returns_freshly_rendered_panels(): void
    {
        $this->makeStudent('Grade 7 - Rizal', 'Normal');

        $payload = $this->withSession($this->headSession())
            ->getJson('/dashboard/school-head/metrics')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('stamp', $payload);
        $this->assertStringContainsString('Learners enrolled', $payload['html']['stats']);
        $this->assertStringContainsString('Feeding Program', $payload['html']['programs']);
        $this->assertStringContainsString('Grade 7', $payload['html']['snapshot']);
        $this->assertArrayHasKey('queue', $payload['html']);
        $this->assertArrayHasKey('cycle', $payload['html']);
    }

    #[Test]
    public function the_pulse_stamp_moves_only_when_records_change(): void
    {
        $session = $this->headSession();

        $first = $this->withSession($session)->getJson('/dashboard/school-head/metrics/pulse')
            ->assertOk()->json('stamp');
        $second = $this->withSession($session)->getJson('/dashboard/school-head/metrics/pulse')
            ->assertOk()->json('stamp');
        $this->assertSame($first, $second);

        $this->makeStudent('Grade 7 - Rizal', 'Normal');

        $third = $this->withSession($session)->getJson('/dashboard/school-head/metrics/pulse')
            ->assertOk()->json('stamp');
        $this->assertNotSame($first, $third);
    }

    #[Test]
    public function the_live_endpoints_are_closed_to_other_roles(): void
    {
        $session = ['active_role' => 'class_adviser', 'active_institution_id' => $this->institution->id];

        $this->withSession($session)->getJson('/dashboard/school-head/metrics')->assertForbidden();
        $this->withSession($session)->getJson('/dashboard/school-head/metrics/pulse')->assertForbidden();
    }
}
