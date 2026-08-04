<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The System Admin has no row in `accounts` — it is the account that approves
 * those — so its credentials come from config/system_admin.php.
 */
class SystemAdminLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('system_admin.username', 'systemadmin');
        Config::set('system_admin.password', 'correct-horse');
        Config::set('system_admin.password_hash', null);
    }

    #[Test]
    public function correct_credentials_sign_the_admin_in(): void
    {
        $response = $this->post('/admin-login', [
            'username' => 'systemadmin',
            'password' => 'correct-horse',
        ]);

        $response->assertRedirect(route('dashboard.system-admin'));
        $this->assertSame('system_admin', session('active_role'));
        $this->assertSame('System Admin', session('active_name'));
    }

    #[Test]
    public function a_wrong_password_is_rejected(): void
    {
        $this->post('/admin-login', [
            'username' => 'systemadmin',
            'password' => 'wrong',
        ]);

        $this->assertNull(session('active_role'));
    }

    #[Test]
    public function a_wrong_username_is_rejected(): void
    {
        $this->post('/admin-login', [
            'username' => 'notadmin',
            'password' => 'correct-horse',
        ]);

        $this->assertNull(session('active_role'));
    }

    #[Test]
    public function a_bcrypt_password_hash_is_accepted(): void
    {
        Config::set('system_admin.password', null);
        Config::set('system_admin.password_hash', Hash::make('hashed-secret'));

        $this->post('/admin-login', [
            'username' => 'systemadmin',
            'password' => 'hashed-secret',
        ]);

        $this->assertSame('system_admin', session('active_role'));
    }

    #[Test]
    public function the_hash_takes_precedence_over_the_plaintext_password(): void
    {
        Config::set('system_admin.password', 'stale-plaintext');
        Config::set('system_admin.password_hash', Hash::make('the-real-one'));

        $this->post('/admin-login', ['username' => 'systemadmin', 'password' => 'stale-plaintext']);
        $this->assertNull(session('active_role'), 'A superseded plaintext password must not still work.');

        $this->post('/admin-login', ['username' => 'systemadmin', 'password' => 'the-real-one']);
        $this->assertSame('system_admin', session('active_role'));
    }

    #[Test]
    public function login_is_refused_when_no_credentials_are_configured(): void
    {
        Config::set('system_admin.password', '');
        Config::set('system_admin.password_hash', '');

        $this->post('/admin-login', ['username' => 'systemadmin', 'password' => '']);

        $this->assertNull(session('active_role'), 'A blank configured secret must never authenticate.');
    }

    #[Test]
    public function signing_in_clears_any_school_scope_from_a_previous_session(): void
    {
        $institution = Institution::create(['name' => 'School A', 'status' => 'active']);

        // A school user was signed in on this browser first.
        $this->withSession([
            'active_role' => 'class_adviser',
            'active_institution_id' => $institution->id,
            'active_school_name' => 'School A',
            'assigned_grade_level' => 'Grade 7/SPED',
            'assigned_section' => 'Rosal',
        ])->post('/admin-login', [
            'username' => 'systemadmin',
            'password' => 'correct-horse',
        ]);

        $this->assertSame('system_admin', session('active_role'));
        $this->assertNull(session('active_institution_id'), 'An unscoped admin must not inherit a school binding.');
        $this->assertNull(session('active_school_name'));
        $this->assertNull(session('assigned_grade_level'));
        $this->assertNull(session('assigned_section'));
    }

    #[Test]
    public function a_failed_attempt_is_audited(): void
    {
        $this->post('/admin-login', ['username' => 'systemadmin', 'password' => 'wrong']);

        $this->assertTrue(
            AuditLog::where('action', 'login_failed')->exists(),
            'A failed System Admin login must leave an audit entry.',
        );
    }

    /**
     * Guards the bug this fix was for: an env() call outside config/ returns
     * its fallback once `php artisan config:cache` has run, because the .env
     * file is no longer loaded. For the admin credentials that silently
     * restored the default password on a production deploy.
     *
     * Config files are the only place env() may be called, so this scans the
     * whole of app/ and routes/ rather than just the login route.
     */
    #[Test]
    public function no_runtime_code_reads_the_environment_directly(): void
    {
        $offenders = [];

        foreach (['app', 'routes'] as $directory) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($directory))
            );

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                foreach (file($file->getPathname(), FILE_IGNORE_NEW_LINES) as $number => $line) {
                    // Ignore comment lines so prose about env() does not trip this.
                    $code = trim($line);

                    if ($code === '' || str_starts_with($code, '//') || str_starts_with($code, '*') || str_starts_with($code, '/*')) {
                        continue;
                    }

                    if (preg_match('/(?<![A-Za-z_])env\s*\(/', $code)) {
                        $offenders[] = $file->getPathname().':'.($number + 1);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "env() must only be called from config/ — these calls return their fallback under config:cache:\n".implode("\n", $offenders),
        );
    }

    #[Test]
    public function the_admin_credentials_come_from_the_system_admin_config(): void
    {
        $this->assertNotNull(config('system_admin.username'));
        $this->assertArrayHasKey('password_hash', config('system_admin'));
    }
}
