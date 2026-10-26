<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\StudentVitalSigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Role separation for vital signs.
 *
 * The class adviser encodes height and weight — the two readings a teacher
 * takes with a tape and a scale, and what the feeding programme's BMI is
 * built from. The school nurse encodes temperature, pulse rate and blood
 * pressure, which are a clinical observation.
 *
 * So the adviser's form shows those three and never asks for them, and the
 * separation is enforced server-side: the adviser's save ignores them
 * whatever it posts, and the nurse's endpoint refuses any other role. A
 * read-only field is presentation; the endpoint is the guarantee.
 */
class StudentVitalSignsRoleTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function sessionFor(string $role): array
    {
        $base = [
            'active_role' => $role,
            'active_name' => $role === 'school_nurse' ? 'Nurse Cruz' : 'Maria Santos',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
            'school_health_card_records' => [[
                'lrn' => '110000000001',
                'first_name' => 'Juan',
                'last_name' => 'Cruz',
                'grade_level' => 'Grade 10',
                'section' => 'Dalton',
            ]],
        ];

        if ($role === 'class_adviser') {
            $base['assigned_grade_level'] = 'Grade 10';
            $base['assigned_section'] = 'Dalton';
        }

        return $base;
    }

    private function learner(array $details = []): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'student_id' => '110000000001',
            'student_name' => 'Cruz, Juan',
            'school_name' => 'Sta. Ana NHS',
            'grade_level' => 'Grade 10',
            'section' => 'Grade 10 / Dalton',
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'weight' => '40',
            'bmi_value' => '18',
            'nutritional_status' => 'Normal',
            'student_details' => array_merge([
                'lrn' => '110000000001',
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
            ], $details),
        ]);
    }

    /** The fields the adviser's save posts for the whole card. */
    private function adviserPayload(array $overrides = []): array
    {
        return array_merge([
            'last_name' => 'Cruz',
            'first_name' => 'Juan',
            'lrn' => '110000000001',
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

    private function recordVitals(array $payload, string $role = 'school_nurse')
    {
        return $this->withSession($this->sessionFor($role))
            ->postJson(route('student-vitals.store', '110000000001'), $payload);
    }

    // ── The adviser reads ────────────────────────────────────────────

    #[Test]
    public function the_advisers_form_shows_vital_signs_and_never_asks_for_them(): void
    {
        $html = $this->withSession($this->sessionFor('class_adviser'))
            ->get(route('dashboard.class-adviser', ['tab' => 'form']))
            ->assertOk()
            ->getContent();

        // A readout, not fields.
        $this->assertStringContainsString('id="vitalTemperature"', $html);
        $this->assertStringContainsString('id="vitalPulse"', $html);
        $this->assertStringContainsString('id="vitalBloodPressure"', $html);
        $this->assertStringContainsString('Recorded by the school nurse', $html);

        $this->assertStringNotContainsString('name="temperature_c"', $html);
        $this->assertStringNotContainsString('name="pulse_bpm"', $html);
        $this->assertStringNotContainsString('name="blood_pressure"', $html);
    }

    /** Height and weight stay the adviser's to enter. */
    #[Test]
    public function the_adviser_still_encodes_height_and_weight(): void
    {
        $html = $this->withSession($this->sessionFor('class_adviser'))
            ->get(route('dashboard.class-adviser', ['tab' => 'form']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="weight_kg"', $html);
        $this->assertStringContainsString('name="height_m"', $html);
    }

    // ── The nurse writes ─────────────────────────────────────────────

    #[Test]
    public function the_nurse_can_record_vital_signs(): void
    {
        $record = $this->learner();

        $this->recordVitals([
            'temperature_c' => '36.8',
            'pulse_bpm' => '76',
            'blood_pressure' => '110/70',
        ])->assertOk();

        $vitals = StudentVitalSigns::read($record->fresh());

        $this->assertSame('36.8', $vitals['temperature_c']);
        $this->assertSame('76', $vitals['pulse_bpm']);
        $this->assertSame('110/70', $vitals['blood_pressure']);
        $this->assertSame('Nurse Cruz', $vitals['recorded_by']);
        $this->assertTrue($vitals['has_any']);
    }

    /** Recording one does not disturb the adviser's half of the card. */
    #[Test]
    public function recording_vitals_leaves_the_rest_of_the_card_alone(): void
    {
        $record = $this->learner();

        $this->recordVitals(['temperature_c' => '37.2'])->assertOk();

        $details = $record->fresh()->student_details;

        $this->assertSame('Maria Cruz', $details['parent_guardian']);
        $this->assertSame('12 Rizal St.', $details['address']);
        $this->assertEquals(150, $details['height_cm']);
    }

    /** The ranges moved with the fields. */
    #[Test]
    public function out_of_range_readings_are_refused(): void
    {
        $this->learner();

        $this->recordVitals(['temperature_c' => 120])
            ->assertStatus(422)
            ->assertJsonValidationErrors('temperature_c');

        $this->recordVitals(['pulse_bpm' => 900])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pulse_bpm');
    }

    /**
     * A blood pressure is systolic/diastolic in mmHg. The field used to take
     * any text, so "normal" or "110" was saved as a reading and printed on the
     * MLAT; now anything that is not a plausible pair is refused and nothing
     * is written.
     */
    #[Test]
    public function a_blood_pressure_that_is_not_a_reading_is_refused(): void
    {
        $record = $this->learner();

        foreach ([
            'normal' => 'Enter the blood pressure as systolic/diastolic',
            '110' => 'Enter the blood pressure as systolic/diastolic',
            '110/70/60' => 'Enter the blood pressure as systolic/diastolic',
            '11O/70' => 'Enter the blood pressure as systolic/diastolic',
            '120-80' => 'Enter the blood pressure as systolic/diastolic',
            '1100/70' => 'Enter the blood pressure as systolic/diastolic',
            '400/80' => 'The systolic (first) figure must be between 50 and 250',
            '120/10' => 'The diastolic (second) figure must be between 30 and 150',
            '70/110' => 'The systolic (first) figure must be higher than the diastolic',
            '80/80' => 'The systolic (first) figure must be higher than the diastolic',
        ] as $value => $message) {
            // (string): PHP turns the key '110' into an integer, and the form
            // always posts text.
            $value = (string) $value;
            $response = $this->recordVitals(['blood_pressure' => $value])->assertStatus(422);
            $this->assertStringStartsWith($message, $response->json('errors.blood_pressure.0'), "'{$value}' must be refused.");
        }

        $this->assertNull(StudentVitalSigns::read($record->fresh())['blood_pressure'], 'A refused reading writes nothing.');
    }

    /** A valid reading is kept the standard way, spaces around the slash dropped. */
    #[Test]
    public function a_blood_pressure_is_stored_as_systolic_over_diastolic(): void
    {
        $record = $this->learner();

        $this->recordVitals(['blood_pressure' => ' 118 / 76 '])->assertOk()
            ->assertJsonPath('vitals.blood_pressure', '118/76');
        $this->assertSame('118/76', StudentVitalSigns::read($record->fresh())['blood_pressure']);

        // The edges of the range are readings too.
        $this->recordVitals(['blood_pressure' => '250/150'])->assertOk();
        $this->recordVitals(['blood_pressure' => '50/30'])->assertOk();

        // And blank still clears it.
        $this->recordVitals(['blood_pressure' => ''])->assertOk();
        $this->assertNull(StudentVitalSigns::read($record->fresh())['blood_pressure']);
    }

    /** The form says what it takes, and filters what it is given. */
    #[Test]
    public function the_nurses_blood_pressure_field_takes_a_reading(): void
    {
        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.student-health-records'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/id="vfBloodPressure"[^>]*inputmode="numeric"[^>]*pattern="[^"]+"/s', $html);
        $this->assertStringContainsString('const bloodPressureProblem = ', $html);
    }

    /** A nurse correcting a mistyped reading to "none taken" must be able to. */
    #[Test]
    public function a_blank_field_clears_the_reading(): void
    {
        $record = $this->learner(['temperature_c' => '39.9']);

        $this->recordVitals(['temperature_c' => '', 'pulse_bpm' => '70'])->assertOk();

        $vitals = StudentVitalSigns::read($record->fresh());

        $this->assertNull($vitals['temperature_c']);
        $this->assertSame('70', $vitals['pulse_bpm']);
    }

    #[Test]
    public function a_reading_is_encrypted_at_rest_and_audited(): void
    {
        $this->learner();

        $this->recordVitals(['blood_pressure' => '118/76'])->assertOk();

        $raw = (string) DB::table('student_health_records')
            ->where('student_id', '110000000001')->value('student_details');

        $this->assertStringNotContainsString('118/76', $raw);

        $this->assertTrue(
            AuditLog::where('subject_type', 'StudentHealthRecord')->exists(),
            'Recording a vital sign must leave an audit entry.'
        );
    }

    // ── Nobody else writes ───────────────────────────────────────────

    /**
     * The read-only rendering on the adviser's form is presentation; this is
     * the guarantee. A stale tab, a replayed form or devtools all reach the
     * endpoint the same way.
     */
    #[Test]
    public function no_other_role_can_record_a_reading(): void
    {
        $record = $this->learner();

        foreach (['class_adviser', 'clinic_staff', 'school_head', 'feeding_coor'] as $role) {
            $this->withSession($this->sessionFor($role))
                ->postJson(route('student-vitals.store', '110000000001'), ['temperature_c' => '38.0'])
                ->assertForbidden();
        }

        $this->assertNull(StudentVitalSigns::read($record->fresh())['temperature_c']);
    }

    /** And the adviser's own save cannot smuggle one in. */
    #[Test]
    public function an_advisers_save_never_writes_a_vital_sign(): void
    {
        $record = $this->learner();

        $this->withSession($this->sessionFor('class_adviser'))
            ->post(route('adviser.store'), $this->adviserPayload([
                'temperature_c' => '40.1',
                'pulse_bpm' => '180',
                'blood_pressure' => '200/140',
            ]))
            ->assertSessionHasNoErrors();

        $vitals = StudentVitalSigns::read($record->fresh());

        $this->assertNull($vitals['temperature_c']);
        $this->assertNull($vitals['pulse_bpm']);
        $this->assertNull($vitals['blood_pressure']);
    }

    /**
     * And an adviser editing a learner cannot wipe what the nurse recorded.
     * Their form rebuilds the whole card on every save, so the reading has to
     * be carried across explicitly — otherwise a teacher correcting a phone
     * number would silently delete a clinical observation.
     */
    #[Test]
    public function an_advisers_edit_preserves_the_nurses_reading(): void
    {
        $record = $this->learner();

        $this->recordVitals([
            'temperature_c' => '36.6',
            'pulse_bpm' => '72',
            'blood_pressure' => '112/72',
        ])->assertOk();

        $this->withSession($this->sessionFor('class_adviser'))
            ->post(route('adviser.store'), $this->adviserPayload(['telephone_no' => '09990001111']))
            ->assertSessionHasNoErrors();

        $fresh = $record->fresh();
        $vitals = StudentVitalSigns::read($fresh);

        $this->assertSame('36.6', $vitals['temperature_c']);
        $this->assertSame('72', $vitals['pulse_bpm']);
        $this->assertSame('112/72', $vitals['blood_pressure']);
        $this->assertSame('Nurse Cruz', $vitals['recorded_by']);

        // The adviser's own edit still lands.
        $this->assertSame('09990001111', $fresh->student_details['telephone_no']);
    }

    // ── Scope ────────────────────────────────────────────────────────

    #[Test]
    public function another_schools_learner_is_refused(): void
    {
        $other = Institution::create(['name' => 'Wireless ES', 'status' => 'active']);

        StudentHealthRecord::create([
            'institution_id' => $other->id,
            'student_id' => '110000000009',
            'student_name' => 'Other, Learner',
            'school_name' => 'Wireless ES',
            'grade_level' => 'Grade 7',
            'section' => 'Grade 7 / Rizal',
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'weight' => '35',
            'bmi_value' => '17',
            'nutritional_status' => 'Normal',
        ]);

        $this->withSession($this->sessionFor('school_nurse'))
            ->postJson(route('student-vitals.store', '110000000009'), ['temperature_c' => '37.0'])
            ->assertForbidden();
    }

    // ── The nurse's panel ────────────────────────────────────────────

    #[Test]
    public function the_nurse_profile_offers_the_recording_panel(): void
    {
        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.student-health-records'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="vitalsSection"', $html);
        $this->assertStringContainsString('Record Vital Signs', $html);
        $this->assertStringContainsString('id="vfTemperature"', $html);

        // The form's styles ship with the page that draws it. They used to
        // live in the dashboard's sheet, which this page never loads, so the
        // three inputs rendered as bare browser boxes beside the styled ones.
        $this->assertStringContainsString('.vitals-form-grid input{', $html);
        $this->assertStringContainsString('.vitals-form-grid input:focus{', $html);
    }

    /** Clinic staff read the panel; the button is not theirs. */
    #[Test]
    public function clinic_staff_see_the_readings_without_the_button(): void
    {
        $html = $this->withSession([
            'active_role' => 'clinic_staff',
            'active_name' => 'Clinic Staff',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
        ])->get(route('dashboard.student-health-records'))->assertOk()->getContent();

        $this->assertStringContainsString('id="pvTemperature"', $html);
        $this->assertStringContainsString('const canRecord = false;', $html);
    }
}
