<?php

namespace Tests\Feature;

use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function roleDashboards(): array
    {
        return [
            'System Admin' => ['system_admin', '/dashboard/system-admin'],
            'School Head' => ['school_head', '/dashboard/school-head'],
            'Feeding Coordinator' => ['feeding_coor', '/dashboard/feedingcor-dashboard'],
            'School Nurse' => ['school_nurse', '/dashboard/school-nurse'],
            'Clinic Staff' => ['clinic_staff', '/dashboard/clinic-staff'],
            'Clinic Teacher' => ['clinic_teacher', '/dashboard/clinic-teacher'],
            'Class Adviser' => ['class_adviser', '/dashboard/class-adviser'],
            'Nutritional Coordinator' => ['nutricor', '/dashboard/nutricor-dashboard'],
        ];
    }

    /**
     * A tab is a normal link. The old shared transition hid every destination
     * then intercepted the click to assign window.location.href manually,
     * which made every role look as though it was reloading a tab.
     */
    #[DataProvider('roleDashboards')]
    public function test_role_dashboards_do_not_ship_the_sidebar_page_loader(string $role, string $uri): void
    {
        $school = Institution::create(['name' => 'Navigation Test School', 'status' => 'active']);

        $session = [
            'active_role' => $role,
            'active_name' => 'Navigation Test User',
            'active_username' => 'navigation.test',
            'active_school_name' => $school->name,
            'active_institution_id' => $school->id,
        ];

        if ($role === 'class_adviser') {
            $session['assigned_grade_level'] = 'Grade 10';
            $session['assigned_section'] = 'Dalton';
        }

        $html = $this->withSession($session)->get($uri)->assertOk()->getContent();

        $this->assertStringNotContainsString('page-ready', $html, "{$role} still hides the destination while a tab loads.");
        $this->assertStringNotContainsString('page-exit', $html, "{$role} still fades the current tab before navigation.");
        $this->assertStringNotContainsString('window.location.href = href', $html, "{$role} still forces sidebar navigation through JavaScript.");
    }

    public function test_shared_sidebar_assets_do_not_hide_or_reassign_tab_navigation(): void
    {
        foreach ([
            'resources/css/role-sidebar.css',
            'resources/css/nurse-sidebar.css',
            'resources/views/partials/role-page-transition.blade.php',
            'resources/views/partials/nurse-page-transition.blade.php',
            'resources/views/feedingcor-dashboard/feed-dashboard.blade.php',
            'resources/views/feedingcor-dashboard/feed-program.blade.php',
        ] as $path) {
            $source = file_get_contents(base_path($path));

            $this->assertNotFalse($source, "Could not read {$path}.");
            $this->assertStringNotContainsString('window.location.href = href', $source, "{$path} reassigns sidebar links.");
            $this->assertStringNotContainsString("main.classList.add('page-ready')", $source, "{$path} still hides the incoming page.");
            $this->assertStringNotContainsString("main.classList.add('page-exit')", $source, "{$path} still hides the outgoing page.");
        }
    }
}
