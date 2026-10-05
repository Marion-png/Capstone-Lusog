<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dashboard, My Students and Enroll Student switch in place.
 *
 * All three are panels of one document, and `?tab=` only ever decided which
 * carried `.active`. Following a rail link to change tabs therefore re-ran the
 * whole dashboard — 23 database round trips, about eight seconds against the
 * hosted Postgres — and re-sent 223 KB, to reveal markup already on screen.
 * A same-page `?tab=` link is now answered by the page: panel swapped, URL
 * pushed, nothing fetched.
 *
 * These tests pin the half a browser is not needed for, and that a later
 * edit could quietly break: the server still renders the right panel for
 * every `?tab=` (a refresh, a bookmark, a link opened in a new tab, a browser
 * with JavaScript off), the rail tells the page which entry is which, and the
 * switcher only lives on the page whose panels it switches. The link-matching
 * rule itself is plain URL logic and is the part most likely to be wrong, so
 * its cases are pinned by name below.
 */
class AdviserInPageTabsTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = Institution::create(['name' => 'Test School', 'status' => 'active']);

        StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Cruz, Juan',
            'student_id' => '123456789012',
            'school_name' => 'Test School',
            'section' => 'Grade 7 / Sampaguita',
            'student_details' => ['gender' => 'Male', 'grade_level' => 'Grade 7', 'section' => 'Sampaguita'],
        ]);
    }

    private function adviserSession(): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Test Adviser',
            'active_username' => 'adviser.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->school->id,
            'assigned_grade_level' => 'Grade 7',
            'assigned_section' => 'Sampaguita',
        ];
    }

    private function page(string $query = ''): string
    {
        return $this->withSession($this->adviserSession())
            ->get('/dashboard/class-adviser'.$query)
            ->assertOk()
            ->getContent();
    }

    /** The section element for one panel, so its class list can be read. */
    private function panelTag(string $html, string $id): string
    {
        $this->assertSame(1, preg_match('/<section id="'.$id.'"[^>]*>/', $html, $m), $id.' is missing.');

        return $m[0];
    }

    // ── The premise ─────────────────────────────────────────────────────

    /**
     * Every panel is in every response. That is what makes switching in place
     * possible at all — and if a later change stops rendering a panel for the
     * tab that is not active, the in-page switch would reveal an empty one.
     */
    #[Test]
    public function every_panel_is_rendered_whichever_tab_is_asked_for(): void
    {
        foreach (['', '?tab=saved', '?tab=form'] as $query) {
            $html = $this->page($query);

            foreach (['prototype-dashboard-panel', 'prototype-saved-panel', 'prototype-form-panel'] as $panel) {
                $this->panelTag($html, $panel);
            }
        }
    }

    // ── A refresh, a bookmark, no JavaScript ────────────────────────────

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function tabs(): array
    {
        return [
            'dashboard' => ['', 'prototype-dashboard-panel', 'dashboard'],
            'my students' => ['?tab=saved', 'prototype-saved-panel', 'students'],
            'enroll student' => ['?tab=form', 'prototype-form-panel', 'students'],
        ];
    }

    /**
     * The URL the switcher pushes is one the server still answers correctly,
     * so reloading after a switch lands where the reader was.
     */
    #[Test]
    #[DataProvider('tabs')]
    public function the_server_still_renders_the_panel_each_tab_names(string $query, string $activePanel, string $railKey): void
    {
        $html = $this->page($query);

        foreach (['prototype-dashboard-panel', 'prototype-saved-panel', 'prototype-form-panel'] as $panel) {
            $tag = $this->panelTag($html, $panel);
            $isActive = (bool) preg_match('/class="[^"]*\bactive\b[^"]*"/', $tag);

            $this->assertSame(
                $panel === $activePanel,
                $isActive,
                "{$panel} has the wrong active state for '{$query}'."
            );
        }

        // And the rail entry it belongs to is the lit one.
        $this->assertMatchesRegularExpression(
            '/class="asb-link active"\s+data-adviser-rail="'.$railKey.'"/',
            $html,
            "The rail does not light '{$railKey}' for '{$query}'."
        );
    }

    // ── The rail and the switcher ───────────────────────────────────────

    /** The page can tell the two same-page rail entries apart. */
    #[Test]
    public function the_rail_marks_its_two_same_page_entries(): void
    {
        $html = $this->page();

        $this->assertSame(1, substr_count($html, 'data-adviser-rail="dashboard"'));
        $this->assertSame(1, substr_count($html, 'data-adviser-rail="students"'));

        // Consent Forms and Nutritional Health Status are other pages, so they
        // are not marked and keep navigating.
        $this->assertSame(2, substr_count($html, 'data-adviser-rail='));
    }

    /** The switcher is on the dashboard, wired to history, and nowhere else. */
    #[Test]
    public function the_switcher_is_on_the_dashboard_and_uses_history(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('window.history.pushState({ adviserTab: tab }', $html);
        $this->assertStringContainsString("window.addEventListener('popstate'", $html);
        $this->assertStringContainsString('isSamePageTab', $html);

        // A modified click is the reader asking for a new tab, and is left alone.
        $this->assertStringContainsString('event.metaKey || event.ctrlKey || event.shiftKey || event.altKey', $html);
    }

    /**
     * The other adviser pages share the rail but not the panels, so clicking
     * My Students there has to be a real navigation. The switcher is not on
     * them, and their marked links are therefore plain links.
     */
    #[Test]
    public function the_switcher_is_not_on_the_other_adviser_pages(): void
    {
        foreach ([
            route('consent-forms.index'),
            route('dashboard.class-adviser.feeding-status'),
        ] as $url) {
            $html = $this->withSession($this->adviserSession())->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('data-adviser-rail="students"', $html, $url.' lost the rail marking.');
            $this->assertStringNotContainsString('isSamePageTab', $html, $url.' would swallow a real navigation.');
        }
    }

    /**
     * Only a bare `?tab=` link is answered in place. `?edit=` opens a learner
     * for editing and `?q=` carries the topbar search — both are state the
     * server renders, so the rule must send them through as navigations.
     */
    #[Test]
    public function the_rule_leaves_edit_and_search_links_to_the_server(): void
    {
        $html = $this->page();

        // Every query key must be `tab`, the path must be this page's, and a
        // hash means a different thing entirely.
        $this->assertStringContainsString("keys.every((key) => key === 'tab')", $html);
        $this->assertStringContainsString('url.pathname !== window.location.pathname', $html);
        $this->assertStringContainsString("if (url.hash !== '')", $html);

        // A history entry the switcher did not make is re-rendered by the
        // server rather than guessed at.
        $this->assertStringContainsString('window.location.reload()', $html);
    }
}
