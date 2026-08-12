<?php

namespace Tests\Feature;

use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Clinic Staff keeps its own navigation on the shared clinic pages.
 *
 * Health Records, Consultation Log and Medicine Inventory are shared with
 * the School Nurse, and they used to include the nurse rail unconditionally.
 * A Clinic Staff session that clicked Consultation Log therefore landed on a
 * page wearing the nurse's navigation — Review Queue, health programmes,
 * Dispensing Log — none of which it may open. partials/clinic-rail now picks
 * the rail from the session role; these tests pin that.
 */
class ClinicStaffWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    /** Pages both roles share. */
    public static function sharedPages(): array
    {
        return [
            'health records' => ['/dashboard/student-health-records'],
            'consultation log' => ['/dashboard/consultation-log'],
            'medicine inventory' => ['/dashboard/medicine-inventory'],
        ];
    }

    private function sessionFor(string $role): array
    {
        $school = Institution::firstOrCreate(['name' => 'Sta. Ana NHS'], ['status' => 'active']);

        return [
            'active_role' => $role,
            'active_name' => $role === 'clinic_staff' ? 'Clinic Staff' : 'Nurse Cruz',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $school->id,
        ];
    }

    #[Test]
    public function the_clinic_staff_dashboard_renders_on_the_lusog_system(): void
    {
        $html = $this->withSession($this->sessionFor('clinic_staff'))
            ->get(route('dashboard.clinic-staff'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('--lg-emerald', $html, 'Clinic Staff must use the LUSOG theme.');
        $this->assertStringContainsString('sb-logo-full', $html, 'The rail must show the LUSOG logo lockup.');
        $this->assertStringContainsString('page-ready', $html, 'Without .page-ready the page renders blank.');
    }

    /**
     * The reported bug: opening a shared module threw Clinic Staff onto the
     * nurse's navigation.
     *
     * @dataProvider sharedPages
     */
    public function test_clinic_staff_keeps_its_own_rail_on_shared_pages(string $uri): void
    {
        $html = $this->withSession($this->sessionFor('clinic_staff'))->get($uri)->assertOk()->getContent();

        // Its own dashboard link is present…
        $this->assertStringContainsString(
            'href="'.route('dashboard.clinic-staff').'"',
            $html,
            "{$uri} must keep the Clinic Staff rail."
        );

        // …and none of the nurse-only destinations are.
        foreach (['nurse.index', 'dashboard.school-nurse.feeding-program', 'dashboard.dispensing-log'] as $nurseRoute) {
            $this->assertStringNotContainsString(
                'href="'.route($nurseRoute).'"',
                $html,
                "{$uri} shows Clinic Staff a nurse-only destination ({$nurseRoute})."
            );
        }
    }

    /**
     * The nurse is unaffected — the same pages still carry the nurse rail.
     *
     * @dataProvider sharedPages
     */
    public function test_the_nurse_still_gets_the_nurse_rail_on_the_same_pages(string $uri): void
    {
        $html = $this->withSession($this->sessionFor('school_nurse'))->get($uri)->assertOk()->getContent();

        $this->assertStringContainsString(
            'href="'.route('nurse.index').'"',
            $html,
            "{$uri} must still show the nurse their Review Queue."
        );
        $this->assertStringContainsString(
            'href="'.route('dashboard.dispensing-log').'"',
            $html,
            "{$uri} must still show the nurse the Dispensing Log."
        );
    }

    /**
     * The breadcrumb named the nurse regardless of who was signed in.
     *
     * @dataProvider sharedPages
     */
    public function test_the_breadcrumb_names_the_signed_in_role(string $uri): void
    {
        $clinic = $this->withSession($this->sessionFor('clinic_staff'))->get($uri)->assertOk()->getContent();
        $nurse = $this->withSession($this->sessionFor('school_nurse'))->get($uri)->assertOk()->getContent();

        $this->assertStringContainsString('<span>Clinic Staff</span>', $clinic);
        $this->assertStringContainsString('<span>School Nurse</span>', $nurse);
    }

    #[Test]
    public function the_clinic_rail_never_offers_the_dispensing_log(): void
    {
        $html = $this->withSession($this->sessionFor('clinic_staff'))
            ->get(route('dashboard.clinic-staff'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(route('dashboard.dispensing-log'), $html);
    }

    #[Test]
    public function roles_with_no_business_here_are_redirected_away(): void
    {
        $expected = [
            'class_adviser' => 'dashboard.class-adviser',
            'school_head' => 'dashboard.school-head',
            'feeding_coor' => 'dashboard.feedingcor-dashboard',
            'nutricor' => 'dashboard.nutricor-dashboard',
        ];

        foreach ($expected as $role => $route) {
            $this->withSession($this->sessionFor($role))
                ->get(route('dashboard.clinic-staff'))
                ->assertRedirect(route($route));
        }
    }

    /** The dashboard's figures come from the database, not from fixtures. */
    #[Test]
    public function the_headline_figures_are_no_longer_hardcoded(): void
    {
        $html = $this->withSession($this->sessionFor('clinic_staff'))
            ->get(route('dashboard.clinic-staff'))
            ->assertOk()
            ->getContent();

        // The old demo values. An empty school must read zero.
        foreach (['>31<', '>18<', '>24<', '>7<'] as $demoValue) {
            $this->assertStringNotContainsString($demoValue, $html, "The demo figure {$demoValue} is still hardcoded.");
        }

        $this->assertStringNotContainsString('Juan Dela Cruz', $html);
        $this->assertStringNotContainsString('Ana Gonzales', $html);
    }
}
