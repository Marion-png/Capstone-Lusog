<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A class adviser is scoped twice: to their school, and within it to one class.
 *
 * The second half of that is easy to lose, because the session roster
 * (`school_health_card_records`) is deliberately the *whole school* —
 * StudentRosterSync rebuilds it from every learner the institution holds, so
 * the roster survives a session expiry. Every adviser surface is then
 * responsible for narrowing it to the one class, and a surface that forgets
 * shows one teacher another teacher's learners without any query looking wrong.
 *
 * MultiSchoolDataSeparationTest covers the school boundary. This covers the
 * class boundary inside one school, which is the one a shared session roster
 * can leak across.
 */
class AdviserClassScopeTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::create(['name' => 'One School', 'status' => 'active']);
    }

    /** The session an adviser of Grade 7 / MATIYAGA holds. */
    private function matiyagaAdviser(): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Adviser Matiyaga',
            'active_username' => 'adviser.matiyaga',
            'active_school_name' => 'One School',
            'active_institution_id' => $this->institution->id,
            'assigned_grade_level' => 'Grade 7',
            'assigned_section' => 'MATIYAGA',
        ];
    }

    private function makeLearner(string $lrn, string $grade, string $section, string $surname): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => $surname.', Juan',
            'student_id' => $lrn,
            'school_name' => 'One School',
            'section' => $grade.' / '.$section,
            'weight' => 30,
            'bmi_value' => 15.1,
            'nutritional_status' => 'Wasted',
            'baseline_nutritional_status' => 'Wasted',
            'student_details' => [
                'last_name' => $surname,
                'first_name' => 'Juan',
                'middle_name' => 'Cruz',
                'grade_level' => $grade,
                'section' => $section,
                'gender' => 'Male',
            ],
        ]);
    }

    /**
     * The roster the adviser is shown holds their own class and nothing else,
     * even though the session behind it was rebuilt from the whole school.
     */
    #[Test]
    public function the_dashboard_roster_holds_only_the_advisers_own_class(): void
    {
        $this->makeLearner('LRN-MINE', 'Grade 7', 'MATIYAGA', 'Mine');
        $this->makeLearner('LRN-THEIRS', 'Grade 7', 'MASIPAG', 'Theirs');

        $response = $this->withSession($this->matiyagaAdviser())
            ->get('/dashboard/class-adviser')
            ->assertOk();

        $lrns = collect($response->viewData('records'))->pluck('student_id')->all();

        $this->assertContains('LRN-MINE', $lrns);
        $this->assertNotContains('LRN-THEIRS', $lrns, 'The roster shows a learner from another adviser\'s section.');
    }

    /**
     * The profile page is reached by putting an LRN in the URL, so it is the
     * surface where guessing another section's learner costs nothing. It has
     * always *said* "Student not found in your assigned class" — it has to mean
     * it.
     */
    #[Test]
    public function an_adviser_cannot_open_a_learner_from_another_section(): void
    {
        $this->makeLearner('LRN-MINE', 'Grade 7', 'MATIYAGA', 'Mine');
        $this->makeLearner('LRN-THEIRS', 'Grade 7', 'MASIPAG', 'Theirs');

        // Their own learner opens.
        $this->withSession($this->matiyagaAdviser())
            ->get('/dashboard/class-adviser/students/LRN-MINE')
            ->assertOk();

        // The other section's learner does not.
        $this->withSession($this->matiyagaAdviser())
            ->get('/dashboard/class-adviser/students/LRN-THEIRS')
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    /**
     * Consent forms and health assessments are filled per learner, so their
     * pickers must offer the adviser's class only.
     */
    #[Test]
    public function the_consent_and_assessment_pickers_offer_only_the_advisers_class(): void
    {
        $this->makeLearner('LRN-MINE', 'Grade 7', 'MATIYAGA', 'Mine');
        $this->makeLearner('LRN-THEIRS', 'Grade 7', 'MASIPAG', 'Theirs');

        foreach (['/dashboard/class-adviser/consent-forms', '/dashboard/class-adviser/health-assessments'] as $url) {
            $body = $this->withSession($this->matiyagaAdviser())->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('LRN-MINE', $body, $url.' does not offer the adviser their own learner.');
            $this->assertStringNotContainsString('LRN-THEIRS', $body, $url.' offers a learner from another section.');
        }
    }

    /**
     * An adviser account cannot be approved without a grade and a section
     * (`required_if:role,class_adviser`), so a session carrying neither is a
     * broken account, not an adviser of every class. Scope fails closed here
     * for the reason it does in SchoolHeadOverview: the failure mode of a
     * missing scope must be seeing nothing, never seeing everyone.
     */
    #[Test]
    public function an_adviser_with_no_assigned_class_sees_no_learners(): void
    {
        $this->makeLearner('LRN-MINE', 'Grade 7', 'MATIYAGA', 'Mine');
        $this->makeLearner('LRN-THEIRS', 'Grade 7', 'MASIPAG', 'Theirs');

        $unassigned = $this->matiyagaAdviser();
        $unassigned['assigned_grade_level'] = '';
        $unassigned['assigned_section'] = '';

        $response = $this->withSession($unassigned)->get('/dashboard/class-adviser')->assertOk();

        $this->assertSame(
            [],
            collect($response->viewData('records'))->pluck('student_id')->all(),
            'An adviser with no assigned class was shown the whole school.'
        );
    }

    /**
     * The section string is a choice from the school's catalogue, but it is
     * compared as text, so an adviser must still match their own class when the
     * spelling differs in case or stray spacing.
     */
    #[Test]
    public function the_class_match_ignores_case_and_surrounding_space(): void
    {
        $this->makeLearner('LRN-MINE', 'Grade 7', 'MATIYAGA', 'Mine');

        $session = $this->matiyagaAdviser();
        $session['assigned_section'] = '  matiyaga ';

        $response = $this->withSession($session)->get('/dashboard/class-adviser')->assertOk();

        $this->assertContains(
            'LRN-MINE',
            collect($response->viewData('records'))->pluck('student_id')->all()
        );
    }

    /**
     * The payload the School Health Card form posts for one learner.
     *
     * @return array<string, mixed>
     */
    private function cardPayload(string $lrn, string $surname): array
    {
        return [
            'last_name' => $surname,
            'first_name' => 'Juan',
            'middle_name' => 'Cruz',
            'lrn' => $lrn,
            'birth_month' => 5,
            'birth_day' => 12,
            'birth_year' => 2012,
            'birthplace' => 'Davao City',
            'parent_guardian' => 'Maria Cruz',
            'address' => '12 Mabini St',
            'telephone_no' => '09171234567',
            'gender' => 'Male',
            'height_cm' => 140,
            'weight_kg' => 32,
            'grade_level' => 'Grade 7',
            'section' => 'MATIYAGA',
        ];
    }

    /**
     * Reading is scoped to one class; writing has to be too. The record lookup
     * behind the form is keyed on LRN alone, and the grade and section posted
     * are overwritten with the submitting adviser's own — so an unguarded write
     * let one adviser overwrite a colleague's learner and move them into their
     * own class by typing the LRN.
     */
    #[Test]
    public function an_adviser_cannot_overwrite_a_learner_from_another_section(): void
    {
        $theirs = $this->makeLearner('LRN-THEIRS', 'Grade 7', 'MASIPAG', 'Theirs');

        $this->withSession($this->matiyagaAdviser())
            ->post('/adviser/store', $this->cardPayload('LRN-THEIRS', 'Hijacked'))
            ->assertRedirect()
            ->assertSessionHas('error');

        $theirs->refresh();

        $this->assertSame('Grade 7 / MASIPAG', $theirs->section, "The learner was moved into another adviser's class.");
        $this->assertStringStartsWith('Theirs', (string) $theirs->student_name, "Another adviser overwrote the learner's record.");
    }

    /** Enrolling into the adviser's own class still works. */
    #[Test]
    public function an_adviser_can_still_enrol_and_re_edit_their_own_learner(): void
    {
        $this->withSession($this->matiyagaAdviser())
            ->post('/adviser/store', $this->cardPayload('LRN-NEW', 'Newcomer'))
            ->assertRedirect();

        $record = StudentHealthRecord::where('student_id', 'LRN-NEW')->first();
        $this->assertNotNull($record, 'The adviser could not enrol a learner into their own class.');
        $this->assertSame('Grade 7 / MATIYAGA', $record->section);

        // And editing that same learner again is not mistaken for a takeover.
        $this->withSession($this->matiyagaAdviser())
            ->post('/adviser/store', $this->cardPayload('LRN-NEW', 'Corrected'))
            ->assertRedirect()
            ->assertSessionMissing('error');

        $this->assertStringStartsWith('Corrected', (string) $record->fresh()->student_name);
    }
}
