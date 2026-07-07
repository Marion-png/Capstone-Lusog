<?php

namespace Tests\Feature;

use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PrototypeSessionTest extends TestCase
{
    use RefreshDatabase;

    private function prototypeSession(string $role): array
    {
        $institution = Institution::firstOrCreate(['name' => 'Proto School'], ['status' => 'active']);

        return [
            'active_role' => $role,
            'active_name' => 'Prototype User',
            'active_username' => 'prototype',
            'active_school_name' => $institution->name,
            'active_institution_id' => $institution->id,
        ];
    }

    #[Test]
    public function visiting_a_protected_page_without_a_session_seeds_a_matching_role(): void
    {
        $response = $this->get('/dashboard/student-health-records');

        $response->assertStatus(200);
        $response->assertSessionHas('active_role', 'school_nurse');
    }

    #[Test]
    public function refreshing_a_shared_nurse_page_with_an_adviser_prototype_session_switches_to_nurse(): void
    {
        // Regression: this used to keep class_adviser and the page's role
        // check bounced the user back to the adviser dashboard.
        $response = $this->withSession($this->prototypeSession('class_adviser'))
            ->get('/dashboard/student-health-records');

        $response->assertStatus(200);
        $response->assertSessionHas('active_role', 'school_nurse');
    }

    #[Test]
    public function a_nurse_prototype_session_keeps_its_role_on_shared_pages(): void
    {
        $response = $this->withSession($this->prototypeSession('school_nurse'))
            ->get('/dashboard/consultation-log');

        $response->assertStatus(200);
        $response->assertSessionHas('active_role', 'school_nurse');
    }

    #[Test]
    public function visiting_another_roles_dashboard_switches_the_prototype_session(): void
    {
        $response = $this->withSession($this->prototypeSession('school_nurse'))
            ->get('/dashboard/class-adviser');

        $response->assertStatus(200);
        $response->assertSessionHas('active_role', 'class_adviser');
    }

    #[Test]
    public function a_real_account_session_is_never_switched(): void
    {
        $institution = Institution::firstOrCreate(['name' => 'Real School'], ['status' => 'active']);

        $response = $this->withSession([
            'active_role' => 'class_adviser',
            'active_name' => 'Maria Santos',
            'active_username' => 'maria.santos',
            'active_institution_id' => $institution->id,
        ])->get('/dashboard/student-health-records');

        // Real advisers are still redirected by the page's own role check.
        $response->assertRedirect(route('dashboard.class-adviser'));
    }
}
