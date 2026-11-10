<?php

namespace Tests\Feature;

use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\FeedingBeneficiarySummary;
use App\Support\FeedingProgramCycle;
use App\Support\RequestMemo;
use App\Support\SchoolHeadOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfirmsFeedingFlags;
use Tests\TestCase;

/**
 * A learner who recovers is still a beneficiary.
 *
 * The endline overwrites nutritional_status, so a child re-measured as Normal
 * stopped qualifying on their current reading, and every screen that asked that
 * dropped them: the programme lost its size at the closing weigh-in and its
 * outcome figures could never count a child who got better. Leaving the
 * programme is the coordinator's decision, so a measurement must not do it.
 *
 * The endline here goes through the adviser's real form, not a hand-set column,
 * because the overwrite is the whole of the problem.
 */
class FeedingRecoveredBeneficiaryTest extends TestCase
{
    use ConfirmsFeedingFlags;
    use RefreshDatabase;

    private Institution $school;

    private StudentHealthRecord $recovered;

    private StudentHealthRecord $stillWasted;

    private StudentHealthRecord $waiting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = Institution::create(['name' => 'Test School', 'status' => 'active']);

        $this->recovered = $this->learner('Bituin, Ana', '700000000001', ['feeding_enrolled_at' => now()->subMonths(6)]);
        $this->stillWasted = $this->learner('Dalisay, Ben', '700000000002', ['feeding_enrolled_at' => now()->subMonths(6)]);
        $this->waiting = $this->learner('Hiraya, Cora', '700000000003');

        // The cycle has run its length, or the endline form refuses the weighing.
        FeedingAttendance::create([
            'institution_id' => $this->school->id,
            'student_health_record_id' => $this->stillWasted->id,
            'session_date' => now()->subDays((int) ceil(FeedingProgramCycle::DURATION_DAYS / 5 * 7) + 7)->toDateString(),
            'is_present' => true,
        ]);

        // The coordinator weighs the endline (testers' report #32).
        $this->withSession([
            'active_role' => 'feeding_coor',
            'active_name' => 'Feeding Coordinator',
            'active_username' => 'feedcor.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->school->id,
        ])->post(route('feedingcor-program.beneficiary.endline', $this->recovered), [
            'age' => 13,
            'height_cm' => 145,
            'weight_kg' => 46,
        ])->assertRedirect();

        $this->flushSession();
        $this->recovered->refresh();

