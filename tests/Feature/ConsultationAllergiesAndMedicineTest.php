<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\HealthAssessment;
use App\Models\HealthConsentForm;
use App\Models\Institution;
use App\Models\Medicine;
use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;
use App\Support\ConsultationMedicines;
use App\Support\LearnerAllergies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two things a clinic desk needs while it records a visit.
 *
 *   1. The learner's recorded allergies, before anything is handed over —
 *      read from every place an allergy is recorded, one learner at a time,
 *      for the clinic desks only.
 *   2. What was given at each visit — the medicine, how much, and which
 *      expiry date — beside the visit on the log and on the dashboards,
 *      instead of buried in free text.
 */
class ConsultationAllergiesAndMedicineTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    private StudentHealthRecord $learner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
        $this->learner = $this->learnerAt($this->school, '100000000201', ['med_allergies' => true, 'allergies_detail' => 'Penicillin']);
    }

    private function learnerAt(Institution $school, string $lrn, array $history = []): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_id' => $lrn,
            'student_name' => 'Dimaculangan, Ligaya',
            'school_name' => $school->name,
            'section' => 'Grade 7 / Maingat',
            'weight' => 35,
            'bmi_value' => 16.2,
            'nutritional_status' => 'Wasted',
            'student_details' => [
                'first_name' => 'Ligaya', 'last_name' => 'Dimaculangan',
                'grade_level' => 'Grade 7', 'section' => 'Maingat', 'gender' => 'Female',
                'health_history' => $history,
            ],
        ]);
    }

    private function sessionFor(string $role, ?Institution $school = null): array
    {
        $session = [
            'active_role' => $role,
            'active_name' => 'Staff '.$role,
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => ($school ?? $this->school)->id,
        ];

        if ($role === 'class_adviser') {
            $session += ['assigned_grade_level' => 'Grade 7', 'assigned_section' => 'Maingat'];
        }

        return $session;
    }

    private function consentForm(array $answers): HealthConsentForm
    {
        return HealthConsentForm::create(array_merge([
            'token' => Str::random(48),
            'school_year' => HealthConsentForm::currentSchoolYear(),
            'institution_id' => $this->school->id,
            'division' => 'Davao City', 'school_name' => 'Sta. Ana NHS', 'school_address' => 'Davao City',
            'student_lrn' => '100000000201', 'student_name' => 'Dimaculangan, Ligaya',
            'grade_level' => 'Grade 7', 'section' => 'Maingat', 'parent_guardian_name' => 'Dimaculangan, Bayani',
            'services' => ['checkup'], 'status' => HealthConsentForm::STATUS_DRAFT,
        ], $answers));
    }

    // ── 1. Recorded allergies ──────────────────────────────────────────

    #[Test]
    public function every_place_an_allergy_is_recorded_is_read(): void
    {
        // The parent's letter repeats the health card's allergy and adds a
        // food one; "wala" on the illness line says nothing.
        $this->consentForm(['allergy_medicine' => 'penicillin', 'allergy_food' => 'Shrimp']);

        ParentalConsentForm::create([
            'student_health_record_id' => $this->learner->id, 'program_type' => 'Deworming',
            'school_year' => HealthConsentForm::currentSchoolYear(),
            'file_path' => 'parental-consents/1/x.pdf', 'file_original_name' => 'x.pdf', 'uploaded_by_name' => 'Adviser',
            'allergy_medicine' => true, 'allergy_medicine_detail' => '',
            'allergy_food' => false, 'allergy_food_detail' => 'wala',
        ]);

        HealthAssessment::create([
            'student_health_record_id' => $this->learner->id,
            'school_year' => HealthConsentForm::currentSchoolYear(),
            'med_allergies' => true, 'med_allergies_detail' => 'Mefenamic acid',
        ]);

        $allergies = $this->withSession($this->sessionFor('school_nurse'))
            ->getJson(route('student-allergies.show', '100000000201'))
            ->assertOk()
            ->json('allergies');

        $byText = collect($allergies)->keyBy('text');

        // The same allergy from two places is one allergy with two sources,
        // under the specific kind.
        $this->assertSame('Medicine', $byText['Penicillin']['kind']);
        $this->assertSame(['Health card (Sheet 1)', "Parent's consent form, S.Y. ".HealthConsentForm::currentSchoolYear()], $byText['Penicillin']['sources']);
        $this->assertSame('Food', $byText['Shrimp']['kind']);
        $this->assertSame('Allergy', $byText['Mefenamic acid']['kind']);

        // A ticked box with nothing written beside it is still an allergy.
        $this->assertSame('Medicine', $byText[LearnerAllergies::NOT_SPECIFIED]['kind']);
        $this->assertSame(['Returned consent form, S.Y. '.HealthConsentForm::currentSchoolYear()], $byText[LearnerAllergies::NOT_SPECIFIED]['sources']);

        // "wala" is not an allergy.
        $this->assertFalse($byText->has('wala'));
        $this->assertCount(4, $allergies);
    }

    #[Test]
    public function a_learner_with_nothing_recorded_has_no_allergies(): void
    {
        $this->learnerAt($this->school, '100000000202');
        $this->consentForm(['student_lrn' => '100000000202', 'allergy_medicine' => 'None', 'allergy_food' => 'N/A']);

        $this->withSession($this->sessionFor('clinic_staff'))
            ->getJson(route('student-allergies.show', '100000000202'))
            ->assertOk()
            ->assertExactJson(['allergies' => []]);
    }

    #[Test]
    public function allergies_are_read_by_the_clinic_desks_of_the_learners_own_school_and_audited(): void
    {
        foreach (['school_nurse', 'clinic_staff', 'clinic_teacher'] as $role) {
            $this->withSession($this->sessionFor($role))
                ->getJson(route('student-allergies.show', '100000000201'))
                ->assertOk()
                ->assertJsonPath('allergies.0.text', 'Penicillin');
        }

        foreach (['class_adviser', 'school_head', 'feeding_coor'] as $role) {
            $this->withSession($this->sessionFor($role))
                ->getJson(route('student-allergies.show', '100000000201'))
                ->assertForbidden();
        }

        // Another school's nurse reads nothing of this learner.
        $elsewhere = Institution::create(['name' => 'Other NHS', 'status' => 'active']);
        $this->withSession($this->sessionFor('school_nurse', $elsewhere))
            ->getJson(route('student-allergies.show', '100000000201'))
            ->assertOk()
            ->assertExactJson(['allergies' => []]);

        $this->assertTrue(
            AuditLog::where('url', 'like', '%/health-records/students/100000000201/allergies%')->exists(),
            'Reading a learner\'s allergies is on the audit trail.'
        );
    }

    #[Test]
    public function the_dialog_reads_allergies_for_the_learner_it_is_given(): void
    {
        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.consultation-log'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="cm_allergies"', $html);
        $this->assertStringContainsString("'/allergies'", $html);
        // The search answers an LRN as well as a name, and shows it.
        $this->assertStringContainsString("className: 'cmcombo-lrn', textContent: 'LRN ' + learner.lrn", $html);
        $this->assertStringContainsString('lrn.includes(digits)', $html);
    }

    // ── 2. What was given, beside the visit ────────────────────────────

    private function visitWithParacetamol(int $quantity): Consultation
    {
        $paracetamol = Medicine::firstOrCreate(['name' => 'Paracetamol 500mg tablet'], [
            'institution_id' => $this->school->id,
            'stock_quantity' => 50, 'minimum_threshold' => 10, 'unit' => 'tablets', 'expiry_date' => '2027-04-30',
        ]);

        $this->withSession($this->sessionFor('school_nurse'))
            ->from(route('dashboard.consultation-log'))
            ->post(route('consultations.store'), [
                'consulted_at' => now()->format('Y-m-d H:i'),
                'student_name' => 'Dimaculangan, Ligaya',
                'grade_section' => 'Grade 7 - Maingat',
                'condition' => 'Fever',
                'treatment_given' => 'Rest and fluids.',
                'status' => 'treated',
                'medicine_id' => $paracetamol->id,
                'medicine_quantity' => $quantity,
            ])
            ->assertSessionHasNoErrors('consultation');

        return Consultation::latest('id')->firstOrFail();
    }

    #[Test]
    public function the_log_prints_the_medicine_quantity_and_expiry_beside_the_recommendations(): void
    {
        $visit = $this->visitWithParacetamol(2);

        $this->assertSame(
            'Paracetamol 500mg tablet × 2 tablets (exp 30 Apr 2027)',
            ConsultationMedicines::line(ConsultationMedicines::forConsultations([$visit]), $visit->id)
        );

        $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.consultation-log'))
            ->assertOk()
            ->assertSeeInOrder(['<th>Medicine Given</th>', '<th>Recommendations</th>'], false)
            ->assertSeeInOrder(['Paracetamol 500mg tablet × 2 tablets (exp 30 Apr 2027)', 'Rest and fluids.']);
    }

    #[Test]
    public function the_clinic_dashboards_print_what_was_given(): void
    {
        $this->visitWithParacetamol(3);

        foreach ([['school_nurse', '/dashboard/school-nurse'], ['clinic_teacher', '/dashboard/clinic-teacher'], ['clinic_staff', '/dashboard/clinic-staff']] as [$role, $url]) {
            $this->withSession($this->sessionFor($role))
                ->get($url)
                ->assertOk()
                ->assertSee('<th>Medicine Given</th>', false)
                ->assertSee('Paracetamol 500mg tablet × 3 tablets (exp 30 Apr 2027)');
        }
    }

    #[Test]
    public function the_profiles_consultation_log_carries_the_medicine_for_the_clinic_and_never_the_adviser(): void
    {
        $this->visitWithParacetamol(1);

        $this->withSession($this->sessionFor('school_nurse'))
            ->getJson(route('api.student-consultations', ['lrn' => '100000000201']))
            ->assertOk()
            ->assertJsonPath('consultations.0.medicine', 'Paracetamol 500mg tablet × 1 tablets (exp 30 Apr 2027)')
            ->assertJsonPath('consultations.0.treatment', 'Rest and fluids.');

        // The adviser's profile is redacted: the medicine never reaches them.
        $html = $this->withSession($this->sessionFor('class_adviser') + [
            'school_health_card_records' => [[
                'lrn' => '100000000201', 'first_name' => 'Ligaya', 'last_name' => 'Dimaculangan',
                'grade_level' => 'Grade 7', 'section' => 'Maingat',
            ]],
        ])->get(route('dashboard.class-adviser.student-profile', '100000000201'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Paracetamol 500mg tablet × 1', $html);
    }

    #[Test]
    public function a_list_of_visits_costs_two_queries_whatever_its_length(): void
    {
        $visits = collect(range(1, 4))->map(fn () => $this->visitWithParacetamol(1));

        DB::enableQueryLog();
        DB::flushQueryLog();
        $lines = ConsultationMedicines::forConsultations($visits);
        $this->assertCount(2, DB::getQueryLog());
        $this->assertCount(4, $lines);
    }
}
