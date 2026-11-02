<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\DispenseAllergyCheck;
use App\Models\Institution;
use App\Models\LearnerMedicineAllergy;
use App\Models\Medicine;
use App\Models\MedicineDispense;
use App\Models\StudentHealthRecord;
use App\Support\AllergyCheck;
use App\Support\MedicineAllergies;
use App\Support\RxNormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The RxNorm allergy check (SIGLA RxNorm Allergy Check — API Integration
 * Guide, test cases RX-01 to RX-15).
 *
 * RxNav is faked with the shapes it actually answers in: approximateTerm
 * lists one row per source and leaves some unnamed, related.json?tty=IN+MIN
 * lists every combination product an ingredient is in, and byRxcui returns
 * classes reached through combinations (ibuprofen's N02AJ) and `{}` for an
 * ingredient with none. No test reaches the real API.
 */
class RxNormAllergyCheckTest extends TestCase
{
    use RefreshDatabase;

    private const LRN = '100000000301';

    private const LEARNER = 'Dimaculangan, Ligaya';

    /** term => list of [rxcui, name, source] */
    private const TERMS = [
        'ibuprofen' => [['5640', 'Ibuprofen', 'USP'], ['5640', '', 'GS'], ['5640', 'ibuprofen', 'RXNORM']],
        'ibuprofn' => [['5640', 'Ibuprofen', 'USP'], ['5640', 'ibuprofen', 'RXNORM']],
        'acetaminophen' => [['161', 'acetaminophen', 'RXNORM'], ['707705', '', 'MMSL']],
        'mefenamate' => [['257844', 'mefenamate', 'RXNORM']],
        'amoxicillin' => [['723', 'amoxicillin', 'RXNORM']],
        'amoxicillin / clavulanate' => [['19711', 'amoxicillin / clavulanate', 'RXNORM'], ['19711', 'AMOXICILLIN/CLAVULANATE', 'VANDF']],
    ];

    /** rxcui => [IN ingredients [rxcui, name], MIN products] */
    private const RELATED = [
        '5640' => [[['5640', 'ibuprofen']], [['1007329', 'ibuprofen / phenylephrine']]],
        '161' => [[['161', 'acetaminophen']], []],
        '257844' => [[['257844', 'mefenamate']], []],
        '723' => [[['723', 'amoxicillin']], []],
        '19711' => [[['48203', 'clavulanate'], ['723', 'amoxicillin']], [['19711', 'amoxicillin / clavulanate']]],
    ];

    /** rxcui => list of [classId, className, minConcept rxcui, minConcept tty] */
    private const CLASSES = [
        '5640' => [['M01AE', 'Propionic acid derivatives', '5640', 'IN'], ['N02AJ', 'Opioids in combination', '214627', 'MIN']],
        '161' => [['N02BE', 'Anilides', '161', 'IN']],
        '257844' => [['M01AG', 'Fenamates', '6693', 'PIN']],
        '723' => [['J01CA', 'Penicillins with extended spectrum', '723', 'IN']],
    ];

    private Institution $school;

    /** Replaces the fake RxNav's answer, for an outage or a broken reply. */
    private ?\Closure $rxNavAnswer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
        $this->fakeRxNav();
    }

    // ── Flow A: a medicine ────────────────────────────────────────────

    /** RX-01 */
    #[Test]
    public function mefenamic_acid_is_matched_with_its_atc_class(): void
    {
        $this->addMedicine('Mefenamic Acid')->assertSessionHas('success');

        $medicine = Medicine::firstOrFail();
        $this->assertSame(RxNormService::MATCHED, $medicine->rx_status);
        $this->assertSame('257844', $medicine->rxConcept->rxcui);
        $this->assertSame(['M01AG'], array_column($medicine->rxConcept->atc_codes, 'code'));
    }

    /** RX-02 */
    #[Test]
    public function paracetamol_is_looked_up_as_acetaminophen(): void
    {
        $this->addMedicine('Paracetamol');

        $this->assertSame('acetaminophen', Medicine::firstOrFail()->rxConcept->name);
        Http::assertSent(fn (Request $request) => ($request->data()['term'] ?? null) === 'acetaminophen');
    }

    /** RX-03 */
    #[Test]
    public function a_typo_offers_candidates_and_the_nurse_picks_one(): void
    {
        $this->addMedicine('Ibuprofn')
            ->assertSessionHas('rx_pick', fn (array $pick) => $pick['candidates'] === [['rxcui' => '5640', 'name' => 'ibuprofen']]);

        $medicine = Medicine::firstOrFail();
        $this->assertSame(RxNormService::NOT_FOUND, $medicine->rx_status, 'Never picked automatically.');

        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('medicine-inventory.rxnorm', $medicine), ['generic_name' => 'Ibuprofn', 'rxcui' => '5640'])
            ->assertSessionHas('success');

        $this->assertSame(RxNormService::MATCHED, $medicine->fresh()->rx_status);
        $this->assertSame('5640', $medicine->fresh()->rxConcept->rxcui);
    }

    /** RX-04 */
    #[Test]
    public function a_name_rxnorm_does_not_know_is_not_found(): void
    {
        $this->addMedicine('xyz123')
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'generic name'));

        $this->assertSame(RxNormService::NOT_FOUND, Medicine::firstOrFail()->rx_status);
    }

    /** RX-05 */
    #[Test]
    public function an_outage_saves_the_medicine_unverified_after_two_attempts(): void
    {
        Sleep::fake();
        $this->rxNavAnswer = fn () => Http::response('', 503);

        $this->addMedicine('Ibuprofen')
            ->assertSessionHas('error', 'Allergy check unavailable; saved without verification.');

        $this->assertSame(RxNormService::UNVERIFIED, Medicine::firstOrFail()->rx_status);
        Http::assertSentCount(2);
    }

    #[Test]
    public function an_unreadable_answer_is_unverified_and_an_odd_name_is_never_sent(): void
    {
        $this->rxNavAnswer = fn () => Http::response('<html>maintenance</html>', 200);
        $this->assertSame(RxNormService::UNVERIFIED, app(RxNormService::class)->lookup('Ibuprofen')['status']);

        $this->addMedicine('Ibuprofen; DROP TABLE')->assertSessionHasErrors('generic_name');
        $this->assertSame(0, Medicine::count());
    }

    #[Test]
    public function a_class_reached_only_through_a_combination_is_not_the_ingredients(): void
    {
        $concept = app(RxNormService::class)->lookup('Ibuprofen')['concept'];

        $this->assertSame(['M01AE'], array_column($concept->atc_codes, 'code'));
        $this->assertSame(['5640' => 'ibuprofen'], $concept->ingredientMap());
    }

    // ── Flow B: an allergy ────────────────────────────────────────────

    /** RX-06 */
    #[Test]
    public function a_medicine_allergy_on_sheet_1_is_matched(): void
    {
        $this->submitSheetOne('Ibuprofen', 'medicine');

        $row = LearnerMedicineAllergy::firstOrFail();
        $this->assertSame('Ibuprofen', $row->entered_text);
        $this->assertSame(RxNormService::MATCHED, $row->match_status);
        $this->assertSame(LearnerMedicineAllergy::SOURCE_SHEET1, $row->source);
    }

    /** RX-07 */
    #[Test]
    public function a_food_allergy_stays_text_and_calls_nothing(): void
    {
        $this->submitSheetOne('Hipon', 'food');

        $this->assertSame(0, LearnerMedicineAllergy::count());
        Http::assertNothingSent();
    }

    #[Test]
    public function only_a_medicine_name_leaves_the_school(): void
    {
        $this->learner(['med_allergies' => true, 'allergies_detail' => 'Ibuprofen, amoxicillin', 'allergies_type' => 'medicine']);
        MedicineAllergies::sync($this->school->id, self::LRN);

        $this->assertSame(2, LearnerMedicineAllergy::count());
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), self::LRN)
            || str_contains(urldecode($request->url()), 'Dimaculangan'));
    }

    // ── Flow C: a dispense ────────────────────────────────────────────

    /** RX-08 */
    #[Test]
    public function the_same_ingredient_is_a_warning_that_needs_a_reason(): void
    {
        $ibuprofen = $this->medicine('Ibuprofen 200mg tablet', 'Ibuprofen');
        $this->allergicTo('Ibuprofen');

        $this->checkFor('school_nurse', $ibuprofen)
            ->assertJson(['result' => AllergyCheck::WARNING, 'requires' => 'override'])
            ->assertJsonPath('message', 'Learner is allergic to Ibuprofen. Ibuprofen 200mg tablet contains ibuprofen.');

        $this->dispense('school_nurse', $ibuprofen)->assertSessionHasErrors('allergy_override_reason', null, 'consultation');
        $this->assertSame(0, Consultation::count());

        $this->dispense('school_nurse', $ibuprofen, ['allergy_override_reason' => 'too short'])
            ->assertSessionHasErrors('allergy_override_reason', null, 'consultation');
        $this->assertSame(0, Consultation::count());
    }

    /** RX-09 */
    #[Test]
    public function the_same_drug_class_is_a_caution_saved_after_acknowledgement(): void
    {
        $mefenamic = $this->medicine('Mefenamic acid 500mg capsule', 'Mefenamic Acid');
        $this->allergicTo('Ibuprofen');

        $this->checkFor('school_nurse', $mefenamic)
            ->assertJson(['result' => AllergyCheck::CAUTION, 'requires' => 'acknowledge', 'note' => AllergyCheck::NOTE])
            ->assertJsonPath('message', 'Mefenamic acid 500mg capsule is in the same drug class as Ibuprofen (ATC M01A). Check before giving.');

        $this->dispense('school_nurse', $mefenamic)->assertSessionHasErrors('allergy_acknowledged', null, 'consultation');
        $this->dispense('school_nurse', $mefenamic, ['allergy_acknowledged' => '1'])->assertSessionHasNoErrors();

        $this->assertSame(1, Consultation::count());
        $this->assertSame(AllergyCheck::CAUTION, DispenseAllergyCheck::firstOrFail()->result);
    }

    /** RX-10 */
    #[Test]
    public function an_allergy_to_one_ingredient_of_a_combination_is_a_warning(): void
    {
        $coAmoxiclav = $this->medicine('Co-amoxiclav 625mg tablet', 'Co-amoxiclav');
        $this->allergicTo('Amoxicillin');

        $this->checkFor('school_nurse', $coAmoxiclav)
            ->assertJson(['result' => AllergyCheck::WARNING])
            ->assertJsonPath('message', 'Learner is allergic to Amoxicillin. Co-amoxiclav 625mg tablet contains amoxicillin.');
    }

    /** RX-11 */
    #[Test]
    public function an_override_is_saved_with_its_reason_who_and_when(): void
    {
        $ibuprofen = $this->medicine('Ibuprofen 200mg tablet', 'Ibuprofen');
        $this->allergicTo('Ibuprofen');

        $this->dispense('school_nurse', $ibuprofen, ['allergy_override_reason' => 'MD order on file'])
            ->assertSessionHasNoErrors();

        $check = DispenseAllergyCheck::firstOrFail();
        $this->assertSame(AllergyCheck::WARNING, $check->result);
        $this->assertTrue($check->overridden);
        $this->assertSame('MD order on file', $check->override_reason);
        $this->assertSame('Nurse Cruz', $check->checked_by_name);
        $this->assertSame('school_nurse', $check->checked_by_role);
        $this->assertNotNull($check->checked_at);
        $this->assertSame(Consultation::firstOrFail()->id, $check->consultation_id);

        $raw = DB::table('dispense_allergy_checks')->first();
        $this->assertStringNotContainsString('MD order', (string) $raw->override_reason, 'Encrypted at rest.');
    }

    /** RX-12 */
    #[Test]
    public function a_clinic_teacher_gives_paracetamol_to_a_learner_with_no_allergy(): void
    {
        $paracetamol = $this->medicine('Paracetamol 500mg tablet', 'Paracetamol');
        $this->learner();

        $this->checkFor('clinic_teacher', $paracetamol)->assertJson(['result' => AllergyCheck::CLEAR, 'requires' => 'none']);
        $this->dispense('clinic_teacher', $paracetamol)->assertSessionHasNoErrors();

        $this->assertSame(1, MedicineDispense::count());
        $this->assertSame(AllergyCheck::CLEAR, DispenseAllergyCheck::firstOrFail()->result);
    }

    /** RX-13 */
    #[Test]
    public function a_clinic_teacher_is_blocked_by_a_paracetamol_allergy(): void
    {
        $paracetamol = $this->medicine('Paracetamol 500mg tablet', 'Paracetamol');
        $this->allergicTo('Paracetamol');

        $this->checkFor('clinic_teacher', $paracetamol)->assertJson(['result' => AllergyCheck::BLOCKED, 'requires' => 'blocked']);

        $this->dispense('clinic_teacher', $paracetamol, ['allergy_acknowledged' => '1'])
            ->assertSessionHasErrors(['medicine_id' => 'Learner has a recorded paracetamol allergy. Refer to the School Nurse.'], null, 'consultation');

        $this->assertSame(0, Consultation::count());
        $this->assertSame(100, $paracetamol->fresh()->stock_quantity);
        $blocked = DispenseAllergyCheck::firstOrFail();
        $this->assertSame(AllergyCheck::BLOCKED, $blocked->result);
        $this->assertNull($blocked->consultation_id);
    }

    #[Test]
    public function an_unmatched_allergy_naming_paracetamol_still_blocks_a_clinic_teacher(): void
    {
        $paracetamol = $this->medicine('Paracetamol 500mg tablet', 'Paracetamol');
        $this->learner(['med_allergies' => true, 'allergies_detail' => 'Biogesic', 'allergies_type' => 'medicine']);
        LearnerMedicineAllergy::create([
            'institution_id' => $this->school->id, 'student_lrn' => self::LRN, 'entered_text' => 'Biogesic',
            'match_status' => RxNormService::UNVERIFIED, 'source' => LearnerMedicineAllergy::SOURCE_SHEET1,
        ]);

        $this->checkFor('clinic_teacher', $paracetamol)->assertJson(['result' => AllergyCheck::BLOCKED]);
    }

    /** RX-14 */
    #[Test]
    public function a_clinic_teacher_cannot_post_another_medicine(): void
    {
        $ibuprofen = $this->medicine('Ibuprofen 200mg tablet', 'Ibuprofen');
        $this->learner();

        $this->dispense('clinic_teacher', $ibuprofen, ['allergy_acknowledged' => '1'])->assertForbidden();

        $this->assertSame(0, Consultation::count());
        $this->assertSame(100, $ibuprofen->fresh()->stock_quantity);
    }

    /** RX-15 */
    #[Test]
    public function an_unverified_allergy_asks_for_a_manual_check(): void
    {
        $paracetamol = $this->medicine('Paracetamol 500mg tablet', 'Paracetamol');
        $this->learner(['med_allergies' => true, 'allergies_detail' => 'Tetracycline', 'allergies_type' => 'medicine']);

        // Recorded but never looked up: unverified, never clear.
        $this->checkFor('school_nurse', $paracetamol)
            ->assertJson(['result' => AllergyCheck::UNVERIFIED, 'requires' => 'acknowledge'])
            ->assertJsonPath('message', 'Allergy check could not be completed for Tetracycline. Check the learner\'s allergies manually.');

        $this->dispense('school_nurse', $paracetamol)->assertSessionHasErrors('allergy_acknowledged', null, 'consultation');
        $this->dispense('school_nurse', $paracetamol, ['allergy_acknowledged' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(AllergyCheck::UNVERIFIED, DispenseAllergyCheck::firstOrFail()->result);
    }

    #[Test]
    public function the_allergy_list_marks_an_allergy_that_is_not_verified(): void
    {
        $this->learner(['med_allergies' => true, 'allergies_detail' => 'Tetracycline', 'allergies_type' => 'medicine']);

        $this->withSession($this->sessionFor('school_nurse'))
            ->getJson(route('student-allergies.show', self::LRN))
            ->assertJsonPath('allergies.0.verified', false);
    }

    #[Test]
    public function the_retry_job_verifies_what_an_outage_left_unverified(): void
    {
        $this->learner(['med_allergies' => true, 'allergies_detail' => 'Ibuprofen', 'allergies_type' => 'medicine']);
        LearnerMedicineAllergy::create([
            'institution_id' => $this->school->id, 'student_lrn' => self::LRN, 'entered_text' => 'Ibuprofen',
            'match_status' => RxNormService::UNVERIFIED, 'source' => LearnerMedicineAllergy::SOURCE_SHEET1,
        ]);
        $medicine = Medicine::create([
            'institution_id' => $this->school->id, 'name' => 'Amoxicillin 250mg capsule', 'stock_quantity' => 10,
            'minimum_threshold' => 2, 'unit' => 'capsules', 'generic_name' => 'Amoxicillin', 'rx_status' => RxNormService::UNVERIFIED,
        ]);

        $this->artisan('rxnorm:retry-unverified')->assertSuccessful();

        $this->assertSame(RxNormService::MATCHED, LearnerMedicineAllergy::firstOrFail()->match_status);
        $this->assertSame(RxNormService::MATCHED, $medicine->fresh()->rx_status);
    }

    #[Test]
    public function the_screens_carry_the_check(): void
    {
        $this->addMedicine('Ibuprofn');

        $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.medicine-inventory'))
            ->assertOk()
            ->assertSee('id="rxBackdrop"', false)
            ->assertSee('data-reopen', false)
            ->assertSee('name="rxcui" value="5640"', false)
            ->assertSee('Not found');

        $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.consultation-log'))
            ->assertOk()
            ->assertSee('id="cm_allergy_check"', false)
            ->assertSee('name="student_lrn"', false)
            ->assertSee(AllergyCheck::NOTE);

        $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('consultations.create'))
            ->assertOk()
            ->assertSee('id="cc_allergy_check"', false);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function fakeRxNav(): void
    {
        Http::fake(function (Request $request) {
            if ($this->rxNavAnswer !== null) {
                return ($this->rxNavAnswer)($request);
            }

            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (str_ends_with($path, '/approximateTerm.json')) {
                $rows = self::TERMS[$query['term'] ?? ''] ?? [];

                return Http::response(['approximateGroup' => ['inputTerm' => null] + ($rows === [] ? [] : [
                    'candidate' => array_map(fn (array $row) => array_filter([
                        'rxcui' => $row[0], 'score' => '10', 'rank' => '1', 'name' => $row[1], 'source' => $row[2],
                    ]), $rows),
                ])]);
            }

            if (preg_match('#/rxcui/(\d+)/related\.json$#', $path, $match)) {
                // RxNav refuses related.json without the term types.
                if (($query['tty'] ?? '') !== 'IN MIN') {
                    return Http::response(['error' => 'tty is required'], 400);
                }

                [$ingredients, $products] = self::RELATED[$match[1]] ?? [[], []];
                $group = fn (string $tty, array $concepts) => ['tty' => $tty] + ($concepts === [] ? [] : [
                    'conceptProperties' => array_map(fn (array $c) => ['rxcui' => $c[0], 'name' => $c[1], 'tty' => $tty], $concepts),
                ]);

                return Http::response(['relatedGroup' => ['rxcui' => null, 'conceptGroup' => [$group('IN', $ingredients), $group('MIN', $products)]]]);
            }

            if (str_ends_with($path, '/rxclass/class/byRxcui.json')) {
                $classes = self::CLASSES[$query['rxcui'] ?? ''] ?? [];

                // RxNav answers `{}` for an ingredient with no class.
                return $classes === [] ? Http::response('{}', 200, ['Content-Type' => 'application/json']) : Http::response(['rxclassDrugInfoList' => ['rxclassDrugInfo' => array_map(fn (array $c) => [
                    'minConcept' => ['rxcui' => $c[2], 'tty' => $c[3]],
                    'rxclassMinConceptItem' => ['classId' => $c[0], 'className' => $c[1], 'classType' => 'ATC1-4'],
                ], $classes)]]);
            }

            return Http::response([], 404);
        });
    }

    private function sessionFor(string $role): array
    {
        return [
            'active_role' => $role,
            'active_name' => $role === 'school_nurse' ? 'Nurse Cruz' : 'Teacher Reyes',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
        ];
    }

    private function addMedicine(string $genericName)
    {
        return $this->withSession($this->sessionFor('school_nurse'))
            ->from(route('dashboard.medicine-inventory'))
            ->post(route('medicine-inventory.store'), [
                'catalogue_name' => 'Ibuprofen 200mg tablet',
                'generic_name' => $genericName,
                'stock_quantity' => 20,
                'minimum_threshold' => 5,
                'unit' => 'tablets',
            ]);
    }

    private function medicine(string $name, string $genericName): Medicine
    {
        $medicine = Medicine::create([
            'institution_id' => $this->school->id, 'name' => $name,
            'stock_quantity' => 100, 'minimum_threshold' => 10, 'unit' => 'tablets',
        ]);
        $medicine->applyRxNorm($genericName, app(RxNormService::class)->lookup($genericName));

        return $medicine->fresh();
    }

    private function learner(array $history = []): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_id' => self::LRN,
            'student_name' => self::LEARNER,
            'school_name' => $this->school->name,
            'section' => 'Grade 7 / Maingat',
            'student_details' => [
                'first_name' => 'Ligaya', 'last_name' => 'Dimaculangan',
                'grade_level' => 'Grade 7', 'section' => 'Maingat', 'gender' => 'Female',
                'health_history' => $history,
            ],
        ]);
    }

    private function allergicTo(string $medicine): void
    {
        $this->learner(['med_allergies' => true, 'allergies_detail' => $medicine, 'allergies_type' => 'medicine']);
        MedicineAllergies::sync($this->school->id, self::LRN);
    }

    private function checkFor(string $role, Medicine $medicine)
    {
        return $this->withSession($this->sessionFor($role))
            ->getJson(route('student-allergies.check', ['medicine_id' => $medicine->id, 'lrn' => self::LRN]))
            ->assertOk();
    }

    private function dispense(string $role, Medicine $medicine, array $extra = [])
    {
        return $this->withSession($this->sessionFor($role))
            ->from(route('dashboard.consultation-log'))
            ->post(route('consultations.store'), array_merge([
                'consulted_at' => now()->format('Y-m-d H:i'),
                'student_name' => self::LEARNER,
                'grade_section' => 'Grade 7 - Maingat',
                'student_lrn' => self::LRN,
                'condition' => 'Fever',
                'status' => 'treated',
                'medicine_id' => $medicine->id,
                'medicine_quantity' => 1,
            ], $extra));
    }

    private function submitSheetOne(string $allergy, string $type): void
    {
        $this->withSession([
            'active_role' => 'class_adviser',
            'active_name' => 'Adviser Santos',
            'active_institution_id' => $this->school->id,
            'active_school_name' => 'Sta. Ana NHS',
            'assigned_grade_level' => 'Grade 7',
            'assigned_section' => 'Maingat',
        ])->post(route('adviser.store'), [
            'form_mode' => 'enrol',
            'last_name' => 'Dimaculangan', 'first_name' => 'Ligaya', 'middle_name' => 'B',
            'lrn' => self::LRN,
            'birth_date' => '2013-06-01', 'birthplace' => 'Davao City',
            'parent_guardian' => 'Bayani Dimaculangan', 'address' => 'Sta. Ana, Davao City',
            'region' => 'XI', 'division' => 'Davao City', 'telephone_no' => '09170000000',
            'gender' => 'Female', 'height_cm' => 145, 'weight_kg' => 38,
            'grade_level' => 'Grade 7', 'section' => 'Maingat',
            'health_history' => ['med_allergies' => '1', 'allergies_detail' => $allergy, 'allergies_type' => $type],
        ])->assertRedirect(route('dashboard.class-adviser'));
    }
}
