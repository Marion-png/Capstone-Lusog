# HL7 FHIR Interoperability

How LUSOG takes a learner's health record out of its own database, turns it into a standard HL7 FHIR R4 message, and sends it to another health system over HTTPS, while staying inside the Data Privacy Act of 2012 (RA 10173).

**Route chosen: native backend.** The exchange is built into the Laravel application itself (PHP 8.4, Laravel's HTTP client over Guzzle/cURL). There is no Mirth Connect channel: the app already holds the data, the encryption key and the access rules, so serialising and sending from inside it means the record never passes through a second system that would also need securing.

---

## 1. How it works

```
 School Nurse clicks Transmit             (or: nurse saves an examination with FHIR_AUTO_TRANSMIT=true,
          │                                     or: php artisan fhir:transmit <LRN> --institution=<id>)
          ▼
 FhirExchangeController ── role = school_nurse? same school? ── no ──► 403 / 404
          │ yes
          ▼
 FhirTransmitter::transmit()
   1. FhirBundle::forRecord()  ── reads student_health_records (decrypted in memory),
                                  student_health_conditions, medical_certificates, institutions
   2. JSON-encode, SHA-256 the bytes
   3. INSERT fhir_transmissions (status = pending, payload encrypted)      ◄── evidence first
   4. endpoint is https:// ? ── no ──► status = blocked, audit "transmission_blocked", stop
   5. POST <FHIR_ENDPOINT>   Content-Type: application/fhir+json
                             Authorization: Bearer … | Basic …   X-Request-ID: <uuid>
                             TLS ≥ 1.2, certificate verified, 3 tries on 408/429/5xx
   6. UPDATE fhir_transmissions (sent | failed, HTTP status, server reply encrypted)
   7. INSERT audit_logs  "transmitted" | "transmission_failed"  (host, SHA-256, HTTP status: no payload)
```

| Piece | File |
|---|---|
| Serialiser (record → FHIR Bundle) | `app/Support/FhirBundle.php` |
| Transmitter (HTTPS, retry, recording, audit) | `app/Support/FhirTransmitter.php` |
| Transmission record (encrypted) | `app/Models/FhirTransmission.php`, migration `2026_10_10_000001` |
| Nurse screens | `app/Http/Controllers/FhirExchangeController.php`, `resources/views/dashboard/data-exchange*.blade.php` |
| Automatic send after an examination | `app/Jobs/TransmitFhirRecord.php`, hooked in `NurseController::saveExamination` |
| Command line | `php artisan fhir:transmit`, `php artisan audit:verify` |
| Tests | `tests/Feature/FhirInteroperabilityTest.php`, `tests/Feature/AuditLogImmutabilityTest.php` |

## 2. What the FHIR payload contains

One **transaction Bundle** (`Bundle.type = transaction`) per learner, built from the database when it is requested. Nothing is pre-stored or typed by hand.

| LUSOG data | FHIR resource | Coding |
|---|---|---|
| The school (`institutions`) | `Organization` | `organization-type#edu` |
| The learner (`student_health_records.student_details`) | `Patient` | identifier = LRN (identified) or keyed pseudonym (default) |
| Baseline and endline height | `Observation` (profile `bodyheight`) | LOINC `8302-2`, UCUM `cm` |
| Baseline and endline weight | `Observation` (profile `bodyweight`) | LOINC `29463-7`, UCUM `kg` |
| Baseline and endline BMI | `Observation` (profile `bmi`) | LOINC `39156-5`, UCUM `kg/m2` |
| BMI-for-age status (Severely Wasted … Obese) | `Observation`, `valueCodeableConcept` | LUSOG CodeSystem `nutritional-status`, `derivedFrom` the BMI |
| Height-for-age status | `Observation`, `valueCodeableConcept` | LUSOG CodeSystem `nutritional-status` |
| Nurse's temperature | `Observation` (profile `bodytemp`) | LOINC `8310-5`, UCUM `Cel` |
| Nurse's pulse | `Observation` (profile `heartrate`) | LOINC `8867-4`, UCUM `/min` |
| Nurse's blood pressure | `Observation` (profile `bp`) | LOINC `85354-9` + components `8480-6` / `8462-4`, UCUM `mm[Hg]` |
| Health conditions on the card | `Condition` | `verificationStatus` = `confirmed` when a medical certificate is on file, else `unconfirmed` |

Rules the serialiser keeps:

- **A measurement nobody took is left out.** A learner with no endline gets no endline Observations. A blank value is never sent as zero.
- **Every entry is a conditional update** (`PUT Patient?identifier=…`), so sending the same learner twice updates the receiver's copy instead of creating a duplicate. That is also why the transmitter can safely retry after a timeout.
- **References are internal** (`urn:uuid:…`), and the receiving server rewrites them to its own ids.
- **Every resource carries a generated narrative** built from that resource alone, so a receiver that can't render our codes can still show what the resource says.

## 3. Data Privacy Act compliance

| RA 10173 requirement | How LUSOG meets it |
|---|---|
| **Security of personal information: encryption at rest** (Sec. 20; NPC Circular 16-01) | Every personal and health field is AES-256 encrypted with `APP_KEY` through the `App\Casts` casts (see `EncryptionAtRestTest`). The new `fhir_transmissions` table stores the sent payload, the server's reply, the error text and the sender's name **encrypted**; only lookup keys (school, record id, LRN, status, host, digest) are plain. Uploaded files go through `EncryptedFileStorage`. Sessions are encrypted. |
| **Encryption in transit** | `FhirTransmitter` refuses any endpoint that is not `https://`, before anything is sent, and records the refusal. Certificates are verified, and TLS 1.2 is the minimum version. There is no setting that turns either check off. |
| **Access control and secure credentials** | Only the **School Nurse** can preview, download or send (`FhirTransmitter::ROLES`). Every lookup is re-scoped to the nurse's own school: another school's learner or transmission returns 404. Login passwords are stored as bcrypt hashes. The receiving server's credentials (`FHIR_AUTH_TOKEN`, or `FHIR_USERNAME`/`FHIR_PASSWORD` for HTTP Basic) exist **only in environment variables**. They are attached to the outgoing request and never written to the database, a transmission record, a log or the audit trail (asserted in `FhirInteroperabilityTest`). |
| **Proportionality / data minimisation** (Sec. 11) | The guardian, home address, phone number, clinic notes and consent answers are never put in a bundle, in either mode. **By default (`FHIR_DEIDENTIFY=true`) the Patient is pseudonymised**: no name and no LRN, only an HMAC-SHA256 pseudonym keyed from `APP_KEY`, sex, and the birth year. The resource carries the HL7 security labels `R` (restricted) and `PSEUDED` (pseudonymised). The pseudonym stays the same for a learner, so a receiver can link two sends, but the LRN cannot be recovered from it. Identified mode is for a receiver the school has a data-sharing agreement with. |
| **Accountability: an active, immutable audit log** (Sec. 21) | Every attempt (sent, failed or blocked) writes an `audit_logs` entry with the actor, the learner's record, the receiving host, the payload's SHA-256 and the HTTP status. It never contains the payload. Viewing, downloading and transmitting are also logged by `AuditSensitiveAccess`. The log is now **immutable at three levels**: (1) the `AuditLog` model refuses to update or delete an entry; (2) **database triggers** refuse `UPDATE`, `DELETE` and `TRUNCATE` on `audit_logs` from any query; (3) each entry is **sealed with an HMAC** when it is written. If a row is changed by someone who first dropped the triggers, `php artisan audit:verify` and the System Admin's Audit Trail screen ("Seal" column) both report it as **Altered**. |
| **Record of disclosure** | `fhir_transmissions` keeps exactly what was sent, where, by whom and when, plus what the server answered. On the transmission page, "Payload Integrity" re-hashes the stored copy against the digest taken before sending. |
| **Retention** | The retention purge (`students:purge-expired`) deletes a learner's `fhir_transmissions` rows along with the rest of their record. The audit entry for the disclosure stays, and it holds nothing personal. |

## 4. Configuration

Set these in `.env` locally, or as Railway service variables in production (config is cached, so redeploy or run `php artisan config:clear` after changing them):

| Variable | Meaning | Default |
|---|---|---|
| `FHIR_ENDPOINT` | Receiving server's base URL. **Must be `https://`.** | empty (sending disabled; preview and download still work) |
| `FHIR_AUTH_TOKEN` | Bearer token for the receiver | empty |
| `FHIR_USERNAME` / `FHIR_PASSWORD` | HTTP Basic credentials, used if there is no token | empty |
| `FHIR_DEIDENTIFY` | `true` = pseudonymised Patient; `false` = name, LRN and birth date | `true` |
| `FHIR_AUTO_TRANSMIT` | `true` = send the learner's record automatically after the nurse saves an examination | `false` |
| `FHIR_TIMEOUT` | Seconds per attempt | `30` |
| `FHIR_SYSTEM_BASE` | Namespace for LUSOG's identifiers and local codes. Keep it stable. | `https://lusog-web-production.up.railway.app/fhir` |

The database needs the two new migrations: `php artisan migrate` (pending migrations only; safe on the shared database). One of them installs the `audit_logs` triggers.

## 5. Demonstration script

Before you start: `php artisan migrate:status` must show both `2026_10_10_*` migrations as **Ran**. Until then the audit triggers do not exist, and step 8's SQL would really change the row. Sign in with a real School Nurse account. The local demo session opens the alphabetically first school, which may have no learners.

1. **Configure** `FHIR_ENDPOINT=https://hapi.fhir.org/baseR4` (the public HAPI FHIR R4 test server) and keep `FHIR_DEIDENTIFY=true`. The public server is open to anyone, so no real learner's identity should go there.
2. **Sign in as the School Nurse → Health Data Exchange.** The cards show the receiving server (HTTPS · TLS 1.2+) and the disclosure mode.
3. **Open a learner.** The bundle is serialised from the database on the spot: 1 Organization, 1 Patient, the weigh-in and vital-sign Observations, and any Conditions. Point out the LOINC/UCUM codes, the `PSEUDED` label and the SHA-256 digest.
4. **Transmit.** The transmission page shows `HTTP 200`, one `201 Created` per resource with the server's new ids (e.g. `Patient/95937`), and "Payload Integrity: Verified". Open `https://hapi.fhir.org/baseR4/Patient/<id>` in a browser to show the record now exists outside LUSOG.
5. **Transmit again.** Every entry comes back `200 OK` with the same ids, so no duplicate was created.
6. **Show the refusal.** Set `FHIR_ENDPOINT` to an `http://` URL and transmit: the status is *Refused*, nothing left the server, and the attempt is still logged.
7. **Show the encryption.** In the database, `fhir_transmissions.payload` is ciphertext (`eyJpdiI6…`); the app decrypts it only for an authorised viewer.
8. **Show the audit log.** Sign in as System Admin → Audit Trail: the `transmitted` entry has the host and digest, and every row shows **Sealed**. Try `UPDATE audit_logs SET …` in SQL: the database refuses it (`audit_logs is append-only`). Run `php artisan audit:verify`.
9. Optional: `php artisan fhir:transmit <LRN> --institution=<id>` sends the same way from the command line, and `--preview` prints the bundle without sending it.

## 6. Verification on record

- **Structure:** a synthetic bundle (fake learner "Synthetic Testcase") was checked with the HAPI server's `Bundle/$validate` in both modes: **0 errors, 0 fatal**. The remaining warnings all say the public validator has no LOINC terminology loaded and does not know LUSOG's own CodeSystems. They are not structural problems.
- **Exchange:** the same synthetic bundle was POSTed to `https://hapi.fhir.org/baseR4`. Result: `200`, a `transaction-response` with 16 × `201 Created`. On resend: 16 × `200 OK` with the same ids and versions (idempotent). The server resolved the `urn:uuid` references (`Observation.subject → Patient/…`, `performer → Organization/…`).
- **Automated tests:** `FhirInteroperabilityTest` (17 tests) and `AuditLogImmutabilityTest` (7 tests). The HTTP client is faked, so no test sends anything.

## 7. Known limits

- LUSOG's own code systems (`…/fhir/CodeSystem/nutritional-status`, `…/observation`) are not published as FHIR `CodeSystem` resources, so validators report them as unknown. The display text travels with every code.
- The exchange is **outbound only**. No other system can query LUSOG over FHIR.
- Audit entries written before the immutability migration have no seal and are reported as *Unsealed*, not as trusted. Rotating `APP_KEY` without re-sealing would make every seal read *Altered*. The same key also protects all the encrypted data, so it must not be rotated casually anyway.
- A database superuser can still drop the triggers. The seals make any change they then make detectable, but they cannot stop it.