        $this->assertSame('Normal', $this->recovered->nutritional_status, 'The premise: the endline overwrites the current reading.');
        $this->assertSame('Normal', $this->recovered->endline_nutritional_status);
        $this->assertSame('Wasted', $this->recovered->baseline_nutritional_status);
    }

    /** @param array<string, mixed> $attributes */
    private function learner(string $name, string $lrn, array $attributes = []): StudentHealthRecord
    {
        return StudentHealthRecord::create(array_merge([
            'institution_id' => $this->school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => $name,
            'student_id' => $lrn,
            'school_name' => 'Test School',
            'section' => 'Grade 7 / Matiyaga',
            'weight' => 33,
            'bmi_value' => 15.7,
            'nutritional_status' => 'Wasted',
            'baseline_age' => 13,
            'baseline_height_cm' => 145,
            'baseline_weight_kg' => 33,
            'baseline_bmi_value' => 15.7,
            'baseline_nutritional_status' => 'Wasted',
            'student_details' => ['gender' => 'Female', 'nutritional_status_height_for_age' => 'Normal Height-for-Age'],
        ], $attributes));
    }

    private function asCoordinator(): static
    {
        return $this->withSession([
            'active_role' => 'feeding_coor',
            'active_name' => 'Test Coordinator',
            'active_username' => 'feedcor.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->school->id,
        ]);
    }

    private function open(string $url): TestResponse
    {
        return $this->asCoordinator()->get($url)->assertOk();
    }

    #[Test]
    public function an_endline_of_normal_does_not_take_a_learner_off_the_programme(): void
    {
        $this->assertTrue(FeedingBeneficiarySummary::isEnrolled($this->recovered));
        $this->assertTrue(FeedingBeneficiarySummary::isBeneficiary($this->recovered));

        // They still may not be offered a place: they no longer qualify for one.
        $this->assertFalse(FeedingBeneficiarySummary::isEligible($this->recovered));
    }

    /** Widening the test must not let anybody in who never was one. */
    #[Test]
    public function only_somebody_enrolled_who_really_qualified_counts(): void
    {
        $enrolled = ['feeding_enrolled_at' => now()];

        $correctedToNormal = $this->learner('Likha, Dan', '700000000004', $enrolled + [
            'nutritional_status' => 'Normal',
            'baseline_nutritional_status' => 'Normal',
        ]);
        $neverEnrolled = $this->learner('Malaya, Ela', '700000000005', [
            'nutritional_status' => 'Normal',
            'endline_nutritional_status' => 'Normal',
        ]);
        $seniorHigh = $this->learner('Tala, Fe', '700000000006', $enrolled + ['section' => 'Grade 11 / Stem']);
        $removed = $this->learner('Ulan, Gia', '700000000007', $enrolled + [
            'nutritional_status' => 'Normal',
            'endline_nutritional_status' => 'Normal',
            'feeding_removed_at' => now(),
        ]);

        $this->assertFalse(FeedingBeneficiarySummary::isBeneficiary($correctedToNormal), 'A baseline corrected to Normal never qualified.');
        $this->assertFalse(FeedingBeneficiarySummary::isBeneficiary($neverEnrolled), 'Qualifying once is not enrolment.');
        $this->assertFalse(FeedingBeneficiarySummary::isBeneficiary($seniorHigh), 'The programme is Junior High only.');
        $this->assertFalse(FeedingBeneficiarySummary::isBeneficiary($removed), 'The coordinator took them off the line.');
    }

    #[Test]
    public function the_dashboard_and_the_beneficiaries_tab_keep_the_programmes_size(): void
    {
        $stats = $this->open(route('dashboard.feedingcor-dashboard'))->viewData('dashboardStats');
        $this->assertSame(2, $stats['beneficiaries']);
        $this->assertSame(1, $stats['awaiting_enrollment'], 'The recovered learner is not counted as waiting, nor taken off the waiting count.');

        $tab = $this->open(route('dashboard.feedingcor-health-records'));
        $this->assertSame(2, $tab->viewData('beneficiarySummary')['beneficiaries']);
        $this->assertSame(1, $tab->viewData('beneficiarySummary')['pending']);
        $this->assertSame(2, $tab->viewData('segmentCounts')['all']);
        $this->assertContains('Bituin, Ana', collect($tab->viewData('records'))->pluck('student_name')->all());

        // On the whole roll they read Enrolled, never "Not qualified".
        $row = collect($this->open(route('dashboard.feedingcor-health-records', ['view' => 'all_students']))->viewData('records'))
            ->firstWhere('student_name', 'Bituin, Ana');
        $this->assertSame('beneficiary', $row->segment);
    }

    #[Test]
    public function the_enrolment_dialog_counts_them_and_does_not_offer_them(): void
    {
        $dialog = $this->asCoordinator()
            ->getJson(route('feedingcor-program.enrollment.candidates'))
            ->assertOk();

        $this->assertSame(2, $dialog->json('enrolled'));
        $this->assertSame(1, $dialog->json('waiting'));
        $this->assertSame([$this->waiting->id], collect($dialog->json('rows'))->pluck('id')->all());
    }

    #[Test]
    public function they_stay_on_both_master_lists_and_the_bmi_report(): void
    {
        $roster = $this->asCoordinator()
            ->getJson(route('dashboard.feedingcor-sbfp-forms.roster'))
            ->assertOk();

        $row = collect($roster->json('studentsByGrade')['Grade 7'])->firstWhere('name', 'Bituin, Ana');
        $this->assertSame(['feeding-beneficiaries', 'feeding-masterlist'], $row['lists']);

        // The report's Only Beneficiaries and the lists beside it name one roll.
        $values = $roster->json('bmiReports.beneficiaries.values')[''];
        $this->assertSame(2, $values['bmib_g7_total_nst']);
        $this->assertSame(2, $values['bmib_g7_total_w'], 'Both were enrolled on a Wasted baseline.');
        $this->assertSame(1, $values['bmif_g7_total_n'], 'And this is the closing reading the Final grid reports.');
    }

    #[Test]
    public function the_record_attendance_roster_still_lists_them(): void
    {
        $names = collect($this->open(route('feedingcor-program.attendance.record'))->viewData('rows'))
            ->pluck('name')
            ->all();

        $this->assertContains('Bituin, Ana', $names);
        $this->assertNotContains('Hiraya, Cora', $names, 'Nobody enrolled the learner still waiting.');
    }

    /** A flag stays until a Resolved follow-up closes it, whatever the scale says. */
    #[Test]
    public function a_flag_survives_the_learner_recovering(): void
    {
        $this->confirmFlag($this->recovered);

        $this->assertSame(1, $this->open(route('dashboard.feedingcor-at-risk'))->viewData('cards')['at_risk']);

        $standing = $this->open(route('feedingcor-program.beneficiary', $this->recovered))->viewData('standing');
        $this->assertTrue($standing['enrolled']);
        $this->assertTrue($standing['at_risk'], 'The record must not read Active while the list reads Flagged.');
    }

    /** This is what the bug hid: the figures that say whether feeding worked. */
    #[Test]
    public function the_school_heads_outcome_counts_the_child_who_got_better(): void
    {
        RequestMemo::flush();

        $outcome = SchoolHeadOverview::for($this->school->id, StudentHealthRecord::currentSchoolYear())->outcome();

        $this->assertSame(2, $outcome['beneficiaries']);
        $this->assertSame(1, $outcome['measured']);
        $this->assertSame(1, $outcome['not_measured']);
        $this->assertSame(1, $outcome['rehabilitated']);
        $this->assertSame(1, $outcome['improved']);
        $this->assertSame(0, $outcome['still_wasted']);
    }

    /** The coordinator can still take them off, and they are not offered back. */
    #[Test]
    public function only_the_coordinator_ends_their_place(): void
    {
        $this->asCoordinator()
            ->post(route('feedingcor-program.enrollment.remove', $this->recovered), ['reason' => 'Completed the programme'])
            ->assertRedirect();

        $this->recovered->refresh();

        $this->assertFalse(FeedingBeneficiarySummary::isBeneficiary($this->recovered));
        $this->assertNotNull($this->recovered->feeding_enrolled_at, 'They were fed, and the record keeps saying so.');

        $dialog = $this->asCoordinator()->getJson(route('feedingcor-program.enrollment.candidates'))->assertOk();
        $this->assertSame([$this->waiting->id], collect($dialog->json('rows'))->pluck('id')->all());
    }
}
