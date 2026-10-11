# Sample FHIR bundles

Two HL7 FHIR R4 transaction Bundles exported by `App\Support\FhirBundle::forRecord()` and `FhirBundle::encode()`, the same serializer the School Nurse's Health Data Exchange download and `FhirTransmitter` use. See [../fhir-interoperability.md](../fhir-interoperability.md) for how they are built and sent.

The learner is synthetic: Pedro Penduko, LRN 109876543201, the fixture from `tests/Feature/FhirInteroperabilityTest.php`. Neither file holds a real learner's data.

| File | Mode | The Patient carries |
|---|---|---|
| `sample-bundle.json` | identified (`FHIR_DEIDENTIFY=false`) | name, LRN, birth date |
| `sample-bundle-pseudonymised.json` | default (`FHIR_DEIDENTIFY=true`) | an HMAC pseudonym, sex and birth year, labelled `PSEUDED` |

Each bundle holds 16 entries: 1 Organization, 1 Patient, 13 Observations (baseline and endline height, weight, BMI, BMI-for-age and height-for-age status; temperature, pulse and blood pressure) and 1 Condition.

Both were checked with HAPI FHIR's `Bundle/$validate` at `https://hapi.fhir.org/baseR4` on 2026-10-12: **0 errors, 0 fatal**. The 20 warnings in each are the public validator not having LOINC loaded, LUSOG's own code systems being unpublished (known limit, see the interoperability doc §7), and `Patient.identifier.type` carrying text without a code.

The pseudonym in the pseudonymised file was computed under a throwaway key, not the deployment's `APP_KEY`.
