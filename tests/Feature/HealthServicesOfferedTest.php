<?php

namespace Tests\Feature;

use App\Models\HealthConsentForm;
use App\Models\HealthServiceSetting;
use App\Models\Institution;
use App\Models\ParentalConsentForm;
use App\Support\ConsentStanding;
use App\Support\HealthServicesOffered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The School Nurse's "Health Services Offered": one switch per service, per
 * school year. A service the school does not offer reads Not offered on every
 * consent form, exactly as one the adviser did not shade at the top — never
 * Excluded, which would read as the parent refusing it. The rules that hold
 * in every school (Immunization for Grade 7, Iron for female learners) are
 * coded, not switched.
 */
class HealthServicesOfferedTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = Institution::create(['name' => 'Test High School', 'status' => 'active']);
    }

    private function sessionFor(string $role): array
    {
        return [
            'active_role' => $role,
            'active_name' => 'Nurse Cruz',
            'active_username' => 'nurse1',
            'active_school_name' => $this->school->name,
            'active_institution_id' => $this->school->id,
        ];
    }

    private function allBut(array $off): array
    {
        return array_values(array_diff(array_keys(HealthConsentForm::PLANNED_SERVICES), $off));
    }

    #[Test]
    public function the_school_nurse_sets_the_services_offered_for_a_school_year(): void
    {
        $year = HealthConsentForm::currentSchoolYear();

        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('settings'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Health Services Offered', $html);
        // Nothing saved yet: every service is offered.
        $this->assertSame(count(HealthConsentForm::PLANNED_SERVICES), substr_count($html, 'role="switch" checked'));
        $this->assertStringContainsString('Grade 7 only', $html);
        $this->assertStringContainsString('Female learners only', $html);

        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('settings.health-services'), [
                'school_year' => $year,
                'offered' => $this->allBut(['deworming_schistosomiasis', 'deworming_filariasis', 'tas']),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(
            $this->allBut(['deworming_schistosomiasis', 'deworming_filariasis', 'tas']),
            HealthServicesOffered::for($this->school->id, $year),
        );
        $this->assertSame(['deworming_schistosomiasis', 'deworming_filariasis', 'tas'], HealthServiceSetting::sole()->not_offered);

        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('settings'))
            ->getContent();
        $this->assertStringContainsString('value="tas" role="switch" >', $html);
        $this->assertStringContainsString('value="deworming_worms" role="switch" checked', $html);
    }

    /** A year with no setting of its own carries the last one, and says so. */
    #[Test]
    public function a_school_year_with_no_setting_carries_the_last_one(): void
    {
        $year = HealthConsentForm::currentSchoolYear();
        $start = (int) explode('-', $year)[0];
        $next = ($start + 1).'-'.($start + 2);

        HealthServicesOffered::save($this->school->id, $year, $this->allBut(['tas']), 'Nurse Cruz');

        $this->assertNotContains('tas', HealthServicesOffered::for($this->school->id, $next));

        $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('settings', ['services_year' => $next]))
            ->assertOk()
            ->assertSee('showing the setting carried over from S.Y. '.$year);

        // Saved for the next year, it stands on its own.
        HealthServicesOffered::save($this->school->id, $next, $this->allBut([]), 'Nurse Cruz');
        $this->assertContains('tas', HealthServicesOffered::for($this->school->id, $next));
        $this->assertNotContains('tas', HealthServicesOffered::for($this->school->id, $year));
    }

    /** It is the School Nurse's: nobody else's page carries it, and the endpoint refuses them. */
    #[Test]
    public function only_the_school_nurse_may_change_it(): void
    {
        foreach (['class_adviser', 'clinic_staff', 'feeding_coor', 'school_head'] as $role) {
            $this->withSession($this->sessionFor($role))
                ->get(route('settings'))
                ->assertOk()
                ->assertDontSee('Health Services Offered');

            $this->withSession($this->sessionFor($role))
                ->post(route('settings.health-services'), [
                    'school_year' => HealthConsentForm::currentSchoolYear(),
                    'offered' => [],
                ])
                ->assertForbidden();
        }

        $this->assertSame(0, HealthServiceSetting::count());
    }

    #[Test]
    public function a_year_off_the_list_or_a_service_off_the_letter_is_refused(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('settings.health-services'), ['school_year' => '1999-2000', 'offered' => []])
            ->assertSessionHasErrors('school_year', null, 'healthServices');

        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('settings.health-services'), ['school_year' => HealthConsentForm::currentSchoolYear(), 'offered' => ['deworming']])
            ->assertSessionHasErrors('offered.0', null, 'healthServices');

        $this->assertSame(0, HealthServiceSetting::count());
    }

    /** The deployment school starts with what Davao City does not give switched off. */
    #[Test]
    public function sta_ana_starts_with_schistosomiasis_filariasis_and_tas_off(): void
    {
        Institution::seedDefaults();

        $staAna = Institution::where('name', Institution::REGISTRATION_SCHOOL)->firstOrFail();

        $this->assertSame(
            $this->allBut(['deworming_schistosomiasis', 'deworming_filariasis', 'tas']),
            HealthServicesOffered::for($staAna->id, ParentalConsentForm::calendarSchoolYear()),
        );
        // Every other school offers them all until its nurse says otherwise.
        $this->assertSame(array_keys(HealthConsentForm::PLANNED_SERVICES), HealthServicesOffered::for($this->school->id, ParentalConsentForm::calendarSchoolYear()));
    }

    /**
     * Not shaded at the top → Not offered; shaded and full consent →
     * Consented; shaded and listed under "except these" → Excluded. A service
     * the school does not offer, or one a rule rules out, is never Excluded.
     */
    #[Test]
    public function each_service_reads_consented_excluded_not_offered_or_not_applicable(): void
    {
        $offered = $this->allBut(['tas']);
        $full = ['source' => 'upload', 'choice' => HealthConsentForm::CONSENT_ALL, 'services' => ['checkup', 'iron'], 'listed' => true, 'exceptions' => '', 'excluded' => null];

        $this->assertSame(ConsentStanding::SERVICE_CONSENTED, ConsentStanding::forService('checkup', 8, true, $full, 'Female', $offered));
        $this->assertSame(ConsentStanding::SERVICE_NOT_ON_FORM, ConsentStanding::forService('vitamin_a', 8, true, $full, 'Female', $offered));
        $this->assertSame('Not offered', ConsentStanding::SERVICE_LABELS[ConsentStanding::SERVICE_NOT_ON_FORM]);
        // The school does not offer it, whatever the form says.
        $this->assertSame(ConsentStanding::SERVICE_NOT_ON_FORM, ConsentStanding::forService('tas', 8, true, [...$full, 'services' => ['tas']], 'Female', $offered));
        // Iron is for female learners only.
        $this->assertSame(ConsentStanding::SERVICE_CONSENTED, ConsentStanding::forService('iron', 8, true, $full, 'Female', $offered));
        $this->assertSame(ConsentStanding::SERVICE_NOT_APPLICABLE, ConsentStanding::forService('iron', 8, true, $full, 'Male', $offered));
        // Immunization is for Grade 7 only.
        $this->assertSame(ConsentStanding::SERVICE_NOT_APPLICABLE, ConsentStanding::forService('immunization_grade7', 8, true, [...$full, 'services' => ['immunization_grade7']], 'Female', $offered));

        $partial = [...$full, 'choice' => HealthConsentForm::CONSENT_SPECIFIC, 'services' => ['checkup', 'dental'], 'excluded' => ['dental']];
        $this->assertSame(ConsentStanding::SERVICE_CONSENTED, ConsentStanding::forService('checkup', 8, true, $partial, 'Female', $offered));
        $this->assertSame(ConsentStanding::SERVICE_DECLINED, ConsentStanding::forService('dental', 8, true, $partial, 'Female', $offered));
        $this->assertSame(ConsentStanding::SERVICE_NOT_ON_FORM, ConsentStanding::forService('vitamin_a', 8, true, $partial, 'Female', $offered));

        // A refusal declines what the form asked about, and nothing it did not.
        $refused = [...$full, 'choice' => HealthConsentForm::CONSENT_DENY, 'services' => ['checkup']];
        $this->assertSame(ConsentStanding::SERVICE_DECLINED, ConsentStanding::forService('checkup', 8, true, $refused, 'Female', $offered));
        $this->assertSame(ConsentStanding::SERVICE_NOT_ON_FORM, ConsentStanding::forService('vitamin_a', 8, true, $refused, 'Female', $offered));
    }

    /**
     * The upload dialog locks the rows the learner is never given: a service
     * the school does not offer this school year, MR, Td outside Grade 7, and
     * Iron per learner sex.
     */
    #[Test]
    public function the_upload_dialog_knows_which_rows_are_locked(): void
    {
        HealthServicesOffered::save($this->school->id, HealthConsentForm::currentSchoolYear(), $this->allBut(['tas']), 'Nurse Cruz');

        $html = $this->withSession([
            'active_role' => 'class_adviser',
            'active_name' => 'Test Adviser',
            'active_username' => 'adviser1',
            'active_school_name' => $this->school->name,
            'active_institution_id' => $this->school->id,
            'assigned_grade_level' => 'Grade 8',
            'assigned_section' => 'Rizal',
        ])->get(route('consent-forms.index'))->assertOk()->getContent();

        $this->assertStringContainsString('"key":"tas","label":', $html);
        $this->assertMatchesRegularExpression('/"key":"tas".*?"offered":false/', $html);
        $this->assertMatchesRegularExpression('/"key":"iron".*?"offered":true,"only_sex":"Female"/', $html);
        // A Grade 8 class is never given the Grade 7 immunization: it is listed,
        // carrying the grade that locks it to Not offered for this class.
        $this->assertMatchesRegularExpression('/"key":"immunization_grade7".*?"only_grade":7/', $html);
        $this->assertStringContainsString('const classGrade = 8;', $html);
        $this->assertStringContainsString("'Not offered'", $html);
    }
}
