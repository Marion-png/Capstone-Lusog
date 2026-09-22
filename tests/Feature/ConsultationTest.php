<?php

namespace Tests\Feature;

use App\Models\Condition;
use App\Models\Consultation;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
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

    /**
     * A saved consultation comes back to the page the dialog was opened on —
     * a learner's profile with that learner open, say — not always to the
     * Consultation Log. Only a URL inside this app is honoured.
     *
     * @test
     */
    public function saving_returns_to_where_the_dialog_was_opened(): void
    {
        $payload = [
            'consulted_at' => now()->format('Y-m-d'),
            'student_name' => 'John Doe',
            'grade_section' => 'Grade 10 - A',
            'condition' => 'Headache',
            'status' => 'treated',
        ];

        // From a learner's profile: back to that profile, learner open.
        $profile = route('dashboard.student-health-records').'?open=123456789012';
        $this->withSession($this->clinicSession())
            ->post('/dashboard/consultation-log', $payload + ['return_to' => $profile])
            ->assertRedirect($profile);

        // From the log, or with nothing said: the log.
        $this->withSession($this->clinicSession())
            ->post('/dashboard/consultation-log', $payload)
            ->assertRedirect(route('dashboard.consultation-log'));

        // A value off the wire pointing anywhere else is refused.
        $this->withSession($this->clinicSession())
            ->post('/dashboard/consultation-log', $payload + ['return_to' => 'https://evil.example/phish'])
            ->assertRedirect(route('dashboard.consultation-log'));

        $this->assertSame(3, Consultation::count());
    }

    /**
     * The dialog carries its page as the return target, and the profile
     * rewrites it to reopen the learner; the standalone page's Back and
     * Cancel go to the page it was opened from.
     *
     * @test
     */
    public function the_dialog_and_the_standalone_page_carry_the_return_target(): void
    {
        $log = $this->withSession($this->clinicSession())
            ->get(route('dashboard.consultation-log'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('name="return_to" id="cm_return_to" value="'.route('dashboard.consultation-log').'"', $log);

        $profile = $this->withSession($this->clinicSession() + ['active_role' => 'school_nurse'])
            ->get(route('dashboard.student-health-records'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString("url.searchParams.set('open', lrn)", $profile);

        $standalone = $this->withSession($this->clinicSession())
            ->from(route('dashboard.student-health-records'))
            ->get(route('consultations.create'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('href="'.route('dashboard.student-health-records').'" class="btn btn-ghost">Back</a>', $standalone);
        $this->assertStringContainsString('name="return_to" value="'.route('dashboard.student-health-records').'"', $standalone);

        // Opened cold — no referrer inside the app — it falls back to the log.
        $cold = $this->flushHeaders()->flushSession()
            ->withSession($this->clinicSession())
            ->get(route('consultations.create'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('href="'.route('dashboard.consultation-log').'" class="btn btn-ghost">Back</a>', $cold);
    }

    /**
     * The New Consultation page carries the same clinic-notes field the
     * profile's dialog does, so a note written from the dashboard's learner
     * search lands on the visit like any other.
     */
    /** @test */
    public function the_new_consultation_page_takes_a_clinic_note(): void
    {
        $html = $this->withSession($this->clinicSession())
            ->get(route('consultations.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Clinic Notes', $html);
        $this->assertStringContainsString('<textarea id="notes" name="notes" maxlength="2000"', $html);
    }

    /**
     * The learner is searched for, not typed. Both entrances to New
     * Consultation — the dialog and the standalone page — embed the school's
     * roll (LRN, name, grade – section) for a picker that fills the grade
     * and section from the record once a learner is chosen. The index is
     * scoped to the session's school and never lists another school's child.
     */
    /** @test */
    public function new_consultation_offers_a_learner_search_that_fills_the_section(): void
    {
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        StudentHealthRecord::create([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_id' => '130000000001',
            'student_name' => 'Cruz, Juan',
            'section' => 'Grade 10 / Dalton',
            'weight' => 40, 'bmi_value' => 18, 'nutritional_status' => 'Normal',
            'student_details' => ['lrn' => '130000000001', 'last_name' => 'Cruz', 'first_name' => 'Juan', 'grade_level' => 'Grade 10', 'section' => 'Dalton'],
        ]);
        StudentHealthRecord::create([
            'institution_id' => $other->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_id' => '130000000002',
            'student_name' => 'Outsider, Nina',
            'section' => 'Grade 8 / Bonifacio',
            'weight' => 40, 'bmi_value' => 18, 'nutritional_status' => 'Normal',
            'student_details' => ['lrn' => '130000000002', 'last_name' => 'Outsider', 'first_name' => 'Nina', 'grade_level' => 'Grade 8', 'section' => 'Bonifacio'],
        ]);

        $entry = json_encode(['lrn' => '130000000001', 'name' => 'Cruz, Juan', 'section' => 'Grade 10 - Dalton']);

        // The standalone page.
        $page = $this->withSession($this->clinicSession())
            ->get(route('consultations.create'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('id="student_search"', $page);
        $this->assertStringContainsString('role="combobox"', $page);
        $this->assertStringContainsString($entry, $page);
        $this->assertStringNotContainsString('Outsider', $page);

        // The dialog on the Consultation Log.
        $dialog = $this->withSession($this->clinicSession())
            ->get(route('dashboard.consultation-log'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('id="cm_student_combo"', $dialog);
        $this->assertStringContainsString('id="cm_student_results"', $dialog);
        $this->assertStringContainsString($entry, $dialog);
        $this->assertStringNotContainsString('Outsider', $dialog);
    }
}
