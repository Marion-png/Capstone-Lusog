<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;
use App\Support\ConsentStanding;
use App\Support\FeedingConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A measurement never puts a learner on the feeding line.
 *
 * The class adviser's weighing decides whether a learner *qualifies*; the
 * Feeding Coordinator enrols — and only a learner whose parent or guardian has
 * signed the School-Based Feeding Program consent, which the adviser files
 * through the same upload dialog as the Sulat-Pahibalo, as its own kind of
 * form. The two forms answer different questions and are never read as each
 * other.
 */
class FeedingConsentTest extends TestCase
{
    use RefreshDatabase;

    private const CANDIDATES = '/dashboard/feedingcor-program/enrollment/candidates';

    private const ENROLL = '/dashboard/feedingcor-program/enrollment';

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function coordinatorSession(): array
    {
        return [
            'active_role' => 'feeding_coor',
            'active_name' => 'Test Coordinator',
            'active_institution_id' => $this->school->id,
        ];
    }

    private function adviserSession(): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Maria Santos',
            'active_username' => 'adviser1',
            'active_school_name' => $this->school->name,
            'active_institution_id' => $this->school->id,
            'assigned_grade_level' => 'Grade 7',
            'assigned_section' => 'Sampaguita',
        ];
    }

    /** Measured as wasted by the adviser: qualified, not enrolled. */
    private function qualified(string $lrn = '100000000001', string $name = 'Cruz, Juan'): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => $name,
            'student_id' => $lrn,
            'school_name' => $this->school->name,
            'section' => 'Grade 7 / Sampaguita',
            'weight' => 25,
            'bmi_value' => 14.9,
            'nutritional_status' => 'Wasted',
            'baseline_nutritional_status' => 'Wasted',
            'student_details' => ['gender' => 'Female'],
        ]);
    }

    private function enrol(StudentHealthRecord ...$records)
    {
        return $this->withSession($this->coordinatorSession())
            ->postJson(self::ENROLL, ['record_ids' => array_map(fn ($r) => $r->id, $records)]);
    }

    // ── The gate ────────────────────────────────────────────────────────

    #[Test]
    public function a_qualified_learner_is_not_enrolled_until_their_parent_consents(): void
    {
        $learner = $this->qualified();

        $this->assertNull($learner->fresh()->feeding_enrolled_at, 'Measuring a learner never enrols them.');

        $row = $this->withSession($this->coordinatorSession())->getJson(self::CANDIDATES)->assertOk()->json('rows.0');
        $this->assertSame(FeedingConsent::NONE, $row['consent']);
        $this->assertSame('Waiting for parent\'s consent', $row['consent_label']);
        $this->assertFalse($row['can_enroll']);

        $this->enrol($learner)
            ->assertStatus(422)
            ->assertJsonPath('without_consent', 1);
        $this->assertNull($learner->fresh()->feeding_enrolled_at);

        $this->fileFeedingConsent($learner);

        $this->assertTrue($this->withSession($this->coordinatorSession())->getJson(self::CANDIDATES)->json('rows.0.can_enroll'));
        $this->enrol($learner)->assertOk()->assertJsonPath('enrolled_now', 1);
        $this->assertNotNull($learner->fresh()->feeding_enrolled_at);
    }

    #[Test]
    public function a_parent_who_declined_blocks_enrolment(): void
    {
        $learner = $this->qualified();
        $this->fileFeedingConsent($learner, 'refused');

        $this->assertSame('declined', $this->withSession($this->coordinatorSession())->getJson(self::CANDIDATES)->json('rows.0.consent'));
        $this->enrol($learner)->assertStatus(422);
        $this->assertNull($learner->fresh()->feeding_enrolled_at);
    }

    #[Test]
    public function an_answer_with_no_signed_form_authorises_nothing(): void
    {
        $learner = $this->qualified();
        $this->fileFeedingConsent($learner)->forceFill(['file_path' => null])->save();

        $this->enrol($learner)->assertStatus(422);
        $this->assertNull($learner->fresh()->feeding_enrolled_at);
    }

    #[Test]
    public function last_years_consent_does_not_cover_this_year(): void
    {
        $learner = $this->qualified();
        $this->fileFeedingConsent($learner)->forceFill(['school_year' => '1999-2000'])->save();

        $this->assertSame(FeedingConsent::NONE, FeedingConsent::forRecord($learner));
        $this->enrol($learner)->assertStatus(422);
    }

    #[Test]
    public function a_bulk_enrolment_takes_only_the_learners_with_consent(): void
    {
        $consented = $this->qualified('100000000001', 'Cruz, Juan');
        $waiting = $this->qualified('100000000002', 'Reyes, Ana');
        $this->fileFeedingConsent($consented);

        $this->enrol($consented, $waiting)
            ->assertOk()
            ->assertJsonPath('enrolled_now', 1)
            ->assertJsonPath('without_consent', 1)
            ->assertJsonPath('message', '1 learner was not enrolled: no parent\'s consent to the feeding program is on file.');

        $this->assertNotNull($consented->fresh()->feeding_enrolled_at);
        $this->assertNull($waiting->fresh()->feeding_enrolled_at);
    }

    // ── Two forms, never read as each other ─────────────────────────────

    #[Test]
    public function the_health_services_consent_is_not_a_feeding_consent(): void
    {
        $learner = $this->qualified();

        ParentalConsentForm::create([
            'student_health_record_id' => $learner->id,
            'program_type' => ParentalConsentForm::PROGRAM_HEALTH_SERVICES,
            'school_year' => (string) $learner->school_year,
            'consent_type' => 'full',
            'file_path' => 'parental-consents/'.$learner->id.'/sulat.jpg',
            'uploaded_by_name' => 'Maria Santos',
        ]);

        $this->assertSame(FeedingConsent::NONE, FeedingConsent::forRecord($learner));
        $this->enrol($learner)->assertStatus(422);
    }

    #[Test]
    public function a_feeding_consent_is_not_a_health_services_consent(): void
    {
        $learner = $this->qualified();
        $this->fileFeedingConsent($learner);

        // The clinic's consent list still has no Sulat-Pahibalo for this learner.
        $this->withSession([
            'active_role' => 'school_nurse',
            'active_name' => 'Nurse Reyes',
            'active_institution_id' => $this->school->id,
        ])->get(route('consent-forms.nurse-index'))
            ->assertOk()
            ->assertSee('>No form yet</span>', false)
            ->assertDontSee('>Consented</span>', false);

        // Nor does the adviser's own consent standing.
        $this->assertSame(ConsentStanding::PENDING, ConsentStanding::for(null, ParentalConsentForm::healthServices()->first()));
    }

    // ── The adviser files it ────────────────────────────────────────────

    #[Test]
    public function the_adviser_files_the_feeding_consent_from_the_upload_dialog(): void
    {
        $learner = $this->qualified();

        $this->withSession($this->adviserSession())
            ->get(route('consent-forms.index'))
            ->assertOk()
            ->assertSee('Which form is this?')
            ->assertSee('School-Based Feeding Program consent');

        $this->withSession($this->adviserSession())
            ->from(route('consent-forms.index'))
            ->post(route('parental-consent.store'), [
                'lrn' => '100000000001',
                'program' => ParentalConsentForm::PROGRAM_FEEDING,
                'consent_type' => 'full',
                'consent' => UploadedFile::fake()->create('sbfp.jpg', 120, 'image/jpeg'),
            ])
            ->assertRedirect()
            ->assertSessionHas('consent_success');

        $form = ParentalConsentForm::latest('id')->firstOrFail();
        $this->assertSame(ParentalConsentForm::PROGRAM_FEEDING, $form->program_type);
        $this->assertSame(FeedingConsent::CONSENTED, FeedingConsent::forRecord($learner));

        $this->enrol($learner)->assertOk();
    }

    #[Test]
    public function the_feeding_consent_needs_the_signed_form_and_a_yes_or_no(): void
    {
        $this->qualified();

        foreach ([
            'no signed form' => ['consent_type' => 'full'],
            'no answer' => ['consent' => UploadedFile::fake()->create('sbfp.jpg', 120, 'image/jpeg')],
            'an "except" answer' => ['consent_type' => 'partial', 'consent' => UploadedFile::fake()->create('sbfp.jpg', 120, 'image/jpeg')],
        ] as $because => $fields) {
            $this->withSession($this->adviserSession())
                ->from(route('consent-forms.index'))
                ->post(route('parental-consent.store'), ['lrn' => '100000000001', 'program' => ParentalConsentForm::PROGRAM_FEEDING] + $fields)
                ->assertSessionHasErrors();

            $this->assertSame(0, ParentalConsentForm::count(), 'Nothing is filed with '.$because.'.');
        }
    }

    // ── What the nurse and the head read ────────────────────────────────

    #[Test]
    public function the_nutritional_health_status_list_says_what_a_qualified_learner_waits_for(): void
    {
        $learner = $this->qualified();
        $nurse = [
            'active_role' => 'school_nurse',
            'active_name' => 'Nurse Reyes',
            'active_institution_id' => $this->school->id,
        ];

        // The row's own standing — the filter and legend name every standing,
        // so the page as a whole always carries "Enrolled beneficiary".
        $this->withSession($nurse)
            ->get(route('dashboard.school-nurse.nutritional-status'))
            ->assertOk()
            ->assertSee('data-standing="Qualified, waiting for parent&#039;s consent"', false)
            ->assertDontSee('data-standing="Enrolled beneficiary"', false);

        $this->fileFeedingConsent($learner);

        $this->withSession($nurse)
            ->get(route('dashboard.school-nurse.nutritional-status'))
            ->assertOk()
            ->assertSee('data-standing="Qualified, consent on file — awaiting enrolment"', false);
    }

    #[Test]
    public function the_coordinators_all_students_view_offers_enrol_only_with_consent(): void
    {
        $learner = $this->qualified();

        $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records?view=all_students')
            ->assertOk()
            ->assertSee('Waiting for parent&#039;s consent', false)
            ->assertDontSee('data-enroll="'.$learner->id.'"', false)
            ->assertDontSee('data-select="'.$learner->id.'"', false);
    }
}
