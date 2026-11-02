# RxNorm Allergy Check

SIGLA checks every medicine dispensed on a consultation against the learner's
recorded medicine allergies, using **RxNav**, the free RxNorm API from the U.S.
National Library of Medicine. It is decision support only: the School Nurse
decides.

## Setup

Nothing to sign up for. RxNav needs no API key.

1. `php artisan migrate` creates `rx_concepts`, `rx_concept_ingredients`,
   `learner_medicine_allergies` and `dispense_allergy_checks`, and adds
   `generic_name`, `rx_concept_id` and `rx_status` to `medicines`. Until it
   runs, the check is off and every screen behaves as before.
2. `php artisan rxnorm:retry-unverified --all-learners` (once) looks up the
   allergies that were recorded before the check existed.
3. On **Medicine Inventory**, click each item's **Generic name** and enter the
   generic (e.g. `Paracetamol`, `Mefenamic Acid`). An item nobody has matched
   reads as *Not checked*, and a learner with a medicine allergy gets a grey
   "check manually" result for it.
4. Optional: `RXNORM_BASE_URL` overrides `https://rxnav.nlm.nih.gov/REST`
   (`config/services.php`). Pointing it somewhere wrong is how test case RX-05
   simulates an outage.

The daily `rxnorm:retry-unverified` (scheduled in `routes/console.php`) asks
again for anything an outage left unverified and refreshes concepts older than
90 days. Nothing runs `schedule:run` on Railway yet, so run it by hand when
RxNav has been down.

## Endpoints

All three are `GET` over HTTPS, 5-second timeout, two attempts on a dropped
connection, 429 or 5xx (`App\Support\RxNormService`, the only class that calls
RxNav).

| # | Request | What SIGLA keeps |
|---|---------|------------------|
| 1 | `/approximateTerm.json?term=…&maxEntries=3` | The top candidate if its name is the name asked for; otherwise up to three candidates for the nurse to choose from |
| 2 | `/rxcui/{rxcui}/related.json?tty=IN+MIN` | The `IN` group: the product's ingredients (co-amoxiclav is two) |
| 3 | `/rxclass/class/byRxcui.json?rxcui=…&relaSource=ATC` | Each ingredient's ATC classes, leaving out classes reached only through a combination product |

RxNav is called only when a medicine or an allergy is **saved**: Add Medicine,
the Generic name dialog, Sheet 1, the parent's online consent, a recorded paper
consent, an uploaded consent and the health assessment. The check on a dispense
reads SIGLA's own database. Only a medicine name is ever sent, never a
learner's name, LRN or record.

## Synonym list

`config/rxnorm.php` maps Philippine names to RxNorm's before the lookup:

| Name used in PH | RxNorm name |
|-----------------|-------------|
| Paracetamol, Biogesic | acetaminophen |
| Salbutamol | albuterol |
| Cotrimoxazole, Co-trimoxazole | sulfamethoxazole / trimethoprim |
| Co-amoxiclav | amoxicillin / clavulanate |
| Mefenamic acid | mefenamate |

Mefenamic acid is listed because RxNorm's ingredient is "mefenamate" and
approximateTerm reads "mefenamic acid" as *meclofenamic* acid. Add the clinic's
own stock as it is found.

## Results

| Result | When | Who | Save |
|--------|------|-----|------|
| Blocked | A recorded paracetamol allergy, matched or only named in the text | Clinic Teacher, Clinic Staff | Refused, no override |
| Warning | An allergy to an ingredient of the medicine | School Nurse | Needs an override reason of 10+ characters |
| Caution | An allergy in the same ATC level-3 class (e.g. ibuprofen M01AE and mefenamic acid M01AG share M01A) | School Nurse | After acknowledgement |
| Unverified | An allergy not matched, the medicine not matched, or no learner chosen off the roll | Both | After acknowledgement |
| Clear | No match and everything verified | Both | Saved |

The dialog shows the result as soon as a learner and a medicine are chosen
(`student-allergies.check`), and the save runs the same check again
(`ConsultationController::store`). Every result is written to
`dispense_allergy_checks`, including clear ones and blocked attempts. A
Clinic Teacher posting any medicine other than paracetamol gets HTTP 403.
Food allergies are not checked.

Tests: `tests/Feature/RxNormAllergyCheckTest.php` (RX-01 to RX-15, with
RxNav faked through `Http::fake()`).
