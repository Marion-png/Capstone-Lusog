<?php

namespace Tests\Feature;

use App\Models\Condition;
use App\Models\Consultation;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\ConsultationVisibility;
use App\Support\SchoolHeadHealthOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who may see what a learner came to the clinic for.
 *
 * The complaint, the diagnosis and the treatment are the clinical narrative
 * about a child. Only the desks treating them see it:
 *
 *   - School nurse and clinic staff: the whole record.
 *   - Class adviser: the date, the time, and that the learner attended. A
 *     teacher needs to know a pupil was out of class; they do not need the
 *     diagnosis to do their job. The one exception is what the clinic hands
 *     over on purpose — a photograph the nurse shared, and now the note on
 *     the visit when the nurse ticked "share with the class adviser". That
 *     is a disclosure somebody made, per visit and reversibly, not a widening
 *     of the role.
 *   - School head: nothing per-visit. Their screens are management summaries.
 *     School-wide tallies stay — "twelve headaches this month" is a statistic
 *     about the school, not a detail about a learner.
 *
 * The redaction happens where the payload is built, never in a template: a
 * value that reaches the browser has been disclosed whether or not the view
 * chose to print it. These tests assert on the rendered page for that reason.
 *
 * CONTESTED — the class adviser's line is not settled, and these tests are
 * currently what holds it in place. Ma'am Nanette's guidance is date and time
 * only; the school nurse argues on the record that the adviser is the
 * "second parent sa school" and needs the full record to inform parents.
 * See docs/open-decisions.md, entry 1.
 *
 * If a test in the class-adviser section below starts failing, check whether
 * somebody has implemented the other side of that argument before you "fix"
 * the code to match the test.
 */
class ConsultationPrivacyTest extends TestCase
{
    use RefreshDatabase;

    /** Strings that cannot occur anywhere else on a page. */
    private const COMPLAINT = 'Zymotic pharyngitis marker';

