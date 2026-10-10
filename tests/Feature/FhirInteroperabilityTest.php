<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FhirTransmission;
use App\Models\Institution;
use App\Models\MedicalCertificate;
use App\Models\StudentHealthCondition;
use App\Models\StudentHealthRecord;
use App\Support\FhirBundle;
use App\Support\StudentRecordPurge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HL7 FHIR R4 outbound exchange (docs/fhir-interoperability.md).
 *
 * A learner's record serialises to a transaction Bundle built from the rows
 * on file; it is sent over HTTPS and nothing else; every attempt leaves an
 * encrypted transmission record and an audit entry; only the School Nurse can
 * reach it, and only for their own school. The HTTP client is faked
 * throughout — no test sends a record anywhere.
 */
class FhirInteroperabilityTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fhir.example.test/baseR4';

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = Institution::create([
            'name' => 'San Antonio National High School',
            'address' => 'Purok 3, San Antonio, Davao City',
            'status' => 'active',
        ]);

        config([
            'services.fhir.endpoint' => self::ENDPOINT,
            'services.fhir.auth_token' => 'super-secret-token',
            'services.fhir.deidentify' => true,
            'services.fhir.system_base' => 'https://lusog.example.test/fhir',
        ]);

        Sleep::fake();
    }

    private function nurse(?Institution $school = null): array
    {
        $school ??= $this->school;

        return [
            'active_role' => 'school_nurse',
            'active_name' => 'Nurse Joy',
            'active_username' => 'nurse.joy',
            'active_school_name' => $school->name,
            'active_institution_id' => $school->id,
        ];
    }

    private function learner(array $overrides = [], ?Institution $school = null): StudentHealthRecord
    {
        $school ??= $this->school;

        return StudentHealthRecord::create(array_merge([
            'institution_id' => $school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_id' => '109876543201',
            'student_name' => 'Penduko, Pedro',
            'school_name' => $school->name,
            'section' => 'Grade 8 / Sampaguita',
            'baseline_age' => 14,
            'baseline_height_cm' => 150,
            'baseline_weight_kg' => 35,
            'baseline_bmi_value' => 15.56,
            'baseline_nutritional_status' => 'Severely Wasted',
            'baseline_recorded_at' => '2026-07-02',
            'endline_age' => 14,
            'endline_height_cm' => 152,
            'endline_weight_kg' => 45,
            'endline_bmi_value' => 19.48,
            'endline_nutritional_status' => 'Normal',
            'endline_recorded_at' => '2026-09-30',
            'student_details' => [
                'first_name' => 'Pedro', 'last_name' => 'Penduko', 'middle_name' => 'Santos',
                'grade_level' => 'Grade 8', 'section' => 'Sampaguita',
                'birth_year' => 2011, 'birth_month' => 8, 'birth_day' => 20,
                'gender' => 'M',
                'parent_guardian' => 'Maria Penduko',
                'address' => '123 Mabini Street, Davao City',
                'telephone_no' => '09171234567',
                'nutritional_status_height_for_age' => 'Normal',
                'temperature_c' => '36.8',
                'pulse_bpm' => '82',
                'blood_pressure' => '110/70',
                'vitals_recorded_by' => 'Nurse Joy',
                'vitals_recorded_at' => '2026-09-30 09:15:00',
            ],
        ], $overrides));
    }

    private function resources(array $bundle, string $type): array
    {
        return array_values(array_map(
            fn ($entry) => $entry['resource'],
            array_filter($bundle['entry'], fn ($entry) => $entry['resource']['resourceType'] === $type)
        ));
    }

    private function observation(array $bundle, string $loinc, string $phaseText): ?array
    {
        foreach ($this->resources($bundle, 'Observation') as $obs) {
            if (($obs['code']['coding'][0]['code'] ?? null) === $loinc && str_contains($obs['code']['text'] ?? '', $phaseText)) {
                return $obs;
            }
        }

        return null;
    }

    private function transactionResponse(int $entries): array
    {
        return [
            'resourceType' => 'Bundle',
            'type' => 'transaction-response',
            'entry' => array_map(fn ($i) => ['response' => [
                'status' => '201 Created',
                'location' => 'Observation/'.(1000 + $i).'/_history/1',
            ]], range(1, $entries)),
        ];
    }

    #[Test]
    public function a_record_serialises_to_a_fhir_r4_transaction_bundle(): void
    {
        $bundle = FhirBundle::forRecord($this->learner(), deidentify: false);

        $this->assertSame('Bundle', $bundle['resourceType']);
        $this->assertSame('transaction', $bundle['type']);
        $this->assertSame(['Organization' => 1, 'Patient' => 1, 'Observation' => 13], FhirBundle::summary($bundle));

        $patient = $this->resources($bundle, 'Patient')[0];
        $this->assertSame('109876543201', $patient['identifier'][0]['value']);
        $this->assertSame('Penduko', $patient['name'][0]['family']);
        $this->assertSame(['Pedro', 'Santos'], $patient['name'][0]['given']);
        $this->assertSame('male', $patient['gender']);
        $this->assertSame('2011-08-20', $patient['birthDate']);

        $org = $this->resources($bundle, 'Organization')[0];
        $this->assertSame('San Antonio National High School', $org['name']);
        $this->assertSame('edu', $org['type'][0]['coding'][0]['code']);

        // The two weigh-ins, LOINC-coded with UCUM units.
        $this->assertSame(150.0, $this->observation($bundle, '8302-2', 'baseline')['valueQuantity']['value']);
        $this->assertSame('cm', $this->observation($bundle, '8302-2', 'baseline')['valueQuantity']['code']);
        $this->assertSame(45.0, $this->observation($bundle, '29463-7', 'endline')['valueQuantity']['value']);
        $this->assertSame('kg/m2', $this->observation($bundle, '39156-5', 'endline')['valueQuantity']['code']);
        $this->assertSame('2026-07-02', $this->observation($bundle, '8302-2', 'baseline')['effectiveDateTime']);
        $this->assertSame(['http://hl7.org/fhir/StructureDefinition/bmi'], $this->observation($bundle, '39156-5', 'baseline')['meta']['profile']);

        $statuses = collect($this->resources($bundle, 'Observation'))
            ->filter(fn ($o) => isset($o['valueCodeableConcept']))
            ->map(fn ($o) => $o['code']['text'].' = '.$o['valueCodeableConcept']['text'])
            ->values()->all();
        $this->assertContains('BMI-for-age nutritional status (baseline weigh-in) = Severely Wasted', $statuses);
        $this->assertContains('BMI-for-age nutritional status (endline weigh-in) = Normal', $statuses);

        // The nurse's vitals.
        $this->assertSame('Cel', $this->observation($bundle, '8310-5', 'Body temperature')['valueQuantity']['code']);
        $bp = $this->observation($bundle, '85354-9', 'Blood pressure');
        $this->assertSame([110.0, 70.0], array_map(fn ($c) => $c['valueQuantity']['value'], $bp['component']));

        // Every reference points at an entry in the bundle, and every entry is
        // a conditional update so a re-send updates rather than duplicates.
        $fullUrls = array_column($bundle['entry'], 'fullUrl');
        foreach ($bundle['entry'] as $entry) {
            $this->assertStringStartsWith('urn:uuid:', $entry['fullUrl']);
            $this->assertSame('PUT', $entry['request']['method']);
            $this->assertStringContainsString('?identifier=', $entry['request']['url']);
            array_walk_recursive($entry['resource'], function ($value, $key) use ($fullUrls) {
                if ($key === 'reference') {
                    $this->assertContains($value, $fullUrls);
                }
            });
        }
    }

    #[Test]
    public function by_default_the_patient_is_pseudonymised_and_nothing_unnecessary_is_ever_sent(): void
    {
        $record = $this->learner();
        $json = FhirBundle::encode(FhirBundle::forRecord($record));
        $patient = $this->resources(json_decode($json, true), 'Patient')[0];

        foreach (['Penduko', 'Pedro', '109876543201', 'Maria', 'Mabini', '09171234567'] as $identifying) {
            $this->assertStringNotContainsString($identifying, $json, "{$identifying} must not leave the school in pseudonymised mode.");
        }

        $this->assertArrayNotHasKey('name', $patient);
        $this->assertSame('2011', $patient['birthDate']);
        $this->assertSame(FhirBundle::pseudonym($record), $patient['identifier'][0]['value']);
        $this->assertContains('PSEUDED', array_column($patient['meta']['security'], 'code'));

        // Stable: the receiver can link two sends about the same learner.
        $again = $this->resources(FhirBundle::forRecord($record), 'Patient')[0];
        $this->assertSame($patient['identifier'][0]['value'], $again['identifier'][0]['value']);

        // Identified mode carries the learner — but still never the guardian,
        // the address or the phone number.
        $identified = FhirBundle::encode(FhirBundle::forRecord($record, deidentify: false));
        $this->assertStringContainsString('Penduko', $identified);
        foreach (['Maria', 'Mabini', '09171234567'] as $unnecessary) {
            $this->assertStringNotContainsString($unnecessary, $identified);
        }
    }

    #[Test]
    public function a_weigh_in_nobody_took_produces_no_observation(): void
    {
        $record = $this->learner([
            'endline_age' => null, 'endline_height_cm' => null, 'endline_weight_kg' => null,
            'endline_bmi_value' => null, 'endline_nutritional_status' => null, 'endline_recorded_at' => null,
        ]);

        $bundle = FhirBundle::forRecord($record);
        $texts = array_map(fn ($o) => $o['code']['text'], $this->resources($bundle, 'Observation'));

        $this->assertEmpty(array_filter($texts, fn ($t) => str_contains($t, 'endline')));
        $this->assertNotEmpty(array_filter($texts, fn ($t) => str_contains($t, 'baseline')));
    }

    #[Test]
    public function a_condition_is_confirmed_only_when_a_certificate_backs_it(): void
    {
        $record = $this->learner();
        $asthma = StudentHealthCondition::create(['student_lrn' => '109876543201', 'institution_id' => $this->school->id, 'condition_name' => 'Asthma']);
        StudentHealthCondition::create(['student_lrn' => '109876543201', 'institution_id' => $this->school->id, 'condition_name' => 'Allergic rhinitis']);
        MedicalCertificate::create([
            'student_health_condition_id' => $asthma->id,
            'student_lrn' => '109876543201',
            'institution_id' => $this->school->id,
            'file_path' => 'certificates/asthma.pdf',
            'file_original_name' => 'asthma.pdf',
            'uploaded_by_name' => 'Nurse Joy',
            'uploaded_by_role' => 'school_nurse',
        ]);

        $conditions = collect($this->resources(FhirBundle::forRecord($record), 'Condition'))
            ->mapWithKeys(fn ($c) => [$c['code']['text'] => $c['verificationStatus']['coding'][0]['code']]);

        $this->assertSame(['Asthma' => 'confirmed', 'Allergic rhinitis' => 'unconfirmed'], $conditions->all());
    }

    #[Test]
    public function the_nurse_transmits_over_https_and_the_disclosure_is_recorded_encrypted_and_audited(): void
    {
        config(['services.fhir.deidentify' => false]);
        $record = $this->learner();

        Http::fake([self::ENDPOINT => Http::response($this->transactionResponse(15), 200)]);

        $response = $this->withSession($this->nurse())
            ->post(route('dashboard.school-nurse.data-exchange.transmit', $record->student_id));

        $transmission = FhirTransmission::sole();
        $response->assertRedirect(route('dashboard.school-nurse.data-exchange.transmission', $transmission));

        Http::assertSent(function (ClientRequest $request) use ($transmission) {
            return $request->url() === self::ENDPOINT
                && $request->method() === 'POST'
                && str_starts_with($request->header('Content-Type')[0] ?? '', 'application/fhir+json')
                && $request->header('Authorization')[0] === 'Bearer super-secret-token'
                && $request->header('X-Request-ID')[0] === $transmission->uuid
                && $request->body() === $transmission->payload;
        });

        $this->assertSame('sent', $transmission->status);
        $this->assertSame(200, $transmission->http_status);
        $this->assertSame('fhir.example.test', $transmission->endpoint_host);
        $this->assertSame('manual', $transmission->trigger);
        $this->assertTrue($transmission->payloadIntact());
        $this->assertCount(15, $transmission->serverOutcomes());

        // Encrypted at rest: the stored payload and sender are ciphertext.
        $raw = DB::table('fhir_transmissions')->where('id', $transmission->id)->first();
        $this->assertStringNotContainsString('Penduko', (string) $raw->payload);
        $this->assertStringNotContainsString('Nurse Joy', (string) $raw->sent_by_name);
        $this->assertStringContainsString('Penduko', $transmission->payload);

        // Credentials never leave the environment.
        foreach ((array) $raw as $column => $value) {
            $this->assertStringNotContainsString('super-secret-token', (string) $value, "{$column} holds the token.");
        }

        $audit = AuditLog::where('action', 'transmitted')->sole();
        $this->assertSame('StudentHealthRecord', $audit->subject_type);
        $this->assertSame($record->id, (int) $audit->subject_id);
        $this->assertSame('nurse.joy', $audit->actor_username);
        $this->assertSame($transmission->payload_sha256, $audit->details['payload_sha256']);
        $this->assertSame('fhir.example.test', $audit->details['endpoint_host']);
        $this->assertStringNotContainsString('Penduko', json_encode($audit->details).$audit->description);
        $this->assertStringNotContainsString('super-secret-token', json_encode($audit->details));
    }

    #[Test]
    public function an_endpoint_that_is_not_https_is_refused_and_nothing_is_sent(): void
    {
        config(['services.fhir.endpoint' => 'http://fhir.example.test/baseR4']);
        Http::fake();
        $record = $this->learner();

        $this->withSession($this->nurse())
            ->post(route('dashboard.school-nurse.data-exchange.transmit', $record->student_id))
            ->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertSame('blocked', FhirTransmission::sole()->status);
        $this->assertTrue(AuditLog::where('action', 'transmission_blocked')->exists());
    }

    #[Test]
    public function with_no_endpoint_nothing_is_sent_and_nothing_is_recorded(): void
    {
        config(['services.fhir.endpoint' => '']);
        Http::fake();
        $record = $this->learner();

        $this->withSession($this->nurse())
            ->from(route('dashboard.school-nurse.data-exchange.preview', $record->student_id))
            ->post(route('dashboard.school-nurse.data-exchange.transmit', $record->student_id))
            ->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertSame(0, FhirTransmission::count());
    }

    #[Test]
    public function a_rejected_bundle_is_recorded_as_failed_with_the_servers_reason(): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            'resourceType' => 'OperationOutcome',
            'issue' => [['severity' => 'error', 'code' => 'processing', 'diagnostics' => 'Unknown code system']],
        ], 422)]);

        $this->withSession($this->nurse())
            ->post(route('dashboard.school-nurse.data-exchange.transmit', $this->learner()->student_id));

        $transmission = FhirTransmission::sole();
        $this->assertSame('failed', $transmission->status);
        $this->assertSame(422, $transmission->http_status);
        $this->assertStringContainsString('Unknown code system', $transmission->error_message);
        $this->assertTrue(AuditLog::where('action', 'transmission_failed')->exists());
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_busy_server_is_retried_because_every_entry_is_idempotent(): void
    {
        Http::fakeSequence(self::ENDPOINT)
            ->push(['resourceType' => 'OperationOutcome'], 503)
            ->push($this->transactionResponse(3), 200);

        $this->withSession($this->nurse())
            ->post(route('dashboard.school-nurse.data-exchange.transmit', $this->learner()->student_id));

        $this->assertSame('sent', FhirTransmission::sole()->status);
        Http::assertSentCount(2);
    }

    #[Test]
    public function only_the_school_nurse_can_reach_the_exchange(): void
    {
        $record = $this->learner();
        Http::fake();

        foreach (['clinic_staff', 'class_adviser', 'school_head', 'feeding_coor', 'nutricor', 'system_admin'] as $role) {
            $session = ['active_role' => $role, 'active_institution_id' => $this->school->id, 'active_name' => 'X'];

            $this->withSession($session)->get('/dashboard/school-nurse/data-exchange/learners/'.$record->student_id.'/download')->assertForbidden();
            $this->withSession($session)->post('/dashboard/school-nurse/data-exchange/learners/'.$record->student_id.'/transmit')->assertForbidden();
        }

        Http::assertNothingSent();
        $this->assertSame(0, FhirTransmission::count());
    }

    #[Test]
    public function another_schools_learner_and_transmissions_are_not_found(): void
    {
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $theirs = $this->learner(['student_id' => '999999999999'], $other);

        Http::fake([self::ENDPOINT => Http::response($this->transactionResponse(1), 200)]);
        $this->withSession($this->nurse($other))
            ->post(route('dashboard.school-nurse.data-exchange.transmit', $theirs->student_id));
        $theirTransmission = FhirTransmission::sole();

        $this->withSession($this->nurse())->get(route('dashboard.school-nurse.data-exchange.preview', $theirs->student_id))->assertNotFound();
        $this->withSession($this->nurse())->post(route('dashboard.school-nurse.data-exchange.transmit', $theirs->student_id))->assertNotFound();
        $this->withSession($this->nurse())->get(route('dashboard.school-nurse.data-exchange.transmission', $theirTransmission))->assertNotFound();

        Http::assertSentCount(1);
    }

    #[Test]
    public function the_exchange_screens_render_for_the_nurse(): void
    {
        $record = $this->learner();
        Http::fake([self::ENDPOINT => Http::response($this->transactionResponse(15), 200)]);

        $this->withSession($this->nurse())
            ->get(route('dashboard.school-nurse.data-exchange'))
            ->assertOk()
            ->assertSee('Health Data <span>Exchange</span>', false)
            ->assertSee('Penduko, Pedro')
            ->assertSee(route('dashboard.school-nurse.data-exchange.preview', '109876543201'), false)
            ->assertSee('fhir.example.test');

        $this->withSession($this->nurse())
            ->get(route('dashboard.school-nurse.data-exchange.preview', $record->student_id))
            ->assertOk()
            ->assertSee('&quot;resourceType&quot;: &quot;Bundle&quot;', false)
            ->assertSee('Pseudonymised');

        $download = $this->withSession($this->nurse())
            ->get(route('dashboard.school-nurse.data-exchange.download', $record->student_id))
            ->assertOk();
        $this->assertStringStartsWith('application/fhir+json', $download->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', $download->headers->get('Content-Disposition'));
        $this->assertSame('transaction', json_decode($download->getContent(), true)['type']);
        $this->assertTrue(AuditLog::where('action', 'downloaded')->where('description', 'like', '%FHIR%')->exists());

        $this->withSession($this->nurse())
            ->post(route('dashboard.school-nurse.data-exchange.transmit', $record->student_id));

        $this->withSession($this->nurse())
            ->get(route('dashboard.school-nurse.data-exchange.transmission', FhirTransmission::sole()))
            ->assertOk()
            ->assertSee('Verified')
            ->assertSee('201 Created')
            ->assertSee('Observation/1001/_history/1');
    }

    #[Test]
    public function the_nurse_rail_links_to_the_exchange(): void
    {
        $this->withSession($this->nurse())
            ->get(route('dashboard.school-nurse.data-exchange'))
            ->assertSee('Health Data Exchange');
    }

    #[Test]
    public function with_auto_transmit_on_an_examination_sends_the_record_after_the_response(): void
    {
        config(['services.fhir.auto_transmit' => true]);
        $record = $this->learner();
        Http::fake([self::ENDPOINT => Http::response($this->transactionResponse(15), 200)]);

        $session = $this->nurse() + ['school_health_card_records' => [[
            'lrn' => '109876543201', 'last_name' => 'Penduko', 'first_name' => 'Pedro',
            'grade_level' => 'Grade 8', 'section' => 'Sampaguita',
            'nutritional_status_bmi_for_age' => 'Normal',
        ]]];

        $this->withSession($session)
            ->post(route('nurse.examine.save', 0), ['date_of_examination' => '2026-09-30'])
            ->assertRedirect();

        $transmission = FhirTransmission::sole();
        $this->assertSame('examination', $transmission->trigger);
        $this->assertSame('sent', $transmission->status);
        $this->assertSame($record->id, (int) $transmission->student_health_record_id);
    }

    #[Test]
    public function with_auto_transmit_off_an_examination_sends_nothing(): void
    {
        $this->learner();
        Http::fake();

        $session = $this->nurse() + ['school_health_card_records' => [[
            'lrn' => '109876543201', 'last_name' => 'Penduko', 'first_name' => 'Pedro',
        ]]];

        $this->withSession($session)->post(route('nurse.examine.save', 0), ['date_of_examination' => '2026-09-30']);

        Http::assertNothingSent();
        $this->assertSame(0, FhirTransmission::count());
    }

    #[Test]
    public function the_console_command_previews_and_transmits_through_the_same_path(): void
    {
        $this->learner();
        Http::fake([self::ENDPOINT => Http::response($this->transactionResponse(15), 201)]);

        $this->artisan('fhir:transmit', ['lrn' => '109876543201', '--institution' => $this->school->id, '--preview' => true])
            ->expectsOutputToContain('"resourceType": "Bundle"')
            ->assertSuccessful();
        Http::assertNothingSent();

        $this->artisan('fhir:transmit', ['lrn' => '109876543201', '--institution' => $this->school->id])
            ->assertSuccessful();

        $transmission = FhirTransmission::sole();
        $this->assertSame('console', $transmission->trigger);
        $this->assertSame('sent', $transmission->status);

        $this->artisan('fhir:transmit', ['lrn' => '109876543201'])->assertFailed();
    }

    #[Test]
    public function the_retention_purge_removes_the_stored_copies_of_what_was_sent(): void
    {
        $record = $this->learner();
        Http::fake([self::ENDPOINT => Http::response($this->transactionResponse(1), 200)]);
        $this->withSession($this->nurse())->post(route('dashboard.school-nurse.data-exchange.transmit', $record->student_id));
        $this->assertSame(1, FhirTransmission::count());

        StudentRecordPurge::purge($this->school->id, ['109876543201'], 'test');

        $this->assertSame(0, FhirTransmission::count());
        // The fact of the disclosure stays on the append-only trail.
        $this->assertTrue(AuditLog::where('action', 'transmitted')->exists());
    }
}
