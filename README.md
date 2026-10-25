# SIGLA

**School-Based Information Governance for Life Care Administration** — a school
clinic, learner health-records and School-Based Feeding Program (SBFP) system for
DepEd schools, built around Grades 7–10.

SIGLA replaces the paper trail a school health programme keeps. Class advisers
enrol their learners and record their measurements, health cards and parental
consents; the school nurse examines learners, logs consultations and runs the
clinic's medicine stock; the feeding coordinator enrols qualified learners and
records every feeding day; and the school head reads the whole picture and
exports the DepEd forms. Every figure on screen — beneficiary counts, at-risk
lists, BMI grids, consent completion, turnout rates — is derived from the
underlying records when the page is read; nothing is hand-tallied.

The repository and the design system (`lusog-theme.css`) keep the project's
earlier name, LUSOG.

## What it covers

- **Learner records.** Advisers enrol their class from a form, a CSV/XLSX class
  masterlist, or a photograph of the masterlist. Baseline and endline
  measurements are classified by BMI-for-age and height-for-age, and the
  Mandatory Learner's Health Assessment Tool (MLAT, Sheets 1 and 2) prints and
  downloads as the clinic's own workbook.
- **School-year handover.** The outgoing adviser closes their section with an
  end-of-year status for every learner (Promoted, Retained, Transferred out,
  Dropped out, Completed Grade 10). Next year's adviser uploads the class
  masterlist, previews it, and confirms; records move **by LRN** and are never
  re-encoded. Archived learners are deleted after the retention period.
- **Clinic.** Consultations, the nurse's examination, medicine inventory with
  deliveries, dispensing and expiry tracking, archived students, and a live data
  visualization page.
- **Parental consent.** The Sulat-Pahibalo reaches the school three ways — the
  parent signs it online from a link, the adviser records the signed paper form
  (optionally read from a photo), or the adviser uploads a scan. The nurse reads
  every consent by school year, program and parent's answer.
- **School-Based Feeding Program.** Enrolment of qualified learners, attendance
  (recorded on site, imported from a spreadsheet, or read from a photograph),
  an at-risk flow in which nobody is flagged without the coordinator's
  confirmation, follow-ups, and the DepEd BMI forms and masterlists.
- **Oversight.** The school head's dashboard, programme reports, consent
  compliance and inventory overview, with DepEd form exports — all read-only.
- **Health data exchange.** One learner's record sent as an HL7 FHIR R4
  transaction bundle to an external endpoint, pseudonymised by default.

## Roles

| Role | What they do |
|---|---|
| **School Nurse** | Health records, examinations (MLAT Sheet 2), consultations, dispensing, medicine inventory, consent forms (read-only), data visualization, FHIR exchange |
| **Clinic Teacher** | A teacher covering the clinic: reads the nurse's screens, dispenses paracetamol only, never changes stock |
| **Clinic Staff** | Consultation logging and medicine inventory |
| **Class Adviser** | Enrols and measures their own class, health cards, consent forms, medical documents, closes the school year |
| **Feeding Coordinator** | SBFP enrolment, attendance, at-risk confirmation and follow-up, SBFP forms |
| **Nutrition Coordinator** | Nutrition analytics and consolidated reports |
| **School Head** | Reads, monitors and exports — never encodes |
| **System Admin** | Approves account requests, manages schools and their settings, views the audit trail |

Parents have no account: each one answers the consent letter from a private
link the adviser sends.

Authentication is session-based and custom (no `Laravel\Auth`). Accounts live
in the `accounts` table; new accounts are requested at `/account-request` and
approved by the System Admin, who signs in at `/admin-login`. In a `local` or
`testing` environment, opening any role's dashboard URL without a session seeds
a demo session for that role, so every screen can be reached by typing its URL.
This is switched off everywhere else and must never be enabled on the deployed
site.

## Stack

- PHP 8.4 · Laravel 12 · Blade · Tailwind CSS 4 · Alpine.js · Vite
- PostgreSQL on Railway — the only database the app runs against
- PHPUnit with in-memory SQLite for the test suite
- OpenSpout for XLSX imports and exports
- Optional: Google Gemini (class masterlist and consent form photos), Anthropic
  Claude (feeding attendance sheet photos), an HL7 FHIR R4 server
