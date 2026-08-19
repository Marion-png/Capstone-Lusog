<?php

namespace Tests\Feature;

use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Class Adviser and the School Nurse search for a learner with the same
 * control — partials/learner-search.
 *
 * Each role grew its own version and they drifted: different field shape,
 * avatar size (36px against 32px), result fields, result count, and empty
 * state. Two implementations of one control is how they got there, so the
 * fix is one implementation, not two patched to match. These tests fail if
 * either role starts rendering its own again.
 *
 * The behaviour that legitimately differs is the destination and whether
 * Enter submits: the adviser has a filtered list to fall back to when a
 * partial name matches several learners; the nurse does not.
 */
class SharedLearnerSearchTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function roster(): array
    {
        return [[
            'lrn' => '600000000001',
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'middle_name' => 'Reyes',
            'grade_level' => 'Grade 10',
            'section' => 'Dalton',
            'gender' => 'Male',
        ]];
    }

    private function sessionFor(string $role): array
    {
        return [
            'active_role' => $role,
            'active_name' => 'Staff Member',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
            'assigned_grade_level' => 'Grade 10',
            'assigned_section' => 'Dalton',
            'school_health_card_records' => $this->roster(),
        ];
    }

    private function adviserPage(): string
    {
        return $this->withSession($this->sessionFor('class_adviser'))
            ->get(route('dashboard.class-adviser'))
            ->assertOk()
            ->getContent();
    }

    private function nursePage(): string
    {
        return $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.school-nurse'))
            ->assertOk()
            ->getContent();
    }

    /** One markup, one set of ids, on both. */
    #[Test]
    public function both_roles_render_the_same_search_markup(): void
    {
        foreach (['adviser' => $this->adviserPage(), 'nurse' => $this->nursePage()] as $role => $html) {
            foreach (['id="lsearchBox"', 'id="lsearchInput"', 'id="lsearchResults"'] as $marker) {
                $this->assertStringContainsString($marker, $html, "The $role search is missing $marker.");
            }

            $this->assertStringContainsString('class="lsearch-dropdown"', $html);
            $this->assertStringContainsString('placeholder="Search students..."', $html);
        }
    }

    /** One stylesheet, so the two cannot look different. */
    #[Test]
    public function both_roles_render_the_same_search_styles(): void
    {
        foreach (['adviser' => $this->adviserPage(), 'nurse' => $this->nursePage()] as $role => $html) {
            foreach (['.lsearch-row', '.lsearch-avatar', '.lsearch-count', '.lsearch-empty'] as $rule) {
                $this->assertStringContainsString($rule, $html, "The $role page is missing $rule.");
            }
        }
    }

    /** Neither role keeps its old private version. */
    #[Test]
    public function the_two_private_versions_are_gone(): void
    {
        $adviser = $this->adviserPage();
        $nurse = $this->nursePage();

        foreach (['asb-search', 'asb-result-avatar', 'asb-no-results'] as $dead) {
            $this->assertStringNotContainsString($dead, $adviser);
        }

        foreach (['nurse-search', 'learnerFind', 'lf-avatar', 'lf-empty'] as $dead) {
            $this->assertStringNotContainsString($dead, $nurse);
        }
    }

    /**
     * Included once per page. A second copy would bind its listeners to the
     * first copy's ids and the dropdown would answer twice.
     */
    #[Test]
    public function the_search_is_rendered_once_per_page(): void
    {
        foreach (['adviser' => $this->adviserPage(), 'nurse' => $this->nursePage()] as $role => $html) {
            $this->assertSame(1, substr_count($html, 'id="lsearchInput"'), "The $role page renders the search twice.");
            $this->assertSame(1, substr_count($html, '.lsearch-avatar {'), "The $role page inlines the stylesheet twice.");
        }
    }

    /** The same roster fields reach the same renderer on both sides. */
    #[Test]
    public function both_rosters_carry_the_lrn_name_and_section(): void
    {
        foreach (['adviser' => $this->adviserPage(), 'nurse' => $this->nursePage()] as $role => $html) {
            $this->assertStringContainsString('600000000001', $html, "The $role roster lost the LRN.");
            $this->assertStringContainsString('Cruz, Juan R.', $html, "The $role roster lost the name.");
            $this->assertStringContainsString('Grade 10 - Dalton', $html, "The $role roster lost the section.");
        }
    }

    /**
     * What differs is the destination, and that is the point of the shared
     * parameter — not a second implementation.
     */
    #[Test]
    public function each_role_keeps_its_own_destination(): void
    {
        $this->assertStringContainsString(
            json_encode(url('dashboard/class-adviser/students').'/{lrn}'),
            $this->adviserPage()
        );

        $this->assertStringContainsString(
            json_encode(route('dashboard.student-health-records').'?open={lrn}'),
            $this->nursePage()
        );
    }

    /**
     * Enter searches the adviser's list, because a partial name can match
     * several learners there. The nurse has no such list, so their search is
     * not a form and submits nothing.
     */
    #[Test]
    public function only_the_adviser_search_submits(): void
    {
        $this->assertStringContainsString(
            '<form method="GET" action="'.route('dashboard.class-adviser').'" class="lsearch" id="lsearchBox">',
            $this->adviserPage()
        );

        $this->assertStringContainsString('<div class="lsearch" id="lsearchBox">', $this->nursePage());
    }

    /**
     * Every adviser tab carries it too. That side already worked, because
     * its topbar is one shared partial — this pins that it stays that way.
     */
    #[Test]
    public function the_search_is_on_every_adviser_tab(): void
    {
        $routes = [
            'dashboard.class-adviser',
            'consent-forms.index',
            'dashboard.class-adviser.feeding-status',
        ];

        foreach ($routes as $route) {
            $html = $this->withSession($this->sessionFor('class_adviser'))
                ->get(route($route))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString('id="lsearchInput"', $html, "$route has no search.");
            $this->assertStringContainsString('placeholder="Search students..."', $html);
        }
    }

    /**
     * Results are built from DOM nodes. Learner names are typed by an adviser
     * and a template string would run any markup inside one.
     */
    #[Test]
    public function results_are_never_built_from_innerhtml(): void
    {
        foreach (['adviser' => $this->adviserPage(), 'nurse' => $this->nursePage()] as $role => $html) {
            $start = strpos($html, "const box = document.getElementById('lsearchBox');");
            $this->assertNotFalse($start, "The $role page is missing the search script.");
            $script = substr($html, $start, (int) strpos($html, '</script>', $start) - $start);

            // The word appears in the partial's own comment explaining why it
            // is not used, so look for the assignment, not the name.
            $this->assertStringNotContainsString('innerHTML =', $script);
            $this->assertStringNotContainsString('insertAdjacentHTML', $script);
            $this->assertStringContainsString('textContent', $script);
        }
    }
}
