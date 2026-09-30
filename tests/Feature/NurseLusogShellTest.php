<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The School Nurse pages render on the LUSOG design system.
 *
 * Every nurse tab must inline css/lusog-theme.css, use the logo-led .sb-*
 * rail from partials/nurse-lusog-sidebar, and add .page-ready — without
 * which css/nurse-sidebar.css leaves `.sidebar ~ .main` at opacity 0 and
 * the page renders blank under JS. This guards against a page drifting
 * back to the retired .nsb-* rail or to a private copy of the shell.
 */
class NurseLusogShellTest extends TestCase
{
    use RefreshDatabase;

    /** Nurse tabs that have been moved onto the design system. */
    public static function lusogPages(): array
    {
        return [
            'dashboard' => ['/dashboard/school-nurse'],
            'health records' => ['/dashboard/student-health-records'],
            'review queue' => ['/nurse'],
            'consultation log' => ['/dashboard/consultation-log'],
            'medicine inventory' => ['/dashboard/medicine-inventory'],
            'data visualization' => ['/dashboard/data-visualization'],
            'feeding program' => ['/dashboard/school-nurse/feeding-program'],
        ];
    }

    private function nurseSession(): array
    {
        return [
            'active_role' => 'school_nurse',
            'active_name' => 'Nurse Cruz',
            'active_school_name' => 'Sta. Ana National High School',
            'active_institution_id' => 1,
        ];
    }

    /**
     * @dataProvider lusogPages
     */
    public function test_nurse_page_renders_on_the_lusog_theme(string $uri): void
    {
        $response = $this->withSession($this->nurseSession())->get($uri);

        $response->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('--lg-emerald', $html, "{$uri} does not inline the LUSOG theme");
        $this->assertStringContainsString('sb-section-label', $html, "{$uri} does not render the LUSOG nurse rail");
        $this->assertStringContainsString('page-ready', $html, "{$uri} would render blank: nothing adds .page-ready");
    }

    /**
     * The retired .nsb-* rail must not come back on a converted page.
     *
     * @dataProvider lusogPages
     */
    public function test_nurse_page_does_not_use_the_retired_rail(string $uri): void
    {
        $html = $this->withSession($this->nurseSession())->get($uri)->getContent();

        $this->assertStringNotContainsString('nsb-item', $html, "{$uri} still renders the retired .nsb-* rail");
    }

    /**
     * The nurse's window onto the feeding programme is titled "Nutritional
     * Status Report" — on the rail, in the breadcrumb, in the heading and in
     * the tab title — never "Feeding Program", which is the coordinator's word
     * for their own screens.
     */
    /**
     * Nutrition is one module on the nurse's rail, with two views.
     *
     * It used to be two entries with nearly the same name — "Nutritional
     * Health Status" and, under Health Programs, "Nutritional Status Report" —
     * which is how a nurse learns to guess which one to press. They are not
     * the same reading (one is every learner's weigh-ins, the other the
     * feeding programme's cycle and turnout), so neither was deleted: the
     * second became a view inside the first.
     */
    public function test_nutrition_is_one_rail_entry_with_two_views(): void
    {
        $programme = $this->withSession($this->nurseSession())
            ->get('/dashboard/school-nurse/feeding-program')
            ->assertOk()
            ->getContent();

        // The old second entry is gone from the rail.
        $this->assertStringNotContainsString('Nutritional Status Report', $programme);

        // Both views are reachable from the module's own rail, and this is the
        // programme one.
        $this->assertStringContainsString('class="nh-tabs"', $programme);
        $this->assertStringContainsString('Feeding Programme', $programme);
        $this->assertStringContainsString(route('dashboard.school-nurse.nutritional-status'), $programme);
        $this->assertMatchesRegularExpression('/nh-tab active[^>]*>\s*<span class="nh-tab-label">Feeding Programme/s', $programme);

        // One entry on the rail, lit on this view too.
        $this->assertMatchesRegularExpression('/class="sb-link active">.*?Nutritional Health Status\s*<\/a>/s', $programme);
        $this->assertSame(
            1,
            preg_match_all('/class="sb-link[^"]*"[^>]*>.*?Nutritional Health Status\s*<\/a>/s', $programme),
            'Nutrition is one entry on the rail, not two.'
        );

        // And the learner roll is the module's other view.
        $learners = $this->withSession($this->nurseSession())
            ->get('/dashboard/school-nurse/nutritional-status')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('class="nh-tabs"', $learners);
        $this->assertMatchesRegularExpression('/nh-tab active[^>]*>\s*<span class="nh-tab-label">Learners/s', $learners);
        $this->assertStringContainsString(route('dashboard.school-nurse.feeding-program'), $learners);
    }

    /**
     * The nurse reads the whole school's nutritional health status.
     *
     * They already open every learner's record one at a time; "how many
     * children are wasted this year" had no screen that answered it. It is the
     * School Head's list — one controller, one reading, rendered in whichever
     * rail the reader belongs to — so the two desks can never report different
     * figures for the same school.
     */
    public function test_the_nurse_can_read_the_nutritional_health_status_list(): void
    {
        $html = $this->withSession($this->nurseSession())
            ->get('/dashboard/school-nurse/nutritional-status')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Nutritional Health Status', $html);

        // Drawn in the nurse's own shell, not the head's: a nurse's click must
        // never land on the School Head's menu.
        $this->assertStringContainsString('sb-section-label', $html);
        $this->assertStringNotContainsString('asb-link-text', $html);
        $this->assertStringContainsString('page-ready', $html, 'A nurse page that never adds .page-ready renders blank.');
        $this->assertStringContainsString('<span>School Nurse</span>', $html);

        // And the rail offers it.
        $rail = $this->withSession($this->nurseSession())
            ->get('/dashboard/school-nurse')
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('/dashboard/school-nurse/nutritional-status', $rail);
    }

    /** It reads; it offers nothing to change. */
    public function test_the_nutritional_status_list_is_read_only_for_the_nurse(): void
    {
        $html = $this->withSession($this->nurseSession())
            ->get('/dashboard/school-nurse/nutritional-status')
            ->assertOk()
            ->getContent();

        // Measurements belong to the class adviser and enrolment to the
        // coordinator, so the page carries no write of its own. The one form
        // on it is the GET filter toolbar; the only POST in the document is
        // the rail's logout, which is the browser's own session and not
        // school data.
        $this->assertStringContainsString('<form method="GET" class="card sh-toolbar"', $html);
        $this->assertStringNotContainsString('enrollment.store', $html);
        $this->assertStringNotContainsString('storeBaseline', $html);
        $this->assertSame(
            1,
            substr_count(strtolower($html), 'method="post"'),
            'The only POST on the page is the rail\x27s logout.'
        );
    }

    /** A role with no business in it is turned away. */
    public function test_another_role_cannot_open_the_nutritional_status_list(): void
    {
        foreach (['feeding_coor', 'nutricor', 'clinic_staff'] as $role) {
            $this->withSession(array_merge($this->nurseSession(), ['active_role' => $role]))
                ->get('/dashboard/school-nurse/nutritional-status')
                ->assertRedirect(route('login'));
        }
    }
}
