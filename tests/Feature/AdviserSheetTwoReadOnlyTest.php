<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sheet 2 — the systems review — is written once, at enrolment.
 *
 * Opening a learner from their profile is reading their record, not
 * re-examining them. The sheet renders read-only for a learner already on
 * file, and the server keeps the stored review whatever the form posts: a
 * disabled control is only a suggestion, and a stale tab, a replayed form
 * or devtools all reach the endpoint the same way.
 *
 * That also closes a quieter hole. The roster row the browser fills the
 * edit form from is a copy, and it carries no signature image — so every
 * edit used to round-trip a clinical finding through the browser and write
 * back whatever came home.
 *
 * A NEW learner still gets a writable Sheet 2, or the field would have no
 * writer at all.
 */
class AdviserSheetTwoReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function adviserSession(): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Maria Santos',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
            'assigned_grade_level' => 'Grade 10',
            'assigned_section' => 'Dalton',
            'school_health_card_records' => [],
        ];
    }

    /** A learner whose systems review is already on file. */
    private function enrolledLearner(): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'student_id' => '800000000001',
            'student_name' => 'Cruz, Juan',
            'school_name' => 'Sta. Ana NHS',
            'grade_level' => 'Grade 10',
            'section' => 'Grade 10 / Dalton',
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'weight' => '40',
            'bmi_value' => '18',
            'nutritional_status' => 'Normal',
            'student_details' => [
                'lrn' => '800000000001',
                'last_name' => 'Cruz',
                'first_name' => 'Juan',
                'birth_year' => 2010, 'birth_month' => 5, 'birth_day' => 14,
                'birthplace' => 'Davao City',
                'gender' => 'Male',
                'parent_guardian' => 'Maria Cruz',
                'address' => '12 Rizal St.',
                'telephone_no' => '09171234567',
                'height_cm' => 150,
                'weight_kg' => 40,
                'grade_level' => 'Grade 10',
                'section' => 'Dalton',
                'systems_review' => [
                    'skin_lesions' => true,
                    'dental_caries' => true,
                    'dental_referral' => true,
                    'notes' => 'Referred to the district dentist.',
                    'examiner_name' => 'Nurse Reyes, RN',
                ],
            ],
        ]);
    }

    /** The fields a valid save needs, so only Sheet 2 is under test. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'last_name' => 'Cruz',
            'first_name' => 'Juan',
            'lrn' => '800000000001',
            'birth_month' => 5, 'birth_day' => 14, 'birth_year' => 2010,
            'birthplace' => 'Davao City',
            'parent_guardian' => 'Maria Cruz',
            'address' => '12 Rizal St.',
            'telephone_no' => '09171234567',
            'gender' => 'Male',
            'height_cm' => 150,
            'weight_kg' => 41,
            'grade_level' => 'Grade 10',
            'section' => 'Dalton',
        ], $overrides);
    }

    private function dashboard(): string
    {
        return $this->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser'))
            ->assertOk()
            ->getContent();
    }

    #[Test]
    public function the_sheet_can_be_locked_and_says_so(): void
    {
        $html = $this->dashboard();

        $this->assertStringContainsString('id="sheet2Fieldset"', $html);
        $this->assertStringContainsString('id="sheet2ReadonlyNote"', $html);
        $this->assertStringContainsString("This learner's systems review was recorded when they were enrolled.", $html);
    }

    /** Edit mode locks it; enrolling a new learner does not. */
    #[Test]
    public function edit_mode_locks_the_sheet_and_enrolment_does_not(): void
    {
        $html = $this->dashboard();

        $this->assertStringContainsString('sheet2.disabled = editing;', $html);
        $this->assertStringContainsString('sheet2Note.hidden = !editing;', $html);
    }

    /**
     * The pad is a canvas and the upload area a div — neither is a form
     * control, so the disabled fieldset never reaches them.
     */
    #[Test]
    public function the_signature_pad_is_locked_too(): void
    {
        $html = $this->dashboard();

        $this->assertStringContainsString('window.setSignaturePadEnabled = (enabled) =>', $html);
        $this->assertStringContainsString('if (!padEnabled || !ensureCanvas())', $html);
        $this->assertStringContainsString('if (padEnabled) fileInput?.click();', $html);
    }

    /** Editing a learner leaves the review exactly as it stands. */
    #[Test]
    public function an_edit_keeps_the_stored_systems_review(): void
    {
        $record = $this->enrolledLearner();

        $this->withSession($this->adviserSession())
            ->post(route('adviser.store'), $this->payload())
            ->assertRedirect();

        $review = $record->fresh()->student_details['systems_review'];

        $this->assertTrue((bool) $review['skin_lesions']);
        $this->assertTrue((bool) $review['dental_caries']);
        $this->assertSame('Referred to the district dentist.', $review['notes']);
        $this->assertSame('Nurse Reyes, RN', $review['examiner_name']);
    }

    /**
     * And a posted one is ignored — the sheet is disabled, but the endpoint
     * is what has to refuse.
     */
    #[Test]
    public function a_posted_systems_review_cannot_overwrite_the_stored_one(): void
    {
        $record = $this->enrolledLearner();

        $this->withSession($this->adviserSession())
            ->post(route('adviser.store'), $this->payload([
                'systems_review' => [
                    'skin_normal' => '1',
                    'dental_good' => '1',
                    'notes' => 'Nothing to report.',
                    'examiner_name' => 'Someone Else',
                ],
            ]))
            ->assertRedirect();

        $review = $record->fresh()->student_details['systems_review'];

        // The finding survives…
        $this->assertTrue((bool) $review['skin_lesions']);
        $this->assertTrue((bool) $review['dental_caries']);
        $this->assertSame('Referred to the district dentist.', $review['notes']);
        $this->assertSame('Nurse Reyes, RN', $review['examiner_name']);

        // …and the posted version never lands.
        $this->assertFalse((bool) ($review['skin_normal'] ?? false));
        $this->assertFalse((bool) ($review['dental_good'] ?? false));
    }

    /** The rest of the edit still saves — only Sheet 2 is frozen. */
    #[Test]
    public function the_rest_of_the_form_still_saves_on_an_edit(): void
    {
        $record = $this->enrolledLearner();

        $this->withSession($this->adviserSession())
            ->post(route('adviser.store'), $this->payload([
                'weight_kg' => 44,
                'address' => '99 Bonifacio Ave.',
            ]))
            ->assertRedirect();

        $fresh = $record->fresh();

        $this->assertEqualsWithDelta(44, (float) $fresh->weight, 0.01);
        $this->assertSame('99 Bonifacio Ave.', $fresh->student_details['address']);
    }

    /**
     * A NEW learner still gets a writable Sheet 2. Freezing it everywhere
     * would leave the field with no writer at all.
     */
    #[Test]
    public function a_new_learner_still_records_a_systems_review(): void
    {
        $this->withSession($this->adviserSession())
            ->post(route('adviser.store'), $this->payload([
                'lrn' => '800000000009',
                'systems_review' => [
                    'skin_lesions' => '1',
                    'notes' => 'Rash on the left forearm.',
                    'examiner_name' => 'Nurse Reyes, RN',
                ],
            ]))
            ->assertRedirect();

        $record = StudentHealthRecord::where('student_id', '800000000009')->first();

        $this->assertNotNull($record);
        $review = $record->student_details['systems_review'];

        $this->assertTrue((bool) $review['skin_lesions']);
        $this->assertSame('Rash on the left forearm.', $review['notes']);
    }

    /** The signature on file survives an edit, as it always did. */
    #[Test]
    public function the_stored_signature_survives_an_edit(): void
    {
        $record = $this->enrolledLearner();

        $details = $record->student_details;
        $details['systems_review']['examiner_signature'] = 'data:image/png;base64,AAAA';
        $record->forceFill(['student_details' => $details])->save();

        $this->withSession($this->adviserSession())
            ->post(route('adviser.store'), $this->payload())
            ->assertRedirect();

        $this->assertSame(
            'data:image/png;base64,AAAA',
            $record->fresh()->student_details['systems_review']['examiner_signature']
        );
    }

    /** The full-page profile shows the review and offers no way to edit it. */
    #[Test]
    public function the_student_profile_page_only_reads_the_review(): void
    {
        $this->enrolledLearner();

        $html = $this->withSession(array_merge($this->adviserSession(), [
            'school_health_card_records' => [[
                'lrn' => '800000000001',
                'first_name' => 'Juan',
                'last_name' => 'Cruz',
                'grade_level' => 'Grade 10',
                'section' => 'Dalton',
            ]],
        ]))->get(route('dashboard.class-adviser.student-profile', '800000000001'))
            ->assertOk()
            ->getContent();

        $start = strpos($html, 'id="vpTabSheet2"');
        $this->assertNotFalse($start);
        $panel = substr($html, $start, (int) strpos($html, '</div>', $start) - $start);

        $this->assertStringNotContainsString('<input', $panel);
        $this->assertStringNotContainsString('<textarea', $panel);
        $this->assertStringNotContainsString('name="systems_review', $panel);
    }
}
