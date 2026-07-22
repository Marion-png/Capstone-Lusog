<?php

namespace Tests\Feature;

use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression coverage for: several nurse-side pages had no role check at
 * all, unlike /dashboard/student-health-records which did. A session whose
 * role wasn't actually school_nurse/clinic_staff could browse most of the
 * nurse section successfully and only get unexpectedly bounced to its own
 * dashboard on the one page that happened to check — e.g. a class_adviser
 * session could open the nurse dashboard fine, then get redirected away the
 * moment it clicked into Health Records. Every nurse-only page must now
 * consistently redirect a mismatched role immediately.
 */
class NurseRoleGuardTest extends TestCase
{
    use RefreshDatabase;

    private function adviserSession(): array
    {
        $institution = Institution::create(['name' => 'Test School', 'status' => 'active']);

        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Test Adviser',
            'active_username' => 'test.adviser',
            'active_institution_id' => $institution->id,
        ];
    }

    #[Test]
    public function a_class_adviser_session_is_redirected_away_from_every_nurse_only_page(): void
    {
        $session = $this->adviserSession();

        $nursePages = [
            'dashboard.school-nurse',
            'nurse.index',
            'dashboard.school-nurse.deworming',
            'dashboard.consultation-log',
            'consultations.create',
            'dashboard.medicine-inventory',
            'medicine-inventory.create',
            'dashboard.data-visualization',
            'dashboard.student-health-records',
        ];

        foreach ($nursePages as $routeName) {
            $response = $this->withSession($session)->get(route($routeName));

            $response->assertRedirect(route('dashboard.class-adviser'));
        }
    }

    #[Test]
    public function a_school_nurse_session_can_view_every_nurse_only_page(): void
    {
        $institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
        $session = [
            'active_role' => 'school_nurse',
            'active_name' => 'Test Nurse',
            'active_username' => 'test.nurse',
            'active_institution_id' => $institution->id,
        ];

        $nursePages = [
            'dashboard.school-nurse',
            'nurse.index',
            'dashboard.school-nurse.deworming',
            'dashboard.consultation-log',
            'consultations.create',
            'dashboard.medicine-inventory',
            'medicine-inventory.create',
            'dashboard.data-visualization',
            'dashboard.student-health-records',
        ];

        foreach ($nursePages as $routeName) {
            $this->withSession($session)->get(route($routeName))->assertStatus(200);
        }
    }

    #[Test]
    public function system_admin_can_still_view_every_nurse_only_page(): void
    {
        $institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
        $session = [
            'active_role' => 'system_admin',
            'active_name' => 'Test Admin',
            'active_username' => 'test.admin',
        ];

        $nursePages = [
            'dashboard.school-nurse',
            'nurse.index',
            'dashboard.school-nurse.deworming',
            'dashboard.consultation-log',
            'consultations.create',
            'dashboard.medicine-inventory',
            'medicine-inventory.create',
            'dashboard.data-visualization',
            'dashboard.student-health-records',
        ];

        foreach ($nursePages as $routeName) {
            $this->withSession($session)->get(route($routeName))->assertStatus(200);
        }
    }
}
