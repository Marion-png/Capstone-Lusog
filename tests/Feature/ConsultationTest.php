<?php

namespace Tests\Feature;

use App\Models\Condition;
use App\Models\Consultation;
use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsultationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);

        // Seed some conditions
        Condition::create(['name' => 'Fever', 'category' => 'General']);
        Condition::create(['name' => 'Cough', 'category' => 'Respiratory']);
    }

    private function clinicSession(): array
    {
        return [
            'active_role'           => 'clinic_staff',
            'active_institution_id' => $this->institution->id,
        ];
    }

    /** @test */
    public function can_store_consultation_with_condition_id(): void
    {
        $condition = Condition::where('name', 'Fever')->first();

        $response = $this->withSession($this->clinicSession())
            ->post('/dashboard/consultation-log', [
            'consulted_at' => now()->format('Y-m-d'),
            'student_name' => 'John Doe',
            'grade_section' => 'Grade 10 - A',
            'condition_id' => $condition->id,
            'treatment_given' => 'Rest and fluids',
            'status' => 'treated',
        ]);

        $response->assertRedirect(route('dashboard.consultation-log'));

        $this->assertDatabaseHas('consultations', [
            'student_name' => 'John Doe',
            'condition' => 'Fever',
            'condition_id' => $condition->id,
        ]);
    }

    /** @test */
    public function can_store_consultation_with_manual_condition_text(): void
    {
        $response = $this->withSession($this->clinicSession())
            ->post('/dashboard/consultation-log', [
            'consulted_at' => now()->format('Y-m-d'),
            'student_name' => 'Jane Doe',
            'grade_section' => 'Grade 10 - B',
            'condition' => 'Custom Condition',
            'treatment_given' => 'Observation',
            'status' => 'referred',
        ]);

        $response->assertRedirect(route('dashboard.consultation-log'));

        $this->assertDatabaseHas('consultations', [
            'student_name' => 'Jane Doe',
            'condition' => 'Custom Condition',
        ]);
    }

    /** @test */
    public function requires_either_condition_id_or_condition_text(): void
    {
        $response = $this->withSession($this->clinicSession())
            ->post('/dashboard/consultation-log', [
            'consulted_at' => now()->format('Y-m-d'),
            'student_name' => 'Test Student',
            'grade_section' => 'Grade 10 - C',
            'treatment_given' => 'Test',
            'status' => 'treated',
        ]);

        $response->assertSessionHasErrors('condition');
    }

    /** @test */
    public function can_retrieve_consultation_log_with_condition_relationship(): void
    {
        $condition = Condition::where('name', 'Fever')->first();

        Consultation::create([
            'institution_id' => $this->institution->id,
            'consulted_at' => now(),
            'student_name' => 'Test Student',
            'grade_section' => 'Grade 10',
            'condition' => 'Fever',
            'condition_id' => $condition->id,
            'treatment_given' => 'Rest',
            'status' => 'treated',
        ]);

        $response = $this->withSession($this->clinicSession())
            ->get('/dashboard/consultation-log');

        $response->assertStatus(200);

        $consultation = Consultation::first();
        $this->assertNotNull($consultation->conditionRecord);
        $this->assertEquals('Fever', $consultation->conditionRecord->name);
    }

    /** @test */
    public function validates_condition_id_exists(): void
    {
        $response = $this->withSession($this->clinicSession())
            ->post('/dashboard/consultation-log', [
            'consulted_at' => now()->format('Y-m-d'),
            'student_name' => 'Test Student',
            'grade_section' => 'Grade 10',
            'condition_id' => 9999,
            'treatment_given' => 'Test',
            'status' => 'treated',
        ]);

        $response->assertSessionHasErrors('condition_id');
    }

    /** @test */
    public function accepts_valid_status_values(): void
    {
        $condition = Condition::where('name', 'Fever')->first();

        foreach (['treated', 'referred'] as $status) {
            $response = $this->withSession($this->clinicSession())
                ->post('/dashboard/consultation-log', [
                'consulted_at' => now()->format('Y-m-d'),
                'student_name' => "Student {$status}",
                'grade_section' => 'Grade 10',
                'condition_id' => $condition->id,
                'treatment_given' => 'Test',
                'status' => $status,
            ]);

            $response->assertRedirect();
        }
    }
}
