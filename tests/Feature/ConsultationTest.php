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
            'active_role' => 'clinic_staff',
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

        // student_name and condition are encrypted at rest — assert via the model.
        $consultation = Consultation::where('condition_id', $condition->id)->first();
        $this->assertNotNull($consultation);
        $this->assertSame('John Doe', $consultation->student_name);
        $this->assertSame('Fever', $consultation->condition);
        $this->assertNotSame('John Doe', $consultation->getRawOriginal('student_name'));
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

        // student_name and condition are encrypted at rest — assert via the model.
        $consultation = Consultation::latest('id')->first();
        $this->assertNotNull($consultation);
        $this->assertSame('Jane Doe', $consultation->student_name);
        $this->assertSame('Custom Condition', $consultation->condition);
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

        // The condition-search component only ever renders @error('condition_id')
        // (its `name` prop). The error must be attached under that same key or
        // it never reaches the user — see missing_condition_error_is_visible_to_the_condition_search_component.
        $response->assertSessionHasErrors('condition_id', null, 'consultation');

        $this->assertSame(0, Consultation::count());
    }

    /**
     * Regression: the controller used to attach the "missing condition" error
     * under the key 'condition', but resources/views/components/condition-search.blade.php
     * only displays @error($name) where $name is the `name` prop passed in
     * (consultation-create.blade.php passes name="condition_id"). The mismatch
     * meant a user who submitted without picking a condition — e.g. typed a
     * name and pressed Enter, which (before the JS fix) didn't select
     * anything — saw no error message at all. The form just silently failed
     * and nothing was ever saved.
     */
    /** @test */
    public function missing_condition_error_is_visible_to_the_condition_search_component(): void
    {
        $response = $this->withSession($this->clinicSession())
            ->post('/dashboard/consultation-log', [
                'consulted_at' => now()->format('Y-m-d'),
                'student_name' => 'Pedro Reyes',
                'grade_section' => 'Grade 9 - C',
                'status' => 'treated',
            ]);

        $response->assertSessionHasErrors(['condition_id' => 'Please select or enter a condition.'], null, 'consultation');
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

        $response->assertSessionHasErrors('condition_id', null, 'consultation');
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
