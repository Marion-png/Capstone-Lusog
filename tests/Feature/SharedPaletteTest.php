<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every role renders on one palette.
 *
 * Pages already migrated to css/lusog-theme.css get their colours from the
 * theme. Everything else inlines css/lusog-palette.css *after* its own
 * styles, which re-points the older private green ramps (--g900, --text-3,
 * --border, …) at LUSOG values. Both routes must end at the same emerald.
 *
 * These tests pin the two ways that quietly breaks: a page losing the
 * palette include, and the palette being loaded before the page's own
 * :root (where it would lose the cascade and change nothing).
 */
class SharedPaletteTest extends TestCase
{
    use RefreshDatabase;

    /** One representative page per role, with the role that may open it. */
    public static function rolePages(): array
    {
        return [
            'class adviser dashboard' => ['/dashboard/class-adviser', 'class_adviser'],
            'adviser entry form' => ['/adviser/create', 'class_adviser'],
            'adviser consent forms' => ['/dashboard/class-adviser/consent-forms', 'class_adviser'],
            'adviser feeding status' => ['/dashboard/class-adviser/feeding-status', 'class_adviser'],
            'clinic staff' => ['/dashboard/clinic-staff', 'clinic_staff'],
            'school head' => ['/dashboard/school-head', 'school_head'],
            'school head reports' => ['/dashboard/school-head/reports', 'school_head'],
            'feeding coordinator' => ['/dashboard/feedingcor-dashboard', 'feeding_coor'],
            'nutricor dashboard' => ['/dashboard/nutricor-dashboard', 'nutricor'],
            'nutricor analytics' => ['/dashboard/nutricor-analytics', 'nutricor'],
            'nutricor reports' => ['/dashboard/nutricor-reports', 'nutricor'],
            'system admin' => ['/dashboard/system-admin', 'system_admin'],
            'system admin audit' => ['/dashboard/system-admin/audit-logs', 'system_admin'],
            'nurse deworming' => ['/dashboard/school-nurse/deworming', 'school_nurse'],
            'nurse data visualization' => ['/dashboard/data-visualization', 'school_nurse'],
            'nurse health assessments' => ['/dashboard/school-nurse/health-assessments', 'school_nurse'],
        ];
    }

    private function sessionFor(string $role): array
    {
        return [
            'active_role' => $role,
            'active_name' => 'Test User',
            'active_username' => 'tester',
            'active_school_name' => 'Sta. Ana National High School',
            'active_institution_id' => 1,
            'assigned_grade_level' => 'Grade 7',
            'assigned_section' => 'Rizal',
        ];
    }

    /**
     * @dataProvider rolePages
     */
    public function test_every_role_page_renders_on_the_lusog_emerald(string $uri, string $role): void
    {
        $response = $this->withSession($this->sessionFor($role))->get($uri);

        $response->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString(
            '#126B3A',
            $html,
            "{$uri} does not carry the LUSOG emerald — it is missing the theme or the shared palette."
        );
    }

    /**
     * The palette only works if it comes last. A page that inlines it
     * before its own :root would keep the old greens silently.
     *
     * @dataProvider rolePages
     */
    public function test_the_shared_palette_is_loaded_after_the_pages_own_styles(string $uri, string $role): void
    {
        $html = $this->withSession($this->sessionFor($role))->get($uri)->assertOk()->getContent();

        $paletteAt = strpos($html, '--lg-emerald-deep');

        if ($paletteAt === false) {
            $this->fail("{$uri} defines no LUSOG tokens at all.");
        }

        // The legacy ramp is what the palette has to beat. Where a page
        // declares one, the palette's copy must appear after it.
        $legacyAt = strpos($html, '--g900');

        if ($legacyAt !== false) {
            $paletteRampAt = strpos($html, '--g900: #0E5730');
            $this->assertNotFalse($paletteRampAt, "{$uri} never loads the bridged ramp.");
            $this->assertGreaterThan(
                $legacyAt,
                $paletteRampAt,
                "{$uri} loads the shared palette before its own :root, so the old greens still win."
            );
        }

        $this->assertTrue(true);
    }

    /**
     * No inlined stylesheet may contain the sequence that closes a style
     * element.
     *
     * These sheets are inlined into <style> blocks. An HTML parser does not
     * read CSS comments — it ends the element at the first closing sequence
     * it sees, anywhere, and every rule after that spills onto the page as
     * visible text. A usage example inside a comment is enough to do it.
     */
    public function test_no_inlined_stylesheet_can_close_its_own_style_element(): void
    {
        $closer = '</'.'style';

        foreach (glob(resource_path('css/*.css')) as $sheet) {
            $this->assertStringNotContainsString(
                $closer,
                file_get_contents($sheet),
                basename($sheet).' contains a style-closing sequence and would break every page that inlines it.'
            );
        }
    }

    /**
     * Rendered pages open and close the same number of style elements.
     *
     * Catches the same fault from the other side: if a sheet ever smuggles
     * a closing tag in, the rendered HTML ends up with more closes than
     * opens and the page leaks CSS as text.
     *
     * @dataProvider rolePages
     */
    public function test_rendered_pages_have_balanced_style_elements(string $uri, string $role): void
    {
        $html = $this->withSession($this->sessionFor($role))->get($uri)->assertOk()->getContent();

        $this->assertSame(
            substr_count($html, '<style'),
            substr_count($html, '</'.'style>'),
            "{$uri} has unbalanced style elements — CSS will render as page text."
        );
    }

    /** The sign-in page is public, so it never reaches the role provider. */
    public function test_the_sign_in_page_does_not_leak_css_as_text(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertSame(
            substr_count($html, '<style'),
            substr_count($html, '</'.'style>'),
            'The sign-in page has unbalanced style elements.'
        );

        // The palette's opening comment must stay inside the style element.
        $paletteAt = strpos($html, 'LUSOG — shared palette');
        if ($paletteAt !== false) {
            $bodyAt = strpos($html, '<body');
            $this->assertLessThan($bodyAt, $paletteAt, 'The palette is rendering inside the page body.');
        }
    }

    /** Pages on the theme must not double-load the palette. */
    public function test_theme_pages_do_not_also_inline_the_palette(): void
    {
        $themed = [
            'resources/views/dashboard/school-nurse.blade.php',
            'resources/views/feedingcor-dashboard/feed-dashboard.blade.php',
            'resources/views/schoolhead-dashboard/school-head.blade.php',
        ];

        foreach ($themed as $path) {
            $src = file_get_contents(base_path($path));

            $this->assertStringContainsString('lusog-theme.css', $src);
            $this->assertStringNotContainsString(
                'lusog-palette.css',
                $src,
                "{$path} is on the theme and must not also inline the palette."
            );
        }
    }
}
