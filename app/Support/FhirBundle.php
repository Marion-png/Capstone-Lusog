<?php

namespace App\Support;

use App\Models\Institution;
use App\Models\StudentHealthCondition;
use App\Models\StudentHealthRecord;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * One learner's record as an HL7 FHIR R4 transaction Bundle.
 *
 * Built at the moment it is asked for, from the rows this application already
 * holds — never from a stored copy — so a bundle always says what the record
 * says now. It reads through the same classes the screens do: the two
 * weigh-ins through NutritionalHealthStatus (so a bundle cannot classify a
 * child differently from the profile), the nurse's readings through
 * StudentVitalSigns, sex through FeedingBeneficiarySummary::normalizeSex().
 *
 * What goes in:
 *   - Organization — the school (no personal information).
 *   - Patient — the learner.
 *   - Observation — height, weight and BMI for each weigh-in that was taken,
 *     the BMI-for-age and height-for-age classifications beside them, and the
 *     nurse's temperature, pulse and blood pressure.
 *   - Condition — each health condition on the learner's card, confirmed when
 *     a medical certificate backs it and unconfirmed when it does not.
 *
 * **A reading nobody took is left out, never sent as a value.** The same rule
 * every screen keeps: an unmeasured phase produces no Observation at all.
 *
 * **Minimum necessary.** The guardian, the home address, the contact number,
 * the clinic's notes and the consent answers are never put in a bundle in
 * either mode. With de-identification on (the default) the Patient also
 * carries no name and no LRN — only a keyed pseudonym, sex and birth year —
 * and is labelled PSEUDED so the receiver knows what it holds.
 *
 * **Re-sending is safe.** Every entry is a conditional update keyed on an
 * identifier minted here, so a server that already holds the learner updates
 * them instead of creating a duplicate, and the transmitter may retry a
 * request that timed out.
 */
final class FhirBundle
{
    public const FHIR_VERSION = '4.0.1';

    public const MIME = 'application/fhir+json';

    private const LOINC = 'http://loinc.org';

    private const UCUM = 'http://unitsofmeasure.org';

    private const OBSERVATION_CATEGORY = 'http://terminology.hl7.org/CodeSystem/observation-category';

    private const CONFIDENTIALITY = 'http://terminology.hl7.org/CodeSystem/v3-Confidentiality';

    private const SECURITY_OBSERVATION = 'http://terminology.hl7.org/CodeSystem/v3-ObservationValue';

    private const VITALS_PROFILE = 'http://hl7.org/fhir/StructureDefinition/';

    /**
     * The measurements of one weigh-in: the field NutritionalHealthStatus
     * reports it under, its LOINC code, UCUM unit and vital-signs profile.
     */
    private const MEASUREMENTS = [
        'height_cm' => ['key' => 'body-height', 'loinc' => '8302-2', 'display' => 'Body height', 'unit' => 'cm', 'ucum' => 'cm', 'profile' => 'bodyheight'],
        'weight_kg' => ['key' => 'body-weight', 'loinc' => '29463-7', 'display' => 'Body weight', 'unit' => 'kg', 'ucum' => 'kg', 'profile' => 'bodyweight'],
        'bmi' => ['key' => 'bmi', 'loinc' => '39156-5', 'display' => 'Body mass index (BMI) [Ratio]', 'unit' => 'kg/m2', 'ucum' => 'kg/m2', 'profile' => 'bmi'],
    ];

    /** The two classifications, coded in this application's own CodeSystem. */
    private const CLASSIFICATIONS = [
        'bmi_status' => ['key' => 'bmi-for-age-status', 'display' => 'BMI-for-age nutritional status'],
        'hfa_status' => ['key' => 'height-for-age-status', 'display' => 'Height-for-age nutritional status'],
    ];

