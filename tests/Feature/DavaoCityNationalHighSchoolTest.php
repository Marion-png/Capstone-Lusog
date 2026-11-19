<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\HealthConsentForm;
use App\Models\HealthServiceSetting;
use App\Models\Institution;
use App\Models\InstitutionSection;
use App\Models\Medicine;
use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;
use App\Support\DecryptedValues;
use App\Support\FeedingAtRiskRule;
use App\Support\HealthServicesOffered;
use App\Support\SchoolLetterhead;
use Database\Seeders\InstitutionSectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Davao City National High School is a second registration school beside the
 * deployment's own. Its staff register, are approved and sign in like Sta.
 * Ana's, and the two schools never read each other's records. Every name and
 * LRN below is invented.
 */
class DavaoCityNationalHighSchoolTest extends TestCase
{
    use RefreshDatabase;

    private const DAVAO_CITY = 'Davao City National High School';

    private Institution $staAna;

    private Institution $davaoCity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staAna = Institution::create(['name' => Institution::REGISTRATION_SCHOOL, 'status' => 'active']);
        $this->davaoCity = Institution::create(['name' => self::DAVAO_CITY, 'status' => 'active']);
    }

    /** @test */
    public function davao_city_national_high_school_is_a_registration_school(): void
    {
        $this->assertContains(self::DAVAO_CITY, Institution::REGISTRATION_SCHOOLS);

        $this->get('/account-request')
            ->assertOk()
            ->assertSee(self::DAVAO_CITY)
            ->assertSee(Institution::REGISTRATION_SCHOOL);

        $this->getJson('/api/institutions')
            ->assertOk()
            ->assertJsonFragment(['id' => $this->davaoCity->id, 'name' => self::DAVAO_CITY]);
    }

    /** @test */
    public function the_default_catalogue_seeds_it_active(): void
    {
        Institution::query()->delete();

        Institution::seedDefaults();

        $this->assertDatabaseHas('institutions', ['name' => self::DAVAO_CITY, 'status' => 'active']);
        $this->assertSame(1, Institution::query()->where('name', self::DAVAO_CITY)->count());
    }

    /** @test */
    public function a_registration_for_it_is_stamped_with_that_school_and_encrypted(): void
    {
        $this->post('/account-request', [
            'terms' => '1',
            'name' => 'Lorna D. Ybanez',
            'username' => 'nurse.ybanez',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'role' => 'school_nurse',
            'institution_id' => $this->davaoCity->id,
        ])->assertSessionHasNoErrors();

        $row = DB::table('account_requests')->where('username', 'nurse.ybanez')->first();

        $this->assertNotNull($row);
        $this->assertSame($this->davaoCity->id, (int) $row->institution_id);
        $this->assertSame(self::DAVAO_CITY, $row->school_name);
        $this->assertStringNotContainsString('Ybanez', (string) $row->name);
        $this->assertTrue(DecryptedValues::isEncrypted((string) $row->name));
    }

    /** @test */
    public function its_section_catalogue_runs_grades_seven_to_ten(): void
    {
        $this->seedSections();

        $grades = $this->getJson("/api/institutions/{$this->davaoCity->id}/sections")
            ->assertOk()
            ->json('grades');

        $this->assertSame(['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'], array_keys($grades));
        $this->assertSame(InstitutionSectionSeeder::DAVAO_CITY_SECTIONS['Grade 7'], $grades['Grade 7']);
        $this->assertSame(InstitutionSectionSeeder::DAVAO_CITY_SECTIONS['Grade 10'], $grades['Grade 10']);

        // Each school's catalogue is its own.
        $this->assertNotContains('MAAYOS', $grades['Grade 7']);
        $this->assertSame(
            ['Grade 7' => ['MAAYOS']],
            $this->getJson("/api/institutions/{$this->staAna->id}/sections")->assertOk()->json('grades'),
        );
    }

    /** @test */
    public function the_migration_publishes_the_catalogue_once_and_only_for_an_existing_school(): void
    {
        $migration = require database_path('migrations/2026_11_18_000001_seed_davao_city_national_high_school_sections.php');

        $migration->up();
        $migration->up();

        $expected = array_sum(array_map('count', InstitutionSectionSeeder::DAVAO_CITY_SECTIONS));
        $this->assertSame($expected, InstitutionSection::query()->where('institution_id', $this->davaoCity->id)->count());
        $this->assertSame(0, InstitutionSection::query()->where('institution_id', $this->staAna->id)->count());

        $migration->down();
        $this->assertSame(0, InstitutionSection::query()->where('institution_id', $this->davaoCity->id)->count());
    }

    /** @test */
    public function an_adviser_registers_for_one_of_its_sections(): void
    {
        $this->seedSections();

        $this->post('/account-request', $this->adviserRequest('Grade 8', 'narra'))
            ->assertSessionHasNoErrors();

        // Stored in the catalogue's own spelling.
        $this->assertDatabaseHas('account_requests', [
            'username' => 'adviser.pacana',
            'institution_id' => $this->davaoCity->id,
            'assigned_grade_level' => 'Grade 8',
            'assigned_section' => 'NARRA',
        ]);
    }

    /** @test */
    public function a_section_it_does_not_run_is_refused_even_when_sta_ana_runs_it(): void
    {
        $this->seedSections();

        $this->post('/account-request', $this->adviserRequest('Grade 7', 'MAAYOS'))
            ->assertSessionHasErrors('assigned_section');

        $this->assertDatabaseMissing('account_requests', ['username' => 'adviser.pacana']);
    }

    /** @test */
    public function two_advisers_of_the_same_grade_never_see_each_others_learners(): void
    {
        $this->seedSections();
        $this->learner($this->davaoCity, 'Montecillo, Teresa L.', '900000000202', 'Grade 7 / SAMPAGUITA');
        $this->learner($this->davaoCity, 'Buenaventura, Rafael C.', '900000000203', 'Grade 7 / ROSAL');
        // A section of the same name at the other school is still the other school's.
        $this->learner($this->staAna, 'Salvador, Ignacio P.', '900000000101', 'Grade 7 / ROSAL');

        $this->withSession($this->adviser($this->davaoCity, 'Grade 7', 'SAMPAGUITA'))
            ->get('/dashboard/class-adviser')
            ->assertOk()
            ->assertSee('Montecillo, Teresa L.')
            ->assertDontSee('Buenaventura')
            ->assertDontSee('Salvador');

        $this->flushSession();

        $this->withSession($this->adviser($this->davaoCity, 'Grade 7', 'ROSAL'))
            ->get('/dashboard/class-adviser')
            ->assertOk()
            ->assertSee('Buenaventura, Rafael C.')
            ->assertDontSee('Montecillo')
            ->assertDontSee('Salvador');

        $this->flushSession();

        // Nor can one adviser open the other's learner by LRN.
        $this->withSession($this->adviser($this->davaoCity, 'Grade 7', 'ROSAL'))
            ->get('/dashboard/class-adviser/students/900000000202')
            ->assertDontSee('Montecillo');
    }

    /** @test */
    public function an_adviser_cannot_take_over_a_learner_from_another_section_of_the_grade(): void
    {
        $this->seedSections();
        $this->learner($this->davaoCity, 'Montecillo, Teresa L.', '900000000202', 'Grade 7 / SAMPAGUITA');

        $this->withSession($this->adviser($this->davaoCity, 'Grade 7', 'ROSAL'))->post('/adviser/store', [
            'last_name' => 'Rewritten', 'first_name' => 'Name', 'lrn' => '900000000202',
            'birth_month' => 1, 'birth_day' => 2, 'birth_year' => 2013, 'birthplace' => 'Davao City',
            'parent_guardian' => 'Parent', 'address' => 'Street', 'telephone_no' => '0917',
            'height_cm' => 150, 'weight_kg' => 40, 'grade_level' => 'Grade 7', 'section' => 'ROSAL',
        ]);

        $record = StudentHealthRecord::query()->where('student_id', '900000000202')->sole();
        $this->assertSame('Montecillo, Teresa L.', $record->student_name);
        $this->assertSame('Grade 7 / SAMPAGUITA', $record->section);
    }

    /** @test */
    public function the_feeding_and_nutrition_reports_count_only_their_own_school(): void
    {
        // Three beneficiaries at Sta. Ana, two at Davao City.
        foreach (['900000000101', '900000000102', '900000000103'] as $i => $lrn) {
            $this->learner($this->staAna, "Salvador{$i}, Ignacio P.", $lrn, 'Grade 7 / MAAYOS');
        }
        $this->learner($this->davaoCity, 'Montecillo, Teresa L.', '900000000202', 'Grade 7 / SAMPAGUITA');
        // Its adviser's form named the other school: the record is still Davao
        // City's, because the school is the institution id, not the typed name.
        $this->learner($this->davaoCity, 'Buenaventura, Rafael C.', '900000000203', 'Grade 7 / ROSAL', Institution::REGISTRATION_SCHOOL);

        foreach ([[$this->davaoCity, 2], [$this->staAna, 3]] as [$school, $count]) {
            $cards = $this->withSession($this->staff('feeding_coor', $school))
                ->getJson('/dashboard/feedingcor-dashboard/metrics')
                ->assertOk()
                ->json('html.cards');
            $this->assertMatchesRegularExpression('/Beneficiary<\/div>.*?kpi-value">'.$count.'</s', $cards, $school->name);
            $this->flushSession();

            $summary = $this->withSession($this->staff('nutricor', $school))
                ->get('/dashboard/nutricor-reports')
                ->assertOk()
                ->viewData('summary');
            $this->assertSame($count, $summary['total_population'], $school->name);
            $this->flushSession();

            $consolidated = $this->withSession($this->staff('nutricor', $school))
                ->get('/dashboard/nutricor-consolidated')
                ->assertOk()
                ->assertSee($school->name);
            $this->assertSame($count, $consolidated->viewData('totalStudents'), $school->name);
            $this->flushSession();
        }

        $sections = $this->withSession($this->staff('nutricor', $this->davaoCity))
            ->get('/dashboard/nutricor-consolidated')
            ->viewData('sectionBreakdown');
        $this->assertSame(['Grade 7 / ROSAL', 'Grade 7 / SAMPAGUITA'], $sections->keys()->all());
    }

    /** @test */
    public function the_consolidated_report_reads_nothing_without_a_school(): void
    {
        $this->learner($this->davaoCity, 'Montecillo, Teresa L.', '900000000202', 'Grade 7 / SAMPAGUITA');

        $response = $this->withSession(['active_role' => 'nutricor', 'active_name' => 'Test', 'active_username' => 'test'])
            ->get('/dashboard/nutricor-consolidated');

        // Turned away for having no school, or shown nothing: never every school.
        if ($response->getStatusCode() === 200) {
            $this->assertSame(0, $response->viewData('totalStudents'));
        } else {
            $this->assertContains($response->getStatusCode(), [302, 403]);
        }
    }

    /** @test */
    public function a_catalogue_school_outside_the_list_is_still_refused(): void
    {
        $other = Institution::create(['name' => 'Wireless ES', 'status' => 'active']);

        $this->post('/account-request', [
            'terms' => '1',
            'name' => 'Teodora P. Alcala',
            'username' => 'nurse.alcala',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'role' => 'school_nurse',
            'institution_id' => $other->id,
        ])->assertSessionHasErrors('institution_id');

        $this->assertDatabaseCount('account_requests', 0);
    }

    /** @test */
    public function an_approved_account_signs_in_to_its_own_school_and_sees_only_its_learners(): void
    {
        $this->learner($this->staAna, 'Salvador, Ignacio P.', '900000000101');
        $this->learner($this->davaoCity, 'Montecillo, Teresa L.', '900000000202');

        $this->post('/account-request', [
            'terms' => '1',
            'name' => 'Lorna D. Ybanez',
            'username' => 'nurse.ybanez',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'role' => 'school_nurse',
            'institution_id' => $this->davaoCity->id,
        ])->assertSessionHasNoErrors();

        $requestId = DB::table('account_requests')->where('username', 'nurse.ybanez')->value('id');

        $this->withSession(['active_role' => 'system_admin', 'active_name' => 'Test Admin', 'active_username' => 'systemadmin'])
            ->post(route('dashboard.system-admin.requests.approve', $requestId));

        $account = DB::table('accounts')->where('username', 'nurse.ybanez')->first();
        $this->assertNotNull($account);
        $this->assertSame($this->davaoCity->id, (int) $account->institution_id);
        $this->assertTrue(DecryptedValues::isEncrypted((string) $account->name));

        $this->flushSession();
        $this->post('/login', ['email' => 'nurse.ybanez', 'password' => 'password1'])
            ->assertSessionHas('active_institution_id', $this->davaoCity->id)
            ->assertSessionHas('active_school_name', self::DAVAO_CITY);

        $this->get('/dashboard/student-health-records')
            ->assertOk()
            ->assertSee('Montecillo, Teresa L.')
            ->assertDontSee('Salvador, Ignacio P.')
            ->assertDontSee('900000000101');
    }

    /** @test */
    public function neither_school_reads_the_others_learners(): void
    {
        $this->learner($this->staAna, 'Salvador, Ignacio P.', '900000000101');
        $this->learner($this->davaoCity, 'Montecillo, Teresa L.', '900000000202');

        $this->withSession($this->nurse($this->davaoCity))
            ->get('/dashboard/student-health-records')
            ->assertOk()
            ->assertSee('Montecillo, Teresa L.')
            ->assertDontSee('Salvador, Ignacio P.');

        $this->flushSession();

        $this->withSession($this->nurse($this->staAna))
            ->get('/dashboard/student-health-records')
            ->assertOk()
            ->assertSee('Salvador, Ignacio P.')
            ->assertDontSee('Montecillo, Teresa L.');
    }

    /** @test */
    public function neither_school_reads_the_others_consultations_or_medicines(): void
    {
        foreach ([[$this->staAna, 'Salvador, Ignacio P.', 'Staana Paracetamol'], [$this->davaoCity, 'Montecillo, Teresa L.', 'Dcnhs Paracetamol']] as [$school, $learner, $medicine]) {
            Consultation::create([
                'institution_id' => $school->id,
                'consulted_at' => now(),
                'student_name' => $learner,
                'grade_section' => 'Grade 8 - Narra',
                'condition' => 'Fever',
                'status' => 'treated',
            ]);
            Medicine::create([
                'institution_id' => $school->id,
                'name' => $medicine,
                'stock_quantity' => 40,
                'minimum_threshold' => 10,
                'unit' => 'tablets',
            ]);
        }

        $this->withSession($this->nurse($this->davaoCity))
            ->get('/dashboard/consultation-log')
            ->assertOk()
            ->assertSee('Montecillo, Teresa L.')
            ->assertDontSee('Salvador, Ignacio P.');

        $this->flushSession();

        $this->withSession($this->nurse($this->davaoCity))
            ->get('/dashboard/medicine-inventory')
            ->assertOk()
            ->assertSee('Dcnhs Paracetamol')
            ->assertDontSee('Staana Paracetamol');

        $this->flushSession();

        $this->withSession($this->nurse($this->staAna))
            ->get('/dashboard/consultation-log')
            ->assertOk()
            ->assertSee('Salvador, Ignacio P.')
            ->assertDontSee('Montecillo, Teresa L.');
    }

    /** @test */
    public function its_learner_data_is_ciphertext_in_the_database(): void
    {
        $record = $this->learner($this->davaoCity, 'Montecillo, Teresa L.', '900000000202');

        $raw = DB::table('student_health_records')->where('id', $record->id)->first();

        $this->assertSame($this->davaoCity->id, (int) $raw->institution_id);
        $this->assertStringNotContainsString('Montecillo', (string) $raw->student_name);
        $this->assertStringNotContainsString('Severely Wasted', (string) $raw->nutritional_status);
        $this->assertSame('Montecillo, Teresa L.', StudentHealthRecord::find($record->id)->student_name);
    }

    /** @test */
    public function its_consent_forms_are_not_headed_with_sta_anas_address(): void
    {
        $this->assertSame(
            HealthConsentForm::DEFAULT_SCHOOL_ADDRESS,
            HealthConsentForm::defaultSchoolAddress($this->staAna->id, Institution::REGISTRATION_SCHOOL),
        );

        // No address on file: a blank line to write on, never another school's.
        $this->assertSame('', HealthConsentForm::defaultSchoolAddress($this->davaoCity->id, self::DAVAO_CITY));

        $this->davaoCity->update(['address' => 'F. Torres St., Davao City']);
        $this->assertSame('F. Torres St., Davao City', HealthConsentForm::defaultSchoolAddress($this->davaoCity->id, self::DAVAO_CITY));
    }

    /** @test */
    public function it_starts_on_the_same_programme_defaults_as_sta_ana(): void
    {
        $migration = require database_path('migrations/2026_11_19_000001_apply_registration_defaults_to_every_registration_school.php');
        $migration->up();
        $migration->up();

        foreach ([$this->staAna, $this->davaoCity] as $school) {
            $this->assertSame(FeedingAtRiskRule::MODE_UNEXCUSED_ABSENCE_DAYS, $school->fresh()->feeding_at_risk_mode, $school->name);
            $this->assertSame(1, HealthServiceSetting::query()->where('institution_id', $school->id)->count(), $school->name);

            foreach (Institution::DAVAO_CITY_NOT_OFFERED as $service) {
                $this->assertNotContains($service, HealthServicesOffered::for($school->id, ParentalConsentForm::calendarSchoolYear()), "{$school->name}: {$service}");
            }
        }
    }

    /** @test */
    public function the_defaults_never_overrule_a_choice_already_made(): void
    {
        $this->davaoCity->update(['feeding_at_risk_mode' => FeedingAtRiskRule::MODE_ATTENDANCE_RATE]);
        HealthServicesOffered::save($this->davaoCity->id, ParentalConsentForm::calendarSchoolYear(), array_keys(HealthConsentForm::PLANNED_SERVICES), 'Nurse');

        $migration = require database_path('migrations/2026_11_19_000001_apply_registration_defaults_to_every_registration_school.php');
        $migration->up();
        Institution::seedDefaults();

        $this->assertSame(FeedingAtRiskRule::MODE_ATTENDANCE_RATE, $this->davaoCity->fresh()->feeding_at_risk_mode);
        $this->assertContains('tas', HealthServicesOffered::for($this->davaoCity->id, ParentalConsentForm::calendarSchoolYear()));
    }

    /** @test */
    public function a_fresh_database_seeds_both_schools_the_same_way(): void
    {
        Institution::query()->delete();

        Institution::seedDefaults();

        foreach (Institution::REGISTRATION_SCHOOLS as $name) {
            $school = Institution::query()->where('name', $name)->firstOrFail();
            $this->assertSame(FeedingAtRiskRule::MODE_UNEXCUSED_ABSENCE_DAYS, $school->feeding_at_risk_mode, $name);
            $this->assertNotContains('tas', HealthServicesOffered::for($school->id, ParentalConsentForm::calendarSchoolYear()), $name);
        }
    }

    /** @test */
    public function each_school_prints_its_own_seal_and_never_the_others(): void
    {
        $this->assertStringEndsWith('school-logos/sta-ana-national-high-school.png', SchoolLetterhead::schoolLogo($this->staAna->id));
        $this->assertStringEndsWith('school-logos/davao-city-national-high-school.png', SchoolLetterhead::schoolLogo($this->davaoCity->id));
        $this->assertNotNull(SchoolLetterhead::seals($this->staAna->id)['school']);
        $this->assertNotNull(SchoolLetterhead::sealFiles($this->staAna->id)['school']);

        $this->withSession($this->staff('feeding_coor', $this->davaoCity))
            ->get('/dashboard/feedingcor-sbfp-forms')
            ->assertOk()
            ->assertDontSee('sta-ana-national-high-school.png', false);

        $this->flushSession();

        $this->withSession($this->staff('feeding_coor', $this->staAna))
            ->get('/dashboard/feedingcor-sbfp-forms')
            ->assertOk()
            ->assertSee('school-logos/sta-ana-national-high-school.png', false);
    }

    private function learner(Institution $school, string $name, string $lrn, string $section = 'Grade 8 / NARRA', ?string $typedSchool = null): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'institution_id' => $school->id,
            'school_name' => $typedSchool ?? $school->name,
            'student_name' => $name,
            'student_id' => $lrn,
            'section' => $section,
            'weight' => 30.0,
            'bmi_value' => 13.5,
            'nutritional_status' => 'Severely Wasted',
            'baseline_nutritional_status' => 'Severely Wasted',
            'feeding_enrolled_at' => now()->subWeek(),
            'student_details' => ['gender' => 'Female', 'birth_date' => '2013-03-04'],
        ]);
    }

    private function seedSections(): void
    {
        InstitutionSectionSeeder::seedSections($this->davaoCity->id, InstitutionSectionSeeder::DAVAO_CITY_SECTIONS);
        InstitutionSection::create(['institution_id' => $this->staAna->id, 'grade_level' => 'Grade 7', 'name' => 'MAAYOS']);
    }

    /** @return array<string, mixed> */
    private function adviserRequest(string $grade, string $section): array
    {
        return [
            'terms' => '1',
            'name' => 'Ramon T. Pacana',
            'username' => 'adviser.pacana',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'role' => 'class_adviser',
            'institution_id' => $this->davaoCity->id,
            'assigned_grade_level' => $grade,
            'assigned_section' => $section,
        ];
    }

    /** @return array<string, mixed> */
    private function adviser(Institution $school, string $grade, string $section): array
    {
        return [
            ...$this->staff('class_adviser', $school),
            'assigned_grade_level' => $grade,
            'assigned_section' => $section,
        ];
    }

    /** @return array<string, mixed> */
    private function staff(string $role, Institution $school): array
    {
        return [
            'active_role' => $role,
            'active_name' => 'Test Staff',
            'active_username' => 'staff.test',
            'active_institution_id' => $school->id,
            'active_school_name' => $school->name,
        ];
    }

    /** @return array<string, mixed> */
    private function nurse(Institution $school): array
    {
        return [
            'active_role' => 'school_nurse',
            'active_name' => 'Test Nurse',
            'active_username' => 'nurse.test',
            'active_institution_id' => $school->id,
            'active_school_name' => $school->name,
        ];
    }
}
