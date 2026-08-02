<?php

namespace Tests\Feature;

use App\Models\HealthAssessment;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnrollStudentSheet2Test extends TestCase
{
    use RefreshDatabase;

    private Institution $inst;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inst = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function adviserSession(): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Maria Santos',
            'active_username' => 'maria.santos',
            'active_institution_id' => $this->inst->id,
            'assigned_grade_level' => 'Grade 10',
            'assigned_section' => 'Dalton',
            'assigned_school_name' => $this->inst->name,
        ];
    }

    private function enrollGomez(array $session): void
    {
        $this->withSession($session)->post('/adviser/store', [
            'last_name' => 'Gomez', 'first_name' => 'Jose', 'middle_name' => '',
            'lrn' => '123456789014', 'birth_month' => 1, 'birth_day' => 1, 'birth_year' => 2012,
            'birthplace' => 'Davao', 'parent_guardian' => 'Ana Gomez', 'address' => '123 St',
            'school_id' => '1', 'region' => 'XI', 'division' => 'Davao', 'telephone_no' => '0912',
            'height_cm' => 140, 'weight_kg' => 35, 'grade_level' => 'Grade 10', 'section' => 'Dalton',
        ]);
    }

    #[Test]
    public function sidebar_no_longer_lists_a_separate_health_assessment_link(): void
    {
        $this->withSession($this->adviserSession())
            ->get('/dashboard/class-adviser')
            ->assertStatus(200)
            ->assertDontSee('Health Assessment</span>', false);
    }

    #[Test]
    public function sheet_two_shows_the_unlocked_form_for_a_freshly_enrolled_student(): void
    {
        $session = $this->adviserSession();
        $this->enrollGomez($session);

        $response = $this->withSession($session)
            ->get('/dashboard/class-adviser?tab=form&sheet=2&lrn=123456789014');

        $response->assertStatus(200);
        $response->assertSee('Gomez, Jose');
        // The "Locked" badge only renders in the server-side locked banner,
        // which must be absent before any assessment has been submitted.
        $response->assertDontSee('>Locked<', false);
        // Form must not carry display:none when no assessment exists yet.
        $response->assertSee('<form id="healthAssessmentForm" method="POST" action="'.route('health-assessment.store').'" novalidate >', false);
    }

    #[Test]
    public function submitting_sheet_two_locks_it_on_the_next_view(): void
    {
        $session = $this->adviserSession();
        $this->enrollGomez($session);

        $this->withSession($session)->post('/adviser/health-assessment', [
            'lrn' => '123456789014',
            'date_of_assessment' => '2026-07-28',
            'assessed_by' => 'Maria Santos',
        ])->assertRedirect();

        $response = $this->withSession($session)
            ->get('/dashboard/class-adviser?tab=form&sheet=2&lrn=123456789014');

        $response->assertStatus(200);
        $response->assertSee('Health Assessment Already on File');
        $response->assertSee('style="display:none;"', false);

        $record = StudentHealthRecord::where('student_id', '123456789014')->first();
        $this->assertSame(1, HealthAssessment::where('student_health_record_id', $record->id)->count());
    }

    #[Test]
    public function sheet_two_without_a_student_shows_the_placeholder(): void
    {
        $response = $this->withSession($this->adviserSession())
            ->get('/dashboard/class-adviser?tab=form&sheet=2');

        $response->assertStatus(200);
        $response->assertSee('No student selected yet');
    }
}
