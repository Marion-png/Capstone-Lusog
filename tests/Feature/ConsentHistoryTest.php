<?php

namespace Tests\Feature;

use App\Models\HealthConsentForm;
use App\Models\Institution;
use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A consent outlives the school year it was given in.
 *
 * Nothing deletes a consent form, its status or the signed scan filed with it
 * when a school year ends — only the retention purge does, years after the
 * learner has left. But a page that reads the current year alone hides every
 * one of them on the first of June, which to the person looking is the same as
 * losing them. So the adviser's and the clinic's consent lists both take a
 * school year, an earlier year reads exactly as it was left, and nothing on it
 * can be uploaded or changed.
 */
class ConsentHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    private function adviserSession(): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Test Adviser',
            'active_username' => 'adviser1',
            'active_institution_id' => $this->institution->id,
            'active_school_name' => 'Test School',
            'assigned_school_name' => 'Test School',
            'assigned_grade_level' => 'Grade 1',
            'assigned_section' => 'Sampaguita',
        ];
    }

    private function nurseSession(): array
    {
        return [
            'active_role' => 'school_nurse',
            'active_name' => 'Nurse Reyes',
            'active_institution_id' => $this->institution->id,
        ];
    }

    private function lastSchoolYear(): string
    {
        [$start] = explode('-', HealthConsentForm::currentSchoolYear());

        return ((int) $start - 1).'-'.$start;
    }

    /** One learner on one year's roll, in the given class. */
    private function record(string $lrn, string $schoolYear, string $section = 'Grade 1 / Sampaguita', string $lastName = 'Dela Cruz'): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'school_year' => $schoolYear,
            'institution_id' => $this->institution->id,
            'student_id' => $lrn,
            'student_name' => $lastName.', Juan A.',
            'student_details' => ['last_name' => $lastName, 'first_name' => 'Juan', 'middle_name' => 'A', 'parent_guardian' => 'Maria '.$lastName],
            'school_name' => 'Test School',
            'section' => $section,
            'weight' => 18.5,
            'bmi_value' => 15.3,
            'nutritional_status' => 'Normal',
        ]);
    }

    private function letter(string $lrn, string $schoolYear, string $choice): HealthConsentForm
    {
        return HealthConsentForm::create([
            'institution_id' => $this->institution->id,
            'student_lrn' => $lrn,
            'school_year' => $schoolYear,
            'student_name' => 'Dela Cruz, Juan A.',
            'grade_level' => 'Grade 1',
            'section' => 'Sampaguita',
            'division' => HealthConsentForm::DEFAULT_DIVISION,
            'school_name' => 'Test School',
            'school_address' => HealthConsentForm::DEFAULT_SCHOOL_ADDRESS,
            'status' => HealthConsentForm::STATUS_SIGNED,
            'consent_choice' => $choice,
            'services' => ['deworming'],
            'signed_at' => now(),
        ]);
    }

    /**
     * The whole life of one consent: filed during a school year by the
     * adviser's own upload, then read back after that year has ended.
     */
    #[Test]
    public function a_consent_filed_last_year_is_still_on_the_advisers_page(): void
    {
        $this->travelTo(now()->subYear());

        $this->withSession($this->adviserSession())
            ->post(route('adviser.store'), [
                'last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_name' => 'A',
                'lrn' => '123456789012', 'birth_date' => '2015-06-01', 'birthplace' => 'Davao City',
                'parent_guardian' => 'Maria Dela Cruz', 'address' => '123 Mabini St.',
                'region' => 'XI', 'division' => 'Davao City',
                'telephone_no' => '09171234567', 'gender' => 'Male',
                'height_cm' => 110, 'weight_kg' => 18.5,
                'grade_level' => 'Grade 1', 'section' => 'Sampaguita',
            ])->assertRedirect();

        $this->flushSession()->withSession($this->adviserSession())
            ->post(route('parental-consent.store'), [
                'lrn' => '123456789012',
                'consent_type' => 'full',
                'consent' => UploadedFile::fake()->create('sulat-pahibalo-2025.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $this->travelBack();

        $last = $this->lastSchoolYear();
        $upload = ParentalConsentForm::firstOrFail();
        $this->assertSame($last, $upload->school_year);

        // The year has ended and nothing was deleted: the row and its scan.
        Storage::disk('local')->assertExists($upload->file_path);

        // The current year's list does not hold them — the learner is not on
        // this year's roll — but it offers the year they are in.
        $this->flushSession()->withSession($this->adviserSession())
            ->get(route('consent-forms.index'))
            ->assertOk()
            ->assertDontSee('123456789012')
            ->assertSee('<option value="'.$last.'"', false)
            ->assertDontSee('id="pastYearNotice"', false);

        $html = $this->flushSession()->withSession($this->adviserSession())
            ->get(route('consent-forms.index', ['school_year' => $last]))
            ->assertOk()
            ->assertSee('School Year '.$last)
            ->assertSee('123456789012')
            ->assertSee('data-status="approved"', false)
            ->assertSee('sulat-pahibalo-2025.pdf')
            ->assertSee('id="pastYearNotice"', false)
            ->getContent();

        // Read-only: nothing that would file a new consent is on the page.
        $this->assertStringNotContainsString('id="openUploadConsent"', $html);
        $this->assertStringNotContainsString('id="uploadConsentModal"', $html);
        $this->assertStringNotContainsString('title="Upload Form"', $html);

        // And the scan itself still opens, through the audited download.
        $this->assertStringContainsString(
            json_encode(route('parental-consent.download', $upload->id)),
            $html
        );
        $this->flushSession()->withSession($this->adviserSession())
            ->get(route('parental-consent.download', $upload->id))
            ->assertOk();
    }

    /**
     * An earlier year is narrowed by the same rule as this one: the class the
     * record was in that year, against the adviser's assignment.
     */
    #[Test]
    public function an_earlier_year_lists_only_the_advisers_class(): void
    {
        $last = $this->lastSchoolYear();
        $this->record('111111111111', $last, 'Grade 1 / Sampaguita', 'Mine');
        $this->record('222222222222', $last, 'Grade 1 / Rosal', 'Theirs');

        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        StudentHealthRecord::create([
            'school_year' => $last,
            'institution_id' => $other->id,
            'student_id' => '333333333333',
            'student_name' => 'Elsewhere, Ana',
            'school_name' => 'Other School',
            'section' => 'Grade 1 / Sampaguita',
            'nutritional_status' => 'Normal',
        ]);

        $this->withSession($this->adviserSession())
            ->get(route('consent-forms.index', ['school_year' => $last]))
            ->assertOk()
            ->assertSee('111111111111')
            ->assertDontSee('222222222222')
            ->assertDontSee('333333333333');
    }

    /** A year nobody has records for is not a year, and narrows nothing. */
    #[Test]
    public function an_unknown_school_year_falls_back_to_the_current_one(): void
    {
        $this->withSession($this->adviserSession())
            ->get(route('consent-forms.index', ['school_year' => '1999-2000']))
            ->assertOk()
            ->assertSee('School Year '.HealthConsentForm::currentSchoolYear())
            ->assertDontSee('id="pastYearNotice"', false)
            ->assertSee('id="openUploadConsent"', false);

        $this->flushSession()->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-index', ['school_year' => '1999-2000']))
            ->assertOk()
            ->assertSee('S.Y. '.HealthConsentForm::currentSchoolYear())
            ->assertDontSee('id="pastYearNotice"', false);
    }

    /**
     * The clinic reads last year's letters and scans after the year ends — the
     * letter opens, and its back link returns to the year it came from.
     */
    #[Test]
    public function the_nurse_reads_an_earlier_years_consents(): void
    {
        $last = $this->lastSchoolYear();

        $this->letter('111111111111', $last, HealthConsentForm::CONSENT_ALL);
        $this->record('111111111111', $last);

        $scanned = $this->record('222222222222', $last, 'Grade 1 / Sampaguita', 'Santos');
        $upload = ParentalConsentForm::create([
            'student_health_record_id' => $scanned->id,
            'program_type' => 'Deworming',
            'school_year' => $last,
            'consent_type' => 'refused',
            'file_path' => 'parental-consents/'.$scanned->id.'/scan.pdf',
            'uploaded_by_name' => 'Test Adviser',
        ]);

        // This year's list does not carry them.
        $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-index'))
            ->assertOk()
            ->assertDontSee('111111111111')
            ->assertDontSee('222222222222')
            ->assertSee('<option value="'.$last.'"', false);

        // Last year's list carries both, by masked LRN — the full one never
        // reaches the page.
        $this->flushSession()->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-index', ['school_year' => $last]))
            ->assertOk()
            ->assertSee('id="pastYearNotice"', false)
            ->assertSee('•••••••11111')
            ->assertSee('>Consented</span>', false)
            ->assertSee('•••••••22222')
            ->assertSee('>Declined</span>', false)
            ->assertDontSee('111111111111')
            ->assertDontSee('222222222222');

        // The scan opens from the learner's own page, in that year.
        $this->flushSession()->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-learner', $scanned))
            ->assertOk()
            ->assertSee(e(route('parental-consent.download', $upload->id)), false)
            ->assertSee(e(route('consent-forms.nurse-index', ['school_year' => $last])), false);

        // Clear filters goes back to every default, the current year included.
        $this->flushSession()->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-index', ['school_year' => $last, 'status' => 'consented']))
            ->assertOk()
            ->assertSee('href="'.e(route('consent-forms.nurse-index')).'" class="cf-filter-clear"', false);

        // The letter opens, and leads back to the year it belongs to.
        $form = HealthConsentForm::where('student_lrn', '111111111111')->firstOrFail();
        $this->flushSession()->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-show', $form))
            ->assertOk()
            ->assertSee(e(route('consent-forms.nurse-index', ['school_year' => $last])), false);

        // The School Head is sent back to their own tab, in that year.
        $this->flushSession()->withSession([
            'active_role' => 'school_head',
            'active_name' => 'Head',
            'active_institution_id' => $this->institution->id,
        ])
            ->get(route('consent-forms.head-show', $form))
            ->assertOk()
            ->assertSee(e(route('dashboard.school-head.consent', ['school_year' => $last])), false);
    }

    /**
     * A consent answers for the year it was given in, so one learner's two
     * years are two rows on two lists — never one row whose year is chance.
     */
    #[Test]
    public function the_nurses_list_never_mixes_two_years_answers(): void
    {
        $last = $this->lastSchoolYear();
        $current = HealthConsentForm::currentSchoolYear();

        $this->record('111111111111', $last);
        $this->record('111111111111', $current);
        $this->letter('111111111111', $last, HealthConsentForm::CONSENT_DENY);
        $this->letter('111111111111', $current, HealthConsentForm::CONSENT_ALL);

        $this->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-index'))
            ->assertOk()
            ->assertSee('>Consented</span>', false)
            ->assertDontSee('>Declined</span>', false);

        $this->flushSession()->withSession($this->nurseSession())
            ->get(route('consent-forms.nurse-index', ['school_year' => $last]))
            ->assertOk()
            ->assertSee('>Declined</span>', false)
            ->assertDontSee('>Consented</span>', false);
    }
}
