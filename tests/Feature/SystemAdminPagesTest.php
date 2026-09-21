<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Support\FeedingAtRiskRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The System Admin's three screens render on the LUSOG design system.
 *
 * The Control Center, the Audit Trail and the admin sign-in were the last
 * pages on a private green ramp with their own sidebar. They now inline
 * lusog-theme.css, the shared role rail and one page sheet, so a change to
 * the theme reaches this role like every other. These tests render each
 * page over real rows — accounts, pending and processed requests, a school
 * on the app default and one with its own policy, audit entries with and
 * without a payload — so a Blade error in any branch fails the build
 * rather than the admin's next visit.
 */
class SystemAdminPagesTest extends TestCase
{
    use RefreshDatabase;

    private function adminSession(): array
    {
        return ['active_role' => 'system_admin', 'active_name' => 'Test Admin', 'active_username' => 'systemadmin'];
    }

    private function seedAccountsAndRequests(Institution $school): void
    {
        DB::table('accounts')->insert([
            [
                'name' => 'Ana Cruz', 'username' => 'acruz', 'password_hash' => null, 'role' => 'class_adviser',
                'institution_id' => $school->id, 'school_name' => $school->name,
                'assigned_grade_level' => 'Grade 7', 'assigned_section' => 'Rizal',
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'name' => 'Ben Reyes', 'username' => 'breyes', 'password_hash' => null, 'role' => 'school_nurse',
                'institution_id' => $school->id, 'school_name' => $school->name,
                'assigned_grade_level' => null, 'assigned_section' => null,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        DB::table('account_requests')->insert([
            [
                'id' => (string) Str::uuid(), 'name' => 'Carla Dizon', 'username' => 'cdizon', 'password_hash' => 'x',
                'role' => 'class_adviser', 'institution_id' => $school->id, 'school_name' => $school->name,
                'assigned_grade_level' => 'Grade 8', 'assigned_section' => 'Bonifacio',
                'status' => 'pending', 'decided_at' => null, 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(), 'name' => 'Dan Evangelista', 'username' => 'devangelista', 'password_hash' => 'x',
                'role' => 'feeding_coor', 'institution_id' => $school->id, 'school_name' => $school->name,
                'assigned_grade_level' => null, 'assigned_section' => null,
                'status' => 'accepted', 'decided_at' => now(), 'created_at' => now()->subDay(), 'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(), 'name' => 'Eva Flores', 'username' => 'eflores', 'password_hash' => 'x',
                'role' => 'school_head', 'institution_id' => $school->id, 'school_name' => $school->name,
                'assigned_grade_level' => null, 'assigned_section' => null,
                'status' => 'declined', 'decided_at' => now(), 'created_at' => now()->subDay(), 'updated_at' => now(),
            ],
        ]);
    }

    #[Test]
    public function the_control_center_renders_every_section_on_the_theme(): void
    {
        $default = Institution::create(['name' => 'Default School', 'status' => 'active']);
        $custom = Institution::create([
            'name' => 'Custom School',
            'status' => 'active',
            'feeding_at_risk_threshold' => 85,
            'feeding_at_risk_mode' => FeedingAtRiskRule::MODE_UNEXCUSED_ABSENCE_DAYS,
        ]);
        $this->seedAccountsAndRequests($default);

        $response = $this->withSession($this->adminSession())->get('/dashboard/system-admin')->assertOk();

        $html = $response->getContent();

        // On the theme and the shared rail, not the retired private sheet.
        $this->assertStringContainsString('--lg-emerald-deep', $html);
        $this->assertStringContainsString('asb-sidebar', $html);
        $this->assertStringNotContainsString('lusog-palette.css', $html);
        $this->assertStringNotContainsString('sb-pin', $html);

        // The rail names every section and the audit trail.
        $response->assertSee('Control Center')
            ->assertSee('href="#requests"', false)
            ->assertSee('href="#accounts"', false)
            ->assertSee('href="#feeding-policy"', false)
            ->assertSee(route('dashboard.system-admin.audit-logs'));

        // Every section, with its rows.
        $response->assertSee('Incoming Account Requests')
            ->assertSee('Carla Dizon')
            ->assertSee('Grade 8 / Bonifacio')
            ->assertSee(route('dashboard.system-admin.requests.approve', DB::table('account_requests')->where('username', 'cdizon')->value('id')))
            ->assertSee('User and Role Management')
            ->assertSee('Ana Cruz')
            ->assertSee('Grade 7 / Rizal')
            ->assertSee('Ben Reyes')
            ->assertSee('Feeding Program Policy')
            ->assertSee('Default School')
            ->assertSee('Custom School')
            ->assertSee('App default')
            ->assertSee('School-set')
            ->assertSee('Account Request History')
            ->assertSee('Dan Evangelista')
            ->assertSee('Accepted')
            ->assertSee('Eva Flores')
            ->assertSee('Declined');

        // The policy form still posts every setting under its original name.
        $response->assertSee(route('dashboard.system-admin.institutions.at-risk-threshold', $custom->id))
            ->assertSee('name="at_risk_mode"', false)
            ->assertSee('name="threshold"', false)
            ->assertSee('name="minimum_observation_days"', false)
            ->assertSee('name="absence_flag_days"', false)
            ->assertSee('name="absence_removal_days"', false)
            ->assertSee('name="cycle_days"', false)
            ->assertSee('value="85"', false);

        // The board can never show the unscoped admin anything, so it is gone.
        $this->assertStringNotContainsString('ann-board', $html);
    }

    #[Test]
    public function the_control_center_renders_its_empty_states(): void
    {
        $this->withSession($this->adminSession())
            ->get('/dashboard/system-admin')
            ->assertOk()
            ->assertSee('No pending account requests.')
            ->assertSee('No created accounts yet.')
            ->assertSee('No schools on file.')
            ->assertSee('No processed account requests yet.');
    }

    #[Test]
    public function the_audit_trail_renders_on_the_theme_with_its_filters(): void
    {
        AuditLog::create([
            'actor_name' => 'Nurse One', 'actor_username' => 'nurse1', 'actor_role' => 'school_nurse',
            'action' => 'updated', 'subject_type' => 'StudentHealthRecord', 'subject_id' => 7,
            'description' => 'Updated a record', 'details' => ['changed' => ['weight' => ['old' => 30, 'new' => 31]]],
            'http_method' => 'POST', 'url' => 'http://localhost/adviser/store', 'route_name' => 'adviser.store',
            'ip_address' => '127.0.0.1', 'created_at' => now(),
        ]);
        AuditLog::create([
            'actor_name' => null, 'actor_username' => 'ghost', 'actor_role' => 'guest',
            'action' => 'login_failed', 'description' => 'Failed sign-in',
            'http_method' => 'POST', 'url' => 'http://localhost/login', 'route_name' => null,
            'ip_address' => '127.0.0.1', 'created_at' => now(),
        ]);

        $response = $this->withSession($this->adminSession())
            ->get(route('dashboard.system-admin.audit-logs'))
            ->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('--lg-emerald-deep', $html);
        $this->assertStringContainsString('asb-sidebar', $html);
        $this->assertStringNotContainsString('lusog-palette.css', $html);

        $response->assertSee('Audit')
            ->assertSee('Nurse One')
            ->assertSee('updated')
            ->assertSee('badge-critical', false)
            ->assertSee('login_failed')
            ->assertSee('StudentHealthRecord #7')
            ->assertSee('adviser.store')
            ->assertSee('&quot;weight&quot;', false)
            ->assertSee('2 entries shown');

        // Filtering narrows the list and offers the way back.
        $this->withSession($this->adminSession())
            ->get(route('dashboard.system-admin.audit-logs', ['action' => 'login_failed']))
            ->assertOk()
            ->assertSee('1 entry shown')
            ->assertDontSee('Nurse One')
            ->assertSee('Clear');

        $this->withSession($this->adminSession())
            ->get(route('dashboard.system-admin.audit-logs', ['username' => 'nobody']))
            ->assertOk()
            ->assertSee('No audit entries match the current filter.');
    }

    #[Test]
    public function the_admin_sign_in_renders_and_still_signs_in(): void
    {
        $page = $this->get('/admin-login')->assertOk();

        $html = $page->getContent();
        $this->assertStringContainsString('--lg-emerald-deep', $html);
        $this->assertSame(
            substr_count($html, '<style'),
            substr_count($html, '</'.'style>'),
            'The admin sign-in has unbalanced style elements.'
        );

        $page->assertSee(route('admin.login.submit'))
            ->assertSee('name="username"', false)
            ->assertSee('name="password"', false)
            ->assertSee(route('login'));

        $this->post('/admin-login', ['username' => 'wrong', 'password' => 'wrong'])
            ->assertRedirect();

        $this->get('/admin-login')->assertOk()->assertSee('Invalid System Admin credentials.');

        $this->post('/admin-login', ['username' => 'systemadmin', 'password' => 'admin123'])
            ->assertRedirect(route('dashboard.system-admin'));
    }
}