    private const TREATMENT = 'Placebo lozenge marker';

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function learner(): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'student_id' => '120000000001',
            'student_name' => 'Cruz, Juan',
            'school_name' => 'Sta. Ana NHS',
            'grade_level' => 'Grade 10',
            'section' => 'Grade 10 / Dalton',
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'weight' => '40',
            'bmi_value' => '18',
            'nutritional_status' => 'Normal',
            'student_details' => [
                'lrn' => '120000000001',
                'last_name' => 'Cruz',
                'first_name' => 'Juan',
                'grade_level' => 'Grade 10',
                'section' => 'Dalton',
            ],
        ]);
    }

    private function visit(): Consultation
    {
        return Consultation::create([
            'institution_id' => $this->school->id,
            'student_name' => 'Cruz, Juan',
            'grade_section' => 'Grade 10 / Dalton',
            'condition' => self::COMPLAINT,
            'treatment_given' => self::TREATMENT,
            'status' => 'referred',
            'consulted_at' => now()->subDay(),
        ]);
    }

    private function sessionFor(string $role): array
    {
        $base = [
            'active_role' => $role,
            'active_name' => 'Staff Member',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
            'school_health_card_records' => [[
                'lrn' => '120000000001',
                'first_name' => 'Juan',
                'last_name' => 'Cruz',
                'grade_level' => 'Grade 10',
                'section' => 'Dalton',
            ]],
        ];

        if ($role === 'class_adviser') {
            $base['assigned_grade_level'] = 'Grade 10';
            $base['assigned_section'] = 'Dalton';
        }

        return $base;
    }

    // ── The rule itself ──────────────────────────────────────────────

    #[Test]
    public function only_the_treating_desks_may_see_details(): void
    {
        $this->assertTrue(ConsultationVisibility::maySeeDetails('school_nurse'));
        $this->assertTrue(ConsultationVisibility::maySeeDetails('clinic_staff'));

        foreach (['class_adviser', 'school_head', 'feeding_coor', 'nutricor', 'system_admin', '', null] as $role) {
            $this->assertFalse(
                ConsultationVisibility::maySeeDetails($role),
                'Role '.var_export($role, true).' must not see consultation details.'
            );
        }
    }

    #[Test]
    public function a_redacted_visit_carries_the_timestamp_and_nothing_clinical(): void
    {
        $row = ConsultationVisibility::present($this->visit(), 'class_adviser');

        $this->assertNotNull($row['consulted_at']);
        $this->assertNotNull($row['date']);
        $this->assertNotNull($row['time']);
        $this->assertFalse($row['details_visible']);

        foreach (['condition', 'treatment_given', 'status', 'grade_section'] as $clinical) {
            $this->assertArrayNotHasKey($clinical, $row, "'$clinical' must not reach a redacted payload.");
        }
    }

    #[Test]
    public function the_clinic_sees_the_whole_record(): void
    {
        $row = ConsultationVisibility::present($this->visit(), 'school_nurse');

        $this->assertTrue($row['details_visible']);
        $this->assertSame(self::COMPLAINT, $row['condition']);
        $this->assertSame(self::TREATMENT, $row['treatment_given']);
        $this->assertSame('referred', $row['status']);
    }

    // ── The class adviser ────────────────────────────────────────────

    #[Test]
    public function the_advisers_profile_shows_the_visit_but_not_the_narrative(): void
    {
        $this->learner();
        $this->visit();

        $html = $this->withSession($this->sessionFor('class_adviser'))
            ->get(route('dashboard.class-adviser.student-profile', '120000000001'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('vpConsultationsList', $html);
        $this->assertStringContainsString('<b>Date and time, plus what the clinic shares:</b>', $html);

        $this->assertStringNotContainsString(self::COMPLAINT, $html);
        $this->assertStringNotContainsString(self::TREATMENT, $html);
    }

    /** The adviser's dashboard embeds the same meta payload. */
    /**
     * A note reaches the adviser only because the clinic sent it.
     *
     * The note is clinical text and stops at the clinic like the complaint
     * and the treatment — unless the nurse ticked "share this note with the
     * learner's class adviser" when they wrote it. An unshared note is
     * absent from the payload, not blanked in the view.
     */
    #[Test]
    public function only_a_note_the_clinic_shared_reaches_the_adviser(): void
    {
        $visit = $this->visit();
        $visit->forceFill(['notes' => 'Watch for dizziness this afternoon.'])->save();

        // Written, not shared: the adviser is told nothing about it.
        $row = ConsultationVisibility::present($visit->fresh(), 'class_adviser');
        $this->assertArrayNotHasKey('shared_note', $row);

        // Shared: it is the one piece of clinical text this role receives.
        $visit->forceFill(['notes_shared_with_adviser' => true])->save();
        $row = ConsultationVisibility::present($visit->fresh(), 'class_adviser');
        $this->assertSame('Watch for dizziness this afternoon.', $row['shared_note']);

        // And still nothing else.
        foreach (['condition', 'treatment_given', 'status', 'grade_section'] as $clinical) {
            $this->assertArrayNotHasKey($clinical, $row);
        }
        $this->assertFalse($row['details_visible']);
    }

    /** The whole way round: the nurse writes it, the adviser reads it. */
    #[Test]
    public function a_shared_note_is_written_on_the_visit_and_read_on_the_advisers_profile(): void
    {
        $this->learner();

        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('consultations.store'), [
                'consulted_at' => now()->toDateTimeString(),
                'student_name' => 'Cruz, Juan',
                'grade_section' => 'Grade 10 / Dalton',
                'condition' => self::COMPLAINT,
                'treatment_given' => self::TREATMENT,
                'notes' => 'No PE for a week.',
                'notes_shared_with_adviser' => '1',
                'status' => 'treated',
            ])
            ->assertRedirect();

        $this->assertTrue(Consultation::latest('id')->first()->notes_shared_with_adviser);

        $html = $this->withSession($this->sessionFor('class_adviser'))
            ->get(route('dashboard.class-adviser.student-profile', '120000000001'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No PE for a week.', $html);
        $this->assertStringContainsString('Note from the clinic', $html);
        // The rest of the visit is still the clinic's.
        $this->assertStringNotContainsString(self::COMPLAINT, $html);
        $this->assertStringNotContainsString(self::TREATMENT, $html);
    }

    /** A note left unshared never reaches the adviser's page. */
    #[Test]
    public function an_unshared_note_never_reaches_the_advisers_profile(): void
    {
        $this->learner();
        $this->visit()->forceFill(['notes' => 'Clinic-only observation.'])->save();

        $html = $this->withSession($this->sessionFor('class_adviser'))
            ->get(route('dashboard.class-adviser.student-profile', '120000000001'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Clinic-only observation.', $html);
    }

    #[Test]
    public function the_advisers_dashboard_never_embeds_the_narrative(): void
    {
        $this->learner();
        $this->visit();

        $html = $this->withSession($this->sessionFor('class_adviser'))
            ->get(route('dashboard.class-adviser'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(self::COMPLAINT, $html);
        $this->assertStringNotContainsString(self::TREATMENT, $html);
    }

    /**
     * The API the profile's clinic tabs call is nurse-and-clinic only, so an
     * adviser cannot fetch what the page declined to render.
     */
    #[Test]
    public function the_consultations_api_refuses_the_adviser(): void
    {
        $this->learner();
        $this->visit();

        $this->withSession($this->sessionFor('class_adviser'))
            ->getJson(route('api.student-consultations', ['lrn' => '120000000001']))
            ->assertForbidden();
    }

    // ── The school head ──────────────────────────────────────────────

    /**
     * The head's latest-visits table listed each visit's complaint. That is a
     * per-visit clinical detail, so the column is gone and the payload behind
     * it no longer carries the field at all — a template cannot print what it
     * was never handed.
     */
    #[Test]
    public function the_heads_latest_visits_carry_no_complaint(): void
    {
        $this->learner();
        $this->visit();

        $html = $this->withSession($this->sessionFor('school_head'))
            ->get(route('dashboard.school-head.health'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<th>Complaint</th>', $html);

        // Treatment never reaches this role at all.
        $this->assertStringNotContainsString(self::TREATMENT, $html);

        // And the row the table is built from has no such key to print.
        $overview = SchoolHeadHealthOverview::for(
            $this->school->id,
            StudentHealthRecord::currentSchoolYear(),
            StudentHealthRecord::where('institution_id', $this->school->id)->get(),
        );

        $recent = $overview->clinic()['recent'] ?? [];
        $this->assertNotEmpty($recent, 'The visit should reach the head as a row.');

        foreach ($recent as $row) {
            $this->assertArrayNotHasKey('complaint', $row);
        }
    }

    /** Nothing about the treatment reaches the head's dashboard either. */
    #[Test]
    public function the_heads_dashboard_shows_no_treatment(): void
    {
        $this->learner();
        $this->visit();

        $html = $this->withSession($this->sessionFor('school_head'))
            ->get(route('dashboard.school-head'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(self::TREATMENT, $html);
    }

    /**
     * School-wide tallies are a statistic about the school, not a detail
     * about a learner, and the head needs them to run the clinic. Removing
     * the per-visit complaint must not have taken the aggregate with it.
     */
    #[Test]
    public function the_head_keeps_the_school_wide_tallies(): void
    {
        $this->learner();
        $this->visit();

        $html = $this->withSession($this->sessionFor('school_head'))
            ->get(route('dashboard.school-head.health'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Complaint', $html, 'The complaint rankings panel is still there.');
    }

    // ── The clinic ───────────────────────────────────────────────────

    #[Test]
    public function the_nurse_and_clinic_still_see_everything(): void
    {
        Condition::create(['name' => 'Headache', 'category' => 'General']);
        $this->learner();
        $this->visit();

        foreach (['school_nurse', 'clinic_staff'] as $role) {
            $html = $this->withSession($this->sessionFor($role))
                ->get(route('dashboard.consultation-log'))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString(self::COMPLAINT, $html, "$role must still see the complaint.");
        }
    }

    #[Test]
    public function the_consultations_api_serves_the_clinic(): void
    {
        $this->learner();
        $this->visit();

        $rows = $this->withSession($this->sessionFor('school_nurse'))
            ->getJson(route('api.student-consultations', ['lrn' => '120000000001']))
            ->assertOk()
            ->json('consultations');

        $this->assertSame(self::COMPLAINT, $rows[0]['condition']);
        $this->assertSame(self::TREATMENT, $rows[0]['treatment']);
    }
}
