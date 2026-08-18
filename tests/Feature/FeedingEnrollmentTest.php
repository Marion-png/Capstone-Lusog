<?php

namespace Tests\Feature;

use App\Http\Controllers\FeedingEnrollmentController;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Enrolling a beneficiary.
 *
 * Qualifying and being enrolled are two different facts. The class adviser
 * measures a learner and their nutritional status decides whether they
 * *qualify*; the Feeding Coordinator then decides whether to *enrol* them. Only
 * the second makes someone a beneficiary — the dashboard, the attendance
 * screens and the at-risk rule all read the enrolled roll.
 */
class FeedingEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private const CANDIDATES = '/dashboard/feedingcor-program/enrollment/candidates';

    private const ENROLL = '/dashboard/feedingcor-program/enrollment';

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

    /** A learner as the adviser leaves them: measured, qualified, not yet enrolled. */
    private function makeCandidate(
        string $status = 'Wasted',
        string $section = 'Grade 7 / Sampaguita',
        ?int $institutionId = null,
        ?string $enrolledAt = null,
    ): StudentHealthRecord {
        return StudentHealthRecord::create([
            'institution_id' => $institutionId ?? $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Learner '.random_int(1000, 9999),
            'student_id' => 'LRN'.random_int(100000, 999999),
            'school_name' => 'Test School',
            'section' => $section,
            'weight' => 30,
            'bmi_value' => 15.1,
            'nutritional_status' => $status,
            'baseline_nutritional_status' => $status,
            'baseline_recorded_at' => '2026-06-12',
            'student_details' => ['gender' => 'Male'],
            'feeding_enrolled_at' => $enrolledAt,
        ]);
    }

    #[Test]
    public function the_waiting_list_holds_qualified_learners_nobody_has_enrolled(): void
    {
        $waiting = $this->makeCandidate('Wasted');
        $severe = $this->makeCandidate('Severely Wasted', 'Grade 8 / Luna');
        $enrolled = $this->makeCandidate('Wasted', 'Grade 7 / Rosal', null, now()->toDateTimeString());
        $normal = $this->makeCandidate('Normal', 'Grade 7 / Rosal');

        $payload = $this->withSession($this->coordinatorSession())
            ->getJson(self::CANDIDATES)
            ->assertOk()
            ->json();

        $names = collect($payload['rows'])->pluck('name');

        $this->assertCount(2, $payload['rows']);
        $this->assertTrue($names->contains($waiting->student_name));
        $this->assertTrue($names->contains($severe->student_name));
        $this->assertFalse($names->contains($enrolled->student_name), 'An enrolled learner is no longer waiting.');
        $this->assertFalse($names->contains($normal->student_name), 'A learner who does not qualify never appears.');

        $this->assertSame(1, $payload['enrolled']);
        $this->assertSame(2, $payload['waiting']);
        $this->assertSame('12 June 2026', $payload['weigh_in']);

        // The modal's own filter options come from the list it was given.
        $this->assertSame(['Grade 7', 'Grade 8'], $payload['grades']);
        $this->assertContains('Sampaguita', $payload['sections']);
    }

    #[Test]
    public function the_status_badge_separates_wasted_from_severely_wasted(): void
    {
        $this->makeCandidate('Severely Wasted');

        $row = $this->withSession($this->coordinatorSession())
            ->getJson(self::CANDIDATES)
            ->assertOk()
            ->json('rows.0');

        $this->assertSame('Severely Wasted', $row['status']);
        $this->assertSame('SW', $row['status_short']);
        $this->assertSame('badge-critical', $row['badge']);
    }

    #[Test]
    public function enrolling_a_learner_makes_them_a_beneficiary(): void
    {
        $learner = $this->makeCandidate();

        $this->withSession($this->coordinatorSession())
            ->postJson(self::ENROLL, ['record_ids' => [$learner->id]])
            ->assertOk()
            ->assertJson(['enrolled_now' => 1]);

        $fresh = $learner->fresh();
        $this->assertNotNull($fresh->feeding_enrolled_at);
        $this->assertSame('Test Coordinator', $fresh->feeding_enrolled_by);

        // They now count as a beneficiary on the dashboard and drop off the list.
        $stats = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->viewData('dashboardStats');

        $this->assertSame(1, $stats['beneficiaries']);
        $this->assertSame(0, $stats['awaiting_enrollment']);
        $this->assertSame(0, $this->withSession($this->coordinatorSession())->getJson(self::CANDIDATES)->json('waiting'));
    }

    #[Test]
    public function several_learners_can_be_enrolled_at_once(): void
    {
        $first = $this->makeCandidate();
        $second = $this->makeCandidate('Severely Wasted', 'Grade 8 / Luna');

        $this->withSession($this->coordinatorSession())
            ->postJson(self::ENROLL, ['record_ids' => [$first->id, $second->id]])
            ->assertOk()
            ->assertJson(['enrolled_now' => 2]);

        $this->assertNotNull($first->fresh()->feeding_enrolled_at);
        $this->assertNotNull($second->fresh()->feeding_enrolled_at);
    }

    /** A double-clicked button must not rewrite when someone joined the programme. */
    #[Test]
    public function re_enrolling_an_enrolled_learner_does_not_move_their_enrolment_date(): void
    {
        $learner = $this->makeCandidate('Wasted', 'Grade 7 / Sampaguita', null, now()->subMonth()->toDateTimeString());
        $enrolledAt = $learner->fresh()->feeding_enrolled_at;

        $this->withSession($this->coordinatorSession())
            ->postJson(self::ENROLL, ['record_ids' => [$learner->id]])
            ->assertStatus(422)
            ->assertJson(['enrolled_now' => 0]);

        $this->assertEquals($enrolledAt, $learner->fresh()->feeding_enrolled_at);
    }

    #[Test]
    public function a_learner_who_does_not_qualify_cannot_be_enrolled(): void
    {
        $normal = $this->makeCandidate('Normal');

        $this->withSession($this->coordinatorSession())
            ->postJson(self::ENROLL, ['record_ids' => [$normal->id]])
            ->assertStatus(422);

        $this->assertNull($normal->fresh()->feeding_enrolled_at);
    }

    #[Test]
    public function another_schools_learner_cannot_be_enrolled_or_listed(): void
    {
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $outsider = $this->makeCandidate('Wasted', 'Grade 7 / Sampaguita', $other->id);

        $this->withSession($this->coordinatorSession())
            ->getJson(self::CANDIDATES)
            ->assertOk()
            ->assertJsonCount(0, 'rows');

        $this->withSession($this->coordinatorSession())
            ->postJson(self::ENROLL, ['record_ids' => [$outsider->id]])
            ->assertStatus(422);

        $this->assertNull($outsider->fresh()->feeding_enrolled_at);
    }

    #[Test]
    public function enrolment_is_audited(): void
    {
        $learner = $this->makeCandidate();

        $this->withSession($this->coordinatorSession())
            ->postJson(self::ENROLL, ['record_ids' => [$learner->id]])
            ->assertOk();

        $this->assertTrue(
            AuditLog::query()->get()->contains(
                fn ($log) => str_contains((string) $log->description, '1 learner(s) enrolled into the feeding programme by Test Coordinator')
            )
        );
    }

    #[Test]
    public function the_enrolment_endpoints_are_closed_to_other_roles(): void
    {
        $learner = $this->makeCandidate();
        $session = ['active_role' => 'class_adviser', 'active_institution_id' => $this->institution->id];

        $this->withSession($session)->getJson(self::CANDIDATES)->assertForbidden();
        $this->withSession($session)->postJson(self::ENROLL, ['record_ids' => [$learner->id]])->assertForbidden();

        $this->assertNull($learner->fresh()->feeding_enrolled_at);
    }

    /** An unenrolled learner is not fed: they stay off the attendance screens. */
    #[Test]
    public function a_waiting_learner_is_not_on_the_attendance_roll(): void
    {
        $waiting = $this->makeCandidate();
        $enrolled = $this->makeCandidate('Wasted', 'Grade 7 / Rosal', null, now()->toDateTimeString());

        $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-program/attendance/record')
            ->assertOk()
            ->assertSee($enrolled->student_name)
            ->assertDontSee($waiting->student_name);

        // Same question of the Attendance tab, which lists the enrolled roll.
        $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-attendance')
            ->assertOk()
            ->assertSee($enrolled->student_name)
            ->assertDontSee($waiting->student_name);
    }

    /**
     * The adviser-to-coordinator handover, and its boundary.
     *
     * An adviser measuring a learner stamps their own institution on the
     * record; the dialog reads the coordinator's. So a learner appears on the
     * waiting list when — and only when — the two work at the same school.
     */
    #[Test]
    public function a_learner_appears_for_the_coordinator_at_the_advisers_own_school(): void
    {
        $mine = $this->makeCandidate('Wasted', 'Grade 7 / Sampaguita');

        $otherSchool = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $theirs = $this->makeCandidate('Wasted', 'Grade 7 / Sampaguita', $otherSchool->id);

        $payload = $this->withSession($this->coordinatorSession())
            ->getJson(self::CANDIDATES)
            ->assertOk()
            ->json();

        $names = array_column($payload['rows'], 'name');

        $this->assertContains($mine->student_name, $names);
        $this->assertNotContains($theirs->student_name, $names, 'Another school’s learner never reaches this dialog.');

        // And the write refuses them too, whatever the client posts.
        $this->withSession($this->coordinatorSession())
            ->postJson(self::ENROLL, ['record_ids' => [$theirs->id]])
            ->assertStatus(422);

        $this->assertNull($theirs->fresh()->feeding_enrolled_at);
    }

    /**
     * No school on the session means no list — never every school's children.
     * Scope fails closed twice over on a screen that names learners: the
     * InstitutionScope middleware ejects the request before it arrives, and the
     * controller would still return nobody if it ever did.
     */
    #[Test]
    public function a_coordinator_with_no_school_reaches_nobody(): void
    {
        $learner = $this->makeCandidate();
        $session = ['active_role' => 'feeding_coor', 'active_name' => 'Test Coordinator'];

        $this->withSession($session)->getJson(self::CANDIDATES)->assertRedirect();
        $this->withSession($session)->postJson(self::ENROLL, ['record_ids' => [$learner->id]])->assertRedirect();

        $this->assertNull($learner->fresh()->feeding_enrolled_at);

        // And the controller's own guard, exercised directly: an unscoped read
        // returns an empty waiting list rather than the whole database.
        $controller = new FeedingEnrollmentController;
        $request = Request::create(self::CANDIDATES);
        $request->setLaravelSession(app('session.store'));
        $request->session()->put(['active_role' => 'feeding_coor']);

        $payload = $controller->candidates($request)->getData(true);

        $this->assertSame([], $payload['rows']);
        $this->assertSame(0, $payload['waiting']);
    }

    /** Senior High is outside the programme, so it is never offered for enrolment. */
    #[Test]
    public function a_senior_high_learner_is_never_a_candidate(): void
    {
        $jhs = $this->makeCandidate('Wasted', 'Grade 10 / Narra');
        $shs = $this->makeCandidate('Severely Wasted', 'Grade 11 / Humss');

        $payload = $this->withSession($this->coordinatorSession())
            ->getJson(self::CANDIDATES)
            ->assertOk()
            ->json();

        $names = array_column($payload['rows'], 'name');

        $this->assertContains($jhs->student_name, $names);
        $this->assertNotContains($shs->student_name, $names);

        $this->withSession($this->coordinatorSession())
            ->postJson(self::ENROLL, ['record_ids' => [$shs->id]])
            ->assertStatus(422);

        $this->assertNull($shs->fresh()->feeding_enrolled_at);
    }

    #[Test]
    public function the_dashboard_offers_the_enrol_action_and_its_modal(): void
    {
        $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->assertSee('id="enrollBeneficiaryBtn"', false)
            ->assertSee('id="enrollBackdrop"', false)
            ->assertSee('Qualified learners')
            ->assertSee('Enroll selected');
    }
}
