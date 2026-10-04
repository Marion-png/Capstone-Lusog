<?php

namespace Tests\Feature;

use App\Models\AttendanceImport;
use App\Models\ConsultationPhoto;
use App\Models\HealthConsentForm;
use App\Models\MedicalCertificate;
use App\Models\ParentalConsentForm;
use App\Models\StudentPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * What a visitor can learn about the server from the outside — a browser's
 * Inspect Element, a crawler, or a guessed URL.
 *
 * The deployment once served public/phpinfo-check.php, which printed every
 * environment variable (APP_KEY and the database password among them) to
 * anyone who asked. These pin the layers that keep that from recurring.
 */
class PublicExposureTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function public_holds_no_script_but_the_front_controller(): void
    {
        $scripts = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(public_path(), RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (strtolower($file->getExtension()) === 'php') {
                $scripts[] = str_replace('\\', '/', substr($file->getPathname(), strlen(public_path()) + 1));
            }
        }

        $this->assertSame(['index.php'], $scripts, 'Anything in public/ ending in .php can be run by visiting it.');
    }

    #[Test]
    public function the_web_server_runs_only_the_front_controller_and_hides_dotfiles(): void
    {
        $caddyfile = (string) file_get_contents(base_path('Caddyfile'));

        $this->assertStringContainsString('path *.php *.php/*', $caddyfile);
        $this->assertStringContainsString('not path /index.php /index.php/*', $caddyfile);
        $this->assertStringContainsString('respond @scripts 404', $caddyfile);
        $this->assertStringContainsString('respond @hidden 404', $caddyfile);
        $this->assertStringContainsString('php_ini expose_php Off', $caddyfile);
        $this->assertStringContainsString('root * {{.RAILPACK_PHP_ROOT_DIR}}', $caddyfile);
    }

    #[Test]
    public function every_response_carries_the_browser_protections(): void
    {
        foreach ([$this->get('/login'), $this->get('/no-such-page-anywhere')] as $response) {
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
            $response->assertHeader('Referrer-Policy', 'same-origin');
            $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            $response->assertHeader('Permissions-Policy');
        }
    }

    #[Test]
    public function https_is_pinned_only_when_the_request_came_over_https(): void
    {
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');

        // Railway's edge terminates TLS and forwards X-Forwarded-Proto.
        $this->withHeader('X-Forwarded-Proto', 'https')
            ->get('/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    #[Test]
    public function crawlers_are_asked_to_index_nothing(): void
    {
        $robots = (string) file_get_contents(public_path('robots.txt'));

        $this->assertMatchesRegularExpression('/^Disallow:\s*\/\s*$/m', $robots);
    }

    #[Test]
    public function a_stored_file_location_is_never_serialized(): void
    {
        $this->assertArrayNotHasKey('file_path', (new MedicalCertificate(['file_path' => 'medical-certificates/a.enc']))->toArray());
        $this->assertArrayNotHasKey('file_path', (new ConsultationPhoto(['file_path' => 'consultation-photos/a.enc']))->toArray());
        $this->assertArrayNotHasKey('file_path', (new StudentPhoto(['file_path' => 'student-photos/a.enc']))->toArray());
        $this->assertArrayNotHasKey('stored_path', (new AttendanceImport(['stored_path' => 'attendance/a.enc']))->toArray());

        $consent = (new ParentalConsentForm(['file_path' => 'consents/a.enc', 'med_cert_path' => 'consents/b.enc']))->toArray();
        $this->assertArrayNotHasKey('file_path', $consent);
        $this->assertArrayNotHasKey('med_cert_path', $consent);

        $form = (new HealthConsentForm(['token' => 'parent-link-token', 'paper_form_path' => 'paper/a.enc']))->toArray();
        $this->assertArrayNotHasKey('token', $form);
        $this->assertArrayNotHasKey('paper_form_path', $form);

        // Hidden from serialization only: the code that serves a file still reads it.
        $this->assertSame('medical-certificates/a.enc', (new MedicalCertificate(['file_path' => 'medical-certificates/a.enc']))->file_path);
    }
}
