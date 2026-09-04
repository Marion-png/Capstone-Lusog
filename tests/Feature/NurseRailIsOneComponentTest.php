<?php

namespace Tests\Feature;

use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every nurse page draws its rail from one partial.
 *
 * The Feeding Program page used to hand-copy the rail for the nurse's
 * read-only view, and the copy drifted: renaming "Review Queue" to "Health
 * Assessment" in partials/nurse-lusog-sidebar left that page still showing
 * the old label and the retired Health Assessments tab, and two of its links
 * pointed at "#". A rail is navigation — if one page disagrees about what
 * the tabs are called, the reader cannot tell which is right.
 *
 * So these tests check the labels on every nurse tab, not just on the one
 * page a change happened to touch.
 */
class NurseRailIsOneComponentTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    /** Every destination on the nurse rail that renders the rail back. */
    public static function nursePages(): array
    {
        return [
            'Dashboard' => ['dashboard.school-nurse'],
            'Health Records' => ['dashboard.student-health-records'],
            'Health Assessment' => ['nurse.index'],
            'Consultation Log' => ['dashboard.consultation-log'],
            'Feeding Program' => ['dashboard.school-nurse.feeding-program'],
            'Medicine Inventory' => ['dashboard.medicine-inventory'],
            'Dispensing Log' => ['dashboard.dispensing-log'],
            'Data Visualization' => ['dashboard.data-visualization'],
        ];
    }

    private function nurseSession(): array
    {
        return [
            'active_role' => 'school_nurse',
            'active_name' => 'Nurse Cruz',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
            'school_health_card_records' => [],
        ];
    }

    private function page(string $route): string
    {
        return $this->withSession($this->nurseSession())
            ->get(route($route))
            ->assertOk()
            ->getContent();
    }

    /**
     * Just the rail's own markup.
     *
     * Every nurse page inlines its stylesheets, and those carry comments —
     * `learner-search.css` mentions "Health Assessments" while explaining a
     * layout override, and `school-nurse-review-queue.css` is named after the
     * old label. Asserting against the whole document would be testing CSS
     * prose rather than navigation.
     */
    private function rail(string $route): string
    {
        $html = $this->page($route);

        $start = strpos($html, '<nav class="sb-nav">');
        $this->assertNotFalse($start, "The nurse rail is missing from $route.");

        $end = strpos($html, '</nav>', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    /**
     * The rail names it once, as "Health Assessment". Both the old label and
     * the retired duplicate tab must be gone everywhere, not just on the page
     * the rename was applied to.
     */
    #[DataProvider('nursePages')]
    #[Test]
    public function the_rail_carries_the_current_labels_on_this_page(string $route): void
    {
        $rail = $this->rail($route);

        $this->assertStringContainsString('Health Assessment', $rail);
        $this->assertStringNotContainsString('Review Queue', $rail);
        // The plural was the retired duplicate tab; the singular is the one
        // that stayed, so an "s" here means the old entry is back.
        $this->assertStringNotContainsString('Health Assessments', $rail);
    }

    /**
     * Every page offers the same destinations, in the same order.
     *
     * Not byte-equality — the rail legitimately varies (the active tab, the
     * pending-cards badge). What must not vary is where it can take you, and
     * that is exactly what the hand-copied Feeding Program rail got wrong: it
     * listed a tab the shared rail had dropped and pointed two entries at "#".
     */
    #[DataProvider('nursePages')]
    #[Test]
    public function this_page_offers_the_same_destinations(string $route): void
    {
        $this->assertSame(
            $this->linksOn('dashboard.school-nurse'),
            $this->linksOn($route),
            "The rail on $route offers different destinations from the Dashboard's — it is a copy, not the shared partial."
        );
    }

    /**
     * The hrefs in the rail, in order.
     *
     * @return list<string>
     */
    private function linksOn(string $route): array
    {
        preg_match_all('/href="([^"]*)"/', $this->rail($route), $matches);

        return $matches[1];
    }

    /**
     * The Feeding Program page is the one that drifted, so it gets its own
     * check: it must include the shared partial rather than repeat it.
     */
    #[Test]
    public function the_feeding_program_page_draws_the_shared_rail(): void
    {
        $html = $this->page('dashboard.school-nurse.feeding-program');

        // Markers only the shared partial renders.
        $this->assertStringContainsString('class="sb-logo-full"', $html);
        $this->assertStringContainsString('Consent Forms', $html);

        // And exactly one rail, not two.
        $this->assertSame(1, substr_count($html, '<nav class="sb-nav">'));
    }

    /**
     * The Feeding Coordinator reads the same Blade file through the other
     * branch, and must still get their own rail — not the nurse's.
     */
    #[Test]
    public function the_coordinator_still_gets_their_own_rail(): void
    {
        $html = $this->withSession([
            'active_role' => 'feeding_coor',
            'active_name' => 'Coordinator Reyes',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
        ])->get(route('dashboard.feedingcor-dashboard'))->assertOk()->getContent();

        // The coordinator has no clinic tabs.
        $this->assertStringNotContainsString('Health Assessment', $html);
        $this->assertStringNotContainsString('Consultation Log', $html);
    }
}
