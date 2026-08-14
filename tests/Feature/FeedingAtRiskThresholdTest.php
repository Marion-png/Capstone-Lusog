<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\FeedingAtRiskRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The at-risk threshold is school-configurable.
 *
 * The programme default is 80% cumulative attendance, but a school running four
 * feeding days a week cannot be judged on the same line as one running five, so
 * the System Admin sets it per school. Everything that flags, counts or explains
 * at-risk must read the school's figure through FeedingAtRiskRule::forInstitution()
 * — the moment one screen reads config directly, two screens disagree about who
 * is flagged.
 *
 * Also guards the per-learner attendance view the dashboard's "View" opens.
 */
class FeedingAtRiskThresholdTest extends TestCase
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
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->institution->id,
        ];
    }

    private function adminSession(): array
    {
        return ['active_role' => 'system_admin', 'active_name' => 'Test Admin'];
    }

    private function makeStudent(?int $institutionId = null): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $institutionId ?? $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Learner '.random_int(1000, 9999),
            'student_id' => 'LRN'.random_int(100000, 999999),
            'school_name' => 'Test School',
            'section' => 'Grade 7 / Sampaguita',
            'weight' => 30,
            'bmi_value' => 15.1,
            'nutritional_status' => 'Wasted',
            'baseline_nutritional_status' => 'Wasted',
            'student_details' => ['gender' => 'Male'],
            // Already in the programme: qualifying is the adviser's measurement,
            // enrolling is the coordinator's decision, and these learners have both.
            'feeding_enrolled_at' => now(),
        ]);
    }

    private function mark(StudentHealthRecord $record, string $date, ?bool $present, bool $needsReview = false): void
    {
        FeedingAttendance::create([
            'student_health_record_id' => $record->id,
            'session_date' => $date,
            'is_present' => $present,
            'needs_review' => $needsReview,
            'source' => FeedingAttendance::SOURCE_MANUAL_ENTRY,
        ]);
    }

    #[Test]
    public function the_default_is_the_approved_eighty_percent(): void
    {
        $this->assertSame(80.0, FeedingAtRiskRule::fromConfig()->thresholdPercent());
        $this->assertSame(80.0, FeedingAtRiskRule::forInstitution($this->institution->id)->thresholdPercent());
    }

    #[Test]
    public function a_school_can_be_given_its_own_threshold(): void
    {
        $this->institution->update(['feeding_at_risk_threshold' => 90]);

        $rule = FeedingAtRiskRule::forInstitution($this->institution->id);

        $this->assertSame(90.0, $rule->thresholdPercent());
        $this->assertStringContainsString('90%', $rule->describe());
        // 4 of 5 = 80%, fine at the default and flagged at 90%.
        $this->assertTrue($rule->isAtRisk([true, true, true, true, false]));
        $this->assertFalse(FeedingAtRiskRule::forInstitution(null)->isAtRisk([true, true, true, true, false]));
    }

    #[Test]
    public function two_schools_are_judged_on_their_own_thresholds(): void
    {
        $other = Institution::create(['name' => 'Other School', 'status' => 'active', 'feeding_at_risk_threshold' => 60]);

        $this->assertSame(80.0, FeedingAtRiskRule::forInstitution($this->institution->id)->thresholdPercent());
        $this->assertSame(60.0, FeedingAtRiskRule::forInstitution($other->id)->thresholdPercent());
    }

    #[Test]
    public function the_system_admin_sets_and_clears_a_schools_threshold(): void
    {
        $this->withSession($this->adminSession())
            ->get('/dashboard/system-admin')
            ->assertOk()
            ->assertSee('Feeding At-Risk Threshold');

        $this->withSession($this->adminSession())
            ->post("/dashboard/system-admin/institutions/{$this->institution->id}/at-risk-threshold", ['threshold' => 85])
            ->assertRedirect();

        $this->assertSame(85, $this->institution->fresh()->feeding_at_risk_threshold);

        // Clearing it returns the school to the app default rather than pinning
        // it to today's number.
        $this->withSession($this->adminSession())
            ->post("/dashboard/system-admin/institutions/{$this->institution->id}/at-risk-threshold", ['threshold' => null])
            ->assertRedirect();

        $this->assertNull($this->institution->fresh()->feeding_at_risk_threshold);
        $this->assertSame(80.0, FeedingAtRiskRule::forInstitution($this->institution->id)->thresholdPercent());
    }

    #[Test]
    public function a_threshold_change_is_audited(): void
    {
        $this->withSession($this->adminSession())
            ->post("/dashboard/system-admin/institutions/{$this->institution->id}/at-risk-threshold", ['threshold' => 85]);

        $this->assertDatabaseCount('audit_logs', 2);   // the page-view middleware entry, then the change
        $this->assertTrue(
            AuditLog::query()->get()->contains(
                fn ($log) => str_contains((string) $log->description, 'at-risk threshold for Test School set to 85%')
            )
        );
    }

    #[Test]
    public function only_the_system_admin_may_change_a_threshold(): void
    {
        $this->withSession($this->coordinatorSession())
            ->post("/dashboard/system-admin/institutions/{$this->institution->id}/at-risk-threshold", ['threshold' => 50])
            ->assertRedirect(route('login'));

        $this->assertNull($this->institution->fresh()->feeding_at_risk_threshold);
    }

    #[Test]
    public function an_out_of_range_threshold_is_rejected(): void
    {
        $this->withSession($this->adminSession())
            ->post("/dashboard/system-admin/institutions/{$this->institution->id}/at-risk-threshold", ['threshold' => 140])
            ->assertSessionHasErrors('threshold');

        $this->assertNull($this->institution->fresh()->feeding_at_risk_threshold);
    }

    #[Test]
    public function the_learner_view_shows_one_beneficiarys_sessions(): void
    {
        $learner = $this->makeStudent();
        $this->mark($learner, now()->subDays(2)->toDateString(), true);
        $this->mark($learner, now()->subDay()->toDateString(), false);
        $this->mark($learner, now()->toDateString(), null, true);

        $response = $this->withSession($this->coordinatorSession())
            ->get("/dashboard/feedingcor-program/attendance/learner/{$learner->id}")
            ->assertOk()
            ->assertSee($learner->student_name)
            ->assertSee('Session History');

        $summary = $response->viewData('summary');

        // The unconfirmed mark is excluded from both sides of the rate.
        $this->assertSame(2, $summary['confirmed']);
        $this->assertSame(1, $summary['present']);
        $this->assertSame(1, $summary['unconfirmed']);
        $this->assertSame(50.0, $summary['rate']);
        $this->assertTrue($summary['at_risk']);
        $this->assertCount(3, $response->viewData('sessions'));
    }

    #[Test]
    public function the_learner_view_is_scoped_to_the_coordinators_school(): void
    {
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $outsider = $this->makeStudent($other->id);

        $this->withSession($this->coordinatorSession())
            ->get("/dashboard/feedingcor-program/attendance/learner/{$outsider->id}")
            ->assertRedirect(route('dashboard.feedingcor-dashboard'));
    }

    #[Test]
    public function the_learner_view_is_closed_to_other_roles(): void
    {
        $learner = $this->makeStudent();

        $this->withSession(['active_role' => 'class_adviser', 'active_institution_id' => $this->institution->id])
            ->get("/dashboard/feedingcor-program/attendance/learner/{$learner->id}")
            ->assertRedirect(route('login'));
    }
}
