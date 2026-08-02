<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Institution;
use App\Models\Medicine;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The nurse's dashboard renders its headline metrics, consultations table and
 * summary panels from real records. "Top Consultation Cases" in particular
 * must tally decrypted values in PHP — `condition` is encrypted, so a SQL
 * GROUP BY would bucket ciphertext and show one row per consultation.
 */
class SchoolNurseDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function nurseSession(): array
    {
        return [
            'active_role' => 'school_nurse',
            'active_name' => 'Ana Reyes',
            'active_username' => 'ana.reyes',
            'active_institution_id' => $this->institution->id,
            'active_school_name' => 'Sta. Ana NHS',
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    private function consultation(array $attributes = []): Consultation
    {
        return Consultation::create(array_merge([
            'institution_id' => $this->institution->id,
            'consulted_at' => now(),
            'student_name' => 'Dela Cruz, Juan',
            'grade_section' => 'Grade 10 - Dalton',
            'condition' => 'Fever',
            'treatment_given' => 'Rest and fluids',
            'status' => 'treated',
        ], $attributes));
    }

    #[Test]
    public function top_consultation_cases_groups_decrypted_conditions_not_ciphertext(): void
    {
        $this->consultation(['condition' => 'Fever']);
        $this->consultation(['condition' => 'Fever', 'student_name' => 'Reyes, Maria']);
        $this->consultation(['condition' => 'fever', 'student_name' => 'Tan, Sofia']);
        $this->consultation(['condition' => 'Cough', 'student_name' => 'Gomez, Jose']);

        $response = $this->withSession($this->nurseSession())
            ->get('/dashboard/school-nurse')
            ->assertOk();

        $top = $response->viewData('topConditions');

        // Three "Fever" rows collapse into one bucket, case-insensitively.
        $this->assertSame(
            [['name' => 'fever', 'total' => 3], ['name' => 'cough', 'total' => 1]],
            $top->all()
        );

        $response->assertSee('Fever')->assertSee('Cough');
        // Ciphertext starts with the Laravel payload marker once base64-decoded;
        // the raw column value must never reach the page.
        $response->assertDontSee(Consultation::first()->getRawOriginal('condition'));
    }

    #[Test]
    public function the_headline_metrics_come_from_real_records(): void
    {
        StudentHealthRecord::create([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_id' => 'LRN001',
            'student_name' => 'Dela Cruz, Juan',
            'section' => 'Grade 10 / Dalton',
            'weight' => 40, 'bmi_value' => 17.7, 'nutritional_status' => 'Normal',
            'is_at_risk' => true,
        ]);

        $this->consultation();
        $this->consultation(['consulted_at' => now()->subMonths(2), 'student_name' => 'Old, Case']);

        Medicine::create([
            'institution_id' => $this->institution->id,
            'name' => 'Paracetamol',
            'unit' => 'tablets',
            'stock_quantity' => 3,
            'minimum_threshold' => 20,
        ]);

        $response = $this->withSession($this->nurseSession())
            ->get('/dashboard/school-nurse')
            ->assertOk();

        $this->assertSame(1, $response->viewData('totalRecords'));
        $this->assertSame(1, $response->viewData('consultationsToday'));
        $this->assertSame(1, $response->viewData('atRiskCount'));
        $this->assertSame(1, $response->viewData('lowStockCount'));

        $response->assertSee('Total Records')
            ->assertSee('At-Risk Learners')
            ->assertSee('Low Stock Medicines')
            ->assertSee('Paracetamol');
    }

    #[Test]
    public function the_dashboard_greets_the_nurse_and_shows_the_school_and_year(): void
    {
        $response = $this->withSession($this->nurseSession())
            ->get('/dashboard/school-nurse')
            ->assertOk();

        $response->assertSee('Ana Reyes')
            ->assertSee('School Nurse')
            ->assertSee('Sta. Ana NHS')
            ->assertSee(StudentHealthRecord::currentSchoolYear())
            ->assertSee('Clinic Open');
    }

    #[Test]
    public function the_consultations_table_carries_the_filter_hooks(): void
    {
        $this->consultation(['grade_section' => 'Grade 9 - Rizal']);
        $this->consultation(['grade_section' => 'Grade 12 - STEM A', 'student_name' => 'Tan, Sofia']);
        $this->consultation(['grade_section' => 'Faculty', 'student_name' => 'Santos, Mr.']);

        $html = $this->withSession($this->nurseSession())
            ->get('/dashboard/school-nurse')
            ->assertOk()
            ->getContent();

        // Grade buckets are derived from the decrypted label, in PHP.
        $this->assertStringContainsString('data-level="junior"', $html);
        $this->assertStringContainsString('data-level="senior"', $html);
        $this->assertStringContainsString('data-level="personnel"', $html);
        $this->assertStringContainsString('id="consultSearch"', $html);
        $this->assertStringContainsString('data-today="1"', $html);
    }

    #[Test]
    public function the_role_is_labelled_school_nurse_across_the_app(): void
    {
        $this->withSession($this->nurseSession())
            ->get('/dashboard/school-nurse')
            ->assertOk()
            ->assertDontSee('Clinical Teacher');

        $this->get('/account-request')
            ->assertOk()
            ->assertSee('School Nurse')
            ->assertDontSee('Clinical Teacher');
    }

    #[Test]
    public function every_nurse_page_uses_the_shared_sidebar_with_the_right_item_active(): void
    {
        $pages = [
            '/dashboard/school-nurse' => 'dashboard.school-nurse',
            '/nurse' => 'nurse.index',
            '/dashboard/student-health-records' => 'dashboard.student-health-records',
            '/dashboard/consultation-log' => 'dashboard.consultation-log',
            '/dashboard/medicine-inventory' => 'dashboard.medicine-inventory',
            '/dashboard/school-nurse/deworming' => 'dashboard.school-nurse.deworming',
            '/dashboard/data-visualization' => 'dashboard.data-visualization',
        ];

        foreach ($pages as $url => $activeRoute) {
            $html = $this->withSession($this->nurseSession())->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('class="nsb"', $html, "{$url} must render the shared nurse sidebar.");
            // The old per-page collapsed sidebar is gone everywhere.
            $this->assertStringNotContainsString('<aside class="sidebar">', $html, "{$url} still has its own sidebar.");
            $this->assertStringContainsString(
                'href="'.route($activeRoute).'" class="nsb-item active"',
                $html,
                "{$url} must highlight its own nav item."
            );
        }
    }

    #[Test]
    public function the_side_menu_renders_the_full_nav_and_the_user_card(): void
    {
        $nurse = $this->withSession($this->nurseSession())
            ->get('/dashboard/school-nurse')
            ->assertOk();

        foreach (['nsb-brand', 'nsb-label', 'nsb-item', 'nsb-footer', 'nsb-user', 'nsb-avatar'] as $hook) {
            $nurse->assertSee($hook, false);
        }

        foreach (['Clinic', 'Health Programs', 'Inventory', 'Reports', 'System'] as $section) {
            $nurse->assertSee($section);
        }

        $nurse->assertSee('Ana Reyes')
            ->assertSee('Sta. Ana NHS')
            ->assertSee('Review Queue')
            ->assertSee('Medicine Inventory')
            ->assertSee('AN');
    }

    #[Test]
    public function signing_out_from_the_side_menu_still_posts_a_csrf_protected_form(): void
    {
        $html = $this->withSession($this->nurseSession())
            ->get('/dashboard/school-nurse')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('action="'.route('logout').'"', $html);
        $this->assertMatchesRegularExpression(
            '/action="'.preg_quote(route('logout'), '/').'".*?_token/s',
            $html,
            'The logout form must carry a CSRF token.'
        );

        $this->withSession($this->nurseSession())
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertNull(session('active_role'));
    }

    #[Test]
    public function the_pending_health_card_badge_counts_unexamined_learners(): void
    {
        $session = array_merge($this->nurseSession(), [
            'school_health_card_records' => [
                ['lrn' => 'LRN001', 'examination' => []],
                ['lrn' => 'LRN002', 'examination' => []],
                ['lrn' => 'LRN003', 'examination' => ['deworming' => 'V']],
            ],
        ]);

        $html = $this->withSession($session)->get('/dashboard/school-nurse')->assertOk()->getContent();

        // Two learners still awaiting examination.
        $this->assertStringContainsString('<span class="nsb-badge alert">2</span>', $html);
    }

    #[Test]
    public function another_schools_consultations_never_appear(): void
    {
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);

        Consultation::create([
            'institution_id' => $other->id,
            'consulted_at' => now(),
            'student_name' => 'Outsider, Nina',
            'grade_section' => 'Grade 10 - Dalton',
            'condition' => 'Migraine',
            'treatment_given' => 'Rest',
            'status' => 'treated',
        ]);

        $response = $this->withSession($this->nurseSession())
            ->get('/dashboard/school-nurse')
            ->assertOk();

        $this->assertSame(0, $response->viewData('consultationsToday'));
        $response->assertDontSee('Outsider, Nina')->assertDontSee('Migraine');
    }
}
