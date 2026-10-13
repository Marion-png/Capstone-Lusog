<?php

namespace Tests\Feature;

use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\FeedingAtRiskRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfirmsFeedingFlags;
use Tests\TestCase;

/**
 * Who may see that a learner is flagged for follow-up: the Feeding Coordinator
 * and the School Nurse — and not the Clinic Teacher, the class adviser or
 * Clinic Staff, although each of them reads screens beside the flag.
 *
 * Every test here starts from the same learner, flagged the only way anybody
 * is: seven unexcused absences in a row, confirmed by the coordinator.
 */
class FeedingFlagVisibilityTest extends TestCase
{
    use ConfirmsFeedingFlags, RefreshDatabase;

    private Institution $institution;

    private StudentHealthRecord $learner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::create([
            'name' => 'Visibility School',
            'status' => 'active',
            'feeding_at_risk_mode' => FeedingAtRiskRule::MODE_UNEXCUSED_ABSENCE_DAYS,
        ]);

        $this->learner = StudentHealthRecord::create([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Flagged Learner',
            'student_id' => '123456789012',
            'school_name' => 'Visibility School',
            'section' => 'Grade 7 / MATIYAGA',
            'weight' => 20.0,
            'bmi_value' => 13.5,
            'nutritional_status' => 'Wasted',
            'baseline_nutritional_status' => 'Wasted',
            'student_details' => ['gender' => 'Male'],
            'feeding_enrolled_at' => now(),
        ]);

        foreach (range(1, 9) as $daysAgo) {
            FeedingAttendance::create([
                'student_health_record_id' => $this->learner->id,
                'session_date' => now()->subDays($daysAgo)->toDateString(),
                'is_present' => $daysAgo > 7,
                'needs_review' => false,
                'source' => FeedingAttendance::SOURCE_MANUAL_ENTRY,
            ]);
        }

        $this->confirmFlag($this->learner);
    }

    private function sessionFor(string $role, array $extra = []): array
    {
        return array_merge([
            'active_role' => $role,
            'active_name' => 'Test '.$role,
            'active_username' => $role.'.test',
            'active_school_name' => 'Visibility School',
            'active_institution_id' => $this->institution->id,
        ], $extra);
    }

    #[Test]
    public function the_stored_flag_is_set_only_by_the_confirmation(): void
    {
        $this->assertTrue((bool) $this->learner->fresh()->is_at_risk);
    }

    #[Test]
    public function the_school_nurse_sees_the_flag_on_her_dashboard_and_the_clinic_teacher_does_not(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->get('/dashboard/school-nurse')
            ->assertOk()
            ->assertSee('At-Risk Learners')
            ->assertViewHas('atRiskCount', 1);

        $this->withSession($this->sessionFor('clinic_teacher'))
            ->get('/dashboard/clinic-teacher')
            ->assertOk()
            ->assertDontSee('At-Risk Learners')
            ->assertViewHas('atRiskCount', null);
    }

    #[Test]
    public function clinic_staff_are_not_shown_the_feeding_alerts(): void
    {
        $this->withSession($this->sessionFor('clinic_staff'))
            ->get('/dashboard/clinic-staff')
            ->assertOk()
            ->assertDontSee('Feeding Program At-Risk Alerts')
            ->assertDontSee('Flagged Learner');

        $this->withSession($this->sessionFor('school_nurse'))
            ->get('/dashboard/clinic-staff')
            ->assertOk()
            ->assertSee('Feeding Program At-Risk Alerts')
            ->assertSee('Flagged Learner');
    }

    #[Test]
    public function the_class_adviser_never_sees_the_flag(): void
    {
        $adviser = $this->sessionFor('class_adviser', [
            'assigned_grade_level' => 'Grade 7',
            'assigned_section' => 'MATIYAGA',
        ]);

        $response = $this->withSession($adviser)
            ->get('/dashboard/class-adviser/feeding-status')
            ->assertOk()
            ->assertSee('Flagged Learner');

        $response->assertDontSee('At-Risk');
        $response->assertDontSee('at-risk &middot;', false);
        $response->assertDontSee('value="at-risk"', false);
        $response->assertDontSee('data-risk', false);
        foreach ($response->viewData('students') as $student) {
            $this->assertArrayNotHasKey('at_risk', $student);
        }
    }

    #[Test]
    public function the_nurse_reads_the_flag_on_the_nutritional_health_status_list_and_the_head_and_clinic_teacher_do_not(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->get('/dashboard/school-nurse/nutritional-status')
            ->assertOk()
            ->assertSee('Flagged for follow-up')
            ->assertSee('Flagged for Follow-Up')
            ->assertViewHas('showFlags', true);

        foreach ([
            'school_head' => '/dashboard/school-head/masterlist',
            'clinic_teacher' => '/dashboard/clinic-teacher/nutritional-status',
        ] as $role => $url) {
            $response = $this->withSession($this->sessionFor($role))->get($url)->assertOk();

            $response->assertSee('Flagged Learner');
            $response->assertDontSee('Flagged for follow-up');
            $response->assertDontSee('Flagged for Follow-Up');
            $response->assertDontSee('name="attendance"', false);
            $this->assertFalse($response->viewData('showFlags'));
            foreach ($response->viewData('rows') as $row) {
                $this->assertFalse($row['at_risk'], $role.' must never be handed a learner\'s flag.');
            }

            // The filter off the wire is dropped, never applied unseen.
            $this->withSession($this->sessionFor($role))->get($url.'?attendance=at_risk')
                ->assertOk()
                ->assertSee('Flagged Learner');
        }
    }

    #[Test]
    public function the_school_head_reads_a_count_and_never_a_list_of_names(): void
    {
        $response = $this->withSession($this->sessionFor('school_head'))
            ->get('/dashboard/school-head')
            ->assertOk();

        $response->assertSee('1 beneficiary flagged for follow-up');
        $response->assertDontSee(route('dashboard.school-head.masterlist', ['attendance' => 'at_risk']), false);
    }
}
