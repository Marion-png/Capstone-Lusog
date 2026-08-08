<?php

namespace Tests\Feature;

use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the School Head dashboard: its numbers come from the school's own
 * records (never a fixed schedule), the live-refresh endpoints stay scoped and
 * role-gated, and the chart's axis stays on whole learners.
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

    private function makeStudent(string $section, string $status, ?Institution $school = null): StudentHealthRecord
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
        ]);
    }

    #[Test]
    public function dashboard_renders_with_no_learners(): void
    {
        $this->withSession($this->headSession())
            ->get('/dashboard/school-head')
            ->assertOk()
            ->assertSee('No student data yet', false);
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
    public function dashboard_uses_the_shared_role_sidebar_that_never_collapses(): void
    {
        foreach (['/dashboard/school-head', '/dashboard/school-head/reports'] as $url) {
            $response = $this->withSession($this->headSession())->get($url)->assertOk();

            // The shared panel, at one fixed width...
            $response->assertSee('asb-sidebar', false);
            // ...and none of the hover-driven collapse it replaced.
            $response->assertDontSee('sidebar:hover', false);
            $response->assertDontSee('sb-pin', false);
        }
    }

    #[Test]
    public function chart_counts_only_this_schools_learners(): void
    {
        $this->makeStudent('Grade 7 - Rizal', 'Normal');
        $this->makeStudent('Grade 7 - Rizal', 'Wasted');
        $this->makeStudent('Grade 8 - Bonifacio', 'Normal');
        $this->makeStudent('Grade 7 - Mabini', 'Normal', $this->otherSchool);

        $response = $this->withSession($this->headSession())->get('/dashboard/school-head')->assertOk();

        $chart = $response->viewData('gradeChart');
        $this->assertCount(2, $chart);
        $this->assertSame('Grade 7', $chart[0]['label']);
        $this->assertSame(1, $chart[0]['healthy']);
        $this->assertSame(1, $chart[0]['risk']);
        $this->assertSame(2, $chart[0]['total']);
        $this->assertSame('Grade 8', $chart[1]['label']);
        $this->assertSame(1, $chart[1]['total']);

        $this->assertSame(3, $response->viewData('stats')['total_students']);
    }

    #[Test]
    public function chart_axis_ticks_are_whole_learners(): void
    {
        foreach (range(1, 7) as $ignored) {
            $this->makeStudent('Grade 9 - Luna', 'Normal');
        }

        $axis = $this->withSession($this->headSession())
            ->get('/dashboard/school-head')
            ->assertOk()
            ->viewData('chartAxis');

        // Eight is the first multiple of four that clears seven learners.
        $this->assertSame(8, $axis['max']);
        $this->assertSame([8, 6, 4, 2, 0], $axis['ticks']);
        foreach ($axis['ticks'] as $tick) {
            $this->assertIsInt($tick);
        }
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
        $this->assertStringContainsString('Total Students', $payload['html']['stats']);
        $this->assertStringContainsString('Feeding Program', $payload['html']['programs']);
        $this->assertStringContainsString('Grade 7', $payload['html']['chart']);
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
