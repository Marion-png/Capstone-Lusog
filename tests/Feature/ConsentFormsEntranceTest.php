<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Consent Forms rises into place when opened from the nurse rail.
 *
 * The entrance is pure CSS on purpose. A JS-driven reveal that fails to
 * run would leave the page invisible, so these tests pin the two things
 * that would silently break it: the opt-in class on <body>, and the fact
 * that the shared stylesheet's print block never inherits the animation.
 */
class ConsentFormsEntranceTest extends TestCase
{
    use RefreshDatabase;

    private function nurseSession(): array
    {
        return [
            'active_role' => 'school_nurse',
            'active_name' => 'Nurse Cruz',
            'active_school_name' => 'Sta. Ana National High School',
            'active_institution_id' => 1,
        ];
    }

    public function test_the_page_opts_into_the_rise_animation(): void
    {
        $html = $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<body class="cf-rise">', $html);
        $this->assertStringContainsString('cf-rise-in', $html, 'The keyframes must be inlined with the page.');
        $this->assertStringContainsString('.cf-rise .cf-wrap', $html);
    }

    public function test_the_animation_is_confined_to_screen_and_respects_reduced_motion(): void
    {
        $css = file_get_contents(resource_path('css/consent-form.css'));

        $screenAt = strpos($css, '@media screen');
        $keyframesAt = strpos($css, '@keyframes cf-rise-in');
        $printAt = strpos($css, '@media print');

        $this->assertNotFalse($screenAt);
        $this->assertNotFalse($keyframesAt);
        $this->assertNotFalse($printAt);

        // The keyframes live inside the screen-only block, which closes
        // before the print block begins.
        $this->assertGreaterThan($screenAt, $keyframesAt, 'Keyframes must sit inside @media screen.');
        $this->assertLessThan($printAt, $keyframesAt, 'Keyframes must not fall into the print block.');

        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertMatchesRegularExpression(
            '/prefers-reduced-motion[^{]*\{[^}]*\.cf-rise[^}]*animation:\s*none/s',
            $css,
            'Reduced motion must switch the rise off.'
        );
    }

    /** The parent-facing form shares the sheet and must not animate. */
    public function test_the_parent_consent_page_does_not_opt_in(): void
    {
        $html = file_get_contents(resource_path('views/consent-forms/parent.blade.php'));

        $this->assertStringNotContainsString('cf-rise', $html);
    }
}