    private const PHASES = [
        'baseline' => 'baseline weigh-in',
        'endline' => 'endline weigh-in',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function forRecord(StudentHealthRecord $record, ?bool $deidentify = null): array
    {
        $deidentify ??= self::deidentifies();
        $base = self::systemBase();

        $organizationUrn = self::urn();
        $patientUrn = self::urn();

        $entries = [
            self::entry($organizationUrn, self::organization($record, $base), 'Organization', $base.'/sid/institution', (string) $record->institution_id),
        ];

        [$patient, $patientSystem, $patientValue] = self::patient($record, $base, $organizationUrn, $deidentify);
        $entries[] = self::entry($patientUrn, $patient, 'Patient', $patientSystem, $patientValue);

        foreach (self::observations($record, $base, $patientUrn) as [$urn, $resource, $identifier]) {
            $entries[] = self::entry($urn, $resource, 'Observation', $base.'/sid/observation', $identifier);
        }

        foreach (self::conditions($record, $base, $patientUrn) as [$urn, $resource, $identifier]) {
            $entries[] = self::entry($urn, $resource, 'Condition', $base.'/sid/condition', $identifier);
        }

        foreach ($entries as $i => $entry) {
            $entries[$i]['resource'] = self::finish($entry['resource'], $organizationUrn);
        }

        return [
            'resourceType' => 'Bundle',
            'meta' => [
                'lastUpdated' => now()->toIso8601String(),
                'security' => self::securityLabels($deidentify),
            ],
            'identifier' => [
                'system' => $base.'/sid/bundle',
                'value' => (string) Str::uuid(),
            ],
            'type' => 'transaction',
            'timestamp' => now()->toIso8601String(),
            'entry' => $entries,
        ];
    }

    public static function encode(array $bundle): string
    {
        return json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /**
     * How many of each resource the bundle carries, in the order they appear.
     *
     * @return array<string, int>
     */
    public static function summary(array $bundle): array
    {
        $counts = [];

        foreach ($bundle['entry'] ?? [] as $entry) {
            $type = (string) ($entry['resource']['resourceType'] ?? 'Unknown');
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        return $counts;
    }

    public static function deidentifies(): bool
    {
        return (bool) config('services.fhir.deidentify', true);
    }

    public static function systemBase(): string
    {
        return rtrim((string) config('services.fhir.system_base'), '/');
    }

    /**
     * The learner's pseudonym: an HMAC of school and LRN under a key derived
     * from APP_KEY. Stable, so a receiver can link two transmissions about one
     * learner; one-way without the key, so the receiver cannot recover the LRN
     * from it. The school is in the input because an LRN is only unique
     * within the scope this application keys a record by.
     */
    public static function pseudonym(StudentHealthRecord $record): string
    {
        $key = hash_hmac('sha256', 'lusog-fhir-pseudonym', (string) config('app.key'));

        return hash_hmac('sha256', $record->institution_id.'|'.$record->student_id, $key);
    }

    /**
     * @return array<string, mixed>
     */
    private static function organization(StudentHealthRecord $record, string $base): array
    {
        $institution = $record->institution_id ? Institution::query()->find($record->institution_id) : null;
        $name = trim((string) ($institution?->name ?: $record->school_name));
        $address = trim((string) ($institution?->address ?? ''));

        return array_filter([
            'resourceType' => 'Organization',
            'identifier' => [[
                'system' => $base.'/sid/institution',
                'value' => (string) $record->institution_id,
            ]],
            'active' => true,
            'type' => [[
                'coding' => [[
                    'system' => 'http://terminology.hl7.org/CodeSystem/organization-type',
                    'code' => 'edu',
                    'display' => 'Educational Institute',
                ]],
            ]],
            'name' => $name !== '' ? $name : null,
            'address' => $address !== '' ? [['text' => $address, 'country' => 'PH']] : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @return array{0: array<string, mixed>, 1: string, 2: string}
     */
    private static function patient(StudentHealthRecord $record, string $base, string $organizationUrn, bool $deidentify): array
    {
        $details = is_array($record->student_details) ? $record->student_details : [];
        $gender = match (FeedingBeneficiarySummary::normalizeSex((string) ($details['gender'] ?? ''))) {
            'Male' => 'male',
            'Female' => 'female',
            default => 'unknown',
        };

        $birthDate = self::birthDate($details);
        $schoolName = trim((string) $record->school_name);

        if ($deidentify) {
            $system = $base.'/sid/learner-pseudonym';
            $value = self::pseudonym($record);

            $patient = array_filter([
                'resourceType' => 'Patient',
                'meta' => ['security' => self::securityLabels(true)],
                'identifier' => [[
                    'use' => 'secondary',
                    'type' => ['text' => 'LUSOG pseudonymous learner key'],
                    'system' => $system,
                    'value' => $value,
                ]],
                'active' => true,
                'gender' => $gender,
                // The year alone: enough to read a growth reading against, not
                // enough to single a child out.
                'birthDate' => $birthDate !== null ? substr($birthDate, 0, 4) : null,
                'managingOrganization' => array_filter(['reference' => $organizationUrn, 'display' => $schoolName ?: null]),
            ], fn ($v) => $v !== null);

            return [$patient, $system, $value];
        }

        $system = $base.'/sid/lrn';
        $value = (string) $record->student_id;

        $patient = array_filter([
            'resourceType' => 'Patient',
            'meta' => ['security' => self::securityLabels(false)],
            'identifier' => [[
                'use' => 'official',
                'type' => ['text' => 'DepEd Learner Reference Number'],
                'system' => $system,
                'value' => $value,
            ]],
            'active' => true,
            'name' => self::name($record, $details),
            'gender' => $gender,
            'birthDate' => $birthDate,
            'managingOrganization' => array_filter(['reference' => $organizationUrn, 'display' => $schoolName ?: null]),
        ], fn ($v) => $v !== null);

        return [$patient, $system, $value];
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private static function name(StudentHealthRecord $record, array $details): ?array
    {
        $family = trim((string) ($details['last_name'] ?? ''));
        $given = array_values(array_filter([
            trim((string) ($details['first_name'] ?? '')),
            trim((string) ($details['middle_name'] ?? '')),
        ], fn (string $part) => $part !== ''));

        // A record imported before the card was split into parts carries the
        // roster's "Last, First" string only.
        if ($family === '' && $given === []) {
            $full = trim((string) $record->student_name);
            if ($full === '') {
                return null;
            }
            if (str_contains($full, ',')) {
                [$family, $rest] = array_map('trim', explode(',', $full, 2));
                $given = array_values(array_filter(preg_split('/\s+/', $rest) ?: [], fn ($p) => $p !== ''));
            } else {
                return [['use' => 'official', 'text' => $full]];
            }
        }

        return [array_filter([
            'use' => 'official',
            'text' => trim(implode(' ', $given).' '.$family),
            'family' => $family !== '' ? $family : null,
            'given' => $given !== [] ? $given : null,
        ], fn ($v) => $v !== null)];
    }

    private static function birthDate(array $details): ?string
    {
        $year = (int) ($details['birth_year'] ?? 0);
        $month = (int) ($details['birth_month'] ?? 0);
        $day = (int) ($details['birth_day'] ?? 0);

        if ($year < 1900 || ! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * @return list<array{0: string, 1: array<string, mixed>, 2: string}>
     */
    private static function observations(StudentHealthRecord $record, string $base, string $patientUrn): array
    {
        $out = [];
        $nutrition = NutritionalHealthStatus::forRecord($record);

        foreach (self::PHASES as $phase => $phaseLabel) {
            $reading = $nutrition[$phase];
            if (! $reading['measured']) {
                continue;
            }

            $effective = self::dateTime($reading['recorded_at'] ?? '');
            $bmiUrn = null;

            foreach (self::MEASUREMENTS as $field => $spec) {
                if (! is_numeric($reading[$field] ?? null)) {
                    continue;
                }

                $urn = self::urn();
                $identifier = $record->id.'-'.$phase.'-'.$spec['key'];

                $out[] = [$urn, self::quantityObservation(
                    $base, $identifier, $patientUrn, $effective,
                    $spec['loinc'], $spec['display'], $spec['display'].' ('.$phaseLabel.')',
                    (float) $reading[$field], $spec['unit'], $spec['ucum'], $spec['profile'],
                ), $identifier];

                if ($field === 'bmi') {
                    $bmiUrn = $urn;
                }
            }

            foreach (self::CLASSIFICATIONS as $field => $spec) {
                $value = trim((string) ($reading[$field] ?? ''));
                if ($value === '') {
                    continue;
                }

                $identifier = $record->id.'-'.$phase.'-'.$spec['key'];
                $resource = array_filter([
                    'resourceType' => 'Observation',
                    'identifier' => [['system' => $base.'/sid/observation', 'value' => $identifier]],
                    'status' => 'final',
                    'category' => [self::category('exam', 'Exam')],
                    'code' => [
                        'coding' => [[
                            'system' => $base.'/CodeSystem/observation',
                            'code' => $spec['key'],
                            'display' => $spec['display'],
                        ]],
                        'text' => $spec['display'].' ('.$phaseLabel.')',
                    ],
                    'subject' => ['reference' => $patientUrn],
                    'effectiveDateTime' => $effective,
                    'valueCodeableConcept' => [
                        'coding' => [[
                            'system' => $base.'/CodeSystem/nutritional-status',
                            'code' => Str::slug($value),
                            'display' => $value,
                        ]],
                        'text' => $value,
                    ],
                    'derivedFrom' => ($field === 'bmi_status' && $bmiUrn !== null) ? [['reference' => $bmiUrn]] : null,
                ], fn ($v) => $v !== null);

                $out[] = [self::urn(), $resource, $identifier];
            }
        }

        foreach (self::vitalSigns($record, $base, $patientUrn) as $vital) {
            $out[] = $vital;
        }

        return $out;
    }

    /**
     * The nurse's three readings, each its own vital-signs Observation.
     *
     * @return list<array{0: string, 1: array<string, mixed>, 2: string}>
     */
    private static function vitalSigns(StudentHealthRecord $record, string $base, string $patientUrn): array
    {
        $vitals = StudentVitalSigns::read($record);
        if (! $vitals['has_any']) {
            return [];
        }

        $effective = self::dateTime((string) ($vitals['recorded_at'] ?? ''));
        $out = [];

        if (is_numeric($vitals['temperature_c'])) {
            $identifier = $record->id.'-vitals-body-temperature';
            $out[] = [self::urn(), self::quantityObservation(
                $base, $identifier, $patientUrn, $effective,
                '8310-5', 'Body temperature', 'Body temperature',
                (float) $vitals['temperature_c'], 'Cel', 'Cel', 'bodytemp',
            ), $identifier];
        }

        if (is_numeric($vitals['pulse_bpm'])) {
            $identifier = $record->id.'-vitals-heart-rate';
            $out[] = [self::urn(), self::quantityObservation(
                $base, $identifier, $patientUrn, $effective,
                '8867-4', 'Heart rate', 'Pulse rate',
                (float) $vitals['pulse_bpm'], 'beats/minute', '/min', 'heartrate',
            ), $identifier];
        }

        if (preg_match('/^\s*(\d{2,3})\s*\/\s*(\d{2,3})\s*$/', (string) $vitals['blood_pressure'], $bp)) {
            $identifier = $record->id.'-vitals-blood-pressure';
            $component = fn (string $code, string $display, string $value) => [
                'code' => ['coding' => [['system' => self::LOINC, 'code' => $code, 'display' => $display]]],
                'valueQuantity' => ['value' => (float) $value, 'unit' => 'mmHg', 'system' => self::UCUM, 'code' => 'mm[Hg]'],
            ];

            $out[] = [self::urn(), array_filter([
                'resourceType' => 'Observation',
                'meta' => $effective !== null ? ['profile' => [self::VITALS_PROFILE.'bp']] : null,
                'identifier' => [['system' => $base.'/sid/observation', 'value' => $identifier]],
                'status' => 'final',
                'category' => [self::category('vital-signs', 'Vital Signs')],
                'code' => [
                    'coding' => [['system' => self::LOINC, 'code' => '85354-9', 'display' => 'Blood pressure panel with all children optional']],
                    'text' => 'Blood pressure',
                ],
                'subject' => ['reference' => $patientUrn],
                'effectiveDateTime' => $effective,
                'component' => [
                    $component('8480-6', 'Systolic blood pressure', $bp[1]),
                    $component('8462-4', 'Diastolic blood pressure', $bp[2]),
                ],
            ], fn ($v) => $v !== null), $identifier];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function quantityObservation(
        string $base,
        string $identifier,
        string $patientUrn,
        ?string $effective,
        string $loinc,
        string $display,
        string $text,
        float $value,
        string $unit,
        string $ucum,
        string $profile,
    ): array {
        return array_filter([
            'resourceType' => 'Observation',
            // The vital-signs profile requires a time; a reading with no date
            // on file is sent as a plain Observation rather than claiming a
            // profile it does not meet.
            'meta' => $effective !== null ? ['profile' => [self::VITALS_PROFILE.$profile]] : null,
            'identifier' => [['system' => $base.'/sid/observation', 'value' => $identifier]],
            'status' => 'final',
            'category' => [self::category('vital-signs', 'Vital Signs')],
            'code' => [
                'coding' => [['system' => self::LOINC, 'code' => $loinc, 'display' => $display]],
                'text' => $text,
            ],
            'subject' => ['reference' => $patientUrn],
            'effectiveDateTime' => $effective,
            'valueQuantity' => [
                'value' => $value,
                'unit' => $unit,
                'system' => self::UCUM,
                'code' => $ucum,
            ],
        ], fn ($v) => $v !== null);
    }

    /**
     * @return list<array{0: string, 1: array<string, mixed>, 2: string}>
     */
    private static function conditions(StudentHealthRecord $record, string $base, string $patientUrn): array
    {
        if (! SchemaCache::hasTable('student_health_conditions') || (string) $record->student_id === '') {
            return [];
        }

        $hasCertificates = SchemaCache::hasTable('medical_certificates')
            && SchemaCache::hasColumn('medical_certificates', 'student_health_condition_id');

        $conditions = StudentHealthCondition::query()
            ->forStudent((string) $record->student_id, $record->institution_id ? (int) $record->institution_id : null)
            ->when($hasCertificates, fn ($q) => $q->withCount('certificates'))
            ->orderBy('id')
            ->get();

        $out = [];

        foreach ($conditions as $condition) {
            $name = trim((string) $condition->condition_name);
            if ($name === '') {
                continue;
            }

            $verified = $hasCertificates && (int) ($condition->certificates_count ?? 0) > 0;
            $identifier = 'condition-'.$condition->id;

            $out[] = [self::urn(), array_filter([
                'resourceType' => 'Condition',
                'identifier' => [['system' => $base.'/sid/condition', 'value' => $identifier]],
                'clinicalStatus' => ['coding' => [[
                    'system' => 'http://terminology.hl7.org/CodeSystem/condition-clinical',
                    'code' => 'active',
                    'display' => 'Active',
                ]]],
                // Backed by a medical certificate on file, or only reported.
                'verificationStatus' => ['coding' => [[
                    'system' => 'http://terminology.hl7.org/CodeSystem/condition-ver-status',
                    'code' => $verified ? 'confirmed' : 'unconfirmed',
                    'display' => $verified ? 'Confirmed' : 'Unconfirmed',
                ]]],
                'category' => [['coding' => [[
                    'system' => 'http://terminology.hl7.org/CodeSystem/condition-category',
                    'code' => 'problem-list-item',
                    'display' => 'Problem List Item',
                ]]]],
                'code' => ['text' => $name],
                'subject' => ['reference' => $patientUrn],
                'recordedDate' => $condition->created_at?->toIso8601String(),
            ], fn ($v) => $v !== null), $identifier];
        }

        return $out;
    }

    /**
     * The two things every resource gets once it is built: a one-line
     * human-readable narrative (FHIR's dom-6 — a receiver with no renderer
     * for our codes can still show what the resource says), and, for an
     * Observation, the school as the performer. The narrative is made from
     * the resource itself, so it can never say more than the resource does —
     * a pseudonymised Patient's narrative has no name to print.
     *
     * @return array<string, mixed>
     */
    private static function finish(array $resource, string $organizationUrn): array
    {
        if ($resource['resourceType'] === 'Observation' && ! isset($resource['performer'])) {
            $resource = self::insertAfter($resource, 'subject', 'performer', [['reference' => $organizationUrn]]);
        }

        return self::insertAfter(
            $resource,
            isset($resource['meta']) ? 'meta' : 'resourceType',
            'text',
            [
                'status' => 'generated',
                'div' => '<div xmlns="http://www.w3.org/1999/xhtml"><p>'
                    .htmlspecialchars(self::narrative($resource), ENT_QUOTES | ENT_XML1, 'UTF-8')
                    .'</p></div>',
            ],
        );
    }

    private static function narrative(array $resource): string
    {
        $born = isset($resource['birthDate']) ? ' · born '.$resource['birthDate'] : '';

        return match ($resource['resourceType']) {
            'Organization' => (string) ($resource['name'] ?? 'School'),
            'Patient' => isset($resource['name'][0]['text'])
                ? $resource['name'][0]['text'].' · '.$resource['gender'].$born
                : 'Pseudonymised learner · '.$resource['gender'].$born,
            'Observation' => ($resource['code']['text'] ?? 'Observation').': '.self::observationValue($resource)
                .(isset($resource['effectiveDateTime']) ? ' ('.$resource['effectiveDateTime'].')' : ''),
            'Condition' => ($resource['code']['text'] ?? 'Condition')
                .' — '.($resource['verificationStatus']['coding'][0]['display'] ?? ''),
            default => (string) $resource['resourceType'],
        };
    }

    private static function observationValue(array $resource): string
    {
        $quantity = fn (array $q) => rtrim(rtrim(number_format((float) $q['value'], 2, '.', ''), '0'), '.').' '.$q['unit'];

        return match (true) {
            isset($resource['valueQuantity']) => $quantity($resource['valueQuantity']),
            isset($resource['valueCodeableConcept']) => (string) ($resource['valueCodeableConcept']['text'] ?? ''),
            isset($resource['component']) => implode('/', array_map(
                fn ($c) => rtrim(rtrim(number_format((float) $c['valueQuantity']['value'], 2, '.', ''), '0'), '.'),
                $resource['component']
            )).' '.($resource['component'][0]['valueQuantity']['unit'] ?? ''),
            default => '',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function insertAfter(array $resource, string $after, string $key, mixed $value): array
    {
        $out = [];
        foreach ($resource as $k => $v) {
            $out[$k] = $v;
            if ($k === $after) {
                $out[$key] = $value;
            }
        }

        if (! array_key_exists($key, $out)) {
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * A conditional update: the receiver matches the resource on its
     * identifier and updates it, or creates it if it holds none.
     *
     * @return array<string, mixed>
     */
    private static function entry(string $fullUrl, array $resource, string $type, string $system, string $value): array
    {
        return [
            'fullUrl' => $fullUrl,
            'resource' => $resource,
            'request' => [
                'method' => 'PUT',
                'url' => $type.'?identifier='.rawurlencode($system.'|'.$value),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function category(string $code, string $display): array
    {
        return [
            'coding' => [['system' => self::OBSERVATION_CATEGORY, 'code' => $code, 'display' => $display]],
            'text' => $display,
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private static function securityLabels(bool $deidentify): array
    {
        $labels = [[
            'system' => self::CONFIDENTIALITY,
            'code' => 'R',
            'display' => 'restricted',
        ]];

        if ($deidentify) {
            $labels[] = [
                'system' => self::SECURITY_OBSERVATION,
                'code' => 'PSEUDED',
                'display' => 'pseudonymized',
            ];
        }

        return $labels;
    }

    /**
     * A date on file as a FHIR dateTime: a bare date stays a date (that is
     * all anybody recorded), a timestamp gains the application's offset.
     */
    private static function dateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private static function urn(): string
    {
        return 'urn:uuid:'.Str::uuid();
    }
}
