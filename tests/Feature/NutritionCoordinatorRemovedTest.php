<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Support\AccountSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Nutrition Coordinator (`nutricor`) was removed from SIGLA on 2026-11-22.
 * Nothing offers the role, nothing creates it, its pages are gone, and an
 * account or session still holding it signs in to nothing.
 */
class NutritionCoordinatorRemovedTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = Institution::create(['name' => Institution::REGISTRATION_SCHOOL, 'status' => 'active']);
    }

    #[Test]
    public function the_role_and_its_pages_are_gone(): void
    {
        $this->assertNotContains('nutricor', AccountSettings::SCHOOL_ROLES);
        $this->assertArrayNotHasKey('nutricor', AccountSettings::ROLE_LABELS);

        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString('nutricor', $route->uri(), 'A Nutrition Coordinator page is still routed.');
        }

        $this->assertFalse(Route::has('dashboard.nutricor-dashboard'));
        $this->assertDirectoryDoesNotExist(resource_path('views/nutricor'));
        $this->assertFileDoesNotExist(app_path('Http/Controllers/NutritionCoordinatorController.php'));
        $this->assertFileDoesNotExist(app_path('Http/Controllers/NutricorController.php'));
    }

    #[Test]
    public function nobody_can_request_the_role(): void
    {
        $this->get('/account-request')
            ->assertOk()
            ->assertDontSee('value="nutricor"', false)
            ->assertDontSee('Nutritional Coordinator')
            ->assertDontSee('Nutrition Coordinator');

        $this->post('/account-request', [
            'name' => 'Lorna Dizon',
            'username' => 'lorna.dizon',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
            'role' => 'nutricor',
            'institution_id' => $this->school->id,
            'terms' => '1',
        ])->assertSessionHasErrors('role');

        $this->assertSame(0, DB::table('account_requests')->count());
    }

    #[Test]
    public function no_admin_can_create_or_approve_the_role(): void
    {
        $admin = ['active_role' => 'system_admin', 'active_name' => 'Admin', 'active_username' => 'admin'];

        $this->withSession($admin)->post('/dashboard/system-admin/accounts', [
            'name' => 'Lorna Dizon',
            'username' => 'lorna.dizon',
            'password' => 'Secret1234!',
            'role' => 'nutricor',
            'institution_id' => $this->school->id,
        ])->assertSessionHasErrors('role');

        // A request filed before the role was removed is never turned into an account.
        DB::table('account_requests')->insert([
            'id' => 'req-nutricor-1',
            'name' => 'Lorna Dizon',
            'username' => 'lorna.dizon',
            'password_hash' => Hash::make('secret1234'),
            'role' => 'nutricor',
            'institution_id' => $this->school->id,
            'school_name' => $this->school->name,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->flushSession();
        $this->withSession($admin)
            ->post('/dashboard/system-admin/requests/req-nutricor-1/approve')
            ->assertSessionHas('error', 'That role is no longer part of SIGLA.');

        $this->assertSame(0, DB::table('accounts')->where('role', 'nutricor')->count());
        $this->assertSame('pending', DB::table('account_requests')->where('id', 'req-nutricor-1')->value('status'));
    }

    #[Test]
    public function an_account_left_holding_the_role_cannot_sign_in(): void
    {
        DB::table('accounts')->insert([
            'name' => 'Nutrition Coordinator',
            'username' => 'nutri1',
            'password_hash' => Hash::make('secret1234'),
            'role' => 'nutricor',
            'institution_id' => $this->school->id,
            'school_name' => $this->school->name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post('/login', ['email' => 'nutri1', 'password' => 'secret1234'])
            ->assertRedirect()
            ->assertSessionHas('error', 'This account\'s role is no longer part of SIGLA.');

        $this->assertNull(session('active_role'));
    }

    #[Test]
    public function a_session_still_holding_the_role_is_signed_out_everywhere(): void
    {
        foreach (['/dashboard/school-nurse', '/dashboard/settings', '/dashboard/feedingcor-dashboard', '/health-records/students/900000000101/incidents'] as $uri) {
            $this->withSession([
                'active_role' => 'nutricor',
                'active_name' => 'Nutrition Coordinator',
                'active_username' => 'nutri1',
                'active_institution_id' => $this->school->id,
                'active_school_name' => $this->school->name,
            ])->get($uri)->assertRedirect(route('login'));

            $this->assertNull(session('active_role'), $uri);
            $this->flushSession();
        }
    }
}
