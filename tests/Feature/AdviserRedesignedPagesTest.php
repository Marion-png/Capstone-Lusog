<?php

namespace Tests\Feature;

use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdviserRedesignedPagesTest extends TestCase
{
    use RefreshDatabase;

    private function adviserSession(Institution $inst): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Maria Santos',
            'active_username' => 'maria.santos',
            'active_institution_id' => $inst->id,
            'assigned_grade_level' => 'Grade 10',
            'assigned_section' => 'Dalton',
            'assigned_school_name' => $inst->name,
            'school_health_card_records' => [
                [
                    'last_name' => 'Gomez', 'first_name' => 'Jose', 'middle_name' => '',
                    'lrn' => '123456789014', 'grade_level' => 'Grade 10', 'section' => 'Dalton',
                    'height_cm' => 150, 'weight_kg' => 40, 'nutritional_status_bmi_for_age' => 'Normal',
                    'examination' => [],
                ],
            ],
        ];
    }

    #[Test]
    public function every_redesigned_adviser_page_renders_with_the_shared_sidebar(): void
    {
        $inst = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
        $session = $this->adviserSession($inst);

        $this->withSession($session)->get('/dashboard/class-adviser')->assertStatus(200)->assertSee('Good');
        $this->withSession($session)->get('/dashboard/class-adviser/feeding-status')->assertStatus(200);
        $this->withSession($session)->get('/adviser/create')->assertStatus(200);
    }

    #[Test]
    public function the_deworming_request_page_is_gone_from_the_adviser_side(): void
    {
        $inst = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
        $session = $this->adviserSession($inst);

        $this->withSession($session)->get('/dashboard/class-adviser/deworming')->assertNotFound();
        $this->withSession($session)->post('/dashboard/class-adviser/deworming')->assertNotFound();
        $this->withSession($session)->get('/dashboard/class-adviser')->assertDontSee('Deworming Request');
    }

    #[Test]
    public function adding_a_student_via_the_redesigned_form_still_works(): void
    {
        $inst = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
        $session = $this->adviserSession($inst);

        $response = $this->withSession($session)
            ->post('/adviser/store', [
                'last_name' => 'Reyes', 'first_name' => 'Maria', 'middle_name' => '',
                'lrn' => '999999999999', 'birth_month' => 1, 'birth_day' => 1, 'birth_year' => 2012,
                'birthplace' => 'Davao', 'parent_guardian' => 'Pedro Reyes', 'address' => '123 St',
                'region' => 'XI', 'division' => 'Davao', 'telephone_no' => '0912',
                'height_cm' => 140, 'weight_kg' => 35, 'grade_level' => 'Grade 10', 'section' => 'Dalton',
            ]);

        // Sheet 1 and Sheet 2 (systems review) are one combined form, so a
        // single submission is enough — no separate Health Assessment step.
        $response->assertRedirect(route('dashboard.class-adviser'));

        $this->withSession($session)
            ->get('/dashboard/class-adviser?tab=saved')
            ->assertStatus(200)
            ->assertSee('Reyes, Maria');
    }
}
