<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fill Medical Record writes to one child's record, through a URL that carries
 * only a position in the session's roster.
 *
 * So three things have to hold: the page works in a session that has not built
 * its roster yet, the save refuses to land on anybody but the learner the form
 * was opened for, and an examination is never mistaken for a weighing.
 */
class NurseExaminationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    private function nurseSession(array $extra = []): array
    {
        return $extra + [
            'active_role' => 'school_nurse',
            'active_name' => 'Nena Nurse',
            'active_username' => 'nurse.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->school->id,
        ];
    }

    private function learner(string $name, string $lrn, array $attributes = []): StudentHealthRecord
    {
        return StudentHealthRecord::create($attributes + [
            'institution_id' => $this->school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => $name,
            'student_id' => $lrn,
            'school_name' => 'Test School',
            'section' => 'Grade 7 / Maayos',
            'weight' => 30,
            'bmi_value' => 14.5,
            'nutritional_status' => 'Severely Wasted',
            'baseline_height_cm' => 144,
            'baseline_weight_kg' => 30,
            'baseline_bmi_value' => 14.5,
            'baseline_nutritional_status' => 'Severely Wasted',
            'student_details' => [
                'last_name' => strtok($name, ','),
                'first_name' => 'Tala',
                'gender' => 'Female',
                'nutritional_status_bmi_for_age' => 'Severely Wasted',
                'height_cm' => 144,
                'weight_kg' => 30,
            ],
        ]);
    }

    #[Test]
    public function the_form_opens_in_a_session_that_has_not_built_its_roster(): void
    {
        $this->learner('Dimaculangan, Tala', '100000000101');

        // A nurse signs back in and reloads the tab: nothing has synced yet.
        $this->withSession($this->nurseSession())
            ->get('/nurse/0/examine')
            ->assertOk()
            ->assertSee('Dimaculangan')
            ->assertSee('name="lrn" value="100000000101"', false);
    }

    #[Test]
    public function a_session_with_no_roster_can_still_save(): void
    {
        $record = $this->learner('Dimaculangan, Tala', '100000000102');

        $this->withSession($this->nurseSession())
            ->post('/nurse/0/examine', ['lrn' => '100000000102', 'skin_scalp' => 'Clear'])
            ->assertRedirect();

        $this->assertSame('Clear', $record->fresh()->examination['skin_scalp'] ?? null);
    }

    #[Test]
    public function an_examination_is_not_an_endline_weighing(): void
    {
        $record = $this->learner('Dimaculangan, Tala', '100000000103');

        $this->withSession($this->nurseSession())
            ->post('/nurse/0/examine', ['lrn' => '100000000103', 'date_of_examination' => '2026-10-26'])
            ->assertRedirect();

        $record->refresh();

        $this->assertNotEmpty($record->examination, 'The examination itself is saved.');
        $this->assertNull($record->endline_nutritional_status, 'Nobody re-measured this learner.');
        $this->assertNull($record->endline_recorded_at);
        $this->assertNull($record->endline_weight_kg);
        $this->assertNull($record->endline_height_cm);
    }

    #[Test]
    public function an_examination_leaves_a_real_endline_alone(): void
    {
        $record = $this->learner('Dimaculangan, Tala', '100000000104', [
            'endline_height_cm' => 146,
            'endline_weight_kg' => 36,
            'endline_bmi_value' => 16.9,
            'endline_nutritional_status' => 'Wasted',
            'endline_recorded_at' => '2026-10-01',
        ]);

        $this->withSession($this->nurseSession())
            ->post('/nurse/0/examine', ['lrn' => '100000000104', 'date_of_examination' => '2026-10-26'])
            ->assertRedirect();

        $record->refresh();

        $this->assertSame('Wasted', $record->endline_nutritional_status);
        $this->assertSame('2026-10-01', $record->endline_recorded_at->toDateString());
    }

    #[Test]
    public function the_save_refuses_a_form_opened_for_another_learner(): void
    {
        $first = $this->learner('Dimaculangan, Tala', '100000000105');
        $second = $this->learner('Batumbakal, Tala', '100000000106');

        // Whoever sits at position 0 now, the form says it was opened for
        // somebody else: an older tab, a roster rebuilt since.
        $atZero = StudentHealthRecord::orderBy('id')->first();
        $other = $atZero->is($first) ? $second : $first;

        $this->withSession($this->nurseSession())
            ->from('/nurse')
            ->post('/nurse/0/examine', ['lrn' => $other->student_id, 'skin_scalp' => 'Clear'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertEmpty($first->fresh()->examination);
        $this->assertEmpty($second->fresh()->examination);
    }
}
