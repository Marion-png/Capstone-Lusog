# Parent SMS notifications (Semaphore API)

SIGLA texts a parent when something happens to their child at school that the
parent should hear about the same day. Messages are sent through
[Semaphore](https://semaphore.co), a Philippine SMS gateway, over its REST API
(v4).

| Event | Who triggers it | When the parent is texted |
|---|---|---|
| **Clinic visit** | School Nurse, Clinic Staff or Clinic Teacher saves a consultation for a learner chosen from the roll | Right after the consultation is saved |
| **Feeding absences** | Feeding Coordinator records or imports attendance | When the learner's run of unexcused absences reaches the school's *attendance notice* (default **3** sessions in a row), once per run |
| **Test message** | System Admin, from the Parent SMS console | On demand, to any number typed in |

Nothing is sent unless the **parent opted in** on their online Sulat-Pahibalo.

---

## 1. Setup

### Environment variables

| Variable | Required | Default | Purpose |
|---|---|---|---|
| `SEMAPHORE_API_KEY` | yes, to send | *(blank)* | The API key from the Semaphore dashboard. Blank turns texting off; nothing else in the app changes. |
| `SEMAPHORE_SENDER_NAME` | no | *(blank → Semaphore's default, "SEMAPHORE")* | The sender name shown on the parent's phone. It must be approved on the Semaphore account first. |
| `SMS_DRY_RUN` | no | `false` | `true` composes and logs every message exactly as a real send would, but never calls the gateway. Useful for demos without credits. It wins over a key that is set. |
| `SMS_DAILY_CAP` | no | `300` | The most messages one school may send per day (school time). It stops a bug or a re-imported sheet from draining the account. |
| `SEMAPHORE_ENDPOINT` | no | `https://api.semaphore.co/api/v4` | Override only for testing. |
| `SEMAPHORE_TIMEOUT` | no | `15` | Seconds before a call to the gateway is abandoned. |

All of these are read only in `config/services.php` (`services.semaphore.*`).
Run `php artisan config:clear` after changing them. On Railway, set them as
service variables.

### Database

```bash
php artisan migrate
```

Migration `2026_11_01_000001_create_sms_notifications_table` adds:

* `sms_notifications`: the log of every message.
* `health_consent_forms.sms_opt_in`, `sms_number`, `sms_language`: the parent's choice.

Until it has run, the opt-in box is not shown and nothing is sent.

### No queue worker needed

A message is written as a `queued` row, then sent **after the HTTP response
has gone back** (`SendParentSms::dispatchAfterResponse`). The nurse never waits
on the gateway, and the deployment, which runs no queue worker, sends it
anyway.

---

## 2. How it works

```
 Parent's Sulat-Pahibalo ──► opt-in + number + language  (health_consent_forms)
                                      │
 Consultation saved ─┐                ▼
 Attendance saved ───┼──► ParentNotifier ── checks ──► SmsNotification (queued)
                     │      opt-in · number · duplicate · daily cap   │
                     │                                                ▼ after response
                     │                                  SendParentSms job
                     │                                                │
                     │                                  SemaphoreClient::send()
                     │                                  POST /api/v4/messages
                     │                                                │
                     │                     accepted / failed (row updated, audited)
                     │                                                │
 System Admin ───────┴──► "Refresh delivery status" / sms:refresh-status
                            GET /api/v4/messages/{id} → delivered / failed
```

### Classes

| Class | Responsibility |
|---|---|
| `App\Support\SemaphoreClient` | The only code that calls Semaphore: `send()`, `message()` (delivery report), `account()` (credit balance). Turns every failure into an `SmsGatewayFailure` with a readable message. |
| `App\Support\ParentNotifier` | Decides **whether** a parent is texted and **what** the text says: opt-in, number, duplicates, daily cap, templates (English / Cebuano). |
| `App\Jobs\SendParentSms` | Sends one queued row and records the gateway's answer. |
| `App\Support\SmsDeliveryReports` | Fetches delivery reports for messages still on their way. |
| `App\Support\PhilippineMobileNumber` | Reads `0917 123 4567`, `+63 917 123 4567`, `639171234567`, … as `09171234567`; rejects landlines. |
| `App\Models\SmsNotification` | One message: status, gateway message id, network, attempts, error. |
| `App\Http\Controllers\SmsNotificationController` | The System Admin's Parent SMS console. |
| `App\Console\Commands\RefreshSmsDeliveryStatus` | `php artisan sms:refresh-status`. |

### Semaphore endpoints used

| Call | Method & path | Parameters | Used for |
|---|---|---|---|
| Send | `POST /api/v4/messages` (form body) | `apikey`, `number`, `message`, `sendername` (optional) | Every message |
| Delivery report | `GET /api/v4/messages/{id}` | `apikey` | Refresh delivery status |
| Account | `GET /api/v4/account` | `apikey` | Credit balance on the console (cached 60 s) |

Semaphore answers a send with a list of message objects (`message_id`,
`status`, `network`, …). Status mapping:

| Semaphore status | SIGLA status |
|---|---|
| `Queued`, `Pending` | **Sent to network** (`accepted`) |
| `Sent` | **Delivered** |
| `Failed`, `Refunded` | **Failed** |
| *(dry run)* | **Simulated** |

### Message templates

Both languages say what happened in general words and ask the parent to
contact the school. Example (English):

> Sta. Ana NHS: Ligaya visited the school clinic today at 10:42 AM. Please contact the school clinic for details. -SIGLA

> Sta. Ana NHS: Ligaya has missed 3 school feeding sessions in a row. Please contact the Feeding Coordinator. -SIGLA

Cebuano:

> Sta. Ana NHS: Si Ligaya mibisita sa school clinic karong adlawa, 10:42 AM. Palihug kontaka ang school clinic para sa detalye. -SIGLA

---

## 3. Security and privacy

| Measure | Where |
|---|---|
| **Opt-in only.** The parent ticks the box and types the number on their own Sulat-Pahibalo. Their *latest* answered form decides, so next year's form without the tick stops the messages. | `ParentNotifier::optInFor()` |
| **No health information in any text.** A message names the child's first name and the event, never a complaint, medicine, diagnosis, nutritional status or measurement. An SMS sits unencrypted on a phone that may be shared. | `ParentNotifier::compose()`; `the_text_carries_no_health_information` test |
| **Encryption at rest.** The number, the message, the gateway's error text and the staff name are encrypted (`EncryptedString`). Screens print only `•••• 4567`. | `SmsNotification`, `HealthConsentForm` casts |
| **School scoping.** The clinic trigger looks the learner up by LRN **within the nurse's own school**, so an LRN posted from another school reaches nobody. | `ConsultationController::textParent()` |
| **Role checks.** The console and its actions are System Admin only. | `SmsNotificationController::guard()` |
| **API key handling.** The key lives in an environment variable, is sent in the POST body (not the URL) for sends, and is blanked in `phpunit.xml` so no test can reach the gateway. | `config/services.php`, `phpunit.xml` |
| **Audit trail.** Every message row is `Auditable`; failures (`sms_failed`), cap hits (`sms_blocked`) and retries (`sms_retried`) are recorded too. | `audit_logs` |
| **Abuse limits.** A per-school daily cap, plus a unique de-duplication key per event (`clinic_visit:{consultation}`, `attendance_notice:{record}:{run start}`). | `sms_notifications.dedupe_key` |
| **Retention.** A learner's messages are deleted with the learner by the retention purge. | `StudentRecordPurge::LRN_KEYED` |

---

## 4. Error handling

| Situation | What happens | What staff see |
|---|---|---|
| No key and no dry run | Feature is off | Nothing; the consultation saves as before |
| Parent did not opt in | Nothing sent | "The parent has not opted in to text messages, so none was sent." |
| Number on the form is not a PH mobile | Nothing sent | "…not a valid mobile number, so no text was sent." (the parent's form refuses such a number in the first place) |
| Same event again (re-saved attendance, replayed form) | Nothing sent | (nothing; one event = one message) |
| Daily cap reached | Nothing sent, `sms_blocked` audited | "The school's daily text message limit has been reached…" |
| Gateway busy (429 / 5xx) | Retried 3 times, then **Failed** with Retry offered | "The SMS gateway is unavailable at the moment. Try again shortly." |
| Connection dropped | **Failed**, *not* repeated automatically (the message may already have been accepted; a double text is worse) | "The SMS gateway could not be reached…" |
| Wrong API key (401/403) | **Failed** | "The Semaphore API key was refused. Check SEMAPHORE_API_KEY." |
| Out of credits | **Failed** | "The SMS account has run out of credits. Top up…" |
| Unapproved sender name | **Failed** | "The sender name is not approved…" |
| Gateway refuses the number/message (422) | **Failed**, not retried | The gateway's own reason |
| Message begins with "TEST" or is over 480 chars | Refused before sending (Semaphore silently discards "TEST…" messages) | — |
| Any send failure | The consultation or attendance **is still saved** | |

---

## 5. Testing

```bash
php artisan test --filter ParentSmsNotificationTest
```

27 tests with the gateway faked (`Http::fake()`), covering: number formats, the
clinic and attendance triggers, opt-in rules, the no-health-information rule,
language, dry run, retries, refusals, credits, wrong key, dropped connection,
de-duplication, the daily cap, cross-school isolation, encryption at rest, the
console's role check, delivery reports and retry.

---

## 6. Operating it

* **Console:** System Admin → **Parent SMS** (`/dashboard/system-admin/sms`): gateway state, credit balance, delivered/failed counts, a sample send, the message log with filters, **Refresh delivery status** and **Retry**.
* **Delivery reports from a terminal:** `php artisan sms:refresh-status`.
* **Costs:** one Semaphore credit per 160 characters. Most messages are one or two credits.
