<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every role opens on the SIGLA logo.
 *
 * The lockup (images/sigla-logo.png) heads every rail and the sign-in pages;
 * the shield alone (images/sigla-mark.png) is the favicon and the small
 * square topbar icon. The old LUSOG file is gone, and these tests make sure
 * nothing still asks for it — a page that did would show a broken image.
 */
class BrandLogoTest extends TestCase
{
    use RefreshDatabase;

    private const LOCKUP = 'images/sigla-logo.png';

    private const MARK = 'images/sigla-mark.png';

    /** One page per role, plus the retired Deworming rail. */
    public static function rolePages(): array
    {
        return [
            'school nurse' => ['/dashboard/school-nurse', 'school_nurse'],
            'school nurse deworming' => ['/dashboard/school-nurse/deworming', 'school_nurse'],
            'clinic staff' => ['/dashboard/clinic-staff', 'clinic_staff'],
            'clinic teacher' => ['/dashboard/clinic-teacher', 'clinic_teacher'],
            'class adviser' => ['/dashboard/class-adviser', 'class_adviser'],
            'school head' => ['/dashboard/school-head', 'school_head'],
            'feeding coordinator' => ['/dashboard/feedingcor-dashboard', 'feeding_coor'],
            'nutrition coordinator' => ['/dashboard/nutricor-dashboard', 'nutricor'],
            'system admin' => ['/dashboard/system-admin', 'system_admin'],
        ];
    }

    public static function signInPages(): array
    {
        return [
            'sign in' => ['/login'],
            'admin sign in' => ['/admin-login'],
            'account request' => ['/account-request'],
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

    private function assertBranded(string $html, string $uri): void
    {
        $this->assertMatchesRegularExpression(
            '#<img[^>]+src="[^"]*'.preg_quote(self::LOCKUP, '#').'"#',
            $html,
            "{$uri} does not show the SIGLA logo."
        );
        $this->assertMatchesRegularExpression(
            '#<link rel="icon"[^>]+href="[^"]*'.preg_quote(self::MARK, '#').'"#',
            $html,
            "{$uri} does not use the SIGLA shield as its favicon."
        );
        $this->assertStringNotContainsString('lusog-logo', $html, "{$uri} still asks for the retired LUSOG logo.");
    }

    /**
     * @dataProvider rolePages
     */
    public function test_every_role_rail_shows_the_sigla_logo(string $uri, string $role): void
    {
        $html = $this->withSession($this->sessionFor($role))->get($uri)->assertOk()->getContent();

        $this->assertBranded($html, $uri);
    }

    /**
     * @dataProvider signInPages
     */
    public function test_the_sign_in_pages_show_the_sigla_logo(string $uri): void
    {
        $html = $this->get($uri)->assertOk()->getContent();

        $this->assertBranded($html, $uri);
    }

    /**
     * The consent and assessment topbars box their icon at 34x34. The stacked
     * lockup would be squeezed there, so they carry the square shield. The
     * nurse's consent pages lead with their Back link, so one may sit before it.
     */
    public function test_the_square_topbar_icon_is_the_shield(): void
    {
        foreach ([
            '/dashboard/school-nurse/consent-forms' => 'school_nurse',
            '/dashboard/class-adviser/health-assessments' => 'class_adviser',
        ] as $uri => $role) {
            $html = $this->withSession($this->sessionFor($role))->get($uri)->assertOk()->getContent();

            $this->assertMatchesRegularExpression(
                '#<header class="cf-topbar">\s*(?:<a [^>]*class="cf-back[^"]*"[^>]*>[^<]*</a>\s*)?<img src="[^"]*'.preg_quote(self::MARK, '#').'"#',
                $html,
                "{$uri} does not head its topbar with the SIGLA shield."
            );
        }
    }

    public function test_both_logo_files_are_transparent_pngs(): void
    {
        foreach ([self::LOCKUP, self::MARK] as $file) {
            $path = public_path($file);
            $this->assertFileExists($path);

            $head = file_get_contents($path, false, null, 0, 26);
            $this->assertSame("\x89PNG\r\n\x1a\n", substr($head, 0, 8), "{$file} is not a PNG.");
            // IHDR colour type 6 is RGBA: the logo sits on the brand green,
            // so a file without an alpha channel would paint a box around it.
            $this->assertSame(6, ord($head[25]), "{$file} has no alpha channel.");
        }

        [$width, $height] = getimagesize(public_path(self::MARK));
        $this->assertSame($width, $height, 'The favicon mark must be square.');
    }

    public function test_no_view_asks_for_the_retired_lusog_logo(): void
    {
        $this->assertFileDoesNotExist(public_path('images/lusog-logo.png'));

        $offenders = [];
        $views = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));

        foreach ($views as $file) {
            if ($file->isFile() && str_contains(file_get_contents($file->getPathname()), 'lusog-logo')) {
                $offenders[] = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders, 'These views still reference images/lusog-logo.png.');
    }
}
