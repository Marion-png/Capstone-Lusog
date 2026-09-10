# LUSOG

**Learner Utilization and Status of Growth** — a school clinic and School-Based
Feeding Program (SBFP) management system for DepEd schools.

LUSOG replaces the paper trail a school clinic keeps: class advisers record each
learner's health card and nutritional measurements, the nurse and clinic staff
log consultations and medicine stock, the feeding coordinator enrols qualified
learners and records feeding-day attendance, and the school head reads the whole
picture and exports the DepEd forms. Every figure on screen — beneficiary counts,
at-risk lists, BMI grids, turnout rates — is derived from the underlying records
at read time; nothing is hand-tallied.

## Roles

| Role | What they do |
|---|---|
| **School Nurse** | Reviews health cards, logs consultations, manages deworming and health records |
| **Clinic Staff** | Consultation logging, medicine inventory and dispensing |
| **Class Adviser** | Enters learner data and baseline/endline measurements, consent forms, medical certificates |
| **Feeding Coordinator** | Enrols SBFP beneficiaries, records attendance, monitors at-risk learners, prints SBFP forms |
| **Nutrition Coordinator** | Nutrition analytics and consolidated reports |
| **School Head** | Reads, monitors and exports — never encodes |
| **System Admin** | Approves account requests, manages schools and per-school settings, views the audit trail |

Authentication is session-based and custom (no `Laravel\Auth`): accounts live in
the `accounts` table, new accounts are requested at `/account-request` and
approved by the System Admin, and the admin signs in at `/admin-login`. In a
development checkout, opening any role's dashboard URL without a session seeds a
demo session for that role, so every UI can be reached by typing its URL.

## Stack

- PHP 8.2 · Laravel 12 · Blade · Tailwind CSS 4 · Vite
- PostgreSQL, deployed on Railway (the only database this app runs against)
- PHPUnit with in-memory SQLite for the test suite
- OpenSpout for XLSX exports; the Anthropic SDK for reading photographed attendance sheets (optional)

## Getting started

The project database is the **deployed Railway PostgreSQL** — the accounts,
institutions and learner records exist only there. Do not stand up a local
Postgres; it will look like it works and show you an empty, parallel school.
Full details are in [docs/railway-database-access.md](docs/railway-database-access.md).

```bash
composer install
npm install
cp .env.example .env
```

Then edit `.env`:

1. Set `DB_URL` to the Postgres service's `DATABASE_PUBLIC_URL` from the Railway
   dashboard (project **capstone-lusog** → Postgres → Variables).
2. Set `APP_KEY` to the key the team already uses — ask a collaborator for it.
   Every personal field in the database is encrypted with it, so a freshly
   generated key cannot read any existing record.

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
> applies migrations not yet on the database.

## Development

```bash
composer run dev            # server + queue worker + Vite, concurrently
php artisan serve           # server only
./vendor/bin/pint           # code style (Laravel Pint)
```

Optional: set `ANTHROPIC_API_KEY` in `.env` to enable scanning a photographed
attendance sheet with Claude vision. The model is handed the known roster and
matches marks to it; it never identifies anyone from a face. Left blank, the
route is disabled and the CSV/XLSX import works as normal.

## Testing

```bash
composer run test                                   # config:clear, then the whole suite
php artisan test --filter ClassName::methodName     # one test
```

Tests run against in-memory SQLite (`phpunit.xml`) and never touch Railway.
Because there is no Auth facade, feature tests set the session directly with
`$this->withSession([...])`.

Several tests exist to guard a rule rather than a feature — among them
`EncryptionAtRestTest`, `AuditTrailTest`, `AdviserRosterPersistenceTest`,
`AdviserClassScopeTest`, `SchoolHeadRoleTest` and `DashboardQueryBudgetTest`.
A change that breaks one of those has broken a rule, not just a test.

## Rules the code keeps

These are the invariants every change must preserve. The full statement of each
lives in [CLAUDE.md](CLAUDE.md).

- **Encryption at rest.** Names, contact details, health data, consent answers
  and signatures are encrypted with `APP_KEY` through the casts in `App\Casts`.
  An encrypted column is never referenced in SQL — fetch first, then filter,
  sort or group in PHP. Uploaded documents go through `EncryptedFileStorage`.
- **Audit trail.** Every access and action on personal data is written to the
  append-only `audit_logs` table; nothing ever updates or deletes a row there.
- **Multi-school separation.** Every read and write of school data is scoped by
  the session's `active_institution_id`, and a class adviser is further scoped
  to their own grade and section.
- **The school head reads; other roles write.** `RestrictSchoolHeadWrites`
  refuses every write for that role except signing in and out.
- **Promotion never deletes.** A learner's record is keyed by school year; a new
  year is a new row, and prior years stay retrievable.
- **Round trips are the cost.** The database is reached over the network, so a
  page reads the roster once, a change-detection pulse costs one query, and
  anything memoised is invalidated by the write that moves it.

## Repository layout

```
app/Http/Controllers   one controller per role surface (Adviser*, Feeding*, SchoolHead*, ...)
app/Http/Middleware    EnsureActiveSession, InstitutionScope, RestrictSchoolHeadWrites, AuditSensitiveAccess
app/Support            the business rules: FeedingAtRiskRule, FeedingBeneficiarySummary,
                       FeedingProgramCycle, BmiAssessmentReport, SchoolHeadOverview, ...
app/Casts              EncryptedString / EncryptedArray / EncryptedBoolean
resources/views        Blade templates, grouped by role dashboard
resources/css          lusog-theme.css (the design system) plus one sheet per page
routes/web.php         every route, inline or controller-backed; no role route groups
database/migrations    schema history; database/seeders seeds conditions, schools and sections
docs/                  database access, open product decisions, progress report
scripts/               check-railway-db.php (read-only environment check)
tests/Feature          the suite, including the invariant guards above
```

## Further reading

- [CLAUDE.md](CLAUDE.md) — the architecture and every invariant, in full
- [docs/railway-database-access.md](docs/railway-database-access.md) — connecting to the deployed database
- [docs/open-decisions.md](docs/open-decisions.md) — product questions still awaiting a ruling
- [docs/progress-report.md](docs/progress-report.md) — module status by role
- [CONDITION_COMPONENT_GUIDE.md](CONDITION_COMPONENT_GUIDE.md) — the condition search component and `/api/conditions`