- Deployed on Railway with Railpack (FrankenPHP) behind the repository's `Caddyfile`

## Getting started

The project database is the **deployed Railway PostgreSQL** — the accounts,
schools and learner records exist only there. Do not stand up a local Postgres;
it will look like it works and show you an empty, parallel school. Full details
are in [docs/railway-database-access.md](docs/railway-database-access.md).

You need **PHP 8.4 or newer**: the locked OpenSpout and Symfony packages require
it, and on an older PHP every spreadsheet import and export fails.

```bash
composer install
npm install
cp .env.example .env
```

Then edit `.env`:

1. Set `DB_URL` to the Postgres service's `DATABASE_PUBLIC_URL` from the Railway
   dashboard (project **capstone-lusog** → Postgres → Variables).
2. Set `APP_KEY` to the key the team already uses — ask a collaborator for it
   directly, never over a shared channel. Every personal field in the database
   is encrypted with it, so a freshly generated key cannot read any existing
   record.

Verify the wiring and build the assets:

```bash
php artisan config:clear
php scripts/check-railway-db.php   # read-only: connection, schema, accounts, APP_KEY
npm run build
```

> **Do not run against the shared database:** `composer run setup` or
> `php artisan key:generate` (replaces `APP_KEY` and makes every encrypted
> column unreadable), and `migrate:fresh`, `db:wipe` or `migrate:rollback`
> (destroy the team's data). `php artisan migrate` on its own is safe — it only
> applies migrations not yet on the database. Your `.env` holds the real
> database password and encryption key: keep it off shared drives and out of
> commits.

## Optional integrations

Each of these is off until its setting is filled in, and the rest of the app
works the same without it. They send school data to an outside service, so fill
them in only once the school has agreed to that.

| Setting | Turns on |
|---|---|
| `GEMINI_API_KEY` (and `GEMINI_MODEL`) | Reading a photographed class masterlist and a photographed Sulat-Pahibalo |
| `ANTHROPIC_API_KEY` | Reading a photographed feeding attendance sheet against the known roster |
| `FHIR_ENDPOINT` and its credentials | The nurse's Health Data Exchange (HTTPS only) |

The photo readers propose and people decide. A class list or a consent read
from a photo is saved only after the adviser has checked it against the paper,
and an attendance mark the reader was unsure of counts for nothing until the
coordinator reviews it. No reader is ever used to identify anyone from a face.
See [docs/fhir-interoperability.md](docs/fhir-interoperability.md) for the
exchange.

## Development

```bash
composer run dev            # server + queue worker + Vite, concurrently
php artisan serve           # server only
./vendor/bin/pint           # code style (Laravel Pint)
```

## Testing

```bash
composer run test                                   # config:clear, then the whole suite
php artisan test --filter ClassName::methodName     # one test
```

Tests run against in-memory SQLite (`phpunit.xml`) and never touch Railway;
`phpunit.xml` also blanks every provider key, so no test can reach Gemini,
Anthropic or a FHIR server. Because there is no Auth facade, feature tests set
the session directly with `$this->withSession([...])`.

Several tests exist to guard a rule rather than a feature — among them
`EncryptionAtRestTest`, `AuditTrailTest`, `AuditLogImmutabilityTest`,
`AdviserRosterPersistenceTest`, `AdviserClassScopeTest`, `SchoolHeadRoleTest`,
`SchoolYearTransitionTest`, `StudentRetentionTest` and
`DashboardQueryBudgetTest`. A change that breaks one of those has broken a rule,
not just a test. [docs/test-cases.md](docs/test-cases.md) lists the manual test
cases by role.

## Deploying

The live site is the `lusog-web` service in the Railway project
**capstone-lusog**. It is not deployed from GitHub; it is uploaded from a
checkout with the Railway CLI:

```bash
railway login
railway up --service lusog-web --ci
```

`railway up` uploads the working tree as it is, uncommitted changes included,
so commit and push first. A failed build leaves the previous version serving.
**Migrations do not run on deploy**: agree a new migration with the team and
apply it with `php artisan migrate` before deploying the code that needs it.
See [docs/deployment.md](docs/deployment.md) for the service settings.

## Maintenance commands

```bash
php artisan audit:verify                                   # check every audit entry's seal
php artisan students:purge-expired --dry-run               # list archived learners past their deletion date
php artisan students:purge-expired                         # delete them, with every file attached
php artisan fhir:transmit <lrn> --institution=<id> --preview   # print one learner's FHIR bundle; drop --preview to send it
```

`students:purge-expired` is scheduled daily in `routes/console.php`, but nothing
runs Laravel's scheduler on the deployment yet, so for now it is run by hand.

## Rules the code keeps

These are the invariants every change must preserve. The full statement of each
lives in [CLAUDE.md](CLAUDE.md).

- **Encryption at rest.** Names, contact details, health data, consent answers
  and signatures are encrypted with `APP_KEY` through the casts in `App\Casts`.
  An encrypted column is never referenced in SQL — fetch first, then filter,
  sort or group in PHP. Uploaded files go through `EncryptedFileStorage`.
- **Audit trail.** Every access and action on personal data is written to
  `audit_logs`. The table is append-only and enforced as such: the model and
  database triggers refuse updates and deletes, and every entry is sealed with
  an HMAC that `audit:verify` checks.
- **Multi-school separation.** Every read and write of school data is scoped by
  the session's `active_institution_id`, and a class adviser is further scoped
  to their own grade and section.
- **The school head reads; other roles write.** `RestrictSchoolHeadWrites`
  refuses every write for that role except signing in and out and changing
  their own password.
- **Promotion never deletes.** A learner's record is keyed by school year; a new
  year is a new row, records move between years by LRN, and prior years stay
  retrievable. The only deletion is the retention purge of learners archived
  longer than the retention period (5 years by default).
- **People decide.** Nobody is flagged at-risk without the coordinator's
  confirmation, and nothing a photo reader proposes is recorded without the
  adviser's.
- **Round trips are the cost.** The database is reached over the network, so a
  page reads the roster once, a change-detection pulse costs one query, and
  anything memoised is invalidated by the write that moves it.

## Repository layout

```
app/Http/Controllers   one controller per role surface (Adviser*, Feeding*, SchoolHead*, ...)
app/Http/Middleware    EnsureActiveSession, InstitutionScope, RestrictSchoolHeadWrites, AuditSensitiveAccess
app/Support            the business rules: FeedingAttendanceFlag, FeedingBeneficiarySummary,
                       LearnerPlacement, SchoolYearTransition, BmiAssessmentReport,
                       SchoolHeadOverview, ConsentFormScanner, FhirBundle, ...
app/Casts              EncryptedString / EncryptedArray / EncryptedBoolean
app/Console/Commands   audit:verify, students:purge-expired, fhir:transmit
resources/views        Blade templates, grouped by role dashboard
resources/css          lusog-theme.css (the design system) plus one sheet per page
routes/web.php         every route, inline or controller-backed; no role route groups
database/migrations    schema history; database/seeders seeds conditions, schools and sections
docs/                  deployment, database access, FHIR, test cases, open decisions, progress
scripts/               check-railway-db.php (read-only environment check)
tests/Feature          the suite, including the invariant guards above
```

## Further reading

- [CLAUDE.md](CLAUDE.md) — the architecture and every invariant, in full
- [docs/deployment.md](docs/deployment.md) — the Railway service and how to deploy it
- [docs/railway-database-access.md](docs/railway-database-access.md) — connecting to the deployed database
- [docs/fhir-interoperability.md](docs/fhir-interoperability.md) — the HL7 FHIR exchange, with sample bundles in `docs/fhir-samples`
- [docs/test-cases.md](docs/test-cases.md) — manual test cases by role
- [docs/open-decisions.md](docs/open-decisions.md) — product questions still awaiting a ruling
- [docs/progress-report.md](docs/progress-report.md) — module status by role
- [CONDITION_COMPONENT_GUIDE.md](CONDITION_COMPONENT_GUIDE.md) — the condition search component and `/api/conditions`
