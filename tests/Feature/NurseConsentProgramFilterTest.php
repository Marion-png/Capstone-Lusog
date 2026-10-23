<?php

namespace Tests\Feature;

use App\Models\HealthConsentForm;
use App\Models\Institution;
use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The nurse's consent list answers "who may I give this program to?".
 *
 * Every row names the programs the parent agreed to, and a Program filter
 * narrows the list to the learners whose parent was asked about one program,
 * with the Consent badge answering for that program. A "yes, except" is never
 * settled by the page: what the parent left out is handwritten, so it is
 * shown as written and the nurse is told to check it.
 */
class NurseConsentProgramFilterTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    private function nurseSession(): array
    {
        return [
            'active_role' => 'school_nurse',
            'active_name' => 'Nurse Reyes',
            'active_institution_id' => $this->institution->id,
        ];
    }

    /** @param  list<string>  $services */
    private function letter(string $lrn, string $name, string $choice, array $services, string $exceptions = ''): HealthConsentForm
    {
        return HealthConsentForm::create([
            'institution_id' => $this->institution->id,
            'student_lrn' => $lrn,
            'school_year' => HealthConsentForm::currentSchoolYear(),
            'student_name' => $name,
            'grade_level' => 'Grade 7',
            'section' => 'Sampaguita',
            'division' => HealthConsentForm::DEFAULT_DIVISION,
            'school_name' => 'Test School',
            'school_address' => HealthConsentForm::DEFAULT_SCHOOL_ADDRESS,
            'status' => HealthConsentForm::STATUS_SIGNED,
            'consent_choice' => $choice,
            'consent_exceptions' => $exceptions !== '' ? $exceptions : null,
            'refusal_reason' => $choice === HealthConsentForm::CONSENT_DENY ? 'Relihiyon' : null,
            'services' => $services,
            'signed_at' => now(),
        ]);
    }

    private function list(array $query = []): string
    {
        return $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-index', $query))
            ->assertOk()
            ->getContent();
    }

    #[Test]
    public function every_row_names_the_programs_the_parent_agreed_to(): void
    {
        $this->letter('111111111111', 'Abad, Ana', HealthConsentForm::CONSENT_ALL, ['checkup', 'deworming', 'deworming_worms', 'vitamin_a']);
        $this->letter('222222222222', 'Bautista, Ben', HealthConsentForm::CONSENT_SPECIFIC, ['checkup', 'dental'], 'Dental');
        $this->letter('333333333333', 'Cruz, Carla', HealthConsentForm::CONSENT_DENY, ['checkup']);

        $html = $this->list();

        $this->assertStringContainsString('Programs consented', $html);
        // A sub-item reads under its heading, in the letter's own order.
        $this->assertStringContainsString('Check-up · Deworming (worms) · Vitamin A', $html);
        // "Yes, except" lists what was asked and the parent's words, as written.
        $this->assertStringContainsString('Check-up · Dental check-up', $html);
        $this->assertStringContainsString('Except, as written: &ldquo;Dental&rdquo;', $html);
        // A refusal consents to nothing.
        $this->assertMatchesRegularExpression('#<td class="cf-programs">\s*None\s*</td>#', $html);
    }

    #[Test]
    public function the_program_filter_lists_only_the_letters_that_asked_about_it(): void
    {
        $this->letter('111111111111', 'Abad, Ana', HealthConsentForm::CONSENT_ALL, ['deworming']);
        $this->letter('222222222222', 'Bautista, Ben', HealthConsentForm::CONSENT_ALL, ['vitamin_a']);

        $html = $this->list(['program' => 'deworming']);

        $this->assertStringContainsString('111111111111', $html);
        $this->assertStringNotContainsString('222222222222', $html);
        $this->assertStringContainsString('<option value="deworming" selected>Deworming (1)</option>', $html);
        $this->assertStringContainsString('Consent to Deworming', $html);
    }

    #[Test]
    public function the_badge_answers_for_the_chosen_program_and_never_settles_an_exception(): void
    {
        $this->letter('111111111111', 'Abad, Ana', HealthConsentForm::CONSENT_ALL, ['dental']);
        $this->letter('222222222222', 'Bautista, Ben', HealthConsentForm::CONSENT_SPECIFIC, ['dental', 'checkup'], 'ngipon');
        $this->letter('333333333333', 'Cruz, Carla', HealthConsentForm::CONSENT_DENY, ['dental']);

        $html = $this->list(['program' => 'dental']);

        $this->assertStringContainsString('>Consented</span>', $html);
        $this->assertStringContainsString('>Check the exceptions</span>', $html);
        $this->assertStringContainsString('Except, as written: &ldquo;ngipon&rdquo;', $html);
        $this->assertStringContainsString('>Did not consent</span>', $html);
    }

    #[Test]
    public function a_heading_and_its_sub_items_find_each_other(): void
    {
        $this->letter('111111111111', 'Abad, Ana', HealthConsentForm::CONSENT_ALL, ['deworming_worms']);
        $this->letter('222222222222', 'Bautista, Ben', HealthConsentForm::CONSENT_ALL, ['immunization']);

        // A sub-item on the letter means its heading is too.
        $this->assertStringContainsString('111111111111', $this->list(['program' => 'deworming']));
        // A heading on the letter covers every sub-item under it.
        $this->assertStringContainsString('222222222222', $this->list(['program' => 'immunization_grade7']));
        // And a sub-item never reaches a sibling it was not asked about.
        $this->assertStringNotContainsString('111111111111', $this->list(['program' => 'deworming_filariasis']));
    }

    #[Test]
    public function the_program_and_the_answer_filters_combine(): void
    {
        $this->letter('111111111111', 'Abad, Ana', HealthConsentForm::CONSENT_ALL, ['deworming']);
        $this->letter('222222222222', 'Bautista, Ben', HealthConsentForm::CONSENT_DENY, ['deworming']);
        $this->letter('333333333333', 'Cruz, Carla', HealthConsentForm::CONSENT_DENY, ['vitamin_a']);

        $html = $this->list(['program' => 'deworming', 'consent' => HealthConsentForm::CONSENT_DENY]);

        $this->assertStringContainsString('222222222222', $html);
        $this->assertStringNotContainsString('111111111111', $html);
        $this->assertStringNotContainsString('333333333333', $html);
        // The answer filter's total counts the program's letters, not the school's.
        $this->assertStringContainsString('All answers (2)', $html);
    }

    #[Test]
    public function an_uploaded_scan_reads_as_the_program_it_was_filed_for(): void
    {
        $record = StudentHealthRecord::create([
            'school_year' => HealthConsentForm::currentSchoolYear(),
            'institution_id' => $this->institution->id,
            'student_id' => '444444444444',
            'student_name' => 'Diaz, Dan',
            'student_details' => ['last_name' => 'Diaz', 'first_name' => 'Dan'],
            'school_name' => 'Test School',
            'section' => 'Grade 7 / Sampaguita',
        ]);

        ParentalConsentForm::create([
            'student_health_record_id' => $record->id,
            'program_type' => 'Deworming',
            'school_year' => HealthConsentForm::currentSchoolYear(),
            'consent_type' => 'full',
            'file_path' => 'parental-consents/'.$record->id.'/scan.pdf',
            'uploaded_by_name' => 'Test Adviser',
        ]);

        $html = $this->list(['program' => 'deworming']);

        // The table's own cell: the topbar's learner search carries every
        // learner's LRN whatever the list shows.
        $this->assertStringContainsString('LRN 444444444444', $html);
        $this->assertMatchesRegularExpression('#<td class="cf-programs">\s*Deworming\s*</td>#', $html);
        $this->assertStringNotContainsString('LRN 444444444444', $this->list(['program' => 'vitamin_a']));
    }

    #[Test]
    public function an_unknown_program_narrows_nothing(): void
    {
        $this->letter('111111111111', 'Abad, Ana', HealthConsentForm::CONSENT_ALL, ['deworming']);

        $html = $this->list(['program' => 'not-a-program']);

        $this->assertStringContainsString('111111111111', $html);
        $this->assertStringContainsString('All Consents on File', $html);
    }
}
