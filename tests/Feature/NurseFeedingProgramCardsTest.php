<?php

namespace Tests\Feature;

use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The School Nurse's read-only Feeding Program view drops the "Improving"
 * figure; the Feeding Coordinator keeps it.
 *
 * Improvement is a baseline-to-endline comparison, so it only means anything
 * once the closing measurement exists. The nurse reads this page mid-cycle
 * and would see a permanent 0%, which reads as "nobody improved" rather than
 * "not measured yet". The coordinator owns the endline, so the figure stays
 * on their side.
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

    #[Test]
    public function the_nurse_does_not_see_the_improving_card(): void
    {
        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.school-nurse.feeding-program'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('>Improving</div>', $html);
    }

    /** The other three figures are untouched. */
    #[Test]
    public function the_nurse_still_sees_the_other_three_figures(): void
    {
        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.school-nurse.feeding-program'))
            ->assertOk()
            ->getContent();

        foreach (['Enrolled Students', 'Program Day', 'Avg. Attendance'] as $label) {
            $this->assertStringContainsString('>'.$label.'</div>', $html);
        }
    }

    /** Three cards means a three-column row, not four with a hole. */
    #[Test]
    public function the_nurses_figure_row_closes_up(): void
    {
        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.school-nurse.feeding-program'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('class="kpi-grid cols-3"', $html);
    }

    /**
     * The coordinator still gets the improvement figure — on their Dashboard's
     * Nutritional Progress panel, which owns the baseline-to-endline comparison.
     * They have no Feeding Program tab to carry a card any more.
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
