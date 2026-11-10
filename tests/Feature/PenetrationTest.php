<?php

namespace Tests\Feature;

use App\Models\Institution;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The attacks an outsider would try first, run against the application on
 * every test run: guessing passwords, walking in through an account with no
 * password, the public default admin password, reaching a page or a write
 * without signing in, a school account with no school, and SQL typed into the
 * sign-in form. Each one must fail.
 */
class PenetrationTest extends TestCase
{
    use RefreshDatabase;

    /** Pages anybody may open or post to, by design. */
    private const PUBLIC_URIS = [
        '/', 'login', 'admin-login', 'logout', 'account-request', 'up',
        'consent/{token}',
        // The registration form's school and section lists: school structure,
        // no personal data.
        'api/institutions', 'api/institutions/{institutionId}/sections',
    ];

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.prototype_sessions' => false]);
        $this->school = Institution::create(['name' => 'Pentest School', 'status' => 'active']);
    }

    private function account(string $username, ?string $password, string $role = 'school_nurse'): void
    {
        DB::table('accounts')->insert([
            'name' => 'Test Person',
            'username' => $username,
            'password_hash' => $password === null ? null : Hash::make($password),
            'role' => $role,
            'institution_id' => $this->school->id,
            'school_name' => $this->school->name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ── Sign-in ─────────────────────────────────────────────────────────────

    #[Test]
    public function an_account_with_no_password_cannot_be_entered_with_any_password(): void
    {
        $this->account('no.password', null);

        foreach (['', 'anything', 'admin123', 'password1'] as $guess) {
            $this->post('/login', ['email' => 'no.password', 'password' => $guess ?: 'x']);
            $this->assertFalse(session()->has('active_role'), "'{$guess}' opened a passwordless account.");
        }
    }

    #[Test]
    public function an_unknown_username_reads_the_same_as_a_wrong_password(): void
    {
        $this->account('real.user', 'password1');

        $this->post('/login', ['email' => 'nobody.here', 'password' => 'password1'])
            ->assertSessionHas('error', 'Invalid username or password.');
        $this->post('/login', ['email' => 'real.user', 'password' => 'wrong1234'])
            ->assertSessionHas('error', 'Invalid username or password.');
    }

    #[Test]
    public function guessing_passwords_locks_the_username_even_before_the_right_one(): void
    {
        $this->account('target.user', 'password1');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'target.user', 'password' => "guess{$i}abc"]);
        }

        $this->post('/login', ['email' => 'target.user', 'password' => 'password1'])
            ->assertSessionHas('error', fn ($m) => str_starts_with($m, 'Too many sign-in attempts'));
        $this->assertFalse(session()->has('active_role'));
    }

    #[Test]
    public function spraying_one_password_over_many_usernames_is_stopped(): void
    {
        $this->account('sprayed.user', 'password1');

        for ($i = 0; $i < 30; $i++) {
            $this->post('/login', ['email' => "user{$i}", 'password' => 'password1']);
        }

        $this->post('/login', ['email' => 'sprayed.user', 'password' => 'password1'])
            ->assertSessionHas('error', fn ($m) => str_starts_with($m, 'Too many sign-in attempts'));
    }

    #[Test]
    public function a_correct_sign_in_clears_earlier_mistakes(): void
    {
        $this->account('typo.user', 'password1');

        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', ['email' => 'typo.user', 'password' => 'typo1234']);
        }
        $this->post('/login', ['email' => 'typo.user', 'password' => 'password1'])
            ->assertRedirect(route('dashboard.school-nurse'));
    }

    #[Test]
    public function sql_typed_into_the_sign_in_form_is_only_text(): void
    {
        $this->account('real.user', 'password1');

        foreach (["' OR '1'='1", "real.user'--", "' OR 1=1 --", "real.user' /*"] as $payload) {
            $this->post('/login', ['email' => $payload, 'password' => "' OR '1'='1"]);
            $this->assertFalse(session()->has('active_role'), "{$payload} signed in.");
        }
    }

    #[Test]
    public function the_admin_sign_in_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin-login', ['username' => 'systemadmin', 'password' => "guess{$i}"]);
        }

        $this->post('/admin-login', ['username' => 'systemadmin', 'password' => 'admin123'])
            ->assertSessionHas('error', fn ($m) => str_starts_with($m, 'Too many sign-in attempts'));
        $this->assertNotSame('system_admin', session('active_role'));
    }

    #[Test]
    public function the_public_default_admin_password_opens_nothing_in_production(): void
    {
        config(['app.system_admin.password' => 'admin123']);
        $this->app->detectEnvironment(fn () => 'production');
        // Outside `testing` the CSRF check runs again; it is not what is under
        // test here, so it is set aside to let the password itself be judged.
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->post('/admin-login', ['username' => 'systemadmin', 'password' => 'admin123'])
            ->assertSessionHas('error', 'Invalid System Admin credentials.');

        $this->assertNotSame('system_admin', session('active_role'));

        // A password the deploy actually set still works in production.
        config(['app.system_admin.password' => 'a-real-secret-9']);
        $this->post('/admin-login', ['username' => 'systemadmin', 'password' => 'a-real-secret-9'])
            ->assertRedirect(route('dashboard.system-admin'));
    }

    #[Test]
    public function a_forged_cross_site_form_is_refused_in_production(): void
    {
        $this->account('real.user', 'password1');
        $this->app->detectEnvironment(fn () => 'production');

        // A signed-in nurse's browser, made to post by another site: no token.
        $response = $this->withSession(['active_role' => 'school_nurse', 'active_institution_id' => $this->school->id, 'active_username' => 'real.user'])
            ->post('/dashboard/settings/password', ['password' => 'hijacked1', 'password_confirmation' => 'hijacked1']);

        $this->assertTrue(
            $response->getStatusCode() === 419 || str_contains((string) $response->headers->get('Location'), '/login'),
            'A post with no CSRF token was not refused ('.$response->getStatusCode().').'
        );

        $hash = (string) DB::table('accounts')->where('username', 'real.user')->value('password_hash');
        $this->assertTrue(Hash::check('password1', $hash), 'The forged post changed the password.');
    }

    #[Test]
    public function an_account_the_admin_creates_has_a_password(): void
    {
        $admin = ['active_role' => 'system_admin', 'active_name' => 'System Admin', 'active_username' => 'systemadmin'];

        $this->withSession($admin)->post('/dashboard/system-admin/accounts', [
            'name' => 'New Nurse', 'username' => 'new.nurse', 'role' => 'school_nurse',
            'institution_id' => $this->school->id,
        ])->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('accounts', ['username' => 'new.nurse']);

        $this->withSession($admin)->post('/dashboard/system-admin/accounts', [
            'name' => 'New Nurse', 'username' => 'new.nurse', 'password' => 'firstpass1',
            'role' => 'school_nurse', 'institution_id' => $this->school->id,
        ])->assertSessionHasNoErrors();

        $hash = (string) DB::table('accounts')->where('username', 'new.nurse')->value('password_hash');
        $this->assertTrue(Hash::check('firstpass1', $hash));
    }

    #[Test]
    public function no_other_role_can_create_an_account(): void
    {
        foreach (['school_nurse', 'clinic_staff', 'clinic_teacher', 'class_adviser', 'school_head', 'feeding_coor', 'nutricor'] as $role) {
            $this->withSession(['active_role' => $role, 'active_institution_id' => $this->school->id, 'active_username' => 'x'])
                ->post('/dashboard/system-admin/accounts', [
                    'name' => 'Planted', 'username' => "planted.{$role}", 'password' => 'planted123',
                    'role' => 'school_nurse', 'institution_id' => $this->school->id,
                ]);

            $this->assertDatabaseMissing('accounts', ['username' => "planted.{$role}"]);
        }
    }

    // ── Reaching the application without signing in ────────────────────────

    #[Test]
    public function no_page_opens_without_signing_in(): void
    {
        $open = [];

        foreach ($this->routes(['GET']) as $uri) {
            $response = $this->get('/'.ltrim($this->fill($uri), '/'));
            $status = $response->getStatusCode();
            $location = (string) $response->headers->get('Location');

            if ($status < 300 || ($status < 400 && ! str_contains($location, '/login'))) {
                $open[] = "{$uri} ({$status})";
            }
        }

        $this->assertSame([], $open, 'Opened without a sign-in: '.implode(', ', $open));
    }

    #[Test]
    public function no_write_is_accepted_without_signing_in(): void
    {
        $accepted = [];

        foreach ($this->routes(['POST', 'PUT', 'PATCH', 'DELETE']) as $method => $uris) {
            foreach ($uris as $uri) {
                $response = $this->call($method, '/'.ltrim($this->fill($uri), '/'), ['name' => 'x', 'lrn' => '123456789012']);
                $status = $response->getStatusCode();
                $location = (string) $response->headers->get('Location');

                $refused = in_array($status, [401, 403, 404, 405, 419], true)
                    || ($status >= 300 && $status < 400 && str_contains($location, '/login'));

                if (! $refused) {
                    $accepted[] = "{$method} {$uri} ({$status})";
                }
            }
        }

        $this->assertSame([], $accepted, 'Accepted without a sign-in: '.implode(', ', $accepted));
    }

    #[Test]
    public function a_school_account_with_no_school_reads_nothing_anywhere(): void
    {
        $noSchool = ['active_role' => 'school_nurse', 'active_username' => 'x', 'active_institution_id' => null];

        foreach (['/api/student-health-history?lrn=1', '/api/student-consent-status?lrn=1', '/api/student-conditions?lrn=1', '/api/conditions'] as $url) {
            $response = $this->withSession($noSchool)->getJson($url);
            $response->assertRedirect(route('login'));
            $this->assertFalse(session()->has('active_role'), "{$url} kept a school account with no school signed in.");
        }
    }

    // ── Browser protections ─────────────────────────────────────────────────

    #[Test]
    public function every_response_carries_the_browser_protections(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $csp = (string) $response->headers->get('Content-Security-Policy');
        foreach (["base-uri 'self'", "object-src 'none'", "frame-ancestors 'self'", "form-action 'self'"] as $directive) {
            $this->assertStringContainsString($directive, $csp);
        }
    }

    #[Test]
    public function the_health_records_panels_escape_what_the_server_sends(): void
    {
        // The consent, assessment, history and conditions panels build markup
        // from free text a parent or an adviser typed. Each reply is escaped as
        // it arrives (escDeep), never interpolated raw.
        $source = file_get_contents(resource_path('views/dashboard/student-health-records.blade.php'));

        $this->assertSame(4, substr_count($source, 'escDeep(await resp.json()') + substr_count($source, 'escDeep((await resp.json())'));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Every application route's URI for the given methods, public ones left
     * out. GET returns a flat list; any other set returns [method => uris].
     */
    private function routes(array $methods): array
    {
        $found = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (in_array($uri, self::PUBLIC_URIS, true) || str_starts_with($uri, '_') || str_starts_with($uri, 'storage/')) {
                continue;
            }
            if (! str_starts_with((string) $route->getActionName(), 'App\\') && $route->getActionName() !== 'Closure') {
                continue;
            }

            foreach (array_intersect($methods, $route->methods()) as $method) {
                $found[$method][] = $uri;
            }
        }

        return $methods === ['GET'] ? array_values(array_unique($found['GET'] ?? [])) : $found;
    }

    /** A route URI with every {parameter} filled in. */
    private function fill(string $uri): string
    {
        return preg_replace('/\{[^}]+\}/', '1', $uri);
    }
}
