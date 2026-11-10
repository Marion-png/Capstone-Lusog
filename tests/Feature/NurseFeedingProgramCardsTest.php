<?php

namespace Tests\Feature;

use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The figures on the nurse's Feeding Programme view are the Feeding
 * Coordinator's own.
 *
 * The page used to compute its own four cards — and an "Improving" figure off
 * the current status that read a permanent 0% mid-cycle. It now renders the
 * coordinator's beneficiary cards from FeedingBeneficiarySummary::tally(), so
 * the two desks report one programme size, one wasting count and one turnout.
 * Improvement lives in the baseline-to-endline panel, whose "not yet
 * measured" share says what a 0% headline used to get wrong.
 */
class NurseFeedingProgramCardsTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function sessionFor(string $role): array
    {
        return [
            'active_role' => $role,
            'active_name' => $role === 'school_nurse' ? 'Nurse Cruz' : 'Feeding Coordinator',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
        ];
    }

    private function beneficiary(string $status, string $section = 'Grade 7 / Matiyaga'): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Learner '.random_int(1000, 9999),
            'student_id' => '1000000000'.random_int(10, 99),
            'school_name' => 'Sta. Ana NHS',
            'section' => $section,
            'weight' => 28,
            'bmi_value' => 14.2,
            'nutritional_status' => $status,
            'baseline_nutritional_status' => $status,
            'feeding_enrolled_at' => now(),
        ]);
    }

    #[Test]
    public function the_nurse_does_not_see_an_improving_card(): void
    {
        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.school-nurse.feeding-program'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('>Improving</div>', $html);
    }

    /** The coordinator's five cards, in a five-column row. */
    #[Test]
    public function the_nurse_reads_the_coordinators_five_cards(): void
    {
        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.school-nurse.feeding-program'))
            ->assertOk()
            ->getContent();

        foreach (['Total Beneficiaries', 'Severely Wasted', 'Wasted', 'For Follow-up', 'Currently Attending'] as $label) {
            $this->assertStringContainsString('<div class="kpi-label">'.$label.'</div>', $html);
        }
        $this->assertStringContainsString('class="kpi-grid cols-5 fp-cards live-pane"', $html);
    }

    /** The Clinic Teacher is never handed a flag, so their row has four cards. */
    #[Test]
    public function the_clinic_teachers_row_has_no_at_risk_card(): void
    {
        $html = $this->withSession(['active_role' => 'clinic_teacher'] + $this->sessionFor('school_nurse'))
            ->get(route('dashboard.clinic-teacher.feeding-program'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<div class="kpi-label">For Follow-up</div>', $html);
        $this->assertStringContainsString('<div class="kpi-label">Currently Attending</div>', $html);
        $this->assertStringContainsString('class="kpi-grid fp-cards live-pane"', $html);
    }

    /**
     * Same reading, same numbers: the nurse's cards and the coordinator's
     * Beneficiaries tab report the same programme.
     */
    #[Test]
    public function the_nurse_and_the_coordinator_count_the_same_programme(): void
    {
        $a = $this->beneficiary('Severely Wasted');
        $this->beneficiary('Wasted');
        $this->beneficiary('Wasted', 'Grade 8 / Rosal');
        // Measured Normal: not a beneficiary on either desk.
        $this->beneficiary('Normal');

        FeedingAttendance::create([
            'student_health_record_id' => $a->id,
            'session_date' => now()->subDay()->toDateString(),
            'is_present' => true,
            'needs_review' => false,
            'source' => 'manual_entry',
        ]);

        $nurse = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.school-nurse.feeding-program'))
            ->assertOk()
            ->viewData('beneficiarySummary');

        $coordinator = $this->withSession($this->sessionFor('feeding_coor'))
            ->get('/dashboard/feedingcor-health-records')
            ->assertOk()
            ->viewData('beneficiarySummary');

        foreach (['beneficiaries', 'severely_wasted', 'wasted', 'at_risk', 'attendance_rate', 'attendance_sessions'] as $key) {
            $this->assertSame($coordinator[$key], $nurse[$key], $key.' differs between the two desks.');
        }
        $this->assertSame(3, $nurse['beneficiaries']);
    }

    /**
     * The coordinator still gets the improvement figure — on their Dashboard's
     * Nutritional Progress panel, which owns the baseline-to-endline comparison.
     */
    #[Test]
    public function the_feeding_coordinator_keeps_the_improvement_figure(): void
    {
        $html = $this->withSession($this->sessionFor('feeding_coor'))
            ->get(route('dashboard.feedingcor-dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Improved Nutritional Status', $html);
    }
}
