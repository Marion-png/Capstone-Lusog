<?php

namespace Tests\Feature;

use App\Models\HealthConsentForm;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The School Head's Consent Compliance tab, filtered by health service.
 *
 * The tab already answered "who is outstanding". This answers the question the
 * other way round: **who may this service actually be given to** — the learners
 * whose parent answered and did not refuse, for the service the head picked.
 * Each row opens that parent's own signed letter, which is a deliberate act
 * about a named learner and is audited as one.
 *
 * What it still must not do is put the letter's *contents* on the monitoring
 * table: the allergies, the write-in exceptions and the signature belong behind
 * that click, not in a list.
 */
class SchoolHeadConsentServicesTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private Institution $otherSchool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
        $this->otherSchool = Institution::create(['name' => 'Other School', 'status' => 'active']);
    }

    private function headSession(): array
    {
        return [
            'active_role' => 'school_head',
            'active_name' => 'Principal Reyes',
            'active_username' => 'head.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->institution->id,
        ];
    }

    private function learner(string $name, string $lrn): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => $name,
            'student_id' => $lrn,
            'school_name' => 'Test School',
            'section' => 'Grade 7 / Rizal',
            'weight' => 30,
            'bmi_value' => 15,
            'nutritional_status' => 'Wasted',
            'baseline_nutritional_status' => 'Wasted',
        ]);
    }

    /** @param  list<string>  $services */
    private function consentFor(
        StudentHealthRecord $learner,
        array $services,
        string $status = HealthConsentForm::STATUS_SIGNED,
        string $choice = 'allow',
        ?Institution $school = null,
    ): HealthConsentForm {
        $school ??= $this->institution;

        return HealthConsentForm::create([
            'token' => bin2hex(random_bytes(8)),
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'institution_id' => $school->id,
            'division' => 'Davao City',
            'school_name' => $school->name,
            'school_address' => 'Davao City',
            'student_address' => 'Davao City',
            'student_lrn' => $learner->student_id,
            'student_name' => $learner->student_name,
            'grade_level' => 'Grade 7',
            'section' => 'Rizal',
            'parent_guardian_name' => 'Maria Santos',
            'services' => $services,
            'status' => $status,
            'consent_choice' => $choice,
            'allergy_food' => 'Peanuts',
            'signature' => 'data:image/png;base64,AAAA',
            'signed_at' => now(),
        ]);
    }

    private function open(string $query = '')
    {
        return $this->withSession($this->headSession())->get('/dashboard/school-head/consent'.$query);
    }

    #[Test]
    public function the_tab_offers_every_health_service_as_a_filter(): void
    {
        $this->learner('Dela Cruz, Juan', '100000000001');

        $response = $this->open()->assertOk();

        $response->assertSee('Health service');
        // The catalogue is the printed letter's own list, read from the model.
        foreach (array_keys(HealthConsentForm::serviceLabels()) as $key) {
            $response->assertSee('value="'.$key.'"', false);
        }
    }

    #[Test]
    public function filtering_to_a_service_lists_only_the_learners_consented_for_it(): void
    {
        $dewormed = $this->learner('Dewormed, Ana', '100000000001');
        $dentalOnly = $this->learner('Dental, Ben', '100000000002');

        $this->consentFor($dewormed, ['deworming', 'checkup']);
        $this->consentFor($dentalOnly, ['dental']);

        $rows = $this->open('?service=deworming')->assertOk()->viewData('grantedRows');

        $this->assertSame(['Dewormed, Ana'], collect($rows)->pluck('name')->all());
    }

    /**
     * A parent who refused, and a letter nobody has answered, authorise
     * nothing — so neither learner appears on a list of who may be treated.
     */
    #[Test]
    public function a_refusal_or_an_unanswered_letter_authorises_nothing(): void
    {
        $allowed = $this->learner('Allowed, Ana', '100000000001');
        $refused = $this->learner('Refused, Ben', '100000000002');
        $awaiting = $this->learner('Awaiting, Cara', '100000000003');
        $drafted = $this->learner('Drafted, Dan', '100000000004');

        $this->consentFor($allowed, ['deworming']);
        $this->consentFor($refused, ['deworming'], choice: HealthConsentForm::CONSENT_DENY);
        $this->consentFor($awaiting, ['deworming'], status: HealthConsentForm::STATUS_SENT);
        $this->consentFor($drafted, ['deworming'], status: HealthConsentForm::STATUS_DRAFT);

        $rows = $this->open('?service=deworming')->assertOk()->viewData('grantedRows');

        $this->assertSame(['Allowed, Ana'], collect($rows)->pluck('name')->all());
    }

    /** Another school's consent never reaches this head. */
    #[Test]
    public function the_list_is_scoped_to_the_heads_own_school(): void
    {
        $mine = $this->learner('Mine, Ana', '100000000001');
        $this->consentFor($mine, ['deworming']);

        $theirs = StudentHealthRecord::create([
            'institution_id' => $this->otherSchool->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Theirs, Ben',
            'student_id' => '200000000001',
            'school_name' => 'Other School',
            'section' => 'Grade 7 / Rizal',
            'weight' => 30,
            'bmi_value' => 15,
            'nutritional_status' => 'Wasted',
        ]);
        $this->consentFor($theirs, ['deworming'], school: $this->otherSchool);

        $response = $this->open('?service=deworming')->assertOk();

        $response->assertSee('Mine, Ana');
        $response->assertDontSee('Theirs, Ben');
    }

    /** An unrecognised service is dropped rather than emptying the page. */
    #[Test]
    public function an_unknown_service_is_ignored(): void
    {
        $learner = $this->learner('Dela Cruz, Juan', '100000000001');
        $this->consentFor($learner, ['deworming']);

        $response = $this->open('?service=teleportation')->assertOk();

        $this->assertSame('', $response->viewData('service'));
        $this->assertCount(1, $response->viewData('grantedRows'));
    }

    /** The monitoring table reports standing, never the letter's contents. */
    #[Test]
    public function the_table_never_carries_the_letters_contents(): void
    {
        $learner = $this->learner('Dela Cruz, Juan', '100000000001');
        $this->consentFor($learner, ['deworming']);

        $response = $this->open('?service=deworming')->assertOk();

        $response->assertSee('Dela Cruz, Juan');
        $response->assertDontSee('Peanuts');
        $response->assertDontSee('data:image/png;base64');
    }

    #[Test]
    public function the_head_can_open_one_signed_form(): void
    {
        $learner = $this->learner('Dela Cruz, Juan', '100000000001');
        $form = $this->consentFor($learner, ['deworming']);

        $this->withSession($this->headSession())
            ->get(route('consent-forms.head-show', $form->id))
            ->assertOk()
            ->assertSee('Dela Cruz, Juan');

        // Opening a parent's answer about a named learner is recorded.
        $this->assertDatabaseHas('audit_logs', [
            'actor_role' => 'school_head',
            'route_name' => 'consent-forms.head-show',
        ]);

        // And the form itself carries who read it.
        $this->assertStringContainsString(
            'Viewed by School Head',
            json_encode($form->fresh()->audit),
        );
    }

    /** A letter nobody has signed has no signature to show. */
    #[Test]
    public function an_unsigned_form_cannot_be_opened(): void
    {
        $learner = $this->learner('Dela Cruz, Juan', '100000000001');
        $form = $this->consentFor($learner, ['deworming'], status: HealthConsentForm::STATUS_SENT);

        $this->withSession($this->headSession())
            ->get(route('consent-forms.head-show', $form->id))
            ->assertRedirect(route('dashboard.school-head.consent'))
            ->assertSessionHas('error');
    }

    /** And never another school's letter, whatever id is on the wire. */
    #[Test]
    public function another_schools_form_is_refused(): void
    {
        $theirs = StudentHealthRecord::create([
            'institution_id' => $this->otherSchool->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Theirs, Ben',
            'student_id' => '200000000001',
            'school_name' => 'Other School',
            'section' => 'Grade 7 / Rizal',
            'weight' => 30,
            'bmi_value' => 15,
            'nutritional_status' => 'Wasted',
        ]);
        $form = $this->consentFor($theirs, ['deworming'], school: $this->otherSchool);

        $response = $this->withSession($this->headSession())
            ->get(route('consent-forms.head-show', $form->id));

        $this->assertNotSame(200, $response->getStatusCode());
    }

    /** Only the School Head reaches this page. */
    #[Test]
    public function other_roles_cannot_open_the_heads_form_view(): void
    {
        $learner = $this->learner('Dela Cruz, Juan', '100000000001');
        $form = $this->consentFor($learner, ['deworming']);

        $this->withSession([
            'active_role' => 'feeding_coor',
            'active_name' => 'Coordinator',
            'active_username' => 'coor.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->institution->id,
        ])
            ->get(route('consent-forms.head-show', $form->id))
            ->assertRedirect(route('login'));
    }
}
