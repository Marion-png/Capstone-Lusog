<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\SchoolCalendarDay;
use App\Support\AccountName;
use App\Support\SchoolCalendar;
use App\Support\SchoolClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A School Admin (AdminScope) administers one school from the Control Center:
 * its accounts, account requests, feeding policy, calendar entries and audit
 * trail, and nothing of any other school's. Every name below is invented.
 */
class SchoolAdminTest extends TestCase
{
    use RefreshDatabase;

    private const DAVAO_CITY = 'Davao City National High School';

    private Institution $staAna;

    private Institution $davaoCity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staAna = Institution::create(['name' => Institution::REGISTRATION_SCHOOL, 'status' => 'active']);
        $this->davaoCity = Institution::create(['name' => self::DAVAO_CITY, 'status' => 'active']);

        DB::table('accounts')->insert([
            $this->account('dcnhs.admin', 'school_admin', $this->davaoCity, 'Dalisay Admin', 'adminpass1'),
            $this->account('nurse.dalisay', 'school_nurse', $this->davaoCity, 'Marites Dalisay'),
            $this->account('nurse.santos', 'school_nurse', $this->staAna, 'Lualhati Santos'),
        ]);
    }

    private function account(string $username, string $role, Institution $school, string $name, string $password = 'password1'): array
    {
        return [
            'name' => AccountName::seal($name), 'username' => $username, 'password_hash' => Hash::make($password),
            'role' => $role, 'institution_id' => $school->id, 'school_name' => $school->name,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function requestFor(Institution $school, string $username, string $name): string
    {
        $id = (string) Str::uuid();

        DB::table('account_requests')->insert([
            'id' => $id, 'name' => AccountName::seal($name), 'username' => $username, 'password_hash' => Hash::make('password1'),
            'role' => 'school_nurse', 'institution_id' => $school->id, 'school_name' => $school->name,
            'status' => 'pending', 'decided_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function asSchoolAdmin(?int $institutionId = null): array
    {
        return [
            'active_role' => 'school_admin',
            'active_name' => 'Dalisay Admin',
            'active_username' => 'dcnhs.admin',
            'active_school_name' => self::DAVAO_CITY,
            'active_institution_id' => $institutionId ?? $this->davaoCity->id,
        ];
    }

    #[Test]
    public function it_signs_in_at_the_admin_sign_in_as_its_own_school(): void
    {
        $this->post('/admin-login', ['username' => 'dcnhs.admin', 'password' => 'adminpass1'])
            ->assertRedirect(route('dashboard.system-admin'))
            ->assertSessionHas('active_role', 'school_admin')
            ->assertSessionHas('active_institution_id', $this->davaoCity->id)
            ->assertSessionHas('active_name', 'Dalisay Admin');
    }

    #[Test]
    public function the_school_sign_in_takes_it_to_the_control_center_too(): void
    {
        $this->post('/login', ['email' => 'dcnhs.admin', 'password' => 'adminpass1'])
            ->assertRedirect(route('dashboard.system-admin'))
            ->assertSessionHas('active_role', 'school_admin');
    }

    #[Test]
    public function a_wrong_password_and_an_unknown_username_read_the_same(): void
    {
        $wrong = $this->post('/admin-login', ['username' => 'dcnhs.admin', 'password' => 'nottheone1']);
        $wrong->assertSessionHas('error', 'Invalid System Admin credentials.');
        $this->assertNull(session('active_role'));

        $this->flushSession();

        $this->post('/admin-login', ['username' => 'nobody.here', 'password' => 'nottheone1'])
            ->assertSessionHas('error', 'Invalid System Admin credentials.');
        $this->assertNull(session('active_role'));
    }

    #[Test]
    public function another_roles_account_does_not_open_the_admin_sign_in(): void
    {
        $this->post('/admin-login', ['username' => 'nurse.dalisay', 'password' => 'password1'])
            ->assertSessionHas('error', 'Invalid System Admin credentials.');
        $this->assertNull(session('active_role'));
    }

    #[Test]
    public function the_control_center_shows_only_its_own_school(): void
    {
        $this->requestFor($this->davaoCity, 'adviser.bituin', 'Bituin Malaya');
        $this->requestFor($this->staAna, 'adviser.ligaya', 'Ligaya Tamayo');

        $this->withSession($this->asSchoolAdmin())
            ->get('/dashboard/system-admin')
            ->assertOk()
            ->assertSee('School Administrator')
            ->assertSee('Marites Dalisay')
            ->assertSee('Bituin Malaya')
            ->assertSee(self::DAVAO_CITY)
            ->assertDontSee('Lualhati Santos')
            ->assertDontSee('Ligaya Tamayo')
            ->assertDontSee(Institution::REGISTRATION_SCHOOL)
            ->assertDontSee('Sync Holidays');
    }

    #[Test]
    public function it_decides_its_own_schools_requests_and_no_others(): void
    {
        $own = $this->requestFor($this->davaoCity, 'adviser.bituin', 'Bituin Malaya');
        $other = $this->requestFor($this->staAna, 'adviser.ligaya', 'Ligaya Tamayo');

        $this->withSession($this->asSchoolAdmin())->post(route('dashboard.system-admin.requests.approve', $other));
        $this->withSession($this->asSchoolAdmin())->post(route('dashboard.system-admin.requests.decline', $other));
        $this->assertSame('pending', DB::table('account_requests')->where('id', $other)->value('status'));
        $this->assertFalse(DB::table('accounts')->where('username', 'adviser.ligaya')->exists());

        $this->withSession($this->asSchoolAdmin())->post(route('dashboard.system-admin.requests.approve', $own));
        $this->assertSame('accepted', DB::table('account_requests')->where('id', $own)->value('status'));
        $this->assertDatabaseHas('accounts', ['username' => 'adviser.bituin', 'institution_id' => $this->davaoCity->id]);
    }

    #[Test]
    public function an_account_it_creates_belongs_to_its_school_whatever_is_posted(): void
    {
        $this->withSession($this->asSchoolAdmin())->post(route('dashboard.system-admin.accounts.store'), [
            'name' => 'Amihan Reyes', 'username' => 'head.reyes', 'password' => 'password1',
            'role' => 'school_head', 'institution_id' => $this->staAna->id,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('accounts', ['username' => 'head.reyes', 'institution_id' => $this->davaoCity->id, 'school_name' => self::DAVAO_CITY]);
        $this->assertFalse(DB::table('accounts')->where('username', 'head.reyes')->where('institution_id', $this->staAna->id)->exists());
    }

    #[Test]
    public function only_the_system_admin_appoints_a_school_admin(): void
    {
        $this->withSession($this->asSchoolAdmin())->post(route('dashboard.system-admin.accounts.store'), [
            'name' => 'Second Admin', 'username' => 'admin.two', 'password' => 'password1', 'role' => 'school_admin',
        ])->assertSessionHasErrors('role');
        $this->assertFalse(DB::table('accounts')->where('username', 'admin.two')->exists());

        $this->flushSession();

        $this->withSession(['active_role' => 'system_admin', 'active_name' => 'System Admin', 'active_username' => 'systemadmin'])
            ->post(route('dashboard.system-admin.accounts.store'), [
                'name' => 'Sta. Ana Admin', 'username' => 'staana.admin', 'password' => 'password1',
                'role' => 'school_admin', 'institution_id' => $this->staAna->id,
            ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('accounts', ['username' => 'staana.admin', 'role' => 'school_admin', 'institution_id' => $this->staAna->id]);
    }

    #[Test]
    public function it_sets_its_own_schools_feeding_policy_and_no_others(): void
    {
        $this->withSession($this->asSchoolAdmin())
            ->post(route('dashboard.system-admin.institutions.at-risk-threshold', $this->staAna->id), ['threshold' => 50])
            ->assertForbidden();
        $this->assertNull($this->staAna->fresh()->feeding_at_risk_threshold);

        $this->withSession($this->asSchoolAdmin())
            ->post(route('dashboard.system-admin.institutions.at-risk-threshold', $this->davaoCity->id), ['threshold' => 75]);
        $this->assertSame(75, (int) $this->davaoCity->fresh()->feeding_at_risk_threshold);
    }

    #[Test]
    public function its_audit_trail_holds_only_its_own_schools_entries(): void
    {
        $baseline = (int) AuditLog::max('id');

        AuditLog::create(['action' => 'viewed', 'actor_username' => 'nurse.dalisay', 'institution_id' => $this->davaoCity->id, 'description' => 'Davao City entry', 'created_at' => now()]);
        AuditLog::create(['action' => 'viewed', 'actor_username' => 'nurse.santos', 'institution_id' => $this->staAna->id, 'description' => 'Sta Ana entry', 'created_at' => now()]);

        $this->assertGreaterThan($baseline, (int) AuditLog::max('id'));

        $this->withSession($this->asSchoolAdmin())
            ->get(route('dashboard.system-admin.audit-logs'))
            ->assertOk()
            ->assertSee('nurse.dalisay')
            ->assertDontSee('nurse.santos');
    }

    #[Test]
    public function it_keeps_its_own_schools_calendar_and_cannot_sync_holidays(): void
    {
        $day = (string) SchoolClock::today();

        $this->withSession($this->asSchoolAdmin())->post(route('dashboard.system-admin.calendar.store'), [
            'institution_id' => $this->staAna->id,
            'kind' => SchoolCalendar::KIND_SUSPENSION,
            'starts_on' => $day,
            'name' => 'Typhoon Signal No. 2',
        ]);
        $this->assertDatabaseHas('school_calendar_days', ['institution_id' => $this->davaoCity->id, 'name' => 'Typhoon Signal No. 2']);
        $this->assertFalse(SchoolCalendarDay::query()->where('institution_id', $this->staAna->id)->exists());

        $staAnaBreak = SchoolCalendarDay::query()->create([
            'institution_id' => $this->staAna->id, 'starts_on' => $day, 'ends_on' => $day,
            'name' => 'Sta Ana Foundation Day', 'kind' => SchoolCalendar::KIND_BREAK,
            'source' => SchoolCalendar::SOURCE_MANUAL, 'created_by_name' => 'System Admin',
        ]);

        $this->withSession($this->asSchoolAdmin())->delete(route('dashboard.system-admin.calendar.destroy', $staAnaBreak->id));
        $this->assertNotNull($staAnaBreak->fresh());

        $this->withSession($this->asSchoolAdmin())->get('/dashboard/system-admin')->assertDontSee('Sta Ana Foundation Day');

        $this->withSession($this->asSchoolAdmin())->post(route('dashboard.system-admin.calendar.sync'))
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function without_a_school_it_is_signed_out(): void
    {
        $this->withSession(array_merge($this->asSchoolAdmin(), ['active_institution_id' => null]))
            ->get('/dashboard/system-admin')
            ->assertRedirect(route('login'));
        $this->assertNull(session('active_role'));
    }

    #[Test]
    public function it_changes_its_own_password_in_settings(): void
    {
        $this->withSession($this->asSchoolAdmin())
            ->get(route('settings'))
            ->assertOk()
            ->assertSee('School Admin');
    }

    #[Test]
    public function the_console_command_creates_one_without_overwriting(): void
    {
        $this->assertSame(0, Artisan::call('accounts:create-school-admin', [
            'school' => self::DAVAO_CITY, 'username' => 'dcnhs.second', '--name' => 'Second Admin',
        ]));

        $row = DB::table('accounts')->where('username', 'dcnhs.second')->first();
        $this->assertSame('school_admin', $row->role);
        $this->assertSame($this->davaoCity->id, (int) $row->institution_id);
        $this->assertSame('Second Admin', AccountName::read($row->name));
        $this->assertNotSame('', (string) $row->password_hash);

        $this->assertSame(1, Artisan::call('accounts:create-school-admin', [
            'school' => (string) $this->davaoCity->id, 'username' => 'dcnhs.admin',
        ]));
        $this->assertSame(1, DB::table('accounts')->where('username', 'dcnhs.admin')->count());
    }
}
