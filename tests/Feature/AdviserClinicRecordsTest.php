<?php

namespace Tests\Feature;

use App\Models\ClinicNote;
use App\Models\Consultation;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Clinic Notes and Consultation Log on the class adviser's student profile.
 * Both are written by the school nurse / clinic staff and are read-only here:
 * the adviser sees the records for their own school's learner and has no way,
 * on the page or through the clinic API, to add or change one.
 */
class AdviserClinicRecordsTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    private Institution $otherSchool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Test School', 'status' => 'active']);
        $this->otherSchool = Institution::create(['name' => 'Other School', 'status' => 'active']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function adviserSession(?Institution $school = null): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Test Adviser',
            'assigned_grade_level' => 'Grade 10',
            'assigned_section' => 'Dalton',
            'active_institution_id' => ($school ?? $this->school)->id,
        ];
    }

    private function learner(string $lrn = 'LRN001', string $name = 'Dela Cruz, Juan', ?Institution $school = null): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => ($school ?? $this->school)->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_id' => $lrn,
            'student_name' => $name,
            'section' => 'Grade 10 / Dalton',
            'weight' => 40,
            'bmi_value' => 17.7,
            'nutritional_status' => 'Normal',
            'student_details' => [
                'last_name' => explode(',', $name)[0],
                'first_name' => trim(explode(',', $name)[1] ?? ''),
                'grade_level' => 'Grade 10',
                'section' => 'Dalton',
            ],
        ]);
    }

    private function note(string $lrn, string $text, ?Institution $school = null, ?string $followUp = null): ClinicNote
    {
        return ClinicNote::create([
            'institution_id' => ($school ?? $this->school)->id,
            'student_lrn' => $lrn,
            'school_year' => ClinicNote::currentSchoolYear(),
            'note' => $text,
            'author_name' => 'Ana Reyes, RN',
            'follow_up_date' => $followUp,
        ]);
    }

    private function consultation(string $name, array $overrides = [], ?Institution $school = null): Consultation
    {
        return Consultation::create(array_merge([
            'institution_id' => ($school ?? $this->school)->id,
            'consulted_at' => now()->subDay(),
            'student_name' => $name,
            'grade_section' => 'Grade 10 - Dalton',
            'condition' => 'Asthma',
            'treatment_given' => 'Salbutamol inhaler administered',
            'status' => 'referred',
        ], $overrides));
    }

    private function profile(string $lrn = 'LRN001', ?Institution $school = null): string
    {
        return $this->flushSession()
            ->withSession($this->adviserSession($school))
            ->get(route('dashboard.class-adviser.student-profile', $lrn))
            ->assertOk()
            ->getContent();
    }

    // ── tabs ────────────────────────────────────────────────────────────────

    #[Test]
    public function the_profile_carries_a_clinic_notes_and_a_consultation_log_tab(): void
    {
        $this->learner();

        $html = $this->profile();

        $this->assertStringContainsString('data-panel="vpTabNotes"', $html);
        $this->assertStringContainsString('data-panel="vpTabConsultations"', $html);
        $this->assertStringContainsString('Clinic Notes', $html);
        $this->assertStringContainsString('Note History', $html);
        $this->assertStringContainsString('Consultation History', $html);
    }

    // ── clinic notes ────────────────────────────────────────────────────────

    #[Test]
    public function clinic_notes_for_the_learner_are_rendered_with_author_and_follow_up(): void
    {
        $this->learner();
        $this->note('LRN001', 'Reports occasional wheezing during PE.', null, now()->addWeek()->toDateString());

        $html = $this->profile();

        $this->assertStringContainsString('Reports occasional wheezing during PE.', $html);
        $this->assertStringContainsString('Ana Reyes, RN', $html);
        $this->assertStringContainsString(now()->addWeek()->format('M j, Y'), $html);
    }

    #[Test]
    public function clinic_notes_are_listed_newest_first(): void
    {
        $this->learner();
        $older = $this->note('LRN001', 'Older observation');
        $older->forceFill(['created_at' => now()->subWeek()])->saveQuietly();
        $this->note('LRN001', 'Newest observation');

        $html = $this->profile();

        $this->assertLessThan(
            strpos($html, 'Older observation'),
            strpos($html, 'Newest observation'),
            'The newest clinic note must be rendered first.'
        );
    }

    #[Test]
    public function another_learners_clinic_notes_never_appear(): void
    {
        $this->learner('LRN001');
        $this->learner('LRN002', 'Reyes, Maria');
        $this->note('LRN002', 'Note about a different learner');

        $this->assertStringNotContainsString('Note about a different learner', $this->profile('LRN001'));
    }

    #[Test]
    public function another_schools_clinic_notes_never_appear(): void
    {
        $this->learner('LRN001');
        // Same LRN, different school — only this school's note may be read.
        $this->learner('LRN001', 'Dela Cruz, Juan', $this->otherSchool);
        $this->note('LRN001', 'Note held by another school', $this->otherSchool);

        $this->assertStringNotContainsString('Note held by another school', $this->profile('LRN001'));
    }

    // ── consultation log ────────────────────────────────────────────────────

    #[Test]
    public function consultations_for_the_learner_are_rendered_with_what_the_clinic_records(): void
    {
        $this->learner();
        $this->consultation('Dela Cruz, Juan');

        $html = $this->profile();

        $this->assertStringContainsString('Asthma', $html);
        $this->assertStringContainsString('Salbutamol inhaler administered', $html);
        // status is stored lowercase and shown as the clinic log labels it.
        $this->assertStringContainsString('Referred', $html);
    }

    #[Test]
    public function another_learners_consultations_never_appear(): void
    {
        $this->learner('LRN001', 'Dela Cruz, Juan');
        $this->learner('LRN002', 'Reyes, Maria');
        $this->consultation('Reyes, Maria', ['condition' => 'Migraine headache']);

        $this->assertStringNotContainsString('Migraine headache', $this->profile('LRN001'));
    }

    #[Test]
    public function another_schools_consultations_never_appear(): void
    {
        $this->learner('LRN001', 'Dela Cruz, Juan');
        $this->consultation('Dela Cruz, Juan', ['condition' => 'Consultation at another school'], $this->otherSchool);

        $this->assertStringNotContainsString('Consultation at another school', $this->profile('LRN001'));
    }

    // ── read-only ───────────────────────────────────────────────────────────

    #[Test]
    public function neither_panel_offers_any_way_to_write(): void
    {
        $this->learner();
        $this->note('LRN001', 'Observed and advised rest.');
        $this->consultation('Dela Cruz, Juan');

        $html = $this->profile();

        // Exactly the two clinic panels — Medical Documents follows them and is
        // the adviser's own to fill, so its uploader must stay out of the slice.
        $start = strpos($html, 'id="vpTabNotes"');
        $end = strpos($html, 'id="vpTabDocuments"', $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $panels = substr($html, $start, $end - $start);

        foreach (['<form', '<input', '<select', '<textarea', '<button'] as $editable) {
            $this->assertStringNotContainsString(
                $editable,
                $panels,
                "The read-only clinic panels must not contain {$editable}."
            );
        }

        $this->assertStringContainsString('<b>Read-Only:</b>', $panels);
    }

    #[Test]
    public function the_adviser_still_cannot_reach_the_clinic_write_endpoints(): void
    {
        $this->learner();

        $this->withSession($this->adviserSession())
            ->postJson(route('api.student-clinic-notes.store'), ['lrn' => 'LRN001', 'note' => 'nope'])
            ->assertForbidden();

        $this->withSession($this->adviserSession())
            ->getJson(route('api.student-clinic-notes', ['lrn' => 'LRN001']))
            ->assertForbidden();

        $this->assertSame(0, ClinicNote::count());
    }
}
